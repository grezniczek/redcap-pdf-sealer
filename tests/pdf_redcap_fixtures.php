<?php

declare(strict_types=1);

use Com\Tecnick\Pdf\Sign\Cms\Certificate;
use DE\RUB\PDFSealerExternalModule\Pdf\PdfSealBuilder;
use DE\RUB\PDFSealerExternalModule\Pki\CertificateIssuer;
use DE\RUB\PDFSealerExternalModule\Timestamp\InternalTimestampProvider;
use DE\RUB\PDFSealerExternalModule\Timestamp\InternalTsaService;
use DE\RUB\PDFSealerExternalModule\Timestamp\TimestampProvider;
use DE\RUB\PDFSealerExternalModule\Timestamp\TsaIdentity;
use DE\RUB\PDFSealerExternalModule\Timestamp\TsaPolicy;

require __DIR__ . '/support/pdf_timestamp_checks.php';
require __DIR__ . '/support/redcap_pdf_fixtures.php';

$core = rtrim(getenv('PDF_SEALER_REDCAP_ROOT') ?: '/home/gr/redcap/codebase', '/');
$directory = sys_get_temp_dir() . '/pdf_sealer_fixtures_' . bin2hex(random_bytes(8));
checkSeal(mkdir($directory, 0700), 'Could not create fixture directory');
try {
    $cases = createRedcapFixtures($core, $directory);
    $issuer = new CertificateIssuer(static fn(): string => tempnam($directory, 'cert_'));
    $root = $issuer->createRoot('Synthetic Fixture Test');
    $project = $issuer->createProject('Synthetic Fixture Test', $issuer->newProjectUuid(), $root);
    $tsa = $issuer->createTsa('Synthetic Fixture Test', $root);
    $rootPem = Certificate::derToPem($root->certificateDer);
    $provider = new class(new InternalTimestampProvider(
        new InternalTsaService(TsaPolicy::DEFAULT_OID),
        new TsaIdentity($tsa->certificateDer, $tsa->privateKey(), [$root->certificateDer]),
    )) implements TimestampProvider {
        public string $request = '';
        public string $response = '';
        public function __construct(private InternalTimestampProvider $internal) {}
        public function policyOid(): string { return $this->internal->policyOid(); }
        public function respond(string $requestDer, int $now): string
        {
            $this->request = $requestDer;
            return $this->response = $this->internal->respond($requestDer, $now);
        }
    };
    $builder = new PdfSealBuilder();
    foreach ($cases as $name => [$path, $pageCount, $linkCount]) {
        $source = file_get_contents($path);
        [$status, $output] = runSealCommand(['qpdf', '--check', $path]);
        checkSeal($status === 0, $name . ': source invalid: ' . $output);
        $links = fixtureLinks($source);
        checkSeal(count($links) === $pageCount && array_sum(array_map('count', $links)) === $linkCount,
            $name . ': unexpected source pages/links');
        foreach ($links as $pageLinks) {
            foreach ($pageLinks as [$uri]) { checkSeal($uri === 'https://projectredcap.org', 'Unexpected footer URI'); }
        }
        [$status, $images] = runSealCommand(['pdfimages', '-list', $path]);
        checkSeal($status === 0 && preg_match('/\bimage\s+240\s+80\s/', $images) === 1,
            $name . ': synthetic signature image missing');
        $presentation = fixturePresentation($path, $directory . '/source');
        checkSeal(count($presentation[0]) === $pageCount && str_contains($presentation[1], 'SYNTHETIC CONSENT TEST'),
            $name . ': source rendering/text missing');
        foreach (['bb', 'bt'] as $profile) {
            $now = time();
            if ($profile === 'bb') {
                $sealed = $builder->seal($source, $project->certificateDer, $project->privateKey(), [$root->certificateDer], $now);
                verifySeal($source, $sealed, $rootPem);
            } else {
                $result = $builder->sealTimestamped($source, $project->certificateDer, $project->privateKey(), [$root->certificateDer], $now, $provider);
                $sealed = $result->pdf;
                verifyTimestampedSeal($source, $result, $rootPem, $provider, $now);
            }
            checkSeal(fixtureLinks($sealed) === $links, $name . ': sealing changed page order or footer links');
            $sealedPath = $directory . '/sealed.pdf';
            checkSeal(file_put_contents($sealedPath, $sealed) === strlen($sealed), 'Could not write sealed fixture');
            checkSeal(fixturePresentation($sealedPath, $directory . '/sealed') === $presentation,
                $name . ': sealing changed page pixels or extracted text');
            echo "$name: $profile signature, page rendering, text, and links passed ($pageCount pages).\n";
        }
    }
} finally {
    foreach (glob($directory . '/*') as $path) { unlink($path); }
    rmdir($directory);
}
echo "Synthetic REDCap-backend fixtures: all five cases passed for B-B and B-T.\n";
