<?php

namespace DE\RUB\PDFSealerExternalModule;

use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\Certificate;
use DE\RUB\PDFSealerExternalModule\Alerts\AdminAlarmService;
use DE\RUB\PDFSealerExternalModule\Alerts\AlarmLock;
use DE\RUB\PDFSealerExternalModule\Alerts\AlarmRepository;
use DE\RUB\PDFSealerExternalModule\Diagnostics\DiagnosticSnapshot;
use DE\RUB\PDFSealerExternalModule\Diagnostics\PkiDiagnosticService;
use DE\RUB\PDFSealerExternalModule\Pki\CertificateIssuer;
use DE\RUB\PDFSealerExternalModule\Pdf\PdfFinalizeService;
use DE\RUB\PDFSealerExternalModule\Pki\IdentityRepository;
use DE\RUB\PDFSealerExternalModule\Pki\PkiHealthService;
use DE\RUB\PDFSealerExternalModule\Pki\PrimaryLogReader;
use DE\RUB\PDFSealerExternalModule\Pki\PrimarySystemSettingReader;
use DE\RUB\PDFSealerExternalModule\Pki\PublicTrustRepository;
use DE\RUB\PDFSealerExternalModule\Pki\SecretProtector;

require_once __DIR__ . '/autoload.php';

class PDFSealerExternalModule extends \ExternalModules\AbstractExternalModule
{
    private const PUBLIC_TRUST_QUERY = 'pdf_sealer_certs';

    /** Framework cron: system-scoped public-certificate inventory and daily alarm summary. */
    public function checkCertificateExpiry($cronInfo): string
    {
        $result = (new \DE\RUB\PDFSealerExternalModule\Pki\ExpiryMonitor(
            $this->framework,
            new \DE\RUB\PDFSealerExternalModule\Pki\ExpiryInventory($this->framework),
            new AdminAlarmService($this->framework, new \DE\RUB\PDFSealerExternalModule\Alerts\AlarmRepository($this->framework), new \DE\RUB\PDFSealerExternalModule\Alerts\AlarmLock()),
            new \DE\RUB\PDFSealerExternalModule\Alerts\AlarmLock(),
        ))->run();
        if ($result['status'] === 'failed') { throw new \RuntimeException('PDF Sealer expiry scan failed; inspect the CC Alarms tab'); }
        return 'PDF Sealer expiry scan: ' . $result['status'] . '; certificates: ' . array_sum($result['counts'])
            . '; notification: ' . $result['mail_status'];
    }

    /** Framework cron: refresh signed, cached CRLs for built-in issuing keys. */
    public function publishCertificateRevocationLists($cronInfo): string
    {
        $framework = $this->framework;
        $settings = new PrimarySystemSettingReader($framework);
        $protector = new SecretProtector();
        try {
            $result = (new \DE\RUB\PDFSealerExternalModule\Pki\CrlPublicationService(
                $framework, new PublicTrustRepository(new PrimaryLogReader($framework), $settings),
                new IdentityRepository($framework, $protector), $protector,
                new \DE\RUB\PDFSealerExternalModule\Pki\CrlRepository($framework, $settings),
                new \DE\RUB\PDFSealerExternalModule\Pki\PkiInitializationLock(),
            ))->run();
            return 'PDF Sealer CRL publication: ' . $result['status'] . '; published: ' . $result['published'];
        } catch (\Throwable) {
            try {
                (new AdminAlarmService($framework, new AlarmRepository($framework), new AlarmLock()))
                    ->raise('CRL_PUBLICATION_FAILED', 'critical');
            } catch (\Throwable) { /* Framework also records the safe cron failure below. */ }
            throw new \RuntimeException('PDF Sealer CRL publication failed; inspect the CC Alarms tab');
        }
    }

    public static function publicTrustUrl(): string
    {
        return APP_PATH_SURVEY_FULL . '?' . self::PUBLIC_TRUST_QUERY;
    }

