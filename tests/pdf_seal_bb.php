<?php

declare(strict_types=1);

use Com\Tecnick\Pdf\Sign\Cms\Certificate;
use DE\RUB\PDFSealerExternalModule\Pdf\PdfSealBuilder;
use DE\RUB\PDFSealerExternalModule\Pdf\PdfStructureInspector;
use DE\RUB\PDFSealerExternalModule\Pdf\UnsupportedPdf;
use DE\RUB\PDFSealerExternalModule\Pki\CertificateIssuer;

require_once __DIR__ . '/support/pdf_seal_checks.php';

$issuer = new CertificateIssuer(static function (): string {
    $path = tempnam(sys_get_temp_dir(), 'pdf_sealer_cert_');
    checkSeal(is_string($path), 'Could not create temporary certificate config');
    return $path;
});
$root = $issuer->createRoot('PDF Seal Test');
$project = $issuer->createProject('PDF Seal Test', $issuer->newProjectUuid(), $root);
$builder = new PdfSealBuilder();
$rootPem = Certificate::derToPem($root->certificateDer);
$paths = [
    '/home/gr/redcap/dev-modules/pdf_injector_v9.9.9/tests/files/pdfi_blank_readable.pdf',
    '/home/gr/redcap/dev-modules/redcap_pdf_form_v9.9.9/PDF/Test.pdf',
    '/home/gr/redcap/codebase/Resources/PDFJS/web/compressed.tracemonkey-pldi-09.pdf',
];
try {
    $builder->seal(testPdfWithExistingSignature(true), $project->certificateDer,
        $project->privateKey(), [$root->certificateDer], time());
    throw new RuntimeException('Certification after an existing signed field was accepted');
} catch (UnsupportedPdf $expected) {
}
$cases = [testPdf(), testPdfWithUriLink(false), testPdfWithUriLink(true),
    testPdfWithIndirectArrays(), testPdfWithExistingSignature(false)];
$redcapPdfPath = getenv('PDF_SEALER_REDCAP_PDF_PATH');
if ($redcapPdfPath !== false && $redcapPdfPath !== '') {
    checkSeal(str_starts_with($redcapPdfPath, '/') && is_file($redcapPdfPath),
        'PDF_SEALER_REDCAP_PDF_PATH must name an exported PDF file');
    $paths[] = $redcapPdfPath;
}
foreach ($paths as $path) {
    if (is_file($path)) {
        $bytes = file_get_contents($path);
        checkSeal(is_string($bytes), 'Could not read local fixture');
        $cases[] = $bytes;
    }
}
foreach ($cases as $source) {
    $signed = $builder->seal($source, $project->certificateDer, $project->privateKey(), [$root->certificateDer], time());
    verifySeal($source, $signed, $rootPem);
}
echo 'PAdES B-B seal, DocMDP, qpdf, pdfsig, OpenSSL chain, and tamper checks passed (', count($cases), " PDFs).\n";
