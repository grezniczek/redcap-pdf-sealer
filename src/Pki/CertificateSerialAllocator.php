<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pki;

use RuntimeException;

/** Reserves integer certificate serials using the Framework's atomic log INSERT. */
final class CertificateSerialAllocator
{
    public const MESSAGE = 'pki_serial_reservation';

    public function __construct(private object $framework, private string $purpose = 'issuance')
    {
        if (!in_array($purpose, ['issuance', 'diagnostic'], true)) {
            throw new RuntimeException('Invalid certificate reservation purpose');
        }
    }

    public function reserve(string $role, ?string $issuerSha256): int
    {
        if (!in_array($role, ['root', 'tsa', 'project'], true)
            || ($role === 'root' ? $issuerSha256 !== null
                : !is_string($issuerSha256) || preg_match('/^[a-f0-9]{64}$/D', $issuerSha256) !== 1)) {
            throw new RuntimeException('Invalid certificate reservation context');
        }
        // The ID is the reservation itself, not an issuance-success event. Never recycle it
        // if signing fails. Do not commit or otherwise change the caller's transaction.
        $id = $this->framework->log(self::MESSAGE, [
            'project_id' => null,
            'record' => '',
            'identity_role' => $role,
            'issuer_sha256' => $issuerSha256 ?? '',
            'purpose' => $this->purpose,
        ]);
        // OpenSSL's integer API uses C long, which is only 32 bits on Windows.
        $maximum = (string) (PHP_OS_FAMILY === 'Windows' ? 2147483647 : PHP_INT_MAX);
        $decimal = is_int($id) || is_string($id) ? (string) $id : '';
        if (preg_match('/^[1-9][0-9]*$/D', $decimal) !== 1
            || strlen($decimal) > strlen($maximum)
            || (strlen($decimal) === strlen($maximum) && strcmp($decimal, $maximum) > 0)) {
            throw new RuntimeException('Certificate serial reservation failed or exceeds the integer API range; use PHP 8.4+');
        }
        return (int) $decimal;
    }
}
