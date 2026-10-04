# Development and testing

These commands are for development checkouts. For installation and normal operation, use the [administrator guide](../docs/ADMIN.md). Return to the [developer index](README.md) for acceptance records and packaging.

## Setup and standalone checks

Keep the development checkout named `pdf_sealer_v9.9.9`; this directory convention is independent of published release metadata. Use a Core/Framework checkout with PDF finalization support. Run the commands below from the module root directory. The committed `libraries/` tree and module-owned `autoload.php` are sufficient to run the module and tests. Composer manifests are development-only inputs for rebuilding those libraries; see [release licensing and dependency builds](release_licensing.md). Do not install or upgrade dependencies merely to run a test.

The standalone RFC 3161 timestamp responder, PKI components, and PDF seal builder are under `src/`. Run the standalone checks (`tests/pdf_structure.php` requires `qpdf`; the PDF seal tests also require `pdfsig` on `PATH`):

```sh
php tests/dependency_isolation.php
php tests/timestamp_spike.php
php tests/external_timestamp.php
php tests/external_timestamp_settings.php
php tests/timestamp_transport.php
php tests/certificate_serials.php
php tests/pki_primitives.php
php tests/pki_lifecycle_prerequisites.php
php tests/providers.php
php tests/pki_storage.php
php tests/pki_initialization.php
php tests/crl.php
php tests/project_identity.php
php tests/project_renewal.php
php tests/project_revocation.php
php tests/builtin_maintenance.php
php tests/root_renewal.php
php tests/tsa_revocation.php
php tests/root_revocation.php
php tests/timestamp_alternatives.php
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

## Installation support notices

Run **tests/project_pipeline_status.php** on PHP 8.2 and the current PHP runtime. Its isolated processes check neither feature, Core only, Framework only and both, using the actual **views/sealing-support.php** notice. The Core marker is the constant **Vanderbilt\REDCap\Classes\Settings\ProjectSettingKeys::EXTERNAL_MODULES_PDF_FINALIZE_EXECUTION_PLAN**; the Framework marker is the class **ExternalModules\PdfFinalize**. Missing support must report unavailable pipeline status without querying assignment; both markers must preserve existing assignment/storage checks and hide the warning. These checks passed on PHP 8.2.34 and 8.5.11 on 2026-10-03.

For a clean-install browser check on a separate stack without finalization support, verify the notice on **Certificates & sealing** and **PDF Sealer status**, missing-component labels, and usable certificate management. Do not change the live development Core/Framework or PKI simply to simulate absence. On the current stack with both features, no compatibility notice is expected; assignment and PKI status remain visible. On **2026-10-03**, the user reported that tests on both sealing-capable and non-capable installations looked good. This completes the manual acceptance of the support-notice slice. The report does not separately establish every clean-install release checklist item or installation of a final release archive.

## External TSA foundation

`tests/external_timestamp.php` uses disposable synthetic PKI and injected responders, including OpenSSL's independent `ts -reply` with fractional `genTime`. It checks separate document/TSA roots, an issuing intermediate (including a response that omits the intermediate), default/explicit policies including large UUID arcs, wrong policy/imprint/nonce/purpose/trust, root injection, stale and tampered tokens, malformed/oversized bodies and transport failure. It also covers a legacy SHA-1 ESS certificate identifier in a B-T PDF while rejecting a SHA-1 CMS signature. Complete PDFs pass qpdf/pdfsig/CMS checks and their embedded timestamps are independently verified with OpenSSL against the actual CMS signature bytes. Temporary keys/certificates/PDFs are removed; no REDCap data or live service is used.

`tests/timestamp_transport.php` checks HTTPS URL/auth validation, 3-second connection/10-second request bounds, 64 KiB response cap passed to the Core helper, redirect/compression refusal, content type/status handling and secret-safe errors. It uses an HTTP test double and the real Core `ResponseByteLimit` class. Set `PDF_SEALER_REDCAP_ROOT` if Core is elsewhere. This test does not exercise real network streaming, TLS certificates, proxy authentication or timeouts; those require endpoint acceptance after CC integration. The production transport depends on `HttpClient::requestWithResponseLimit` and fails closed if that helper is unavailable.

Both suites and the existing nine-fixture `tests/pdf_seal_bt.php` (including B-B checks) passed on PHP 8.2 and 8.5. The internal timestamp spike also passed on PHP 8.5. External sources are not selectable yet; no browser intervention is needed for this foundation slice. Future source registration must encrypt credentials, audit changes without secrets, and expose explicit per-source diagnostic results. Alternative-source and B-B orchestration remain outside these tests.

## External TSA configuration and finalizer integration

Run `tests/external_timestamp_settings.php` on PHP 8.2 and the current PHP runtime. It reuses the external enrollment/activation fixtures, fake transactional Framework and encryption, then drives the real source repository, HTTPS transport adapter and finalizer against a simulated HTTP responder. It covers encrypted credentials, secret-free public summaries/audits, no network on registration/render reads, duplicate rejection, registration/policy/diagnostic rollback, persisted success/failure observations, CA expiry inventory, B-T sealing, strict failure without byte changes or alternatives, explicit B-B fallback and no-timestamp operation. The finished PDFs are cryptographically checked. `tests/pki_admin_ajax.php` covers authenticated action registration, CC authorization, invalid input, positive policy dispatch, and transaction failures. Both pass on PHP 8.2/8.5. Built-in diagnostic, expiry and public-trust checks also pass on PHP 8.5.

Manual acceptance (FreeTSA source and Acrobat checks passed; remaining checks below use a test provider/project):

1. Obtain an approved HTTPS RFC 3161 endpoint and its complete public TSA issuing-CA-to-root chain. TLS trust must already work through REDCap's HTTP configuration. Enter optional Basic credentials directly in CC and register the source; refresh should preserve the source without displaying credentials or running a probe.
2. Choose **Test source**. Confirm the pass/fail result and local/profile-formatted observation time, then refresh and confirm persistence. Failure should replace a previous result; it must not silently use another source.
3. Select the test project's CA provider under **Timestamping**, select the source, leave B-B fallback off, and save. Verify provider/project labels and the cleared/disabled fallback control for **No timestamp**. Changing this policy affects all projects assigned to that provider; use a dedicated test provider if needed.
4. Produce a fresh eConsent PDF and verify project Logging, Acrobat certification/no-modification and the embedded timestamp. Supply the downloaded PDF for independent verification if desired.
5. On a disposable source/provider, test an unreachable/rejecting endpoint: strict mode must fail sealing, and explicitly enabled B-B fallback must yield B-B with a fallback log. REDCap may still store/deliver the preceding unsealed PDF after strict failure. Restore the intended policy afterward.

On 2026-09-28, the user registered FreeTSA through the CC page and its live **Test source** passed, with signer SHA-256 fingerprint `32e841a95cc1164101ffde41298ef2fc75c1c4372ef095e88a6bbd47dfb191fc`. A read-only REDCap dev-tool query confirmed the stored diagnostic. An independent OpenSSL timestamp request/verification and a replay of its response isolated the legacy ESSCertID issue. The user then selected FreeTSA for sealing and reported that Acrobat accepted the resulting eConsent PDF without modification. Acrobat initially could not verify the embedded timestamp; after the FreeTSA CA root was trusted in Acrobat, the user confirmed that the timestamp check passed. Project Logging, provider-label details, and the failed-source/B-B policy check were not separately reported. No PDF was supplied for independent verification. Automated tests use local responders rather than a live endpoint. Ordered alternatives, source editing/deletion and external TSA public-chain downloads remain outside this slice.

## Ordered TSA alternatives

Run `RANDFILE=/tmp/pdf-sealer-tsa-random php -d xdebug.mode=off tests/timestamp_alternatives.php`, also with `php8.2`. Disposable enrollment/activation fixtures and simulated HTTP/storage drive actual finalizer PDFs. Coverage includes distinct ordered sources, unknown/duplicate/non-list/over-limit rejection, policy/audit rollback, alternative CA inventory, primary short-circuit, explicit built-in and external alternatives with different policies, the same signature request across attempts, malformed response retry, exhausted/late deadlines, attempt logging, strict input preservation and final B-B fallback. A fake monotonic clock exercises budgets without sleeps. Transport tests check shortened HTTP options and no network call after expiry; these do not prove real proxy/TLS timeout behavior. The external timestamp suite independently verifies an OpenSSL-generated legacy ESS token through the ordered builder path.

On 2026-09-30, the alternatives, external timestamp, transport, authorization, provider, expiry and built-in diagnostic checks passed on PHP 8.2.34 and 8.5.11. JavaScript state/payload checks exercise saved order, disabled/duplicate choices, primary/no-timestamp clearing and AJAX payloads. Changed PHP lint, JavaScript syntax, config/language validation and diff checks pass. No live source/provider settings or project certificates were changed.

On **2026-10-01**, the user reported that all requested test outcomes and Acrobat checks passed in **PID 524**, using **records 2, 3, 4, 6, and 7**. This completes the live acceptance matrix: healthy-primary B-T, first-alternative B-T, second-alternative B-T after two unavailable sources, strict failure after exhaustion, and explicitly permitted B-B fallback. The supplied record list is recorded collectively; no case-to-record mapping was provided. No resulting PDFs or logs were supplied for independent inspection. Deadline behavior remains supported by automated tests rather than a live timing measurement.

The following procedure remains available for future regression checks; no additional acceptance run is needed for the completed matrix. Use a disposable provider/project, or account for all projects sharing the selected CA provider's timestamp policy:

1. In **TSA → Timestamping**, choose the test provider and a healthy primary, then one or two distinct alternatives. Save/refresh and reselect the provider: order must persist. Confirm the project status and CC provider **Manage → Details** show it. Changing the primary clears alternatives/B-B; **No timestamp** disables and clears them. **Test source** must still probe only that source.
2. Register a disposable unavailable source (for example `https://127.0.0.1:1/`, using a valid public TSA CA chain and no credentials). Choose it as primary and FreeTSA or the built-in TSA as first alternative, with B-B fallback **off**. Generate a new eConsent: Acrobat should show certification/no modification and an embedded timestamp. Project Logging should mark **alternative timestamp source**; the restricted `seal_timestamp_outcome` log should list the failed primary and actual selected alternative.
3. With that unavailable primary and **no alternatives**, strict mode should fail sealing without silently trying the built-in TSA. REDCap may still store/deliver an unsealed PDF. Explicitly enable B-B fallback and generate another PDF: it should be B-B with **timestamp fallback** in project Logging. Restore the intended policy afterward.

