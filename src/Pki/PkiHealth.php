<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pki;

enum PkiHealth: string
{
    case Uninitialized = 'UNINITIALIZED';
    case Ready = 'READY';
    case Degraded = 'DEGRADED';
    case Broken = 'BROKEN';
}
