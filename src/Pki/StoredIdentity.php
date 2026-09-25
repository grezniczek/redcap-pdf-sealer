<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pki;

use Com\Tecnick\Pdf\Sign\Cms\Certificate;
use OpenSSLAsymmetricKey;
use RuntimeException;

/** Public identity metadata plus an encrypted private key from a system log record. */
final readonly class StoredIdentity
{
    public function __construct(
        public string $id,
        public string $role,
        public string $certificateDer,
        private string $encryptedPrivateKey,
        public ?string $projectUuid,
    ) {}

    public function privateKey(SecretProtector $protector): OpenSSLAsymmetricKey
    {
        $pem = $protector->decrypt($this->encryptedPrivateKey);
        try {
            $key = openssl_pkey_get_private($pem);
            if (!$key instanceof OpenSSLAsymmetricKey
                || !openssl_x509_check_private_key(Certificate::derToPem($this->certificateDer), $key)) {
                throw new RuntimeException('Stored identity key does not match its certificate');
            }
            return $key;
        } finally {
            unset($pem);
        }
    }

    /** Rehydrate a validated root only for transient certificate issuance. */
    public function asGeneratedIdentity(SecretProtector $protector): GeneratedIdentity
    {
        $key = $this->privateKey($protector);
        if (!openssl_pkey_export($key, $pem)) {
            throw new RuntimeException('Stored identity key cannot be exported');
        }
        return new GeneratedIdentity($this->certificateDer, $pem);
    }

    public function __debugInfo(): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role,
            'certificate_sha256' => hash('sha256', $this->certificateDer),
            'project_uuid' => $this->projectUuid,
            'encrypted_private_key' => '[redacted]',
        ];
    }
}
