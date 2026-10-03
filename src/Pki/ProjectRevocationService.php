<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pki;

use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\Certificate;
use RuntimeException;
use Throwable;

/** Confirmed CC block first; publication/replacement cannot undo an accepted revocation. */
final readonly class ProjectRevocationService
{
    public function __construct(
        private object $framework,
        private ProjectBindingRepository $bindings,
        private IdentityRepository $identities,
        private ProjectRevocationRepository $revocations,
        private ProjectRenewalService $renewal,
        private CrlPublicationService $publication,
        private CrlRepository $crls,
        private ProjectIssueLock $projectLock,
        private PkiInitializationLock $configurationLock,
    ) {}

    public function preview(int $pid): array
    {
        return $this->withLocks($pid, fn(): array => $this->snapshot($pid));
    }

    public function revoke(int $pid, string $reviewHash, string $reason): array
    {
        $code = match ($reason) { 'superseded' => 4, 'compromise' => 1, default => throw new RuntimeException('Invalid revocation reason') };
        $view = $this->withLocks($pid, function () use ($pid, $reviewHash, $code): array {
            $view = $this->snapshot($pid);
            if ($view['revoked'] || !hash_equals($view['review_hash'], $reviewHash)) {
                throw new RuntimeException('Revocation state changed; review again');
            }
            $identity = $this->identities->find($view['identity_id']);
            $rootDer = $this->identities->publicCertificate($identity->issuerId, 'root');
            if ($this->framework->query('START TRANSACTION', []) === false) { throw new RuntimeException('Revocation transaction failed'); }
            try {
                $this->revocations->append($pid, $identity, $rootDer, $code, time());
                if ($this->framework->query('COMMIT', []) === false) { throw new RuntimeException('Revocation commit failed'); }
            } catch (Throwable $e) {
                $this->framework->query('ROLLBACK', []);
                throw $e;
            }
            return $view;
        });
        // These are separate transactions, after releasing both locks. A failure leaves the block and retry intent durable.
        $published = false;
        try {
            $this->publication->run(time(), true);
            $identity = $this->identities->find($view['identity_id']);
            $crl = $this->crls->load($this->identities->publicCertificate($identity->issuerId, 'root'));
            $published = $crl !== null && $crl['next_update'] > time()
                && $this->revocations->merge($this->identities->publicCertificate($identity->issuerId, 'root'), $crl['entries']) === $crl['entries'];
        } catch (Throwable) { /* Hourly and daily publication retry from immutable blocks. */ }
        $replacement = 'pending';
        try { $replacement = $this->renewal->renewAutomatically($pid, time()); }
        catch (Throwable) { /* Hourly maintenance retries; the old key stays blocked. */ }
        return ['identity_id' => $view['identity_id'], 'crl_published' => $published, 'replacement' => $replacement];
    }

    private function snapshot(int $pid): array
    {
        ProjectBindingRepository::assertPid($pid);
        $binding = $this->bindings->find($pid);
        if ($binding?->providerId !== ProviderRepository::BUILTIN_CA || $binding->identityId === null) {
            throw new RuntimeException('Revocation requires an existing built-in signer');
        }
        $identity = $this->identities->find($binding->identityId);
        if ($identity === null || $identity->role !== 'project' || $identity->projectUuid !== $binding->uuid
            || $identity->providerId !== $binding->providerId || $identity->issuerId === null || $identity->issuerChain !== []) {
            throw new RuntimeException('Revocation binding invalid');
        }
        $rootDer = $this->identities->publicCertificate($identity->issuerId, 'root');
        $pem = Certificate::derToPem($identity->certificateDer);
        $details = openssl_x509_parse($pem);
        $key = openssl_pkey_get_public(Certificate::derToPem($rootDer));
        if (!is_array($details) || $key === false || openssl_x509_verify($pem, $key) !== 1
            || ($details['subject']['CN'] ?? null) !== 'REDCap Project ' . $binding->uuid) {
            throw new RuntimeException('Revocation certificate provenance invalid');
        }
        // Retired/expired issuers, disabled projects, corrupt private keys and pending work must not prevent a public-key block.
        $view = ['pid' => $pid, 'uuid' => $binding->uuid, 'identity_id' => $identity->id,
            'issuer_identity_id' => $identity->issuerId, 'issuer_fingerprint' => hash('sha256', $rootDer),
            'revoked' => $this->revocations->find($identity) !== null,
            'certificate' => ['subject' => $details['name'], 'fingerprint' => hash('sha256', $identity->certificateDer),
                'thumbprint' => hash('sha1', $identity->certificateDer),
                'valid_from' => $details['validFrom_time_t'], 'valid_until' => $details['validTo_time_t']]];
        return $view + ['review_hash' => hash('sha256', json_encode($view, JSON_THROW_ON_ERROR))];
    }

    private function withLocks(int $pid, callable $work): mixed
    {
        return $this->projectLock->withLock($pid, fn() => $this->configurationLock->withLock($work));
    }
}
