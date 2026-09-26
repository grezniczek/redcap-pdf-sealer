# PDF Sealer

A reference implementation in progress for cryptographically sealing REDCap-generated PDFs through the `redcap_module_pdf_finalize` hook.

The module declares one terminal `seal` operation for e-Consent PDFs. If the source page contains a direct PDF Link annotation, the signing revision preserves its clickable action in an indirect object before adding the invisible signature widget; the original PDF bytes remain intact. When it is assigned to a project PDF finalization pipeline and the PKI is ready, the hook seals the Framework working copy and returns a terminal modified result. The Framework keeps the prior PDF on failure.

The standalone RFC 3161 timestamp responder, PKI components, and PDF seal builder are under `src/`. After `composer install`, run the standalone checks (`tests/pdf_structure.php` requires `qpdf`; the PDF seal tests also require `pdfsig` on `PATH`):

```sh
php tests/timestamp_spike.php
php tests/pki_primitives.php
php tests/pki_storage.php
php tests/pki_initialization.php
php tests/project_identity.php
php tests/admin_alarms.php
php tests/pki_admin_ajax.php
php tests/pki_diagnostic.php
php tests/public_trust.php
php tests/project_trust_link.php
php tests/project_pipeline_status.php
php tests/pdf_structure.php
php tests/pdf_seal_bb.php
php tests/pdf_seal_bt.php
```

A repeatable synthetic consent/attachment suite uses the installed REDCap PDF backend and footer method without bootstrapping REDCap or accessing project data:

```sh
PDF_SEALER_REDCAP_ROOT=/home/gr/redcap/codebase php tests/pdf_redcap_fixtures.php
```

It requires PHP GD, qpdf, OpenSSL, and Poppler's `pdfsig`, `pdfimages`, `pdftoppm`, and `pdftotext`. Five fixtures cover transparent signature images, multiple pages, footer links enabled/disabled, a landscape attachment merged with qpdf, and rotated pages in compressed object streams. Both B-B and B-T undergo independent signature/timestamp checks; all pages must render identically at 72 dpi and retain their text and footer links. Temporary PDFs and synthetic keys are discarded. This is backend/structural coverage, not a complete eConsent workflow or a REDCap merge-path test. See [fixture coverage](DEV_DOCS/pdf_fixture_coverage.md) for limits and remaining acceptance checks.

To check a REDCap-generated PDF without adding its bytes to the repository, export it to a local file and run `PDF_SEALER_REDCAP_PDF_PATH=/absolute/path/to/exported.pdf php tests/pdf_structure.php`, `PDF_SEALER_REDCAP_PDF_PATH=/absolute/path/to/exported.pdf php tests/pdf_seal_bb.php`, or the same command with `tests/pdf_seal_bt.php`. The seal tests sign in memory with disposable test certificates, then check the detached CMS and root chain with OpenSSL. The B-T test also validates the RFC 3161 response with OpenSSL. All PDF tests use `qpdf`; the seal tests also use Poppler `pdfsig` to confirm PDF signature recognition, signed ranges, and full-document coverage. Its `-nocert` option skips trust validation for the disposable test root; OpenSSL verifies that chain separately.

The superuser Control Center **PDF Sealer PKI** page provides explicit, one-time root and TSA initialization with a GET redirect after submission. Administrators configure PKI alarm email recipients and download the active public root certificate in PEM or DER there through JSMO AJAX requests. Downloading the self-signed root does not automatically make it trusted by PDF viewers. A public trust page, linked from the PKI page, lists the current and historical public roots with fingerprints and PEM/DER downloads at the configured survey URL (`/surveys/?pdf_sealer_certs` on a standard installation). The survey endpoint also handles the page's JSMO downloads, so public API access is not required.

The PKI page also provides **Run diagnostic self-test** through a superuser-only JSMO AJAX action. It checks stored root/TSA keys, an in-memory encryption round-trip, temporary signer issuance, the RFC 3161 responder, and B-B/B-T sealing with local structure and cryptographic checks. Each check reports passed, failed, or skipped. Both profiles are tested regardless of the saved timestamp mode, using the configured or built-in TSA policy, with no fallback hiding a failed B-T check. The test saves no identities or PDFs, changes no settings, and produces no project sealing logs or alarm emails. It does not validate project pipeline assignment, existing PDFs, viewer trust, revocation/LTV, or full PAdES compliance.

In projects where this EM is enabled, the project menu shows a trust certificate link to every signed-in project user by default. A project configuration checkbox hides it for that project. The link opens the public survey page in a new tab.

The **PDF Sealer status** project page is available to administrators and users with Project Design rights. It shows sealing operation assignment and pipeline warnings, instance PKI readiness, the project seal UUID, and certificate subject, SHA-256 fingerprint, and UTC validity dates. Unissued, incomplete, expired, and unusable identities have distinct messages. Viewing the page never issues or repairs certificates or writes sealing logs. The page directs users to REDCap Logging for actual sealing outcomes; it does not validate existing PDFs.

The PDF finalization hook uses the project sealing identity and in-process TSA for PAdES B-T. It uses the built-in PDF Sealer TSA Policy v1 (`2.25.186172099785128831488612506224552954430`) when `tsa_policy_oid` is unset, so a healthy TSA produces B-T by default. An explicit `tsa_policy_oid` overrides it. B-B fallback applies when timestamping fails or the TSA is unavailable; set `timestamp_mode` to `none` for B-B only, or `bb_fallback` to `0` to make timestamp failures fail the operation. Administrators can change timestamp mode and B-B fallback on the Control Center **PDF Sealer PKI** page. Both values save together through JSMO AJAX and apply to future sealing operations across projects. Unset settings retain the defaults: internal timestamping with B-B fallback enabled. The optional `tsa_policy_oid` override still has no UI control. Disabling fallback fails the sealing operation if timestamping fails; the Framework still retains the preceding PDF for storage or delivery.

Seal successes and failures appear in the project's standard REDCap Logging page for users with the Logging right. The entry shows a brief outcome and profile or fallback status; when the PDF context has a record and event ID, REDCap associates the entry with them. A failure includes the generation ID as a reference to a detailed, system-scoped `seal_event` EM log entry. Invalid project context is recorded only in the EM log.

On a disposable REDCap development instance, run `PDF_SEALER_LIVE_TEST=1 php tests/pki_live_framework.php` to verify Framework storage, the PDF finalization hook, project Logging, failure diagnostics, and alarm throttling. Its records and settings are rolled back, and its alarm sender is mocked. See [implementation status](DEV_DOCS/implementation_status.md) for completed work and remaining integration.
