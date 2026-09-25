<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pki;

use RuntimeException;

/** Versioned wrapper around REDCap's installation-key encryption. */
final class SecretProtector
{
    private const PREFIX = 'redcap-v1:';

    public function encrypt(string $plaintext): string
    {
        if ($plaintext === '' || !function_exists('encrypt')) {
            throw new RuntimeException('REDCap encryption is unavailable');
        }
        $ciphertext = \encrypt($plaintext);
        if (!is_string($ciphertext) || $ciphertext === '') {
            throw new RuntimeException('REDCap encryption failed');
        }
        return self::PREFIX . $ciphertext;
    }

    public function decrypt(string $stored): string
    {
        if (!str_starts_with($stored, self::PREFIX) || !function_exists('decrypt')) {
            throw new RuntimeException('Unsupported or unavailable REDCap encryption');
        }
        $plaintext = \decrypt(substr($stored, strlen(self::PREFIX)));
        if (!is_string($plaintext) || $plaintext === '') {
            throw new RuntimeException('REDCap decryption failed');
        }
        return $plaintext;
    }
}
