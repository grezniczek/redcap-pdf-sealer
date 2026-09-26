<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Diagnostics;

use Com\Tecnick\Pdf\Sign\Cms\SignedDataVerifier;
use Com\Tecnick\Pdf\Sign\Timestamp\Client;
use Com\Tecnick\Pdf\Sign\Timestamp\Config;
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
use DE\RUB\PDFSealerExternalModule\Timestamp\TsaIdentity;
use DE\RUB\PDFSealerExternalModule\Timestamp\TsaPolicy;
use RuntimeException;
use Throwable;

/** Exercises stored PKI and sealing in memory, without persistence or project context. */
final readonly class PkiDiagnosticService
{
    public function __construct(
        private IdentityRepository $identities,
        private SecretProtector $protector,
        private CertificateIssuer $issuer,
        private PrimarySystemSettingReader $settings,
    ) {}

    /** @return array{passed: bool, checks: array<string, string>} Only fixed identifiers leave this service. */
    public function run(): array
    {
        $checks = [];
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
        $health = (new PkiHealthService($this->identities, $this->protector))->inspect(time());
        $root = $step('root', true, function () use ($health) {
            if (!in_array($health->status, [PkiHealth::Ready, PkiHealth::Degraded], true)) {
                throw new RuntimeException('Root is not ready');
            }
            $root = $this->identities->find($this->identities->activeId('root'));
            if ($root === null || $root->role !== 'root') { throw new RuntimeException('Root unavailable'); }
            return $root->asGeneratedIdentity($this->protector);
        });
        $tsa = $step('tsa', $root !== null, function () use ($health, $root) {
            if ($health->status !== PkiHealth::Ready) { throw new RuntimeException('TSA is not ready'); }
            $tsa = $this->identities->find($this->identities->activeId('tsa'));
            if ($tsa === null || $tsa->role !== 'tsa') { throw new RuntimeException('TSA unavailable'); }
            return new TsaIdentity($tsa->certificateDer, $tsa->privateKey($this->protector), [$root->certificateDer]);
        });
        $signer = $step('signer', $root !== null, function () use ($root) {
            $organization = $this->settings->get('organization');
            if (!is_string($organization)) { throw new RuntimeException('Organization unavailable'); }
            return $this->issuer->createProject($organization, $this->issuer->newProjectUuid(), $root);
        });
        $sample = self::samplePdf();
        $builder = new PdfSealBuilder();
        $verifier = new SampleSealVerifier();
        $step('bb', $signer !== null, function () use ($sample, $root, $signer, $builder, $verifier): void {
            $sealed = $builder->seal($sample, $signer->certificateDer, $signer->privateKey(), [$root->certificateDer], time());
            $verifier->verify($sample, $sealed, $signer->certificateDer);
        });
        $provider = $step('timestamp', $tsa !== null, function () use ($tsa) {
            $policy = $this->settings->get('tsa_policy_oid');
            if ($policy === null || $policy === '') { $policy = TsaPolicy::DEFAULT_OID; }
            if (!is_string($policy)) { throw new RuntimeException('Invalid policy'); }
            $provider = new InternalTimestampProvider(new InternalTsaService($policy), $tsa);
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
        return ['passed' => count(array_filter($checks, static fn(string $status): bool => $status !== 'passed')) === 0, 'checks' => $checks];
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
