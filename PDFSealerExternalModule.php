<?php

namespace DE\RUB\PDFSealerExternalModule;

class PDFSealerExternalModule extends \ExternalModules\AbstractExternalModule
{
	public function redcap_pdf_finalize(
		string $temporaryPdfPath,
		array $operation,
		array $context
	): \ExternalModules\PdfFinalizeResult {
		return \ExternalModules\PdfFinalizeResult::unchanged();
	}
}
