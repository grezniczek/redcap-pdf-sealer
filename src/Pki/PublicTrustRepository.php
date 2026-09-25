<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pki;

use Com\Tecnick\Pdf\Sign\Cms\Certificate;
use OpenSSLAsymmetricKey;
use RuntimeException;

/** Reads only public root certificate fields from system-scoped PKI records. */
final class PublicTrustRepository
{
    private const MESSAGE = 'pki_identity';

    public function __construct(
        private readonly PrimaryLogReader $reader,
        private readonly PrimarySystemSettingReader $settings,
    ) {}

    public function activeRootId(): ?string
    {
        $id = $this->settings->get('active_root_identity_id');
        if ($id === null || $id === '') {
            return null;
        }
        if (!is_string($id) || preg_match('/^[0-9a-f]{32}$/D', $id) !== 1) {
            throw new RuntimeException('Malformed active root pointer');
        }
        return $id;
    }

    /** @return list<array{id: string, der: string, fingerprint: string, subject: string, valid_from: int, valid_until: int}> */
    public function roots(): array
    {
        $result = $this->reader->query(
            'SELECT identity_id, certificate_der_b64, certificate_sha256 WHERE message = ? AND identity_role = ? AND ISNULL(project_id) ORDER BY log_id DESC',
            [self::MESSAGE, 'root'],
        );
        if ($result === false) {
            throw new RuntimeException('Public root query failed');
        }
        $roots = [];
        $seen = [];
        $certificate = new Certificate();
        while (($row = $result->fetch_assoc()) !== null) {
            $id = $row['identity_id'] ?? null;
            $encoded = $row['certificate_der_b64'] ?? null;
            $fingerprint = $row['certificate_sha256'] ?? null;
            if (!is_string($id) || preg_match('/^[0-9a-f]{32}$/D', $id) !== 1 || isset($seen[$id])
                || !is_string($encoded) || !is_string($fingerprint)
                || preg_match('/^[0-9a-f]{64}$/D', $fingerprint) !== 1) {
                throw new RuntimeException('Malformed public root record');
            }
            $der = base64_decode($encoded, true);
            if ($der === false || !hash_equals($fingerprint, hash('sha256', $der))) {
                throw new RuntimeException('Public root certificate digest mismatch');
            }
            $pem = Certificate::derToPem($der);
            $details = openssl_x509_parse($pem);
            $publicKey = openssl_pkey_get_public($pem);
            if (!is_array($details) || !is_string($details['name'] ?? null)
                || !is_int($details['validFrom_time_t'] ?? null)
                || !is_int($details['validTo_time_t'] ?? null)
                || !($publicKey instanceof OpenSSLAsymmetricKey)
                || !$certificate->isCertificateAuthority($der)
                || openssl_x509_verify($pem, $publicKey) !== 1) {
                throw new RuntimeException('Invalid public root certificate');
            }
            $seen[$id] = true;
            $roots[] = [
                'id' => $id,
                'der' => $der,
                'fingerprint' => $fingerprint,
                'subject' => $details['name'],
                'valid_from' => $details['validFrom_time_t'],
                'valid_until' => $details['validTo_time_t'],
            ];
        }
        return $roots;
    }
}
