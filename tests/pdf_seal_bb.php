<?php

declare(strict_types=1);

use Com\Tecnick\Pdf\Parser\Parser;
use Com\Tecnick\Pdf\Sign\Cms\Certificate;
use DE\RUB\PDFSealerExternalModule\Pdf\PdfSealBuilder;
use DE\RUB\PDFSealerExternalModule\Pdf\PdfStructureInspector;
use DE\RUB\PDFSealerExternalModule\Pdf\UnsupportedPdf;
use DE\RUB\PDFSealerExternalModule\Pki\CertificateIssuer;

require dirname(__DIR__) . '/vendor/autoload.php';

function checkSeal(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function runSealCommand(array $command): array
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    checkSeal(is_resource($process), 'Could not start ' . $command[0]);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $output];
}

function assemblePdf(array $bodies): string
{
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($bodies as $number => $body) {
        $offsets[$number] = strlen($pdf);
        $pdf .= $number . " 0 obj\n" . $body . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($bodies) + 1) . "\n0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= sprintf('%010d 00000 n ', $offset) . "\n";
    }
    return $pdf . "trailer\n<< /Size " . (count($bodies) + 1)
        . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
}

function testPdf(): string
{
    return assemblePdf([
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] >>',
    ]);
}

function testPdfWithIndirectArrays(): string
{
    return assemblePdf([
        1 => '<< /Type /Catalog /Pages 2 0 R /AcroForm 4 0 R /Perms 7 0 R >>',
        2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] /Annots 6 0 R >>',
        4 => '<< /Fields 5 0 R /SigFlags 0 >>',
        5 => '[8 0 R]',
        6 => '[9 0 R]',
        7 => '<< /UR3 null >>',
        8 => '<< /FT /Tx /T (existing) >>',
        9 => '<< /Type /Annot /Subtype /Text /Rect [0 0 10 10] >>',
    ]);
}

function testPdfWithExistingSignature(bool $signed): string
{
    return assemblePdf([
        1 => '<< /Type /Catalog /Pages 2 0 R /AcroForm 4 0 R >>',
        2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] >>',
        4 => '<< /Fields [5 0 R] >>',
        5 => '<< /FT /Sig /T (prior) ' . ($signed ? '/V 6 0 R ' : '') . '>>',
        6 => $signed ? '<< /Filter /Adobe.PPKLite /SubFilter /adbe.pkcs7.detached /ByteRange [0 1 2 3] /Contents <> >>'
            : '<< /Type /Example >>',
    ]);
}

function dictionaryValue(array $dictionary, string $key): ?array
{
    return PdfStructureInspector::value($dictionary, $key);
}

