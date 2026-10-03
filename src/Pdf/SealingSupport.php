<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pdf;

use ExternalModules\PdfFinalize;
use Vanderbilt\REDCap\Classes\Settings\ProjectSettingKeys;

/** Feature presence only; project assignment and PKI health are inspected separately. */
final class SealingSupport
{
    /** @return array{core: bool, framework: bool, supported: bool} */
    public static function inspect(): array
    {
        $core = defined(ProjectSettingKeys::class . '::EXTERNAL_MODULES_PDF_FINALIZE_EXECUTION_PLAN');
        $framework = class_exists(PdfFinalize::class);
        return ['core' => $core, 'framework' => $framework, 'supported' => $core && $framework];
    }
}
