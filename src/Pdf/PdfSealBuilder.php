<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pdf;

use Com\Tecnick\Pdf\Sign\Cms\Certificate;
use Com\Tecnick\Pdf\Sign\Config;
use Com\Tecnick\Pdf\Sign\Output\Signature;
use Com\Tecnick\Pdf\Sign\Output\Widget;
use Com\Tecnick\Pdf\Sign\Signer;
use OpenSSLAsymmetricKey;

/** Builds an invisible, certification-level PAdES B-B seal in one appended revision. */
final class PdfSealBuilder
{
    private const DOC_MDP = ' /Reference [<< /Type /SigRef /TransformMethod /DocMDP'
        . ' /TransformParams << /Type /TransformParams /P 1 /V /1.2 >> >>]';

    public function __construct(
        private readonly PdfStructureInspector $inspector = new PdfStructureInspector(),
        private readonly IncrementalRevisionWriter $writer = new IncrementalRevisionWriter(),
        private readonly Signer $signer = new Signer(checkSignerCertificate: true),
    ) {
    }

    /** @param list<string> $chainCertsDer Issuer certificates, root included. */
    public function seal(
        string $originalPdf,
        string $projectCertDer,
        OpenSSLAsymmetricKey $privateKey,
        array $chainCertsDer,
        int $signingTime,
    ): string {
        if ($chainCertsDer === [] || !openssl_x509_check_private_key(Certificate::derToPem($projectCertDer), $privateKey)) {
            throw new \InvalidArgumentException('Project signing identity or root chain is invalid');
        }
        $pdf = $this->inspector->inspect($originalPdf);
        if ($pdf->hasExistingSignatures) {
            throw new UnsupportedPdf('A DocMDP certification seal must be the first signed field');
        }
        [$pageNumber, $pageGeneration] = self::splitReference($pdf->firstPageRef);
        if ($pageGeneration !== 0) {
            throw new UnsupportedPdf('Page generation is unsupported by the signature widget emitter');
        }

        $config = new Config(Config::PROFILE_PADES_B_B, 'sha256', 1);
        $next = $pdf->nextObjectNumber;
        $signatureNumber = $next++;
        $widgetNumber = $next++;
        $signatureRef = $signatureNumber . '_0';
        $widgetRef = $widgetNumber . '_0';
        $objects = [];

        $page = $pdf->firstPage;
        $annots = $this->appendArrayReference(
            PdfStructureInspector::value($page, 'Annots'), $widgetRef, $pdf, $objects,
        );
        $objects[$pdf->firstPageRef] = self::put($page, 'Annots', $annots);

        $acroForm = $pdf->acroForm ?? CosSerializer::dictionary([]);
        $fields = $this->appendArrayReference(
            PdfStructureInspector::value($acroForm, 'Fields'), $widgetRef, $pdf, $objects,
        );
        $acroForm = self::put($acroForm, 'Fields', $fields);
        $oldFlags = PdfStructureInspector::value($acroForm, 'SigFlags');
        if ($oldFlags !== null && (($oldFlags[0] ?? null) !== 'numeric'
            || preg_match('/^(?:0|[1-9][0-9]*)$/D', (string) ($oldFlags[1] ?? '')) !== 1)) {
            throw new UnsupportedPdf('Invalid AcroForm signature flags');
        }
        $acroForm = self::put($acroForm, 'SigFlags', self::number(((int) ($oldFlags[1] ?? 0)) | 3));
        $acroFormRef = $pdf->acroFormRef ?? ($next++ . '_0');
        $objects[$acroFormRef] = $acroForm;

        $permissions = $pdf->permissions ?? CosSerializer::dictionary([]);
        $permissions = self::put($permissions, 'DocMDP', CosSerializer::reference($signatureRef));
        $catalog = $pdf->catalog;
        if ($pdf->permissionsRef !== null) {
            $objects[$pdf->permissionsRef] = $permissions;
        } else {
            $catalog = self::put($catalog, 'Perms', $permissions);
        }
        $catalog = self::put($catalog, 'AcroForm', CosSerializer::reference($acroFormRef));
        if (self::needsVersionUpgrade($pdf)) {
            $catalog = self::put($catalog, 'Version', CosSerializer::name('1.7'));
        }
        $objects[$pdf->rootRef] = $catalog;

        $fieldName = 'REDCapSeal' . bin2hex(random_bytes(8));
        $widget = (new Widget())->annotation($widgetNumber, '', $pageNumber, $fieldName, $signatureNumber);
        $objects[$widgetRef] = self::emittedBody($widgetNumber, $widget);
        $date = '(D:' . gmdate('YmdHis', $signingTime) . 'Z)';
        $signature = (new Signature())->valueObject(
            $signatureNumber, $config->subFilter(), self::DOC_MDP, [], $date,
        );
        $objects[$signatureRef] = self::emittedBody($signatureNumber, $signature);

        $prepared = $this->writer->append($pdf, $objects);
        [$coveredPdf, $hexStart, $hexLength] = self::fixByteRange($prepared, strlen($originalPdf));
        $contentsStart = $hexStart - 1;
        $contentsEnd = $hexStart + $hexLength + 1;
        $coveredBytes = substr($coveredPdf, 0, $contentsStart) . substr($coveredPdf, $contentsEnd);
        $cmsDer = $this->signer->sign(
            $coveredBytes, $projectCertDer, $privateKey, $chainCertsDer, $config, $signingTime,
        );
        $hexCms = strtoupper(bin2hex($cmsDer));
        if (strlen($hexCms) > $hexLength) {
            throw new \LengthException('CMS signature exceeds reserved PDF Contents');
        }
        return substr_replace($coveredPdf, str_pad($hexCms, $hexLength, '0'), $hexStart, $hexLength);
    }

