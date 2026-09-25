<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pki;

use OpenSSLAsymmetricKey;
use RuntimeException;

/** Transient identity; storage must encrypt privateKeyPem before persistence. */
final readonly class GeneratedIdentity
{
    public function __construct(
        public string $certificateDer,
        private string $privateKeyPem,
    ) {}

    public function privateKeyPem(): string
    {
        return $this->privateKeyPem;
    }

    public function privateKey(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_get_private($this->privateKeyPem);
        if ($key === false) {
            throw new RuntimeException('Identity private key cannot be loaded');
        }
        return $key;
    }

    public function __debugInfo(): array
    {
        return ['certificate_sha256' => hash('sha256', $this->certificateDer), 'private_key' => '[redacted]'];
    }

    public function __serialize(): array
    {
        throw new RuntimeException('A private-key identity must not be serialized');
    }
}
