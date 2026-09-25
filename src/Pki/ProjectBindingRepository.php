<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pki;

use RuntimeException;

/** Append-only PID to pseudonymous UUID and active identity bindings. */
final class ProjectBindingRepository
{
    private const MESSAGE = 'project_identity_binding';

    private PrimaryLogReader $reader;

    /** @param \ExternalModules\Framework $framework */
    public function __construct(private readonly object $framework, ?PrimaryLogReader $reader = null)
    {
        $this->reader = $reader ?? new PrimaryLogReader($framework);
    }

    public function hasAny(): bool
    {
        $result = $this->reader->query(
            'SELECT log_id WHERE message = ? AND ISNULL(project_id) LIMIT 1',
            [self::MESSAGE],
        );
        if ($result === false) {
            throw new RuntimeException('Project binding query failed');
        }
        return $result->fetch_assoc() !== null;
    }

    public function find(int $pid): ?ProjectBinding
    {
        self::assertPid($pid);
        $result = $this->reader->query(
            'SELECT project_uuid, identity_id WHERE message = ? AND redcap_pid = ? AND ISNULL(project_id) ORDER BY log_id DESC LIMIT 2',
            [self::MESSAGE, (string) $pid],
        );
        if ($result === false) {
            throw new RuntimeException('Project binding query failed');
        }
        $latest = $result->fetch_assoc();
        if ($latest === null) {
            return null;
        }
        $binding = $this->parse($latest);
        $previous = $result->fetch_assoc();
        if ($previous !== null) {
            $prior = $this->parse($previous);
            if ($prior->uuid !== $binding->uuid || ($prior->identityId !== null && $binding->identityId === null)) {
                throw new RuntimeException('Conflicting project identity binding');
            }
        }
        return $binding;
    }

    public function bindUuid(int $pid, string $uuid): void
    {
        self::assertPid($pid);
        self::assertUuid($uuid);
        if ($this->find($pid) !== null) {
            throw new RuntimeException('Project UUID is already bound');
        }
        $this->append($pid, $uuid, null);
    }

    public function activate(int $pid, string $uuid, string $identityId): void
    {
        self::assertPid($pid);
        self::assertUuid($uuid);
        if (preg_match('/^[0-9a-f]{32}$/D', $identityId) !== 1) {
            throw new RuntimeException('Invalid project identity ID');
        }
        $current = $this->find($pid);
        if ($current === null || $current->uuid !== $uuid || $current->identityId !== null) {
            throw new RuntimeException('Project identity activation requires an unassigned UUID binding');
        }
        $this->append($pid, $uuid, $identityId);
    }

    private function append(int $pid, string $uuid, ?string $identityId): void
    {
        $logId = $this->framework->log(self::MESSAGE, [
            'project_id' => null,
            'record' => '',
            'redcap_pid' => (string) $pid,
            'project_uuid' => $uuid,
            'identity_id' => $identityId,
        ]);
        if ((!is_int($logId) && !ctype_digit((string) $logId)) || (int) $logId < 1) {
            throw new RuntimeException('Project binding insertion failed');
        }
    }

    private function parse(array $row): ProjectBinding
    {
        $uuid = $row['project_uuid'] ?? null;
        self::assertUuid($uuid);
        $id = $row['identity_id'] ?? null;
        if ($id !== null && (!is_string($id) || preg_match('/^[0-9a-f]{32}$/D', $id) !== 1)) {
            throw new RuntimeException('Malformed project identity binding');
        }
        return new ProjectBinding($uuid, $id);
    }

    public static function assertPid(int $pid): void
    {
        if ($pid < 1) {
            throw new RuntimeException('Invalid REDCap project ID');
        }
    }

    private static function assertUuid(mixed $uuid): void
    {
        if (!is_string($uuid) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $uuid) !== 1) {
            throw new RuntimeException('Malformed project seal UUID');
        }
    }
}
