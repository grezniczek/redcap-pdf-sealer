<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Timestamp;

use Com\Tecnick\Pdf\Sign\Cms\Asn1;
use Com\Tecnick\Pdf\Sign\Cms\Certificate;
use Com\Tecnick\Pdf\Sign\Cms\Oid;
use OpenSSLAsymmetricKey;
use RuntimeException;
use Throwable;

/** A narrow RFC 3161 responder for SHA-256 requests. It performs no I/O. */
final class InternalTsaService
{
    private const SHA256 = '2.16.840.1.101.3.4.2.1';
    private const RSA_ENCRYPTION = '1.2.840.113549.1.1.1';
    private const TIME_STAMPING = '1.3.6.1.5.5.7.3.8';

    private Asn1 $asn1;
    private Certificate $certificate;

    public function __construct(private readonly string $policyOid)
    {
        $this->asn1 = new PolicyOidAsn1($policyOid);
        $this->certificate = new Certificate($this->asn1);
    }

    public function policyOid(): string
    {
        return $this->policyOid;
    }

    /** Return a DER TimeStampResp; invalid requests receive a rejection status. */
    public function respond(string $timestampRequestDer, TsaIdentity $identity, int $now): string
    {
        try {
            $request = $this->parseRequest($timestampRequestDer);
        } catch (InvalidTimestampRequest $e) {
            return $this->status(2, $e->failureBit);
        }

        $this->assertIdentity($identity, $now);
        $serial = $this->serial();
        $time = gmdate('YmdHis', $now) . 'Z';
        $tstInfo = $this->asn1->encodeSequence(
            $this->asn1->encodeInteger(1)
            . $this->asn1->encodeObjectIdentifier($this->policyOid)
            . $request['imprintDer']
            . $serial
            . "\x18" . $this->asn1->encodeLength(strlen($time)) . $time
            . $request['nonceDer'],
        );

        return $this->asn1->encodeSequence($this->statusInfo(0) . $this->token($tstInfo, $identity));
    }

    /** @return array{imprintDer: string, nonceDer: string} */
    private function parseRequest(string $der): array
    {
        try {
            $root = $this->asn1->readSingleElement($der, 0x30, 'TimeStampReq');
            $body = $root['value'];
            $offset = 0;
            $version = $this->asn1->readTlv($body, $offset);
            if ($version['tag'] !== 0x02 || $this->asn1->decodeInteger($version['value']) !== 1) {
                throw new InvalidTimestampRequest(2);
            }

            $imprint = $this->asn1->readTlv($body, $offset);
            if ($imprint['tag'] !== 0x30) {
                throw new InvalidTimestampRequest(2);
            }
            $imprintOffset = 0;
            $algorithm = $this->asn1->readTlv($imprint['value'], $imprintOffset);
            $digest = $this->asn1->readTlv($imprint['value'], $imprintOffset);
            if ($algorithm['tag'] !== 0x30 || $digest['tag'] !== 0x04
                || $imprintOffset !== strlen($imprint['value'])) {
                throw new InvalidTimestampRequest(2);
            }
            if ($this->asn1->decodeAlgorithmIdentifier($algorithm['raw'], 'request imprint') !== self::SHA256) {
                throw new InvalidTimestampRequest(0);
            }
            if (strlen($digest['value']) !== 32) {
                throw new InvalidTimestampRequest(2);
            }

            $nonce = '';
            $field = $this->asn1->readOptionalTlv($body, $offset);
            if ($field !== null && $field['tag'] === 0x06) {
                if ($field['raw'] !== $this->asn1->encodeObjectIdentifier($this->policyOid)) {
                    throw new InvalidTimestampRequest(15);
                }
                $field = $this->asn1->readOptionalTlv($body, $offset);
            }
            if ($field !== null && $field['tag'] === 0x02) {
                $this->asn1->assertMinimalInteger($field['value']);
                if ($field['value'] === '' || (ord($field['value'][0]) & 0x80) !== 0
                    || trim($field['value'], "\x00") === '') {
                    throw new InvalidTimestampRequest(2);
                }
                $nonce = $field['raw'];
                $field = $this->asn1->readOptionalTlv($body, $offset);
            }
            if ($field !== null && $field['tag'] === 0x01) {
                if ($field['value'] !== "\x00" && $field['value'] !== "\xFF") {
                    throw new InvalidTimestampRequest(2);
                }
                $field = $this->asn1->readOptionalTlv($body, $offset);
            }
            if ($field !== null && $field['tag'] === 0xA0) {
                throw new InvalidTimestampRequest(16);
            }
            if ($field !== null || $offset !== strlen($body)) {
                throw new InvalidTimestampRequest(2);
            }

            return ['imprintDer' => $imprint['raw'], 'nonceDer' => $nonce];
        } catch (InvalidTimestampRequest $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new InvalidTimestampRequest(2, $e);
        }
    }

