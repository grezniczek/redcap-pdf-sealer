# Development and testing

These commands are for development checkouts. For installation and normal operation, use the [administrator guide](../docs/ADMIN.md). Return to the [developer index](README.md) for acceptance records and packaging.

## Setup and standalone checks

Keep the development checkout named `pdf_sealer_v9.9.9`; this directory convention is independent of published release metadata. Use a Core/Framework checkout with PDF finalization support. Run the commands below from the module root directory. The committed `libraries/` tree and module-owned `autoload.php` are sufficient to run the module and tests. Composer manifests are development-only inputs for rebuilding those libraries; see [release licensing and dependency builds](release_licensing.md). Do not install or upgrade dependencies merely to run a test.

The standalone RFC 3161 timestamp responder, PKI components, and PDF seal builder are under `src/`. Run the standalone checks (`tests/pdf_structure.php` requires `qpdf`; the PDF seal tests also require `pdfsig` on `PATH`):

```sh
php tests/dependency_isolation.php
php tests/timestamp_spike.php
php tests/certificate_serials.php
php tests/pki_primitives.php
php tests/providers.php
php tests/pki_storage.php
php tests/pki_initialization.php
php tests/project_identity.php
php tests/admin_alarms.php
php tests/expiry_monitor.php
php tests/pki_admin_ajax.php
php tests/pki_diagnostic.php
php tests/diagnostic_snapshot.php
php tests/public_trust.php
php tests/project_trust_link.php
php tests/project_pipeline_status.php
php tests/pdf_structure.php
php tests/pdf_seal_bb.php
php tests/pdf_seal_bt.php
```

Run the issuer, initialization, and diagnostic suites on both PHP 8.2/8.3 and PHP 8.4+ when changing serial allocation. Standalone tests use disposable synthetic identities and fake Framework storage; `tests/certificate_serials.php` checks the real OpenSSL serial output and integer bounds, while initialization checks unused reservations across rollback/retry. The production allocator uses Framework log inserts; it never commits a caller transaction. The rollback-only live harness therefore also rolls back any reservation rows and must never export its temporary certificates or signed PDFs.

A repeatable synthetic consent/attachment suite uses the installed REDCap PDF backend and footer method without bootstrapping REDCap or accessing project data:

```sh
PDF_SEALER_REDCAP_ROOT=/home/gr/redcap/codebase php tests/pdf_redcap_fixtures.php
```

To retain a manual-validation bundle, append `--export-dir /absolute/new-directory`. This exports the ten verified PDFs, a disposable public root, a hash manifest, and the [Acrobat/DSS checklist](pdf_manual_validation.md). Private keys are never exported. Existing output directories are refused; an incomplete run removes its exports.

Sealing externalizes inline links on every page and preserves the latest input revision's cross-reference format (table or stream), while retaining the complete original byte prefix. The [fixture acceptance notes](pdf_fixture_coverage.md) track Acrobat findings separately from local cryptographic verification.

It requires PHP GD, qpdf, OpenSSL, and Poppler's `pdfsig`, `pdfimages`, `pdftoppm`, and `pdftotext`. Five fixtures cover transparent signature images, multiple pages, footer links enabled/disabled, a landscape attachment merged with qpdf, and rotated pages in compressed object streams. Both B-B and B-T undergo independent signature/timestamp checks; all pages must render identically at 72 dpi and retain their text and footer links. Temporary PDFs and synthetic keys are discarded. This is backend/structural coverage, not a complete eConsent workflow or a REDCap merge-path test. See [fixture coverage](pdf_fixture_coverage.md) for limits and remaining acceptance checks.

To check a REDCap-generated PDF without adding its bytes to the repository, export it to a local file and run `PDF_SEALER_REDCAP_PDF_PATH=/absolute/path/to/exported.pdf php tests/pdf_structure.php`, `PDF_SEALER_REDCAP_PDF_PATH=/absolute/path/to/exported.pdf php tests/pdf_seal_bb.php`, or the same command with `tests/pdf_seal_bt.php`. The seal tests sign in memory with disposable test certificates, then check the detached CMS and root chain with OpenSSL. The B-T test also validates the RFC 3161 response with OpenSSL. All PDF tests use `qpdf`; the seal tests also use Poppler `pdfsig` to confirm PDF signature recognition, signed ranges, and full-document coverage. Its `-nocert` option skips trust validation for the disposable test root; OpenSSL verifies that chain separately.

