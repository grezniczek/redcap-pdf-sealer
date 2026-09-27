<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pki;

use DE\RUB\PDFSealerExternalModule\Alerts\AdminAlarmService;
use DE\RUB\PDFSealerExternalModule\Alerts\AlarmLock;
use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\Certificate;
use RuntimeException;
use Throwable;

/** Scheduled public-certificate date checks. No issuance, renewal, or key decryption. */
final class ExpiryMonitor
{
    public const SETTING = 'last-expiry-scan';
    public const BANDS = ['invalid', 'expired', '7d', '30d', '90d', 'healthy'];

    public function __construct(
        private readonly object $framework,
        private readonly ExpiryInventory $inventory,
        private readonly AdminAlarmService $alarms,
        private readonly AlarmLock $lock,
    ) {}

    public function run(?int $now = null): array
    {
        $now ??= time();
        if ($now < 1) { throw new RuntimeException('Invalid expiry scan time'); }
        return $this->lock->withLock(hash('sha256', 'expiry-monitor'), function () use ($now): array {
            try {
                $snapshot = self::evaluate($this->inventory->collect(), $now);
            } catch (Throwable) {
                $snapshot = self::evaluate([], $now);
                $snapshot['status'] = 'failed';
            }
            // Preserve the scan result even when delivery or alarm persistence fails.
            $this->save($snapshot);
            try {
                $snapshot['mail_status'] = $snapshot['status'] === 'failed'
                    ? $this->alarms->raiseExpiryScanFailure($now)
                    : $this->alarms->raiseExpirySummary($snapshot['counts'], $now);
            } catch (Throwable) {
                $snapshot['mail_status'] = 'failed';
            }
            $this->save($snapshot);
            return $snapshot;
        });
    }

    public static function evaluate(array $inventory, int $now): array
    {
        $counts = array_fill_keys(self::BANDS, 0);
        $items = [];
        $nearest = null;
        foreach ($inventory as $id => $item) {
            $expires = null;
            $band = 'invalid';
            $parsed = $item['der'] === null ? false : @openssl_x509_parse(Certificate::derToPem($item['der']));
            if (is_array($parsed) && is_int($parsed['validTo_time_t'] ?? null) && is_int($parsed['validFrom_time_t'] ?? null)) {
                $expires = $parsed['validTo_time_t'];
                $nearest = $nearest === null ? $expires : min($nearest, $expires);
                $band = $parsed['validFrom_time_t'] > $now ? 'invalid' : self::band($expires, $now);
            }
            $counts[$band]++;
            if ($band !== 'healthy') {
                $items[] = ['id' => $id, 'role' => $item['role'], 'pid' => $item['pid'], 'expires' => $expires, 'band' => $band];
            }
        }
        usort($items, static fn(array $a, array $b): int =>
            [array_search($a['band'], self::BANDS, true), $a['expires'] ?? 0, $a['id']]
            <=> [array_search($b['band'], self::BANDS, true), $b['expires'] ?? 0, $b['id']]);
        return ['completed_at' => $now, 'status' => $inventory === [] ? 'uninitialized' : 'ok',
            'counts' => $counts, 'nearest_expiry' => $nearest, 'items' => array_slice($items, 0, 50), 'mail_status' => 'not_needed'];
    }

    public static function band(int $expires, int $now): string
    {
        $remaining = $expires - $now;
        return match (true) {
            $remaining < 0 => 'expired',
            $remaining <= 7 * 86400 => '7d',
            $remaining <= 30 * 86400 => '30d',
            $remaining <= 90 * 86400 => '90d',
            default => 'healthy',
        };
    }

    public static function load(object $framework): ?array
    {
        $raw = $framework->getSystemSetting(self::SETTING);
        if ($raw === null) { return null; }
        if (!is_string($raw)) { throw new RuntimeException('Invalid expiry snapshot'); }
        return self::validate(json_decode($raw, true, 16, JSON_THROW_ON_ERROR));
    }

    private function save(array $snapshot): void
    {
        $this->framework->setSystemSetting(self::SETTING, json_encode(self::validate($snapshot), JSON_THROW_ON_ERROR));
    }

    private static function validate(mixed $value): array
    {
        if (!is_array($value) || !is_int($value['completed_at'] ?? null) || $value['completed_at'] < 1
            || !in_array($value['status'] ?? null, ['ok', 'uninitialized', 'failed'], true)
            || !is_array($value['counts'] ?? null) || !is_array($value['items'] ?? null) || count($value['items']) > 50
            || !array_key_exists('nearest_expiry', $value) || ($value['nearest_expiry'] !== null && !is_int($value['nearest_expiry']))
            || !in_array($value['mail_status'] ?? null, ['not_needed', 'sent', 'throttled', 'unconfigured', 'invalid_recipients', 'failed'], true)) {
            throw new RuntimeException('Invalid expiry snapshot');
        }
        foreach (self::BANDS as $band) {
            if (!is_int($value['counts'][$band] ?? null) || $value['counts'][$band] < 0) { throw new RuntimeException('Invalid expiry count'); }
        }
        foreach ($value['items'] as $item) {
            if (!is_array($item) || !is_string($item['id'] ?? null) || preg_match('/^(?:[a-f0-9]{32}|[a-f0-9]{64})$/D', $item['id']) !== 1
                || !in_array($item['role'] ?? null, ['root', 'tsa', 'project', 'ca'], true)
                || !array_key_exists('pid', $item) || ($item['pid'] !== null && (!is_int($item['pid']) || $item['pid'] < 1))
                || !array_key_exists('expires', $item) || ($item['expires'] !== null && !is_int($item['expires']))
                || !in_array($item['band'] ?? null, self::BANDS, true)) { throw new RuntimeException('Invalid expiry item'); }
        }
        return $value;
    }
}
