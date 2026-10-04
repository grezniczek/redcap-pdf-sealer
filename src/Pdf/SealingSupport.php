<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pdf;

use ExternalModules\PdfFinalize;

/** Feature presence only; project assignment and PKI health are inspected separately. */
final class SealingSupport
{
    /** @return array{core: bool, framework: bool, supported: bool} */
    public static function inspect(): array
    {
        $core = defined('\PdfFinalizer::CONTRACT_VERSION') && \PdfFinalizer::CONTRACT_VERSION >= 1;
        $framework = defined(PdfFinalize::class . '::CONTRACT_VERSION') && PdfFinalize::CONTRACT_VERSION >= 1;
        return ['core' => $core, 'framework' => $framework, 'supported' => $core && $framework];
    }
}
