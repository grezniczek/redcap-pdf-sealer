<?php

declare(strict_types=1);

/** Synthetic content through the installed REDCap backend; no application/DB bootstrap. */
function createRedcapFixtures(string $core, string $directory): array
{
    foreach (['Libraries/tFPDF.php', 'Libraries/FPDF_HTML.php', 'Classes/PDF.php', 'Resources/images/redcap-logo-small.png'] as $file) {
        checkSeal(is_file($core . '/' . $file), 'Missing REDCap dependency: ' . $file);
    }
    checkSeal(extension_loaded('gd'), 'The fixture generator requires PHP GD');
    define('FONT', 'Arial'); // Built-in font avoids writing REDCap's Unicode font cache.
    define('USE_UTF8', false);
    define('FPDF_FONTPATH', $core . '/Resources/pdf/font/');
    define('NOW', '2026-09-26 12:00:00');
    define('LOGO_PATH', $core . '/Resources/images/');
    require $core . '/Libraries/tFPDF.php';
    require $core . '/Libraries/FPDF_HTML.php';
    require $core . '/Classes/PDF.php';

    // Only the footer's date presentation and constant are stubbed; rendering is Core's method.
    if (!class_exists('DateTimeRC', false)) {
        class DateTimeRC { public static function format_ts_from_ymd($value) { return $value; } }
    }
    if (!class_exists('System', false)) {
        class System { public const powered_by_redcap = 'Powered by REDCap'; }
    }
    $signaturePath = $directory . '/synthetic-signature.png';
    $image = imagecreatetruecolor(240, 80);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 255, 255, 255, 127));
    $ink = imagecolorallocatealpha($image, 20, 40, 100, 0);
    imagesetthickness($image, 3);
    // Deliberately artificial zigzag: no person's signature or project data.
    for ($x = 15; $x < 215; $x += 20) {
        imageline($image, $x, 55, $x + 10, 20, $ink);
        imageline($image, $x + 10, 20, $x + 20, 55, $ink);
    }
    checkSeal(imagepng($image, $signaturePath), 'Could not write synthetic signature');
    imagedestroy($image);

    $make = static function (string $name, int $pages, bool $footer, bool $landscape = false) use ($directory, $signaturePath): string {
        $GLOBALS['Proj'] = (object) ['project' => ['pdf_show_logo_url' => $footer ? '1' : '0']];
        $GLOBALS['project_encoding'] = '';
        $pdf = new FPDF_HTML();
        $pdf->SetCompression(true);
        $pdf->SetAutoPageBreak(false);
        for ($page = 1; $page <= $pages; ++$page) {
            $pdf->AddPage($landscape ? 'L' : 'P');
            if (!$landscape) { PDF::setFooterImage($pdf); }
            $pdf->SetXY(12, 18);
            $pdf->SetFont('Arial', 'B', 14);
            $pdf->Cell(0, 8, 'SYNTHETIC CONSENT TEST - ' . $name, 0, 1);
            $pdf->SetFont('Arial', '', 11);
            $pdf->MultiCell(180, 6, "Page $page of $pages. No participant data.\nI agree to participate in this fictional study.\nThe image below is generated test artwork.");
            if ($page === $pages) { $pdf->Image($signaturePath, 20, 65, 60, 20); }
        }
        $path = $directory . '/' . $name . '.pdf';
        $bytes = $pdf->Output('', 'S');
        checkSeal(file_put_contents($path, $bytes) === strlen($bytes), 'Could not write PDF fixture');
        return $path;
    };
    $single = $make('consent-signature', 1, true);
    $multi = $make('consent-multipage', 3, true);
    $noFooter = $make('consent-no-footer-link', 2, false);
    $annex = $make('landscape-attachment', 1, false, true);
    $merged = $directory . '/merged-attachment.pdf';
    [$status, $output] = runSealCommand(['qpdf', '--empty', '--pages', $single, '1', $annex, '1', '--', $merged]);
    checkSeal($status === 0, 'qpdf fixture merge failed: ' . $output);
    $compressed = $directory . '/merged-object-streams.pdf';
    [$status, $output] = runSealCommand(['qpdf', '--object-streams=generate', '--rotate=+90:2', $merged, $compressed]);
    checkSeal($status === 0, 'qpdf object-stream fixture failed: ' . $output);
    checkSeal(str_contains(file_get_contents($compressed), '/Type /ObjStm'), 'Fixture does not contain object streams');
    checkSeal(str_contains(file_get_contents($single), '/SMask'), 'Signature fixture lost its alpha mask');
    return [
        'consent-signature' => [$single, 1, 1],
        'consent-multipage' => [$multi, 3, 3],
        'consent-no-footer-link' => [$noFooter, 2, 0],
        'merged-attachment' => [$merged, 2, 1],
        'merged-object-streams' => [$compressed, 2, 1],
    ];
}

/** Ordered page/link inventory; resolves both inline and indirect annotations. */
function fixtureLinks(string $bytes): array
{
    [$xref, $objects] = (new \Com\Tecnick\Pdf\Parser\Parser(['decode_streams' => false, 'strict_limits' => true]))->parse($bytes);
    $resolve = static fn(array $value): array => $value[0] === 'objref' ? $objects[$value[1]][0] : $value;
    $walk = static function (array $node) use (&$walk, $resolve): array {
        $node = $resolve($node);
        if (dictionaryValue($node, 'Type')[1] === 'Pages') {
            $pages = [];
            foreach ($resolve(dictionaryValue($node, 'Kids'))[1] as $kid) { array_push($pages, ...$walk($kid)); }
            return $pages;
        }
        checkSeal(dictionaryValue($node, 'Type')[1] === 'Page', 'Unexpected page tree node');
        $links = [];
        $annots = dictionaryValue($node, 'Annots');
        foreach ($annots === null ? [] : $resolve($annots)[1] as $annotation) {
            $annotation = $resolve($annotation);
            if (dictionaryValue($annotation, 'Subtype')[1] !== 'Link') { continue; }
            $action = $resolve(dictionaryValue($annotation, 'A'));
            checkSeal(dictionaryValue($action, 'S')[1] === 'URI', 'Unexpected fixture link action');
            $links[] = [dictionaryValue($action, 'URI')[1], array_map(
                static fn(array $number): float => (float) $number[1], dictionaryValue($annotation, 'Rect')[1]
            )];
        }
        return [$links];
    };
    return $walk(dictionaryValue($objects[$xref['trailer']['root']][0], 'Pages'));
}

/** Independent Poppler rendering and text extraction, including every page. */
function fixturePresentation(string $path, string $prefix): array
{
    [$status, $output] = runSealCommand(['pdftoppm', '-r', '72', $path, $prefix]);
    checkSeal($status === 0, 'Poppler rendering failed: ' . $output);
    $pages = glob($prefix . '-*.ppm');
    natsort($pages);
    checkSeal($pages !== [], 'Poppler rendered no pages');
    $hashes = [];
    foreach ($pages as $page) {
        $hashes[] = hash_file('sha256', $page);
        unlink($page);
    }
    [$status, $text] = runSealCommand(['pdftotext', '-layout', $path, '-']);
    checkSeal($status === 0, 'Poppler text extraction failed: ' . $text);
    return [$hashes, $text];
}
