<?php

declare(strict_types=1);

use DE\RUB\PDFSealerExternalModule\Pdf\CosSerializer;
use DE\RUB\PDFSealerExternalModule\Pdf\IncrementalRevisionWriter;
use DE\RUB\PDFSealerExternalModule\Pdf\PdfStructureInspector;
use DE\RUB\PDFSealerExternalModule\Pdf\UnsupportedPdf;

require dirname(__DIR__) . '/vendor/autoload.php';

function checkPdf(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function rejectPdf(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (UnsupportedPdf $error) {
        return;
    }
    throw new RuntimeException($message);
}

function checkWithQpdf(string $pdf): void
{
    $path = tempnam(sys_get_temp_dir(), 'pdf_sealer_qpdf_');
    checkPdf(is_string($path), 'Could not create qpdf test file');
    try {
        checkPdf(file_put_contents($path, $pdf) === strlen($pdf), 'Could not write qpdf test file');
        $process = proc_open(['qpdf', '--check', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        checkPdf(is_resource($process), 'Could not start qpdf');
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        checkPdf(proc_close($process) === 0, 'qpdf --check failed: ' . $output);
    } finally {
        unlink($path);
    }
}

function fixturePdf(): string
{
    $pdf = "%PDF-1.4\n";
    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R /AcroForm 4 0 R >>',
        2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] >>',
        4 => '<< /Fields [] /SigFlags 0 >>',
    ];
    $offsets = [];
    foreach ($objects as $number => $body) {
        $offsets[$number] = strlen($pdf);
        $pdf .= $number . " 0 obj\n" . $body . "\nendobj\n";
    }
    $startXref = strlen($pdf);
    $pdf .= "xref\n0 5\n0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= sprintf('%010d 00000 n ', $offset) . "\n";
    }
    return $pdf . "trailer\n<< /Size 5 /Root 1 0 R >>\nstartxref\n"
        . $startXref . "\n%%EOF\n";
}

$inspector = new PdfStructureInspector();
$writer = new IncrementalRevisionWriter();
$original = fixturePdf();
$parsed = $inspector->inspect($original);
checkPdf($parsed->rootRef === '1_0' && $parsed->firstPageRef === '3_0', 'Catalog or first page was not resolved');
checkPdf($parsed->acroFormRef === '4_0' && $parsed->nextObjectNumber === 5, 'AcroForm or next object was not resolved');
checkPdf($inspector->inspect(str_replace('/Size 5', '/Size 3', $original))->nextObjectNumber === 5,
    'Object allocation trusted a trailer Size below an observed object');
checkPdf($inspector->inspect(str_replace('/Size 5', '/Size 9', $original))->nextObjectNumber === 9,
    'Object allocation ignored a larger trailer Size');
$probe = CosSerializer::dictionary(['Type' => CosSerializer::name('PDFSealerProbe')]);
$revised = $writer->append($parsed, [$parsed->rootRef => $parsed->catalog, '5_0' => $probe]);
checkPdf(str_starts_with($revised, $original), 'Incremental revision changed source bytes');
checkPdf(str_contains(substr($revised, strlen($original)), '/Prev ' . $parsed->startXref), 'Trailer /Prev is incorrect');
$again = $inspector->inspect($revised);
checkPdf($again->nextObjectNumber === 6 && $again->rootRef === $parsed->rootRef, 'Revised PDF does not resolve');
checkPdf($again->acroFormRef === $parsed->acroFormRef, 'Existing AcroForm was lost');
checkWithQpdf($revised);

rejectPdf(static fn() => $inspector->inspect('garbage'), 'Invalid PDF was accepted');
rejectPdf(static fn() => $inspector->inspect(str_replace('/Root 1 0 R', '/Root 1 0 R /Encrypt 4 0 R', $original)),
    'Encrypted PDF was accepted');
$certified = $parsed->catalog;
$certified[1][] = CosSerializer::name('Perms');
$certified[1][] = CosSerializer::dictionary(['DocMDP' => CosSerializer::reference('4_0')]);
rejectPdf(static fn() => $inspector->inspect($writer->append($parsed, ['1_0' => $certified])),
    'Existing DocMDP was accepted');
$indirectCertified = $parsed->catalog;
$indirectCertified[1][] = CosSerializer::name('Perms');
$indirectCertified[1][] = CosSerializer::reference('5_0');
rejectPdf(static fn() => $inspector->inspect($writer->append($parsed, [
    '1_0' => $indirectCertified,
    '5_0' => CosSerializer::dictionary(['DocMDP' => CosSerializer::reference('4_0')]),
])), 'Indirect DocMDP was accepted');
try {
    $writer->append($parsed, ['4_1' => $probe]);
    throw new RuntimeException('Writer reused an unobserved generation');
} catch (InvalidArgumentException $expected) {
}

// Local development fixtures provide additional xref-stream, ObjStm and AcroForm coverage.
$localFixtures = [
    '/home/gr/redcap/dev-modules/pdf_injector_v9.9.9/tests/files/pdfi_blank_readable.pdf',
    '/home/gr/redcap/dev-modules/redcap_pdf_form_v9.9.9/PDF/Test.pdf',
    '/home/gr/redcap/codebase/Resources/PDFJS/web/compressed.tracemonkey-pldi-09.pdf',
];
$tested = 0;
foreach ($localFixtures as $path) {
    if (!is_file($path)) {
        continue;
    }
    $source = file_get_contents($path);
    checkPdf(is_string($source), 'Could not read PDF fixture');
    $input = $inspector->inspect($source);
    $revision = $writer->append($input, [
        $input->rootRef => $input->catalog,
        $input->nextObjectNumber . '_0' => $probe,
    ]);
    checkPdf(str_starts_with($revision, $source), 'Local PDF source bytes changed');
    checkPdf($inspector->inspect($revision)->nextObjectNumber === $input->nextObjectNumber + 1,
        'Local PDF revision did not parse');
    checkWithQpdf($revision);
    ++$tested;
}

echo 'PDF structural adapter and qpdf checks passed (', $tested, " optional local fixtures).\n";
