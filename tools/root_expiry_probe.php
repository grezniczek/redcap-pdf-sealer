<?php

declare(strict_types=1);

/** Disposable expired-anchor Acrobat probe. Never reads or changes the installation PKI. */
use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\Certificate;
use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Signer;
use DE\RUB\PDFSealerExternalModule\Pdf\PdfSealBuilder;
use DE\RUB\PDFSealerExternalModule\Pki\CertificateIssuer;
use DE\RUB\PDFSealerExternalModule\Pki\GeneratedIdentity;
use DE\RUB\PDFSealerExternalModule\Timestamp\InternalTimestampProvider;
use DE\RUB\PDFSealerExternalModule\Timestamp\InternalTsaService;
use DE\RUB\PDFSealerExternalModule\Timestamp\TimestampProvider;
use DE\RUB\PDFSealerExternalModule\Timestamp\TsaIdentity;
use DE\RUB\PDFSealerExternalModule\Timestamp\TsaPolicy;

if (getenv('PDF_SEALER_LIVE_TEST') !== '1' || $argc !== 2
    || !in_array($argv[1], ['--preview', '--run'], true) || PHP_VERSION_ID < 80400) {
    throw new RuntimeException('Use PDF_SEALER_LIVE_TEST=1 and --preview|--run with PHP 8.4+ on this development instance');
}
echo "Disposable root expiry probe: public artifacts only; no installation identities, records, settings, logs or edocs change.\n";
echo "Trust only the expired original test root; compare renewed-same-key and unrelated-key PDF trust in Acrobat.\n";
if ($argv[1] === '--preview') {
    exit;
}

$_SERVER['PHP_SELF'] = 'pdf_sealer_root_expiry_probe.php';
$core = rtrim(getenv('PDF_SEALER_REDCAP_ROOT') ?: '/home/gr/redcap/codebase', '/');
require $core . '/Config/init_global.php';
require dirname(__DIR__) . '/tests/support/pdf_timestamp_checks.php';
require dirname(__DIR__) . '/tests/support/root_certificate_probe.php';
checkSeal(db_query('SET SESSION TRANSACTION READ ONLY', [], null, MYSQLI_STORE_RESULT, true) !== false,
    'Cannot put the diagnostic database connection into read-only mode');
$framework = \ExternalModules\ExternalModules::getFrameworkInstance('pdf_sealer', 'v9.9.9');
$issuer = new CertificateIssuer([$framework, 'createTempFile'],
    static fn(): never => throw new RuntimeException('Diagnostic must never reserve a database serial'));
$now = time();
$organization = 'PDF Sealer Expiry TEST ' . gmdate('Ymd-His') . ' ' . bin2hex(random_bytes(4));
$template = $issuer->createRoot($organization);
$rootKey = $template->privateKey();
$expiredDer = reissueProbeRoot($template->certificateDer, $rootKey, $now - 365 * 86400, $now - 86400);
$renewedDer = reissueProbeRoot($expiredDer, $rootKey, $now - 60, $now + 3650 * 86400);
$expiredPem = Certificate::derToPem($expiredDer);
$renewedPem = Certificate::derToPem($renewedDer);
$expiredInfo = openssl_x509_parse($expiredPem);
$renewedInfo = openssl_x509_parse($renewedPem);
checkSeal(is_array($expiredInfo) && is_array($renewedInfo)
    && $expiredInfo['validTo_time_t'] < $now && $renewedInfo['validFrom_time_t'] <= $now
    && $renewedInfo['validTo_time_t'] > $now && $expiredInfo['subject'] === $renewedInfo['subject']
    && $expiredInfo['issuer'] === $renewedInfo['issuer']
    && $expiredInfo['extensions'] === $renewedInfo['extensions']
    && openssl_x509_verify($renewedPem, openssl_pkey_get_public($expiredPem)) === 1,
    'Expired and renewed root invariants failed');
$renewedRoot = new GeneratedIdentity($renewedDer, $template->privateKeyPem());
$unrelatedRoot = $issuer->createRoot($organization);
checkSeal(openssl_x509_parse(Certificate::derToPem($unrelatedRoot->certificateDer))['subject'] === $expiredInfo['subject']
    && openssl_x509_verify(Certificate::derToPem($unrelatedRoot->certificateDer), openssl_pkey_get_public($expiredPem)) !== 1,
    'Negative control must have the same subject and a different key');

