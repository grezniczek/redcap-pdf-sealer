<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Diagnostics;

/** Stores only the latest diagnostic time and fixed check outcomes, never test material. */
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
            'passed' => $result['passed'] ?? null, 'checks' => $result['checks'] ?? null]);
        $this->framework->setSystemSetting(self::SETTING, json_encode($snapshot, JSON_THROW_ON_ERROR));
        return $snapshot;
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
        return ['completed_at' => $value['completed_at'], 'passed' => $value['passed'], 'checks' => $checks];
    }
}
