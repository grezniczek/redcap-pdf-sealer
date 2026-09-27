<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pki;

use RuntimeException;

/** Public certificates referenced by current configuration/bindings; never reads private keys. */
final class ExpiryInventory
{
    private PrimaryLogReader $logs;
    private PrimarySystemSettingReader $settings;

    public function __construct(private readonly object $framework, ?PrimaryLogReader $logs = null, ?PrimarySystemSettingReader $settings = null)
    {
        $this->logs = $logs ?? new PrimaryLogReader($framework);
        $this->settings = $settings ?? new PrimarySystemSettingReader($framework);
    }

    /** @return array<string, array{role:string, pid:?int, der:?string}> Missing/corrupt certificates have null DER. */
    public function collect(): array
    {
        $wanted = [];
        $add = static function (mixed $id, string $role, ?int $pid = null) use (&$wanted): void {
            if (!is_string($id) || preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
                throw new RuntimeException('Invalid expiry inventory reference');
            }
            if (isset($wanted[$id]) && ($wanted[$id]['role'] !== $role || $wanted[$id]['pid'] !== $pid)) {
                throw new RuntimeException('Conflicting expiry inventory reference');
            }
            $wanted[$id] ??= ['role' => $role, 'pid' => $pid, 'der' => null];
        };
        $providers = new ProviderRepository($this->framework, $this->settings);
        $providerIds = array_fill_keys(array_filter($providers->externalIds(), fn(string $id): bool => !$providers->isRetired($id)), true);
        foreach ($providers->publicCertificates() as $certificate) {
            if ($certificate['retired']) { continue; }
            $wanted[$certificate['id']] = ['role' => 'ca', 'pid' => null, 'der' => $certificate['der']];
        }
        if ($providers->hasConfiguration() && !$providers->isRetired($providers->defaultId())) { $providerIds[$providers->defaultId()] = true; }
        foreach (['root', 'tsa'] as $role) {
            $id = $this->settings->get('active_' . $role . '_identity_id');
            if ($id !== null && ($role === 'tsa' || !$providers->hasConfiguration() || !$providers->isRetired(ProviderRepository::BUILTIN_CA))) { $add($id, $role); }
        }
        // Select only each project's latest binding; older certificates are not active signers.
        $latest = $this->query(
            'SELECT MAX(log_id) AS latest_id WHERE message = ? AND ISNULL(project_id) GROUP BY redcap_pid',
            ['project_identity_binding'],
        );
        $bindingIds = [];
        while ($row = $latest->fetch_assoc()) { $bindingIds[] = $row['latest_id']; }
        foreach (array_chunk($bindingIds, 200) as $batch) {
            $rows = $this->query('SELECT redcap_pid, identity_id, provider_id WHERE message = ? AND ISNULL(project_id) AND log_id IN ('
                . implode(',', array_fill(0, count($batch), '?')) . ')', ['project_identity_binding', ...$batch]);
            while ($row = $rows->fetch_assoc()) {
                $pid = filter_var($row['redcap_pid'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($pid === false) { throw new RuntimeException('Invalid project in expiry inventory'); }
                ProviderRepository::assertId($row['provider_id'] ?? null);
                if (($row['identity_id'] ?? null) !== null) {
                    $providerIds[$row['provider_id']] = true;
                    $add($row['identity_id'], 'project', $pid);
                }
            }
        }
        foreach (array_keys($providerIds) as $providerId) {
            $provider = $providers->provider($providerId);
            if ($provider['kind'] === 'internal') { $add($provider['issuer_identity_id'], 'root'); }
            if ($provider['timestamp_source'] !== null) {
                $source = $providers->source($provider['timestamp_source']);
                $add($source['identity_id'], 'tsa');
                $add($source['issuer_identity_id'], 'root');
            }
        }
        if ($this->settings->get('active_tsa_identity_id') !== null && $providers->hasConfiguration()) {
            $add($providers->source(ProviderRepository::BUILTIN_TSA)['issuer_identity_id'], 'root');
        }
        // A project's recorded issuer can differ from its provider's current issuance identity.
        $this->loadCertificates($wanted, true);
        foreach ($wanted as $item) {
            if (!empty($item['issuer_chain'])) {
                foreach ($item['issuer_chain'] as $der) {
                    $wanted[hash('sha256', $der)] = ['role' => 'ca', 'pid' => null, 'der' => $der];
                }
            } elseif (isset($item['issuer'])) { $add($item['issuer'], 'root'); }
        }
        $this->loadCertificates($wanted, false);
        foreach ($wanted as &$item) { unset($item['issuer'], $item['issuer_chain']); }
        return $wanted;
    }

    private function loadCertificates(array &$wanted, bool $withIssuers): void
    {
        $ids = array_keys(array_filter($wanted, static fn(array $item): bool => $item['der'] === null));
        foreach (array_chunk($ids, 200) as $batch) {
            $rows = $this->query('SELECT identity_id, identity_role, certificate_der_b64, certificate_sha256, issuer_identity_id, issuer_chain_json WHERE message = ? AND ISNULL(project_id) AND identity_id IN ('
                . implode(',', array_fill(0, count($batch), '?')) . ')', ['pki_identity', ...$batch]);
            $seen = [];
            while ($row = $rows->fetch_assoc()) {
                $id = $row['identity_id'];
                if (!isset($wanted[$id])) { throw new RuntimeException('Unexpected expiry certificate'); }
                if (isset($seen[$id])) { throw new RuntimeException('Duplicate expiry certificate'); }
                $seen[$id] = true;
                if ($withIssuers && $wanted[$id]['role'] === 'project') {
                    $wanted[$id]['issuer'] = $row['issuer_identity_id'] ?? '';
                    $wanted[$id]['issuer_chain'] = IdentityRepository::decodeIssuerChain($row['issuer_identity_id'] ?? null, $row['issuer_chain_json'] ?? null);
                }
                $der = is_string($row['certificate_der_b64'] ?? null) ? base64_decode($row['certificate_der_b64'], true) : false;
                if ($der !== false && is_string($row['certificate_sha256'] ?? null)
                    && hash_equals($row['certificate_sha256'], hash('sha256', $der))
                    && ($row['identity_role'] ?? null) === $wanted[$id]['role']) {
                    $wanted[$id]['der'] = $der;
                }
            }
        }
    }

    private function query(string $sql, array $params): mixed
    {
        $result = $this->logs->query($sql, $params);
        if ($result === false) { throw new RuntimeException('Expiry inventory query failed'); }
        return $result;
    }
}
