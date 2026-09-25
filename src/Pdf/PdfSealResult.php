<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pdf;

/** Sealed PDF and the timestamp details needed by a future seal event. */
final readonly class PdfSealResult
{
    public function __construct(
        public string $pdf,
        public string $profile,
        public ?string $timestampSerialHex = null,
        public ?int $timestampTime = null,
    ) {}
}
