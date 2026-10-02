<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pki;

use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\{Certificate, SignedDataVerifier};
use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Timestamp\{Client, Config};
use DE\RUB\PDFSealerExternalModule\Timestamp\{InternalTimestampProvider, InternalTsaService, PolicyOidAsn1, TsaIdentity};
use RuntimeException;
use Throwable;

/** Fresh-key built-in TSA replacement, preserving provider policy and certificate history. */
final readonly class TsaRenewalService
{
    public function __construct(
        private object $framework,
        private IdentityRepository $identities,
        private SecretProtector $protector,
        private CertificateIssuer $issuer,
        private PkiHealthService $health,
        private PkiInitializationLock $configurationLock,
    ) {}

    public function renewAutomatically(int $now, ?string $expectedIdentityId = null): string
    {
        return $this->configurationLock->withLock(function () use ($now, $expectedIdentityId): string {
            $now = max($now, time());
            $providers = $this->identities->providers();
            $source = $providers->source(ProviderRepository::BUILTIN_TSA);
            $rootId = $this->identities->activeId('root');
            if ($source['identity_id'] !== $this->identities->activeId('tsa')
                || $providers->provider(ProviderRepository::BUILTIN_CA)['issuer_identity_id'] !== $rootId) {
                throw new RuntimeException('Built-in references disagree');
            }
            if ($expectedIdentityId !== null && $source['identity_id'] !== $expectedIdentityId) { throw new RuntimeException('TSA review is stale'); }
            $oldTsa = $this->identities->find($source['identity_id']);
            if ($oldTsa === null || $oldTsa->role !== 'tsa') { throw new RuntimeException('TSA unavailable'); }
            $revoked = $this->identities->tsaRevocations()->find($oldTsa) !== null;
            $tsaDer = $oldTsa->certificateDer;
            $fields = (new Certificate())->fields($tsaDer);
            if ($expectedIdentityId === null && !$revoked && !LeafRenewalPolicy::due($fields['not_after'], $source['issuer_identity_id'], $rootId, $now)) { return 'skipped'; }
            if ($this->health->inspectIssuance($rootId, $now)->status !== PkiHealth::Ready) {
                throw new RuntimeException('Root is unavailable for TSA renewal');
            }
            $root = $this->identities->find($rootId);
            $rootFields = (new Certificate())->fields($root->certificateDer);
            LeafRenewalPolicy::assertIssuerWindow($rootFields['not_after'], $now);
            $oldIssuer = (new Certificate())->fields($this->identities->publicCertificate($source['issuer_identity_id'], 'root'));
            if (!$revoked) { $this->health->captureTimestamp($source, max($fields['not_before'], $oldIssuer['not_before'])); }
            $organization = openssl_x509_parse(Certificate::derToPem($root->certificateDer))['subject']['O'] ?? null;
            if (!is_string($organization) || $organization === '') { throw new RuntimeException('Root organization missing'); }
            $generated = $this->issuer->createTsa($organization, $root->asGeneratedIdentity($this->protector));
            $provider = new InternalTimestampProvider(new InternalTsaService($source['policy_oid']),
                new TsaIdentity($generated->certificateDer, $generated->privateKey(), [$root->certificateDer]),
                static fn(): int => max($now, time()));
            $client = new Client(new Config('http://localhost.invalid/tsa'), new PolicyOidAsn1($source['policy_oid']));
            $request = $client->buildRequest(random_bytes(32));
            $sampleNow = max($now, time());
            $token = $client->parseResponse($provider->respond($request->der, $sampleNow), $request, $sampleNow);
            if ((new SignedDataVerifier(requireSigningCertificate: true))->verify($token) !== $generated->certificateDer) {
                throw new RuntimeException('Replacement TSA sample failed');
            }
            if ($this->framework->query('START TRANSACTION', []) === false) { throw new RuntimeException('TSA transaction failed'); }
            try {
                $id = $this->identities->append('tsa', $generated);
                $this->identities->activate('tsa', $id);
                $updated = $source; $updated['identity_id'] = $id; $updated['issuer_identity_id'] = $rootId;
                $this->framework->setSystemSetting('tsa_source_' . ProviderRepository::BUILTIN_TSA, json_encode($updated, JSON_THROW_ON_ERROR));
                $this->health->captureTimestamp($updated, max($now, time())); // Includes encrypted-key round trip before activation commits.
                $audit = $this->framework->log('tsa_certificate_renewal', [
                    'project_id' => null, 'record' => '', 'actor' => $expectedIdentityId === null ? 'system:cron' : $this->framework->getUser()->getUsername(),
                    'reason' => $revoked ? 'revocation_recovery' : ($expectedIdentityId === null ? 'automatic' : 'manual'),
                    'previous_identity_id' => $source['identity_id'], 'identity_id' => $id, 'issuer_identity_id' => $rootId,
                    'previous_certificate_sha256' => hash('sha256', $tsaDer), 'certificate_sha256' => hash('sha256', $generated->certificateDer),
                ]);
                if ((!is_int($audit) && !ctype_digit((string) $audit)) || (int) $audit < 1) { throw new RuntimeException('TSA audit failed'); }
                if ($this->framework->query('COMMIT', []) === false) { throw new RuntimeException('TSA commit failed'); }
                return 'renewed';
            } catch (Throwable $e) {
                $this->framework->query('ROLLBACK', []);
                throw $e;
            }
        });
    }

}
