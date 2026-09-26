<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Diagnostics;

use Com\Tecnick\Pdf\Parser\Parser;
use Com\Tecnick\Pdf\Sign\Cms\Asn1;
use Com\Tecnick\Pdf\Sign\Cms\Certificate;
use Com\Tecnick\Pdf\Sign\Cms\SignedDataVerifier;
use DE\RUB\PDFSealerExternalModule\Pdf\PdfStructureInspector;
use RuntimeException;

/** Checks our generated one-page sample only; not a general PDF/PAdES validator. */
final class SampleSealVerifier
{
    public function verify(string $source, string $sealed, string $signerDer, ?string $tsaDer = null): void
    {
        self::require(str_starts_with($sealed, $source), 'Original sample changed');
        [$xref, $objects] = (new Parser(['decode_streams' => false, 'strict_limits' => true]))->parse($sealed);
        $value = PdfStructureInspector::value(...);
        $catalog = $objects[$xref['trailer']['root']][0];
        $signatureRef = $value($value($catalog, 'Perms'), 'DocMDP');
        self::require(($signatureRef[0] ?? null) === 'objref', 'Missing certification signature');
        $signature = $objects[$signatureRef[1]][0];
        self::require($value($signature, 'SubFilter')[1] === 'ETSI.CAdES.detached', 'Wrong signature format');
        $transform = $value($signature, 'Reference')[1][0];
        self::require($value($transform, 'TransformMethod')[1] === 'DocMDP'
            && $value($value($transform, 'TransformParams'), 'P')[1] === '1', 'Wrong certification permissions');
        $acroForm = $objects[$value($catalog, 'AcroForm')[1]][0];
        $fields = $value($acroForm, 'Fields')[1];
        self::require(count($fields) === 1, 'Wrong signature field count');
        $widget = $objects[$fields[0][1]][0];
        self::require($value($widget, 'FT')[1] === 'Sig'
            && $value($widget, 'V')[1] === $signatureRef[1], 'Signature field mismatch');
        self::require($value($objects['3_0'][0], 'Annots')[1][0][1] === $fields[0][1], 'Widget is not on sample page');

        $range = $value($signature, 'ByteRange');
        self::require(($range[0] ?? null) === '[' && count($range[1]) === 4, 'Invalid signed range');
        [$zero, $start, $end, $tail] = array_map(static fn(array $token): int => (int) $token[1], $range[1]);
        self::require($zero === 0 && $start > strlen($source) && $end > $start && $tail > 0
            && $end + $tail === strlen($sealed) && $sealed[$start] === '<' && $sealed[$end - 1] === '>',
            'Signature does not cover complete sample');
        $padded = hex2bin(substr($sealed, $start + 1, $end - $start - 2));
        self::require(is_string($padded), 'Invalid signature contents');
        $offset = 0;
        $cms = (new Asn1())->readTlv($padded, $offset)['raw'];
        self::require(trim(substr($padded, $offset), "\0") === '', 'Invalid signature padding');
        $covered = substr($sealed, 0, $start) . substr($sealed, $end);
        $verifier = new SignedDataVerifier(requireSigningCertificate: true);
        self::require($verifier->verify($cms, $covered) === $signerDer, 'Unexpected signing certificate');
        $tokens = (new Certificate())->signatureTimestampTokens($cms);
        self::require(count($tokens) === ($tsaDer === null ? 0 : 1), 'Unexpected timestamp count');
        if ($tsaDer !== null) {
            self::require($verifier->verify($tokens[0]) === $tsaDer, 'Unexpected timestamp signer');
        }
        // The B-T builder separately verifies the token's imprint, nonce, policy and time.
    }

    private static function require(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}
