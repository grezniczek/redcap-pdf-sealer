# PDF Sealer

A reference implementation for REDCap PDF sealing.

The `watermark` operation adds a visible `FINALIZER TEST` mark to each page using Ghostscript and returns the modified PDF. Ghostscript (`gs`) must be available to the PHP process; if rendering fails, the operation reports a controlled failure and the Framework retains the previous PDF. The `seal` operation remains a placeholder and returns unchanged.

This watermark is for acceptance testing only. Ghostscript rewrites the PDF and may not preserve all advanced PDF features; do not treat this module as a production document sealer.

## Implementation status

The new sealer is being built in stages. A standalone RFC 3161 responder and the root/TSA/project certificate-generation primitives are available under `src/`; the REDCap `seal` operation is still a placeholder. Run `composer install`, then `php tests/timestamp_spike.php` and `php tests/pki_primitives.php` to exercise the current independent checks. See [implementation status](DEV_DOCS/implementation_status.md) for the completed gate and remaining work.
