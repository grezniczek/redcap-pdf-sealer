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
use DE\RUB\PDFSealerExternalModule\Pki\PrimaryLogReader;
use DE\RUB\PDFSealerExternalModule\Pki\PrimarySystemSettingReader;
use DE\RUB\PDFSealerExternalModule\Pki\PublicTrustRepository;
use DE\RUB\PDFSealerExternalModule\Pki\SecretProtector;

require_once __DIR__ . '/autoload.php';

class PDFSealerExternalModule extends \ExternalModules\AbstractExternalModule
{
    private const PUBLIC_TRUST_QUERY = 'pdf_sealer_certs';

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
        if ($project_id !== null
            || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'
            || ($_SERVER['QUERY_STRING'] ?? '') !== self::PUBLIC_TRUST_QUERY) {
            return;
        }

        $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $surveyPath = parse_url(APP_PATH_SURVEY_FULL, PHP_URL_PATH);
        if (!is_string($requestPath) || !is_string($surveyPath)
            || rtrim($requestPath, '/') !== rtrim($surveyPath, '/')) {
            return;
        }

        define('PDF_SEALER_PUBLIC_TRUST_ROUTE', true);
        $module = $this;
		$module->exitAfterHook();
        require __DIR__ . '/trust.php';
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
        if (!$this->framework->isSuperUser()
            || $project_id !== null
            || $this->framework->getProjectId() !== null) {
            throw new \RuntimeException($this->framework->tt('pki_access_denied'));
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

    /** @return array{ok: bool, message?: string, timestamp_mode?: string, bb_fallback?: bool} */
    private function saveTimestampSettings(mixed $payload): array
    {
        if (!is_array($payload) || count($payload) !== 2
            || !in_array($payload['timestamp_mode'] ?? null, ['internal', 'none'], true)
            || !is_bool($payload['bb_fallback'] ?? null)) {
            return ['ok' => false, 'message' => $this->framework->tt('timestamp_settings_invalid')];
        }
        $started = false;
        try {
            if ($this->framework->query('START TRANSACTION', []) === false) {
                throw new \RuntimeException('Could not start settings transaction');
            }
            $started = true;
            $this->framework->setSystemSetting('timestamp_mode', $payload['timestamp_mode']);
            // Keep string storage compatible with the primary-connection settings reader.
            $this->framework->setSystemSetting('bb_fallback', $payload['bb_fallback'] ? '1' : '0');
            if ($this->framework->query('COMMIT', []) === false) {
                throw new \RuntimeException('Could not commit settings transaction');
            }
        } catch (\Throwable $e) {
            if ($started) {
                try { $this->framework->query('ROLLBACK', []); } catch (\Throwable) {}
            }
            error_log('PDF Sealer timestamp settings update failed (' . get_class($e) . ')');
            return ['ok' => false, 'message' => $this->framework->tt('timestamp_settings_save_failed')];
        }
        return ['ok' => true, 'timestamp_mode' => $payload['timestamp_mode'], 'bb_fallback' => $payload['bb_fallback']];
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
            || preg_match('/^[0-9a-f]{32}$/D', $payload['id']) !== 1
            || !in_array($payload['format'] ?? null, ['pem', 'der'], true)) {
            return ['ok' => false, 'message' => $this->framework->tt('pki_invalid_request')];
        }
        try {
            $repository = new PublicTrustRepository(
                new PrimaryLogReader($this->framework),
                new PrimarySystemSettingReader($this->framework),
            );
            foreach ($repository->roots() as $root) {
                if ($root['id'] === $payload['id']) {
                    return self::certificateDownloadPayload($root['der'], $payload['format']);
                }
            }
        } catch (\Throwable) {
            // Do not expose repository details through a public endpoint.
        }
        return ['ok' => false, 'message' => $this->framework->tt('pki_root_download_unavailable')];
    }

    /** @return array{ok: true, filename: string, content_type: string, base64: string} */
    private static function certificateDownloadPayload(string $der, string $format): array
    {
        $contents = $format === 'pem' ? Certificate::derToPem($der) : $der;
        return [
            'ok' => true,
            'filename' => 'redcap-pdf-sealer-root-' . substr(hash('sha256', $der), 0, 16) . '.' . $format,
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
