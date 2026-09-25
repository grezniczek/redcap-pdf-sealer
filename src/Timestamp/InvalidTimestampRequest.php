<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Timestamp;

use RuntimeException;
use Throwable;

/** Internal parse failure mapped to a PKIFailureInfo bit. */
final class InvalidTimestampRequest extends RuntimeException
{
    public function __construct(public readonly int $failureBit, ?Throwable $previous = null)
    {
        parent::__construct('Timestamp request rejected', 0, $previous);
    }
}
