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

1. In **TSA → Timestamping**, choose the test provider and a healthy primary, then one or two distinct alternatives. Save/refresh and reselect the provider: order must persist. Confirm the project status and CC provider card show it. Changing the primary clears alternatives/B-B; **No timestamp** disables and clears them. **Test source** must still probe only that source.
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

**First Acrobat round passed, user-reported on 2026-10-01.** Acrobat already trusted the root used for PID 524, and the user imported none of the certificates in the ZIP. All three PDFs (A/B/C) were displayed as certified and timestamped. This establishes acceptance of the same-key renewed root, including fresh project/TSA leaves, while the original trusted root is still valid. The separate expired-anchor certification check also passed as recorded below. This first round itself did not test expiry. This is a read-only developer probe, not an implemented renewal action.

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
3. Existing PID 524/TSA certificates do not gain CDPs retrospectively. To exercise normal project issuance, use the existing CC renewal workflow for a chosen built-in test project, then create a new consent PDF. Its new project certificate should carry the CRL URL; the existing TSA still lacks that extension until it is replaced by future lifecycle work. Do not reset the installation solely to complete this check.

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
