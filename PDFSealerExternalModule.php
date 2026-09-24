<?php

namespace DE\RUB\PDFSealerExternalModule;

class PDFSealerExternalModule extends \ExternalModules\AbstractExternalModule
{
	public function redcap_pdf_finalize(
		string $temporaryPdfPath,
		array $operation,
		array $context
	): \ExternalModules\PdfFinalizeResult {
		if (($operation['id'] ?? null) !== 'watermark') {
			return \ExternalModules\PdfFinalizeResult::unchanged();
		}
		if (!function_exists('exec')) {
			return \ExternalModules\PdfFinalizeResult::failed('ghostscript_unavailable');
		}

		$outputPath = @tempnam(dirname($temporaryPdfPath), 'pdf_sealer_');
		if ($outputPath === false) {
			return \ExternalModules\PdfFinalizeResult::failed('temporary_file_failed');
		}

		try {
			// EndPage's reason 0 is an actual page; other reasons must not emit extra pages.
			$watermark = '<< /EndPage { exch pop 0 eq dup { gsave /Helvetica-Bold findfont 16 scalefont setfont 0.4 setgray 36 36 moveto (FINALIZER TEST) show grestore } if } >> setpagedevice';
			$command = 'gs -q -dSAFER -dBATCH -dNOPAUSE -sDEVICE=pdfwrite '
				. '-sOutputFile=' . escapeshellarg($outputPath) . ' '
				. '-c ' . escapeshellarg($watermark) . ' '
				. '-f ' . escapeshellarg($temporaryPdfPath) . ' 2>&1';
			$output = [];
			$exitCode = 1;
			@exec($command, $output, $exitCode);
			if ($exitCode !== 0 || !is_file($outputPath) || filesize($outputPath) === 0) {
				return \ExternalModules\PdfFinalizeResult::failed('watermark_render_failed');
			}
			if (!@copy($outputPath, $temporaryPdfPath)) {
				return \ExternalModules\PdfFinalizeResult::failed('watermark_copy_failed');
			}

			return \ExternalModules\PdfFinalizeResult::modified($temporaryPdfPath);
		} finally {
			@unlink($outputPath);
		}
	}
}
