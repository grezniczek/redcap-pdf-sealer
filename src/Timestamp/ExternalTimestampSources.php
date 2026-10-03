<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Timestamp;

use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\{Certificate, SignedDataVerifier};
use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Timestamp\{Client, Config};
use DE\RUB\PDFSealerExternalModule\Pki\{CaChainValidator, PkiInitializationLock, PrimarySystemSettingReader, ProviderRepository, SecretProtector};
use RuntimeException;
use Throwable;

/** CC-managed immutable sources; credentials never enter public summaries or audit payloads. */
final class ExternalTimestampSources
{
    private PrimarySystemSettingReader $settings;
    public function __construct(private readonly object $framework, ?PrimarySystemSettingReader $settings = null)
    {
        $this->settings = $settings ?? new PrimarySystemSettingReader($framework);
    }

    public function ids(): array
    {
        $json = $this->settings->get('external_tsa_source_ids');
        if ($json === null) { return []; }
        $ids = is_string($json) ? json_decode($json, true, 8, JSON_THROW_ON_ERROR) : null;
        if (!is_array($ids) || !array_is_list($ids) || count($ids) > 16 || count(array_unique($ids, SORT_REGULAR)) !== count($ids)) {
            throw new RuntimeException('Invalid TSA source catalog');
        }
        foreach ($ids as $id) { self::assertId($id); }
        return $ids;
    }

    private static function assertId(mixed $id): void
    {
        if (!is_string($id) || preg_match('/^remote-tsa-[a-f0-9]{16}$/D', $id) !== 1) { throw new RuntimeException('Invalid external TSA ID'); }
    }

    public static function validate(array $source, string $id): array
    {
        self::assertId($id);
        if (count($source) !== 7 || ($source['id'] ?? null) !== $id || ($source['kind'] ?? null) !== 'external'
            || !is_string($source['name'] ?? null) || trim($source['name']) === '' || strlen($source['name']) > 128
            || preg_match('/[\x00-\x1f\x7f]/', $source['name']) || !is_string($source['endpoint'] ?? null)
            || !is_string($source['policy_oid'] ?? null) || strlen($source['policy_oid']) > 256
            || !array_key_exists('credentials', $source) || ($source['credentials'] !== null
                && (!is_string($source['credentials']) || !str_starts_with($source['credentials'], 'redcap-v1:')))
            || !is_array($source['chain'] ?? null) || !array_is_list($source['chain'])
            || count($source['chain']) < 1 || count($source['chain']) > 8) {
            throw new RuntimeException('Invalid external TSA configuration');
        }
        new HttpsTimestampTransport($source['endpoint']);
        new PolicyOidAsn1($source['policy_oid']);
        foreach ($source['chain'] as $cert) { ProviderRepository::certificateDer($cert); }
        return $source;
    }

    public function get(string $id): array
    {
        self::assertId($id);
        $json = $this->settings->get('tsa_source_' . $id);
        $source = is_string($json) ? json_decode($json, true, 16, JSON_THROW_ON_ERROR) : null;
        if (!is_array($source)) { throw new RuntimeException('Missing external TSA'); }
        return self::validate($source, $id);
    }

