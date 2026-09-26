<?php

declare(strict_types=1);

namespace ExternalModules {
    class AbstractExternalModule
    {
        public object $framework;
    }
}

namespace {
    use DE\RUB\PDFSealerExternalModule\PDFSealerExternalModule;
    use DE\RUB\PDFSealerExternalModule\Timestamp\TimestampSettings;

    require dirname(__DIR__) . '/PDFSealerExternalModule.php';

    function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    final class FakeFramework
    {
        public bool $superuser = true;
        public ?int $projectId = null;
        public bool $failWrite = false;
        public array $settings = [];
        public ?string $failKey = null;
        public ?string $failQuery = null;
        public ?array $transaction = null;
        public array $queries = [];

        public function query(string $sql, array $params): bool
        {
            check($params === [], 'Unexpected transaction parameters');
            $this->queries[] = $sql;
            if ($this->failQuery === $sql) { return false; }
            if ($sql === 'START TRANSACTION') {
                check($this->transaction === null, 'Nested settings transaction');
                $this->transaction = $this->settings;
            } elseif ($sql === 'COMMIT') {
                check($this->transaction !== null, 'Commit without transaction');
                $this->transaction = null;
            } elseif ($sql === 'ROLLBACK') {
                check($this->transaction !== null, 'Rollback without transaction');
                $this->settings = $this->transaction;
                $this->transaction = null;
            } else {
                throw new \RuntimeException('Unexpected query');
            }
            return true;
        }

        public function isSuperUser(): bool { return $this->superuser; }
        public function getProjectId(): ?int { return $this->projectId; }
        public function tt(string $key): string { return $key; }
        public function setSystemSetting(string $key, mixed $value): void
        {
            if ($this->failWrite || $this->failKey === $key) {
                throw new \RuntimeException('Storage unavailable');
            }
            $this->settings[$key] = $value;
        }
    }

    $framework = new FakeFramework();
    $module = new PDFSealerExternalModule();
    $module->framework = $framework;

    $result = $module->redcap_module_ajax('save_alert_recipients', "a@example.org; A@example.org\nb@example.org", null);
    check($result === ['ok' => true, 'recipients' => 'A@example.org, b@example.org'], 'Valid recipients were not saved');
    check($framework->settings['admin-alert-recipients'] === ['A@example.org', 'b@example.org'], 'Stored recipients differ');

    $result = $module->redcap_module_ajax('save_alert_recipients', 'bad address', null);
    check($result['ok'] === false && $framework->settings['admin-alert-recipients'] === ['A@example.org', 'b@example.org'],
        'Invalid addresses changed the setting');
    $result = $module->redcap_module_ajax('save_alert_recipients', '', null);
    check($result['ok'] === true && $framework->settings['admin-alert-recipients'] === [],
        'Empty input did not disable alarm email');

    $result = $module->redcap_module_ajax('download_root_certificate', 'unknown', null);
    check($result === ['ok' => false, 'message' => 'pki_invalid_request'],
        'Unsupported certificate format was accepted');

    foreach (['pdf bytes', [], ['project_id' => 461]] as $payload) {
        check($module->redcap_module_ajax('run_diagnostic', $payload, null)
            === ['ok' => false, 'message' => 'pki_invalid_request'], 'Diagnostic accepted caller-supplied data');
    }
    $config = json_decode(file_get_contents(dirname(__DIR__) . '/config.json'), true, flags: JSON_THROW_ON_ERROR);
    check(in_array('run_diagnostic', $config['auth-ajax-actions'], true)
        && !in_array('run_diagnostic', $config['no-auth-ajax-actions'], true), 'Diagnostic AJAX authentication configuration is wrong');

