<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pki;

final readonly class PkiHealthReport
{
    public function __construct(
        public PkiHealth $status,
        public ?string $errorCode = null,
        public ?string $identityId = null,
    ) {}
}
