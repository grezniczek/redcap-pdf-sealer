<?php

namespace DE\RUB\PDFSealerExternalModule;

use Com\Tecnick\Pdf\Sign\Cms\Certificate;
use DE\RUB\PDFSealerExternalModule\Alerts\AdminAlarmService;
use DE\RUB\PDFSealerExternalModule\Pdf\PdfFinalizeService;
use DE\RUB\PDFSealerExternalModule\Pki\IdentityRepository;
use DE\RUB\PDFSealerExternalModule\Pki\PrimaryLogReader;
use DE\RUB\PDFSealerExternalModule\Pki\PrimarySystemSettingReader;
use DE\RUB\PDFSealerExternalModule\Pki\PublicTrustRepository;
use DE\RUB\PDFSealerExternalModule\Pki\SecretProtector;

require_once __DIR__ . '/vendor/autoload.php';

class PDFSealerExternalModule extends \ExternalModules\AbstractExternalModule
{
    private const PUBLIC_TRUST_QUERY = 'pdf_sealer_certs&NOAUTH';

    public static function publicTrustUrl(): string
    {
        return APP_PATH_SURVEY_FULL . '?' . self::PUBLIC_TRUST_QUERY;
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
        if ($action === 'save_alert_recipients') {
            return $this->saveAlertRecipients($payload);
        }
        if ($action === 'download_root_certificate') {
            return $this->downloadRootCertificate($payload);
        }
        throw new \RuntimeException($this->framework->tt('pki_invalid_request'));
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

    public function redcap_pdf_finalize(
        string $temporaryPdfPath,
        array $operation,
        array $context
    ): \ExternalModules\PdfFinalizeResult {
        return (new PdfFinalizeService($this->framework))->finalize($temporaryPdfPath, $operation, $context);
    }
}
