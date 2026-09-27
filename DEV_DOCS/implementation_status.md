# PDF Sealer implementation status

## Current summary — 2026-09-27

**PHP compatibility:** PHP 8.4+ is recommended; PHP 8.2 remains the minimum. Issuance now selects random 128-bit serials on PHP 8.4+ and EM-log-reserved integer serials on PHP 8.2/8.3. Live CC diagnostics and fresh-project integer-serial issuance passed in PID 524, with user-confirmed Acrobat acceptance of its B-B PDF. See the compatibility fix and acceptance notes below and the [serial allocation/recovery rules](../docs/pki.md#certificate-serials).

PDF Sealer produces terminal B-T or B-B seals for eligible eConsent PDFs when its operation is assigned and PKI is usable. Control Center administration, project status, public certificates, and their UI refinements have user approval. The root README and packaged project/admin/technical guides describe current behavior; [DEV_DOCS](README.md) holds implementation and testing material.

Selected Acrobat/DSS interoperability acceptance is complete for six multipage/merged B-B/B-T fixtures. Structure, cryptography, and expected profiles pass; DSS trusted validation remains indeterminate for the disposable untrusted root. The stored/downloaded record 18 comparison also passed. See [interop acceptance](pdf_interop_acceptance.md) and [pipeline acceptance](pdf_pipeline_acceptance.md) for exact scope. These results do not establish revocation/LTV, every PDF backend, or every live merge workflow.

Third-party attribution, reproducible namespace prefixing, and release-content checks are implemented. The module loads committed PHP libraries with its own autoloader; release archives exclude Composer manifests, lockfile, vendor directory, and runtime. See [release licensing](release_licensing.md). Automatic renewal/rotation, revocation publication, external TSA configuration, and B-LT/B-LTA remain unimplemented. The proposed Core footer-link change is deferred. Optional real alarm-mail receipt was not separately recorded as verified.

Project-copy and metadata-only XML export/import acceptance passed on this instance (524 → 525 and 524 → 526): no inherited signing identities, distinct destination certificates/public keys, and identity reuse on second sealing. Direct copy retained the pipeline but left the EM disabled; XML import required explicit enablement and pipeline assignment. Evidence and scope limits are recorded below. PMT setup and first sealing also passed for PID 527, with a distinct destination signer and user-reported Acrobat acceptance; PMT signer reuse was not separately tested. Missing pipeline transfer through XML and PMT is a deferred Core/Framework integration gap, not the intended final behavior; see [the deferred slice](#deferred-pdf-finalization-pipeline-transfer).

The next lifecycle work follows the [provider-aware design](provider_lifecycle_design.md): built-in and external CA providers, locally generated project keys/CSRs, and per-provider internal/external timestamp sources selected exclusively in the Control Center. This is planned work; external enrollment, timestamp sources, monitoring, and renewal are not implemented. The user confirmed greenfield scope: this is the only deployment, and development PKI/configuration can be discarded and recreated as needed; no legacy migration or compatibility layer is required. Fallback defaults and the implementation sequence are proposals recorded in that design.

## Implementation history

The sections below record bounded implementation slices, including tests and limitations at the time. Earlier counts and approaches may be superseded by later entries; they are not a second current specification. The [original plan](PDF_Sealer_EM_Implementation_Plan.md) is preserved as design history.

## Milestone 1: standalone RFC 3161 responder

`src/Timestamp/InternalTsaService.php` accepts DER `TimeStampReq` and returns a signed DER `TimeStampResp` for the v1 SHA-256 profile. It uses Tecnick's public ASN.1 codec, OIDs, certificate parser, request client, and CMS/token verifier. `tc-lib-pdf-sign` 2.0.4 has no public encapsulated CMS builder, so this module constructs only the `TSTInfo` CMS `SignedData` envelope and its required signed attributes. No OpenSSL CLI is invoked by production code.

`php tests/timestamp_spike.php` exercises request acceptance and rejection, imprint/policy/nonce/time/serial handling, a Tecnick round trip, and independent `openssl ts -verify` validation. This satisfies the plan's go/no-go gate for proceeding to PKI and PDF work.

## Milestone 2: PKI foundation

`src/Pki/CertificateIssuer.php` creates distinct RSA 3072 keys and SHA-256 certificates for a self-signed root, a dedicated TSA, and a project UUID identity. In REDCap, construct it with `CertificateIssuer::forFramework($module->framework)`; the Framework supplies and tracks the temporary OpenSSL configuration file. The root, TSA, and project certificate profiles follow the plan. `GeneratedIdentity` is transient and prevents serialization of private key PEM. `SecretProtector` wraps REDCap's global encryption/decryption functions with a version marker and rejects failures.

`php tests/pki_primitives.php` verifies certificate profiles, distinct keys, the root chain, invalid-root rejection, and an RFC 3161 response signed by the issued TSA. Both Tecnick and `openssl ts -verify` accept that response.

`IdentityRepository` now appends encrypted identity records to system-scoped EM logs and keeps active root/TSA IDs in system settings. `PkiHealthService` reports `UNINITIALIZED`, `READY`, `DEGRADED`, or `BROKEN` without creating replacement identities. A missing active root pointer after a root record exists is `BROKEN`; an unusable TSA leaves the signing root in `DEGRADED` state. `php tests/pki_storage.php` checks scope, encrypted storage, pointer integrity, key mismatches, and these health transitions with a Framework test double.

`ProjectIdentityService` now binds each REDCap PID to a stable pseudonymous UUID and issues a project certificate on first use. The UUID and active identity binding are append-only system-scoped logs. A primary-database advisory lock serializes issuance per PID, and Framework log pseudo-queries are executed on the primary connection so replica lag cannot bypass that lock; an interrupted write can reuse an unbound identity, while a corrupt active identity fails closed. The service accepts a healthy root even when TSA health is degraded, permitting future B-B fallback. `php tests/project_identity.php` covers repeat calls, separate projects, interrupted issuance, corrupt keys, broken roots, and lock timeout.

`PkiInitializationService` now provides an explicit, one-time initialization path through the superuser-only `pki-admin.php` Control Center page. The page collects the organization and shows read-only public root/TSA details after initialization. An instance-wide primary advisory lock rejects concurrent starts; the service refuses existing or orphan PKI material and writes the organization, root, TSA, and active pointers in one transaction. Initialization failures roll back instead of leaving a partial PKI. Root/TSA pointer reads use the primary database connection for immediate consistency. `php tests/pki_initialization.php` checks readiness, repeat refusal, orphan rejection, and rollback. No persistent PKI was initialized on the development instance during testing.

`AdminAlarmService` now appends system-scoped `pki_alarm` events and sends to the Control Center `admin-alert-recipients` setting. A per-condition primary-database advisory lock and the latest successful-mail log enforce one email per fingerprint per hour. Failed or unconfigured sends are recorded and do not start the throttle. Notification content contains only the severity, error code, identity ID, and time. `php tests/admin_alarms.php` covers persistence, recipient validation, throttling, retries, and lock contention. The PDF finalization hook raises these alarms for actionable PKI health failures.

### Control Center page follow-ups

The PKI Control Center page accepts comma-, semicolon-, or whitespace-separated alarm recipient addresses, validates them with the alarm sender's parser, and saves the repeatable system setting through a superuser-only JSMO AJAX action. Empty input disables alarm email. PKI initialization POSTs now return HTTP 303 to a GET of the page, including on failure, so refresh does not resubmit them. The page renders its Control Center header only after handling the POST to keep the redirect possible. `php tests/pki_admin_ajax.php` covers recipient validation, clearing, authorization, and storage failure. The user confirmed that the page passed an in-browser check of this PRG and AJAX slice. The page now also offers active-root PEM and DER downloads through a superuser-only JSMO action; the browser creates the file from the returned public certificate bytes. The focused AJAX test rejects unauthorized requests and unsupported download formats; the user confirmed PEM and DER downloads in the browser. The later administration and project layout slices completed the UI refinements; the user has approved the UI.

### Public trust distribution

The public trust page is linked from the PKI Control Center page through the configured survey URL with the exact `?pdf_sealer_certs` query marker. The survey host is a familiar entry point for respondents who may need the trust certificate. The module enables every-page hooks on system pages and login-form contexts so `redcap_every_page_before_render` can capture this anonymous survey request before REDCap handles survey access codes. The hook returns immediately unless the request is a GET with no project context, the exact query marker, and the configured survey path. This guard is necessary because the setting also invokes the hook on unrelated anonymous login pages. Direct anonymous module-page access is disabled.

The page lists the active root and all other system-scoped root certificates with subjects, SHA-256 fingerprints, and validity dates; it explains the self-signed trust model and the instance TSA. PEM/DER downloads use an allowlisted public JSMO action through the survey passthrough endpoint and browser-created files. `PublicTrustRepository` selects only public certificate fields, verifies each stored digest and self-signed CA certificate, and never loads encrypted private keys. `php tests/public_trust.php` covers multiple roots, the active pointer, the public-only query, and integrity failures.

The user confirmed the `?pdf_sealer_certs` page and PEM/DER downloads from both the public trust page and the PKI Control Center in the browser. A local anonymous HTTP request returned the trust page with status 200, its JSMO endpoint pointed to the survey host, and the former anonymous API URL no longer returned the page.

The project menu now shows the trust certificate link to all signed-in users in projects where this EM is enabled. A project configuration checkbox, `hide-project-trust-link`, opts that project out; an unset checkbox leaves the link visible. `redcap_module_link_check_display` returns the link for project users without design rights and delegates unrelated links to the Framework default. The menu item opens a small authenticated module page that redirects to the configured survey URL, because the Framework appends `&pid` to project menu URLs and the trust endpoint requires an exact query marker. `php tests/project_trust_link.php` checks default visibility, the opt-out, and unrelated-link permissions. The user confirmed that the project menu link works in the browser. Admins can also link to the public survey URL through standard REDCap configuration; no login-page injection is planned.

The repository, initialization path, and encryption wrapper have a live development-instance check: `PDF_SEALER_LIVE_TEST=1 php tests/pki_live_framework.php` boots REDCap, initializes a disposable root and TSA using the Framework temporary-file helper, reads their encrypted identities through real Framework logs, issues a project identity in an active project context, checks system scope and alarm throttling with a mock sender, and rolls back the outer test transaction. The test uses REDCap's actual `encrypt()`/`decrypt()` and confirms the rollback removed the records and settings. It sends no email. It must only run against a disposable development instance. `enable-no-auth-logging` is enabled because e-consent PDF finalization can run without an authenticated user. This verifies the services in a CLI REDCap bootstrap; it does not exercise the Control Center page in a browser or an e-consent web request. The PDF hook now uses these services.

## Milestone 3: existing-PDF structural adapter

`tc-lib-pdf-parser` 3.16.1 now reads cross-reference tables, cross-reference streams, `/Prev` chains, and object streams for `PdfStructureInspector`. The inspector resolves the current trailer, catalog, page tree and first page, existing AcroForm, document IDs, info reference, and highest observed object number. It rejects encrypted documents, an existing catalog `/Perms /DocMDP`, an unresolvable catalog or page tree, and malformed final `startxref`. PDFs with bytes before the `%PDF-` header are rejected because parser offsets are relative to that header. Existing signature fields are detected but are not yet processed.

`CosSerializer` handles the token types needed for dictionary and array revisions. `IncrementalRevisionWriter` appends revised or new indirect objects, a classic xref table, and a trailer with `/Prev` pointing to the input's final `startxref`; it never rewrites the original prefix. The benign revision is test-only. `php tests/pdf_structure.php` checks prefix preservation, `/Prev`, object allocation, AcroForm preservation, parsing of the result, and unsupported-input failures. It also exercises three optional local PDFs covering classic xref, xref stream, compressed objects, and AcroForm. Ghostscript processed all three revised local PDFs without errors. `qpdf --check` also passed for the generated test PDF and all three revised local PDFs, and is now part of `tests/pdf_structure.php`. Two actual REDCap-generated PDFs from the development test project (one form PDF and one all-records PDF) were exported read-only through `redcap_devctl`. Their source hashes matched the inspected edoc metadata. Both benign revisions preserved the full source prefix, parsed again, and passed `qpdf --check` and Ghostscript. The originals also passed `qpdf --check`. The exported bytes were kept out of the repository and removed after testing. `PDF_SEALER_REDCAP_PDF_PATH` makes this check repeatable with an exported artifact; the sealing hook is connected in Milestone 6.

## Milestone 4: standalone PAdES B-B seal

`PdfSealBuilder` now appends an invisible signature widget and field, updates the first page's `/Annots`, extends an existing or new AcroForm `/Fields`, sets `/SigFlags`, and sets catalog `/Perms /DocMDP` with `P=1`. It preserves existing fields, annotation actions, and unrelated permissions; direct first-page `/Link` annotations are emitted as equivalent indirect objects in the signing revision. This keeps REDCap's footer URI clickable and leaves the incoming bytes unchanged. Acrobat validated the PDF produced by this signing path as not modified after certification while the footer link remained clickable. The self-issued CA remains untrusted until configured as a trust anchor. It upgrades older PDF headers through catalog `/Version /1.7` and refuses certification after an existing signed field. Tecnick emits the signature and widget objects and signs the detached SHA-256 CAdES CMS with the project certificate and embedded root chain. The module's writer owns the fixed-width `/ByteRange` and reserved `/Contents` placement; CMS injection changes only the reserved hex characters after signing.

`php tests/pdf_seal_bb.php` verifies the unchanged original prefix, DocMDP and field placement, `qpdf --check`, Poppler `pdfsig` recognition and signed-range validation, OpenSSL CMS signature and root-chain validation, and rejection after a protected byte is altered. It passes on three generated PDFs and three optional local PDFs, including xref-stream/object-stream, existing-AcroForm, and indirect-array inputs. OpenSSL also confirms `signingCertificateV2` and the absence of a CMS `signingTime` attribute. A read-only export of a REDCap-generated form PDF from the development test project passed the same test; its source hash matched the edoc metadata, and the temporary export was removed. `pdfsig -nocert` recognizes a valid ETSI.CAdES.detached signature covering the entire document on all six PDFs; OpenSSL separately validates the disposable root chain. The user confirmed that Acrobat accepts current live e-Consent seals without modification warnings and retains the clickable footer link. DSS was not yet checked at this milestone; subsequent selected B-B/B-T structure, profile, and cryptographic acceptance is recorded in [interop acceptance](pdf_interop_acceptance.md), with private-root trust limitations. Ghostscript renders the signed PDFs but warns that it does not implement an invisible signature field without an appearance stream. Hook integration is described in Milestone 6.

## Milestone 5: standalone PAdES B-T seal

`PdfSealBuilder::sealTimestamped()` now passes Tecnick's B-T profile a timestamp client and an in-process `TimestampProvider`. `InternalTimestampProvider` calls `InternalTsaService` directly; no HTTP call returns to REDCap. The provider exchanges DER requests and responses rather than the plan's digest-only sketch, allowing Tecnick to own nonce construction and token validation. Tecnick requests the RFC 3161 token over the CMS signature bytes, validates its imprint, nonce, policy, signature, and TSA certificate, and embeds it in the CMS unsigned attributes. The B-T signature reserves a larger fixed `/Contents` region. The returned `PdfSealResult` includes the verified profile and token serial/time for the hook's seal event. The existing `seal()` method still produces B-B. A TSA failure raises an exception, leaving fallback to the hook integration slice; it cannot be reported as B-T success.

`php tests/pdf_seal_bt.php` checks B-T sealing on the six generated/local PDFs and a read-only REDCap-generated form PDF export. All seven passed the existing structural, `qpdf`, `pdfsig`, OpenSSL CMS, and tamper checks. The new test also confirms that the request imprint hashes the actual CMS signature bytes, the embedded token serial/time match the result, and OpenSSL verifies the RFC 3161 response and root chain. A simulated TSA failure is rejected, and a separate B-B seal contains no timestamp token. The temporary REDCap export was removed. Acrobat reports an embedded timestamp on live sealed PDFs; DSS profile acceptance was not yet available at this milestone; it is now recorded for the selected fixtures in [interop acceptance](pdf_interop_acceptance.md).

## Milestone 6: PDF finalization hook

`redcap_module_pdf_finalize` now delegates the declared e-Consent `seal` operation to `PdfFinalizeService`. The service checks the context project ID against any ambient Framework project ID and PKI health, gets or issues the project's signing identity, and reads the Framework working PDF. It uses the instance TSA for B-T when `timestamp_mode` is `internal` and health is ready. With no `tsa_policy_oid` setting, the fixed PDF Sealer TSA Policy v1 OID (`2.25.186172099785128831488612506224552954430`) is used; it is derived from UUID `8c0f7132-9d42-4240-b259-da71e931ca3e`. An explicit OID setting overrides it. With `bb_fallback` unset or `1`, an unavailable or failed TSA causes a separate B-B signing attempt; `timestamp_mode=none` selects B-B directly. Disabling fallback makes the operation fail. Success writes only the Framework working copy and returns `modified` with `terminal=true` and profile/timestamp diagnostics. Failures return `failed`, so the Framework discards that copy. PKI health failures invoke the existing throttled alarm service when a specific alarm code is available. The hook writes concise success and failure outcomes to the project Logging page and keeps extended EM diagnostics for failures.

The opt-in `tests/pki_live_framework.php` transaction now invokes the actual hook against a generated PDF on the development instance. It passed default-policy B-T with no OID setting, explicit-policy B-T, B-B fallback after an invalid policy override, disabled-fallback failure with unchanged working bytes, B-B-only mode, and a context-only project ID with no ambient Framework project ID. `qpdf --check` and `pdfsig -nocert` accepted the successful hook outputs. The test confirmed that PKI records and settings rolled back and removed its temporary working file. A first-use certificate timing issue surfaced during this test: REDCap's request-stable `NOW_UTC` can precede a certificate issued later in the same request. The hook now signs at the later of `NOW_UTC` and the current time. No project PDF was committed or replaced.

## Built-in TSA policy correction

The built-in policy no longer depends on a configured institutional OID. `TsaPolicy` defines the fixed UUID-derived `2.25` OID. Tecnick's ASN.1 codec converts OID arcs to PHP integers and cannot encode or decode a 128-bit UUID arc, so `PolicyOidAsn1` extends only the selected policy OID path while leaving all other ASN.1 work with Tecnick. The signing client omits optional `reqPolicy`; `InternalTsaService` accepts the same OID when explicitly requested and returns RFC 3161 `unacceptedPolicy` for a different one. The B-T builder also checks the embedded token's policy against the selected policy. `tests/timestamp_spike.php` now covers omitted, matching, and unsupported `reqPolicy` values, including another UUID-derived OID; OpenSSL verifies the resulting timestamp response. The standalone B-T test and rollback-contained live hook test pass with the built-in default.

## Milestone 7: project Logging and failure diagnostics

`PdfFinalizeService` calls `REDCap::logEvent` with an explicit project ID for each valid-context seal outcome. Success entries show PAdES B-T or B-B and identify timestamp fallback; failure entries show a generation-ID reference. The hook passes the context's record ID and event ID when available, so users with the Logging right can filter by them. When an event ID is absent, the hook prevents `REDCap::logEvent` from inheriting an unrelated query-string event ID. A mismatched or missing project context is not logged to a guessed project.

`SealEventRepository` appends extended, system-scoped `seal_event` records only for failures. They contain the generation ID, PID, project UUID and certificate identity when available, input digest, fixed error code/message, and failure outcome. They contain no record ID, instrument, or clinical context. Failure-diagnostic logging is best effort and does not mask the sealing error. `REDCap::logEvent` returns no insertion status; the hook treats a thrown logging exception as a sealing failure.

The rollback-contained live test verifies project Logging entries for B-T, B-B fallback, B-B-only, and failed attempts; record/event association; no inherited event ID when context omits one; failure-only EM diagnostics; and rollback of both log destinations. The test writes no lasting PDF or log entry.

## Project status page

`project-status.php` adds a read-only project menu page using the Framework's normal administrator/Design-right access rules, with an explicit matching page guard. `ProjectPipelineStatus` reads the Framework's configuration state and reports unavailable Core/Framework support, missing assignment, unresolved entries, or pipeline warnings (including earlier terminal operations and duplicate assignments). It shows the positions of this module's `seal` operation. Pipeline assignment is configuration evidence, not proof that a PDF generation pathway invoked the hook.

`ProjectIdentityService::inspect()` reuses the signing identity checks without entering issuance or acquiring a lock. The page shows instance PKI health separately, alongside the project UUID and public certificate metadata. Unissued, interrupted issuance, expired, not-yet-valid, unreadable, and unusable identities are distinguished. Only public metadata reaches the view; no key material is rendered. Reading status does not create identities, repair bindings, write logs, or renew certificates. Actual seal outcomes remain in project Logging.

`tests/project_identity.php` checks read-only status for missing, active, pending, expired, and corrupt identities, including unchanged storage and no issuance lock calls. `tests/project_pipeline_status.php` checks configuration availability, assignment, document type, and warnings. `tests/project_trust_link.php` confirms that the new status link retains normal permissions while the public-root link keeps its broader project visibility. These focused checks pass. The user subsequently approved the project page and its later styling.

The user confirmed live sealing after the hook rename to `redcap_module_pdf_finalize`, with PDFs looking correct in Acrobat. The renamed CA/TSA were initialized and the project identity was issued on the next eligible seal after restoring the Core checkout containing the PDF finalization integration.

## Control Center timestamp settings

The PKI page now offers instance-wide timestamp mode (`internal` for B-T, `none` for B-B) and the B-B fallback policy. A superuser-only, non-project JSMO AJAX action validates both choices, writes their existing system-setting keys through Framework helpers in one transaction, and rolls back on failure. The fallback setting remains stored as `"1"` or `"0"`, matching the primary-connection reader. Its preference is retained in B-B-only mode and applies again when internal timestamping is selected. No settings are written on page load.

`TimestampSettings` supplies the same strict parsing and defaults to the page and finalization service: internal timestamping and enabled B-B fallback when unset, with existing string forms still supported. Unreadable or invalid persisted values produce an explicit warning and empty selectors instead of pretending defaults are active. The page explains the instance-wide scope and that a failed seal leaves the preceding PDF available to REDCap. The policy OID override is unchanged and has no UI control in this slice.

`tests/pki_admin_ajax.php` covers all four mode/fallback combinations, defaults and legacy string forms, exact stored types, malformed requests, authentication scope, and rollback after first/second-write and transaction failures. These checks pass. Browser acceptance was requested at this milestone; the user subsequently reported successful browser checks and approved the completed administration UI.

## Control Center diagnostic self-test

`run_diagnostic` is an authenticated JSMO AJAX action restricted to superusers outside project context. It accepts no custom input. `PkiDiagnosticService` reports seven checks: encryption round-trip, active root, active TSA, temporary signer issuance, B-B sealing, RFC 3161 response validation, and B-T sealing. Failed prerequisites cause dependent checks to be skipped; an unavailable TSA still permits the B-B check. Both profiles are tested independently of the saved mode/fallback settings. An invalid policy fails the timestamp check instead of producing a fallback success.

The service reuses the stored active root/TSA and REDCap encryption helpers. It issues a disposable project-profile signer with a fresh UUID in memory, without project binding or identity persistence. The Framework supplies the temporary OpenSSL configuration file, and the issuer removes it. PDF bytes and keys remain server-side; only fixed check IDs, statuses, and an aggregate result reach the browser. No project logs or alarms are emitted, and no sealing settings, certificates, or PDFs are saved. The later layout slice adds storage of a minimal diagnostic outcome snapshot, as described below. The encryption probe round-trips in memory; it does not test database writes.

`SampleSealVerifier` parses the generated sample's certification signature and widget, checks DocMDP P=1 and complete ByteRange coverage, and uses Tecnick's CMS verifier to validate the detached digest, signature, and signing-certificate binding. B-B must have no timestamp, while B-T must have one verified token signed by the active TSA. The existing B-T builder also validates imprint, nonce, policy, time, and timestamp metadata. The diagnostic timestamp client follows the sealing path by omitting optional reqPolicy because Tecnick's Config cannot encode a UUID-sized OID arc; the existing PolicyOidAsn1 adapter handles the response. This is a bounded local capability test, not an independent PDF/PAdES validator or project pipeline test.

`php tests/pki_diagnostic.php` passes with disposable certificates and Framework/encryption test doubles. It covers healthy default/override policies, B-B-only production configuration, missing/corrupt PKI, issuance/configuration failure, encryption failure, failed/skipped reporting, unchanged storage, temporary-file cleanup, and rejection of altered signed bytes or unexpected signer/profile. `tests/pki_admin_ajax.php` covers the action's authentication allowlist, superuser/non-project restriction, and rejected caller-supplied data. The user confirmed that the diagnostic browser test looks good. The later layout slices completed styling and have user approval.

## Synthetic consent and attachment fixture coverage

`tests/pdf_redcap_fixtures.php` generates five disposable cases using the installed REDCap tFPDF/FPDF_HTML backend and `PDF::setFooterImage()` with synthetic content. They cover an alpha-channel signature PNG, three-page output with footer links on every page, footer suppression, a qpdf-merged landscape attachment, and rotated pages with xref/object streams. It does not bootstrap REDCap, read project data, modify Core, or persist certificates/PDFs. The test uses built-in fonts and makes no writes to Core's font cache.

Both B-B and B-T pass on all five cases. qpdf checks the input and sealed PDF; pdfsig confirms one valid whole-document signature; OpenSSL validates the detached CMS/root chain and rejects tampered covered bytes. For B-T, OpenSSL now independently verifies both the RFC 3161 response and the token extracted from the final PDF against the actual CMS signature bytes. Poppler renders every source and sealed page identically at 72 dpi; extracted text, ordered footer URI targets, and link rectangles also match. The signature image is verified present before sealing. No production fix was required.

Existing verification code moved to `tests/support/` so the new fixtures and original B-B/B-T suites share the same checks. The original suite still passes on eight available generated/local fixtures in both profiles. Full synthetic fixture details, tool versions, and remaining live/Acrobat/DSS acceptance boundaries are in `pdf_fixture_coverage.md`.

## Real Core/Framework dispatch acceptance

`tests/pdf_pipeline_live.php` adds a preview-first development-instance check against an already initialized project. The preflight requires healthy root/TSA, an existing usable project signer, an execution plan containing only `pdf_sealer:seal`, and InnoDB for the tables the probe can write. It uses the configured timestamp mode without changing settings, identity bindings, or the pipeline. Synthetic fixture generation runs in a separate process so fixture-only class/global substitutes never enter the live REDCap process.

All five fixtures passed through both `PdfFinalizer::finalize()` and `PdfFinalizer::finalizeContents()` on PID 461, using actual Framework resolution and `redcap_module_pdf_finalize` dispatch. All ten outputs passed B-T signature, embedded timestamp, unchanged-prefix, rendering/text, and footer-link checks. Framework events confirmed terminal adoption, the expected profile, and matching final SHA-256. A `record_pdf` context bypassed the eConsent-only operation unchanged. A previously certified input produced a controlled failure and retained its original bytes.

The Framework rolls back before and after each hook. The first harness revision used START TRANSACTION alone, which was ended before the first hook; eleven synthetic project logs and one failure diagnostic therefore persisted. Those exact test entries were removed with previewed `redcap_devctl` operations (the diagnostic's parameters cascaded). That first run also advanced the project's normal activity timestamp; it was not reset because doing so could overwrite concurrent activity. The corrected harness disables autocommit across hook boundaries, rolls back before restoring it, and confirms no test project/EM logs survive. The corrected run passed, and an independent dev-control query confirmed zero remaining synthetic project logs. No edocs were created or replaced.

The runner tests dispatch and artifact handoff, not storage or HTTP delivery. `pdf_pipeline_acceptance.md` documents the saved-snapshot/download acceptance, now passed for record 18. The dev-control API supports database/edoc inspection but no application-test transaction runner; a previewable development application-test runner would be a useful enhancement. No Core or Framework changes were made.

## Record 18 storage/download and Acrobat acceptance

The user supplied the saved eConsent PDF for PID 461, record 18, and confirmed Acrobat acceptance. The archive metadata identifies edoc 2335, event 1423, instance 1, snapshot 266, with 89,378 bytes and MIME `application/pdf`. Its SHA-256, supplied by the user from the stored file, exactly matches the agent-calculated download digest: `f5d84731e23e4d15c73d9596457a381decd6f9d225b5613ebefef24e6e110594`.

qpdf, pdfsig, detached CMS/root-chain verification, embedded timestamp imprint/chain verification, DocMDP P=1, unchanged rendering/text, and footer-link preservation all passed. The timestamp is 2026-09-26 17:02:15 UTC under the built-in TSA policy. The root fingerprint matches the public PKI record, and project B-T success entries associate record 18 with event 1423. Full evidence and scope are in `pdf_pipeline_acceptance.md`.

Dev-control database inspection succeeded, but its edoc inspect/hash/export operations failed to return a valid JSON envelope. Filesystem permissions prevented direct stored-file reads; the user's `sudo sha256sum` result completed the comparison. No permissions or stored data were changed, and temporary verification artifacts were removed. The edoc-tool error remains a tooling issue to investigate separately.

## Multipage/merged manual interoperability bundle

`tests/pdf_redcap_fixtures.php --export-dir /absolute/new-directory` now optionally retains the ten locally verified B-B/B-T fixtures, public test root (PEM/DER), SHA-256 manifest with expected profiles/page/link counts, and `pdf_manual_validation.md`. The directory must be new, files use exclusive creation, incomplete exports are removed, and private keys remain transient. Default execution still cleans up all generated material. `DEV_DOCS/interop-artifacts/` is Git-ignored.

The initial manual bundle was `DEV_DOCS/interop-artifacts/acceptance-20260926-01`, generated 2026-09-26 at 17:11 UTC with disposable root fingerprint `93d9b2177ca8aa019ce182738aa238b02025c4b8aa064c43ff0ac8a2973ad0a4`. All ten outputs passed local checks; exported file hashes/sizes match the manifest. The user subsequently reported a modification warning for `consent-multipage-BT.pdf` and an empty Signature Panel for `merged-object-streams-BT.pdf`. This initial bundle was superseded by the corrected `acceptance-20260926-02` bundle; its completed DSS findings are recorded below and in [interop acceptance](pdf_interop_acceptance.md).

The checklist links to the official DSS validation demo and requests Detailed Report and Diagnostic Data alongside exact PDF filenames. Trust/revocation findings and structural/cryptographic findings must be recorded separately. Public test-root availability does not establish trust in the DSS demo. No documents have been uploaded, no certificates installed as trusted, and no production code/PKI settings changed in this slice.

## Multipage annotation and xref-stream corrections

The Acrobat failures exposed two gaps in the original adapter. `PdfStructureInspector` now visits the whole page tree, including later branches, and resolves indirect annotation arrays for all leaves. `PdfSealBuilder` externalizes inline Link annotations on every page. Changed annotation arrays are local to their page, avoiding accidental replication of the first-page signature widget when an input shares an indirect array. Original bytes, link dictionaries, page contents, and existing indirect annotations are preserved.

`IncrementalRevisionWriter` now retains the latest input revision's cross-reference format. Stream-based inputs receive a new xref stream with direct `/W`, `/Index`, `/Length`, trailer entries and `/Prev`, including an entry for the stream itself; classic inputs retain classic tables. This follows the stream structure in [ISO 32000-1, section 7.5.8](https://opensource.adobe.com/dc-acrobat-sdk-docs/pdfstandards/PDF32000_2008.pdf). The user subsequently confirmed Acrobat acceptance of the corrected merged/object-stream fixture, resolving the previously empty Signature Panel.

`tests/pdf_structure.php` passes with three optional local fixtures, including format preservation and rejection of a cycle after the first page. The B-B/B-T suite passes on nine PDFs per profile, adding a nested three-page tree with shared indirect annotation arrays. Shared verification now checks that every Link is indirect, unchanged, and retained in order, with exactly one added annotation on the first page. All ten REDCap-backend fixture seals pass qpdf, pdfsig, OpenSSL CMS/root-chain and embedded timestamp checks, tamper rejection, and identical rendering/text/link checks.

Fresh manual bundle: `DEV_DOCS/interop-artifacts/acceptance-20260926-02`, root SHA-256 `81194a6299bb700ed398dddd50930ea224cf1919a1a283f770cd29642341fbfd`. Both initial B-T fixtures now have user-confirmed Acrobat acceptance: no modifications since certification. No Core/Framework or database changes were made.

Separately, the user's Docupilot screenshot displayed `21 January 1970 17:20:42 UTC` for the accepted record 18 file. Its PDF `/M` is `D:20260926170215Z`, matching the independently verified embedded timestamp. The Unix seconds value `1790442135`, incorrectly interpreted as milliseconds, gives exactly `1970-01-21 17:20:42.135 UTC`. This strongly indicates a date-display conversion error in that validator; the sealer's encoded date does not need changing.

## DSS and Acrobat interoperability results

The supplied DSS v6.5 diagnostics and Detailed Reports for `consent-multipage-BT.pdf` and `merged-object-streams-BT.pdf` match the replacement bundle's unsigned-revision hashes, signature fields, ByteRanges, covered-byte digests, CMS signature values, timestamps and root fingerprint. The sealed PDFs' full hashes still match their manifest. Both are recognized as PAdES-BASELINE-T with valid structure, document signatures and embedded signature timestamps. Format checking (including DocMDP), cryptographic verification, signature acceptance and algorithm checks pass.

Overall DSS validation is INDETERMINATE/NO_CERTIFICATE_CHAIN_FOUND for both signature and timestamp because the included disposable root is not a trusted anchor. The diagnostic data contains both signer → root and TSA → root chains; this is not missing embedded certificate material. No revocation evidence is present and no LT/LTA status is claimed. The user confirms Acrobat's no-modification result for both corrected PDFs. These findings close the two reported compatibility failures; they do not extend manual acceptance to the other fixture variants. See [interop acceptance](pdf_interop_acceptance.md) for the preserved report hashes and exact validation times. This slice changed documentation and local acceptance records only.

### Remaining manual variants completed

The follow-up used `consent-multipage-BB.pdf`, `merged-object-streams-BB.pdf`, and both profiles of `merged-attachment` from the same `acceptance-20260926-02` bundle. Their hashes and sizes match the manifest. The user confirmed Acrobat's “not modified” result for all four. DSS v6.5 recognizes the three B-B files as PAdES-BASELINE-B without timestamps and the B-T attachment as PAdES-BASELINE-T with one valid timestamp. Format, signature acceptance, cryptographic and algorithm checks pass. The only reported overall blocker remains INDETERMINATE/NO_CERTIFICATE_CHAIN_FOUND due to the untrusted disposable root.

All eight new reports were correlated to the source artifacts and preserved with hashes, bringing the acceptance record to six PDFs and twelve reports. The selected integrity/profile interoperability round is complete; no repeat manual checks are recommended now. Acrobat page/link appearance and timestamp display were not separately confirmed, while local rendering/text/link checks passed. Single-page/no-footer controls and actual REDCap merge workflows are not newly claimed as manually accepted. No regeneration, production-code change, PKI change, or repeat run of the sealing tests was needed for this review. See [the completed record](pdf_interop_acceptance.md).


## Control Center administration layout

The Control Center link is now **PDF Sealer administration**. Its page uses the reference EM's compact brand/title treatment: **PDF Sealer** above **Certificates & sealing**, status/organization/timestamp summary cards, and the public-certificate link above four keyboard-accessible tabs. Root CA contains initialization and public-root downloads; TSA contains its certificate and timestamp settings; Diagnostic contains the self-test and cached result; Alarms contains recipient configuration and a confirmed test-send action. Existing initialization PRG, JSMO settings saves and browser-created downloads are retained. The shared `assets/admin.css` styling is also used by the project page, as described below.

`DiagnosticSnapshot` saves only the completion epoch, overall outcome and seven fixed check statuses under `last-diagnostic-result`. It validates stored data and excludes test artifacts and key material. Opening the page reads the snapshot without running the self-test. The initial UI displayed UTC time (subsequently changed to browser-local time below) and elapsed days; passing results are green for less than 24 hours, neutral below 7 days, then light/medium/strong red at 7/14/30 days. Failed results remain red. Age updates while the page stays open using the server time at load; results are explicitly labeled as historical. Execution failures retain the preceding result, and persistence failures show the fresh result with an unsaved warning.

`send_test_alarm` is authenticated and restricted to superusers in system context. It requires an explicit boolean confirmation payload and accepts no caller-supplied recipient destination. The browser displays saved recipients in its confirmation and refuses unsaved edits. The existing alarm service sends a translated, clearly marked test through REDCap email and records `TEST_ALARM` / `test`; its separate fingerprint uses the normal one-hour successful-send throttle without affecting real alarms. No test email was sent during implementation.

PHP syntax, rendered JavaScript syntax, translation parsing, `tests/diagnostic_snapshot.php`, `tests/admin_alarms.php`, `tests/pki_admin_ajax.php`, and `tests/pki_diagnostic.php` pass. Tests cover cache reload, failed results, malformed data, minimal persistence, write failure, test-alarm throttling/retries and isolation from real alarms, confirmation payload validation and unauthorized dispatch. Mail delivery was mocked. The user subsequently approved the Control Center layout and completed UI work. Optional real test-email receipt was not separately reported; automated delivery tests used a mock sender. No Core, Framework or instance PKI changes were made.


## Project status page styling

Following the user's approval and small visual adjustments to the Control Center layout, `project-status.php` now uses that same shared stylesheet and branding. **PDF Sealer** appears above **Project sealing status**, followed by summary cards for pipeline assignment, instance PKI readiness and the project certificate. The public-certificate link sits above stacked pipeline, PKI and certificate sections. There are no tabs or new actions. Certificate fields use aligned labels, UTC validity dates and the same neutral fingerprint treatment as the Control Center. Existing detailed state explanations, pipeline positions, unissued-certificate messaging and Logging guidance remain present.

Only presentation and translated summary labels changed; access checks and read-only inspection behavior are retained. The existing Control Center styles were preserved, with one additive class for standalone section spacing and rounded corners. PHP syntax, language-file parsing, status-label coverage and diff checks pass. The user subsequently accepted the project styling, adjusted its maximum container width, and confirmed that UI work is complete.


Certificate subjects now use a shared escaped HTML formatter on the Control Center Root CA/TSA panels, project status page, and public trust page (current and historical roots). Each slash-delimited attribute starts on a new line; literal slashes within values are retained. The user's project container-width adjustment is preserved. PHP syntax and focused formatting/HTML-escaping checks pass.

## Documentation by audience

The root README is now the repository primer. `config.json` selects `docs/PROJECT.md` for project documentation and `docs/ADMIN.md` for system documentation, retaining `README.md` as the general fallback. Packaged references cover PKI and sealing/validation. The developer index and testing guide collect development navigation and the commands formerly in the root README. Historical plans are labeled, resolved UI/DSS pending statements point to later outcomes, and package-facing developer links use GitHub URLs.

All local Markdown links were checked. The installed Framework Markdown renderer and REDCap Parsedown rendered six packaged pages in both project and system contexts, with URL/path environment helpers substituted; their links resolved and retained project context. A disposable 134-file working-tree ZIP assembled with the export-ignore rules contained every configured guide and its local link targets, excluded developer docs/tests/tools/fixtures, and passed the license checker. JSON parsing, PHP lint for the notice generator, and diff checks passed. No live application/database or PDF sealing tests were needed. Browser confirmation of the two documentation entry points remains the user's final check.

## Prefixed dependency bundle without Composer runtime

The three pinned Tecnick packages are now committed under `libraries/`, with all 48 PHP symbols under `DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\...`. Module code and tests use the module-owned `autoload.php`. No aliases are registered for original namespaces. The original `vendor/` installation remains an ignored development build input; release exports exclude it and both root Composer files.

`tools/build-dependencies.php` validates complete upstream contents against the existing reviewed hashes, then reproducibly prefixes PHP name tokens and PHPDoc references. It retains license/copyright headers, adds dated source and package modification notices, and retains all upstream runtime PHP source. It omits Composer metadata and the upstream installation README. The committed bundle manifest supplies notice metadata; the license checker verifies the reviewed complete bundle and rejects Composer files in extracted releases. Root MIT and upstream LGPL license texts are unchanged. See [the build and release procedure](release_licensing.md).

`tests/dependency_isolation.php` checks all 48 bundled symbols and the module adapter against incompatible unprefixed stand-ins in both loading orders. All 15 selected standalone suites passed, including PKI/storage/initialization, project identities, alarms/AJAX, diagnostics, public trust, project status, and PDF structure. B-B/B-T checks passed for nine available PDFs per profile, including independent signature/timestamp verification. All five synthetic REDCap-backend cases passed in both profiles with unchanged rendering, text, and links. PHP lint passed for 121 files; deterministic rebuild and diff checks passed.

A disposable 119-file working-tree archive built using the export-ignore rules contained no Composer files and all packaged Markdown link targets resolved. The extracted package passed license and load-order checks. A disposable copy of the test scripts ran timestamp and B-B/B-T verification against its packaged source/loader with no vendor directory, then was removed. Negative checks rejected a Composer manifest/runtime, missing license/modification record, changed library source, and stale notices. No release was published and no live database, stored identities, Core, or Framework files were changed. These checks ran on the installed PHP runtime; executing the suite on PHP 8.2 remains a separate compatibility check.

## PHP 8.2.34 compatibility check (initial failure; resolved below)

The user reported switching the web runtime to PHP 8.2.34. Both CLI `php` and `/usr/bin/php8.2` also reported 8.2.34 during this check; cron's executable/configuration was not inspected or changed. All tests below were invoked explicitly through `/usr/bin/php8.2`. OpenSSL reported 3.0.13; OpenSSL, hash, JSON, PCRE, zlib, and GD extensions were loaded.

All 121 PHP files passed syntax checks, and dependency reproducibility and attribution checks passed. Eight standalone suites passed: dependency isolation (both load orders), timestamp spike, alarms, admin AJAX, diagnostic snapshot, project trust links, pipeline status, and PDF structure.

Eight suites were blocked by the same `ArgumentCountError` at `CertificateIssuer::issue()`: PKI primitives, PKI storage, initialization, project identities, diagnostic, public trust, B-B/B-T sealing, and REDCap PDF fixtures. Issuance calls `openssl_csr_sign()` with a seventh `serial_hex` argument. The [PHP manual](https://www.php.net/manual/en/function.openssl-csr-sign.php) documents that argument as introduced in 8.4.0; PHP 8.2 accepts only the integer serial parameter.

The dependency constraints and generated Composer platform check had correctly described the libraries' minimum but did not establish this module's actual runtime compatibility. Certificate issuance with its random 128-bit serials is the identified blocker. No serial-length downgrade, minimum-version change, or custom certificate-signing workaround was made. At that point, a user decision was pending because preserving 128-bit serials on PHP 8.2 required more than adjusting the argument count. The subsequent approved compatibility path is recorded below. Tests used disposable identities; no installed certificates, database records, PHP configuration, or cron jobs were changed. The successful standalone timestamp test does not establish a complete live PHP 8.2 sealing workflow.

## PHP 8.2/8.3 certificate serial compatibility (2026-09-27)

The approved implementation keeps PHP 8.2.0 as the minimum and recommends PHP 8.4+. `CertificateIssuer::forFramework()` wires Framework temporary files and `CertificateSerialAllocator` into every production issuance path. PHP 8.4+ uses the seventh OpenSSL argument for a positive 128-bit serial with 127 random bits and the high bit set; PHP 8.2/8.3 uses only the six-argument API with an integer serial. Existing certificates are reused without migration.

For integer issuance, the allocator inserts a system-scoped `pki_serial_reservation` through `framework->log()` and uses its returned ID. This supplies atomic allocation plus a reservation record containing role, issuer fingerprint, and issuance/diagnostic purpose, with no clinical record, certificate, or key. Reservation failures and IDs outside the native integer/C-long range stop issuance. The exported certificate's serial is checked against the allocation. No serial is derived from a SELECT MAX, wall-clock time, or process-local production counter.

Initialization reserves before its identity-storage transaction, so a later rollback leaves unused reservation records; retry allocates new values. The allocator does not commit or alter a caller transaction. Diagnostic temporary signers also reserve integer serials; the diagnostic UI and guides now disclose that metadata write. Timestamp-token serial generation is unchanged. [Recovery guidance](../docs/pki.md#certificate-serials) covers preserving allocation records and the log sequence, restoring backups, and avoiding independent integer issuance from cloned databases sharing a CA.

Verification: all 17 selected suites pass on PHP 8.2.34, including serial bounds/failures, PKI profiles/storage/initialization, project identities, diagnostic persistence, public trust, B-B/B-T sealing, and all five synthetic REDCap PDF fixtures in both profiles. PHP 8.5.11 passes serial allocation/branch checks, PKI profiles, initialization rollback/retry, diagnostics, and B-B/B-T sealing. The integer maximum survives an actual OpenSSL issuance, and the PHP 8.5 path succeeds with an allocator configured to throw if called. Test runs used a temporary RANDFILE to avoid the PHP 8.5 CLI attempting to write random state outside the sandbox. All 124 PHP files pass PHP 8.2 syntax checks; config/documentation paths, edited guide links, translation parsing, dependency reproducibility, attribution, and diff checks pass.

A read-only `redcap_devctl` schema query confirmed that the main instance's EM `log_id` is an InnoDB unsigned BIGINT AUTO_INCREMENT. No live database writes, certificates, PHP settings, cron settings, Core, or Framework files were changed during the implementation checks. Actual simultaneous live database issuance was not tested. The initially pending browser diagnostic and fresh-project issuance checks were subsequently completed in PID 524, as recorded below.

## Live PHP 8.2 acceptance — PID 524 (2026-09-27)

The user reports that all Control Center diagnostic checks pass. They created project 524, enabled PDF Sealer, configured eConsent, submitted record 1, and downloaded its resulting PDF from the File Repository. Acrobat accepted that download as certified and unmodified.

Read-only `redcap_devctl` inspection confirms that a new project identity was created: serial reservation **35937**, certificate record **35938**, and active binding **35939**. Parsing the stored public certificate with PHP 8.2/OpenSSL confirms serial **35937** (hex **8C61**), exactly matching the reservation. Project Logging entry **1033** records **PDF seal succeeded**, **PAdES B-B**, record **1**, event **1589**. This verifies that the live workflow exercised new integer-serial issuance, rather than reusing project 461's existing signer.

This closes the requested PHP 8.2 diagnostic and first-project browser acceptance. The live PDF in this check was B-B; the passing diagnostic covers both B-B and B-T in-process. Acrobat acceptance is user-reported, and no downloaded PDF was supplied for independent byte/hash or timestamp analysis in this round. See [the acceptance record](pdf_pipeline_acceptance.md#php-82-first-project-acceptance--pid-524) for the identifiers and evidence boundaries. Only developer documentation was changed; database inspection did not modify live data or PKI.

### Return to PHP 8.5 — diagnostic passed

After switching back to PHP 8.5, the user reports that all Control Center diagnostic checks pass. This records the live post-switch check of stored PKI, temporary signer issuance, and both B-B/B-T diagnostic sealing paths. It does not add a new saved-PDF or project-524 signer-reuse test. No further compatibility acceptance step is pending for this slice.


## Diagnostic run time in the browser's local time zone

The Control Center diagnostic completion time now uses the browser's local time zone, with a zone label, and the user's REDCap profile date/time format. `DateTimeRC::get_user_format_full()` supplies date order, separators, and 12/24-hour preference, falling back to REDCap's system default when no user preference is set. The shared renderer applies this to both saved results after refresh and newly completed AJAX runs. The stored epoch, machine-readable ISO timestamp, server-based elapsed-age calculation, and certificate validity dates in UTC are unchanged. The administrator guide describes the display.

The actual JavaScript display block was exercised in Node with Europe/Berlin and America/New_York time zones for summer and winter dates; the expected local hours and unchanged ISO timestamp passed. PHP syntax and diff checks passed. Browser visual confirmation remains with the user; no diagnostic rerun is required to see a saved result in local time.

The profile-format refinement was checked against REDCap's actual `DateTimeRC::format_user_datetime()` output: 108 comparisons across all 18 supported formats, midnight/noon, winter/summer dates, and Europe/Berlin and America/New_York time zones passed. The system-default fallback, PHP syntax, and diff checks also passed. No profile settings were changed.

## Project-copy acceptance — PID 524 → 525 (passed for tested scope)

The user copied PID 524 into PID **525**, **PDF Sealer Test COPY**, and reported taking no further action. Read-only `redcap_devctl` inspection before enablement or first sealing confirmed:

- Both projects have the PDF execution plan `["pdf_sealer:seal"]`.
- PDF Sealer is enabled in 524 but not 525; system-wide enablement is false. This matches Core/Framework copy behavior, which copies the execution plan and project settings while excluding the `enabled` flag.
- PID 525 has no `project_identity_binding` records (no assigned UUID or active certificate) and no PDF sealing success/failure entries. The copied project did not inherit the source's identity binding.
- Each project has one eConsent configuration and one PDF snapshot configuration. This establishes their presence, not field-by-field equivalence.
- The source binding remains UUID `08246aca-8f7d-4e44-8be1-293cd171d9d1`, identity `41754b15ec74d6b2ec773d511f6b0236`, certificate record 35938, SHA-256 `5ea92dd263b4bc7d4495263ea5f3953f0a0349a8751ecffcaeea8d7f3fec73d9`.
- Instance active root/TSA identity IDs are `3a7ad47fed2ff9ed3303cdc93ae8b563` and `1f023cc5f186584f7ebd026ed7d12f65`, respectively, for later comparison.

The source has no persisted PDF Sealer project setting other than enablement, so this copy does not exercise transfer of a non-default `hide-project-trust-link` value. No private-key material was read and no live settings, records, or files were changed.

### First seal in the copied project — passed

The user enabled PDF Sealer in PID 525, confirmed that its pipeline contained the operation exactly once and that its status initially showed no certificate, then submitted record **1**. They report Acrobat accepted the resulting saved download.

Read-only database inspection and OpenSSL verification of stored public certificates confirmed:

| Evidence | PID 525 result |
| --- | --- |
| New UUID | `8e9c939a-1b0e-42da-a647-8cfffdee9390` (binding log 35984) |
| New identity | `3a88b697d70642cf037f6cf328cdcae0` (certificate log 35985; active binding 35986) |
| Certificate serial | `A3DF9B0DBE66147077C9E801CD97336F`, a 128-bit serial consistent with the PHP 8.4+ path |
| Certificate SHA-256 | `d16d1ced505b8dcc0a32c3ec4899f67eeca502d02d612c993ef61b70cae0ee7f` |
| Public-key SPKI DER SHA-256 | `097521545d363fbe90fa66a1209612be10cab563d3a8272fab99f69cbd8afcb0` |
| Project Logging | Entry **1043**, **PDF seal succeeded**, **PAdES B-B**, record **1**, event **1590** |
| Logged workflow time | **2026-09-27 14:02:53**, instance local time; identity/binding persisted at **14:02:54** |

The destination UUID, certificate, and public key differ from PID 524. The source certificate's computed SHA-256 still matches its baseline above; its public-key SPKI DER SHA-256 is `ea1b6a28559255020eeaaf84491de0f84197bf32b5e3684dd4df74f775c0632e`. Both project certificate signatures verify under the same unchanged root, and the active root/TSA identity pointers remain unchanged. These checks used public certificates only; no private-key material or downloaded PDF bytes were read. Acrobat acceptance is user-reported and no new stored/downloaded hash comparison is claimed.

### Second seal in the copied project — identity reuse passed

The user submitted a second eConsent in PID 525, record **2**. Read-only `redcap_devctl` inspection found Project Logging entry **1049**, **PDF seal succeeded**, **PAdES B-B**, record **2**, event **1590**, at **2026-09-27 14:08:09** instance local time.

The destination still has exactly the original two binding entries (UUID allocation 35984 and activation 35986), referencing UUID `8e9c939a-1b0e-42da-a647-8cfffdee9390` and identity `3a88b697d70642cf037f6cf328cdcae0`. Its UUID has exactly one certificate identity record, **35985**, with the same certificate SHA-256 recorded above. No new identity or binding was created for record 2. The successful second seal and unchanged identity storage confirm reuse through the existing project-identity path; the record 2 PDF itself was not supplied for independent certificate extraction or byte validation.

The tested settings-only project-copy workflow passes: no inherited source identity, fresh destination issuance on first use, distinct public keys under the shared root, and subsequent identity reuse. Non-default EM option transfer, copied records/files, and XML export/import are outside this completed scope. Next: inspect a metadata-only REDCap XML export from PID 524, then test a new project created from it before and after its first seal.

## Project XML export/import acceptance — passed for tested scope

The user supplied PID 524's metadata-only export, `C:\Users\grezn\Downloads\PDFSealerTest_2026-09-27_1415.REDCap.xml`, and clarified that EM settings do not travel with project XML. The file was inspected read-only through its WSL path; it was not copied into the repository or modified.

- Size: **45,305 bytes**; SHA-256: `b43f4ebe130b60a095b98ff65188571f17e447eb99517e9469cc18426030dae2`.
- XML parses successfully as ODM **1.3.1**, with creation time `2026-09-27T14:15:57`.
- It contains one form definition, one survey configuration, one eConsent configuration, and one PDF snapshot configuration. No `ClinicalData` element is present.
- Neither PDF Sealer EM settings nor `external_modules.pdf_finalize_execution_plan` / `pdf_sealer:seal` are present. Module enablement and pipeline assignment therefore need separate setup after import; the direct project-copy behavior must not be assumed for XML import.
- Inspection found none of the known source UUID, identity ID, or certificate fingerprint, nor module PKI binding/identity fields, private-key storage fields, certificate DER storage fields, or PEM certificate/private-key markers. The export does not carry the module's signing identity.

The user subsequently created PID 526 from this XML. Initial-state inspection, fresh issuance, and signer reuse passed as recorded below.

### Separate EM settings export

The user also supplied `C:\Users\grezn\Downloads\PDFSealerTest_ModuleSettingsExport_2026-09-27.zip`. Read-only ZIP inspection found exactly one entry: `modules/pdf_sealer/settings.json`, containing the empty JSON array `[]` (2 bytes). The archive is **164 bytes**, SHA-256 `e0e7b0074ba07d93740e374ed6b3de3e918a70b567f8ecbfd684788f0f3da246`.

This separate export contains no setting values, module enablement flag, PDF pipeline assignment, signing identity, or key material. It is consistent with the earlier database finding that PID 524 has no PDF Sealer project setting besides enablement. It confirms the contents of this particular archive; it does not test round-tripping a non-default module setting. The XML import test can proceed with explicit module enablement and pipeline assignment after initial-state inspection. Testing actual settings-value transfer would require a separate case with a non-default project setting.


### Imported PID 526 — initial state passed

The user created **PID 526**, **PDF Sealer from XML**, from the inspected export. Read-only `redcap_devctl` inspection before sealing confirmed:

- No PDF Sealer project settings or enablement flag, and no PDF execution-plan entry. The module is not enabled system-wide either. This matches the XML's absence of EM settings and pipeline configuration.
- No destination `project_identity_binding` rows and no PDF seal success/failure entries. No source UUID or active certificate binding was inherited.
- One active eConsent configuration, **172**, points to the imported project's survey **1018**, form `survey`. The source uses consent **170** and survey **1016**.
- One active PDF snapshot configuration, **270**, points to destination consent **172** and triggers on destination survey **1018**, with File Repository saving enabled and selected forms `:survey`. The source snapshot is **268**. The trigger event is unset in both projects. The checked consent/survey references resolve to PID 526, not PID 524.
- PID 524's original UUID/active identity binding and the instance's active root/TSA identity pointers remain unchanged.

No live data or settings were modified by the agent. The user subsequently confirmed the requested enablement, single-operation assignment, and unissued-certificate status, then completed the first seal recorded below.


### First seal after XML import — passed

The user submitted record **1** in PID 526 and reports that setup/status behaved as expected and Acrobat accepted the resulting saved PDF. Read-only `redcap_devctl` inspection and OpenSSL checks on stored public certificates established:

| Evidence | PID 526 result |
| --- | --- |
| New UUID | `fa7fa996-8c18-4740-9c4e-dcb7d13b5661` (binding log 36011) |
| New identity | `0c8f1beda273bf48c7293064aaeb7d8e` (certificate log 36012; active binding 36013) |
| Certificate serial | `F5669881DB72A4B43029A84D58FB0883` (128 bits) |
| Certificate SHA-256 | `65bcc5674fef68e16c5e9e83928a158e9e953eae5f019e1b6c2dc29e579149c4` |
| Public-key SPKI DER SHA-256 | `68e4f964403b2465abd9562473019206b1db618a2c2f90fe4962edfd7acef126` |
| Project Logging | Entry **1059**, **PDF seal succeeded**, **PAdES B-B**, record **1**, event **1591** |
| Logged workflow/issuance time | **2026-09-27 14:31:41**, instance local time |

The new UUID, certificate, and public key differ from both source PID 524 and copied PID 525. All three project certificate signatures verify under the unchanged installation root. The source/copy certificate hashes and bindings remain at their recorded baselines, and the active root/TSA pointers remain unchanged. No private-key material or downloaded PDF bytes were inspected; Acrobat acceptance is user-reported. The agent made no live changes.

### Second seal after XML import — identity reuse passed

The user submitted record **2** in PID 526. Read-only `redcap_devctl` inspection found Project Logging entry **1065**, **PDF seal succeeded**, **PAdES B-B**, record **2**, event **1591**, at **2026-09-27 14:34:20** instance local time.

The destination retains exactly its original two binding records (36011 and 36013), referencing UUID `fa7fa996-8c18-4740-9c4e-dcb7d13b5661` and identity `0c8f1beda273bf48c7293064aaeb7d8e`. Its UUID still has exactly one certificate identity record, **36012**, with unchanged certificate SHA-256 `65bcc5674fef68e16c5e9e83928a158e9e953eae5f019e1b6c2dc29e579149c4`. No additional issuance or binding records were created for the second seal. The success log and unchanged identity storage confirm reuse through the existing project-identity path; the record 2 PDF was not independently inspected.

This completes the planned same-instance, metadata-only XML export/import acceptance, alongside the earlier settings-only project-copy acceptance. Both destinations started without a signing identity, created distinct signers on first sealing, and reused those signers subsequently. Source identities remained unchanged during the recorded comparisons. Acrobat acceptance was reported for each destination's first saved PDF. No further step is pending for these two cases. Non-default EM settings round-trips, copies containing records/files, and cross-instance migration are outside this evidence; the separate EM settings ZIP inspected here was empty.

## Project Migration Tool — PID 527, queue bug resolved; first seal passed

The user started a further migration from PID 524 using the Project Migration Tool, creating **PID 527**, **PDF Sealer from Project Migration** (migration **2**, started **2026-09-27 15:04:05**, instance local time). Initial read-only inspection found no PDF Sealer project settings, pipeline assignment, identity binding, or sealing events yet. Active eConsent **173** references destination survey **1019**; active snapshot **271** references that consent/survey and saves to the File Repository. These are intermediate observations, not acceptance of a completed migration.

After waiting, migration 2 still had `end_time = NULL`, `status_records = NULL`, `status_calendar = COMPLETED`, and `status_em_settings = QUEUED`. Read-only database inspection and Core source review identified the cause:

- `ProjectMigration::initProject()` queues record import only when records are selected; otherwise its status remains NULL. EM settings can be selected independently.
- `ProjectMigration::cronExtModSettingsImporter()` (local `Classes/ProjectMigration.php`, line 1684) selects only migrations with `status_records = 'COMPLETED'`. It excludes this valid no-records case indefinitely.
- The calendar importer already accepts `status_records IS NULL OR status_records = 'COMPLETED'`, explaining why that component completed. The migration completion check cannot finish while EM settings remain queued.
- Cron **85**, `ProjectMigrationToolExtraInfoImporter2`, calls the EM settings importer. It was enabled at a 60-second interval and most recently completed at **15:09:03** with zero recorded failures. The other migration jobs also ran successfully. This is a selection-condition problem, not evidence of a stalled cron process.
- A read-only comparison query returned **0** for eligibility under the current predicate and **1** with the optional-records predicate for migration 2.

No Core/Framework code, migration statuses, credentials, or project data were changed during the diagnosis. The user subsequently authorized a minimal Core fix, committed separately as `372ca1bc40903da646c55c4da17ea4707154b842` (`Fix EM settings migration when record import is omitted`). The one-line predicate now accepts absent or completed record imports, preserving the gate for queued/processing/failed record imports. PHP 8.2/8.5 syntax checks and eight read-only SQL eligibility cases passed. Normal cron completed migration 2 at **2026-09-27 15:14:03**, without manual migration-status changes. The user reports submitting the Core fix as a separate PR. PDF Sealer code required no change for this defect.

### Completed migration — initial state passed

The user confirmed migration completion and reported no subsequent changes in PID 527. A fresh read-only `redcap_devctl` inspection confirmed:

- Migration 2 has completion time **15:14:03**, `status_calendar = COMPLETED`, `status_em_settings = COMPLETED`, and `status_records = NULL` (records were not selected).
- PDF Sealer now has an explicit project setting `enabled = false`, consistent with the PMT importer disabling migrated modules. It is also not enabled system-wide. No other PDF Sealer project settings are present.
- No PDF execution-plan entry was migrated; explicit `pdf_sealer:seal` assignment is required after enablement.
- No destination UUID/certificate binding and no PDF seal success/failure entries exist. The source project's UUID and active identity binding remain unchanged, as do the instance's active root/TSA pointers.
- Active eConsent **173** points to destination survey **1019**, form `survey`. Active snapshot **271** points to consent **173** and survey **1019**, saves to the File Repository, and selects `:survey`. These references resolve to PID 527.

No live data or settings were changed during this inspection. The user subsequently confirmed enabling PDF Sealer, assigning its operation exactly once, and checking that status still showed no certificate. Although initially choosing to omit further sealing tests, they subsequently completed the first seal recorded below. This remains a same-instance, no-records migration case with no non-default PDF Sealer project settings to transfer.

### First seal after PMT migration — passed

The user submitted new record **1** in PID 527 and reports that Acrobat accepted the resulting PDF. Read-only `redcap_devctl` inspection and OpenSSL checks on stored public certificates established:

| Evidence | PID 527 result |
| --- | --- |
| New UUID | `d44152e4-12ce-43b0-a9a5-021104cde92c` (binding log 36078) |
| New identity | `fb5bd5e39955cc64bbb6e0c63a3a44e3` (certificate log 36079; active binding 36080) |
| Certificate serial | `F534AC28968EA2B7FD611B773169556B` (128 bits) |
| Certificate SHA-256 | `50177b5cf95c6408264caace944f29f56957769acb999653e171ac98cdf34e73` |
| Public-key SPKI DER SHA-256 | `ce9d257ccbf16d8aa0b0ca0f99fec12ddd76038ea7f2183731e6bb6c0f186550` |
| Project Logging | Entry **1081**, **PDF seal succeeded**, **PAdES B-B**, record **1**, event **1592** |
| Logged workflow/issuance time | **2026-09-27 15:51:44**, instance local time |

The new UUID, certificate, and public key differ from PIDs 524, 525, and 526. The new certificate signature verifies under the unchanged installation root. Existing project identity bindings and the active root/TSA pointers remain unchanged. No private-key material or downloaded PDF bytes were inspected; Acrobat acceptance is user-reported. The agent made no live changes.

This completes the agreed PMT acceptance scope: the destination started without an inherited signer and issued its own identity on first sealing. PMT signer reuse was not separately tested; the earlier copy/XML reuse checks remain the available evidence for that behavior. No additional manual sealing check is pending for PID 527. Pipeline transfer remains deferred as described below.

## Deferred: PDF finalization pipeline transfer

**Later slice, explicitly deferred by the user.** Project XML export/import and the Project Migration Tool do not yet carry the PDF finalization execution plan. This is a missing integration in the Core/EM Framework PDF-finalization branches. The direct project-copy path already preserves the plan. Requiring manual reassignment in the XML/PMT tests was a workaround for this gap.

Scope for the later slice:

- Add supported export/import and PMT transport for the ordered operation identifiers stored under `external_modules.pdf_finalize_execution_plan` in Core project settings.
- Coordinate the Core serialization/migration paths with the Framework's execution-plan validation and storage APIs. Preserve operation order and distinguish an absent plan from an explicitly empty plan where those states have different meanings.
- Preserve existing module-enablement behavior; transferring a plan must not implicitly enable a module. Define and test handling of operations whose modules are absent or disabled on the destination.
- Transfer pipeline configuration only. Signing identities, project UUID bindings, private keys, and instance PKI stay outside project transfer.
- Verify XML and PMT round-trips, including multiple ordered operations and empty/absent plans, and retain direct-copy behavior. Confirm the expected project status before module enablement and execution after explicit enablement.

No Core or Framework implementation work for pipeline transfer was performed in this documentation slice. The separately submitted PMT optional-records queue fix is independent of this integration work.
