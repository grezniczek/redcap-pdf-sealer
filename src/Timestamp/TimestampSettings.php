<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Timestamp;

use RuntimeException;

/** Validated provider timestamp choices shared by sealing and the built-in UI. */
final readonly class TimestampSettings
{
    public function __construct(public string $mode, public bool $fallback)
    {
        if (!in_array($mode, ['internal', 'external', 'none'], true)) {
            throw new RuntimeException('Invalid timestamp mode setting');
        }
    }
}
