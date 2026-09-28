<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Timestamp;

use RuntimeException;

/** A fixed registration stage; exception details can contain private configuration. */
final class TimestampSourceRegistrationFailed extends RuntimeException
{
    public function __construct(public readonly string $stage)
    {
        parent::__construct('External TSA registration failed at ' . $stage);
    }
}
