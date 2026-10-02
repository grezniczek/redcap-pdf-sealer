<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pki;

use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\Certificate;
use RuntimeException;
use Throwable;

/** Current built-in TSA only; commit the permanent block before attempting publication and recovery. */
final readonly class TsaRevocationService
{
    public function __construct(
        private object $framework,
        private IdentityRepository $identities,
        private TsaRenewalService $renewal,
        private CrlPublicationService $publication,
        private CrlRepository $crls,
        private PkiInitializationLock $lock,
    ) {}

    public function preview(): array
    {
        return $this->lock->withLock(fn(): array => $this->snapshot());
    }

    public function replace(string $reviewHash): array
    {
        $view = $this->lock->withLock(fn(): array => $this->review($reviewHash));
        // The renewal lock rechecks the exact reviewed identity before replacing it.
        return ['identity_id' => $view['identity_id'], 'replacement' => $this->renewal->renewAutomatically(time(), $view['identity_id'])];
    }

    public function revoke(string $reviewHash, string $reason): array
    {
        $code = match ($reason) { 'superseded' => 4, 'compromise' => 1, default => throw new RuntimeException('Invalid TSA revocation reason') };
        $view = $this->lock->withLock(function () use ($reviewHash, $code): array {
            $view = $this->review($reviewHash);
            if ($view['revoked']) { throw new RuntimeException('TSA already revoked'); }
            $identity = $this->identities->find($view['identity_id']);
            $rootDer = $this->identities->publicCertificate($view['issuer_identity_id'], 'root');
            if ($this->framework->query('START TRANSACTION', []) === false) { throw new RuntimeException('TSA revocation transaction failed'); }
            try {
                $this->identities->tsaRevocations()->append(null, $identity, $rootDer, $code, time(), $view['issuer_identity_id']);
                if ($this->framework->query('COMMIT', []) === false) { throw new RuntimeException('TSA revocation commit failed'); }
            } catch (Throwable $e) {
                $this->framework->query('ROLLBACK', []);
                throw $e;
            }
            return $view;
        });
        $published = false;
        try {
            $this->publication->run(time(), true);
            $rootDer = $this->identities->publicCertificate($view['issuer_identity_id'], 'root');
            $crl = $this->crls->load($rootDer);
            $published = $crl !== null && $crl['next_update'] > time()
                && $this->identities->mergeRevocations($rootDer, $crl['entries']) === $crl['entries'];
        } catch (Throwable) { /* Immutable block remains available for hourly/daily publication. */ }
        $replacement = 'pending';
        try { $replacement = $this->renewal->renewAutomatically(time()); }
        catch (Throwable) { /* Hourly maintenance retries with a fresh key, never the revoked key. */ }
        return ['identity_id' => $view['identity_id'], 'crl_published' => $published, 'replacement' => $replacement];
    }

    private function review(string $hash): array
    {
        $view = $this->snapshot();
        if (!hash_equals($view['review_hash'], $hash)) { throw new RuntimeException('TSA changed; review again'); }
        return $view;
    }

    private function snapshot(): array
    {
        $source = $this->identities->providers()->source(ProviderRepository::BUILTIN_TSA);
        if ($source['kind'] !== 'internal' || $source['identity_id'] !== $this->identities->activeId('tsa')) {
            throw new RuntimeException('Built-in TSA references disagree');
        }
        $identity = $this->identities->find($source['identity_id']);
        if ($identity === null || $identity->role !== 'tsa') { throw new RuntimeException('TSA unavailable'); }
        $rootDer = $this->identities->publicCertificate($source['issuer_identity_id'], 'root');
        $certificate = new Certificate();
        $key = openssl_pkey_get_public(Certificate::derToPem($rootDer));
        $details = openssl_x509_parse(Certificate::derToPem($identity->certificateDer));
        if (!is_array($details) || $key === false || $certificate->fields($identity->certificateDer)['issuer'] !== $certificate->fields($rootDer)['subject']
            || openssl_x509_verify(Certificate::derToPem($identity->certificateDer), $key) !== 1
            || $certificate->extendedKeyUsageWithCriticality($identity->certificateDer) !== [['1.3.6.1.5.5.7.3.8'], true]) {
            throw new RuntimeException('TSA certificate provenance invalid');
        }
        // Public review/block work even if the old private key is damaged or its certificate has expired.
        $view = ['identity_id' => $identity->id, 'issuer_identity_id' => $source['issuer_identity_id'],
            'revoked' => $this->identities->tsaRevocations()->find($identity) !== null,
            'certificate' => ['subject' => $details['name'], 'fingerprint' => hash('sha256', $identity->certificateDer),
                'valid_from' => $details['validFrom_time_t'], 'valid_until' => $details['validTo_time_t']]];
        return $view + ['review_hash' => hash('sha256', json_encode($view, JSON_THROW_ON_ERROR))];
    }
}
