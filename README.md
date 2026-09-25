# PDF Sealer

A reference implementation in progress for cryptographically sealing REDCap-generated PDFs through the `redcap_pdf_finalize` hook.

The module currently declares one terminal `seal` operation for e-Consent PDFs. The hook returns `unchanged` while sealing is being implemented, so enabling the operation does not yet alter PDFs.

The standalone RFC 3161 timestamp responder and root, TSA, and project certificate-generation primitives are under `src/`. Run `composer install`, then `php tests/timestamp_spike.php`, `php tests/pki_primitives.php`, and `php tests/pki_storage.php` to exercise the standalone checks. On a disposable REDCap development instance, run `PDF_SEALER_LIVE_TEST=1 php tests/pki_live_framework.php` to verify real Framework log storage and REDCap encryption; its test record is rolled back. See [implementation status](DEV_DOCS/implementation_status.md) for completed work and remaining integration.
