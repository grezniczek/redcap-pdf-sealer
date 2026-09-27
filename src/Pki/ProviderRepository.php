<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pki;

use DE\RUB\PDFSealerExternalModule\Timestamp\TimestampSettings;
use DE\RUB\PDFSealerExternalModule\Timestamp\TsaPolicy;
use RuntimeException;

/** System-scoped configuration. Only the built-in provider/source can be created in this slice. */
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
            'timestamp_source' => self::BUILTIN_TSA, 'bb_fallback' => true,
        ]);
        $this->framework->setSystemSetting('default_ca_provider', self::BUILTIN_CA);
    }

    public function hasConfiguration(): bool
    {
        return $this->settings->get('default_ca_provider') !== null
            || $this->settings->get('ca_provider_' . self::BUILTIN_CA) !== null
            || $this->settings->get('tsa_source_' . self::BUILTIN_TSA) !== null;
    }

    public function defaultId(): string
    {
        $id = $this->settings->get('default_ca_provider');
        self::assertId($id);
        $this->provider($id);
        return $id;
    }

    /** @return array{id:string, kind:string, issuer_identity_id:string, timestamp_source:?string, bb_fallback:bool} */
    public function provider(string $id): array
    {
        $value = $this->read('ca_provider_', $id);
        if (count($value) !== 5 || array_diff(['id', 'kind', 'issuer_identity_id', 'timestamp_source', 'bb_fallback'], array_keys($value)) !== []
            || $value['id'] !== $id || $value['kind'] !== 'internal' || !is_bool($value['bb_fallback'])) {
            throw new RuntimeException('Invalid or unsupported CA provider');
        }
        self::identityId($value['issuer_identity_id']);
        if ($value['timestamp_source'] !== null) { self::assertId($value['timestamp_source']); }
        return $value;
    }

    /** @return array{id:string, kind:string, identity_id:string, issuer_identity_id:string, policy_oid:string} */
    public function source(string $id): array
    {
        $value = $this->read('tsa_source_', $id);
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
        if ($provider['timestamp_source'] !== null) { $this->source($provider['timestamp_source']); }
        return new TimestampSettings($provider['timestamp_source'] === null ? 'none' : 'internal', $provider['bb_fallback']);
    }

    /** Caller enforces CC authorization and transaction boundaries. */
    public function saveBuiltinTimestamp(string $mode, bool $fallback): void
    {
        if (!in_array($mode, ['internal', 'none'], true)) { throw new RuntimeException('Unsupported timestamp mode'); }
        $provider = $this->provider(self::BUILTIN_CA);
        $this->source(self::BUILTIN_TSA);
        $provider['timestamp_source'] = $mode === 'none' ? null : self::BUILTIN_TSA;
        $provider['bb_fallback'] = $fallback;
        $this->write('ca_provider_' . self::BUILTIN_CA, $provider);
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