function verifySeal(string $source, string $sealed, string $rootPem): string
{
    checkSeal(str_starts_with($sealed, $source), 'Sealing changed the original PDF prefix');
    [$xref, $objects] = (new Parser(['decode_streams' => false, 'strict_limits' => true]))->parse($sealed);
    $catalog = $objects[$xref['trailer']['root']][0];
    if (preg_match('/^%PDF-1\.[0-6](?:\r|\n)/', $source) === 1) {
        checkSeal(dictionaryValue($catalog, 'Version')[1] === '1.7',
            'PAdES revision did not upgrade the catalog PDF version');
    }
    $permissions = dictionaryValue($catalog, 'Perms');
    if (($permissions[0] ?? null) === 'objref') {
        $permissions = $objects[$permissions[1]][0];
    }
    checkSeal(($permissions[0] ?? null) === '<<', 'Missing catalog permissions');
    $sigRef = dictionaryValue($permissions, 'DocMDP');
    checkSeal(($sigRef[0] ?? null) === 'objref', 'Missing DocMDP signature reference');
    $signature = $objects[$sigRef[1]][0];
    checkSeal(dictionaryValue($signature, 'SubFilter')[1] === 'ETSI.CAdES.detached', 'Wrong signature SubFilter');
    $references = dictionaryValue($signature, 'Reference');
    checkSeal(($references[0] ?? null) === '[' && count($references[1]) === 1, 'Missing DocMDP transform');
    $transform = $references[1][0];
    checkSeal(dictionaryValue($transform, 'TransformMethod')[1] === 'DocMDP', 'Wrong transform method');
    checkSeal(dictionaryValue(dictionaryValue($transform, 'TransformParams'), 'P')[1] === '1', 'Wrong DocMDP level');
    $acro = dictionaryValue($catalog, 'AcroForm');
    checkSeal(($acro[0] ?? null) === 'objref', 'Missing AcroForm reference');
    $acroForm = $objects[$acro[1]][0];
    $fields = dictionaryValue($acroForm, 'Fields');
    if (($fields[0] ?? null) === 'objref') {
        $fields = $objects[$fields[1]][0];
    }
    checkSeal(($fields[0] ?? null) === '[' && count($fields[1]) >= 1, 'Missing signature field');
    $lastField = end($fields[1]);
    $widget = $objects[$lastField[1]][0];
    checkSeal(dictionaryValue($widget, 'FT')[1] === 'Sig'
        && dictionaryValue($widget, 'V')[1] === $sigRef[1], 'Widget does not point to the signature');
    $firstPageRef = (new PdfStructureInspector())->inspect($source)->firstPageRef;
    $page = $objects[$firstPageRef][0];
    $annots = dictionaryValue($page, 'Annots');
    if (($annots[0] ?? null) === 'objref') {
        $annots = $objects[$annots[1]][0];
    }
    checkSeal(($annots[0] ?? null) === '[' && end($annots[1])[1] === $lastField[1],
        'Signature widget was not attached to the first page');
    if (str_contains($source, '/Fields 5 0 R')) {
        checkSeal($fields[1][0][1] === '8_0' && $annots[1][0][1] === '9_0'
            && dictionaryValue($objects['7_0'][0], 'UR3')[0] === 'null',
            'Existing fields, annotations, or permissions were lost');
    }

    $range = dictionaryValue($signature, 'ByteRange');
    checkSeal(($range[0] ?? null) === '[' && count($range[1]) === 4, 'Invalid ByteRange array');
    $numbers = array_map(static fn(array $value): int => (int) $value[1], $range[1]);
    [$zero, $start, $end, $tail] = $numbers;
    checkSeal($zero === 0 && $start > strlen($source) && $end > $start
        && $end + $tail === strlen($sealed)
        && $sealed[$start] === '<' && $sealed[$end - 1] === '>', 'ByteRange offsets are incorrect');
    $hex = substr($sealed, $start + 1, $end - $start - 2);
    $padded = hex2bin($hex);
    checkSeal(is_string($padded) && ord($padded[0]) === 0x30, 'Signature Contents is not DER');
    $lengthOctet = ord($padded[1]);
    $lengthBytes = $lengthOctet & 0x7f;
    $cmsLength = ($lengthOctet & 0x80) === 0
        ? 2 + $lengthOctet : 2 + $lengthBytes + (int) hexdec(bin2hex(substr($padded, 2, $lengthBytes)));
    checkSeal($cmsLength <= strlen($padded), 'CMS exceeds reserved Contents');
    $cms = substr($padded, 0, $cmsLength);
    $covered = substr($sealed, 0, $start) . substr($sealed, $end);

    $files = [];
    try {
        foreach (['pdf' => $sealed, 'cms' => $cms, 'covered' => $covered, 'root' => $rootPem] as $name => $bytes) {
            $path = tempnam(sys_get_temp_dir(), 'pdf_sealer_bb_' . $name . '_');
            checkSeal(is_string($path) && file_put_contents($path, $bytes) === strlen($bytes), 'Could not write test file');
            $files[$name] = $path;
        }
        [$status, $output] = runSealCommand(['qpdf', '--check', $files['pdf']]);
        checkSeal($status === 0, 'qpdf rejected sealed PDF: ' . $output);
        // The disposable test root is verified separately by OpenSSL below.
        [$status, $output] = runSealCommand(['pdfsig', '-nocert', '-no-ocsp', $files['pdf']]);
        checkSeal($status === 0 && str_contains($output, 'Signature Type: ETSI.CAdES.detached')
            && str_contains($output, 'Total document signed')
            && substr_count($output, 'Signature Validation: Signature is Valid.') === 1,
            'pdfsig did not recognize one valid, complete PAdES signature: ' . $output);
        checkSeal(preg_match('/Signed Ranges: \[0 - ([0-9]+)\], \[([0-9]+) - ([0-9]+)\]/', $output, $signedRanges) === 1
            && (int) $signedRanges[1] === $start
            && (int) $signedRanges[2] === $end
            && (int) $signedRanges[3] === strlen($sealed),
            'pdfsig reported unexpected signed ranges: ' . $output);
        $openssl = ['openssl', 'cms', '-verify', '-inform', 'DER', '-in', $files['cms'],
            '-content', $files['covered'], '-CAfile', $files['root'], '-purpose', 'any', '-binary', '-out', '/dev/null'];
        [$status, $output] = runSealCommand($openssl);
        checkSeal($status === 0, 'OpenSSL rejected signature or root chain: ' . $output);
        [$status, $attributes] = runSealCommand([
            'openssl', 'cms', '-cmsout', '-print', '-inform', 'DER', '-in', $files['cms'],
        ]);
        checkSeal($status === 0 && str_contains($attributes, '1.2.840.113549.1.9.16.2.47')
            && !str_contains($attributes, '1.2.840.113549.1.9.5'),
            'CMS is missing signingCertificateV2 or includes forbidden signingTime');
        $covered[5] = $covered[5] === 'X' ? 'Y' : 'X';
        file_put_contents($files['covered'], $covered);
        [$status] = runSealCommand($openssl);
        checkSeal($status !== 0, 'Tampering with a protected byte did not invalidate the signature');
    } finally {
        foreach ($files as $path) {
            unlink($path);
        }
    }
    try {
        (new PdfStructureInspector())->inspect($sealed);
        throw new RuntimeException('A second certification seal was accepted');
    } catch (UnsupportedPdf $expected) {
    }
    return $cms;
}

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
$cases = [testPdf(), testPdfWithIndirectArrays(), testPdfWithExistingSignature(false)];
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
