<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Timestamp;

use Com\Tecnick\Pdf\Sign\Cms\Asn1;
use Com\Tecnick\Pdf\Sign\Exception;

/** Adds one UUID-sized 2.25 policy arc to Tecnick's otherwise integer-bounded OID codec. */
final class PolicyOidAsn1 extends Asn1
{
    private const MAX_UUID_INTEGER = '340282366920938463463374607431768211455';
    private string $policyDer;
    private string $policyValue;

    public function __construct(private readonly string $policyOid)
    {
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
        return $oid === $this->policyOid ? $this->policyDer : parent::encodeObjectIdentifier($oid);
    }

    public function decodeObjectIdentifier(string $value): string
    {
        return $value === $this->policyValue ? $this->policyOid : parent::decodeObjectIdentifier($value);
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
