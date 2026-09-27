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
        public ?string $username = 'admin';
        public bool $design = true;
        public array $enabled = [461];
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

        public function getProjectsWithModuleEnabled(): array { return $this->enabled; }
        public function getUser(): object {
            return new class($this) {
                public function __construct(private object $framework) {}
                public function getUsername(): ?string { return $this->framework->username; }
                public function hasDesignRights(int $pid): bool { return $this->framework->superuser || $this->framework->design; }
            };
        }
        public function getModuleInstance(): object { return (object) ['PREFIX' => 'pdf_sealer']; }
        public function prefixSettingKey(string $key): string { return $key; }
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

    final class SettingResult
    {
        public function __construct(private ?array $row) {}
        public function fetch_assoc(): ?array { $row = $this->row; $this->row = null; return $row; }
    }
    function db_query(string $sql, array $params, mixed ...$rest): SettingResult
    {
        global $framework;
        check(str_contains($sql, 's.project_id IS NULL') && $rest[2] === true, 'Settings not read from primary/system scope');
        $value = $framework->settings[$params[1]] ?? null;
        return new SettingResult($value === null ? null : ['value' => $value, 'type' => 'string']);
    }
    $framework = new FakeFramework();
    $providers = new \DE\RUB\PDFSealerExternalModule\Pki\ProviderRepository($framework);
    $providers->initialize(str_repeat('a', 32), str_repeat('b', 32));
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
    foreach ([null, [], ['confirmed' => false], ['confirmed' => 'true'], ['confirmed' => true, 'to' => 'other@example.org']] as $payload) {
        check($module->redcap_module_ajax('send_test_alarm', $payload, null)
            === ['ok' => false, 'message' => 'pki_invalid_request'], 'Test alarm accepted missing confirmation or arbitrary recipients');
    }
    $config = json_decode(file_get_contents(dirname(__DIR__) . '/config.json'), true, flags: JSON_THROW_ON_ERROR);
    check(in_array('run_diagnostic', $config['auth-ajax-actions'], true)
        && !in_array('run_diagnostic', $config['no-auth-ajax-actions'], true), 'Diagnostic AJAX authentication configuration is wrong');
    check(in_array('send_test_alarm', $config['auth-ajax-actions'], true)
        && !in_array('send_test_alarm', $config['no-auth-ajax-actions'], true), 'Test alarm is not authenticated');

    foreach (['internal', 'none'] as $mode) {
        foreach ([false, true] as $fallback) {
            $payload = ['timestamp_mode' => $mode, 'bb_fallback' => $fallback];
            $result = $module->redcap_module_ajax('save_timestamp_settings', $payload, null);
            check($result === ['ok' => true] + $payload, 'Timestamp choices not returned');
            $parsed = $providers->timestampSettings('builtin-ca');
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
    foreach (['ca_provider_builtin-ca'] as $key) {
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

    check(in_array('save_assignment_policy', $config['auth-ajax-actions'], true)
        && !in_array('save_assignment_policy', $config['no-auth-ajax-actions'], true), 'Assignment policy is not authenticated');
    $before = [$framework->settings, $framework->queries];
    foreach ([null, [], ['required' => 'true'], ['required' => 1], ['required' => true, 'extra' => false]] as $payload) {
        $result = $module->redcap_module_ajax('save_assignment_policy', $payload, null);
        check($result === ['ok' => false, 'message' => 'pki_invalid_request'], 'Malformed assignment policy accepted');
    }
    check([$framework->settings, $framework->queries] === $before, 'Invalid policy request wrote storage');

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
            $module->redcap_module_ajax('send_test_alarm', ['confirmed' => true], $case['context']);
            throw new \RuntimeException('Unauthorized test alarm was accepted');
        } catch (\RuntimeException $e) {
            check($e->getMessage() === 'pki_access_denied', 'Unexpected test alarm authorization result');
        }
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
        foreach (['register_ca_provider', 'assign_ca_provider', 'save_assignment_policy', 'preview_ca_retirement', 'set_ca_retirement', 'preview_provider_transition', 'start_provider_transition', 'cancel_provider_transition'] as $action) {
            try {
                $module->redcap_module_ajax($action, [], $case['context']);
                throw new \RuntimeException('Unauthorized provider request accepted');
            } catch (\RuntimeException $e) { check($e->getMessage() === 'pki_access_denied', 'Wrong provider authorization outcome'); }
        }
        check([$framework->settings, $framework->queries] === $before, 'Unauthorized settings request wrote data');
        try {
            $module->redcap_module_ajax('download_root_certificate', 'pem', $case['context']);
            throw new \RuntimeException('Unauthorized download was accepted');
        } catch (\RuntimeException $e) {
            check($e->getMessage() === 'pki_access_denied', 'Unexpected unauthorized download outcome');
        }
    }

    $before = [$framework->settings, $framework->queries];
    foreach (['generate_project_csr', 'download_project_csr', 'cancel_project_csr', 'review_project_certificate', 'activate_project_certificate'] as $action) {
        check(in_array($action, $config['auth-ajax-actions'], true) && !in_array($action, $config['no-auth-ajax-actions'], true), 'CSR action exposed without authentication');
        foreach ([[null, true, true, 461, 461, [461]], ['user', false, false, 461, 461, [461]],
            ['admin', true, true, null, null, [461]], ['admin', true, true, 461, 462, [461,462]],
            ['admin', true, true, 461, 461, []]] as [$username,$superuser,$design,$ambient,$context,$enabled]) {
            $framework->username = $username; $framework->superuser = $superuser; $framework->design = $design;
            $framework->projectId = $ambient; $framework->enabled = $enabled;
            try { $module->redcap_module_ajax($action, null, $context); throw new \RuntimeException('CSR authorization bypass'); }
            catch (\RuntimeException $e) { check($e->getMessage() === 'project_status_access_denied', 'Wrong CSR denial'); }
        }
        $framework->username = 'designer'; $framework->superuser = false; $framework->design = true;
        $framework->projectId = 461; $framework->enabled = [461];
        $badPayloads = $action === 'generate_project_csr' ? [[], ['pid'=>462]] : [null, [], ['id'=>'bad'], ['id'=>str_repeat('a',32),'pid'=>462]];
        foreach ($badPayloads as $payload) {
            check($module->redcap_module_ajax($action,$payload,461) === ['ok'=>false,'message'=>'pki_invalid_request'], 'Invalid CSR payload accepted');
        }
    }
    check([$framework->settings, $framework->queries] === $before, 'Rejected CSR request changed data');

    $framework->superuser = true;
    $framework->projectId = null;
    foreach (['preview_ca_retirement', 'set_ca_retirement', 'preview_provider_transition', 'start_provider_transition', 'cancel_provider_transition'] as $action) {
        check(in_array($action,$config['auth-ajax-actions'],true) && !in_array($action,$config['no-auth-ajax-actions'],true), 'Retirement action exposed without authentication');
        foreach ([null, [], ['provider'=>'bad id'], ['provider'=>'builtin-ca','retired'=>'true','enable_assignment_gate'=>false,'review_hash'=>str_repeat('a',64)],
            ['provider'=>'builtin-ca','retired'=>true,'enable_assignment_gate'=>false,'review_hash'=>'bad']] as $payload) {
            check($module->redcap_module_ajax($action,$payload,null) === ['ok'=>false,'message'=>'pki_invalid_request'], 'Malformed retirement request accepted');
        }
    }
    foreach (['preview_provider_transition', 'start_provider_transition', 'cancel_provider_transition'] as $action) {
        check(in_array($action,$config['auth-ajax-actions'],true) && !in_array($action,$config['no-auth-ajax-actions'],true), 'Transition exposed without authentication');
        foreach ([[], ['pid'=>'461'], ['pid'=>0], ['pid'=>461,'review_hash'=>'bad'],
            ['pid'=>461,'review_hash'=>str_repeat('a',64),'provider'=>'bad id'],
            ['pid'=>461,'review_hash'=>str_repeat('a',64),'provider'=>'builtin-ca','extra'=>true]] as $payload) {
            check($module->redcap_module_ajax($action,$payload,null) === ['ok'=>false,'message'=>'pki_invalid_request'], 'Invalid transition payload accepted');
        }
    }
    $framework->failWrite = true;
    $result = $module->redcap_module_ajax('save_alert_recipients', 'new@example.org', null);
    check($result === ['ok' => false, 'message' => 'admin_alert_recipients_save_failed'],
        'Storage failure was not reported');

    echo "PKI admin AJAX: recipients, downloads, timestamp settings, authorization, and rollback passed.\n";
}
