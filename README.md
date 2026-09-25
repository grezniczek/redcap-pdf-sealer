# PDF Sealer

A reference implementation in progress for cryptographically sealing REDCap-generated PDFs through the `redcap_pdf_finalize` hook.

The module currently declares one terminal `seal` operation for e-Consent PDFs. The hook returns `unchanged` while sealing is being implemented, so enabling the operation does not yet alter PDFs.

The standalone RFC 3161 timestamp responder and root, TSA, and project certificate-generation primitives are under `src/`. Run `composer install`, then `php tests/timestamp_spike.php` and `php tests/pki_primitives.php` to exercise the current checks. See [implementation status](DEV_DOCS/implementation_status.md) for completed work and remaining integration.