    public function register(string $name, #[\SensitiveParameter] string $endpoint, string $pem, string $policy,
        #[\SensitiveParameter] string $username, #[\SensitiveParameter] string $password): string
    {
        try { new HttpsTimestampTransport($endpoint, $username, $password); }
        catch (Throwable) { throw new TimestampSourceRegistrationFailed('endpoint'); }
        try { $chain = (new CaChainValidator($this->framework))->validate($pem); }
        catch (Throwable) { throw new TimestampSourceRegistrationFailed('chain'); }
        try {
            $protector = new SecretProtector();
            $secret = $username === '' ? null : json_encode([$username, $password], JSON_THROW_ON_ERROR);
            $credentials = $secret === null ? null : $protector->encrypt($secret);
            if ($credentials !== null && !hash_equals($secret, $protector->decrypt($credentials))) { throw new RuntimeException('Credential encryption failed'); }
        } catch (Throwable) { throw new TimestampSourceRegistrationFailed('credentials'); }
        $id = 'remote-tsa-' . bin2hex(random_bytes(8));
        try {
            $source = self::validate(['id' => $id, 'kind' => 'external', 'name' => trim($name), 'endpoint' => $endpoint,
                'policy_oid' => $policy, 'chain' => $chain, 'credentials' => $credentials], $id);
        } catch (Throwable) { throw new TimestampSourceRegistrationFailed('details'); }
        try {
            $this->mutate(function () use ($id, $source): void {
                $ids = $this->ids();
                if (count($ids) >= 16) { throw new TimestampSourceRegistrationFailed('limit'); }
                foreach ($ids as $existing) {
                    if ($this->get($existing)['name'] === $source['name']) { throw new TimestampSourceRegistrationFailed('duplicate'); }
                }
                $this->write('tsa_source_' . $id, $source);
                $this->write('external_tsa_source_ids', [...$ids, $id]);
                $this->audit('register', ['source_id' => $id]);
            });
        } catch (TimestampSourceRegistrationFailed $e) {
            throw $e;
        } catch (Throwable $e) {
            error_log('PDF Sealer TSA registration storage failed (' . get_class($e) . ')');
            throw new TimestampSourceRegistrationFailed('storage');
        }
        return $id;
    }

    public function provider(string $id, ?float $deadline = null): ExternalTimestampProvider
    {
        $source = $this->get($id);
        $chain = array_map([ProviderRepository::class, 'certificateDer'], $source['chain']);
        $blocks = (new \DE\RUB\PDFSealerExternalModule\Pki\IdentityRepository($this->framework, new SecretProtector()))->rootRevocations();
        $blocks->assertChain($chain);
        $auth = $source['credentials'] === null ? ['', '']
            : json_decode((new SecretProtector())->decrypt($source['credentials']), true, 4, JSON_THROW_ON_ERROR);
        if (!is_array($auth) || !array_is_list($auth) || count($auth) !== 2 || !is_string($auth[0]) || !is_string($auth[1])) {
            throw new RuntimeException('Invalid TSA credentials');
        }
        $pem = implode('', array_map(static fn(array $cert): string => Certificate::derToPem(ProviderRepository::certificateDer($cert)), $source['chain']));
        return new ExternalTimestampProvider($this->framework, $pem,
            new HttpsTimestampTransport($source['endpoint'], $auth[0], $auth[1], $deadline), $source['policy_oid'],
            static fn() => $blocks->assertChain($chain));
    }

    /** No endpoint, credentials or untrusted service messages are returned to the browser. */
    public function summaries(): array
    {
        $rows = [];
        foreach ($this->ids() as $id) {
            $source = $this->get($id);
            $rows[] = ['id' => $id, 'name' => $source['name'], 'policy_oid' => $source['policy_oid'],
                'authenticated' => $source['credentials'] !== null, 'diagnostic' => $this->snapshot($id)];
        }
        return $rows;
    }

    public function snapshot(string $id): ?array
    {
        self::assertId($id);
        $json = $this->settings->get('tsa_diagnostic_' . $id);
        if ($json === null) { return null; }
        $data = is_string($json) ? json_decode($json, true, 8, JSON_THROW_ON_ERROR) : null;
        if (!is_array($data) || count($data) !== 4 || !is_int($data['checked_at'] ?? null) || !is_bool($data['ok'] ?? null)
            || !array_key_exists('signer_sha256', $data) || !array_key_exists('valid_until', $data)
            || ($data['ok'] && (!is_string($data['signer_sha256']) || preg_match('/^[a-f0-9]{64}$/D', $data['signer_sha256']) !== 1 || !is_int($data['valid_until'])))
            || (!$data['ok'] && ($data['signer_sha256'] !== null || $data['valid_until'] !== null))) { throw new RuntimeException('Invalid TSA observation'); }
        return $data;
    }

    /** Explicit probe; never called while rendering a page or as a substitute for per-seal validation. */
    public function diagnose(string $id): array
    {
        $this->get($id);
        $result = ['checked_at' => time(), 'ok' => false, 'signer_sha256' => null, 'valid_until' => null];
        try {
            $provider = $this->provider($id);
            $asn1 = new PolicyOidAsn1($provider->policyOid());
            $verifier = new SignedDataVerifier($asn1, requireSigningCertificate: true, allowLegacyEssSha1: true);
            $client = new Client(new Config('https://timestamp.invalid/'), $asn1, verifier: $verifier);
            $request = $client->buildRequest(random_bytes(32));
            $token = $client->parseResponse($provider->respond($request->der, time()), $request, time());
            $der = $verifier->verify($token);
            $details = openssl_x509_parse(Certificate::derToPem($der));
            if (!is_int($details['validTo_time_t'] ?? null)) { throw new RuntimeException('Missing signer validity'); }
            $result['ok'] = true;
            $result['signer_sha256'] = hash('sha256', $der);
            $result['valid_until'] = $details['validTo_time_t'];
        } catch (Throwable) { /* Persist only a generic failure; remote text may contain secrets. */ }
        $result['checked_at'] = time();
        $this->mutate(function () use ($id, $result): void {
            $this->write('tsa_diagnostic_' . $id, $result);
            $this->audit('diagnostic', ['source_id' => $id, 'outcome' => $result['ok'] ? 'passed' : 'failed']);
        });
        return $result;
    }

    public function savePolicy(string $providerId, ?string $sourceId, bool $fallback, array $alternatives = []): void
    {
        $this->mutate(function () use ($providerId, $sourceId, $fallback, $alternatives): void {
            (new ProviderRepository($this->framework))->saveTimestampPolicy($providerId, $sourceId, $fallback, $alternatives);
            $this->audit('policy', ['provider_id' => $providerId, 'source_id' => $sourceId ?? 'none',
                'alternative_sources' => json_encode($alternatives, JSON_THROW_ON_ERROR),
                'bb_fallback' => $sourceId !== null && $fallback ? '1' : '0']);
        });
    }

    private function write(string $key, array $value): void { $this->framework->setSystemSetting($key, json_encode($value, JSON_THROW_ON_ERROR)); }
    private function audit(string $action, array $details): void
    {
        $id = $this->framework->log('timestamp_source_admin', ['project_id' => null, 'record' => '', 'action' => $action,
            'actor' => $this->framework->getUser()->getUsername()] + $details);
        if ((!is_int($id) && !ctype_digit((string) $id)) || (int) $id < 1) { throw new RuntimeException('Timestamp audit failed'); }
    }
    private function mutate(callable $work): void
    {
        (new PkiInitializationLock())->withLock(function () use ($work): void {
            if ($this->framework->query('START TRANSACTION', []) === false) { throw new RuntimeException('Transaction unavailable'); }
            try {
                $work();
                if ($this->framework->query('COMMIT', []) === false) { throw new RuntimeException('Commit failed'); }
            } catch (Throwable $e) { $this->framework->query('ROLLBACK', []); throw $e; }
        });
    }
}