    /** @param array<string, array|string> $objects */
    private function appendArrayReference(?array $token, string $newRef, ExistingPdf $pdf, array &$objects): array
    {
        if ($token === null) {
            return ['[', [CosSerializer::reference($newRef)], 0];
        }
        if (($token[0] ?? null) === '[' && is_array($token[1] ?? null)) {
            $token[1][] = CosSerializer::reference($newRef);
            return $token;
        }
        if (($token[0] ?? null) === 'objref') {
            $ref = $token[1];
            $array = $pdf->indirectArrays[$ref] ?? null;
            if ($array === null || isset($objects[$ref])) {
                throw new UnsupportedPdf('Unsupported indirect field or annotation array');
            }
            $array[1][] = CosSerializer::reference($newRef);
            $objects[$ref] = $array;
            return $token;
        }
        throw new UnsupportedPdf('Fields or Annots is not an array');
    }

    private static function put(array $dictionary, string $key, array $value): array
    {
        if (($dictionary[0] ?? null) !== '<<' || !is_array($dictionary[1] ?? null)) {
            throw new UnsupportedPdf('Expected PDF dictionary');
        }
        for ($index = 0; $index + 1 < count($dictionary[1]); $index += 2) {
            if (($dictionary[1][$index][0] ?? null) === '/' && ($dictionary[1][$index][1] ?? null) === $key) {
                $dictionary[1][$index + 1] = $value;
                return $dictionary;
            }
        }
        $dictionary[1][] = CosSerializer::name($key);
        $dictionary[1][] = $value;
        return $dictionary;
    }

    private static function needsVersionUpgrade(ExistingPdf $pdf): bool
    {
        if (preg_match('/^%PDF-([0-9]+)\.([0-9]+)(?:\r|\n)/', $pdf->bytes, $header) !== 1) {
            throw new UnsupportedPdf('Invalid PDF version header');
        }
        $headerVersion = ((int) $header[1] * 10) + (int) $header[2];
        $catalogVersion = PdfStructureInspector::value($pdf->catalog, 'Version');
        $catalogNumber = 0;
        if ($catalogVersion !== null) {
            if (($catalogVersion[0] ?? null) !== '/'
                || preg_match('/^([0-9]+)\.([0-9]+)$/D', (string) ($catalogVersion[1] ?? ''), $version) !== 1) {
                throw new UnsupportedPdf('Invalid catalog PDF version');
            }
            $catalogNumber = ((int) $version[1] * 10) + (int) $version[2];
        }
        return max($headerVersion, $catalogNumber) < 17;
    }

    private static function number(int $value): array
    {
        return ['numeric', (string) $value, 0];
    }

    /** @return array{int,int} */
    private static function splitReference(string $ref): array
    {
        if (preg_match('/^([1-9][0-9]*)_([0-9]+)$/D', $ref, $parts) !== 1) {
            throw new UnsupportedPdf('Invalid page reference');
        }
        return [(int) $parts[1], (int) $parts[2]];
    }

    private static function emittedBody(int $number, string $object): string
    {
        $head = $number . " 0 obj\n";
        $tail = "\nendobj\n";
        if (!str_starts_with($object, $head) || !str_ends_with($object, $tail)) {
            throw new \RuntimeException('Tecnick emitted an unexpected PDF object');
        }
        return substr($object, strlen($head), -strlen($tail));
    }

    /** @return array{string,int,int} Final ByteRange bytes, hex start, reserved hex length. */
    private static function fixByteRange(string $prepared, int $sourceLength): array
    {
        $placeholder = Signature::BYTE_RANGE_PLACEHOLDER;
        $rangeOffset = strpos($prepared, $placeholder, $sourceLength);
        if ($rangeOffset === false || strpos($prepared, $placeholder, $rangeOffset + 1) !== false) {
            throw new \RuntimeException('PDF signature ByteRange placeholder is missing or ambiguous');
        }
        $contentsMarker = '/Contents<';
        $markerOffset = strpos($prepared, $contentsMarker, $rangeOffset + strlen($placeholder));
        if ($markerOffset === false) {
            throw new \RuntimeException('PDF signature Contents placeholder is missing');
        }
        $hexStart = $markerOffset + strlen($contentsMarker);
        $hexLength = Signature::DEFAULT_CONTENTS_LENGTH;
        $contentsStart = $hexStart - 1;
        $contentsEnd = $hexStart + $hexLength + 1;
        if (substr($prepared, $hexStart, $hexLength) !== str_repeat('0', $hexLength)
            || ($prepared[$contentsEnd - 1] ?? null) !== '>') {
            throw new \RuntimeException('PDF signature Contents placeholder changed');
        }
        $numbers = [$contentsStart, $contentsEnd, strlen($prepared) - $contentsEnd];
        if (max($numbers) > 9999999999) {
            throw new \LengthException('PDF ByteRange offset exceeds reserved width');
        }
        $range = sprintf('/ByteRange[0 %010d %010d %010d]', ...$numbers);
        if (strlen($range) !== strlen($placeholder)) {
            throw new \RuntimeException('PDF ByteRange width changed');
        }
        return [substr_replace($prepared, $range, $rangeOffset, strlen($placeholder)), $hexStart, $hexLength];
    }
}
