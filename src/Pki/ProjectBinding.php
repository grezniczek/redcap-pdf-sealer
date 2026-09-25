<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pki;

final readonly class ProjectBinding
{
    public function __construct(
        public string $uuid,
        public ?string $identityId,
    ) {}
}
