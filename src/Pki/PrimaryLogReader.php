<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pki;

use Closure;

/** Framework log pseudo-queries executed on the primary for lock-consistent reads. */
final class PrimaryLogReader
{
    private Closure $query;

    /** @param null|callable(string,array):mixed $query Test override. */
    public function __construct(object $framework, ?callable $query = null)
    {
        $this->query = $query === null
            ? static fn (string $sql, array $params): mixed => \db_query(
                $framework->getQueryLogsSql($sql), $params, null, MYSQLI_STORE_RESULT, true,
            )
            : Closure::fromCallable($query);
    }

    public function query(string $sql, array $params): mixed
    {
        return ($this->query)($sql, $params);
    }
}
