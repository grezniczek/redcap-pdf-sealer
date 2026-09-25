<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pdf;

use Com\Tecnick\Pdf\Sign\Cms\Certificate;
use Com\Tecnick\Pdf\Sign\Cms\Oid;
use Com\Tecnick\Pdf\Sign\Config;
use Com\Tecnick\Pdf\Sign\Output\Signature;
use Com\Tecnick\Pdf\Sign\Output\Widget;
use Com\Tecnick\Pdf\Sign\Signer;
use Com\Tecnick\Pdf\Sign\Timestamp\Client as TimestampClient;
use Com\Tecnick\Pdf\Sign\Timestamp\Config as TimestampConfig;
use DateTimeImmutable;
use DateTimeZone;
use DE\RUB\PDFSealerExternalModule\Timestamp\PolicyOidAsn1;
use DE\RUB\PDFSealerExternalModule\Timestamp\TimestampProvider;
use OpenSSLAsymmetricKey;

/** Builds an invisible, certification-level PAdES B-B or B-T seal in one appended revision. */
final class PdfSealBuilder
{
    private const TIMESTAMPED_CONTENTS_LENGTH = 32_768;
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
        return $this->build($originalPdf, $projectCertDer, $privateKey, $chainCertsDer, $signingTime, null)->pdf;
    }

    /** @param list<string> $chainCertsDer Issuer certificates, root included. */
    public function sealTimestamped(
        string $originalPdf,
        string $projectCertDer,
        OpenSSLAsymmetricKey $privateKey,
        array $chainCertsDer,
        int $signingTime,
        TimestampProvider $timestampProvider,
        ?int $timestampNow = null,
    ): PdfSealResult {
        return $this->build($originalPdf, $projectCertDer, $privateKey, $chainCertsDer, $signingTime, $timestampProvider, $timestampNow);
    }

    /** @param list<string> $chainCertsDer Issuer certificates, root included. */
    private function build(
        string $originalPdf,
        string $projectCertDer,
        OpenSSLAsymmetricKey $privateKey,
        array $chainCertsDer,
        int $signingTime,
        ?TimestampProvider $timestampProvider,
        ?int $timestampNow = null,
    ): PdfSealResult {
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

        $profile = $timestampProvider === null ? Config::PROFILE_PADES_B_B : Config::PROFILE_PADES_B_T;
        $config = new Config($profile, 'sha256', 1);
        $contentsLength = $timestampProvider === null
            ? Signature::DEFAULT_CONTENTS_LENGTH : self::TIMESTAMPED_CONTENTS_LENGTH;
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
        $annots = $this->externalizeInlineLinks($annots, $objects, $next);
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
            $signatureNumber, $config->subFilter(), self::DOC_MDP, [], $date, $contentsLength,
        );
        $objects[$signatureRef] = self::emittedBody($signatureNumber, $signature);

        $prepared = $this->writer->append($pdf, $objects);
        [$coveredPdf, $hexStart, $hexLength] = self::fixByteRange($prepared, strlen($originalPdf), $contentsLength);
        $contentsStart = $hexStart - 1;
        $contentsEnd = $hexStart + $hexLength + 1;
        $coveredBytes = substr($coveredPdf, 0, $contentsStart) . substr($coveredPdf, $contentsEnd);
        $timestampClient = $timestampProvider === null ? null : new TimestampClient(new TimestampConfig(
            'http://localhost.invalid/tsa',
        ), new PolicyOidAsn1($timestampProvider->policyOid()));
        // Tecnick uses this client only as an RFC 3161 codec; the provider owns transport.
        $timestampNow ??= time();
        $transport = $timestampProvider === null ? null
            : static fn(string $requestDer): string => $timestampProvider->respond($requestDer, $timestampNow);
        $cmsDer = $this->signer->sign(
            $coveredBytes, $projectCertDer, $privateKey, $chainCertsDer, $config, $signingTime,
            $timestampClient, $transport, timestampNow: $timestampNow,
        );
        $hexCms = strtoupper(bin2hex($cmsDer));
        if (strlen($hexCms) > $hexLength) {
            throw new \LengthException('CMS signature exceeds reserved PDF Contents');
        }
        $sealed = substr_replace($coveredPdf, str_pad($hexCms, $hexLength, '0'), $hexStart, $hexLength);
        if ($timestampProvider === null) {
            return new PdfSealResult($sealed, $profile);
        }
        [$serialHex, $time] = $this->timestampMetadata($cmsDer, $timestampProvider->policyOid());
        return new PdfSealResult($sealed, $profile, $serialHex, $time);
    }

    /** @return array{string,int} Timestamp serial hex and generation time. */
    private function timestampMetadata(string $cmsDer, string $policyOid): array
    {
        $tokens = $this->signer->signatureTimestampTokens($cmsDer);
        if (count($tokens) !== 1) {
            throw new \RuntimeException('PAdES B-T CMS must contain one timestamp token');
        }
        $asn1 = new PolicyOidAsn1($policyOid);
        $certificate = new Certificate($asn1);
        $offset = 0;
        [$contentType, $content] = $certificate->encapsulatedContent(
            $certificate->signedDataContent($tokens[0]), $offset,
        );
        if ($contentType !== $asn1->encodeObjectIdentifier(Oid::TST_INFO)) {
            throw new \RuntimeException('Timestamp token has the wrong content type');
        }
        $info = $asn1->readSingleElement($content, 0x30, 'TSTInfo');
        $fields = [];
        $offset = 0;
        while ($offset < strlen($info['value'])) {
            $fields[] = $asn1->readTlv($info['value'], $offset);
        }
        if (($fields[1]['raw'] ?? null) !== $asn1->encodeObjectIdentifier($policyOid)) {
            throw new \RuntimeException('Timestamp token policy does not match the configured policy');
        }
        if (($fields[3]['tag'] ?? null) !== 0x02 || ($fields[4]['tag'] ?? null) !== 0x18) {
            throw new \RuntimeException('Timestamp token lacks serial or generation time');
        }
        $timeText = $fields[4]['value'];
        $time = DateTimeImmutable::createFromFormat('!YmdHis\Z', $timeText, new DateTimeZone('UTC'));
        if ($time === false || $time->format('YmdHis\Z') !== $timeText) {
            throw new \RuntimeException('Timestamp generation time is invalid');
        }
        return [strtoupper(bin2hex($fields[3]['value'])), $time->getTimestamp()];
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

    /** @param array<string, array|string> $objects */
    private function externalizeInlineLinks(array $annots, array &$objects, int &$next): array
    {
        if (($annots[0] ?? null) === 'objref') {
            $ref = $annots[1];
            $objects[$ref] = $this->externalizeInlineLinks($objects[$ref], $objects, $next);
            return $annots;
        }
        foreach ($annots[1] as &$annotation) {
            if (($annotation[0] ?? null) !== '<<') {
                continue;
            }
            $subtype = PdfStructureInspector::value($annotation, 'Subtype');
            if (($subtype[0] ?? null) !== '/' || ($subtype[1] ?? null) !== 'Link') {
                continue;
            }
            if ($next >= PHP_INT_MAX) {
                throw new UnsupportedPdf('Too many PDF objects for an indirect link annotation');
            }
            $ref = $next++ . '_0';
            $objects[$ref] = $annotation;
            $annotation = CosSerializer::reference($ref);
        }
        unset($annotation);
        return $annots;
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
    private static function fixByteRange(string $prepared, int $sourceLength, int $contentsLength): array
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
        $hexLength = $contentsLength;
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
