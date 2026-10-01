<?php

declare(strict_types=1);

use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\Asn1;

/** Disposable clone signed by the known issuing key; preserves certificate subject, key and extensions. */
function maintenanceCertificate(string $der, OpenSSLAsymmetricKey $issuerKey, int $start, int $end): string
{
    $asn1 = new Asn1(); $outer = $asn1->readSingleElement($der, 0x30, 'certificate'); $offset = 0;
    $tbs = $asn1->readTlv($outer['value'], $offset); $algorithm = $asn1->readTlv($outer['value'], $offset);
    $fields = []; $offset = 0;
    while ($offset < strlen($tbs['value'])) { $fields[] = $asn1->readTlv($tbs['value'], $offset); }
    $encodeTime = static fn(int $time): string => "\x17\x0d" . gmdate('ymdHis', $time) . 'Z';
    $fields[4]['raw'] = $asn1->encodeSequence($encodeTime($start) . $encodeTime($end));
    $newTbs = $asn1->encodeSequence(implode('', array_column($fields, 'raw')));
    check(openssl_sign($newTbs, $signature, $issuerKey, OPENSSL_ALGO_SHA256), 'Fixture signing failed');
    return $asn1->encodeSequence($newTbs . $algorithm['raw'] . "\x03" . $asn1->encodeLength(strlen($signature) + 1) . "\x00" . $signature);
}

