<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pki;

use Com\Tecnick\Pdf\Sign\Cms\Certificate;
use RuntimeException;

/** Append-only system-context identities; active root/TSA IDs live in system settings. */
final class IdentityRepository
{
    private const MESSAGE = 'pki_identity';
    private const ROLES = ['root', 'tsa', 'project'];

    /** @param \ExternalModules\Framework $framework */
    public function __construct(
        private readonly object $framework,
        private readonly SecretProtector $protector,
    ) {}

    public function append(string $role, GeneratedIdentity $identity, ?string $projectUuid = null): string
    {
        $this->assertRole($role);
        if (($role === 'project') !== ($projectUuid !== null)) {
            throw new RuntimeException('Project UUID is required only for project identities');
        }
        if ($projectUuid !== null && (!is_string($projectUuid) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $projectUuid) !== 1)) {
            throw new RuntimeException('Invalid project UUID');
        }
        $certificate = Certificate::derToPem($identity->certificateDer);
        if (!openssl_x509_check_private_key($certificate, $identity->privateKey())) {
            throw new RuntimeException('Identity key does not match certificate');
        }

        $id = bin2hex(random_bytes(16));
        $ciphertext = $this->protector->encrypt($identity->privateKeyPem());
        $logId = $this->framework->log(self::MESSAGE, [
            'project_id' => null,
            'record' => '',
            'identity_id' => $id,
            'identity_role' => $role,
            'project_uuid' => $projectUuid,
            'certificate_der_b64' => base64_encode($identity->certificateDer),
            'certificate_sha256' => hash('sha256', $identity->certificateDer),
            'private_key_ciphertext' => $ciphertext,
        ]);
        if ((!is_int($logId) && !ctype_digit((string) $logId)) || (int) $logId < 1) {
            throw new RuntimeException('Identity log insertion failed');
        }
        return $id;
    }

    public function find(string $id): ?StoredIdentity
    {
        if (preg_match('/^[0-9a-f]{32}$/D', $id) !== 1) {
            throw new RuntimeException('Invalid identity ID');
        }
        $result = $this->framework->queryLogs(
            'SELECT log_id, identity_id, identity_role, project_uuid, certificate_der_b64, certificate_sha256, private_key_ciphertext WHERE message = ? AND identity_id = ? AND ISNULL(project_id) ORDER BY log_id DESC LIMIT 2',
            [self::MESSAGE, $id],
        );
        if ($result === false) {
            throw new RuntimeException('Identity log query failed');
        }
        $row = $result->fetch_assoc();
        if ($row === null) {
            return null;
        }
        if ($result->fetch_assoc() !== null) {
            throw new RuntimeException('Duplicate identity ID');
        }
        if (($row['identity_id'] ?? null) !== $id || !in_array($row['identity_role'] ?? null, self::ROLES, true)
            || !is_string($row['certificate_der_b64'] ?? null)
            || !is_string($row['certificate_sha256'] ?? null)
            || preg_match('/^[0-9a-f]{64}$/D', $row['certificate_sha256']) !== 1
            || !is_string($row['private_key_ciphertext'] ?? null)
            || $row['private_key_ciphertext'] === '') {
            throw new RuntimeException('Malformed identity log record');
        }
        $der = base64_decode($row['certificate_der_b64'], true);
        if ($der === false || !hash_equals($row['certificate_sha256'], hash('sha256', $der))) {
            throw new RuntimeException('Identity certificate digest mismatch');
        }
        $projectUuid = $row['project_uuid'] ?? null;
        if (($row['identity_role'] === 'project') !== ($projectUuid !== null)
            || ($projectUuid !== null && (!is_string($projectUuid) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $projectUuid) !== 1))) {
            throw new RuntimeException('Identity role/binding mismatch');
        }
        return new StoredIdentity($id, $row['identity_role'], $der, $row['private_key_ciphertext'], $projectUuid);
    }

    public function hasRole(string $role): bool
    {
        $this->assertRole($role);
        $result = $this->framework->queryLogs(
            'SELECT log_id WHERE message = ? AND identity_role = ? AND ISNULL(project_id) LIMIT 1',
            [self::MESSAGE, $role],
        );
        if ($result === false) {
            throw new RuntimeException('Identity log query failed');
        }
        return $result->fetch_assoc() !== null;
    }

    public function activeId(string $role): ?string
    {
        $key = $this->activeSettingKey($role);
        $value = $this->framework->getSystemSetting($key);
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || preg_match('/^[0-9a-f]{32}$/D', $value) !== 1) {
            throw new RuntimeException('Malformed active identity setting');
        }
        return $value;
    }

    public function activate(string $role, string $id): void
    {
        $record = $this->find($id);
        if ($record === null || $record->role !== $role) {
            throw new RuntimeException('Active identity must reference a stored identity of the same role');
        }
        $this->framework->setSystemSetting($this->activeSettingKey($role), $id);
    }

    private function activeSettingKey(string $role): string
    {
        if ($role !== 'root' && $role !== 'tsa') {
            throw new RuntimeException('Only root and TSA have active system pointers');
        }
        return 'active_' . $role . '_identity_id';
    }

    private function assertRole(string $role): void
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new RuntimeException('Unknown PKI identity role');
        }
    }
}
