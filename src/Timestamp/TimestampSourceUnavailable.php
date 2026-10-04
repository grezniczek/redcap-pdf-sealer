<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Timestamp;

/** Local external-source retirement or a lifecycle change invalidates a captured response. */
final class TimestampSourceUnavailable extends \RuntimeException {}
