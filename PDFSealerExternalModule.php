<?php

namespace DE\RUB\PDFSealerExternalModule;

use DE\RUB\PDFSealerExternalModule\Pdf\PdfFinalizeService;

require_once __DIR__ . '/vendor/autoload.php';

class PDFSealerExternalModule extends \ExternalModules\AbstractExternalModule
{
    public function redcap_pdf_finalize(
        string $temporaryPdfPath,
        array $operation,
        array $context
    ): \ExternalModules\PdfFinalizeResult {
        return (new PdfFinalizeService($this->framework))->finalize($temporaryPdfPath, $operation, $context);
    }
}
