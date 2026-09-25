<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pki;

use Closure;
use RuntimeException;

/** Reads PKI system settings from the primary for immediate consistency. */
final class PrimarySystemSettingReader
{
    private Closure $read;

    /** @param null|callable(string):mixed $read Test override. */
    public function __construct(object $framework, ?callable $read = null)
    {
        $this->read = $read === null
            ? static function (string $key) use ($framework): mixed {
                $result = \db_query(
                    'SELECT s.value, s.type FROM redcap_external_module_settings s '
                    . 'JOIN redcap_external_modules m ON m.external_module_id = s.external_module_id '
                    . 'WHERE m.directory_prefix = ? AND s.project_id IS NULL AND s.key = ? LIMIT 2',
                    [$framework->getModuleInstance()->PREFIX, $framework->prefixSettingKey($key)],
                    null, MYSQLI_STORE_RESULT, true,
                );
                if ($result === false) {
                    throw new RuntimeException('PKI setting query failed');
                }
                $row = $result->fetch_assoc();
                if ($row === null) {
                    return null;
                }
                if ($result->fetch_assoc() !== null || ($row['type'] ?? null) !== 'string') {
                    throw new RuntimeException('Malformed PKI system setting');
                }
                return $row['value'];
            }
            : Closure::fromCallable($read);
    }

    public function get(string $key): mixed
    {
        return ($this->read)($key);
    }
}
