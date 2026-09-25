<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pki;

use Closure;
use RuntimeException;

/** Per-project MySQL advisory lock, acquired and released on the primary connection. */
final class ProjectIssueLock
{
    private Closure $query;

    /** @param null|callable(string,array):object $query For tests; production uses REDCap db_query(). */
    public function __construct(?callable $query = null)
    {
        $this->query = $query === null
            ? static fn (string $sql, array $params): mixed => \db_query($sql, $params, null, MYSQLI_STORE_RESULT, true)
            : Closure::fromCallable($query);
    }

    public function withLock(int $pid, callable $work): mixed
    {
        ProjectBindingRepository::assertPid($pid);
        $name = 'pdf_sealer_issue_' . $pid;
        $result = ($this->query)('SELECT GET_LOCK(?, 30)', [$name]);
        if ($result === false || $result->fetch_row()[0] !== 1) {
            throw new RuntimeException('Project identity lock unavailable');
        }
        try {
            return $work();
        } finally {
            $released = ($this->query)('SELECT RELEASE_LOCK(?)', [$name]);
            if ($released === false || $released->fetch_row()[0] !== 1) {
                throw new RuntimeException('Project identity lock release failed');
            }
        }
    }
}
