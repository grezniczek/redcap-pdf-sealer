<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pki;

use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\Certificate;
use RuntimeException;
use Throwable;

/** CC public overview. No issuance, private-key reads, or certificate-health assertions. */
final readonly class ProjectAdminOverview
{
    public function __construct(private object $framework, private IdentityRepository $identities) {}

    public function load(?array $pids = null): array
    {
        $reader = new PrimaryLogReader($this->framework);
        $latest = function (string $message, string $fields) use ($reader, $pids): array {
            $filter = $pids === null ? '' : ' AND redcap_pid IN (' . implode(',', array_fill(0, count($pids), '?')) . ')';
            $result = $reader->query('SELECT MAX(log_id) AS latest_id WHERE message = ? AND ISNULL(project_id)' . $filter . ' GROUP BY redcap_pid', [$message, ...($pids ?? [])]);
            if ($result === false) { throw new RuntimeException('Project overview unavailable'); }
            $ids = []; $rows = [];
            while ($row = $result->fetch_assoc()) { $ids[] = $row['latest_id']; }
            foreach (array_chunk($ids, 200) as $batch) {
                $result = $reader->query('SELECT ' . $fields . ' WHERE message = ? AND ISNULL(project_id) AND log_id IN (' . implode(',', array_fill(0, count($batch), '?')) . ')', [$message, ...$batch]);
                if ($result === false) { throw new RuntimeException('Project overview unavailable'); }
                while ($row = $result->fetch_assoc()) { $rows[(int) $row['redcap_pid']] = $row; }
            }
            return $rows;
        };
        $bindings = $latest('project_identity_binding', 'redcap_pid, project_uuid, provider_id, identity_id, pending_provider_id, transition_id');
        $enrollments = $latest('project_enrollment', 'redcap_pid, action, provider_id, enrollment_id');
        $enabled = array_map('intval', $this->framework->getProjectsWithModuleEnabled());
        $ids = $pids ?? array_values(array_unique([...$enabled, ...array_keys($bindings)]));
        if ($ids === []) { return []; }
        foreach ($ids as $id) { ProjectBindingRepository::assertPid($id); }
        $revoked = [];
        $result = $reader->query('SELECT identity_id, certificate_sha256 WHERE message = ? AND ISNULL(project_id)', ['project_certificate_revocation']);
        if ($result === false) { throw new RuntimeException('Project revocations unavailable'); }
        while ($row = $result->fetch_assoc()) { $revoked[$row['identity_id']] = $row['certificate_sha256']; }
        $providerCache = []; $projects = [];
        foreach (array_chunk($ids, 200) as $batch) {
            $result = $this->framework->query('SELECT project_id, app_title, status, completed_time, date_deleted FROM redcap_projects WHERE project_id IN (' . implode(',', array_fill(0, count($batch), '?')) . ') ORDER BY app_title, project_id', $batch);
            if ($result === false) { throw new RuntimeException('Projects unavailable'); }
            while ($row = $result->fetch_assoc()) {
                $pid = (int) $row['project_id'];
                if (!isset($bindings[$pid]) && !in_array($pid, $enabled, true)) { continue; }
                $project = ['pid' => $pid, 'name' => $row['app_title'], 'status' => match ((int) $row['status']) {
                    0 => 'development', 1 => 'production', 2 => empty($row['completed_time']) ? 'analysis' : 'completed', default => 'unknown',
                }, 'enabled' => in_array($pid, $enabled, true), 'deleted' => !empty($row['date_deleted']),
                    'binding' => null, 'certificate' => null, 'unavailable' => false];
                try {
                    $binding = $bindings[$pid] ?? null;
                    if ($binding !== null) {
                        ProviderRepository::assertId($binding['provider_id']);
                        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $binding['project_uuid'] ?? '') !== 1) {
                            throw new RuntimeException('Invalid project UUID');
                        }
                        foreach (['identity_id', 'transition_id'] as $key) {
                            if (isset($binding[$key]) && preg_match('/^[a-f0-9]{32}$/D', $binding[$key]) !== 1) { throw new RuntimeException('Invalid binding identity'); }
                        }
                        if (($binding['pending_provider_id'] ?? null) !== null) { ProviderRepository::assertId($binding['pending_provider_id']); }
                        if (isset($binding['pending_provider_id']) !== isset($binding['transition_id'])
                            || (isset($binding['pending_provider_id']) && $binding['pending_provider_id'] === $binding['provider_id'])) {
                            throw new RuntimeException('Invalid pending transition');
                        }
                        $state = array_intersect_key($binding, array_flip(['provider_id', 'identity_id', 'pending_provider_id', 'transition_id']));
                        $state += ['identity_id' => null, 'pending_provider_id' => null, 'transition_id' => null];
                        $enrollment = $enrollments[$pid] ?? null;
                        $state['enrollment_id'] = $enrollment !== null && $enrollment['action'] === 'generate'
                            && $enrollment['provider_id'] === ($state['pending_provider_id'] ?? $state['provider_id']) ? $enrollment['enrollment_id'] : null;
                        if ($state['enrollment_id'] !== null && preg_match('/^[a-f0-9]{32}$/D', $state['enrollment_id']) !== 1) { throw new RuntimeException('Invalid pending enrollment'); }
                        $project['binding'] = $state;
                        if (!isset($providerCache[$state['provider_id']])) {
                            $providers = $this->identities->providers();
                            $providerCache[$state['provider_id']] = $providers->provider($state['provider_id']) + ['retired' => $providers->isRetired($state['provider_id'])];
                        }
                        if ($state['identity_id'] !== null) {
                            $der = $this->identities->publicCertificate($state['identity_id'], 'project');
                            $details = openssl_x509_parse(Certificate::derToPem($der));
                            if (!is_array($details) || ($details['subject']['CN'] ?? null) !== 'REDCap Project ' . $binding['project_uuid']) { throw new RuntimeException('Certificate binding unavailable'); }
                            $fingerprint = hash('sha256', $der);
                            if (isset($revoked[$state['identity_id']]) && $revoked[$state['identity_id']] !== $fingerprint) { throw new RuntimeException('Revocation metadata mismatch'); }
                            $project['certificate'] = ['subject' => $details['name'], 'fingerprint' => $fingerprint, 'thumbprint' => hash('sha1', $der),
                                'valid_from' => $details['validFrom_time_t'], 'valid_until' => $details['validTo_time_t'],
                                'status' => isset($revoked[$state['identity_id']]) ? 'revoked' : ($details['validTo_time_t'] < time() ? 'expired' : ($details['validFrom_time_t'] > time() ? 'not_yet_valid' : 'current'))];
                        }
                    }
                } catch (Throwable) { $project['unavailable'] = true; }
                $state = $project['binding']; $certificate = $project['certificate'];
                $usable = !$project['unavailable']; $operational = $usable && $project['enabled'] && !$project['deleted'];
                $builtinSigner = $usable && $state !== null && $state['provider_id'] === ProviderRepository::BUILTIN_CA && $certificate !== null;
                $project['eligible'] = ['assign' => $operational && $state === null,
                    'change' => $operational && $state !== null && $state['pending_provider_id'] === null && $state['enrollment_id'] === null,
                    'cancel' => $operational && $state !== null && $state['transition_id'] !== null,
                    'renew' => $operational && $builtinSigner && $state['pending_provider_id'] === null && $state['enrollment_id'] === null && !($providerCache[$state['provider_id']]['retired'] ?? true),
                    'revoke' => $builtinSigner && $certificate['status'] !== 'revoked'];
                $projects[] = $project;
            }
        }
        return $projects;
    }
}
