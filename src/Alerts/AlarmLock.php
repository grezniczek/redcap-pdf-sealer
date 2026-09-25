<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Alerts;

use Closure;
use RuntimeException;

/** Serializes notification decisions for one alarm condition on the primary DB. */
final class AlarmLock
{
    private Closure $query;

    /** @param null|callable(string,array):mixed $query Test override. */
    public function __construct(?callable $query = null)
    {
        $this->query = $query === null
            ? static fn (string $sql, array $params): mixed => \db_query($sql, $params, null, MYSQLI_STORE_RESULT, true)
            : Closure::fromCallable($query);
    }

    public function withLock(string $fingerprint, callable $work): mixed
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $fingerprint) !== 1) {
            throw new RuntimeException('Invalid alarm fingerprint');
        }
        // MySQL lock names are limited to 64 bytes; 40 hex characters retain 160 bits.
        $name = 'pdf_sealer_alarm_' . substr($fingerprint, 0, 40);
        $result = ($this->query)('SELECT GET_LOCK(?, 30)', [$name]);
        if ($result === false || $result->fetch_row()[0] !== 1) {
            throw new RuntimeException('Alarm lock unavailable');
        }
        try {
            return $work();
        } finally {
            $released = ($this->query)('SELECT RELEASE_LOCK(?)', [$name]);
            if ($released === false || $released->fetch_row()[0] !== 1) {
                throw new RuntimeException('Alarm lock release failed');
            }
        }
    }
}
