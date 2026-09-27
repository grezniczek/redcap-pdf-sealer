# Development and testing

These commands are for development checkouts. For installation and normal operation, use the [administrator guide](../docs/ADMIN.md). Return to the [developer index](README.md) for acceptance records and packaging.

## Setup and standalone checks

Keep the development checkout named `pdf_sealer_v9.9.9`; this directory convention is independent of published release metadata. Use a Core/Framework checkout with PDF finalization support. Run the commands below from the module root directory. The committed `libraries/` tree and module-owned `autoload.php` are sufficient to run the module and tests. Composer manifests are development-only inputs for rebuilding those libraries; see [release licensing and dependency builds](release_licensing.md). Do not install or upgrade dependencies merely to run a test.

The standalone RFC 3161 timestamp responder, PKI components, and PDF seal builder are under `src/`. Run the standalone checks (`tests/pdf_structure.php` requires `qpdf`; the PDF seal tests also require `pdfsig` on `PATH`):

```sh
php tests/dependency_isolation.php
php tests/timestamp_spike.php
php tests/external_timestamp.php
php tests/timestamp_transport.php
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

## External TSA foundation

`tests/external_timestamp.php` uses disposable synthetic PKI and injected responders, including OpenSSL's independent `ts -reply` with fractional `genTime`. It checks separate document/TSA roots, an issuing intermediate (including a response that omits the intermediate), default/explicit policies including large UUID arcs, wrong policy/imprint/nonce/purpose/trust, root injection, stale and tampered tokens, malformed/oversized bodies and transport failure. Complete PDFs pass qpdf/pdfsig/CMS checks and their embedded timestamps are independently verified with OpenSSL against the actual CMS signature bytes. Temporary keys/certificates/PDFs are removed; no REDCap data or live service is used.

`tests/timestamp_transport.php` checks HTTPS URL/auth validation, 3-second connection/10-second request bounds, 64 KiB response cap passed to the Core helper, redirect/compression refusal, content type/status handling and secret-safe errors. It uses an HTTP test double and the real Core `ResponseByteLimit` class. Set `PDF_SEALER_REDCAP_ROOT` if Core is elsewhere. This test does not exercise real network streaming, TLS certificates, proxy authentication or timeouts; those require endpoint acceptance after CC integration. The production transport depends on `HttpClient::requestWithResponseLimit` and fails closed if that helper is unavailable.

Both suites and the existing nine-fixture `tests/pdf_seal_bt.php` (including B-B checks) passed on PHP 8.2 and 8.5. The internal timestamp spike also passed on PHP 8.5. External sources are not selectable yet; no browser intervention is needed for this foundation slice. Future source registration must encrypt credentials, audit changes without secrets, and expose explicit per-source diagnostic results. Alternative-source and B-B orchestration remain outside these tests.

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

### Explicit-assignment policy

`tests/project_identity.php` covers gate-on refusal without writes, explicit built-in assignment, reuse of existing signers, malformed policy, policy-save-before-issuance, and attempted saves during binding/certificate/activation writes. These are deterministic lock interleavings with fake database callbacks, not a live multi-connection load test. `tests/external_providers.php` checks policy audit rollback and independence from CA count. `tests/pki_admin_ajax.php` checks authenticated dispatch and invalid payloads.

`php -d xdebug.mode=off tests/assignment_gate_finalize.php` uses the real finalizer and Framework result class with fake database/Logging boundaries. It verifies `CA_ASSIGNMENT_REQUIRED`, unchanged PDF bytes, record/event context, no certificate or alarm writes, and released locks. Set `PDF_SEALER_FRAMEWORK_ROOT` if the Framework is not at the local development default.

Browser acceptance: save the gate on, refresh to confirm persistence, and use an unbound test project. Its page should say **CA assignment required**. A completed eConsent should still produce the preceding unsealed PDF and the explicit project failure log. Then explicitly assign the built-in CA and test a subsequent eConsent; it should seal. Existing bound projects must continue sealing with the gate on. Restore the desired policy afterward. No live toggles were performed by the standalone suites.

The user confirmed the assignment-gate browser checks passed on 2026-09-27; see [the acceptance note](implementation_status.md#explicit-ca-assignment-gate--2026-09-27). The procedure above remains a reference for future changes, not a request to repeat the completed checks.

### Local project key and CSR preparation

Run `RANDFILE=/tmp/pdf-sealer-enrollment-random php -d xdebug.mode=off tests/project_enrollment.php` (also with `php8.2`). This reuses the external-provider fake persistence/fixtures and uses real OpenSSL keys/CSRs, with independent `openssl req -verify -text` checks. No live database writes occur. `tests/pki_admin_ajax.php` exercises project action authorization and malformed payloads.

Browser check: in a test project assigned to an external provider, generate/download the CSR, refresh and download again (same file SHA-256), cancel after confirmation, and generate again (different CSR). Verify pending metadata persists and the project remains awaiting a certificate; generating a CSR must not enable sealing. An ordinary user without design rights must not gain access. The registration fixture provider works for this check, but its discarded CA key prevents issued-certificate testing later. No private key should be offered as a download.

### External certificate activation and sealing

Run `RANDFILE=/tmp/pdf-sealer-activation-random php -d xdebug.mode=off tests/external_activation.php` (also with `php8.2`). It extends the fake-persistence enrollment fixtures with real root/intermediate/leaf certificates, exercises validation/rollback/replacement, and runs the actual finalizer and sample verifier for B-B/B-T with a separate TSA chain. No live settings or identity writes occur.

For browser acceptance, `tools/external_ca_fixture.php --create` prepares an explicitly disposable CA under ignored `DEV_DOCS/interop-artifacts/external-ca-acceptance/`. It retains only the test issuing CA's private key locally with owner-only permissions so it can issue responses; never upload that key or use this fixture in production. The helper is excluded from packages. The public `chain.pem` is ready to register as a **new** provider; the earlier registration-only fixture cannot issue responses.

1. Register the new public `chain.pem`, choose timestamp policy, then assign a fresh unbound test project to it. Use a fresh project for this first-enrollment procedure; existing assignments use the separate transition workflow.
2. Generate/download that project's CSR.
3. Run `RANDFILE=/tmp/pdf-sealer-fixture-random php -d xdebug.mode=off tools/external_ca_fixture.php --sign /path/to/downloaded.csr`. Windows Downloads paths are accessible under `/mnt/c/Users/grezn/Downloads/` on this instance. The command prints the returned certificate path.
4. Upload the returned PEM, validate/review, activate, and confirm ready status. A mismatched certificate should leave the pending request unchanged.
5. Complete a new eConsent and check project Logging and Acrobat. For manual replacement, prepare another CSR while the current signer remains usable, then activate the returned replacement.

The helper only handles local disposable test CA files and public CSRs/certificates. It does not access REDCap database, pending project keys, or live issuer keys.

First external enrollment/sealing acceptance passed on **2026-09-27**: the supplied project CSR was signed with the disposable issuing CA, the returned certificate chain verified with OpenSSL, and the user reported Acrobat acceptance of the resulting PDF. See [the acceptance record](implementation_status.md#first-external-ca-pdf-acceptance--passed). This does not record separate browser acceptance of replacement or rejection cases. The procedure above remains available for regression checks.

### CA retirement and reactivation

Run `RANDFILE=/tmp/pdf-sealer-retirement-random php -d xdebug.mode=off tests/provider_retirement.php` (also with `php8.2`). The suite reuses disposable activation fixtures and fake persistence. It checks public-only impact review, stale state/usage rejection, rollback including default/gate changes, blocked enrollment with retained pending keys, cancellation, public chain retention, expiry inventory, reactivation, and deterministic lock interleavings during assignment, CSR generation, activation, and built-in issuance. It cryptographically verifies a real finalizer B-T output with both the external project CA and built-in TSA's CA retired, and checks retired first-issuance failure without PDF mutation. This is not a live multi-connection load test. AJAX authorization/configuration/malformed-payload coverage is in `tests/pki_admin_ajax.php`.

Verification on 2026-09-27: retirement and diagnostic suites passed on PHP 8.2 and 8.5. Project identity, expiry, public trust, provider configuration, AJAX authorization, and assignment-gate finalizer regressions passed on PHP 8.5. Changed PHP files passed PHP 8.2 lint; page JavaScript syntax, JSON/INI parsing, language keys, and diff checks passed.

The user confirmed that the browser retirement/reactivation test passes. Separate results for the additional pending-request and built-in-default cases were not reported. The procedure remains below for regression checks:

1. In the working external test project, optionally generate a replacement CSR and keep the page open. On CC **CA providers**, select **Retire CA**, review the PID/signer/pending counts, and confirm.
2. Confirm the retired badge, exclusion from assignment choices, project retirement notice, and retained public certificate downloads. A pending CSR remains downloadable/cancelable; new CSR generation and activation fail, including from stale pages.
3. Complete a new eConsent in the project with an already active signer: Acrobat should still accept the seal.
4. Select **Reactivate CA**, review and confirm. Enrollment becomes available again; the assignment policy remains unchanged.
5. For the built-in default, retirement with the assignment gate off must require the explicit gate checkbox. After confirmation, the gate is on and cannot be turned off until reactivation. The existing TSA remains operational. A new diagnostic should report failed temporary signer issuance and skipped sealing checks, as explained in the page. Reactivate afterward and restore the desired assignment policy explicitly.

No live retirements or provider transitions were performed by the automated suite. Existing bindings can move through the separately tested controlled provider transition workflow. These checks do not claim certificate revocation or an emergency stop for existing signing.

### Controlled project provider transitions

`tests/pki_admin_ajax.php` also exercises a successful `preview_provider_transition` dispatch, including service construction, public binding reads, and lock release without writes. This caught the missing `PkiHealthService` import that caused the initial PID 529 browser review failure; the regression passes on PHP 8.2 and 8.5 after the fix.

Run `RANDFILE=/tmp/pdf-sealer-transition-random php -d xdebug.mode=off tests/provider_transitions.php` (also with `php8.2`). The suite uses real disposable crypto and fake persistence: current signer/UUID/history preservation, builtin/external target activation, pending cancellation and rollback, stale reviews, retirement on either side, one pending operation, no-signer transitions, and lock interleavings. The actual finalizer and sample verifier check that B-B remains effective during preparation and B-T becomes effective only after activation. Transition tests passed on PHP 8.2 and 8.5. Existing retirement, project identity, storage, and AJAX regression suites passed on PHP 8.5. Changed PHP files passed PHP 8.2 lint; page JavaScript syntax, JSON/INI parsing, language keys, packaged-guide links, and diff checks also passed. No live data is changed.

The user confirmed review works after the fix, supplied a new CSR, and reported “Looking good” after receiving the signed certificate and activation/PDF-check instructions. This is positive workflow feedback; no separate cancellation result or Acrobat diagnostic was reported, and no resulting PDF was supplied for independent inspection.

Browser regression procedure (cancellation remains unconfirmed):

1. In CC **CA providers → Change project provider**, review the working external test project. Select the built-in CA and **Issue and activate built-in replacement**. Confirm the project retains its UUID, shows the new certificate/provider, and a new eConsent PDF is accepted in Acrobat.
2. Prepare a change back to the disposable external CA. The project should show its current built-in signer and the external replacement provider. Generate/download a fresh CSR; the current signer must remain usable before activation. Supply the CSR path for signing with the existing disposable CA helper, then upload/review/activate its returned certificate. Confirm another eConsent PDF is accepted and the provider switches only on activation.
3. Prepare another external transition with a pending CSR, review it in CC, and cancel. The pending request/key should disappear, the prior signer should remain, and an old returned certificate should not activate. Canceling only a CSR on the project page should instead retain the pending target. Restore the desired provider afterward through the normal transition workflow.

The automated suite also covers retired targets, no-signer projects, and stale pages; these are not separate browser acceptance claims. Existing PDFs, default provider, and assignment policy must remain unchanged. A transition can change the effective timestamp policy, so note each provider's policy when checking output.
