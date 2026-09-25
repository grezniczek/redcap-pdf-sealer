# PDF Sealer

A reference implementation in progress for cryptographically sealing REDCap-generated PDFs through the `redcap_pdf_finalize` hook.

The module declares one terminal `seal` operation for e-Consent PDFs. When it is assigned to a project PDF finalization pipeline and the PKI is ready, the hook seals the Framework working copy and returns a terminal modified result. The Framework keeps the prior PDF on failure.

The standalone RFC 3161 timestamp responder, PKI components, and PDF seal builder are under `src/`. After `composer install`, run the standalone checks (`tests/pdf_structure.php` requires `qpdf`; the PDF seal tests also require `pdfsig` on `PATH`):

```sh
php tests/timestamp_spike.php
php tests/pki_primitives.php
php tests/pki_storage.php
php tests/pki_initialization.php
php tests/project_identity.php
php tests/admin_alarms.php
php tests/pdf_structure.php
php tests/pdf_seal_bb.php
php tests/pdf_seal_bt.php
```

To check a REDCap-generated PDF without adding its bytes to the repository, export it to a local file and run `PDF_SEALER_REDCAP_PDF_PATH=/absolute/path/to/exported.pdf php tests/pdf_structure.php`, `PDF_SEALER_REDCAP_PDF_PATH=/absolute/path/to/exported.pdf php tests/pdf_seal_bb.php`, or the same command with `tests/pdf_seal_bt.php`. The seal tests sign in memory with disposable test certificates, then check the detached CMS and root chain with OpenSSL. The B-T test also validates the RFC 3161 response with OpenSSL. All PDF tests use `qpdf`; the seal tests also use Poppler `pdfsig` to confirm PDF signature recognition, signed ranges, and full-document coverage. Its `-nocert` option skips trust validation for the disposable test root; OpenSSL verifies that chain separately.

The superuser Control Center **PDF Seal PKI** page provides explicit, one-time root and TSA initialization. The PDF finalization hook uses the project sealing identity and in-process TSA for PAdES B-T. It uses the built-in PDF Sealer TSA Policy v1 (`2.25.186172099785128831488612506224552954430`) when `tsa_policy_oid` is unset, so a healthy TSA produces B-T by default. An explicit `tsa_policy_oid` overrides it. B-B fallback applies when timestamping fails or the TSA is unavailable; set `timestamp_mode` to `none` for B-B only, or `bb_fallback` to `0` to make timestamp failures fail the operation. These settings do not yet have UI controls.

Each e-Consent seal attempt appends a system-scoped `seal_event` with its Framework `generation_id`, project UUID and certificate identity when available, input/output SHA-256 digests, actual PAdES profile, timestamp details, fallback marker, and outcome. It stores no REDCap record ID or instrument. A successful PDF is rejected if its seal event cannot be written.

On a disposable REDCap development instance, run `PDF_SEALER_LIVE_TEST=1 php tests/pki_live_framework.php` to verify Framework storage, the PDF finalization hook, seal-event persistence, and alarm throttling. Its records and settings are rolled back, and its alarm sender is mocked. See [implementation status](DEV_DOCS/implementation_status.md) for completed work and remaining integration.
