<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pki;

use RuntimeException;

/** Shared scheduling policy; issuance still uses OpenSSL's current clock. */
final class LeafRenewalPolicy
{
    public const WINDOW = 90 * 86400;

    public static function due(int $expires, string $issuerId, string $currentIssuerId, int $now): bool
    {
        return $expires <= $now + self::WINDOW || $issuerId !== $currentIssuerId;
    }

    public static function assertIssuerWindow(int $expires, int $now): void
    {
        // Avoid repeatedly issuing a capped leaf that is already in the renewal window.
        if ($expires - $now < self::WINDOW + 86400) {
            throw new RuntimeException('Root renewal is required before leaf maintenance');
        }
    }
}
