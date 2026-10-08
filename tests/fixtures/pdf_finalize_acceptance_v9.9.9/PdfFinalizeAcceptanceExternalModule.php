<?php

declare(strict_types=1);

namespace DE\RUB\PdfFinalizeAcceptanceExternalModule;

use Vanderbilt\REDCap\Classes\PdfFinalization\PdfFinalizeResult;

final class PdfFinalizeAcceptanceExternalModule extends \ExternalModules\AbstractExternalModule
{
    public function redcap_module_pdf_finalize(string $workingPdfPath, array $operation, array $context): PdfFinalizeResult
    {
        return PdfFinalizeResult::unchanged(false);
    }
}
