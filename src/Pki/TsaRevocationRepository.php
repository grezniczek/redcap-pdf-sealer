<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pki;

final class TsaRevocationRepository extends BuiltinRevocationRepository
{
    public const MESSAGE = 'tsa_certificate_revocation';
    protected const ROLE = 'tsa';
}
