<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pki;

use Closure;
use RuntimeException;
use Throwable;

/** Daily publication; no root/leaf replacement and no request-time access to private keys. */
final class CrlPublicationService
{
    private Closure $transaction;

    public function __construct(
        private readonly object $framework,
        private readonly PublicTrustRepository $publicRoots,
        private readonly IdentityRepository $identities,
        private readonly SecretProtector $protector,
        private readonly CrlRepository $crls,
        private readonly PkiInitializationLock $lock,
        ?callable $transaction = null,
    ) {
        $this->transaction = $transaction === null
            ? fn(string $sql): mixed => $framework->query($sql, [])
            : Closure::fromCallable($transaction);
    }

    public function run(?int $now = null): array
    {
        $now ??= time();
        if ($now < 1) { throw new RuntimeException('Invalid CRL publication time'); }
        return $this->lock->withLock(function () use ($now): array {
            $roots = $this->publicRoots->roots();
            $active = $this->publicRoots->activeRootId();
            if ($roots === [] && $active === null) { return ['status' => 'uninitialized', 'published' => 0]; }
            if ($active === null || !in_array($active, array_column($roots, 'id'), true)) {
                throw new RuntimeException('CRL publication requires a consistent active root');
            }
            // Renewed root certificates share a URL/counter/list. Prefer the active version.
            usort($roots, static fn(array $a, array $b): int => ($b['id'] === $active) <=> ($a['id'] === $active));
            $seen = [];
            $count = 0;
            foreach ($roots as $root) {
                $keyId = CrlIssuer::keyId($root['der']);
                if (isset($seen[$keyId])) { continue; }
                $seen[$keyId] = true;
                $previous = $this->crls->load($root['der']);
                if ($previous !== null && $previous['this_update'] > $now) {
                    throw new RuntimeException('CRL clock moved backwards');
                }
                if ($previous !== null && $now - $previous['this_update'] < 86400
                    && $previous['next_update'] > $now) { continue; }
                // Expired retired issuers retain their last snapshot; root maintenance follows separately.
                if ($root['valid_from'] > $now || $root['valid_until'] <= $now) {
                    if ($root['id'] === $active) { throw new RuntimeException('Active CRL issuer is not currently valid'); }
                    continue;
                }
                $identity = $this->identities->find($root['id']);
                if ($identity === null || $identity->role !== 'root' || $identity->certificateDer !== $root['der']) {
                    throw new RuntimeException('CRL issuer identity is inconsistent');
                }
                if ($previous !== null && $previous['number'] === PHP_INT_MAX) {
                    throw new RuntimeException('CRL number exhausted');
                }
                $record = (new CrlIssuer())->issue($identity->asGeneratedIdentity($this->protector),
                    ($previous['number'] ?? 0) + 1, $now, $previous['entries'] ?? []);
                $this->transaction('START TRANSACTION');
                try {
                    $this->crls->save($root['der'], $record);
                    self::audit($this->framework, $root['id'], $record);
                    $this->transaction('COMMIT');
                } catch (Throwable $e) {
                    $this->transaction('ROLLBACK');
                    throw $e;
                }
                ++$count;
            }
            return ['status' => 'ok', 'published' => $count];
        });
    }

    public static function audit(object $framework, string $rootId, array $record): void
    {
        $id = $framework->log('pki_crl_publication', [
            'project_id' => null, 'record' => '', 'identity_id' => $rootId,
            'issuer_key_id' => $record['key_id'], 'crl_number' => (string) $record['number'],
            'this_update' => (string) $record['this_update'], 'next_update' => (string) $record['next_update'],
            'revoked_count' => (string) count($record['entries']),
        ]);
        if ((!is_int($id) && !ctype_digit((string) $id)) || (int) $id < 1) {
            throw new RuntimeException('CRL audit insertion failed');
        }
    }

    private function transaction(string $sql): void
    {
        if (($this->transaction)($sql) === false) { throw new RuntimeException('CRL transaction failed'); }
    }
}