Sources are immutable; the disposable source remains registered after the test. Deadline and complete multi-source exhaustion are covered automatically; the browser procedure need not induce repeated 10-second outages.

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

### Manual built-in project certificate renewal

The user reported that the browser check passed on 2026-09-30. The optional two-tab stale-review check was not separately reported. No resulting PDF was supplied for independent inspection.

Run `php -d xdebug.mode=off tests/project_renewal.php` and the same command with `php8.2`. The suite reuses disposable enrollment fixtures and fake transactional storage. It covers public/no-decryption review, a genuinely expired project certificate, fresh keys and certificates, stable UUID/provider, retained history, stale/replayed reviews, pending CSR/transition isolation, enablement, retired/expired/unusable issuers, encryption/write/commit rollback, project-then-configuration locks, authenticated real AJAX dispatch, and expiry inventory selection. The actual finalizer's B-B/B-T PDFs use the renewed signer and pass independent signature/timestamp verification; a PDF using the previous signer remains verifiable. Lock interleavings are deterministic test doubles, not a live multi-connection load test.

Verification on 2026-09-30: renewal, admin AJAX, provider-transition and retirement suites passed on PHP 8.2.34 and 8.5.11. Changed PHP lint, JavaScript syntax, config/language and diff checks passed. No live project identity, provider, or PDF was changed by automated checks. The user subsequently reported that the browser check passed; the procedure remains available:

1. Choose a test project that already seals under the built-in CA; note its UUID, provider, and certificate fingerprint on **PDF Sealer status**.
2. In CC **Root CA → Renew built-in project certificate**, select that project and review the current certificate. Refresh the project page before confirmation to check that review alone changed nothing.
3. Confirm renewal. Check the success notice identifies the project and new fingerprint and clears the selector. Refresh the project page: UUID/provider must match the originals, fingerprint must change, and signing status must be ready.
4. Complete a new eConsent, inspect project Logging, and check certification/no-modification and the configured timestamp in Acrobat. An earlier PDF must retain its original seal.
5. Optional stale-page check: review in two CC browser tabs, renew in one, then confirm the old review in the other. The stale action must fail without another certificate change; it must keep its project selection for a fresh review.

External projects, unissued projects, and projects with a pending provider transition are excluded from the selector. Backend checks also reject stale selections, retired CAs, and pending/corrupt enrollment. Resolve those states through the existing workflows.

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

## Same-key root renewal in Acrobat — 2026-10-01

**First Acrobat round passed, user-reported on 2026-10-01.** Acrobat already trusted the root used for PID 524, and the user imported none of the certificates in the ZIP. All three PDFs (A/B/C) were displayed as certified and timestamped. This establishes acceptance of the same-key renewed root, including fresh project/TSA leaves, while the original trusted root is still valid. The separate expired-anchor certification check also passed as recorded below. This first round itself did not test expiry. This is a read-only developer probe; the separate production root renewal service is now covered below.

