<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pki;

use DE\RUB\PDFSealerExternalModule\Timestamp\TimestampSettings;
use DE\RUB\PDFSealerExternalModule\Timestamp\TsaPolicy;
use RuntimeException;

/** System-scoped CA providers and timestamp source configuration. */
final class ProviderRepository
{
    public const BUILTIN_CA = 'builtin-ca';
    public const BUILTIN_TSA = 'builtin-tsa';
    private PrimarySystemSettingReader $settings;

    public function __construct(private readonly object $framework, ?PrimarySystemSettingReader $settings = null)
    {
        $this->settings = $settings ?? new PrimarySystemSettingReader($framework);
    }

    public function initialize(string $rootId, string $tsaId): void
    {
        if ($this->hasConfiguration()) {
            throw new RuntimeException('Provider configuration already exists');
        }
        self::identityId($rootId);
        self::identityId($tsaId);
        $this->write('tsa_source_' . self::BUILTIN_TSA, [
            'id' => self::BUILTIN_TSA, 'kind' => 'internal',
            'identity_id' => $tsaId, 'issuer_identity_id' => $rootId, 'policy_oid' => TsaPolicy::DEFAULT_OID,
        ]);
        $this->write('ca_provider_' . self::BUILTIN_CA, [
            'id' => self::BUILTIN_CA, 'kind' => 'internal', 'issuer_identity_id' => $rootId,
            'timestamp_source' => self::BUILTIN_TSA, 'timestamp_alternatives' => [], 'bb_fallback' => true,
        ]);
        $this->framework->setSystemSetting('default_ca_provider', self::BUILTIN_CA);
    }

    public function hasConfiguration(): bool
    {
        return $this->settings->get('default_ca_provider') !== null
            || $this->settings->get('ca_provider_' . self::BUILTIN_CA) !== null
            || $this->settings->get('tsa_source_' . self::BUILTIN_TSA) !== null;
    }

    /** Missing policy means the explicit-assignment gate is off; malformed storage fails closed. */
    public function requiresAssignment(): bool
    {
        return match ($this->settings->get('require_ca_assignment')) {
            null, 'false' => false,
            'true' => true,
            default => throw new RuntimeException('Invalid CA assignment policy'),
        };
    }

    /** Caller holds the configuration lock and transaction. */
    public function saveAssignmentPolicy(bool $required): void
    {
        $default = $this->settings->get('default_ca_provider');
        if (!$required && is_string($default)) { $this->assertActive($default); }
        $this->framework->setSystemSetting('require_ca_assignment', $required ? 'true' : 'false');
    }

    /** Absence means active; malformed lifecycle settings fail closed. */
    public function isRetired(string $id): bool
    {
        $this->provider($id);
        return match ($this->settings->get('ca_provider_retired_' . $id)) {
            null, 'false' => false,
            'true' => true,
            default => throw new RuntimeException('Invalid CA lifecycle state'),
        };
    }

    public function assertActive(string $id): void
    {
        if ($this->isRetired($id)) { throw new CaProviderRetired('The CA provider is retired'); }
    }

    public function isDefault(string $id): bool
    {
        $this->provider($id);
        return $this->settings->get('default_ca_provider') === $id;
    }

    /** Caller holds configuration lock and transaction. Public material is retained. */
    public function saveRetired(string $id, bool $retired): void
    {
        $this->provider($id);
        if ($retired && $this->isDefault($id) && !$this->requiresAssignment()) {
            throw new RuntimeException('Retiring the default CA requires explicit assignment');
        }
        $this->framework->setSystemSetting('ca_provider_retired_' . $id, $retired ? 'true' : 'false');
    }

    public function defaultId(): string
    {
        $id = $this->settings->get('default_ca_provider');
        self::assertId($id);
        $this->provider($id);
        return $id;
    }

