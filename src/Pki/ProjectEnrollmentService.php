<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pki;

use RuntimeException;
use Throwable;

/** One durable pending enrollment per project, separate from active signing identities. */
final class ProjectEnrollmentService
{
    private PrimarySystemSettingReader $settings;
    private IdentityRepository $identities;

    public function __construct(
        private readonly object $framework,
        private readonly ProjectBindingRepository $bindings,
        private readonly ProviderRepository $providers,
        private readonly SecretProtector $protector,
        private readonly ProjectIssueLock $lock,
        ?PrimarySystemSettingReader $settings = null,
        ?IdentityRepository $identities = null,
    ) {
        $this->settings = $settings ?? new PrimarySystemSettingReader($framework);
        $this->identities = $identities ?? new IdentityRepository($framework, $protector, null, $this->settings);
    }

    /** Public-only metadata; viewing a page never generates or decrypts a key. */
    public function inspect(int $pid): ?array
    {
        $binding = $this->externalBinding($pid);
        $pending = $this->load($pid, $binding);
        return $pending === null ? null : $this->publicDetails($pending);
    }

    /** Repeated generation requests reuse the pending request instead of replacing its key. */
    public function generate(int $pid): array
    {
        return $this->lock->withLock($pid, function () use ($pid): array {
            $binding = $this->externalBinding($pid);
            $pending = $this->load($pid, $binding);
            if ($pending === null) {
                $path = $this->framework->createTempFile();
                try {
                    $config = "[req]\ndistinguished_name=subject\nprompt=no\nreq_extensions=request_ext\n[subject]\n[request_ext]\nbasicConstraints=critical,CA:false\nkeyUsage=critical,digitalSignature\nextendedKeyUsage=1.3.6.1.5.5.7.3.36\n";
                    if (file_put_contents($path, $config) !== strlen($config)) { throw new RuntimeException('CSR configuration write failed'); }
                    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 3072, 'config' => $path]);
                    if ($key === false) { throw new RuntimeException('Project key generation failed'); }
                    // No project title, PID, record, or participant information in the CSR.
                    $csr = openssl_csr_new(['commonName' => 'REDCap Project ' . $binding->uuid], $key,
                        ['config' => $path, 'digest_alg' => 'sha256']);
                    if ($csr === false || !openssl_csr_export($csr, $pem) || !openssl_pkey_export($key, $privatePem, null, ['config' => $path])) {
                        throw new RuntimeException('CSR generation failed');
                    }
                    $ciphertext = $this->protector->encrypt($privatePem);
                    $roundTrip = openssl_pkey_get_private($this->protector->decrypt($ciphertext));
                    $public = openssl_csr_get_public_key($pem);
                    if ($roundTrip === false || $public === false
                        || openssl_pkey_get_details($roundTrip)['key'] !== openssl_pkey_get_details($public)['key']) {
                        throw new RuntimeException('Pending key encryption verification failed');
                    }
                    unset($privatePem, $key, $roundTrip);
                    $pending = ['id' => bin2hex(random_bytes(16)), 'uuid' => $binding->uuid, 'provider_id' => $binding->providerId,
                        'created_at' => time(), 'csr_pem' => $pem, 'csr_sha256' => hash('sha256', $pem), 'private_key_ciphertext' => $ciphertext];
                    $this->transaction(function () use ($pid, $pending): void {
                        $this->framework->setSystemSetting($this->key($pid), json_encode($pending, JSON_THROW_ON_ERROR));
                        $this->audit('generate', $pid, $pending);
                    });
                } finally { if (is_string($path)) { @unlink($path); } }
            }
            return $this->downloadPayload($pending);
        });
    }

    public function download(int $pid, string $id): array
    {
        return $this->lock->withLock($pid, function () use ($pid, $id): array {
            return $this->downloadPayload($this->expected($pid, $id));
        });
    }

    public function cancel(int $pid, string $id): void
    {
        $this->lock->withLock($pid, function () use ($pid, $id): void {
            $pending = $this->expected($pid, $id);
            $this->transaction(function () use ($pid, $pending): void {
                $this->framework->removeSystemSetting($this->key($pid));
                $this->audit('cancel', $pid, $pending);
            });
        });
    }

    /** Validate without persisting the uploaded certificate or changing the active signer. */
    public function reviewCertificate(int $pid, string $id, string $pem): array
    {
        return $this->lock->withLock($pid, function () use ($pid, $id, $pem): array {
            $candidate = $this->candidate($pid, $id, $pem);
            return ['ok' => true, 'certificate' => $candidate['details'],
                'active_identity_id' => $this->externalBinding($pid)->identityId, 'review_hash' => $candidate['review_hash']];
        });
    }

    public function activateCertificate(int $pid, string $id, string $pem, string $reviewHash, ?string $expectedActiveId): void
    {
        $this->lock->withLock($pid, function () use ($pid, $id, $pem, $reviewHash, $expectedActiveId): void {
            $binding = $this->externalBinding($pid);
            if ($binding->identityId !== $expectedActiveId) { throw new RuntimeException('Active signer changed; review again'); }
            $candidate = $this->candidate($pid, $id, $pem);
            if (!hash_equals($candidate['review_hash'], $reviewHash)) { throw new RuntimeException('Certificate or chain changed; review again'); }
            $pending = $candidate['pending'];
            $generated = new GeneratedIdentity($candidate['der'], $this->protector->decrypt($pending['private_key_ciphertext']));
            $this->transaction(function () use ($pid, $binding, $candidate, $pending, $generated): void {
                $identityId = $this->identities->append('project', $generated, $binding->uuid, $binding->providerId,
                    hash('sha256', $candidate['chain'][0]), $candidate['chain']);
                $this->bindings->replace($pid, $binding->uuid, $binding->identityId, $identityId);
                $this->framework->removeSystemSetting($this->key($pid));
                $this->audit('activate', $pid, $pending, ['identity_id' => $identityId,
                    'previous_identity_id' => $binding->identityId, 'certificate_sha256' => $candidate['details']['fingerprint']]);
            });
        });
    }

    private function candidate(int $pid, string $id, string $pem): array
    {
        $pending = $this->expected($pid, $id);
        $provider = $this->providers->provider($pending['provider_id']);
        $chain = array_map([ProviderRepository::class, 'certificateDer'], $provider['chain']);
        $validator = $this->identities->externalValidator();
        $der = $validator->parseUpload($pem);
        $details = $validator->validate($der, $chain);
        $certPem = \DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\Certificate::derToPem($der);
        $private = $this->protector->decrypt($pending['private_key_ciphertext']);
        try {
            if (!openssl_x509_check_private_key($certPem, $private)
                || openssl_pkey_get_details(openssl_pkey_get_public($certPem))['key']
                    !== openssl_pkey_get_details(openssl_csr_get_public_key($pending['csr_pem']))['key']) {
                throw new RuntimeException('Certificate does not match pending CSR/key');
            }
        } finally { unset($private); }
        return ['der' => $der, 'chain' => $chain, 'pending' => $pending, 'details' => $details,
            'review_hash' => hash('sha256', $der . implode('', $chain))];
    }

    private function externalBinding(int $pid): ProjectBinding
    {
        ProjectBindingRepository::assertPid($pid);
        $binding = $this->bindings->find($pid);
        if ($binding === null || $this->providers->provider($binding->providerId)['kind'] !== 'external') {
            throw new RuntimeException('External CA assignment required for enrollment');
        }
        return $binding;
    }

    private function expected(int $pid, string $id): array
    {
        $pending = $this->load($pid, $this->externalBinding($pid));
        if ($pending === null || !hash_equals($pending['id'], $id)) { throw new RuntimeException('Pending enrollment changed; refresh the page'); }
        return $pending;
    }

    private function load(int $pid, ProjectBinding $binding): ?array
    {
        $raw = $this->settings->get($this->key($pid));
        if ($raw === null) { return null; }
        $p = is_string($raw) ? json_decode($raw, true, 8, JSON_THROW_ON_ERROR) : null;
        if (!is_array($p) || count($p) !== 7 || !is_string($p['id'] ?? null) || preg_match('/^[a-f0-9]{32}$/D', $p['id']) !== 1
            || ($p['uuid'] ?? null) !== $binding->uuid || ($p['provider_id'] ?? null) !== $binding->providerId
            || !is_int($p['created_at'] ?? null) || $p['created_at'] < 1
            || !is_string($p['csr_pem'] ?? null) || strlen($p['csr_pem']) > 16384
            || !is_string($p['csr_sha256'] ?? null) || !hash_equals(hash('sha256', $p['csr_pem']), $p['csr_sha256'])
            || !is_string($p['private_key_ciphertext'] ?? null) || !str_starts_with($p['private_key_ciphertext'], 'redcap-v1:')) {
            throw new RuntimeException('Invalid pending enrollment');
        }
        $subject = @openssl_csr_get_subject($p['csr_pem']);
        $key = @openssl_csr_get_public_key($p['csr_pem']);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if (!is_array($subject) || $subject !== ['CN' => 'REDCap Project ' . $binding->uuid]
            || !is_array($details) || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] !== 3072) {
            throw new RuntimeException('Invalid pending CSR');
        }
        return $p;
    }

    private function publicDetails(array $p): array
    {
        return ['id' => $p['id'], 'created_at' => $p['created_at'], 'csr_sha256' => $p['csr_sha256'], 'subject' => '/CN=REDCap Project ' . $p['uuid']];
    }

    private function downloadPayload(array $p): array
    {
        return ['ok' => true, 'pending' => $this->publicDetails($p), 'filename' => 'pdf-sealer-' . $p['id'] . '.csr',
            'content_type' => 'application/pkcs10', 'base64' => base64_encode($p['csr_pem'])];
    }

    private function key(int $pid): string { return 'pending_enrollment_' . $pid; }

    private function audit(string $action, int $pid, array $p, array $details = []): void
    {
        $id = $this->framework->log('project_enrollment', ['project_id' => null, 'record' => '', 'action' => $action,
            'redcap_pid' => (string) $pid, 'project_uuid' => $p['uuid'], 'provider_id' => $p['provider_id'],
            'enrollment_id' => $p['id'], 'csr_sha256' => $p['csr_sha256'], 'actor' => $this->framework->getUser()->getUsername()] + $details);
        if ((!is_int($id) && !ctype_digit((string) $id)) || (int) $id < 1) { throw new RuntimeException('Enrollment audit failed'); }
    }

    private function transaction(callable $work): void
    {
        if ($this->framework->query('START TRANSACTION', []) === false) { throw new RuntimeException('Transaction unavailable'); }
        try {
            $work();
            if ($this->framework->query('COMMIT', []) === false) { throw new RuntimeException('Commit failed'); }
        } catch (Throwable $e) { $this->framework->query('ROLLBACK', []); throw $e; }
    }
}