Run with PHP 8.4+ (tested on CLI PHP 8.5.11):

```bash
PDF_SEALER_LIVE_TEST=1 php -d xdebug.mode=off tools/same_key_root_probe.php --preview 524
PDF_SEALER_LIVE_TEST=1 php -d xdebug.mode=off tools/same_key_root_probe.php --run 524
```

The tool reads current identities through the module's repositories after preflight inspection with `redcap_devctl`. It sets the database session to read-only before loading/issuing the diagnostic identities. It never invokes the finalizer hook or writes records, settings, identity logs or edocs. The root key is reused in memory; fresh diagnostic leaf keys also remain in memory. Output contains public certificates, synthetic PDFs and a manifest in a unique Git-ignored directory under `DEV_DOCS/interop-artifacts/`.

The renewed self-signed root preserves the exact subject, issuer, public key, signature algorithm and extensions. Only its serial, validity and resulting signature change. This deliberately narrow DER clone is a diagnostic technique, not the production renewal implementation.

| PDF | Project certificate | TSA certificate | Embedded root |
| --- | --- | --- | --- |
| A-baseline | Current PID 524 signer | Current built-in TSA | Original |
| B-renewed-root-existing-leaves | Current PID 524 signer | Current built-in TSA | Renewed with the same key |
| C-renewed-root-fresh-leaves | Fresh key/certificate, same project UUID | Fresh key/certificate | Renewed with the same key |

All three use the production B-T builder with strict internal timestamps. The renewal cases embed the renewed root only, in both signer and timestamp chains. The tool verifies public certificate/key/profile invariants, B-T CMS, timestamp imprint binding, OpenSSL response/token verification against **only the original root**, qpdf structure, pdfsig cryptographic signature and whole-document coverage. These checks passed on 2026-10-01 for PID 524; they do not establish Acrobat trust behavior.

### Acrobat procedure