    private function assertIdentity(TsaIdentity $identity, int $now): void
    {
        $cert = $identity->certificateDer;
        [$purposes, $critical] = $this->certificate->extendedKeyUsageWithCriticality($cert);
        if ($purposes !== [self::TIME_STAMPING] || !$critical) {
            throw new RuntimeException('TSA certificate must have only the critical timeStamping EKU');
        }
        $this->certificate->assertUsableForSigning($cert);
        $this->certificate->assertValidAt($cert, $now);
        $details = openssl_pkey_get_details($identity->privateKey);
        if ($details === false || $details['type'] !== OPENSSL_KEYTYPE_RSA) {
            throw new RuntimeException('TSA key must be RSA');
        }
        if (!openssl_x509_check_private_key(Certificate::derToPem($cert), $identity->privateKey)) {
            throw new RuntimeException('TSA key does not match its certificate');
        }
    }

    private function token(string $tstInfo, TsaIdentity $identity): string
    {
        $cert = $identity->certificateDer;
        $fields = $this->certificate->fields($cert);
        $sha256 = $this->algorithmIdentifier(self::SHA256);
        $attributes = [
            $this->attribute(Oid::CONTENT_TYPE, $this->asn1->encodeObjectIdentifier(Oid::TST_INFO)),
            $this->attribute(Oid::MESSAGE_DIGEST, $this->asn1->encodeOctetString(hash('sha256', $tstInfo, true))),
            $this->attribute(Oid::SIGNING_CERTIFICATE_V2, $this->asn1->encodeSequence(
                $this->asn1->encodeSequence($this->asn1->encodeSequence(
                    $this->asn1->encodeOctetString(hash('sha256', $cert, true)),
                )),
            )),
        ];
        sort($attributes, SORT_STRING);
        $signedAttributes = implode('', $attributes);
        $signature = '';
        if (!openssl_sign($this->asn1->encodeSet($signedAttributes), $signature, $identity->privateKey, OPENSSL_ALGO_SHA256)) {
            Certificate::clearOpenSslErrors();
            throw new RuntimeException('Unable to sign the timestamp token');
        }

        $signerInfo = $this->asn1->encodeSequence(
            $this->asn1->encodeInteger(1)
            . $this->asn1->encodeSequence($fields['issuer'] . $fields['serial'])
            . $sha256
            . $this->asn1->encodeContext(0, $signedAttributes)
            . $this->algorithmIdentifier(self::RSA_ENCRYPTION, true)
            . $this->asn1->encodeOctetString($signature),
        );
        $certificates = array_unique(array_merge([$cert], $identity->chainDer));
        sort($certificates, SORT_STRING);
        $signedData = $this->asn1->encodeSequence(
            $this->asn1->encodeInteger(3)
            . $this->asn1->encodeSet($sha256)
            . $this->asn1->encodeSequence(
                $this->asn1->encodeObjectIdentifier(Oid::TST_INFO)
                . $this->asn1->encodeContext(0, $this->asn1->encodeOctetString($tstInfo)),
            )
            . $this->asn1->encodeContext(0, implode('', $certificates))
            . $this->asn1->encodeSet($signerInfo),
        );

        return $this->asn1->encodeSequence(
            $this->asn1->encodeObjectIdentifier(Oid::SIGNED_DATA)
            . $this->asn1->encodeContext(0, $signedData),
        );
    }

    private function attribute(string $oid, string $value): string
    {
        return $this->asn1->encodeSequence($this->asn1->encodeObjectIdentifier($oid) . $this->asn1->encodeSet($value));
    }

    private function algorithmIdentifier(string $oid, bool $null = false): string
    {
        return $this->asn1->encodeSequence($this->asn1->encodeObjectIdentifier($oid) . ($null ? $this->asn1->encodeNull() : ''));
    }

    private function serial(): string
    {
        do {
            $bytes = random_bytes(16);
        } while (trim($bytes, "\x00") === '');

        return $this->asn1->encodeIntegerBytes($bytes);
    }

    private function status(int $code, int $failureBit): string
    {
        return $this->asn1->encodeSequence($this->statusInfo($code, $failureBit));
    }

    private function statusInfo(int $code, ?int $failureBit = null): string
    {
        $failure = '';
        if ($failureBit !== null) {
            $bytes = str_repeat("\x00", intdiv($failureBit, 8) + 1);
            $index = intdiv($failureBit, 8);
            $bytes[$index] = chr(0x80 >> ($failureBit % 8));
            $unused = 7 - ($failureBit % 8);
            $failure = "\x03" . $this->asn1->encodeLength(strlen($bytes) + 1) . chr($unused) . $bytes;
        }

        return $this->asn1->encodeSequence($this->asn1->encodeInteger($code) . $failure);
    }
}
