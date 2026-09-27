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

    /** @return array{id:string, kind:string, issuer_identity_id?:string, name?:string, chain?:array, timestamp_source:?string, bb_fallback:bool} */
    public function provider(string $id): array
    {
        $value = $this->read('ca_provider_', $id);
        if (($value['id'] ?? null) !== $id || !is_bool($value['bb_fallback'] ?? null)
            || !array_key_exists('timestamp_source', $value)) { throw new RuntimeException('Invalid CA provider'); }
        if (($value['kind'] ?? null) === 'internal') {
            if (count($value) !== 5) { throw new RuntimeException('Invalid internal provider'); }
            self::identityId($value['issuer_identity_id'] ?? null);
        } elseif (($value['kind'] ?? null) === 'external') {
            if (count($value) !== 6 || !is_string($value['name'] ?? null) || trim($value['name']) === ''
                || strlen($value['name']) > 128 || !is_array($value['chain'] ?? null)
                || !array_is_list($value['chain']) || count($value['chain']) < 1 || count($value['chain']) > 8) {
                throw new RuntimeException('Invalid external provider');
            }
            foreach ($value['chain'] as $cert) { self::certificateDer($cert); }
        } else { throw new RuntimeException('Unsupported CA provider'); }
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
        if ($source !== null) { $this->source($source); }
        $ids = $this->externalIds();
        foreach ($ids as $existing) {
            $provider = $this->provider($existing);
            if ($provider['chain'] === $chain) { throw new RuntimeException('This CA chain is already registered'); }
        }
        $id = 'external-' . bin2hex(random_bytes(8));
        $value = ['id' => $id, 'kind' => 'external', 'name' => $name, 'chain' => $chain,
            'timestamp_source' => $source, 'bb_fallback' => $fallback];
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
                $certificates[] = ['id' => $record['sha256'], 'der' => $der, 'fingerprint' => $record['sha256'],
                    'subject' => $details['name'], 'valid_from' => $details['validFrom_time_t'],
                    'valid_until' => $details['validTo_time_t'], 'provider_id' => $id, 'provider_name' => $provider['name'],
                    'trust_anchor' => $index === count($provider['chain']) - 1];
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
