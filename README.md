# PDF Sealer

A reference implementation in progress for cryptographically sealing REDCap-generated PDFs through the `redcap_pdf_finalize` hook.

The module currently declares one terminal `seal` operation for e-Consent PDFs. The hook returns `unchanged` while sealing is being implemented, so enabling the operation does not yet alter PDFs.

The standalone RFC 3161 timestamp responder, PKI components, and PDF structural adapter are under `src/`. After `composer install`, run the standalone checks (`tests/pdf_structure.php` requires `qpdf`; `tests/pdf_seal_bb.php` also requires `pdfsig` on `PATH`):

```sh
php tests/timestamp_spike.php
php tests/pki_primitives.php
php tests/pki_storage.php
php tests/pki_initialization.php
php tests/project_identity.php
php tests/admin_alarms.php
php tests/pdf_structure.php
php tests/pdf_seal_bb.php
```

To check a REDCap-generated PDF without adding its bytes to the repository, export it to a local file and run `PDF_SEALER_REDCAP_PDF_PATH=/absolute/path/to/exported.pdf php tests/pdf_structure.php` or `PDF_SEALER_REDCAP_PDF_PATH=/absolute/path/to/exported.pdf php tests/pdf_seal_bb.php`. The latter signs in memory with disposable test certificates, then checks the detached CMS and root chain with OpenSSL. Both tests use `qpdf`; the seal test also uses Poppler `pdfsig` to confirm PDF signature recognition, signed ranges, and full-document coverage. Its `-nocert` option skips trust validation for the disposable test root; OpenSSL verifies that chain separately.

The superuser Control Center **PDF Seal PKI** page provides explicit, one-time root and TSA initialization. The standalone PDF builder can append a PAdES B-B certification seal, but it is not connected to the hook. The hook still returns `unchanged`.

On a disposable REDCap development instance, run `PDF_SEALER_LIVE_TEST=1 php tests/pki_live_framework.php` to verify real Framework storage and alarm throttling. Its records and settings are rolled back, and its sender is mocked. See [implementation status](DEV_DOCS/implementation_status.md) for completed work and remaining integration.
