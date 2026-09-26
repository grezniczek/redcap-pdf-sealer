<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Timestamp;

use RuntimeException;

/** Shared interpretation of persisted timestamp settings for sealing and the UI. */
final readonly class TimestampSettings
{
    private function __construct(public string $mode, public bool $fallback) {}

    public static function fromStored(mixed $mode, mixed $fallback): self
    {
        $mode ??= 'internal';
        if (!in_array($mode, ['internal', 'none'], true)) {
            throw new RuntimeException('Invalid timestamp mode setting');
        }
        if (in_array($fallback, [null, '', '1', 'true'], true)) {
            $enabled = true;
        } elseif (in_array($fallback, ['0', 'false'], true)) {
            $enabled = false;
        } else {
            throw new RuntimeException('Invalid B-B fallback setting');
        }
        return new self($mode, $enabled);
    }
}