    $defaults = TimestampSettings::fromStored(null, null);
    check($defaults->mode === 'internal' && $defaults->fallback, 'Default timestamp behavior changed');
    foreach (['', '1', 'true'] as $value) {
        check(TimestampSettings::fromStored('internal', $value)->fallback, 'Legacy enabled fallback changed');
    }
    foreach (['0', 'false'] as $value) {
        check(!TimestampSettings::fromStored('none', $value)->fallback, 'Legacy disabled fallback changed');
    }
    foreach ([['external', '1'], ['', '1'], ['internal', true], ['internal', 'bad']] as [$mode, $fallback]) {
        try {
            TimestampSettings::fromStored($mode, $fallback);
            throw new \LogicException('Invalid stored timestamp setting accepted');
        } catch (\RuntimeException) {}
    }
    foreach (['internal', 'none'] as $mode) {
        foreach ([false, true] as $fallback) {
            $payload = ['timestamp_mode' => $mode, 'bb_fallback' => $fallback];
            $result = $module->redcap_module_ajax('save_timestamp_settings', $payload, null);
            check($result === ['ok' => true] + $payload, 'Timestamp choices not returned');
            check($framework->settings['timestamp_mode'] === $mode
                && $framework->settings['bb_fallback'] === ($fallback ? '1' : '0'), 'Wrong stored setting types');
            $parsed = TimestampSettings::fromStored($framework->settings['timestamp_mode'], $framework->settings['bb_fallback']);
            check($parsed->mode === $mode && $parsed->fallback === $fallback, 'Saved settings differ from sealing interpretation');
            check($framework->transaction === null, 'Settings transaction left open');
        }
    }
    $before = [$framework->settings, $framework->queries];
    foreach ([null, '', [], ['timestamp_mode' => 'internal'],
        ['timestamp_mode' => 'external', 'bb_fallback' => true],
        ['timestamp_mode' => 'internal', 'bb_fallback' => '0'],
        ['timestamp_mode' => 'none', 'bb_fallback' => false, 'tsa_policy_oid' => '1.2.3']] as $payload) {
        $result = $module->redcap_module_ajax('save_timestamp_settings', $payload, null);
        check($result === ['ok' => false, 'message' => 'timestamp_settings_invalid'], 'Malformed payload was accepted');
    }
    check([$framework->settings, $framework->queries] === $before, 'Invalid payload started a transaction or changed settings');
    foreach (['timestamp_mode', 'bb_fallback'] as $key) {
        $framework->failKey = $key;
        $result = $module->redcap_module_ajax('save_timestamp_settings', ['timestamp_mode' => 'internal', 'bb_fallback' => false], null);
        check($result === ['ok' => false, 'message' => 'timestamp_settings_save_failed'], 'Write failure not reported');
        check($framework->settings === $before[0] && $framework->transaction === null, 'Partial timestamp change was not rolled back');
    }
    $framework->failKey = null;
    foreach (['START TRANSACTION', 'COMMIT'] as $sql) {
        $framework->failQuery = $sql;
        $result = $module->redcap_module_ajax('save_timestamp_settings', ['timestamp_mode' => 'internal', 'bb_fallback' => false], null);
        check(!$result['ok'] && $framework->settings === $before[0] && $framework->transaction === null,
            'Transaction failure left changed settings');
    }
    $framework->failQuery = null;
    $config = json_decode(file_get_contents(dirname(__DIR__) . '/config.json'), true, 512, JSON_THROW_ON_ERROR);
    check(in_array('save_timestamp_settings', $config['auth-ajax-actions'], true)
        && !in_array('save_timestamp_settings', $config['no-auth-ajax-actions'], true), 'Timestamp settings action is not authenticated');

    $framework->superuser = false;
    $result = $module->redcap_module_ajax('download_public_root_certificate', ['id' => 'bad', 'format' => 'pem'], null);
    check($result === ['ok' => false, 'message' => 'pki_invalid_request'],
        'Public download action did not accept unauthenticated dispatch');
    $framework->superuser = true;

    foreach ([['superuser' => false, 'projectId' => null, 'context' => null],
              ['superuser' => true, 'projectId' => 461, 'context' => 461],
              ['superuser' => true, 'projectId' => null, 'context' => 461],
              ['superuser' => true, 'projectId' => 461, 'context' => null]] as $case) {
        $framework->superuser = $case['superuser'];
        $framework->projectId = $case['projectId'];
        try {
            $module->redcap_module_ajax('save_alert_recipients', 'new@example.org', $case['context']);
            throw new \RuntimeException('Unauthorized AJAX request was accepted');
        } catch (\RuntimeException $e) {
            check($e->getMessage() === 'pki_access_denied', 'Unexpected unauthorized request outcome');
        }
        try {
            $module->redcap_module_ajax('run_diagnostic', null, $case['context']);
            throw new \RuntimeException('Unauthorized diagnostic accepted');
        } catch (\RuntimeException $e) {
            check($e->getMessage() === 'pki_access_denied', 'Unexpected diagnostic authorization outcome');
        }
        $before = [$framework->settings, $framework->queries];
        try {
            $module->redcap_module_ajax('save_timestamp_settings', ['timestamp_mode' => 'none', 'bb_fallback' => false], $case['context']);
            throw new \RuntimeException('Unauthorized timestamp settings request was accepted');
        } catch (\RuntimeException $e) {
            check($e->getMessage() === 'pki_access_denied', 'Unexpected timestamp settings authorization result');
        }
        check([$framework->settings, $framework->queries] === $before, 'Unauthorized settings request wrote data');
        try {
            $module->redcap_module_ajax('download_root_certificate', 'pem', $case['context']);
            throw new \RuntimeException('Unauthorized download was accepted');
        } catch (\RuntimeException $e) {
            check($e->getMessage() === 'pki_access_denied', 'Unexpected unauthorized download outcome');
        }
    }

    $framework->superuser = true;
    $framework->projectId = null;
    $framework->failWrite = true;
    $result = $module->redcap_module_ajax('save_alert_recipients', 'new@example.org', null);
    check($result === ['ok' => false, 'message' => 'admin_alert_recipients_save_failed'],
        'Storage failure was not reported');

    echo "PKI admin AJAX: recipients, downloads, timestamp settings, authorization, and rollback passed.\n";
}