    /** @return array{id:string, kind:string, issuer_identity_id?:string, name?:string, chain?:array, timestamp_source:?string, timestamp_alternatives:list<string>, bb_fallback:bool} */
    public function provider(string $id): array
    {
        $value = $this->read('ca_provider_', $id);
        if (!array_key_exists('timestamp_alternatives', $value)) { $value['timestamp_alternatives'] = []; }
        self::assertTimestampOrder($value['timestamp_source'] ?? null, $value['timestamp_alternatives']);
        if (($value['id'] ?? null) !== $id || !is_bool($value['bb_fallback'] ?? null)
            || !array_key_exists('timestamp_source', $value)) { throw new RuntimeException('Invalid CA provider'); }
        if (($value['kind'] ?? null) === 'internal') {
            if (count($value) !== 6) { throw new RuntimeException('Invalid internal provider'); }
            self::identityId($value['issuer_identity_id'] ?? null);
        } elseif (($value['kind'] ?? null) === 'external') {
            if (count($value) !== 7 || !is_string($value['name'] ?? null) || trim($value['name']) === ''
                || strlen($value['name']) > 128 || !is_array($value['chain'] ?? null)
                || !array_is_list($value['chain']) || count($value['chain']) < 1 || count($value['chain']) > 8) {
                throw new RuntimeException('Invalid external provider');
            }
            foreach ($value['chain'] as $cert) { self::certificateDer($cert); }
        } else { throw new RuntimeException('Unsupported CA provider'); }
        if ($value['timestamp_source'] !== null) { self::assertId($value['timestamp_source']); }
        return $value;
    }

    /** Internal identity references or validated external source configuration. */
    public function source(string $id): array
    {
        $value = $this->read('tsa_source_', $id);
        if (($value['kind'] ?? null) === 'external') {
            return \DE\RUB\PDFSealerExternalModule\Timestamp\ExternalTimestampSources::validate($value, $id);
        }
        if (count($value) !== 5 || array_diff(['id', 'kind', 'identity_id', 'issuer_identity_id', 'policy_oid'], array_keys($value)) !== []
            || $value['id'] !== $id || $value['kind'] !== 'internal'
            || !is_string($value['policy_oid']) || $value['policy_oid'] === '') {
            throw new RuntimeException('Invalid or unsupported timestamp source');
        }
        self::identityId($value['identity_id']);
        self::identityId($value['issuer_identity_id']);
        return $value;
    }

    public function timestampSettings(string $id): TimestampSettings
    {
        $provider = $this->provider($id);
        $mode = $provider['timestamp_source'] === null ? 'none' : $this->source($provider['timestamp_source'])['kind'];
        return new TimestampSettings($mode, $provider['bb_fallback']);
    }

    /** Caller enforces CC authorization and transaction boundaries. */
    public function saveBuiltinTimestamp(string $mode, bool $fallback): void
    {
        if (!in_array($mode, ['internal', 'none'], true)) { throw new RuntimeException('Unsupported timestamp mode'); }
        $provider = $this->provider(self::BUILTIN_CA);
        $this->source(self::BUILTIN_TSA);
        $provider['timestamp_alternatives'] = [];
        $provider['timestamp_source'] = $mode === 'none' ? null : self::BUILTIN_TSA;
        $provider['bb_fallback'] = $fallback;
        $this->write('ca_provider_' . self::BUILTIN_CA, $provider);
    }

    /** Caller holds the configuration lock and transaction; applies to future seals. */
    public function saveTimestampPolicy(string $id, ?string $source, bool $fallback, array $alternatives = []): void
    {
        $provider = $this->provider($id);
        self::assertTimestampOrder($source, $alternatives);
        foreach ($source === null ? [] : [$source, ...$alternatives] as $position => $sourceId) {
            $timestamp = $this->source($sourceId);
            $previous = $position === 0 ? $provider['timestamp_source'] : ($provider['timestamp_alternatives'][$position - 1] ?? null);
            // Existing retired references may be retained while adjusting fallback/other sources, but never newly assigned.
            if ($timestamp['kind'] === 'external' && $sourceId !== $previous) {
                (new \DE\RUB\PDFSealerExternalModule\Timestamp\ExternalTimestampSources($this->framework, $this->settings))->assertUsable($sourceId);
            }
        }
        $provider['timestamp_alternatives'] = $alternatives;
        $provider['timestamp_source'] = $source;
        $provider['bb_fallback'] = $source !== null && $fallback;
        $this->write('ca_provider_' . $id, $provider);
    }

    public static function assertTimestampOrder(?string $primary, mixed $alternatives): void
    {
        if (!is_array($alternatives) || !array_is_list($alternatives)
            || count($alternatives) > \DE\RUB\PDFSealerExternalModule\Timestamp\OrderedTimestampProvider::MAX_ALTERNATIVES
            || ($primary === null && $alternatives !== [])) {
            throw new RuntimeException('Invalid timestamp alternatives');
        }
        $order = $primary === null ? [] : [$primary, ...$alternatives];
        foreach ($order as $id) { self::assertId($id); }
        if (count(array_unique($order)) !== count($order)) { throw new RuntimeException('Duplicate timestamp source'); }
    }