## Live development-instance checks

The following runners bootstrap REDCap and exercise real storage or dispatch. Use only a disposable development instance and synthetic project. Read the runner's preconditions; do not substitute a production project. The direct-hook runner requires uninitialized PKI, while the pipeline runner requires an initialized project and existing signer.

On a disposable REDCap development instance, run `PDF_SEALER_LIVE_TEST=1 php tests/pki_live_framework.php` to verify Framework storage, the PDF finalization hook, project Logging, failure diagnostics, and alarm throttling. Its records and settings are rolled back, and its alarm sender is mocked. See [implementation status](implementation_status.md) for current results and historical implementation notes.

To test the actual Core/Framework dispatch on an already initialized development project, use the preview-first harness:

```sh
PDF_SEALER_LIVE_TEST=1 PDF_SEALER_TEST_PID=461 php tests/pdf_pipeline_live.php --preview
PDF_SEALER_LIVE_TEST=1 PDF_SEALER_TEST_PID=461 php tests/pdf_pipeline_live.php --run
```

It requires the existing project signer, healthy PKI, and a pipeline containing only `pdf_sealer:seal`. It tests five synthetic fixtures through both Core entry points using the configured timestamp mode, checks terminal adoption and final hashes, and covers document-type bypass and rejection of an already-certified PDF. It does not change settings or write edocs. Autocommit stays disabled because the Framework rolls back at each hook boundary; test log writes and project activity updates are rolled back. This differs from the older direct-hook harness, which requires an uninitialized PKI and cannot establish real dispatch behavior. See the [live acceptance guide](pdf_pipeline_acceptance.md) for the separate stored/downloaded-PDF check.

### Expiry monitoring

`tests/expiry_monitor.php` uses disposable certificates and fake storage/mail to verify active identity selection, retained issuers, threshold boundaries, malformed/missing certificates, failed scans, summary escalation/throttling/retry, and the 50-row display limit without truncating counts. No email is sent.

For the real Framework inventory query and persisted CC snapshot:

```sh
PDF_SEALER_LIVE_TEST=1 php tests/expiry_monitor_live.php --preview
PDF_SEALER_LIVE_TEST=1 php tests/expiry_monitor_live.php --run
```

Preview is read-only. Run requires a wholly healthy inventory, saves the real scan snapshot, checks unchanged public identities/bindings, and blocks email transport. The normal cron uses the real alarm service. Do not alter live certificate dates to test thresholds; use the standalone tests. Adding a cron to the unchanged development version requires registration separately from this runner.

## Independent viewer checks

Use [manual validation](pdf_manual_validation.md) when a change warrants new Acrobat/DSS evidence. Prefer the disposable fixture bundle. The [selected interoperability round](pdf_interop_acceptance.md) is already complete; there is no outstanding request to repeat it. Do not upload real consent PDFs to a public validator as part of this development procedure.

## Documentation and packaging checks

Run `php tools/third-party-notices.php` for dependency attribution checks. Follow [release licensing](release_licensing.md) when staging a ZIP; The committed bundle is included in Git archives; Composer manifests, `vendor/`, and development tooling are excluded. Confirm that the root primer and `docs/` are included, all configured documentation paths exist, and `DEV_DOCS/`, tests, tools, and fixtures are excluded.

Documentation routing and relative Markdown navigation should also be checked in REDCap's project and Control Center documentation views. No live database or PKI mutation is needed for a documentation check.

### External CA registration

Run `RANDFILE=/tmp/pdf-sealer-test-random php -d xdebug.mode=off tests/external_providers.php` (also with `php8.2`). This uses fake Framework persistence with real OpenSSL chain validation; no live settings, mail, or keys are changed. It covers registration/assignment transactions, public certificate integrity, explicit timestamp policy, and rejection of local issuance for pending external assignments.

Add `--fixture` to write a disposable public chain to ignored `DEV_DOCS/interop-artifacts/external-ca-registration-test.pem` for the CC upload check. Its private key is discarded, so do not use that provider for an enrollment acceptance test. The CC AJAX authorization cases are in `tests/pki_admin_ajax.php`.
