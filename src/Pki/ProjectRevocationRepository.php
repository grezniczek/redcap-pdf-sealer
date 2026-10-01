<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pki;

use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\Certificate;
use RuntimeException;

/** Immutable public revocation audit is also the durable local block and CRL publication intent. */
final class ProjectRevocationRepository
{
    public const MESSAGE = 'project_certificate_revocation';
    private PrimaryLogReader $reader;
    private IdentityRepository $identities;

    public function __construct(private readonly object $framework, ?PrimaryLogReader $reader = null, ?IdentityRepository $identities = null)
    {
        $this->reader = $reader ?? new PrimaryLogReader($framework);
        $this->identities = $identities ?? new IdentityRepository($framework, new SecretProtector(), $this->reader);
    }

    public function find(StoredIdentity $identity): ?array
    {
        $result = $this->reader->query(
            'SELECT log_id, identity_id, issuer_identity_id, issuer_key_id, certificate_sha256, serial_hex, revoked_at, reason '
            . 'WHERE message = ? AND identity_id = ? AND ISNULL(project_id) LIMIT 2',
            [self::MESSAGE, $identity->id]);
        if ($result === false) { throw new RuntimeException('Revocation read failed'); }
        $row = $result->fetch_assoc();
        if ($row === null) { return null; }
        $this->validate($row);
        if ($result->fetch_assoc() !== null || $row['issuer_identity_id'] !== $identity->issuerId
            || $row['issuer_key_id'] !== CrlIssuer::keyId($this->identities->publicCertificate($identity->issuerId, 'root'))) {
            throw new RuntimeException('Revocation identity mismatch');
        }
        $this->assertCertificate($row, $identity->certificateDer);
        return $row;
    }

    public function assertNotRevoked(StoredIdentity $identity): void
    {
        if ($this->find($identity) !== null) { throw new ProjectRevoked('Project certificate revoked'); }
    }

    /** Caller holds project/configuration locks and a transaction. No private key is needed to block. */
    public function append(int $pid, StoredIdentity $identity, string $rootDer, int $reason, int $now): void
    {
        if (!in_array($reason, [1, 4], true) || $now < 1 || $identity->role !== 'project'
            || $identity->providerId !== ProviderRepository::BUILTIN_CA || $identity->issuerChain !== []
            || $identity->issuerId === null || $this->find($identity) !== null) {
            throw new RuntimeException('Invalid project revocation');
        }
        $details = openssl_x509_parse(Certificate::derToPem($identity->certificateDer));
        $serial = strtolower(ltrim($details['serialNumberHex'] ?? '', '0'));
        $values = [
            'project_id' => null, 'record' => '', 'redcap_pid' => (string) $pid,
            'project_uuid' => $identity->projectUuid, 'provider_id' => $identity->providerId,
            'identity_id' => $identity->id, 'issuer_identity_id' => $identity->issuerId,
            'issuer_key_id' => CrlIssuer::keyId($rootDer), 'certificate_sha256' => hash('sha256', $identity->certificateDer),
            'serial_hex' => $serial, 'revoked_at' => (string) $now, 'reason' => (string) $reason,
            'actor' => $this->framework->getUser()->getUsername(),
        ];
        $this->validate(['log_id' => 1] + $values);
        $id = $this->framework->log(self::MESSAGE, $values);
        if ((!is_int($id) && !ctype_digit((string) $id)) || (int) $id < 1 || $this->find($identity) === null) {
            throw new RuntimeException('Revocation audit failed');
        }
    }

    /** Preserve already published entries; add immutable local blocks without changing earlier reasons/times. */
    public function merge(string $rootDer, array $previous): array
    {
        $result = $this->reader->query(
            'SELECT log_id, identity_id, issuer_identity_id, issuer_key_id, certificate_sha256, serial_hex, revoked_at, reason '
            . 'WHERE message = ? AND issuer_key_id = ? AND ISNULL(project_id) ORDER BY log_id',
            [self::MESSAGE, CrlIssuer::keyId($rootDer)]);
        if ($result === false) { throw new RuntimeException('Revocation publication read failed'); }
        $entries = [];
        foreach ($previous as $entry) { $entries[$entry['serial_hex']] = $entry; }
        $seen = []; $serials = [];
        $rootFields = (new Certificate())->fields($rootDer);
        $rootKey = openssl_pkey_get_public(Certificate::derToPem($rootDer));
        while ($row = $result->fetch_assoc()) {
            $this->validate($row);
            if ($row['issuer_key_id'] !== CrlIssuer::keyId($rootDer) || isset($seen[$row['identity_id']]) || isset($serials[$row['serial_hex']])) {
                throw new RuntimeException('Revocation ledger inconsistent');
            }
            $seen[$row['identity_id']] = true; $serials[$row['serial_hex']] = true;
            $der = $this->identities->publicCertificate($row['identity_id'], 'project');
            $this->assertCertificate($row, $der);
            if ($rootKey === false || (new Certificate())->fields($der)['issuer'] !== $rootFields['subject']
                || openssl_x509_verify(Certificate::derToPem($der), $rootKey) !== 1) {
                throw new RuntimeException('Revocation issuer provenance invalid');
            }
            $entry = ['serial_hex' => $row['serial_hex'], 'revoked_at' => (int) $row['revoked_at'], 'reason' => (int) $row['reason']];
            if (isset($entries[$row['serial_hex']]) && $entries[$row['serial_hex']] !== $entry) {
                throw new RuntimeException('Published revocation conflicts with local ledger');
            }
            $entries[$row['serial_hex']] = $entry;
        }
        return array_values($entries);
    }

    private function assertCertificate(array $row, string $der): void
    {
        $details = openssl_x509_parse(Certificate::derToPem($der));
        if ($row['certificate_sha256'] !== hash('sha256', $der)
            || $row['serial_hex'] !== strtolower(ltrim($details['serialNumberHex'] ?? '', '0'))) {
            throw new RuntimeException('Revocation certificate mismatch');
        }
    }

    private function validate(array $row): void
    {
        foreach (['identity_id', 'issuer_identity_id'] as $key) {
            if (!is_string($row[$key] ?? null) || preg_match('/^[0-9a-f]{32}$/D', $row[$key]) !== 1) {
                throw new RuntimeException('Invalid revocation identity');
            }
        }
        foreach (['issuer_key_id', 'certificate_sha256'] as $key) {
            if (!is_string($row[$key] ?? null) || preg_match('/^[0-9a-f]{64}$/D', $row[$key]) !== 1) {
                throw new RuntimeException('Invalid revocation hash');
            }
        }
        if (!ctype_digit((string) ($row['log_id'] ?? '')) || (int) $row['log_id'] < 1
            || !is_string($row['serial_hex'] ?? null) || preg_match('/^[1-9a-f][0-9a-f]{0,39}$/D', $row['serial_hex']) !== 1
            || !ctype_digit((string) ($row['revoked_at'] ?? '')) || (int) $row['revoked_at'] < 1
            || !in_array((string) ($row['reason'] ?? ''), ['1', '4'], true)) {
            throw new RuntimeException('Invalid revocation entry');
        }
    }
}
