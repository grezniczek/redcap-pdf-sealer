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
        public array $bindings = [];
        public array $heldLocks = [];
        public function createTempFile(): string { throw new \RuntimeException("Preview must not issue a certificate"); }
        public function getQueryLogsSql(string $sql): string { return $sql; }

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

        public function log(string $message, array $details): int { check($message === 'timestamp_source_admin', 'Unexpected audit'); return 1; }
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
        public function fetch_row(): ?array { $row = $this->fetch_assoc(); return $row === null ? null : array_values($row); }
    }
    function db_query(string $sql, array $params, mixed ...$rest): SettingResult
    {
        global $framework;
        check($rest[2] === true, 'AJAX PKI read did not use primary connection');
        if (str_contains($sql, 'GET_LOCK')) {
            check(!isset($framework->heldLocks[$params[0]]), 'Unexpected nested lock');
            $framework->heldLocks[$params[0]] = true;
            return new SettingResult([1]);
        }
        if (str_contains($sql, 'RELEASE_LOCK')) {
            check(isset($framework->heldLocks[$params[0]]), 'Lock released without acquisition');
            unset($framework->heldLocks[$params[0]]);
            return new SettingResult([1]);
        }
        if (($params[0] ?? null) === 'project_identity_binding') {
            check(str_contains($sql, 'ISNULL(project_id)') && !str_contains($sql, 'private_key'), 'Unexpected binding query');
            return new SettingResult($framework->bindings[(int) $params[1]] ?? null);
        }
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
    check(in_array('project_admin_overview', $config['auth-ajax-actions'], true) && !in_array('project_admin_overview', $config['no-auth-ajax-actions'], true), 'Project overview must require authentication');
    foreach ([[], ['pids'=>[]], ['pids'=>[0]], ['pids'=>['461']], ['pids'=>array_fill(0,51,461)], ['pids'=>['pid'=>461]]] as $payload) {
        $before = [$framework->settings,$framework->queries];
        check($module->redcap_module_ajax('project_admin_overview',$payload,null)['ok'] === false, 'Malformed overview payload accepted');
        check([$framework->settings,$framework->queries] === $before, 'Malformed overview read/wrote data');
    }
    check(in_array('run_diagnostic', $config['auth-ajax-actions'], true)
        && !in_array('run_diagnostic', $config['no-auth-ajax-actions'], true), 'Diagnostic AJAX authentication configuration is wrong');
    check(in_array('send_test_alarm', $config['auth-ajax-actions'], true)
        && !in_array('send_test_alarm', $config['no-auth-ajax-actions'], true), 'Test alarm is not authenticated');

    foreach (['internal', 'none'] as $mode) {
        foreach ([false, true] as $fallback) {
            $payload = ['timestamp_mode' => $mode, 'bb_fallback' => $fallback];
            $result = $module->redcap_module_ajax('save_timestamp_settings', $payload, null);
            check($result === ['ok' => true, 'timestamp_mode' => $mode, 'bb_fallback' => $mode !== 'none' && $fallback], 'Timestamp choices not returned');
            $parsed = $providers->timestampSettings('builtin-ca');
            check($parsed->mode === $mode && $parsed->fallback === ($mode !== 'none' && $fallback), 'Saved settings differ from sealing interpretation');
            check($framework->transaction === null, 'Settings transaction left open');
        }
    }
    foreach (['none', 'builtin-tsa'] as $source) {
        check($module->redcap_module_ajax('save_provider_timestamp', ['provider' => 'builtin-ca', 'source' => $source, 'fallback' => false, 'alternatives' => []], null)['ok'], 'Provider policy dispatch failed');
        check($providers->provider('builtin-ca')['timestamp_source'] === ($source === 'none' ? null : $source), 'Provider policy dispatch did not save');
    }
    $savedPolicy = $framework->settings;
    foreach ([null, 'builtin-tsa', [1 => 'builtin-tsa'], ['builtin-tsa'], ['missing-source']] as $alternatives) {
        check(!$module->redcap_module_ajax('save_provider_timestamp',
            ['provider' => 'builtin-ca', 'source' => 'builtin-tsa', 'fallback' => false, 'alternatives' => $alternatives], null)['ok'],
            'Invalid alternative payload accepted');
        check($framework->settings === $savedPolicy, 'Invalid alternative payload changed policy');
    }
    check(!$module->redcap_module_ajax('save_provider_timestamp',
        ['provider' => 'builtin-ca', 'source' => 'none', 'fallback' => false, 'alternatives' => ['builtin-tsa']], null)['ok'],
        'No-timestamp policy accepted alternatives');
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

    foreach (['register_timestamp_source', 'test_timestamp_source', 'save_provider_timestamp', 'preview_timestamp_retirement', 'set_timestamp_retirement'] as $action) {
        check(in_array($action, $config['auth-ajax-actions'], true) && !in_array($action, $config['no-auth-ajax-actions'], true), 'TSA action must require authentication');
        check($module->redcap_module_ajax($action, [], null)['ok'] === false, 'Malformed TSA payload accepted');
    }
    $sourceId = 'remote-tsa-' . str_repeat('a', 16);
    $publicDer = 'disposable-public-chain-fixture';
    $framework->settings['external_tsa_source_ids'] = json_encode([$sourceId]);
    $framework->settings['tsa_source_' . $sourceId] = json_encode(['id' => $sourceId, 'kind' => 'external',
        'name' => 'Retirement API fixture', 'endpoint' => 'https://example.test/tsr', 'policy_oid' => '',
        'credentials' => null, 'chain' => [['der_b64' => base64_encode($publicDer), 'sha256' => hash('sha256', $publicDer)]]]);
    check($module->redcap_module_ajax('save_provider_timestamp', ['provider' => 'builtin-ca', 'source' => $sourceId,
        'fallback' => false, 'alternatives' => []], null)['ok'], 'Initial external source assignment failed');
    $preview = $module->redcap_module_ajax('preview_timestamp_retirement', ['source' => $sourceId], null);
    check($preview['ok'] && count($preview['providers']) === 1 && !$preview['retired'], 'Retirement public preview dispatch failed');
    $before = [$framework->settings, $framework->queries];
    foreach ([null, [], ['source' => 'builtin-tsa'], ['source' => $sourceId, 'retired' => 1, 'review_hash' => $preview['review_hash']],
        ['source' => $sourceId, 'retired' => true, 'review_hash' => 'bad'],
        ['source' => $sourceId, 'retired' => true, 'review_hash' => $preview['review_hash'], 'extra' => true]] as $payload) {
        check(!$module->redcap_module_ajax('set_timestamp_retirement', $payload, null)['ok'], 'Malformed retirement accepted');
    }
    check([$framework->settings, $framework->queries] === $before, 'Malformed retirement read/wrote state');
    $payload = ['source' => $sourceId, 'retired' => true, 'review_hash' => $preview['review_hash']];
    $framework->failKey = 'tsa_source_lifecycle_' . $sourceId;
    check(!$module->redcap_module_ajax('set_timestamp_retirement', $payload, null)['ok'], 'Retirement storage failure hidden');
    $framework->failKey = null;
    check($framework->settings === $before[0] && $framework->transaction === null, 'Retirement failure left partial state');
    $result = $module->redcap_module_ajax('set_timestamp_retirement', $payload, null);
    check($result === ['ok' => true, 'retired' => true, 'revision' => 1], 'Retirement mutation dispatch failed');
    check(!$module->redcap_module_ajax('set_timestamp_retirement', $payload, null)['ok'], 'Retirement replay accepted');
    check($module->redcap_module_ajax('test_timestamp_source', ['source' => $sourceId], null)
        === ['ok' => false, 'message' => 'tsa_source_unavailable'], 'Retired probe reached transport/crypto');
    $preview = $module->redcap_module_ajax('preview_timestamp_retirement', ['source' => $sourceId], null);
    check($module->redcap_module_ajax('set_timestamp_retirement', ['source' => $sourceId, 'retired' => false,
        'review_hash' => $preview['review_hash']], null) === ['ok' => true, 'retired' => false, 'revision' => 2], 'Reactivation failed');
    check($framework->heldLocks === [], 'TSA retirement leaked a configuration lock');

    $tsaPayload = ['name' => 'Test TSA', 'endpoint' => 'http://example.test/tsr', 'pem' => 'invalid PEM',
        'policy' => '', 'username' => '', 'password' => ''];
    check($module->redcap_module_ajax('register_timestamp_source', $tsaPayload, null)
        === ['ok' => false, 'message' => 'external_tsa_register_endpoint'], 'TSA endpoint error was hidden');
    $tsaPayload['endpoint'] = 'https://example.test/tsr';
    check($module->redcap_module_ajax('register_timestamp_source', $tsaPayload, null)
        === ['ok' => false, 'message' => 'external_tsa_register_chain'], 'TSA chain error was hidden');
    $tsaPayload['redcap_csrf_token'] = 'injected-form-field';
    unset($tsaPayload['policy'], $tsaPayload['username'], $tsaPayload['password']);
    check($module->redcap_module_ajax('register_timestamp_source', $tsaPayload, null)
        === ['ok' => false, 'message' => 'external_tsa_register_chain'], 'Additional form field or absent optional field blocked registration');
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
        foreach (['project_admin_overview', 'preview_project_renewal', 'renew_project_certificate', 'register_timestamp_source', 'test_timestamp_source', 'save_provider_timestamp', 'preview_timestamp_retirement', 'set_timestamp_retirement', 'register_ca_provider', 'assign_ca_provider', 'save_assignment_policy', 'preview_ca_retirement', 'set_ca_retirement', 'preview_provider_transition', 'start_provider_transition', 'cancel_provider_transition'] as $action) {
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
    foreach (['preview_project_renewal','renew_project_certificate'] as $action) {
        check(in_array($action,$config['auth-ajax-actions'],true) && !in_array($action,$config['no-auth-ajax-actions'],true), 'Renewal must require authentication');
        foreach ([null, [], ['pid'=>'461'], ['pid'=>0]] as $payload) {
            check($module->redcap_module_ajax($action,$payload,null) === ['ok'=>false,'message'=>'pki_invalid_request'], 'Malformed renewal accepted');
        }
    }
    // Exercise successful dispatch/service construction, not only rejected payloads.
    $framework->enabled = [529];
    $framework->bindings[529] = ['project_uuid'=>'39e9a540-e005-410d-b9eb-25d357784be1',
        'identity_id'=>str_repeat('c',32),'provider_id'=>'builtin-ca'];
    $beforePreview = [$framework->settings,$framework->queries,$framework->bindings];
    $result = $module->redcap_module_ajax('preview_provider_transition',['pid'=>529],null);
    check(($result['ok'] ?? false) === true && $result['pid'] === 529 && $result['provider_id'] === 'builtin-ca'
        && $result['identity_id'] === str_repeat('c',32) && $result['pending_provider_id'] === null
        && $result['enrollment_id'] === null && preg_match('/^[a-f0-9]{64}$/D',$result['review_hash']) === 1,
        'Valid provider review failed through AJAX dispatch');
    check([$framework->settings,$framework->queries,$framework->bindings] === $beforePreview && $framework->heldLocks === [],
        'AJAX preview wrote data or retained locks');

    $framework->failWrite = true;
    $result = $module->redcap_module_ajax('save_alert_recipients', 'new@example.org', null);
    check($result === ['ok' => false, 'message' => 'admin_alert_recipients_save_failed'],
        'Storage failure was not reported');

    echo "PKI admin AJAX: recipients, downloads, timestamp settings, authorization, and rollback passed.\n";
}
