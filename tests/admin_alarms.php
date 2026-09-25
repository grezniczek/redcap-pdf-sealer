<?php

declare(strict_types=1);

use DE\RUB\PDFSealerExternalModule\Alerts\AdminAlarmService;
use DE\RUB\PDFSealerExternalModule\Alerts\AlarmLock;
use DE\RUB\PDFSealerExternalModule\Alerts\AlarmRepository;
use DE\RUB\PDFSealerExternalModule\Pki\PrimaryLogReader;

require dirname(__DIR__) . '/vendor/autoload.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class FakeResult
{
    public function __construct(private array $rows) {}
    public function fetch_assoc(): ?array { return array_shift($this->rows); }
    public function fetch_row(): ?array { return array_shift($this->rows); }
}

final class FakeFramework
{
    public array $logs = [];
    public array $settings = [];

    public function getSystemSetting(string $key): mixed { return $this->settings[$key] ?? null; }

    public function log(string $message, array $parameters): int
    {
        check($message === 'pki_alarm', 'Unexpected log message');
        check(array_key_exists('project_id', $parameters) && $parameters['project_id'] === null,
            'Alarm was not system scoped');
        check(($parameters['record'] ?? null) === '', 'Alarm retained a clinical record ID');
        $id = count($this->logs) + 1;
        $this->logs[] = ['log_id' => $id, 'message' => $message] + array_filter(
            $parameters, static fn (mixed $value): bool => $value !== null,
        );
        return $id;
    }

    public function queryLogs(string $sql, array $params): FakeResult
    {
        check(str_contains($sql, 'ISNULL(project_id)'), 'Alarm history query was not system scoped');
        $rows = array_values(array_filter($this->logs, static fn (array $row): bool =>
            $row['message'] === $params[0]
            && $row['alarm_fingerprint'] === $params[1]
            && $row['mail_status'] === $params[2]
        ));
        return new FakeResult(array_slice(array_reverse($rows), 0, 1));
    }
}

$framework = new FakeFramework();
$reader = new PrimaryLogReader($framework, [$framework, 'queryLogs']);
$repository = new AlarmRepository($framework, $reader);
$held = false;
$lock = new AlarmLock(static function (string $sql, array $params) use (&$held): FakeResult {
    check(str_starts_with($params[0], 'pdf_sealer_alarm_') && strlen($params[0]) <= 64,
        'Alarm lock name is invalid');
    if (str_contains($sql, 'GET_LOCK')) {
        if ($held) { return new FakeResult([[0]]); }
        $held = true;
        return new FakeResult([[1]]);
    }
    check($held, 'Alarm lock released without acquisition');
    $held = false;
    return new FakeResult([[1]]);
});
$sent = [];
$sender = static function (string $to, string $subject, string $body) use (&$sent): bool {
    $sent[] = compact('to', 'subject', 'body');
    return true;
};
$service = new AdminAlarmService($framework, $repository, $lock, $sender);
$id = str_repeat('a', 32);
$base = 1_700_000_000;

check($service->raise('ROOT_KEY_DECRYPT_FAILED', 'critical', $id, $base) === 'unconfigured',
    'Unconfigured alarm was not recorded');
check(count($sent) === 0, 'Unconfigured alarm sent mail');
$framework->settings['admin-alert-recipients'] = ['admin@example.org', 'admin@example.org'];
check($service->raise('ROOT_KEY_DECRYPT_FAILED', 'critical', $id, $base + 1) === 'sent',
    'First configured alarm was not sent');
check($sent[0]['to'] === 'admin@example.org' && str_contains($sent[0]['body'], $id),
    'Alarm mail omitted expected metadata or duplicated recipients');
check($service->raise('ROOT_KEY_DECRYPT_FAILED', 'critical', $id, $base + 3599) === 'throttled',
    'Alarm was resent within the hour');
check(count($sent) === 1 && count($framework->logs) === 3,
    'Throttled occurrence was not persisted without mail');
check($service->raise('ROOT_KEY_DECRYPT_FAILED', 'critical', $id, $base + 3601) === 'sent',
    'Alarm was not resent after an hour');
check(count($sent) === 2, 'Wrong mail count after throttle expired');
check($service->raise('ROOT_KEY_DECRYPT_FAILED', 'critical', str_repeat('b', 32), $base + 3602) === 'sent',
    'Distinct identity was incorrectly throttled');

$failedSender = static fn (): bool => false;
$failedService = new AdminAlarmService($framework, $repository, $lock, $failedSender);
check($failedService->raise('TSA_KEY_DECRYPT_FAILED', 'degraded', $id, $base) === 'failed',
    'Failed email was not recorded');
check($service->raise('TSA_KEY_DECRYPT_FAILED', 'degraded', $id, $base + 1) === 'sent',
    'Failed email suppressed a retry');
$framework->settings['admin-alert-recipients'] = ['invalid address'];
check($service->raise('PROJECT_KEY_MISMATCH', 'critical', $id, $base) === 'invalid_recipients',
    'Invalid recipient configuration was accepted');
$framework->settings['admin-alert-recipients'] = ['admin@example.org'];
check($service->raise('PROJECT_KEY_MISMATCH', 'critical', $id, $base + 1) === 'sent',
    'Invalid recipient configuration suppressed a later send');
check(!$held, 'Alarm lock remained held');

try {
    $lock->withLock(str_repeat('c', 64), static fn (): bool =>
        $lock->withLock(str_repeat('c', 64), static fn (): bool => true));
    throw new RuntimeException('Concurrent alarm lock was acquired');
} catch (RuntimeException $e) {
    check($e->getMessage() === 'Alarm lock unavailable', 'Unexpected concurrent lock failure');
}
check(!$held, 'Alarm lock was not released after failure');

echo "Admin alarms: persistence, hourly throttling, retries, recipients, and locking passed.\n";
