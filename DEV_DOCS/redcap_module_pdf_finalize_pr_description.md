# PR: Add a generic PDF finalization pipeline

## Summary

Introduce `redcap_module_pdf_finalize` so External Modules can apply ordered, final byte-level processing to REDCap-generated PDFs containing record data. Finalization runs after ordinary PDF construction and before REDCap stores, hashes, emails, archives, returns, or downloads the PDF. Core then uses the resulting bytes unchanged. The hook is purpose-neutral: watermarking, PDF/A conversion, metadata normalization, and sealing are module concerns, not Core behavior.

## Implementation

Modules declare named operations under `pdf-finalize` in `config.json` (`id`, `purpose`, `document_types`, `is_terminal`). Each project persists an explicit ordered plan of `module_prefix:operation_id` entries; duplicate use is supported. The project UI manages assignment and order, retains unavailable entries with warnings, and requires an explicit placement decision when enabling a module would introduce ambiguous ordering.

For each matching entry, the Framework calls
`redcap_module_pdf_finalize(string $temporary_pdf_path, array $operation, array $context): \ExternalModules\PdfFinalizeResult`
on an isolated working copy. `modified` adopts a validated PDF; `unchanged` preserves the current bytes; `failed`, exceptions, and invalid results discard only that operation's copy and allow later entries to run. Processing stops only after an accepted terminal result from an operation declared terminal.

Core supplies `econsent`, `survey_pdf`, or `record_pdf` context with record/event/instrument, generation reason, delivery target, and a correlation ID. Integration covers PDF snapshots (repository and file fields), generated survey-confirmation attachments, browser and API exports, `REDCap::getPDF()`, all-record exports, and record-locking archives. Blank forms bypass the pipeline. Snapshot and email callers retain their more specific finalization point, avoiding double invocation. Projects without an assigned plan retain their existing PDF output path.

Pipeline and artifact events share a generation ID and record final/delivered hashes for auditability.

## Verification

Focused Core and Framework tests cover byte-changing output, context, ordering, result validation, failure isolation, terminal behavior, persistence, and enablement. Live testing in PID 461 used a demo watermark operation to confirm finalized bytes across e-Consent and survey snapshots, file fields, repository storage, email attachments, browser downloads, record PDFs, all-record exports, and the API. A production cryptographic sealer is outside this PR's scope.
