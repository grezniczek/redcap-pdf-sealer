<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pki;

use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\Certificate;
use RuntimeException;

/** One immutable issuing-key block covers every same-key root version and its dependent identities. */
final readonly class RootRevocationRepository
{
    public const MESSAGE = 'root_certificate_revocation';

    public function __construct(private object $framework, private PrimaryLogReader $reader, private IdentityRepository $identities) {}

    public function find(string $der): ?array
    {
        $keyId = CrlIssuer::keyId($der);
        $result = $this->reader->query('SELECT log_id, identity_id, issuer_key_id, certificate_sha256, revoked_at, reason '
            . 'WHERE message = ? AND issuer_key_id = ? AND ISNULL(project_id) LIMIT 2', [self::MESSAGE, $keyId]);
        if ($result === false) { throw new RuntimeException('Root revocation read failed'); }
        $row = $result->fetch_assoc();
        if ($row === null) { return null; }
        if ($result->fetch_assoc() !== null) { throw new RuntimeException('Duplicate root revocation'); }
        $rootDer = $this->identities->publicCertificate($row['identity_id'] ?? '', 'root');
        self::validate($row, $rootDer);
        if (CrlIssuer::keyId($rootDer) !== $keyId) { throw new RuntimeException('Root revocation key mismatch'); }
        return $row;
    }

    public static function validate(array $row, string $rootDer): void
    {
        $keyId = CrlIssuer::keyId($rootDer);
        if (!is_string($row['identity_id'] ?? null)
            || preg_match('/^[0-9a-f]{32}$/D', $row['identity_id']) !== 1
            || ($row['issuer_key_id'] ?? null) !== $keyId
            || !ctype_digit((string) ($row['log_id'] ?? '')) || (int) $row['log_id'] < 1
            || !ctype_digit((string) ($row['revoked_at'] ?? '')) || (int) $row['revoked_at'] < 1
            || !in_array((string) ($row['reason'] ?? ''), ['2', '4'], true)) {
            throw new RuntimeException('Invalid root revocation');
        }
        if (($row['certificate_sha256'] ?? null) !== hash('sha256', $rootDer)) {
            throw new RuntimeException('Root revocation provenance mismatch');
        }
    }

    public function assertNotRevoked(string $der): void
    {
        if ($this->find($der) !== null) { throw new RootRevoked('Built-in issuing key revoked'); }
    }

    public function assertChain(array $chain): void
    {
        foreach ($chain as $der) { $this->assertNotRevoked($der); }
    }

    /** Caller holds the configuration lock and transaction. Public material alone is sufficient. */
    public function append(string $rootId, int $reason, int $now): void
    {
        $der = $this->identities->publicCertificate($rootId, 'root');
        if (!in_array($reason, [2, 4], true) || $now < 1 || $this->find($der) !== null) {
            throw new RuntimeException('Invalid root revocation request');
        }
        $values = ['project_id' => null, 'record' => '', 'identity_id' => $rootId,
            'issuer_key_id' => CrlIssuer::keyId($der), 'certificate_sha256' => hash('sha256', $der),
            'revoked_at' => (string) $now, 'reason' => (string) $reason,
            'actor' => $this->framework->getUser()->getUsername()];
        $id = $this->framework->log(self::MESSAGE, $values);
        if ((!is_int($id) && !ctype_digit((string) $id)) || (int) $id < 1 || $this->find($der) === null) {
            throw new RuntimeException('Root revocation audit failed');
        }
    }

    /** Complete list of stored leaves actually signed by this key, including historical/aliased identities. */
    public function dependents(string $rootDer): array
    {
        $certificate = new Certificate();
        $issuer = $certificate->fields($rootDer)['subject'];
        $key = openssl_pkey_get_public(Certificate::derToPem($rootDer));
        if ($key === false) { throw new RuntimeException('Root public key unavailable'); }
        $found = [];
        foreach (['project', 'tsa'] as $role) {
            foreach ($this->identities->publicCertificates($role) as $identity) {
                $der = $identity['der'];
                if ($certificate->fields($der)['issuer'] !== $issuer || openssl_x509_verify(Certificate::derToPem($der), $key) !== 1) { continue; }
                $details = openssl_x509_parse(Certificate::derToPem($der));
                $serial = strtolower(ltrim($details['serialNumberHex'] ?? '', '0'));
                if (preg_match('/^[1-9a-f][0-9a-f]{0,39}$/D', $serial) !== 1
                    || (isset($found[$serial]) && $found[$serial]['fingerprint'] !== $identity['fingerprint'])) {
                    throw new RuntimeException('Dependent certificate serial inconsistent');
                }
                $found[$serial] = $identity + ['serial_hex' => $serial, 'role' => $role];
            }
        }
        return array_values($found);
    }

    /** Earlier explicit leaf revocations keep their original reasons/times. The key-wide block remains authoritative locally. */
    public function merge(string $rootDer, array $previous): array
    {
        $block = $this->find($rootDer);
        if ($block === null) { return $previous; }
        $entries = [];
        foreach ($previous as $entry) { $entries[$entry['serial_hex']] = $entry; }
        foreach ($this->dependents($rootDer) as $leaf) {
            $entries[$leaf['serial_hex']] ??= ['serial_hex' => $leaf['serial_hex'],
                'revoked_at' => (int) $block['revoked_at'], 'reason' => (int) $block['reason']];
        }
        return array_values($entries);
    }
}
