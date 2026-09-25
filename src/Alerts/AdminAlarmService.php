<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Alerts;

use Closure;
use RuntimeException;
use Throwable;

/** Persistent PKI alarms with one successful email per condition per hour. */
final class AdminAlarmService
{
    private const INTERVAL_SECONDS = 3600;
    private Closure $send;

    /** @param null|callable(string,string,string):bool $send Test override: recipients, subject, body. */
    public function __construct(
        private readonly object $framework,
        private readonly AlarmRepository $alarms,
        private readonly AlarmLock $lock,
        ?callable $send = null,
    ) {
        $this->send = $send === null
            ? static fn (string $to, string $subject, string $body): bool => \REDCap::email(
                $to, (string) ($GLOBALS['project_contact_email'] ?? ''), $subject, $body,
            ) === true
            : Closure::fromCallable($send);
    }

    /** Returns sent, throttled, failed, unconfigured, or invalid_recipients. */
    public function raise(string $code, string $severity, ?string $identityId = null, ?int $now = null): string
    {
        if (preg_match('/^[A-Z][A-Z0-9_]{1,63}$/D', $code) !== 1
            || !in_array($severity, ['critical', 'degraded'], true)
            || ($identityId !== null && preg_match('/^[0-9a-f]{32}$/D', $identityId) !== 1)) {
            throw new RuntimeException('Invalid PKI alarm condition');
        }
        $now ??= time();
        if ($now < 1) {
            throw new RuntimeException('Invalid alarm time');
        }
        $fingerprint = hash('sha256', $code . "\0" . ($identityId ?? ''));
        return $this->lock->withLock($fingerprint, function () use ($code, $severity, $identityId, $fingerprint, $now): string {
            $last = $this->alarms->lastMailedAt($fingerprint);
            $status = 'throttled';
            if ($last === null || $now - $last >= self::INTERVAL_SECONDS) {
                $recipients = $this->recipients();
                if ($recipients === null) {
                    $status = 'invalid_recipients';
                } elseif ($recipients === []) {
                    $status = 'unconfigured';
                } else {
                    $subject = 'REDCap PDF Sealer PKI alarm: ' . $code;
                    $body = 'PDF Sealer PKI alarm<br>Severity: ' . $severity
                        . '<br>Code: ' . $code
                        . '<br>Identity: ' . ($identityId ?? 'none')
                        . '<br>Time: ' . gmdate('c', $now) . '<br>';
                    try {
                        $status = ($this->send)(implode(',', $recipients), $subject, $body) === true ? 'sent' : 'failed';
                    } catch (Throwable $e) {
                        $status = 'failed';
                    }
                }
            }
            $this->alarms->append($code, $severity, $identityId, $fingerprint, $now, $status);
            return $status;
        });
    }

    /** @return list<string>|null Null means invalid configuration. */
    private function recipients(): ?array
    {
        $setting = $this->framework->getSystemSetting('admin-alert-recipients');
        if ($setting === null || $setting === '') {
            return [];
        }
        if (is_string($setting)) {
            $setting = [$setting];
        }
        if (!is_array($setting)) {
            return null;
        }
        $addresses = [];
        foreach ($setting as $value) {
            if (!is_string($value)) {
                return null;
            }
            foreach (preg_split('/[,;\s]+/', trim($value)) ?: [] as $address) {
                if ($address === '') {
                    continue;
                }
                if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                    return null;
                }
                $addresses[strtolower($address)] = $address;
            }
        }
        return array_values($addresses);
    }
}
