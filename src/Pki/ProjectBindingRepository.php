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

    public function hasAny(bool $includeExternal = true): bool
    {
        $result = $this->reader->query(
            'SELECT log_id WHERE message = ? AND ISNULL(project_id)'
                . ($includeExternal ? '' : " AND (ISNULL(provider_id) OR provider_id NOT LIKE 'external-%')") . ' LIMIT 1',
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
            'SELECT project_uuid, identity_id, provider_id, pending_provider_id, transition_id WHERE message = ? AND redcap_pid = ? AND ISNULL(project_id) ORDER BY log_id DESC LIMIT 2',
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
            $providerChanged = $prior->providerId !== $binding->providerId;
            if ($prior->uuid !== $binding->uuid || ($prior->identityId !== null && $binding->identityId === null)
                || (!$providerChanged && $prior->pendingProviderId !== null && $prior->identityId !== $binding->identityId)
                || ($prior->pendingProviderId !== null && $binding->pendingProviderId !== null
                    && ($prior->pendingProviderId !== $binding->pendingProviderId || $prior->transitionId !== $binding->transitionId))
                || ($providerChanged && ($prior->pendingProviderId !== $binding->providerId || $binding->pendingProviderId !== null
                    || $binding->identityId === null || $prior->identityId === $binding->identityId))) {
                throw new RuntimeException('Conflicting project identity binding');
            }
        }
        return $binding;
    }

    /** Public-only retirement impact, including disabled projects and pending replacements. */
    public function providerUsage(string $providerId): array
    {
        ProviderRepository::assertId($providerId);
        $latestRows = function (string $message, string $fields): array {
            $result = $this->reader->query('SELECT MAX(log_id) AS latest_id WHERE message = ? AND ISNULL(project_id) GROUP BY redcap_pid', [$message]);
            if ($result === false) { throw new RuntimeException('Provider usage query failed'); }
            $ids = []; $rows = [];
            while ($row = $result->fetch_assoc()) { $ids[] = $row['latest_id']; }
            foreach (array_chunk($ids, 200) as $batch) {
                $result = $this->reader->query('SELECT ' . $fields . ' WHERE message = ? AND ISNULL(project_id) AND log_id IN ('
                    . implode(',', array_fill(0, count($batch), '?')) . ')', [$message, ...$batch]);
                if ($result === false) { throw new RuntimeException('Provider usage query failed'); }
                while ($row = $result->fetch_assoc()) { $rows[] = $row; }
            }
            return $rows;
        };
        $projects = [];
        foreach ($latestRows(self::MESSAGE, 'redcap_pid, project_uuid, identity_id, provider_id, pending_provider_id, transition_id') as $row) {
            $binding = $this->parse($row);
            if ($binding->providerId !== $providerId && $binding->pendingProviderId !== $providerId) { continue; }
            $pid = filter_var($row['redcap_pid'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($pid === false) { throw new RuntimeException('Invalid project in provider usage'); }
            $projects[$pid] = ['pid' => $pid, 'identity_id' => $binding->providerId === $providerId ? $binding->identityId : null, 'enrollment_id' => null];
            if ($binding->transitionId !== null) { $projects[$pid]['transition_id'] = $binding->transitionId; }
        }
        foreach ($latestRows('project_enrollment', 'redcap_pid, action, enrollment_id, provider_id') as $row) {
            $pid = (int) ($row['redcap_pid'] ?? 0);
            if (isset($projects[$pid]) && $row['action'] === 'generate' && ($row['provider_id'] ?? null) === $providerId) {
                if (!is_string($row['enrollment_id'] ?? null)
                    || preg_match('/^[a-f0-9]{32}$/D', $row['enrollment_id']) !== 1) { throw new RuntimeException('Invalid enrollment usage'); }
                $projects[$pid]['enrollment_id'] = $row['enrollment_id'];
            }
        }
        ksort($projects, SORT_NUMERIC);
        return array_values($projects);
    }

    /** Caller holds project/configuration locks and transaction. */
    public function startTransition(int $pid, string $target): string
    {
        $current = $this->find($pid);
        if ($current === null || $current->pendingProviderId !== null || $current->providerId === $target) {
            throw new RuntimeException('A different provider and no pending transition are required');
        }
        ProviderRepository::assertId($target);
        $id = bin2hex(random_bytes(16));
        $this->append($pid, $current->uuid, $current->identityId, $current->providerId, $target, $id);
        return $id;
    }

    /** Caller has already canceled any CSR under the same locks/transaction. */
    public function cancelTransition(int $pid, string $expectedId): void
    {
        $current = $this->find($pid);
        if ($current === null || $current->transitionId !== $expectedId) { throw new RuntimeException('Transition changed'); }
        $this->append($pid, $current->uuid, $current->identityId, $current->providerId);
    }

    public function bindUuid(int $pid, string $uuid, string $providerId): void
    {
        self::assertPid($pid);
        self::assertUuid($uuid);
        if ($this->find($pid) !== null) {
            throw new RuntimeException('Project UUID is already bound');
        }
        $this->append($pid, $uuid, null, $providerId);
    }

    public function activate(int $pid, string $uuid, string $identityId): void
    {
        self::assertPid($pid);
        self::assertUuid($uuid);
        if (preg_match('/^[0-9a-f]{32}$/D', $identityId) !== 1) {
            throw new RuntimeException('Invalid project identity ID');
        }
        $current = $this->find($pid);
        if ($current === null || $current->uuid !== $uuid || $current->identityId !== null || $current->pendingProviderId !== null) {
            throw new RuntimeException('Project identity activation requires an unassigned UUID binding');
        }
        $this->append($pid, $uuid, $identityId, $current->providerId);
    }

    /** Caller holds the project lock and transaction; protects against a stale activation review. */
    public function replace(int $pid, string $uuid, ?string $expectedIdentityId, string $identityId, ?string $providerId = null): void
    {
        self::assertPid($pid); self::assertUuid($uuid);
        $current = $this->find($pid);
        if ($current === null || $current->uuid !== $uuid || $current->identityId !== $expectedIdentityId
            || preg_match('/^[a-f0-9]{32}$/D', $identityId) !== 1) {
            throw new RuntimeException('Project identity changed before activation');
        }
        $providerId ??= $current->providerId;
        if ($providerId !== $current->enrollmentProviderId()) { throw new RuntimeException('Identity provider does not match enrollment assignment'); }
        $this->append($pid, $uuid, $identityId, $providerId);
    }

    private function append(int $pid, string $uuid, ?string $identityId, string $providerId, ?string $pendingProviderId = null, ?string $transitionId = null): void
    {
        ProviderRepository::assertId($providerId);
        $logId = $this->framework->log(self::MESSAGE, [
            'project_id' => null,
            'record' => '',
            'redcap_pid' => (string) $pid,
            'project_uuid' => $uuid,
            'identity_id' => $identityId,
            'provider_id' => $providerId,
            'pending_provider_id' => $pendingProviderId, 'transition_id' => $transitionId,
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
        ProviderRepository::assertId($row['provider_id'] ?? null);
        $pending = $row['pending_provider_id'] ?? null; $transition = $row['transition_id'] ?? null;
        if (($pending === null) !== ($transition === null)) { throw new RuntimeException('Incomplete provider transition'); }
        if ($pending !== null) {
            ProviderRepository::assertId($pending);
            if ($pending === $row['provider_id'] || !is_string($transition) || preg_match('/^[a-f0-9]{32}$/D', $transition) !== 1) {
                throw new RuntimeException('Invalid provider transition');
            }
        }
        return new ProjectBinding($uuid, $id, $row['provider_id'], $pending, $transition);
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