1. Import or inspect `original-root.cer` in **Preferences → Signatures → Identities & Trusted Certificates → More → Trusted Certificates**. Set it as a trusted root for signed/certified documents, following [Adobe's trust instructions](https://helpx.adobe.com/acrobat/desktop/e-sign-documents/manage-digital-signatures/set-certificate-trust.html). Do not add the renewed root or project/TSA leaf certificates as trusted identities for this experiment.
2. Open A and validate the certification and embedded timestamp. Establish that both are trusted before interpreting B/C; missing revocation information remains expected without a CRL service.
3. Open B and C, validate each, and report separately whether certification and the embedded timestamp remain trusted without any additional trust action. Both should report no document modification. Record the root fingerprint Acrobat actually chooses if accessible; it can build its chain to the original trusted root even though a different certificate with the same public key is embedded.
4. Close Acrobat completely, reopen B/C, and repeat validation to avoid relying only on the existing process state.

**Evidence limit:** the original root in this first round is still valid. The result establishes same-key renewal and fresh-leaf acceptance under those conditions. The separate expired-anchor certification result is recorded below; it does not reproduce a trust-store entry expiring after installation. Production activation/concurrency and CRL behavior are separate slices.

Artifact fingerprints, public output hashes and timestamps are in the generated `manifest.json`. No original deployment state needs migration; these old/new certificates are only the deliberate comparison required by the test.

## Expired original root in Acrobat — prepared 2026-10-01

**Certification acceptance passed, user-reported on 2026-10-01.** Acrobat imported the expired original root; D was reported as certified, and E reported “validity of the certification is UNKNOWN. The author could not be verified”. This is the expected distinction between a same-key renewed issuer and a same-name issuer with a different key. The user did not separately report D's timestamp validation or a post-restart result; those remain unconfirmed. Following the reported PID 524 A/B/C acceptance, [root_expiry_probe.php](../tools/root_expiry_probe.php) prepares a separate disposable CA with a unique organization. The installation root and PID 524 identities cannot be selected as alternative trust anchors for this fixture. No live PKI, records, bindings, configuration or edocs are changed.

```bash
PDF_SEALER_LIVE_TEST=1 php -d xdebug.mode=off tools/root_expiry_probe.php --preview
PDF_SEALER_LIVE_TEST=1 php -d xdebug.mode=off tools/root_expiry_probe.php --run
```

The tool requires PHP 8.4+ and uses Framework temporary files for issuance, with a read-only database session. All generated private keys remain in memory. Output is public-only in a unique Git-ignored artifact directory.

It creates:

- `expired-original-root.cer`: a self-signed anchor whose validity ended one day before generation.
- `renewed-root.cer`: the same subject/public key/extensions, with a new serial and current validity.
- `D-renewed-root.pdf`: new project and TSA keys/certificates issued after the original anchor expired, with only the renewed root embedded.
- `E-different-key-control.pdf`: the same CA subject but a different root key, with a correct independent signature and timestamp. It must not inherit the first root's trust.
- A public manifest with original expiry, root fingerprints and PDF hashes.

The shared [diagnostic root clone](../tests/support/root_certificate_probe.php) is used by both experiments. It is not a runtime renewal API. D/E passed PHP lint, qpdf, pdfsig, CMS and independent OpenSSL timestamp checks on CLI PHP 8.5.11. Each PDF was cryptographically verified with its own valid embedded root; these checks are not an expired-anchor trust acceptance result. Exact subject/profile equality and same-key verification were checked for the paired roots; the control root has the same subject but cannot verify under the paired key.

### Manual procedure

1. Import **only** `expired-original-root.cer` into Acrobat's trusted certificates and enable it as a trusted root for signed/certified documents. Leave PID 524's existing trust untouched. Do not trust the renewed root or any leaf.
2. If Acrobat refuses the expired certificate or requests a special expiry override, report the exact behavior. Do not interpret this import result as an automatic rollover failure; a short-lived anchor trusted while valid would be the next method.
3. Open D. In Signature Properties, report the certification validity/trust and timestamp validation messages. If same-key continuity works with this expired anchor, D should be accepted.
4. Open E. Its cryptographic signature and timestamp are correct, but its issuer should be unknown/untrusted. Record the validation messages rather than only the presence of a certification/timestamp badge.
5. If results are positive, close Acrobat completely, reopen D/E, and report whether the same trust distinction persists.

**Scope:** this imports an already-expired anchor. It does not reproduce the timed transition of a trust-store entry that was installed while valid. The reported D/E result supports same-key root renewal for certification with an expired trusted certificate in the user's Acrobat installation; it does not establish every viewer's behavior, D's timestamp trust or a timed expiry transition. No system clock change was requested. A timed transition check remains a later acceptance option if needed; this completed D/E certification check does not need repeating.

## Built-in CRL publication — 2026-10-01

Run `php tests/crl.php` and `php8.2 tests/crl.php`. The suite uses disposable identities and fake persistence; OpenSSL independently validates the signature and full-list extensions, accepts a nonrevoked leaf, and rejects the exact revoked serial. It checks both project/TSA CDPs, 128-bit serials on PHP 8.4+, bad URLs, cached metadata/signature tampering, rollback, daily/idempotent refresh, expired/future/missing public data, same-key renewal continuity and number exhaustion. The adjacent initialization/renewal/activation/diagnostic/public-trust regressions passed on both PHP versions.

On the main development instance, normal Framework validation registered the new daily cron 126 and its first scheduled run passed. A read-only dev-control query confirmed empty CRL 1 and its update times. Anonymous HTTPS GET returned the exact cached DER with HTTP 200 and `application/pkix-crl`; OpenSSL independently verified it against the installed root. No previewed database mutation was needed. Check normal daily refresh later through `pki_crl_publication` audit metadata; this slice has not waited a real 24 hours.

### Acrobat and browser acceptance

1. Open the public trust page while logged out. The built-in root now has **Download certificate revocation list (CRL)**. It should download a `.crl` file without login or JavaScript. The current development endpoint is [the built-in CRL](https://dev-surveys/surveys/?pdf_sealer_crl=f8a748fa81720be8b9f4c53669432ae91563f2f1478b00e3d8978d9f33fa61b8).
2. Use the [synthetic CRL B-T PDF](interop-artifacts/crl-probe/crl-probe-BT.pdf). It uses the already trusted live root with temporary project/TSA certificates; both certificates contain the live CRL URL. Confirm unchanged certification, timestamp validation, and each leaf's revocation finding. No new root import is needed. The user reported certification accepted, recorded a request to the CRL endpoint after clicking Check Revocation, and supplied Acrobat's explicit valid-certificate result against its cached CRL signed by REDCap PDF Sealer Root CA. Displayed update times (2026/10/01 15:55:01 +02:00 to 2026/10/04 15:55:01 +02:00) match published CRL 1. This completes project-certificate CRL acceptance. Separate timestamp/TSA revocation status and the logged-out browser link/download are not yet reported. For the project certificate, use Signature Properties → Show Signer’s Certificate → select the project leaf → Revocation; use Check Revocation if offered. [Adobe describes this tab](https://www.adobe.com/devnet-docs/acrobatetk/tools/DigSigDC/Acrobat_DigSig_WorkflowGuide.pdf).
3. Existing PID 524/TSA certificates do not gain CDPs retrospectively. To exercise normal project issuance, use the existing CC renewal workflow for a chosen built-in test project, then create a new consent PDF. Its new project certificate should carry the CRL URL; the existing TSA still lacks that extension until hourly maintenance replaces it. Do not reset the installation solely to complete this check.

Regenerate the read-only diagnostic on PHP 8.4+ if needed:

```sh
PDF_SEALER_LIVE_TEST=1 php tools/crl_probe.php --preview
PDF_SEALER_LIVE_TEST=1 php tools/crl_probe.php --run
```

The probe enforces a read-only database session for PKI reads/temporary issuance, uses Framework temporary-file helpers, and refuses runtimes needing integer serial reservations. It saves public artifacts only under the ignored `interop-artifacts/crl-probe/` directory. qpdf, pdfsig, CMS/ByteRange, OpenSSL timestamp and CRL checks pass. Stored identities, provider/source choices and project bindings remain unchanged. These are synthetic test credentials, not an installed project/TSA replacement.

Administrative revocation, prompt publish after revocation, local signing blocks and automatic replacement are future work. No production revocation was performed. CLI acceptance does not establish Acrobat network/cache behavior, long-term validation or root-trust withdrawal.

## Built-in lifecycle prerequisites — 2026-10-01

Run from the module root:

```sh
php8.2 tests/pki_lifecycle_prerequisites.php
php tests/pki_lifecycle_prerequisites.php
```

Passed on PHP 8.2.34 and 8.5.11. The suite uses disposable identities and fake transactional Framework storage; it never changes live PKI or REDCap data.

- Project and TSA leaf expiry stays within an issuer with two remaining whole days; OpenSSL verifies both chains. A longer-lived issuer retains the 730-day default, and a one-day issuer produces a one-day leaf.
- An issuer with less than one full day remaining rejects issuance before allocating a serial or temporary configuration file.
- A setting-read callback replaces an internal source immediately after it is captured. The real finalizer still produces a verified B-T seal using the captured TSA certificate, issuing root and policy, with only one source read. The CC diagnostic likewise uses one captured source even when its replacement has an invalid policy.
- A mismatched captured issuer is rejected. A stale request-start time does not become the production token time: an injected current clock supplies the token's actual creation time.
- TSA or issuing-chain expiry before token creation rejects the response.

Existing `tests/pki_primitives.php`, `tests/pki_diagnostic.php`, `tests/timestamp_alternatives.php`, `tests/project_renewal.php` and `tests/crl.php` also pass on both runtimes. These tests establish the issuance and capture prerequisites; automatic renewal, revocation boundaries and diagnostic version tracking require their own coverage when implemented.

## Automatic built-in leaf maintenance — 2026-10-01

Run `php8.2 tests/builtin_maintenance.php` and `php tests/builtin_maintenance.php` from the module root. Both pass on PHP 8.2.34/8.5.11. The suite reuses manual renewal/enrollment fixtures, adds synthetic expired/due built-in certificates, and drives the real maintenance service and finalizer. No REDCap bootstrap, live database mutation or external HTTP request is used.

Coverage includes the exact 90-day boundary and insufficient issuer window when root recovery is blocked by incoherent references; expired TSA/project recovery; six due projects processed across bounded runs; enabled priority and retained disabled-project maintenance; fresh public keys and stable UUID/provider/history; idempotency; atomic TSA pointer/source/audit and project commit rollback; exponential retry and recovery; competing worker refusal and mutation lock ownership; preservation of pending CSR/provider changes; retired project CA with independent TSA maintenance; expired external signer and unissued-project isolation; deadline refusal; immutable diagnostic cache with changed-version detection; and the public cron entry on uninitialized PKI without a human session. Missing existing root pointers fail without resetting storage.

The original expired-fixture run revealed that backdating leaves before their newly minted issuer was invalid. The corrected disposable issuer has a historically valid start date. The suite also caught request-start time being too early for a newly generated TSA; production sample checks now obtain current time after generation.

For browser acceptance:

1. Let Framework **ExternalModuleValidation** discover `certificate_maintenance`, or refresh cron registration through the normal module workflow. The read-only devctl check initially showed only the existing expiry/CRL jobs; the hourly maintenance job had not yet been registered.
2. After a maintenance run, open **Alarms → Automatic built-in certificate maintenance** and confirm the status, local/profile-formatted run time and counts. Existing long-lived identities should remain unchanged.
3. Open **Diagnostic**. An older snapshot may warn that it lacks version evidence. Run the diagnostic, refresh, and confirm the warning clears while the saved result/time persists.

Forced expiry/rotation and new-pair B-T sealing are covered by disposable tests. Do not change the live clock or shorten live certificates to trigger acceptance. Live automatic replacement itself has not been reported as browser/Acrobat verified.

## Automatic same-key root renewal — 2026-10-01

Run from the module root:

```sh
php8.2 -d xdebug.mode=off tests/root_renewal.php
php -d xdebug.mode=off tests/root_renewal.php
```

Both pass on PHP 8.2.34/8.5.11 using disposable real certificates, fake transactional Framework storage and advisory-lock doubles. No live database writes, installed certificate changes or external requests are involved.

- Exact 820-day boundary and a genuinely expired root/TSA recover using the existing root key, with identical DER names/public key/extensions and a new serial/ten-year validity.
- Fresh TSA validation, active root/TSA references, provider/source references, CRL and audit commit together. Injected identity/log, each setting write, encryption, transaction-start and commit failures retain the prior pair and history.
- Missing/corrupt CRL, exhausted counter, backward publication clock, corrupt key and inconsistent source references fail safely. Nonempty revocation entries and the CRL URL survive renewal; its number increases. Daily publication deduplicates same-key root generations.
- Only the original root is supplied to independent OpenSSL verification of the new TSA and refreshed CRL, including a `-crl_check` chain validation.
- Root retry/backoff recovers to an atomic pair; competing mutations are rejected; repeated renewal is a no-op. Assignment gate, timestamp choices/policy, external provider dependency and retirement remain intact.
- Six dependent projects catch up across bounded runs with stable UUIDs. The actual finalizer produces verified B-T output both before and after a project's issuer update; a prior PDF remains verifiable.
- Public history includes both roots. Cached diagnostic bytes/time remain unchanged and its version mismatch is detectable.

Adjacent leaf maintenance, CRL, certificate serial, primitive issuance, initialization and diagnostic regression suites run on both PHP versions. This production service's CLI evidence is separate from the already accepted [Acrobat same-key experiment](#same-key-root-renewal-in-acrobat--2026-10-01).

Read-only devctl inspection now confirms maintenance cron 127 is registered. The preceding leaf-only run at 2026-10-01 15:25:01 UTC reported ok, zero renewals and no failed/deferred/pending work; its first outcome was TSA/skipped, so it predates the new root phase. No manual registration is required. For a normal browser check, inspect the existing maintenance card after the next scheduled run and rerun the diagnostic if its versions changed. Existing long-lived live identities should not renew. Do not change the live clock, shorten installed certificates or reset PKI to trigger this slice. Revocation/recovery acceptance belongs to its next slice.

## Built-in project revocation — 2026-10-01

Run `php8.2 -d xdebug.mode=off tests/project_revocation.php` and the same command with `php`. The suite uses disposable real PKI plus fake transactional Framework/lock storage; no live certificate, database or project is changed.

Coverage includes no-decryption public review, invalid/stale/replayed payloads, block transaction/audit rollback, independent CRL-setting/audit failure and replacement failure, preserved UUID/provider/history, permanent local block, exact Superseded/Key compromise serials, OpenSSL rejection of the old certificate and acceptance of its replacement, B-B/B-T finalizer output, disabled-project recovery, retired/pending-work deferral, damaged project/issuer keys, bounded revoked-first batches, new-block backoff reset, missing-counter refusal, ledger tampering, pending entries through same-key root renewal and authenticated real module AJAX dispatch.

The finalizer race uses a deterministic query hook to commit revocation after identity acquisition and before acceptance; the result fails with `PROJECT_CERTIFICATE_REVOKED` and the original bytes remain. A competing revocation cannot enter while acceptance owns the project lock. These are defined working-copy acceptance checks, not a live multi-connection stress test or a guarantee that subsequent Core storage/delivery is canceled.

### Browser and Acrobat acceptance — passed

On **2026-10-03**, the user reported that the pending built-in project revocation browser and Acrobat tests were completed in **PID 534** and passed. This is user-reported manual acceptance; the procedure is retained for future regression checks. Optional compromise and failure-recovery cases remain covered by the disposable automated suite unless separately reported.

Use a **disposable built-in test project** with PDF Sealer enabled and its finalization pipeline assigned. Revocation is permanent for the selected certificate and may affect validation of PDFs previously sealed with it.

1. Create an eConsent PDF and retain it. Note the current project certificate fingerprint.
2. In **CA providers → Revoke built-in project certificate**, choose that project and review the matching fingerprint. Cancel the confirmation once; the project certificate must remain unchanged.
3. Review again, choose **Superseded**, and confirm. The message should report the local block, published CRL and fresh active signer separately. If either publication or replacement is pending, retain the message and inspect the underlying PKI/pending-work state; do not interpret it as a rejected block.
4. Refresh the project status: the UUID/provider should be unchanged, the fingerprint should differ and status should be Ready. Create a second consent PDF and confirm unchanged certification/timestamp acceptance in Acrobat.
5. Download the public root's CRL. Its number must increase and the **previous** project serial must appear with reason Superseded. The new project certificate is not on that list. An older cached Acrobat list can delay recognition; the automated OpenSSL check establishes exact serial rejection independently of viewer cache.
6. If desired, repeat **Key compromise** on the new disposable signer, then make another consent. Expect another new key/fingerprint and successful future sealing. Old document bytes remain unchanged; a compromised signer's historical validation is not repaired.
7. Refresh the CC page and check for normal GET/AJAX behavior. The accepted form clears its project/reason; failed or stale review requires a new review. No certificate download/import or manual replacement approval is needed.

Failure recovery, disabled/retired/pending isolation and races are covered by disposable tests. Do not corrupt live keys/settings or manipulate live clocks to reproduce them. The reported PID 534 result completes the pending browser/Acrobat acceptance for this slice.

## Built-in TSA lifecycle — 2026-10-02

Automated verification uses disposable real RSA/certificate/timestamp/CRL data and fake transactional primary persistence/locks. It never revokes live material or changes the database. Run **php tests/tsa_revocation.php** and **php8.2 tests/tsa_revocation.php**; adjacent suites are project_revocation, builtin_maintenance, root_renewal, timestamp_alternatives and pki_lifecycle_prerequisites.

Coverage includes public/no-decryption review, malformed/stale/replayed requests, ordinary replacement without revocation, block transaction/audit rollback, superseded/compromise CRL entries, prompt publication, independent recovery failures, fresh-key/history/policy preservation, damaged-key and new-block backoff recovery, strict/B-B sealing, token post-signing blocks, actual PDF acceptance interleavings, merged project/TSA CRLs during root renewal, and CC-only authenticated AJAX. OpenSSL checks the old TSA as revoked and the replacement as valid against the same CRL; unchanged historical token bytes still verify cryptographically. That check does not establish historical trusted validation. The ordered-source suite also verifies a revoked built-in primary uses its explicitly configured external alternative.

### Browser and Acrobat acceptance — passed

On **2026-10-03**, the user reported that the pending built-in TSA lifecycle browser and Acrobat tests were completed in **PID 534** and passed, alongside project revocation acceptance. The procedure is retained for regression checks; the shared-TSA compromise branch remains disposable automated coverage.

This changes the shared built-in TSA for all providers selecting it. Use the sole development instance with a built-in timestamp test project (PID 524 is suitable if still configured that way).

1. Open **TSA → Replace or revoke built-in TSA certificate**, review the subject/fingerprint, choose **Replace without revoking**, and confirm. Check that a fresh TSA is reported active and refresh shows a new fingerprint. Canceling the confirmation must do nothing; refreshing must not repeat the action.
2. Run CC diagnostics and create an eConsent PDF with that fresh TSA. Confirm certification, no modification and the embedded timestamp in Acrobat. Keep this PDF as the pre-revocation sample.
3. Review the current TSA again, select **Revoke as superseded**, and confirm. Check separate success messages for the permanent local block, published CRL and fresh active replacement. Refresh and verify a different TSA fingerprint, unchanged provider timestamp settings, and healthy diagnostics.
4. Create another eConsent PDF and confirm certification/no modification/timestamp in Acrobat. The original trusted root remains the issuing key; no new root trust import should be necessary.
5. The public CRL should now include the exact superseded TSA serial with reason 4 and an increased counter. Acrobat may keep an older CRL until its cache refreshes. After receiving the new list, validation of the earlier sample's timestamp should follow RFC 3161 non-compromise semantics. Record what Acrobat actually reports; a signature's byte-integrity result alone is not timestamp/revocation acceptance.

The **Key compromise** branch is covered by disposable automated tests. Do not use it merely to test routine replacement on the shared TSA: it withdraws trust in all tokens from that key. Automated implementation/testing made no live mutations; the subsequent user-performed browser/Acrobat acceptance is recorded above.

## Built-in root lifecycle — 2026-10-03

Run **php8.2 tests/root_revocation.php** and **php tests/root_revocation.php**. Both pass on PHP 8.2.34/8.5.11 with disposable real RSA/certificates/timestamps/CRLs and fake transactional primary persistence/advisory locks. No live database, installed certificate, project or external HTTP service is changed. Adjacent suites are project_revocation, tsa_revocation, root_renewal, builtin_maintenance, crl, public_trust and timestamp_alternatives.

Coverage includes public review without key decryption, manual early same-key renewal, stale/replayed/malformed and CC authorization checks, both revocation reasons, block transaction/audit rollback, fresh root/TSA/CRL activation rollback, complete historical known-leaf CRLs and retained counters, independent OpenSSL rejection of old leaves and acceptance under the fresh trusted root, actual B-T finalization, five-project batches/disabled catch-up, damaged old keys and missing old CRL, preserved UUID/provider/policy/pending/retirement state, new-block retry reset, external CA/TSA key aliases, public revoked history and malformed/duplicate metadata, and deterministic finalizer/acceptance interleavings.

### Browser and Acrobat acceptance — passed

On **2026-10-03**, the user reported that all browser/Acrobat root lifecycle checks passed in **PID 534, record 4 after revocation as Superseded**. This is user-reported acceptance; no PDF was supplied for independent analysis. The following procedure is retained for regression checks. CA compromise, injected failures and batch stress remain disposable automated coverage.

This action affects the shared built-in issuing key and all dependent projects. Use the sole development instance and retain pre-change samples; PID 534 is suitable if it still uses the built-in CA/TSA. **Use Superseded for this acceptance, not CA key compromise.** Compromise is covered by disposable tests and deliberately withdraws trust in earlier evidence. The shared root change can affect previously created development PDFs.

1. Note the current root/TSA/project fingerprints, UUID and provider timestamp choices. Retain one eConsent PDF. On **Root CA → Administrative workflows → Renew or revoke built-in Root CA**, review and cancel confirmation once; nothing should change.
2. Optionally confirm **Renew without revoking (same key)**. Refresh: the root certificate fingerprint and TSA should change but the root key/CRL URL stay the same. Diagnostics should pass. This is ordinary early renewal; no old serial is newly revoked. The existing same-key Acrobat experiments already establish their recorded scope; repeating that matrix is unnecessary.
3. Review the now-current root, select **Revoke issuing key as superseded**, and confirm. Check separate messages for the permanent block, old-key CRL publication, fresh root/TSA and bounded project recovery. Refresh must not resubmit. A pending message means the block was accepted; inspect **Alarms → Automatic built-in certificate maintenance**, rather than immediately revoking another root.
4. On the public certificate page, the fresh root should be current and all old same-key versions clearly revoked. Download the fresh root via the normal public action. Verify its fingerprint independently against the CC review. Its CRL URL differs from the old key's URL. The old complete CRL should have an increased counter and known old project/TSA serials, preserving earlier explicit leaf entries.
5. Let remaining eligible projects recover through scheduled maintenance. The selected test project's UUID/provider must remain unchanged; its signer fingerprint must change and status become Ready. Provider timestamp choices must be unchanged. A cached diagnostic may report changed identity versions; rerun it and confirm all checks pass.
6. Create another eConsent PDF. Before importing the fresh root, Acrobat may report unknown/untrusted certification or timestamp; intact signed bytes alone do not establish trust. Install the verified fresh root in the appropriate Acrobat trusted-certificate store, then validate again. Confirm certification, **no modification**, embedded timestamp and revocation checking of the new project/TSA certificates. No project private-key export or manual replacement approval should be needed.
7. Report the PID/record and messages/outcomes. Old sample bytes stay unchanged. Viewer historical trust/revocation decisions and caches are separate from future sealing acceptance; do not infer acceptance of compromise recovery or LTV from the result.

The module cannot withdraw old root trust from Acrobat. For an actual compromise, remove trust in every version of that issuing key through the institutional process, as well as distributing the fresh root. The CRL lists known stored leaves, not unknown forged certificates or a self-signed root serial intended to withdraw anchor policy. No live failure injection, clock changes or key corruption is needed for browser acceptance.

## Windows thumbprint displays — accepted 2026-10-03

The user reported that the browser spot-check of the new Windows thumbprint displays passed. The stored public root's DER yields SHA-256 **37a3266ff662dce32e6a9742aaebbdfae8dd349e15723683e0b0968a9de0df1f** and SHA-1 **56550b178fa44afe7672e854d465f06d5bf86254**, matching the reported Windows certificate dialog. Read-only devctl inspection verified the public certificate bytes; no private keys or downloaded PDF bytes were inspected. OpenSSL comparisons, saved external TSA observation compatibility and PHP 8.2/8.5 syntax checks passed during implementation. No further browser check is pending for this display slice.

## Root CA administration dialog — 2026-10-04

Run **node tests/root_lifecycle_ui.js** with Node 18+ (development only; no packages required). Disposable DOM/rcDialog doubles exercise the real Root CA asset: a standard single-page dialog with the shared **pdf-sealer-dialog-body** style, opening without mutation, conditional unchecked/checked compromise and action-change acknowledgment reset, renewal/superseded endpoint payloads and review hash, cancellation, an already revoked root, failed-request invalidation, in-flight duplicate/close prevention and frozen choices, missing standard dialog support and one-shot text-only reload receipts, including pending CRL/recovery. This checks client control flow rather than rcDialog rendering, drag behavior or a real browser.

The user reported completion of the preceding wizard on 2026-10-04, then requested the single-page layout and accepted its appearance. The following behavior checks remain available for regression; no new live Root CA action is required for the provider UI slice:

1. Verify the compact **Administrative workflows** section and link, movable single-page dialog, shared body style, subject line breaks and radio descriptions. Only Cancel/Confirm should appear, without page numbers or progress.
2. Cancel or use X/Escape; no certificate change should occur. Opening and selecting an action must not itself apply it.
3. Select compromise: the red acknowledgment appears and Confirm stays disabled until checked, then disables again when unchecked. Change away and back: the checkbox must be unchecked again. Cancel this check; no live compromise is needed.
4. If an early renewal is wanted on the disposable development PKI, confirm **Renew without revoking (same key)**. The page should reload to current root/TSA details and a result message. Prior trust/revocation matrices do not need repeating solely for this layout change.

The Node UI test, administrator AJAX suites and PHP syntax checks pass; language/translation-transfer consistency and diff checks pass. The full Root CA regression and administrator AJAX suites passed on PHP 8.2.34/8.5.11 in the preceding slice. Its fixture now backdates the disposable root by one day using the existing validity helper, avoiding same-second renewal failures without changing production issuance. The full regression suite was not repeated for this client layout change. No live renewal/revocation was performed. The candidate predates these UI changes and must be regenerated after the planned UI/UX rounds.

## Compact CA provider administration — 2026-10-04

Run **node tests/providers_admin_ui.js** and **php tests/provider_admin_view.php** (also with **php8.2**). The rendered-view check exercises the actual PHP template, earliest chain expiry, concise timestamp names, escaped metadata and certificate detail templates. Disposable DOM/DataTables/rcDialog doubles exercise the real client asset: read-only Manage opening, Details/Usage tabs, usage pagination initialization and cleanup, fresh confirmation hashes, the default-retirement acknowledgment, retirement/reactivation row and selector updates, preserved table paging, cancellation, failed-review handling, failed-request invalidation and in-flight duplicate/close prevention. Policy saves update the summary without a page reload. These checks do not render a browser or send live mutations.

Both PHP runtimes also pass **tests/provider_retirement.php** and **tests/pki_admin_ajax.php**, preserving server authorization, locks, stale reviews, atomic default-policy gating, pending enrollment, existing B-T signing and reactivation. JavaScript syntax, changed PHP lint, translation transfers and diff checks pass. No live settings, certificates or Core/Framework files were changed.

Browser acceptance remains pending:

1. Open **CA providers** directly and after switching from another CC tab. Check the compact summary/link, five catalog columns, UTC earliest expiry, search/sorting/paging and table widths. **Change assignment policy** should open a movable styled dialog; cancel should leave the summary unchanged. Saving should update the summary and persist after refresh.
2. Open **Manage** for built-in and external providers. Verify complete certificate/timestamp details, subject line breaks and both fingerprints. Only **Close** should be a regular button. On **Usage Stats**, check the project rows, search/paging and width after switching tabs or moving/resizing the dialog. Close and reopen to refresh usage.
3. On a disposable external provider, choose **Retire CA** in the footer and cancel confirmation once: Manage stays open and status is unchanged. Confirm retirement: both dialogs close, the row changes to Retired without a page reload, and the provider disappears from assignment/transition choices. Reopen Manage and confirm **Reactivate CA**: status/choices return, with the assignment policy unchanged.
4. Default-CA retirement still requires the explicit-assignment acknowledgment when the gate is off; this is covered by disposable tests. Reviewing and canceling this confirmation is sufficient for the layout check. No repeat of live certificate revocation or Acrobat acceptance is required solely for this presentation change.

## CC table polish and toast feedback — 2026-10-04

Run **node tests/admin_notifications_ui.js**, **node tests/providers_admin_ui.js** and **node tests/root_lifecycle_ui.js**. The shared notification helper escapes all text before REDCap's HTML-rendering **showToast** API, retains readable newlines, maps danger to Core's persistent **error** type and allows more reading time for warnings. Existing dialog cancellation, stale/failed/busy gates, in-place catalog updates and Root CA reload receipts remain covered.

The status column now has a compact width and expiry cells use smaller text. CSS overrides first/sorted-column shading on module DataTables in both page and dialog bodies, with a uniform row hover highlight. Control Center operation feedback (assignment, registration, transitions, renewals/revocations, timestamp settings, downloads, diagnostics and alarms) uses toasts; persistent health/policy/cache warnings and saved diagnostic observations remain inline. GET initialization/registration/settings notices are shown once and removed from the URL; Root CA receipts retain their existing sessionStorage handoff. No live settings or PKI changes are needed to implement this.

Browser checks pending:

1. Check status width and smaller expiry text. In the catalog and **Manage → Usage Stats**, sorting should not reintroduce first-column shading; hovering should highlight the whole row consistently.
2. Save assignment policy or retire/reactivate a disposable provider. Confirm a toast appears without expanding the page or dialog footer; catalog updates should retain their existing behavior. Cancel remains silent.
3. Check a harmless error (e.g. an invalid external CA registration): its toast should stay until dismissed, without inserting an error banner. Check the alarm-recipient save, timestamp-policy save/GET notice and Root CA download. Refresh after a successful redirected save should not repeat its notice. No additional live root/project/TSA revocation or Acrobat matrix is required for this feedback change.

## CA provider administrative workflow dialogs — 2026-10-04

Run **node tests/provider_workflows_ui.js** and **node tests/project_revocation_ui.js**, alongside the existing provider, Root CA and toast UI tests. The launcher test also checks the registration footer action: required-field/file validation, exact AJAX payload, failed/rejected requests retaining inputs, native form submission, duplicate/busy dismissal prevention, and successful close before catalog refresh. It exercises all five real dialog entry points, moving/reusing form nodes, scoped Select2 setup/destruction, fresh local selection on reopening, blocked busy dismissal, reset cleanup, missing dialog support and setup failure. The revocation asset test checks read-only review, cancel/confirm, captured reason/review hash, frozen requests, duplicate prevention and failed/already-revoked guards. These use disposable DOM/rcDialog doubles rather than browser rendering.

**tests/provider_admin_view.php** now renders the actual workflow view too: five links, five hidden forms with the shared body class, three project pickers and the assignment project table, escaped project labels and unique IDs. It and **tests/pki_admin_ajax.php** pass on PHP 8.2/8.5. Changed-file syntax, inline JavaScript parsing, translation and diff checks pass. No live project/provider settings, enrollment/certificates or Core/Framework files were changed.

Browser checks pending:

1. Check that **CA providers** shows the compact catalog followed by **Administrative workflows** and the five link buttons. Each opens a movable styled dialog, with no form expanding the background page.
2. Open and close/reopen all five workflows. Check project search, provider/source choices, select menus staying inside their dialog, body scrolling and Close/X/Escape. Opening/reviewing/closing should not issue or replace anything. Reopening should clear local selections and require a new review. Empty/unavailable project lists should allow closing the dialog.
3. In registration, check that **Cancel** and **Register external CA** are in the dialog footer, with no duplicate action in the form. Confirm **No timestamp** disables/clears B-B fallback, including after canceling and reopening. Required fields must be validated; invalid registration should retain entered values while showing an error toast. For optional successful registration using a disposable provider, verify the dialog closes before the catalog refresh and the success toast appears. For assignment, use a disposable project and verify the existing receipt and cleared/updated selector behavior.
4. Review an existing provider change and a built-in project certificate without confirming. For project revocation, open its nested confirmation and cancel it: the parent remains open, the review remains usable and no revocation occurs. Requests should temporarily disable fields and dialog dismissal. No additional live certificate revocation/renewal or Acrobat matrix is needed solely for this presentation refactor.

## Bulk project CA assignment — 2026-10-04

Run **node tests/provider_assignment_ui.js**, **node tests/provider_workflows_ui.js** and **node tests/providers_admin_ui.js**. The real bulk controller is exercised with DOM/DataTables doubles that detach off-page/filtered rows. Checks cover selection across pages/search, captured provider and PID payloads, partial/rejected requests, retry excluding successful projects, accessible checkmarks, transition-selector updates, frozen controls, duplicate guards, cleanup/reopen, and empty/unavailable/invalid states. **tests/provider_admin_view.php** renders escaped labels and Development/Production/Analysis/Cleanup/Completed statuses; it and **tests/pki_admin_ajax.php** pass on PHP 8.2/8.5. These checks do not establish actual browser rendering or live assignment acceptance.

Browser checks pending:

1. Open **CA providers → Administrative workflows → Assign project provider**. Check checkbox/PID/Name/Project status columns, 10-row paging, sorting/search, hover and uniform cell backgrounds. Confirm only enabled projects with no provider binding appear. Close remains the sole footer button.
2. Select projects across pages and search filters (if enough qualifying projects exist). The count must include hidden selections. Without a provider or selected projects, the assignment action is disabled. Closing/reopening clears selections and the provider choice without duplicate table controls.
3. With disposable projects, select a provider and **Assign CA provider to the selected projects**. The dialog stays open; success produces one toast and checkmarks, with paging/search retained. Successful projects cannot be selected again and appear in Change project provider. Reopening retains checkmarks; page refresh excludes those projects. External assignment still requires separate certificate enrollment.
4. Optionally exercise a stale row by assigning one selected disposable project to a different CA in another browser tab before submitting the first tab. Other selected projects should succeed; the conflicting PID is identified in the summary toast and stays selected. Refresh to reconcile that already-bound project. No additional renewal/revocation or Acrobat test is needed for this UI slice.
5. During a batch, confirm controls, duplicate submission and Close/X/Escape are blocked. Confirm provider retirement/reactivation still removes/restores its dropdown option and empty eligible-project/provider lists allow dialog dismissal.

## Bulk project provider changes — 2026-10-04

Run **node tests/provider_transition_ui.js**, **node tests/provider_assignment_ui.js**, **node tests/provider_workflows_ui.js** and **node tests/providers_admin_ui.js**. Real transition code uses paged/filtered DOM and AJAX doubles to check row/checkbox selection, retained hidden selections, fresh hashes, partial success, updated current/pending providers, built-in activation, bulk cancellation including pending CSRs/no active CA, failed/stale/ambiguous responses, unavailable refresh, busy guards and cleanup/reopen. Initial assignment's add-row contract is covered. **tests/provider_admin_view.php** renders escaped provider names, signer assignment, waiting and CSR states. It, **tests/pki_admin_ajax.php** and **tests/provider_transitions.php** pass on PHP 8.2/8.5. No live provider/certificate changes were made; these checks do not establish browser acceptance.

Browser checks pending:

1. Open **Change project provider** and check 5-row paging/search/hover (no page-length selector) and plain provider names without a Current provider prefix or bold styling, the selection/PID/name/status/provider columns, and current signer/pending-provider/CSR information. Click rows or checkboxes across pages/filters; the count includes hidden selections. Close remains the only regular dialog footer button. Closing/reopening clears selections without duplicate table controls.
2. Using disposable projects, select a different active external CA and **Change provider for the selected projects**. Success produces one summary toast, clears those checkboxes and shows the current provider plus the replacement awaiting a certificate. Existing signer/provider policy stays active until project-side enrollment and activation. The updated rows persist on reopen; another change is disabled while a pending transition exists.
3. Select pending rows and **Cancel pending provider changes for the selected projects**. Confirm their pending target/CSR clears, current provider and signer remain, rows are unchecked and the toast reports the result. Cancellation should also be available for a retired target. Selecting a mix of pending/non-pending rows disables cancellation; a standalone pending CSR prevents starting a change and must be canceled on the project page.
4. On disposable projects, change to the built-in CA. Confirm successful rows are unchecked and now show the built-in provider with a signing identity. A live seal/Acrobat check is useful if verifying this replacement end to end; no repeat of the full crypto matrix is needed for table styling.
5. Optionally use another tab to change one selected project's state before submission: the first tab refreshes that row and reports its PID without applying another change; other eligible selected projects can succeed. Busy controls and Close/X/Escape must block duplicate operations/dismissal. Failed rows remain selected, while unavailable refreshed metadata requires page refresh before another change.
6. Assign an initially unassigned disposable project through **Assign project provider**, then open Change project provider without refreshing the page: the new project and assigned provider should appear. Provider retirement/reactivation must still remove/restore target dropdown choices.
