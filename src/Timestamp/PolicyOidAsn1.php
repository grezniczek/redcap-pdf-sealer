<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Timestamp;

use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\Asn1;
use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Exception;

/** Adds UUID-sized 2.25 policy arcs to Tecnick's otherwise integer-bounded OID codec. */
final class PolicyOidAsn1 extends Asn1
{
    private const MAX_UUID_INTEGER = '340282366920938463463374607431768211455';
    private string $policyDer = '';
    private string $policyValue = '';

    public function __construct(private readonly string $policyOid)
    {
        // An external TSA may choose its own policy when none was requested.
        if ($policyOid === '') { return; }
        if (preg_match('/^2\\.25\\.([1-9][0-9]*)$/D', $policyOid, $match) === 1) {
            $decimal = $match[1];
            if (strlen($decimal) > strlen(self::MAX_UUID_INTEGER)
                || (strlen($decimal) === strlen(self::MAX_UUID_INTEGER)
                    && strcmp($decimal, self::MAX_UUID_INTEGER) > 0)) {
                throw new Exception('UUID policy OID arc exceeds 128 bits');
            }
            $value = "\x69" . self::decimalBase128($decimal);
            $this->policyDer = "\x06" . $this->encodeLength(strlen($value)) . $value;
            $this->policyValue = $value;
        } else {
            $this->policyDer = parent::encodeObjectIdentifier($policyOid);
            $this->policyValue = $this->readSingleElement($this->policyDer, 0x06, 'TSA policy OID')['value'];
        }
    }

    public function encodeObjectIdentifier(string $oid): string
    {
        if ($oid !== '' && $oid === $this->policyOid) { return $this->policyDer; }
        if (preg_match('/^2\\.25\\.[1-9][0-9]*$/D', $oid) === 1) {
            return (new self($oid))->policyDer;
        }
        return parent::encodeObjectIdentifier($oid);
    }

    public function decodeObjectIdentifier(string $value): string
    {
        if ($value !== '' && $value === $this->policyValue) { return $this->policyOid; }
        // Decode one bounded UUID arc without overflowing a PHP integer.
        if (strlen($value) >= 2 && $value[0] === "\x69") {
            $arc = substr($value, 1);
            if (strlen($arc) <= 19 && ord($arc[0]) !== 0x80) {
                $decimal = '0';
                foreach (str_split($arc) as $i => $byte) {
                    $number = ord($byte);
                    if (($number < 128) !== ($i === strlen($arc) - 1)) {
                        return parent::decodeObjectIdentifier($value);
                    }
                    $carry = $number & 0x7f;
                    $next = '';
                    foreach (array_reverse(str_split($decimal)) as $digit) {
                        $carry += (int) $digit * 128;
                        $next = (string) ($carry % 10) . $next;
                        $carry = intdiv($carry, 10);
                    }
                    $decimal = ($carry ? (string) $carry : '') . $next;
                }
                if ($decimal !== '0') {
                    $oid = '2.25.' . $decimal;
                    if ((new self($oid))->policyValue !== $value) { throw new Exception('Invalid UUID policy OID'); }
                    return $oid;
                }
            }
        }
        return parent::decodeObjectIdentifier($value);
    }

    private static function decimalBase128(string $decimal): string
    {
        $digits = [];
        while ($decimal !== '0') {
            $quotient = '';
            $remainder = 0;
            foreach (str_split($decimal) as $digit) {
                $number = $remainder * 10 + (int) $digit;
                $next = intdiv($number, 128);
                if ($quotient !== '' || $next !== 0) {
                    $quotient .= (string) $next;
                }
                $remainder = $number % 128;
            }
            $digits[] = $remainder;
            $decimal = $quotient === '' ? '0' : $quotient;
        }
        $digits = array_reverse($digits);
        $last = count($digits) - 1;
        $encoded = '';
        foreach ($digits as $index => $digit) {
            $encoded .= chr($digit | ($index === $last ? 0 : 0x80));
        }
        return $encoded;
    }
}
