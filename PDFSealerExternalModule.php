<?php

namespace DE\RUB\PDFSealerExternalModule;

use DE\RUB\PDFSealerExternalModule\Alerts\AdminAlarmService;
use DE\RUB\PDFSealerExternalModule\Pdf\PdfFinalizeService;

require_once __DIR__ . '/vendor/autoload.php';

class PDFSealerExternalModule extends \ExternalModules\AbstractExternalModule
{
    /** @return array{ok: bool, message?: string, recipients?: string} */
    public function redcap_module_ajax($action, $payload, $project_id): array
    {
        if ($action !== 'save_alert_recipients'
            || !$this->framework->isSuperUser()
            || $project_id !== null
            || $this->framework->getProjectId() !== null) {
            throw new \RuntimeException($this->framework->tt('pki_access_denied'));
        }
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

    public function redcap_pdf_finalize(
        string $temporaryPdfPath,
        array $operation,
        array $context
    ): \ExternalModules\PdfFinalizeResult {
        return (new PdfFinalizeService($this->framework))->finalize($temporaryPdfPath, $operation, $context);
    }
}
