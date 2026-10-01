<?php

declare(strict_types=1);

use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\Asn1;
use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\Certificate;

/** Diagnostic-only DER clone: preserve profile/name/key; change serial/validity and self-sign. */
function reissueProbeRoot(string $originalDer, OpenSSLAsymmetricKey $key, int $notBefore, int $notAfter): string
{
    checkSeal($notBefore < $notAfter, 'Invalid root probe validity interval');
    $asn1 = new Asn1();
    $outer = $asn1->readSingleElement($originalDer, 0x30, 'Root certificate');
    $offset = 0;
    $tbs = $asn1->readTlv($outer['value'], $offset);
    $algorithm = $asn1->readTlv($outer['value'], $offset);
    $expectedAlgorithm = $asn1->encodeSequence($asn1->encodeObjectIdentifier('1.2.840.113549.1.1.11') . $asn1->encodeNull());
    checkSeal($tbs['tag'] === 0x30 && $algorithm['raw'] === $expectedAlgorithm,
        'Diagnostic supports the built-in RSA/SHA-256 root only');
    checkSeal(openssl_x509_check_private_key(Certificate::derToPem($originalDer), $key),
        'Root probe key does not match the certificate');
    $fields = [];
    $offset = 0;
    while ($offset < strlen($tbs['value'])) {
        $fields[] = $asn1->readTlv($tbs['value'], $offset);
    }
    checkSeal(count($fields) >= 7 && $fields[0]['tag'] === 0xA0 && $fields[1]['tag'] === 0x02
        && $fields[4]['tag'] === 0x30 && $fields[2]['raw'] === $algorithm['raw'], 'Unexpected root profile');
    $serial = random_bytes(16);
    $serial[0] = chr(ord($serial[0]) | 0x80);
    $fields[1]['raw'] = $asn1->encodeIntegerBytes($serial);
    $encodeTime = static function (int $time) use ($asn1): string {
        $year = (int) gmdate('Y', $time);
        $generalized = $year < 1950 || $year >= 2050;
        $value = gmdate($generalized ? 'YmdHis' : 'ymdHis', $time) . 'Z';
        return chr($generalized ? 0x18 : 0x17) . $asn1->encodeLength(strlen($value)) . $value;
    };
    $fields[4]['raw'] = $asn1->encodeSequence($encodeTime($notBefore) . $encodeTime($notAfter));
    $renewedTbs = $asn1->encodeSequence(implode('', array_column($fields, 'raw')));
    checkSeal(openssl_sign($renewedTbs, $signature, $key, OPENSSL_ALGO_SHA256), 'Root probe signing failed');
    $bitString = "\x03" . $asn1->encodeLength(strlen($signature) + 1) . "\x00" . $signature;
    return $asn1->encodeSequence($renewedTbs . $algorithm['raw'] . $bitString);
}
