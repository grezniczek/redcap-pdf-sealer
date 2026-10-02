<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pki;

final class ProjectRevocationRepository extends BuiltinRevocationRepository
{
    public const MESSAGE = 'project_certificate_revocation';
    protected const ROLE = 'project';
}