    /** Escaped display HTML, with each slash-delimited subject attribute on its own line. */
    public static function certificateSubjectHtml(string $subject): string
    {
        $parts = preg_split('~(?<!\\\\)(?=/[A-Za-z0-9.]+=)~', $subject, -1, PREG_SPLIT_NO_EMPTY);
        return implode("<br>\n", array_map(
            static fn(string $part): string => htmlspecialchars($part, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $parts === false ? [$subject] : $parts,
        ));
    }

    public function redcap_every_page_before_render($project_id): void
    {
        $query = $_SERVER['QUERY_STRING'] ?? '';
        $isTrust = $query === self::PUBLIC_TRUST_QUERY;
        $isCrl = preg_match('/^pdf_sealer_crl=([0-9a-f]{64})$/D', $query, $matches) === 1;
        if ($project_id !== null || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET' || (!$isTrust && !$isCrl)) {
            return;
        }

        $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $surveyPath = parse_url(APP_PATH_SURVEY_FULL, PHP_URL_PATH);
        if (!is_string($requestPath) || !is_string($surveyPath)
            || rtrim($requestPath, '/') !== rtrim($surveyPath, '/')) {
            return;
        }

        $module = $this;
        $module->exitAfterHook();
        if ($isCrl) {
            define('PDF_SEALER_PUBLIC_CRL_ROUTE', true);
            $crlKeyId = $matches[1];
            require __DIR__ . '/crl.php';
        } else {
            define('PDF_SEALER_PUBLIC_TRUST_ROUTE', true);
            require __DIR__ . '/trust.php';
        }
    }

    public function redcap_module_link_check_display($project_id, $link)
    {
        if ($project_id !== null && ($link['key'] ?? null) === 'public-trust') {
            return $this->framework->getProjectSetting('hide-project-trust-link', $project_id) == true
                ? null
                : $link;
        }

        return parent::redcap_module_link_check_display($project_id, $link);
    }

    public function redcap_module_ajax($action, $payload, $project_id): array
    {
        if ($action === 'download_public_root_certificate') {
            return $this->downloadPublicRootCertificate($payload);
        }
        if (in_array($action, ['generate_project_csr', 'download_project_csr', 'cancel_project_csr', 'review_project_certificate', 'activate_project_certificate'], true)) {
            return $this->projectEnrollment($action, $payload, $project_id);
        }
        if (!$this->framework->isSuperUser()
            || $project_id !== null
            || $this->framework->getProjectId() !== null) {
            throw new \RuntimeException($this->framework->tt('pki_access_denied'));
        }
        if (in_array($action, ['preview_project_renewal', 'renew_project_certificate'], true)) {
            return $this->manageProjectRenewal($action, $payload);
        }
        if (in_array($action, ['preview_provider_transition', 'start_provider_transition', 'cancel_provider_transition'], true)) {
            return $this->manageProviderTransition($action, $payload);
        }
        if (in_array($action, ['register_ca_provider', 'assign_ca_provider', 'save_assignment_policy', 'preview_ca_retirement', 'set_ca_retirement'], true)) {
            return $this->manageCaProvider($action, $payload);
        }
        if (in_array($action, ['register_timestamp_source', 'test_timestamp_source', 'save_provider_timestamp'], true)) {
            return $this->manageTimestampSource($action, $payload);
        }
        if ($action === 'run_diagnostic') {
            return $this->runDiagnostic($payload);
        }
        if ($action === 'send_test_alarm') {
            return $this->sendTestAlarm($payload);
        }
        if ($action === 'save_alert_recipients') {
            return $this->saveAlertRecipients($payload);
        }
        if ($action === 'save_timestamp_settings') {
            return $this->saveTimestampSettings($payload);
        }
        if ($action === 'download_root_certificate') {
            return $this->downloadRootCertificate($payload);
        }
        throw new \RuntimeException($this->framework->tt('pki_invalid_request'));
    }

    private function projectEnrollment(string $action, mixed $payload, mixed $projectId): array
    {
        $pid = filter_var($projectId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $ambient = $this->framework->getProjectId();
        $user = $this->framework->getUser();
        if ($pid === false || $ambient === null || (string) $ambient !== (string) $pid
            || !is_string($user->getUsername()) || $user->getUsername() === '' || !$user->hasDesignRights($pid)
            || !in_array($pid, array_map('intval', $this->framework->getProjectsWithModuleEnabled()), true)) {
            throw new \RuntimeException($this->framework->tt('project_status_access_denied'));
        }
        $certificateAction = in_array($action, ['review_project_certificate', 'activate_project_certificate'], true);
        $expectedFields = $certificateAction ? ($action === 'review_project_certificate' ? 2 : 4) : 1;
        if (($action === 'generate_project_csr' && $payload !== null)
            || ($action !== 'generate_project_csr' && (!is_array($payload) || count($payload) !== $expectedFields
                || !is_string($payload['id'] ?? null) || preg_match('/^[a-f0-9]{32}$/D', $payload['id']) !== 1))
            || ($certificateAction && (!is_string($payload['pem'] ?? null) || strlen($payload['pem']) > 65536))
            || ($action === 'activate_project_certificate' && (!is_string($payload['review_hash'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $payload['review_hash']) !== 1 || !array_key_exists('active_identity_id', $payload)
                || ($payload['active_identity_id'] !== null && (!is_string($payload['active_identity_id'])
                    || preg_match('/^[a-f0-9]{32}$/D', $payload['active_identity_id']) !== 1))))) {
            return ['ok' => false, 'message' => $this->framework->tt('pki_invalid_request')];
        }
        try {
            $service = new \DE\RUB\PDFSealerExternalModule\Pki\ProjectEnrollmentService(
                $this->framework, new \DE\RUB\PDFSealerExternalModule\Pki\ProjectBindingRepository($this->framework),
                new \DE\RUB\PDFSealerExternalModule\Pki\ProviderRepository($this->framework), new SecretProtector(),
                new \DE\RUB\PDFSealerExternalModule\Pki\ProjectIssueLock(),
            );
            if ($action === 'generate_project_csr') { return $service->generate($pid); }
            if ($action === 'download_project_csr') { return $service->download($pid, $payload['id']); }
            if ($action === 'review_project_certificate') { return $service->reviewCertificate($pid, $payload['id'], $payload['pem']); }
            if ($action === 'activate_project_certificate') {
                $service->activateCertificate($pid, $payload['id'], $payload['pem'], $payload['review_hash'], $payload['active_identity_id']);
                return ['ok' => true];
            }
            $service->cancel($pid, $payload['id']);
            return ['ok' => true];
        } catch (\DE\RUB\PDFSealerExternalModule\Pki\CaProviderRetired) {
            return ['ok' => false, 'message' => $this->framework->tt('enrollment_provider_retired')];
        } catch (\Throwable) {
            return ['ok' => false, 'message' => $this->framework->tt($certificateAction ? 'enrollment_certificate_failed' : 'enrollment_failed')];
        }
    }

    private function manageProjectRenewal(string $action, mixed $payload): array
    {
        if (!is_array($payload) || !is_int($payload['pid'] ?? null) || $payload['pid'] < 1
            || ($action === 'renew_project_certificate' && (!is_string($payload['review_hash'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $payload['review_hash']) !== 1))) {
            return ['ok' => false, 'message' => $this->framework->tt('pki_invalid_request')];
        }
        try {
            $protector = new SecretProtector();
            $identities = new IdentityRepository($this->framework, $protector);
            $bindings = new \DE\RUB\PDFSealerExternalModule\Pki\ProjectBindingRepository($this->framework);
            $projectLock = new \DE\RUB\PDFSealerExternalModule\Pki\ProjectIssueLock();
            $configurationLock = new \DE\RUB\PDFSealerExternalModule\Pki\PkiInitializationLock();
            $health = new PkiHealthService($identities, $protector);
            $enrollment = new \DE\RUB\PDFSealerExternalModule\Pki\ProjectEnrollmentService(
                $this->framework, $bindings, $identities->providers(), $protector, $projectLock, null, $identities, $configurationLock);
            $projects = new \DE\RUB\PDFSealerExternalModule\Pki\ProjectIdentityService($bindings, $identities, $protector,
                CertificateIssuer::forFramework($this->framework), $health, $projectLock, $configurationLock);
            $service = new \DE\RUB\PDFSealerExternalModule\Pki\ProjectRenewalService(
                $this->framework, $bindings, $identities, $enrollment, $projects, $health, $projectLock, $configurationLock);
            return ['ok' => true] + ($action === 'preview_project_renewal'
                ? $service->preview($payload['pid']) : $service->renew($payload['pid'], $payload['review_hash']));
        } catch (\Throwable) {
            return ['ok' => false, 'message' => $this->framework->tt('renewal_failed')];
        }
    }

    private function manageProviderTransition(string $action, mixed $payload): array
    {
        $fields = match ($action) { 'preview_provider_transition' => 1, 'start_provider_transition' => 3, default => 2 };
        if (!is_array($payload) || count($payload) !== $fields || !is_int($payload['pid'] ?? null) || $payload['pid'] < 1
            || ($action !== 'preview_provider_transition' && (!is_string($payload['review_hash'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $payload['review_hash']) !== 1))
            || ($action === 'start_provider_transition' && (!is_string($payload['provider'] ?? null)
                || preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $payload['provider']) !== 1))) {
            return ['ok' => false, 'message' => $this->framework->tt('pki_invalid_request')];
        }
        try {
            $protector = new SecretProtector();
            $identities = new IdentityRepository($this->framework, $protector);
            $bindings = new \DE\RUB\PDFSealerExternalModule\Pki\ProjectBindingRepository($this->framework);
            $providers = $identities->providers();
            $projectLock = new \DE\RUB\PDFSealerExternalModule\Pki\ProjectIssueLock();
            $configurationLock = new \DE\RUB\PDFSealerExternalModule\Pki\PkiInitializationLock();
            $enrollment = new \DE\RUB\PDFSealerExternalModule\Pki\ProjectEnrollmentService(
                $this->framework, $bindings, $providers, $protector, $projectLock, null, $identities, $configurationLock);
            $projects = new \DE\RUB\PDFSealerExternalModule\Pki\ProjectIdentityService($bindings, $identities, $protector,
                CertificateIssuer::forFramework($this->framework), new PkiHealthService($identities, $protector), $projectLock, $configurationLock);
            $service = new \DE\RUB\PDFSealerExternalModule\Pki\ProviderTransitionService(
                $this->framework, $providers, $bindings, $enrollment, $projects, $projectLock, $configurationLock);
            if ($action === 'preview_provider_transition') { return ['ok' => true] + $service->preview($payload['pid']); }
            if ($action === 'start_provider_transition') {
                return ['ok' => true, 'state' => $service->start($payload['pid'], $payload['provider'], $payload['review_hash'])];
            }
            $service->cancel($payload['pid'], $payload['review_hash']);
            return ['ok' => true, 'state' => 'canceled'];
        } catch (\Throwable) {
            return ['ok' => false, 'message' => $this->framework->tt('transition_failed')];
        }
    }

    private function manageCaProvider(string $action, mixed $payload): array
    {
        if (!is_array($payload)) { return ['ok' => false, 'message' => $this->framework->tt('pki_invalid_request')]; }
        if (in_array($action, ['preview_ca_retirement', 'set_ca_retirement'], true)) {
            if (!is_string($payload['provider'] ?? null) || preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $payload['provider']) !== 1
                || count($payload) !== ($action === 'preview_ca_retirement' ? 1 : 4)
                || ($action === 'set_ca_retirement' && (!is_bool($payload['retired'] ?? null)
                    || !is_bool($payload['enable_assignment_gate'] ?? null) || !is_string($payload['review_hash'] ?? null)
                    || preg_match('/^[a-f0-9]{64}$/D', $payload['review_hash']) !== 1))) {
                return ['ok' => false, 'message' => $this->framework->tt('pki_invalid_request')];
            }
        } elseif ($action === 'register_ca_provider') {
            if (count($payload) !== 4 || !is_string($payload['name'] ?? null) || !is_string($payload['pem'] ?? null)
                || !is_string($payload['source'] ?? null) || !is_bool($payload['fallback'] ?? null)
                || strlen($payload['pem']) > 131072 || strlen($payload['name']) > 128) {
                return ['ok' => false, 'message' => $this->framework->tt('pki_invalid_request')];
            }
        } elseif ($action === 'save_assignment_policy') {
            if (count($payload) !== 1 || !is_bool($payload['required'] ?? null)) {
                return ['ok' => false, 'message' => $this->framework->tt('pki_invalid_request')];
            }
        } elseif (count($payload) !== 2 || !is_int($payload['pid'] ?? null) || $payload['pid'] < 1
            || !is_string($payload['provider'] ?? null)) {
            return ['ok' => false, 'message' => $this->framework->tt('pki_invalid_request')];
        }
        try {
            $service = new \DE\RUB\PDFSealerExternalModule\Pki\ProviderAdminService(
                $this->framework, new \DE\RUB\PDFSealerExternalModule\Pki\ProviderRepository($this->framework),
                new \DE\RUB\PDFSealerExternalModule\Pki\ProjectBindingRepository($this->framework),
                new \DE\RUB\PDFSealerExternalModule\Pki\CaChainValidator($this->framework),
                new \DE\RUB\PDFSealerExternalModule\Pki\PkiInitializationLock(),
                new \DE\RUB\PDFSealerExternalModule\Pki\ProjectIssueLock(),
            );
            if ($action === 'preview_ca_retirement') {
                return ['ok' => true] + $service->previewRetirement($payload['provider']);
            }
            if ($action === 'set_ca_retirement') {
                $service->setRetired($payload['provider'], $payload['retired'], $payload['review_hash'], $payload['enable_assignment_gate']);
                return ['ok' => true];
            }
            if ($action === 'register_ca_provider') {
                $service->register($payload['name'], $payload['pem'], $payload['source'] === 'none' ? null : $payload['source'], $payload['fallback']);
            } elseif ($action === 'save_assignment_policy') {
                $service->saveAssignmentPolicy($payload['required']);
                return ['ok' => true, 'required' => $payload['required']];
            } else { $service->assign($payload['pid'], $payload['provider']); }
            return ['ok' => true];
        } catch (\Throwable) {
            return ['ok' => false, 'message' => $this->framework->tt(match ($action) {
                'preview_ca_retirement', 'set_ca_retirement' => 'provider_lifecycle_failed',
                'register_ca_provider' => 'provider_register_failed',
                'save_assignment_policy' => 'assignment_policy_failed',
                default => 'provider_assign_failed',
            })];
        }
    }

    private function runDiagnostic(mixed $payload): array
    {
        if ($payload !== null) {
            return ['ok' => false, 'message' => $this->framework->tt('pki_invalid_request')];
        }
        try {
            $protector = new SecretProtector();
            $service = new PkiDiagnosticService(
                new IdentityRepository($this->framework, $protector),
                $protector,
                CertificateIssuer::forFramework($this->framework, 'diagnostic'),
                new PrimarySystemSettingReader($this->framework),
            );
            $result = $service->run();
            $completedAt = time();
            $saved = true;
            try {
                (new DiagnosticSnapshot($this->framework))->save($result, $completedAt);
            } catch (\Throwable) {
                $saved = false;
            }
            return ['ok' => true, 'completed_at' => $completedAt, 'saved' => $saved] + $result;
        } catch (\Throwable) {
            return ['ok' => false, 'message' => $this->framework->tt('diagnostic_unavailable')];
        }
    }

    private function sendTestAlarm(mixed $payload): array
    {
        if (!is_array($payload) || count($payload) !== 1 || ($payload['confirmed'] ?? null) !== true) {
            return ['ok' => false, 'message' => $this->framework->tt('pki_invalid_request')];
        }
        try {
            $status = (new AdminAlarmService($this->framework,
                new AlarmRepository($this->framework), new AlarmLock()))->sendTest();
        } catch (\Throwable) {
            $status = 'failed';
        }
        return ['ok' => $status === 'sent', 'message' => $this->framework->tt('alarm_test_' . $status)];
    }

    private function manageTimestampSource(string $action, mixed $payload): array
    {
        $failure = ['ok' => false, 'message' => $this->framework->tt('external_tsa_failed')];
        if (!is_array($payload)) {
            return $action === 'register_timestamp_source'
                ? ['ok' => false, 'message' => $this->framework->tt('external_tsa_register_request')]
                : $failure;
        }
        try {
            $service = new \DE\RUB\PDFSealerExternalModule\Timestamp\ExternalTimestampSources($this->framework);
            if ($action === 'register_timestamp_source') {
                $limits = ['name' => 128, 'endpoint' => 2048, 'pem' => 131072, 'policy' => 256, 'username' => 256, 'password' => 4096];
                $invalid = ['ok' => false, 'message' => $this->framework->tt('external_tsa_register_request')];
                foreach (['policy', 'username', 'password'] as $optional) { $payload[$optional] ??= ''; }
                foreach ($limits as $key => $max) { if (!is_string($payload[$key] ?? null) || strlen($payload[$key]) > $max) { return $invalid; } }
                $id = $service->register($payload['name'], $payload['endpoint'], $payload['pem'], $payload['policy'], $payload['username'], $payload['password']);
                return ['ok' => true, 'id' => $id];
            }
            if ($action === 'test_timestamp_source') {
                if (count($payload) !== 1 || !is_string($payload['source'] ?? null)) { return $failure; }
                return ['ok' => true, 'diagnostic' => $service->diagnose($payload['source'])];
            }
            if (array_diff(array_keys($payload), ['provider', 'source', 'fallback', 'alternatives']) !== []
                || (array_key_exists('alternatives', $payload) && !is_array($payload['alternatives']))
                || !is_string($payload['provider'] ?? null) || !is_string($payload['source'] ?? null)
                || !is_bool($payload['fallback'] ?? null)) { return $failure; }
            $service->savePolicy($payload['provider'], $payload['source'] === 'none' ? null : $payload['source'], $payload['fallback'], $payload['alternatives'] ?? []);
            return ['ok' => true];
        } catch (\DE\RUB\PDFSealerExternalModule\Timestamp\TimestampSourceRegistrationFailed $e) {
            return ['ok' => false, 'message' => $this->framework->tt('external_tsa_register_' . $e->stage)];
        } catch (\Throwable $e) {
            if ($action === 'register_timestamp_source') {
                error_log('PDF Sealer TSA registration failed before validation (' . get_class($e) . ')');
                return ['ok' => false, 'message' => $this->framework->tt('external_tsa_register_internal')];
            }
            return $failure;
        }
    }

    /** @return array{ok: bool, message?: string, timestamp_mode?: string, bb_fallback?: bool} */
    private function saveTimestampSettings(mixed $payload): array
    {
        if (!is_array($payload) || count($payload) !== 2
            || !in_array($payload['timestamp_mode'] ?? null, ['internal', 'none'], true)
            || !is_bool($payload['bb_fallback'] ?? null)) {
            return ['ok' => false, 'message' => $this->framework->tt('timestamp_settings_invalid')];
        }
        $fallback = $payload['timestamp_mode'] !== 'none' && $payload['bb_fallback'];
        try {
            (new \DE\RUB\PDFSealerExternalModule\Timestamp\ExternalTimestampSources($this->framework))->savePolicy(
                'builtin-ca', $payload['timestamp_mode'] === 'none' ? null : 'builtin-tsa', $fallback);
        } catch (\Throwable) {
            return ['ok' => false, 'message' => $this->framework->tt('timestamp_settings_save_failed')];
        }
        return ['ok' => true, 'timestamp_mode' => $payload['timestamp_mode'], 'bb_fallback' => $fallback];
    }

    /** @return array{ok: bool, message?: string, recipients?: string} */
    private function saveAlertRecipients(mixed $payload): array
    {
        $recipients = is_string($payload) && strlen($payload) <= 4096
            ? AdminAlarmService::parseRecipients($payload)
            : null;
        if ($recipients === null) {
            return ['ok' => false, 'message' => $this->framework->tt('admin_alert_recipients_invalid')];
        }
        try {
            $this->framework->setSystemSetting('admin-alert-recipients', $recipients);
        } catch (\Throwable $e) {
            error_log('PDF Sealer alarm recipient update failed (' . get_class($e) . ')');
            return ['ok' => false, 'message' => $this->framework->tt('admin_alert_recipients_save_failed')];
        }
        return ['ok' => true, 'recipients' => implode(', ', $recipients)];
    }

    /** @return array{ok: bool, message?: string, filename?: string, content_type?: string, base64?: string} */
    private function downloadRootCertificate(mixed $format): array
    {
        if (!in_array($format, ['pem', 'der'], true)) {
            return ['ok' => false, 'message' => $this->framework->tt('pki_invalid_request')];
        }
        try {
            $identities = new IdentityRepository($this->framework, new SecretProtector());
            $id = $identities->activeId('root');
            $root = $id === null ? null : $identities->find($id);
            if ($root === null || $root->role !== 'root') {
                throw new \RuntimeException('Active root identity unavailable');
            }
            $der = $root->certificateDer;
            $pem = Certificate::derToPem($der);
            $publicKey = openssl_pkey_get_public($pem);
            if (!is_array(openssl_x509_parse($pem))
                || !(new Certificate())->isCertificateAuthority($der)
                || $publicKey === false
                || openssl_x509_verify($pem, $publicKey) !== 1) {
                throw new \RuntimeException('Active root certificate is invalid');
            }
        } catch (\Throwable $e) {
            error_log('PDF Sealer root certificate download failed (' . get_class($e) . ')');
            return ['ok' => false, 'message' => $this->framework->tt('pki_root_download_unavailable')];
        }
        return self::certificateDownloadPayload($der, $format);
    }

    /** @return array{ok: bool, message?: string, filename?: string, content_type?: string, base64?: string} */
    private function downloadPublicRootCertificate(mixed $payload): array
    {
        if (!is_array($payload)
            || !is_string($payload['id'] ?? null)
            || preg_match('/^(?:[0-9a-f]{32}|[0-9a-f]{64})$/D', $payload['id']) !== 1
            || !in_array($payload['format'] ?? null, ['pem', 'der'], true)) {
            return ['ok' => false, 'message' => $this->framework->tt('pki_invalid_request')];
        }
        try {
            $repository = new PublicTrustRepository(
                new PrimaryLogReader($this->framework),
                new PrimarySystemSettingReader($this->framework),
            );
            foreach (array_merge($repository->roots(), (new \DE\RUB\PDFSealerExternalModule\Pki\ProviderRepository($this->framework))->publicCertificates()) as $root) {
                if ($root['id'] === $payload['id']) {
                    return self::certificateDownloadPayload($root['der'], $payload['format'], isset($root['provider_id']) ? 'ca' : 'root');
                }
            }
        } catch (\Throwable) {
            // Do not expose repository details through a public endpoint.
        }
        return ['ok' => false, 'message' => $this->framework->tt('pki_root_download_unavailable')];
    }

    /** @return array{ok: true, filename: string, content_type: string, base64: string} */
    private static function certificateDownloadPayload(string $der, string $format, string $kind = 'root'): array
    {
        $contents = $format === 'pem' ? Certificate::derToPem($der) : $der;
        return [
            'ok' => true,
            'filename' => 'redcap-pdf-sealer-' . $kind . '-' . substr(hash('sha256', $der), 0, 16) . '.' . $format,
            'content_type' => $format === 'pem' ? 'application/x-pem-file' : 'application/pkix-cert',
            'base64' => base64_encode($contents),
        ];
    }

    public function redcap_module_pdf_finalize(
        string $temporaryPdfPath,
        array $operation,
        array $context
    ): \ExternalModules\PdfFinalizeResult {
        return (new PdfFinalizeService($this->framework))->finalize($temporaryPdfPath, $operation, $context);
    }
}
