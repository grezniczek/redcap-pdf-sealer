<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Timestamp;

/** Built-in PDF Sealer TSA Policy v1; the OID is 2.25 plus the UUID's unsigned integer. */
final class TsaPolicy
{
    public const DEFAULT_UUID = '8c0f7132-9d42-4240-b259-da71e931ca3e';
    public const DEFAULT_OID = '2.25.186172099785128831488612506224552954430';
}
