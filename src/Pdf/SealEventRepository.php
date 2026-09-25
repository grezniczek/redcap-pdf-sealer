<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pdf;

use RuntimeException;

/** Append-only, system-scoped diagnostics for failed PDF sealing attempts. */
final class SealEventRepository
{
    public function __construct(private readonly object $framework) {}

    /** @param array<string, string|null> $fields */
    public function appendFailure(array $fields): void
    {
        $logId = $this->framework->log('seal_event', [
            'project_id' => null,
            'record' => '',
            'event' => 'seal',
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ] + $fields);
        if ((!is_int($logId) && !ctype_digit((string) $logId)) || (int) $logId < 1) {
            throw new RuntimeException('Seal event log insertion failed');
        }
    }
}
