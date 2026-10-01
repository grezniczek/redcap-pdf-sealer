<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Diagnostics;

/** Stores diagnostic time, fixed outcomes and public versions; never test material. */
final class DiagnosticSnapshot
{
    public const CHECKS = ['encryption', 'root', 'tsa', 'signer', 'bb', 'timestamp', 'bt'];
    private const SETTING = 'last-diagnostic-result';

    public function __construct(private readonly object $framework) {}

    public function load(): ?array
    {
        $stored = $this->framework->getSystemSetting(self::SETTING);
        if ($stored === null || $stored === '') { return null; }
        if (!is_string($stored)) { throw new \RuntimeException('Invalid diagnostic snapshot'); }
        return self::validate(json_decode($stored, true, 16, JSON_THROW_ON_ERROR));
    }

    public function save(array $result, int $completedAt): array
    {
        $snapshot = self::validate(['completed_at' => $completedAt,
            'passed' => $result['passed'] ?? null, 'checks' => $result['checks'] ?? null, 'versions' => $result['versions'] ?? null]);
        $this->framework->setSystemSetting(self::SETTING, json_encode($snapshot, JSON_THROW_ON_ERROR));
        return $snapshot;
    }

    /** Public configuration only; never decrypts keys or issues a certificate. */
    public static function currentVersions(\DE\RUB\PDFSealerExternalModule\Pki\IdentityRepository $identities): array
    {
        $source = $identities->providers()->source(\DE\RUB\PDFSealerExternalModule\Pki\ProviderRepository::BUILTIN_TSA);
        return ['root' => $identities->activeId('root'), 'tsa' => $source['identity_id'],
            'tsa_issuer' => $source['issuer_identity_id'], 'tsa_policy' => $source['policy_oid']];
    }

    public static function versionsChanged(?array $snapshot, array $current): bool
    {
        return $snapshot !== null && ($snapshot['versions'] ?? null) !== $current;
    }

    private static function versions(mixed $value): ?array
    {
        if ($value === null) { return null; } // Older results have no version evidence; rerun the diagnostic.
        if (!is_array($value)) { throw new \RuntimeException('Invalid diagnostic versions'); }
        $clean = [];
        foreach (['root', 'tsa', 'tsa_issuer'] as $key) {
            $id = $value[$key] ?? null;
            if ($id !== null && (!is_string($id) || preg_match('/^[0-9a-f]{32}$/D', $id) !== 1)) {
                throw new \RuntimeException('Invalid diagnostic identity version');
            }
            $clean[$key] = $id;
        }
        $policy = $value['tsa_policy'] ?? null;
        if ($policy !== null && (!is_string($policy) || strlen($policy) > 256 || preg_match('/^[0-9]+(?:\\.[0-9]+)+$/D', $policy) !== 1)) {
            throw new \RuntimeException('Invalid diagnostic policy version');
        }
        return $clean + ['tsa_policy' => $policy];
    }

    private static function validate(mixed $value): array
    {
        if (!is_array($value) || !is_int($value['completed_at'] ?? null)
            || $value['completed_at'] < 1 || $value['completed_at'] > 253402300799
            || !is_bool($value['passed'] ?? null) || !is_array($value['checks'] ?? null)) {
            throw new \RuntimeException('Invalid diagnostic snapshot');
        }
        $checks = [];
        foreach (self::CHECKS as $key) {
            $status = $value['checks'][$key] ?? null;
            if (!in_array($status, ['passed', 'failed', 'skipped'], true)) {
                throw new \RuntimeException('Invalid diagnostic check');
            }
            $checks[$key] = $status;
        }
        if ($value['passed'] !== (count(array_filter($checks, static fn(string $s): bool => $s !== 'passed')) === 0)) {
            throw new \RuntimeException('Inconsistent diagnostic snapshot');
        }
        return ['completed_at' => $value['completed_at'], 'passed' => $value['passed'], 'checks' => $checks,
            'versions' => self::versions($value['versions'] ?? null)];
    }
}
