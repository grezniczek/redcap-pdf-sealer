<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pki;

use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\Asn1;
use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\Certificate;
use RuntimeException;

/** Complete, direct X.509 v2 CRLs for the built-in RSA CA; no shell or private-key files. */
final class CrlIssuer
{
    public const LIFETIME = 3 * 86400;
    public const QUERY = 'pdf_sealer_crl';

    /** Stable across certificate renewals retaining the same RSA key. */
    public static function keyId(string $rootDer): string
    {
        return hash('sha256', (new Certificate())->fields($rootDer)['public_key']);
    }

    public static function url(string $surveyUrl, string $rootDer): string
    {
        $parts = parse_url($surveyUrl);
        // Also disallow OpenSSL configuration interpolation and line/quote injection.
        if (!is_array($parts) || !in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || !is_string($parts['host'] ?? null) || $parts['host'] === ''
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || preg_match('!^[A-Za-z0-9:/_.%\[\]~-]+$!D', $surveyUrl) !== 1) {
            throw new RuntimeException('Invalid public survey URL for CRL publication');
        }
        return $surveyUrl . '?' . self::QUERY . '=' . self::keyId($rootDer);
    }

    /** Entries use positive hexadecimal serials, preserving 128-bit values without integer conversion. */
    public function issue(GeneratedIdentity $root, int $number, int $now, array $entries = []): array
    {
        $certificate = new Certificate();
        $certificate->assertValidAt($root->certificateDer, $now, 0);
        $certificate->assertUsableForCrlSigning($root->certificateDer);
        $record = [
            'version' => 1, 'key_id' => self::keyId($root->certificateDer), 'number' => $number,
            'this_update' => $now,
            'next_update' => min($now + self::LIFETIME, $certificate->fields($root->certificateDer)['not_after']),
            'entries' => $entries,
        ];
        $tbs = $this->tbs($root->certificateDer, $record);
        if (!openssl_x509_check_private_key(Certificate::derToPem($root->certificateDer), $root->privateKey())
            || !openssl_sign($tbs, $signature, $root->privateKey(), OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Unable to sign built-in CRL');
        }
        $asn1 = new Asn1();
        $record['der_b64'] = base64_encode($asn1->encodeSequence($tbs . $this->algorithm()
            . "\x03" . $asn1->encodeLength(strlen($signature) + 1) . "\x00" . $signature));
        $this->verify($root->certificateDer, $record);
        return $record;
    }

    /** Validate even an expired snapshot, so refresh cannot erase its counter or revoked serials. */
    public function verify(string $rootDer, array $record): string
    {
        $encoded = $record['der_b64'] ?? null;
        $der = is_string($encoded) ? base64_decode($encoded, true) : false;
        if ($der === false || $der === '' || strlen($der) > 8 * 1024 * 1024) {
            throw new RuntimeException('Invalid cached CRL bytes');
        }
        $asn1 = new Asn1();
        $outer = $asn1->readSingleElement($der, 0x30, 'cached CRL');
        $offset = 0;
        $tbs = $asn1->readTlv($outer['value'], $offset);
        $algorithm = $asn1->readTlv($outer['value'], $offset);
        $signature = $asn1->readTlv($outer['value'], $offset);
        $pem = Certificate::derToPem($rootDer);
        $publicKey = openssl_pkey_get_public($pem);
        (new Certificate())->assertUsableForCrlSigning($rootDer);
        if ($offset !== strlen($outer['value']) || $tbs['raw'] !== $this->tbs($rootDer, $record)
            || $algorithm['raw'] !== $this->algorithm() || $signature['tag'] !== 0x03
            || strlen($signature['value']) < 2 || $signature['value'][0] !== "\x00"
            || $publicKey === false || openssl_x509_verify($pem, $publicKey) !== 1
            || openssl_verify($tbs['raw'], substr($signature['value'], 1), $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('Cached CRL does not match its issuer, metadata or signature');
        }
        return $der;
    }

    private function tbs(string $rootDer, array $record): string
    {
        if (($record['version'] ?? null) !== 1 || ($record['key_id'] ?? null) !== self::keyId($rootDer)
            || !is_int($record['number'] ?? null) || $record['number'] < 1
            || !is_int($record['this_update'] ?? null) || $record['this_update'] < 1
            || !is_int($record['next_update'] ?? null) || $record['next_update'] <= $record['this_update']
            || $record['next_update'] - $record['this_update'] > self::LIFETIME
            || !is_array($record['entries'] ?? null) || !array_is_list($record['entries'])) {
            throw new RuntimeException('Invalid CRL metadata');
        }
        $asn1 = new Asn1();
        $certificate = new Certificate();
        $revoked = '';
        $seen = [];
        foreach ($record['entries'] as $entry) {
            $serial = $entry['serial_hex'] ?? null;
            if (!is_string($serial) || preg_match('/^[0-9a-f]{1,40}$/D', $serial) !== 1
                || ltrim($serial, '0') !== $serial || isset($seen[$serial])
                || !is_int($entry['revoked_at'] ?? null) || $entry['revoked_at'] < 1
                || $entry['revoked_at'] > $record['this_update']
                || !in_array($entry['reason'] ?? null, [1, 4, 5], true)) {
                throw new RuntimeException('Invalid CRL revocation entry');
            }
            $seen[$serial] = true;
            $bytes = hex2bin(strlen($serial) % 2 === 0 ? $serial : '0' . $serial);
            $reason = $this->extension('2.5.29.21', "\x0a\x01" . chr($entry['reason']));
            $revoked .= $asn1->encodeSequence($asn1->encodeIntegerBytes($bytes)
                . $this->time($entry['revoked_at']) . $asn1->encodeSequence($reason));
        }
        $ski = $certificate->subjectKeyIdentifier($rootDer);
        $extensions = $this->extension('2.5.29.35', $asn1->encodeSequence("\x80" . $asn1->encodeLength(strlen($ski)) . $ski))
            . $this->extension('2.5.29.20', $asn1->encodeInteger($record['number']));
        return $asn1->encodeSequence($asn1->encodeInteger(1) . $this->algorithm()
            . $certificate->fields($rootDer)['subject'] . $this->time($record['this_update'])
            . $this->time($record['next_update']) . ($revoked === '' ? '' : $asn1->encodeSequence($revoked))
            . $asn1->encodeContext(0, $asn1->encodeSequence($extensions)));
    }

    private function algorithm(): string
    {
        $asn1 = new Asn1();
        return $asn1->encodeSequence($asn1->encodeObjectIdentifier('1.2.840.113549.1.1.11') . $asn1->encodeNull());
    }

    private function extension(string $oid, string $value): string
    {
        $asn1 = new Asn1();
        return $asn1->encodeSequence($asn1->encodeObjectIdentifier($oid) . $asn1->encodeOctetString($value));
    }

    private function time(int $epoch): string
    {
        $year = (int) gmdate('Y', $epoch);
        $utc = $year >= 1950 && $year < 2050;
        $value = gmdate($utc ? 'ymdHis\Z' : 'YmdHis\Z', $epoch);
        return ($utc ? "\x17" : "\x18") . (new Asn1())->encodeLength(strlen($value)) . $value;
    }
}
