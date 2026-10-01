<?php

declare(strict_types=1);

/**
 * Development-only Acrobat probe. Reads existing PKI; creates public artifacts only.
 * Usage: PDF_SEALER_LIVE_TEST=1 php tools/same_key_root_probe.php --preview|--run PID
 */
use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\Certificate;
use DE\RUB\PDFSealerExternalModule\Pdf\PdfSealBuilder;
use DE\RUB\PDFSealerExternalModule\Pki\CertificateIssuer;
use DE\RUB\PDFSealerExternalModule\Pki\GeneratedIdentity;
use DE\RUB\PDFSealerExternalModule\Pki\IdentityRepository;
use DE\RUB\PDFSealerExternalModule\Pki\ProjectBindingRepository;
use DE\RUB\PDFSealerExternalModule\Pki\ProviderRepository;
use DE\RUB\PDFSealerExternalModule\Pki\SecretProtector;
use DE\RUB\PDFSealerExternalModule\Timestamp\InternalTimestampProvider;
use DE\RUB\PDFSealerExternalModule\Timestamp\InternalTsaService;
use DE\RUB\PDFSealerExternalModule\Timestamp\TimestampProvider;
use DE\RUB\PDFSealerExternalModule\Timestamp\TsaIdentity;

if (getenv('PDF_SEALER_LIVE_TEST') !== '1'
    || $argc !== 3 || !in_array($argv[1], ['--preview', '--run'], true)
    || filter_var($argv[2], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
    throw new RuntimeException('Use PDF_SEALER_LIVE_TEST=1 and --preview|--run PID on this development instance');
}
if (PHP_VERSION_ID < 80400) {
    throw new RuntimeException('This diagnostic requires PHP 8.4+ for write-free 128-bit certificate serials');
}

$_SERVER['PHP_SELF'] = 'pdf_sealer_same_key_root_probe.php';
$core = rtrim(getenv('PDF_SEALER_REDCAP_ROOT') ?: '/home/gr/redcap/codebase', '/');
require $core . '/Config/init_global.php';
require dirname(__DIR__) . '/tests/support/pdf_timestamp_checks.php';

// Block database writes for the probe's PKI reads/issuance, including accidental serial reservations.
checkSeal(db_query('SET SESSION TRANSACTION READ ONLY', [], null, MYSQLI_STORE_RESULT, true) !== false,
    'Cannot put the diagnostic database connection into read-only mode');

$framework = \ExternalModules\ExternalModules::getFrameworkInstance('pdf_sealer', 'v9.9.9');
$pid = (int) $argv[2];
$protector = new SecretProtector();
$identities = new IdentityRepository($framework, $protector);
$providers = new ProviderRepository($framework);
$binding = (new ProjectBindingRepository($framework))->find($pid);
$provider = $providers->provider($providers::BUILTIN_CA);
$source = $providers->source($providers::BUILTIN_TSA);
checkSeal($binding !== null && $binding->providerId === $providers::BUILTIN_CA
    && $binding->identityId !== null && $binding->pendingProviderId === null,
    'Requires an existing built-in project signer without a pending provider transition');
checkSeal($provider['timestamp_source'] === $providers::BUILTIN_TSA,
    'Requires the built-in timestamp source as primary');
$root = $identities->find($provider['issuer_identity_id']);
$tsa = $identities->find($source['identity_id']);
$project = $identities->find($binding->identityId);
checkSeal($root !== null && $root->role === 'root' && $tsa !== null && $tsa->role === 'tsa'
    && $project !== null && $project->role === 'project'
    && $project->projectUuid === $binding->uuid
    && $project->issuerId === $root->id && $source['issuer_identity_id'] === $root->id
    && $identities->activeId('root') === $root->id && $identities->activeId('tsa') === $tsa->id,
    'Requires coherent current root, TSA and project issuer references');
$rootInfo = openssl_x509_parse(Certificate::derToPem($root->certificateDer));
checkSeal(is_array($rootInfo) && ($rootInfo['validFrom_time_t'] ?? PHP_INT_MAX) <= time()
    && ($rootInfo['validTo_time_t'] ?? 0) > time(), 'Current root must be valid');
$organization = $rootInfo['subject']['O'] ?? null;
checkSeal(is_string($organization), 'Current root has no organization');
echo "PID $pid: built-in project and TSA use the current root; read-only diagnostic.\n";
echo 'Original root SHA-256: ' . hash('sha256', $root->certificateDer) . "\n";
echo "Run creates three synthetic B-T PDFs and public certificates; no records, settings, identity logs or edocs are changed.\n";
if ($argv[1] === '--preview') {
    exit;
}

// Diagnostic clone keeps the exact root subject/public key/profile.
require dirname(__DIR__) . '/tests/support/root_certificate_probe.php';
$rootKey = $root->privateKey($protector);
$now = time();
$renewedDer = reissueProbeRoot($root->certificateDer, $rootKey, $now - 60, $now + 3650 * 86400);
$originalPem = Certificate::derToPem($root->certificateDer);
$renewedPem = Certificate::derToPem($renewedDer);
$renewedInfo = openssl_x509_parse($renewedPem);
checkSeal(is_array($renewedInfo) && $renewedInfo['subject'] === $rootInfo['subject']
    && $renewedInfo['issuer'] === $rootInfo['issuer']
    && $renewedInfo['extensions'] === $rootInfo['extensions']
    && $renewedInfo['serialNumberHex'] !== $rootInfo['serialNumberHex']
    && $renewedInfo['validTo_time_t'] > $rootInfo['validTo_time_t']
    && openssl_x509_verify($renewedPem, openssl_pkey_get_public($originalPem)) === 1
    && openssl_x509_check_private_key($renewedPem, $rootKey), 'Same-key root renewal invariants failed');
checkSeal(openssl_pkey_export($rootKey, $rootKeyPem), 'Cannot rehydrate the root for transient leaf issuance');
$renewedRoot = new GeneratedIdentity($renewedDer, $rootKeyPem);
unset($rootKeyPem);
$issuer = new CertificateIssuer([$framework, 'createTempFile'],
    static fn(): never => throw new RuntimeException('Diagnostic must never reserve a database serial'));
$newProject = $issuer->createProject($organization, $binding->uuid, $renewedRoot);
$newTsa = $issuer->createTsa($organization, $renewedRoot);

$directory = dirname(__DIR__) . '/DEV_DOCS/interop-artifacts/same-key-root-pid' . $pid . '-'
    . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
checkSeal(mkdir($directory, 0700, true), 'Could not create diagnostic artifact directory');
file_put_contents($directory . '/original-root.cer', $root->certificateDer);
file_put_contents($directory . '/renewed-root.cer', $renewedDer);
file_put_contents($directory . '/original-root.pem', $originalPem);
file_put_contents($directory . '/renewed-root.pem', $renewedPem);

$cases = [
    'A-baseline' => [$project->certificateDer, $project->privateKey($protector), $tsa->certificateDer,
        $tsa->privateKey($protector), $root->certificateDer],
    'B-renewed-root-existing-leaves' => [$project->certificateDer, $project->privateKey($protector), $tsa->certificateDer,
        $tsa->privateKey($protector), $renewedDer],
    'C-renewed-root-fresh-leaves' => [$newProject->certificateDer, $newProject->privateKey(), $newTsa->certificateDer,
        $newTsa->privateKey(), $renewedDer],
];
$manifest = ['pid' => $pid, 'created_at_utc' => gmdate('c'), 'live_state_changed' => false,
    'expiry_behavior_tested' => false, 'original_root' => hash('sha256', $root->certificateDer),
    'renewed_root' => hash('sha256', $renewedDer), 'cases' => []];
foreach ($cases as $name => [$leaf, $key, $tsaDer, $tsaKey, $anchor]) {
    $label = "Same-key root test / PID $pid / $name";
    $stream = "BT /F1 12 Tf 40 160 Td ($label) Tj ET\n";
    $pdf = assemblePdf([
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 700 220]'
            . ' /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
        4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        5 => '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . 'endstream',
    ]);
    $internal = new InternalTimestampProvider(new InternalTsaService($source['policy_oid']),
        new TsaIdentity($tsaDer, $tsaKey, [$anchor]));
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
    $now = time();
    $result = (new PdfSealBuilder())->sealTimestamped($pdf, $leaf, $key, [$anchor], $now, $recording);
    // Verify every case against ONLY the original root to test preserved anchor identity.
    verifyTimestampedSeal($pdf, $result, $originalPem, $recording, $now);
    $cms = verifySeal($pdf, $result->pdf, $originalPem);
    $tokens = (new \DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Signer())->signatureTimestampTokens($cms);
    checkSeal(str_contains($cms, $anchor) && str_contains($tokens[0], $anchor),
        'Intended root must be embedded in both signer and timestamp chains');
    if ($anchor !== $root->certificateDer) {
        checkSeal(!str_contains($cms, $root->certificateDer),
            'Renewal cases must not embed the original root alongside the renewed one');
    }
    $path = $directory . '/' . $name . '.pdf';
    file_put_contents($path, $result->pdf);
    foreach ([['qpdf', '--check', $path], ['pdfsig', '-nocert', '-no-ocsp', $path]] as $command) {
        [$status, $output] = runSealCommand($command);
        checkSeal($status === 0 && ($command[0] !== 'pdfsig'
            || (str_contains($output, 'Signature Validation: Signature is Valid.')
                && str_contains($output, 'Total document signed'))), 'PDF verification failed: ' . $output);
    }
    $manifest['cases'][$name] = ['pdf_sha256' => hash('sha256', $result->pdf),
        'project_certificate_sha256' => hash('sha256', $leaf),
        'tsa_certificate_sha256' => hash('sha256', $tsaDer),
        'embedded_root_sha256' => hash('sha256', $anchor)];
    echo "$name: B-T, qpdf, pdfsig, CMS and OpenSSL timestamp checks passed against original root.\n";
}
file_put_contents($directory . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
echo 'Renewed root SHA-256: ' . hash('sha256', $renewedDer) . "\n";
echo "Artifacts: $directory\n";
echo "Trust only original-root.cer in Acrobat. Do not import renewed-root.cer or the leaf certificates as trusted.\n";
