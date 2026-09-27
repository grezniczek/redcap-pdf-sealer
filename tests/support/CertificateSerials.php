<?php

declare(strict_types=1);

namespace PDFSealerTests;

/** In-process allocator for disposable standalone test identities only. */
final class CertificateSerials
{
    private static int $next = 1000;

    public static function reserve(string $role, ?string $issuerSha256): int
    {
        return ++self::$next;
    }
}
