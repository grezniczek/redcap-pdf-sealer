<?php

declare(strict_types=1);

/** Read-only live-PKI probe: temporary CDP-bearing signer/TSA and a synthetic B-T PDF. */
use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\Certificate;
use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Ltv\Crl;
use DE\RUB\PDFSealerExternalModule\Diagnostics\PkiDiagnosticService;
use DE\RUB\PDFSealerExternalModule\Pdf\PdfSealBuilder;
use DE\RUB\PDFSealerExternalModule\Pki\CertificateIssuer;
use DE\RUB\PDFSealerExternalModule\Pki\CrlIssuer;
use DE\RUB\PDFSealerExternalModule\Pki\CrlRepository;
use DE\RUB\PDFSealerExternalModule\Pki\IdentityRepository;
use DE\RUB\PDFSealerExternalModule\Pki\PrimarySystemSettingReader;
use DE\RUB\PDFSealerExternalModule\Pki\SecretProtector;
use DE\RUB\PDFSealerExternalModule\Timestamp\InternalTimestampProvider;
use DE\RUB\PDFSealerExternalModule\Timestamp\InternalTsaService;
use DE\RUB\PDFSealerExternalModule\Timestamp\TsaIdentity;
use DE\RUB\PDFSealerExternalModule\Timestamp\TsaPolicy;

if (getenv('PDF_SEALER_LIVE_TEST') !== '1' || $argc !== 2 || !in_array($argv[1], ['--preview', '--run'], true)
    || PHP_VERSION_ID < 80400) {
    throw new RuntimeException('Use PDF_SEALER_LIVE_TEST=1 php tools/crl_probe.php --preview|--run with PHP 8.4+ on the development instance');
}
$_SERVER['PHP_SELF'] = 'pdf_sealer_crl_probe.php';
require rtrim(getenv('PDF_SEALER_REDCAP_ROOT') ?: '/home/gr/redcap/codebase', '/') . '/Config/init_global.php';
require dirname(__DIR__) . '/tests/support/pdf_timestamp_checks.php';
checkSeal(db_query('SET SESSION TRANSACTION READ ONLY', [], null, MYSQLI_STORE_RESULT, true) !== false,
    'Cannot enforce a read-only diagnostic database session');
$framework = \ExternalModules\ExternalModules::getFrameworkInstance('pdf_sealer', 'v9.9.9');
$protector = new SecretProtector();
$identities = new IdentityRepository($framework, $protector);
$settings = new PrimarySystemSettingReader($framework);
$rootId = $identities->activeId('root');
checkSeal($rootId !== null, 'No active built-in root');
$storedRoot = $identities->find($rootId);
checkSeal($storedRoot !== null && $storedRoot->role === 'root', 'Active root unavailable');
$crl = (new CrlRepository($framework, $settings))->load($storedRoot->certificateDer);
checkSeal($crl !== null && $crl['this_update'] <= time() && $crl['next_update'] > time(), 'No current published CRL');
$url = CrlIssuer::url(APP_PATH_SURVEY_FULL, $storedRoot->certificateDer);
echo json_encode(['root_id' => $rootId, 'root_sha256' => hash('sha256', $storedRoot->certificateDer),
    'crl_url' => $url, 'crl_number' => $crl['number'], 'revoked_count' => count($crl['entries']),
    'database_writes' => false, 'temporary_certificates_only' => true], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
if ($argv[1] === '--preview') { exit; }
$organization = $settings->get('organization');
checkSeal(is_string($organization), 'Organization unavailable');
$root = $storedRoot->asGeneratedIdentity($protector);
$issuer = CertificateIssuer::forFramework($framework, 'diagnostic');
$project = $issuer->createProject($organization, $issuer->newProjectUuid(), $root);
$tsa = $issuer->createTsa($organization, $root);
$provider = new InternalTimestampProvider(new InternalTsaService(TsaPolicy::DEFAULT_OID),
    new TsaIdentity($tsa->certificateDer, $tsa->privateKey(), [$root->certificateDer]));
$recording = new class($provider) implements \DE\RUB\PDFSealerExternalModule\Timestamp\TimestampProvider {
    public string $request = '';
    public string $response = '';
    public function __construct(private readonly InternalTimestampProvider $provider) {}
    public function policyOid(): string { return $this->provider->policyOid(); }
    public function respond(string $requestDer, int $now): string {
        $this->request = $requestDer;
        return $this->response = $this->provider->respond($requestDer, $now);
    }
};
$sample = PkiDiagnosticService::samplePdf();
$now = time();
$sealed = (new PdfSealBuilder())->sealTimestamped($sample, $project->certificateDer,
    $project->privateKey(), [$root->certificateDer], $now, $recording);
$validator = new Crl(maxAge: 0, clockSkew: 0);
$der = base64_decode($crl['der_b64'], true);
foreach ([$project, $tsa] as $identity) {
    $parsed = openssl_x509_parse(Certificate::derToPem($identity->certificateDer));
    checkSeal(str_contains($parsed['extensions']['crlDistributionPoints'] ?? '', $url), 'CRL URL missing');
    $validator->validate($der, $root->certificateDer, $identity->certificateDer, time());
}
$output = dirname(__DIR__) . '/DEV_DOCS/interop-artifacts/crl-probe';
checkSeal(is_dir($output) || mkdir($output, 0700, true), 'Cannot create public artifact directory');
$pdfPath = $output . '/crl-probe-BT.pdf';
checkSeal(file_put_contents($pdfPath, $sealed->pdf) === strlen($sealed->pdf), 'Cannot save diagnostic PDF');
$rootPath = $output . '/root.pem';
file_put_contents($rootPath, Certificate::derToPem($root->certificateDer));
$crlPath = $output . '/issuer.crl';
file_put_contents($crlPath, $der);
verifyTimestampedSeal($sample, $sealed, Certificate::derToPem($root->certificateDer), $recording, $now);
foreach ([['qpdf', '--check', $pdfPath], ['pdfsig', '-nocert', '-no-ocsp', $pdfPath]] as $command) {
    [$status, $outputText] = runSealCommand($command);
    checkSeal($status === 0 && ($command[0] !== 'pdfsig' || str_contains($outputText, 'Signature Validation: Signature is Valid.')),
        'PDF structural/signature verification failed');
}
[$status, $verification] = runSealCommand(['openssl', 'crl', '-inform', 'DER', '-in', $crlPath,
    '-noout', '-verify', '-CAfile', $rootPath]);
checkSeal($status === 0 && str_contains($verification, 'verify OK'), 'OpenSSL rejected the live issuer CRL');
echo "CRL signature, temporary leaf CDPs/status, PDF signature and timestamp verified.\n" . $pdfPath . "\n";
