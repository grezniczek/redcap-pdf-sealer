<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pki;

use Closure;
use RuntimeException;

/** Serializes explicit instance PKI initialization on the primary DB. */
final class PkiInitializationLock
{
    private const NAME = 'pdf_sealer_initialize';
    private Closure $query;

    /** @param null|callable(string,array):mixed $query Test override. */
    public function __construct(?callable $query = null)
    {
        $this->query = $query === null
            ? static fn (string $sql, array $params): mixed => \db_query($sql, $params, null, MYSQLI_STORE_RESULT, true)
            : Closure::fromCallable($query);
    }

    public function withLock(callable $work): mixed
    {
        $result = ($this->query)('SELECT GET_LOCK(?, 30)', [self::NAME]);
        if ($result === false || $result->fetch_row()[0] !== 1) {
            throw new RuntimeException('PKI initialization lock unavailable');
        }
        try {
            return $work();
        } finally {
            $released = ($this->query)('SELECT RELEASE_LOCK(?)', [self::NAME]);
            if ($released === false || $released->fetch_row()[0] !== 1) {
                throw new RuntimeException('PKI initialization lock release failed');
            }
        }
    }
}