$directory = dirname(__DIR__) . '/DEV_DOCS/interop-artifacts/root-expiry-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
checkSeal(mkdir($directory, 0700, true), 'Cannot create root expiry artifact directory');
file_put_contents($directory . '/expired-original-root.cer', $expiredDer);
file_put_contents($directory . '/renewed-root.cer', $renewedDer);
$manifest = ['created_at_utc' => gmdate('c'), 'live_state_changed' => false,
    'organization' => $organization, 'original_root_expired_at_utc' => gmdate('c', $expiredInfo['validTo_time_t']),
    'expired_root_sha256' => hash('sha256', $expiredDer), 'renewed_root_sha256' => hash('sha256', $renewedDer),
    'method' => 'Import an already-expired disposable anchor; this is not a timed transition of a previously valid trust-store entry.',
    'acrobat_result' => 'pending', 'cases' => []];
foreach (['D-renewed-root' => $renewedRoot, 'E-different-key-control' => $unrelatedRoot] as $name => $root) {
    $project = $issuer->createProject($organization, $issuer->newProjectUuid(), $root);
    $tsa = $issuer->createTsa($organization, $root);
    $internal = new InternalTimestampProvider(new InternalTsaService(TsaPolicy::DEFAULT_OID),
        new TsaIdentity($tsa->certificateDer, $tsa->privateKey(), [$root->certificateDer]));
    $recording = new class($internal) implements TimestampProvider {
        public string $request = '';
        public string $response = '';
        public function __construct(private readonly InternalTimestampProvider $provider) {}
        public function policyOid(): string { return $this->provider->policyOid(); }
        public function respond(string $requestDer, int $now): string {
            $this->request = $requestDer;
            return $this->response = $this->provider->respond($requestDer, $now);
        }
    };
    $stream = "BT /F1 12 Tf 40 160 Td (Root expiry test / $name) Tj ET\n";
    $pdf = assemblePdf([
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 700 220]'
            . ' /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
        4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        5 => '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . 'endstream',
    ]);
    $now = time();
    checkSeal(openssl_x509_parse(Certificate::derToPem($project->certificateDer))['validFrom_time_t'] > $expiredInfo['validTo_time_t']
        && openssl_x509_parse(Certificate::derToPem($tsa->certificateDer))['validFrom_time_t'] > $expiredInfo['validTo_time_t'],
        'Leaf certificates must have been issued after original-anchor expiry');
    $result = (new PdfSealBuilder())->sealTimestamped($pdf, $project->certificateDer, $project->privateKey(),
        [$root->certificateDer], $now, $recording);
    $rootPem = Certificate::derToPem($root->certificateDer);
    verifyTimestampedSeal($pdf, $result, $rootPem, $recording, $now);
    $cms = verifySeal($pdf, $result->pdf, $rootPem);
    $tokens = (new Signer())->signatureTimestampTokens($cms);
    checkSeal(str_contains($cms, $root->certificateDer) && str_contains($tokens[0], $root->certificateDer)
        && !str_contains($cms, $expiredDer), 'Only the intended current root may be embedded');
    $path = $directory . '/' . $name . '.pdf';
    file_put_contents($path, $result->pdf);
    foreach ([['qpdf', '--check', $path], ['pdfsig', '-nocert', '-no-ocsp', $path]] as $command) {
        [$status, $output] = runSealCommand($command);
        checkSeal($status === 0 && ($command[0] !== 'pdfsig'
            || (str_contains($output, 'Signature Validation: Signature is Valid.')
                && str_contains($output, 'Total document signed'))), 'PDF verification failed: ' . $output);
    }
    $manifest['cases'][$name] = ['pdf_sha256' => hash('sha256', $result->pdf),
        'embedded_root_sha256' => hash('sha256', $root->certificateDer),
        'project_certificate_sha256' => hash('sha256', $project->certificateDer),
        'tsa_certificate_sha256' => hash('sha256', $tsa->certificateDer),
        'expected_acrobat_trust' => $name === 'D-renewed-root' ? 'under test' : 'unknown issuer / untrusted'];
    echo "$name: B-T, qpdf, pdfsig, CMS and OpenSSL timestamp checks passed using its current root.\n";
}
file_put_contents($directory . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
echo 'Expired original anchor: ' . gmdate('c', $expiredInfo['validTo_time_t']) . "\n";
echo "Artifacts: $directory\n";
