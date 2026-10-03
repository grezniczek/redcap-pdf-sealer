<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pki;

use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\{Certificate, SignedDataVerifier};
use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Timestamp\{Client, Config};
use DE\RUB\PDFSealerExternalModule\Timestamp\{InternalTimestampProvider, InternalTsaService, PolicyOidAsn1, TsaIdentity};
use RuntimeException;
use Throwable;

/** Same-key routine renewal; an explicit issuing-key block triggers fresh-key root/TSA recovery. */
final readonly class RootRenewalService
{
    public const WINDOW = CertificateIssuer::LEAF_DAYS * 86400 + LeafRenewalPolicy::WINDOW;

    public function __construct(
        private object $framework,
        private IdentityRepository $identities,
        private SecretProtector $protector,
        private CertificateIssuer $issuer,
        private PkiHealthService $health,
        private CrlRepository $crls,
        private PkiInitializationLock $lock,
    ) {}

    public function renewIfDue(int $now, ?string $expectedRootId = null): string
    {
        return $this->lock->withLock(function () use ($now, $expectedRootId): string {
            $now = max($now, time());
            $rootId = $this->identities->activeId('root');
            if ($rootId === null) { throw new RuntimeException('Root reference missing'); }
            $rootDer = $this->identities->publicCertificate($rootId, 'root');
            if ($expectedRootId !== null && $rootId !== $expectedRootId) { throw new RuntimeException('Root review is stale'); }
            $revocation = $this->identities->rootRevocations()->find($rootDer);
            $fields = (new Certificate())->fields($rootDer);
            if ($fields['not_before'] > $now) { throw new RuntimeException('Root is not yet valid'); }
            // Expiration is recoverable; certificate corruption, mismatched keys and future validity are not.
            $this->health->assertRootCertificate($rootDer, min($now, $fields['not_after'] - 1), $revocation !== null);
            if ($expectedRootId === null && $revocation === null && !self::due($fields['not_after'], $now)) { return 'skipped'; }
            $providers = $this->identities->providers();
            $provider = $providers->provider(ProviderRepository::BUILTIN_CA);
            $source = $providers->source(ProviderRepository::BUILTIN_TSA);
            if ($provider['issuer_identity_id'] !== $rootId || $source['issuer_identity_id'] !== $rootId
                || $source['identity_id'] !== $this->identities->activeId('tsa')) {
                throw new RuntimeException('Root renewal requires coherent built-in references');
            }
            $root = null;
            if ($revocation === null) {
                $tsaFields = (new Certificate())->fields($this->identities->publicCertificate($source['identity_id'], 'tsa'));
                $oldTsa = $this->identities->find($source['identity_id']);
                if ($oldTsa === null) { throw new RuntimeException('TSA unavailable'); }
                if ($this->identities->tsaRevocations()->find($oldTsa) === null) {
                    $this->health->captureTimestamp($source, max($tsaFields['not_before'], $fields['not_before']));
                }
                $root = $this->identities->find($rootId);
                if ($root === null || $root->role !== 'root' || $root->certificateDer !== $rootDer) {
                    throw new RuntimeException('Root record is inconsistent');
                }
            }
            $previousCrl = $revocation === null ? $this->crls->load($rootDer) : null;
            if ($revocation === null && ($previousCrl === null || $previousCrl['number'] === PHP_INT_MAX || $previousCrl['this_update'] > $now)) {
                throw new RuntimeException('Root renewal requires an intact CRL counter and ledger');
            }
            $oldOrganization = openssl_x509_parse(Certificate::derToPem($rootDer))['subject']['O'] ?? null;
            if (!is_string($oldOrganization) || $oldOrganization === '') { throw new RuntimeException('Root organization missing'); }
            // Recovery creates a new trust anchor without accessing the revoked root/TSA private keys or old CRL.
            $renewed = $revocation === null ? $this->issuer->renewRoot($root->asGeneratedIdentity($this->protector))
                : $this->issuer->createRoot($oldOrganization);
            $organization = openssl_x509_parse(Certificate::derToPem($renewed->certificateDer))['subject']['O'] ?? null;
            if (!is_string($organization) || $organization === '') { throw new RuntimeException('Root organization missing'); }
            $tsa = $this->issuer->createTsa($organization, $renewed);
            $sample = new InternalTimestampProvider(new InternalTsaService($source['policy_oid']),
                new TsaIdentity($tsa->certificateDer, $tsa->privateKey(), [$renewed->certificateDer]),
                static fn(): int => max($now, time()));
            $client = new Client(new Config('http://localhost.invalid/tsa'), new PolicyOidAsn1($source['policy_oid']));
            $request = $client->buildRequest(random_bytes(32));
            $sampleTime = max($now, time());
            $token = $client->parseResponse($sample->respond($request->der, $sampleTime), $request, $sampleTime);
            if ((new SignedDataVerifier(requireSigningCertificate: true))->verify($token) !== $tsa->certificateDer) {
                throw new RuntimeException('Renewed root/TSA sample failed');
            }
            $crl = (new CrlIssuer())->issue($renewed, $revocation === null ? $previousCrl['number'] + 1 : 1, max($now, time()),
                $revocation === null ? $this->identities->mergeRevocations($rootDer, $previousCrl['entries']) : []);
            if ($this->framework->query('START TRANSACTION', []) === false) { throw new RuntimeException('Root renewal transaction failed'); }
            try {
                $newRootId = $this->identities->append('root', $renewed);
                $newTsaId = $this->identities->append('tsa', $tsa);
                $this->identities->activate('root', $newRootId);
                $this->identities->activate('tsa', $newTsaId);
                $updatedProvider = $provider; $updatedProvider['issuer_identity_id'] = $newRootId;
                $updatedSource = $source; $updatedSource['identity_id'] = $newTsaId; $updatedSource['issuer_identity_id'] = $newRootId;
                $this->framework->setSystemSetting('ca_provider_' . ProviderRepository::BUILTIN_CA, json_encode($updatedProvider, JSON_THROW_ON_ERROR));
                $this->framework->setSystemSetting('tsa_source_' . ProviderRepository::BUILTIN_TSA, json_encode($updatedSource, JSON_THROW_ON_ERROR));
                $this->crls->save($renewed->certificateDer, $crl);
                CrlPublicationService::audit($this->framework, $newRootId, $crl);
                if ($this->identities->activeId('root') !== $newRootId || $this->identities->activeId('tsa') !== $newTsaId
                    || $providers->provider(ProviderRepository::BUILTIN_CA) !== $updatedProvider
                    || $providers->source(ProviderRepository::BUILTIN_TSA) !== $updatedSource
                    || $this->health->inspectIssuance($newRootId, max($now, time()))->status !== PkiHealth::Ready) {
                    throw new RuntimeException('Renewed root references or encrypted key failed validation');
                }
                $this->health->captureTimestamp($updatedSource, max($now, time()));
                $audit = $this->framework->log('root_certificate_renewal', [
                    'project_id' => null, 'record' => '', 'actor' => $expectedRootId === null ? 'system:cron' : $this->framework->getUser()->getUsername(),
                    'reason' => $revocation !== null ? 'revocation_recovery' : ($expectedRootId !== null ? 'manual'
                        : ($fields['not_after'] <= $now ? 'expired' : 'renewal_window')),
                    'previous_identity_id' => $rootId, 'identity_id' => $newRootId,
                    'previous_tsa_identity_id' => $source['identity_id'], 'tsa_identity_id' => $newTsaId,
                    'previous_certificate_sha256' => hash('sha256', $rootDer),
                    'certificate_sha256' => hash('sha256', $renewed->certificateDer),
                    'issuer_key_id' => $crl['key_id'], 'crl_number' => (string) $crl['number'],
                ]);
                if ((!is_int($audit) && !ctype_digit((string) $audit)) || (int) $audit < 1) { throw new RuntimeException('Root renewal audit failed'); }
                if ($this->framework->query('COMMIT', []) === false) { throw new RuntimeException('Root renewal commit failed'); }
                return 'renewed';
            } catch (Throwable $e) {
                $this->framework->query('ROLLBACK', []);
                throw $e;
            }
        });
    }

    public static function due(int $expires, int $now): bool
    {
        return $expires <= $now + self::WINDOW;
    }
}
