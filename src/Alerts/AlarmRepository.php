<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Alerts;

use DE\RUB\PDFSealerExternalModule\Pki\PrimaryLogReader;
use RuntimeException;

/** Append-only system-context alarm events and successful-mail lookup. */
final class AlarmRepository
{
    private const MESSAGE = 'pki_alarm';
    private PrimaryLogReader $reader;

    /** @param \ExternalModules\Framework $framework */
    public function __construct(private readonly object $framework, ?PrimaryLogReader $reader = null)
    {
        $this->reader = $reader ?? new PrimaryLogReader($framework);
    }

    public function lastMailedAt(string $fingerprint): ?int
    {
        $result = $this->reader->query(
            'SELECT alarm_epoch WHERE message = ? AND alarm_fingerprint = ? AND mail_status = ? AND ISNULL(project_id) ORDER BY log_id DESC LIMIT 1',
            [self::MESSAGE, $fingerprint, 'sent'],
        );
        if ($result === false) {
            throw new RuntimeException('Alarm history query failed');
        }
        $row = $result->fetch_assoc();
        if ($row === null) {
            return null;
        }
        $epoch = $row['alarm_epoch'] ?? null;
        if (!is_string($epoch) || !ctype_digit($epoch) || (int) $epoch < 1) {
            throw new RuntimeException('Malformed successful alarm record');
        }
        return (int) $epoch;
    }

    public function append(string $code, string $severity, ?string $identityId, string $fingerprint, int $now, string $mailStatus): void
    {
        $logId = $this->framework->log(self::MESSAGE, [
            'project_id' => null,
            'record' => '',
            'alarm_code' => $code,
            'alarm_severity' => $severity,
            'identity_id' => $identityId,
            'alarm_fingerprint' => $fingerprint,
            'alarm_epoch' => (string) $now,
            'mail_status' => $mailStatus,
        ]);
        if ((!is_int($logId) && !ctype_digit((string) $logId)) || (int) $logId < 1) {
            throw new RuntimeException('Alarm log insertion failed');
        }
    }
}
