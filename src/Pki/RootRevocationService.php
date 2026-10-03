<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pki;

use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\Certificate;
use RuntimeException;
use Throwable;

/** Public CC review; issuing-key block commits before independent publication and automatic recovery. */
final readonly class RootRevocationService
{
    public function __construct(private object $framework, private IdentityRepository $identities,
        private RootRenewalService $renewal, private CrlPublicationService $publication, private CrlRepository $crls,
        private BuiltinMaintenanceService $maintenance, private PkiHealthService $health, private PkiInitializationLock $lock) {}

    public function preview(): array
    {
        return $this->lock->withLock(fn(): array => $this->snapshot());
    }

    public function renew(string $reviewHash): array
    {
        $view = $this->lock->withLock(fn(): array => $this->review($reviewHash));
        if ($view['revoked']) { throw new RuntimeException('Revoked roots require fresh-key recovery'); }
        return ['identity_id' => $view['identity_id'], 'replacement' => $this->renewal->renewIfDue(time(), $view['identity_id'])];
    }

    public function revoke(string $reviewHash, string $reason): array
    {
        $code = match ($reason) { 'superseded' => 4, 'compromise' => 2, default => throw new RuntimeException('Invalid root revocation reason') };
        $view = $this->lock->withLock(function () use ($reviewHash, $code): array {
            $view = $this->review($reviewHash);
            if ($view['revoked']) { throw new RuntimeException('Root already revoked'); }
            if ($this->framework->query('START TRANSACTION', []) === false) { throw new RuntimeException('Root block transaction failed'); }
            try {
                $this->identities->rootRevocations()->append($view['identity_id'], $code, time());
                if ($this->framework->query('COMMIT', []) === false) { throw new RuntimeException('Root block commit failed'); }
            } catch (Throwable $e) {
                $this->framework->query('ROLLBACK', []);
                throw $e;
            }
            return $view;
        });
        $published = false;
        try {
            $this->publication->run(time(), true);
            $oldDer = $this->identities->publicCertificate($view['identity_id'], 'root');
            $record = $this->crls->load($oldDer);
            $published = $record !== null && $record['next_update'] > time()
                && $this->identities->mergeRevocations($oldDer, $record['entries']) === $record['entries'];
        } catch (Throwable) { /* Old-key publication is independent of new-key deployment. */ }
        $replacement = 'pending';
        try { $replacement = $this->renewal->renewIfDue(time()); }
        catch (Throwable) { /* The old key remains blocked; cron retries fresh-key recovery. */ }
        $maintenance = null;
        try { $maintenance = $this->maintenance->run(); }
        catch (Throwable) { /* Bounded project recovery also resumes through the scheduled worker. */ }
        // A concurrent worker may have completed recovery after the first attempt.
        if ($this->identities->activeId('root') !== $view['identity_id']) {
            try {
                $current = $this->identities->activeId('root');
                if ($this->health->inspectIssuance($current, time())->status === PkiHealth::Ready) { $replacement = 'renewed'; }
            } catch (Throwable) {}
        }
        return ['identity_id' => $view['identity_id'], 'crl_published' => $published, 'replacement' => $replacement,
            'maintenance_status' => $maintenance['status'] ?? 'pending'];
    }

    private function review(string $hash): array
    {
        $view = $this->snapshot();
        if (!hash_equals($view['review_hash'], $hash)) { throw new RuntimeException('Root changed; review again'); }
        return $view;
    }

    private function snapshot(): array
    {
        $id = $this->identities->activeId('root');
        if ($id === null) { throw new RuntimeException('Root is not initialized'); }
        $der = $this->identities->publicCertificate($id, 'root');
        $details = openssl_x509_parse(Certificate::derToPem($der));
        $fields = (new Certificate())->fields($der);
        $this->health->assertRootCertificate($der, max($fields['not_before'], min(time(), $fields['not_after'] - 1)), true);
        $provider = $this->identities->providers()->provider(ProviderRepository::BUILTIN_CA);
        $source = $this->identities->providers()->source(ProviderRepository::BUILTIN_TSA);
        if ($provider['issuer_identity_id'] !== $id || $source['issuer_identity_id'] !== $id
            || $source['identity_id'] !== $this->identities->activeId('tsa')) {
            throw new RuntimeException('Built-in root references disagree');
        }
        $view = ['identity_id' => $id, 'issuer_key_id' => CrlIssuer::keyId($der),
            'revoked' => $this->identities->rootRevocations()->find($der) !== null,
            'certificate' => ['subject' => $details['name'], 'fingerprint' => hash('sha256', $der),
                'thumbprint' => hash('sha1', $der),
                'valid_from' => $details['validFrom_time_t'], 'valid_until' => $details['validTo_time_t']]];
        // Counts are informational, not a reason to make an issuing-key block impossible on a busy installation.
        return $view + ['known_dependent_certificates' => count($this->identities->rootRevocations()->dependents($der)),
            'review_hash' => hash('sha256', json_encode($view, JSON_THROW_ON_ERROR))];
    }
}
