<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Diagnostics;

use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\SignedDataVerifier;
use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Timestamp\Client;
use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Timestamp\Config;
use DE\RUB\PDFSealerExternalModule\Pdf\PdfSealBuilder;
use DE\RUB\PDFSealerExternalModule\Pki\CertificateIssuer;
use DE\RUB\PDFSealerExternalModule\Pki\IdentityRepository;
use DE\RUB\PDFSealerExternalModule\Pki\PkiHealth;
use DE\RUB\PDFSealerExternalModule\Pki\PkiHealthService;
use DE\RUB\PDFSealerExternalModule\Pki\PrimarySystemSettingReader;
use DE\RUB\PDFSealerExternalModule\Pki\SecretProtector;
use DE\RUB\PDFSealerExternalModule\Timestamp\InternalTimestampProvider;
use DE\RUB\PDFSealerExternalModule\Timestamp\InternalTsaService;
use DE\RUB\PDFSealerExternalModule\Timestamp\PolicyOidAsn1;
use RuntimeException;
use Throwable;

/** Exercises stored PKI in memory; PHP 8.2/8.3 reserves a serial for the temporary signer. */
final readonly class PkiDiagnosticService
{
    public function __construct(
        private IdentityRepository $identities,
        private SecretProtector $protector,
        private CertificateIssuer $issuer,
        private PrimarySystemSettingReader $settings,
        private \DE\RUB\PDFSealerExternalModule\Pki\PkiInitializationLock $configurationLock = new \DE\RUB\PDFSealerExternalModule\Pki\PkiInitializationLock(),
    ) {}

    /** Only fixed outcomes and public identity/policy versions leave this service. */
    public function run(): array
    {
        $checks = [];
        $versions = ['root' => null, 'tsa' => null, 'tsa_issuer' => null, 'tsa_policy' => null];
        $step = static function (string $id, bool $enabled, callable $work) use (&$checks): mixed {
            $checks[$id] = 'skipped';
            if (!$enabled) { return null; }
            try {
                $result = $work();
                $checks[$id] = 'passed';
                return $result;
            } catch (Throwable) {
                $checks[$id] = 'failed';
                return null;
            }
        };
        $step('encryption', true, function (): void {
            $probe = bin2hex(random_bytes(32));
            if (!hash_equals($probe, $this->protector->decrypt($this->protector->encrypt($probe)))) {
                throw new RuntimeException('Encryption round-trip failed');
            }
        });
        $root = $step('root', true, function () use (&$versions) {
            $versions['root'] = $this->identities->activeId('root');
            $health = new PkiHealthService($this->identities, $this->protector);
            if ($versions['root'] === null || $health->inspectIssuance($versions['root'], time())->status !== PkiHealth::Ready) {
                throw new RuntimeException('Root is not ready');
            }
            $root = $this->identities->find($versions['root']);
            if ($root === null || $root->role !== 'root') { throw new RuntimeException('Root unavailable'); }
            return $root->asGeneratedIdentity($this->protector);
        });
        $timestampSource = null;
        $tsa = $step('tsa', true, function () use (&$timestampSource, &$versions) {
            $sourceId = \DE\RUB\PDFSealerExternalModule\Pki\ProviderRepository::BUILTIN_TSA;
            $health = new PkiHealthService($this->identities, $this->protector);
            $timestampSource = $this->identities->providers()->source($sourceId);
            $versions['tsa'] = $timestampSource['identity_id'];
            $versions['tsa_issuer'] = $timestampSource['issuer_identity_id'];
            $versions['tsa_policy'] = $timestampSource['policy_oid'];
            return $health->captureTimestamp($timestampSource, time());
        });
        $signer = $step('signer', $root !== null, function () use ($root) {
            return $this->configurationLock->withLock(function () use ($root) {
                $this->identities->providers()->assertActive(\DE\RUB\PDFSealerExternalModule\Pki\ProviderRepository::BUILTIN_CA);
                $organization = $this->settings->get('organization');
                if (!is_string($organization)) { throw new RuntimeException('Organization unavailable'); }
                return $this->issuer->createProject($organization, $this->issuer->newProjectUuid(), $root);
            });
        });
        $sample = self::samplePdf();
        $builder = new PdfSealBuilder();
        $verifier = new SampleSealVerifier();
        $step('bb', $signer !== null, function () use ($sample, $root, $signer, $builder, $verifier): void {
            $sealed = $builder->seal($sample, $signer->certificateDer, $signer->privateKey(), [$root->certificateDer], time());
            $verifier->verify($sample, $sealed, $signer->certificateDer);
        });
        $provider = $step('timestamp', $tsa !== null, function () use ($tsa, $timestampSource) {
            $policy = $timestampSource['policy_oid'];
            $provider = new InternalTimestampProvider(new InternalTsaService($policy), $tsa, static fn(): int => time());
            // Match the sealing path: omit reqPolicy (Config cannot encode UUID-sized arcs).
            // Codec only: the responder runs in process; this URL is never fetched.
            $client = new Client(new Config('http://localhost.invalid/tsa'), new PolicyOidAsn1($policy));
            $request = $client->buildRequest(random_bytes(32));
            $now = time();
            $token = $client->parseResponse($provider->respond($request->der, $now), $request, $now);
            if ((new SignedDataVerifier(requireSigningCertificate: true))->verify($token) !== $tsa->certificateDer) {
                throw new RuntimeException('Unexpected TSA signer');
            }
            return $provider;
        });
        $step('bt', $signer !== null && $provider !== null, function () use ($sample, $root, $tsa, $signer, $builder, $verifier, $provider): void {
            $now = time();
            $sealed = $builder->sealTimestamped($sample, $signer->certificateDer, $signer->privateKey(), [$root->certificateDer], $now, $provider, $now);
            if ($sealed->profile !== 'pades-b-t' || $sealed->timestampSerialHex === null || $sealed->timestampTime === null) {
                throw new RuntimeException('Missing timestamp metadata');
            }
            $verifier->verify($sample, $sealed->pdf, $signer->certificateDer, $tsa->certificateDer);
        });
        return ['passed' => count(array_filter($checks, static fn(string $status): bool => $status !== 'passed')) === 0, 'checks' => $checks, 'versions' => $versions];
    }

    public static function samplePdf(): string
    {
        $pdf = "%PDF-1.4\n";
        $bodies = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] >>',
        ];
        $offsets = [];
        foreach ($bodies as $number => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= "$number 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 4\n0000000000 65535 f \n";
        foreach ($offsets as $offset) { $pdf .= sprintf('%010d 00000 n ', $offset) . "\n"; }
        return $pdf . "trailer\n<< /Size 4 /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    }
}
