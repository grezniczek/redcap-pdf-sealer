<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pdf;

/** Writes changed and new indirect objects, retaining the latest revision's xref format. */
final class IncrementalRevisionWriter
{
    public function __construct(private readonly CosSerializer $cos = new CosSerializer())
    {
    }

    /** @param array<string, array|string> $objects Map of refs to COS tokens or trusted dictionary bodies. */
    public function append(ExistingPdf $pdf, array $objects): string
    {
        if ($objects === []) {
            throw new \InvalidArgumentException('Incremental revision requires an object');
        }

        $entries = [];
        $highest = $pdf->nextObjectNumber - 1;
        foreach ($objects as $reference => $token) {
            if (!is_string($reference)
                || preg_match('/^([1-9][0-9]*)_([0-9]+)$/D', $reference, $parts) !== 1) {
                throw new \InvalidArgumentException('Invalid indirect object reference');
            }
            $number = filter_var($parts[1], FILTER_VALIDATE_INT);
            $generation = filter_var($parts[2], FILTER_VALIDATE_INT);
            if ($number === false || $generation === false || $number >= PHP_INT_MAX - 1 || $generation > 65535
                || ($number < $pdf->nextObjectNumber && !isset($pdf->objectRefs[$reference]))
                || ($number >= $pdf->nextObjectNumber && $generation !== 0)) {
                throw new \InvalidArgumentException('Object number or generation cannot be used');
            }
            if (is_array($token)) {
                $body = $this->cos->serialize($token);
            } elseif (is_string($token) && str_starts_with($token, '<<')
                && str_ends_with($token, '>>') && !str_contains($token, 'endobj')) {
                // Tecnick's PDF signature/widget emitters provide complete objects.
                // The builder extracts only their validated dictionary bodies.
                $body = $token;
            } else {
                throw new \InvalidArgumentException('Invalid indirect object body');
            }
            $entries[$number] = [$generation, $body];
            $highest = max($highest, $number);
        }
        if (count($entries) !== count($objects)) {
            throw new \InvalidArgumentException('A revision cannot contain two generations of one object');
        }
        ksort($entries, SORT_NUMERIC);

        $output = $pdf->bytes;
        if (!str_ends_with($output, "\n") && !str_ends_with($output, "\r")) {
            $output .= "\n";
        }
        $offsets = [];
        foreach ($entries as $number => [$generation, $body]) {
            $offsets[$number] = strlen($output);
            $output .= $number . ' ' . $generation . " obj\n" . $body . "\nendobj\n";
        }
        $xrefOffset = strlen($output);
        if (substr($pdf->bytes, $pdf->startXref, 4) !== 'xref') {
            // Continue an xref-stream file with a stream, including its own entry.
            // Keep signature discovery compatible with readers expecting that format.
            if ($highest >= PHP_INT_MAX - 2) {
                throw new \LengthException('No object number available for the xref stream');
            }
            $xrefNumber = $highest + 1;
            $entries[$xrefNumber] = [0];
            $offsets[$xrefNumber] = $xrefOffset;
            $index = [];
            $stream = '';
            foreach ($entries as $number => [$generation]) {
                $index[] = $number . ' 1';
                // Type 1, unsigned big-endian 64-bit offset, 16-bit generation.
                $stream .= pack('CJn', 1, $offsets[$number], $generation);
            }
            $output .= $xrefNumber . " 0 obj\n<< /Type /XRef /W [1 8 2] /Index ["
                . implode(' ', $index) . '] /Length ' . strlen($stream)
                . $this->trailerEntries($pdf, $xrefNumber + 1)
                . " >>\nstream\n" . $stream . "\nendstream\nendobj\n";
            return $output . "startxref\n" . $xrefOffset . "\n%%EOF\n";
        }
        $output .= "xref\n";
        foreach ($entries as $number => [$generation]) {
            if ($offsets[$number] > 9999999999) {
                throw new \LengthException('Classic xref offset exceeds ten digits');
            }
            $output .= $number . " 1\n" . sprintf('%010d %05d n ', $offsets[$number], $generation) . "\n";
        }
        $output .= "trailer\n<<" . $this->trailerEntries($pdf, $highest + 1)
            . " >>\nstartxref\n" . $xrefOffset . "\n%%EOF\n";
        return $output;
    }

    private function trailerEntries(ExistingPdf $pdf, int $size): string
    {
        $output = ' /Size ' . $size
            . ' /Root ' . $this->cos->serialize(CosSerializer::reference($pdf->rootRef))
            . ' /Prev ' . $pdf->startXref;
        if ($pdf->infoRef !== null) {
            $output .= ' /Info ' . $this->cos->serialize(CosSerializer::reference($pdf->infoRef));
        }
        if ($pdf->documentIds !== []) {
            $output .= ' /ID [<' . $pdf->documentIds[0] . '> <' . $pdf->documentIds[1] . '>]';
        }
        return $output;
    }
}