    /** @return list<string> */
    public function externalIds(): array
    {
        $stored = $this->settings->get('external_ca_provider_ids');
        if ($stored === null) { return []; }
        $ids = is_string($stored) ? json_decode($stored, true, 8, JSON_THROW_ON_ERROR) : null;
        if (!is_array($ids) || !array_is_list($ids) || count($ids) !== count(array_unique($ids, SORT_REGULAR))) {
            throw new RuntimeException('Invalid external provider catalog');
        }
        foreach ($ids as $id) {
            self::assertId($id);
            if (!str_starts_with($id, 'external-')) { throw new RuntimeException('Invalid external provider ID'); }
        }
        return $ids;
    }

    /** Caller validates the chain and holds the configuration lock/transaction. */
    public function registerExternal(string $name, array $chain, ?string $source, bool $fallback): string
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 128 || preg_match('/[\x00-\x1f\x7f]/', $name)) { throw new RuntimeException('Invalid provider name'); }
        if ($source !== null) {
            $timestamp = $this->source($source);
            if ($timestamp['kind'] === 'external') {
                (new \DE\RUB\PDFSealerExternalModule\Timestamp\ExternalTimestampSources($this->framework, $this->settings))->assertUsable($source);
            }
        }
        $ids = $this->externalIds();
        foreach ($ids as $existing) {
            $provider = $this->provider($existing);
            if ($provider['chain'] === $chain) { throw new RuntimeException('This CA chain is already registered'); }
        }
        $id = 'external-' . bin2hex(random_bytes(8));
        $value = ['id' => $id, 'kind' => 'external', 'name' => $name, 'chain' => $chain,
            'timestamp_source' => $source, 'timestamp_alternatives' => [], 'bb_fallback' => $fallback];
        $this->write('ca_provider_' . $id, $value);
        $this->provider($id);
        $ids[] = $id;
        $this->write('external_ca_provider_ids', $ids);
        return $id;
    }

    /** Public certificate metadata only; includes intermediate CAs and roots. */
    public function publicCertificates(): array
    {
        $certificates = [];
        foreach ($this->externalIds() as $id) {
            $provider = $this->provider($id);
            foreach ($provider['chain'] as $index => $record) {
                $der = self::certificateDer($record);
                $details = openssl_x509_parse(\DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\Certificate::derToPem($der));
                if (!is_array($details) || !is_string($details['name'] ?? null)
                    || !is_int($details['validFrom_time_t'] ?? null) || !is_int($details['validTo_time_t'] ?? null)) {
                    throw new RuntimeException('Invalid public CA certificate');
                }
                $certificates[] = ['id' => $record['sha256'], 'der' => $der, 'fingerprint' => $record['sha256'], 'thumbprint' => hash('sha1', $der),
                    'subject' => $details['name'], 'valid_from' => $details['validFrom_time_t'],
                    'valid_until' => $details['validTo_time_t'], 'provider_id' => $id, 'provider_name' => $provider['name'],
                    'trust_anchor' => $index === count($provider['chain']) - 1, 'retired' => $this->isRetired($id)];
            }
        }
        return $certificates;
    }

    public static function certificateDer(mixed $certificate): string
    {
        if (!is_array($certificate) || count($certificate) !== 2 || !is_string($certificate['der_b64'] ?? null)
            || !is_string($certificate['sha256'] ?? null)) { throw new RuntimeException('Invalid CA certificate record'); }
        $der = base64_decode($certificate['der_b64'], true);
        if ($der === false || !hash_equals(hash('sha256', $der), $certificate['sha256'])) { throw new RuntimeException('CA certificate digest mismatch'); }
        return $der;
    }

    public static function assertId(mixed $id): void
    {
        if (!is_string($id) || preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $id) !== 1) {
            throw new RuntimeException('Missing or invalid provider/source ID');
        }
    }

    private static function identityId(mixed $id): void
    {
        if (!is_string($id) || preg_match('/^[0-9a-f]{32}$/D', $id) !== 1) {
            throw new RuntimeException('Invalid provider/source identity ID');
        }
    }

    private function read(string $prefix, string $id): array
    {
        self::assertId($id);
        $json = $this->settings->get($prefix . $id);
        if (!is_string($json)) { throw new RuntimeException('Provider/source is not configured'); }
        $value = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($value)) { throw new RuntimeException('Invalid provider/source configuration'); }
        return $value;
    }

    private function write(string $key, array $value): void
    {
        $this->framework->setSystemSetting($key, json_encode($value, JSON_THROW_ON_ERROR));
    }
}
