# Certificate providers, timestamp sources, and lifecycle

## Status and purpose

Design agreed in principle on **2026-09-27**, ahead of certificate lifecycle implementation. This document describes planned behavior, not capabilities of the current release. Current behavior remains documented in [PKI](../docs/pki.md) and [administration](../docs/ADMIN.md).

The design supports the built-in CA, one or more external CAs, and mixed operation. Locally generated project keys and certificate signing requests (CSRs) belong in the first external-provider workflow. Timestamp sources remain independently registered, with their selection and fallback policy attached to each CA provider and managed exclusively in the Control Center (CC).

**Greenfield development:** the user confirmed that this development instance is the only deployment of the module and its Core/Framework infrastructure. Existing module PKI and configuration may be discarded and recreated as needed. Do not build legacy storage adapters, data migrations, or preservation requirements for this development state. Historical retention during future operational renewal remains part of the product design.

The fallback defaults and implementation sequence below are recommendations for the implementation slices. This document makes no live changes.

## Foundation implementation — 2026-09-27

The first implementation slice adds explicit built-in provider/source records, provider-pinned project bindings, certificate issuer references, and independent issuance/project-signing/timestamp checks. Initialization creates fresh configuration; there is no legacy migration. The existing CC timestamp controls now update the built-in provider policy. Only the internal provider/source kind is executable; external enrollment, provider-management UI, network TSA sources, alternate-source fallback and renewal remain planned below. The next slice implements daily expiry monitoring with 90/30/7-day warning bands, a daily-throttled summary alarm per urgency band, and a cached result in the CC Alarms tab.

## Responsibilities and configuration

| Scope | Responsibility |
| --- | --- |
| CC administrator | Register CA providers and public certificate chains; manage the built-in issuing identity |
| CC administrator | Register internal/external timestamp sources and configure their credentials, verification policy, and availability |
| CC administrator | Select each CA provider's timestamp source, permitted fallback sources, and final failure policy |
| CC administrator | Set the default CA provider or require explicit assignment; assign/change a project's provider |
| Project designer or administrator | Generate a project key/CSR, download the CSR, submit the returned certificate/chain, and activate a validated replacement for the assigned provider |
| Module | Enforce authorization, validate identities and timestamp responses, encrypt private material, serialize activation, and retain audit/history records; automatically maintain built-in root/TSA/project identities |

Project pages show the effective provider and timestamp policy read-only. Project users cannot select a different TSA, change fallback policy, or substitute a different CA by uploading a certificate. Authorization must be enforced at the server action, not just by hiding controls.

## CA providers and identities

A provider has a stable ID, display name, kind (`internal` or `external`), allowed CA chain/trust anchors, availability for new assignments, and a timestamp policy. Public CA material is separate from identities holding private keys: an external provider never requires the CA's private key.

- The built-in provider issues project certificates locally.
- An external provider accepts project certificates issued outside REDCap. Support an issuing intermediate and chain to an explicitly configured trust anchor; do not assume direct root issuance.
- Retain certificate versions and historical associations. An existing identity references its provider and the chain used for it, rather than whatever certificate is currently active for that provider.
- Separate retiring a provider from blocking its use. Retirement prevents new assignments/issuance; any decision to stop existing signing is explicit. Never erase historical certificates to retire a provider.

A project retains its stable internal UUID across renewal and provider transitions. External certificate subjects follow the external CA's permitted naming rules; the database binding establishes the association with the project. Avoid including project titles, record identifiers, or participant information in generated CSRs. The existing pseudonymous UUID subject is the starting template, subject to the external CA's requirements.

The default provider applies when establishing a project's assignment. Changing the default must not silently switch existing projects. Require a resolved assignment before preparing a key/CSR or issuing a certificate.

## Project key and CSR workflow

Preferred external-provider enrollment:

1. A CC administrator assigns the project's CA provider.
2. A designer or administrator explicitly requests enrollment. Opening a status page never generates keys.
3. REDCap generates a project key, encrypts it with the existing secret-protection mechanism, and stores a pending enrollment tied to the project UUID and assigned provider.
4. The user downloads a public CSR and submits it to their CA outside REDCap.
5. The user uploads the issued certificate and any required intermediates. Validate that it matches the pending key, chains to the assigned provider, is valid for the intended signing use, and uses a supported algorithm/profile.
6. Present public certificate details and activate the validated identity atomically under the project lock. Recheck assignment, validity, and pending state when activating.

CSR generation and certificate upload do not replace an active signer. A pending replacement can coexist with the current usable identity. A certificate that is not yet valid remains pending; invalid submissions leave the active identity untouched. Cancellation/supersession is explicit and prevents later activation of a stale response. Limit active enrollment work to one pending request per project initially.

The locally generated private key is not downloadable through this workflow, including by CC administrators. External CA staff receive the CSR, not the private key. This is an application boundary, not a claim that a host/database administrator cannot access installation secrets. A future private-key export facility would require a separate explicit design decision; it is not part of enrollment.

Also retain an explicitly invoked certificate/private-key import path for externally generated project identities. It must enforce the same provider, key-match, chain, profile, and activation checks and encrypt the key immediately. It does not provide an export path for REDCap-generated keys. Upload formats and size limits will be selected in the implementation slice; do not build a general key-management interface.

Pending keys are durable secret storage, not disposable diagnostic log data. Define cancellation cleanup and retention before implementing enrollment. Audit actor, project, provider, public fingerprints, and outcome; never log uploaded keys, key passwords, or credentials.

## Timestamp sources and per-provider policy

Keep the built-in TSA and add CC-managed external RFC 3161 sources. Multiple CA providers may reference the same source. The project signer and TSA may have different trust chains.

Each source needs a stable ID and kind. An external source additionally needs its endpoint, supported authentication, encrypted credentials where applicable, response trust configuration, and any requested/accepted policy constraints. The built-in policy OID applies to the internal TSA; do not impose it on external services. HTTPS transport trust and timestamp-signature trust are separate checks.

Each CA provider selects either:

- **No timestamp:** deliberately produce B-B; or
- **Timestamp:** use a primary source, optionally an ordered list of permitted alternatives, then apply an explicit final failure policy.

Recommended fallback semantics:

| Condition | Result |
| --- | --- |
| Primary returns a valid, acceptable token | Produce B-T using that token |
| Primary fails or its response is rejected | Try the next explicitly configured source; never accept the rejected response |
| An allowed alternative succeeds | Produce B-T and record that an alternative was used |
| All configured sources fail, B-B fallback enabled | Produce B-B and record timestamp fallback |
| All configured sources fail, B-B fallback disabled | Fail the sealing operation |

**No implicit fallback to the internal TSA.** An administrator must explicitly allow it for that provider: a local timestamp and an external timestamp represent different trust choices. Switching timestamp sources never switches the project certificate provider.

For external timestamp policies, recommend no alternative sources and no B-B fallback by default. Fresh built-in provider configuration should explicitly set its timestamp mode and B-B fallback policy; no legacy settings need to be migrated. A change in source or fallback policy is a CC-only, audited change affecting subsequent seals.

Bound the number of sources, individual request durations, response sizes, and total timestamp budget for one sealing operation. All attempts concern the same CMS signature being timestamped. Validate each candidate token against that signature and its own request, including imprint, nonce, policy, signer/chain, and time constraints. A successful HTTP response alone does not establish a usable timestamp.

External HTTP requests must use a suitable existing Framework/REDCap facility where available. Endpoints come only from CC configuration, not project input. Validate transport, constrain redirects and destinations, and avoid automatic fetching of certificate URLs supplied by an uploaded certificate or untrusted response. Support institution-hosted services deliberately; do not assume all legitimate endpoints are on the public Internet.

Public/project pages must never disclose endpoint credentials. CC diagnostics should exercise each selected source explicitly and report individual results; fallback must not conceal a failed primary. Record source IDs and fallback outcomes without expanding project Logging into a verbose technical transcript. External service details belong in restricted diagnostics.

The current Framework failure behavior remains: a failed sealing operation leaves the preceding PDF available for REDCap storage/delivery. A strict timestamp policy is not a guarantee that REDCap blocks PDF generation. Describe this distinction in configuration and administrator documentation.

## Lifecycle and health

The user clarified the lifecycle target on **2026-10-01**: built-in root, TSA and dependent project certificates must be maintained automatically by cron, with manual revocation followed by automatic replacement. Required manual enrollment remains for external certificates. The [automatic built-in PKI plan](builtin_pki_lifecycle_plan.md) supersedes the earlier manual TSA/staged root proposal. It covers bounded work, atomic activation, downtime recovery, prerequisites and viewer-trust/public-revocation boundaries. Routine same-key root renewal and fresh-key TSA/project maintenance are implemented; confirmed project/TSA revocation/recovery is implemented; root revocation and fresh-root-key recovery remain future work.

Separate these questions:

1. Can this provider issue a new identity locally, or does it require external enrollment?
2. Can this project currently seal using its active certificate/key and chain?
3. Can its configured timestamp policy currently be satisfied?

The absence or failure of the built-in CA private key must not by itself invalidate an otherwise usable external project identity. Timestamp health follows that provider's selected sources. Existing locally issued identities likewise need validation independently of whether new local issuance is available.

Expiry monitoring covers active project certificates, relevant issuing chains, and locally managed root/TSA identities. For external timestamp sources, show diagnostic observations with their observation time; a previously observed TSA signer certificate does not prove which certificate the remote service will use next. Do not claim that a local expiry scan monitors a remote service continuously.

Actions differ by provider: built-in identities receive automatic local renewal, whereas external identities need their required enrollment/CSR and returned certificate. Project/TSA replacement uses fresh keys; routine root renewal preserves its existing key and exact subject/profile. Root compromise requires separate fresh-key recovery. Keep the current usable, nonrevoked identity active until its replacement is ready, then atomically switch the binding and retain historical public certificates and audit records. Automatic root/TSA rollover and dependent project replacement are described in the built-in PKI plan; external viewer trust requires separate distribution.

Changing a project's provider prepares a deliberate transition; it must not silently bind the old certificate to the new provider. Show active and pending providers while replacement is underway. Disable/cancel the pending enrollment if its assignment is withdrawn. Define any emergency stop separately from normal renewal.

The public trust page should identify built-in and external providers and publish their configured public CA chains, including retained historical material needed to explain earlier seals. Publication does not establish viewer trust. Explain separately which timestamp sources are permitted; never imply that the project certificate chain also authenticates its timestamp.

## Greenfield implementation and storage

- Introduce the provider/source model directly and initialize fresh built-in root/TSA identities and project bindings as needed. Existing development certificates, keys, UUIDs, and settings need not survive the redesign.
- Scope any development reset to this module's PKI/configuration. Use previewed `redcap_devctl` mutations for live database changes. This is a development operation, not a production reset feature or an automatic destructive upgrade path.
- Keep identity/history storage system-scoped and private keys encrypted. CSR enrollment adds a pending state before a certificate exists; the current certificate-required identity record cannot represent that state by itself.
- Extend recovery logic to distinguish a pending enrollment, an interrupted activation, and historical identities. The current single-unbound-certificate assumption must not select an arbitrary old identity after renewal.
- Copy/export/migration must never transfer private keys, pending enrollment secrets, or active identity bindings. Any transfer of provider preferences needs destination validation; stable references within one instance are not portable trust decisions across installations.
- External certificate validation needs an explicit supported profile, rather than requiring the current exact RSA size, exact EKU list, and `REDCap Project <UUID>` subject. Validate these choices against the installed signing libraries before promising algorithms or certificate compatibility.
- Keep B-LT/B-LTA, revocation publication, CA automation protocols, and remote/HSM-backed signing outside this slice. External CA or TSA support does not itself implement those capabilities.

## Implementation sequence and acceptance

1. **Provider and lifecycle foundation:** add the provider/source model directly; separate issuance, signing, and timestamp health. Reset/reinitialize development PKI as needed, then verify fresh initialization, B-B/B-T sealing, and identity reuse within the new model. No legacy migration or compatibility layer is required.
2. **Expiry monitoring and alarms (implemented):** inspect active public certificates daily without generating/replacing identities; warn at 90/30/7 days and after expiry. Cache CC results and send daily-throttled summary alarms per urgency band.
3. **External enrollment:** implement key/CSR generation from the start, certificate return/activation, and controlled identity import. Cover authorization, wrong key/chain, invalid profiles, stale requests, concurrent activation, and unchanged active identities after rejected uploads.
4. **External timestamping:** add the first supported endpoint/authentication configuration and bounded primary/alternative/B-B handling. Test invalid tokens, timeouts, exhausted budgets, and explicit internal fallback. Independently verify the final embedded timestamp.
5. **Renewal workflows:** reuse pending enrollment and atomic activation for external and local replacements. Exercise historical chains and provider changes without losing project UUIDs.

Keep implementation slices small. Resolve supported certificate profiles, upload formats, timestamp authentication, and key-retention rules when their slice becomes concrete; discuss any substantial added complexity before broadening scope. No live configuration, certificates, credentials, or Core/Framework code are changed by this design document.

## Registration slice implemented — 2026-09-27

CC administrators can register an ordered external public CA chain, choose no timestamp or the built-in TSA explicitly, and assign a module-enabled project before it obtains a provider binding. Registered chain certificates are public and monitored for expiry. These actions are transactional and audited; external assignment fails sealing explicitly until enrollment is implemented. Built-in default issuance remains unchanged. External policy editing, default-selection controls, provider transitions and remote TSA sources remain planned; retirement is implemented as recorded below.

The next slice begins local encrypted project-key and CSR storage, separate from the active signer. Registration accepts a complete chain to a self-signed anchor; alternate/cross-signed path selection is outside this initial implementation.

## Assignment gate implemented — 2026-09-27

CC now offers a default-off requirement for explicit CA assignment, independent of CA count. It applies to projects without a binding. The policy save and automatic first issuance share a configuration lock; existing bindings continue working. The intended failure behavior is documented in both packaged guides: missing assignment blocks sealing while eConsent completion can store/deliver an unsealed PDF. Choosing a different installation default and transitioning an existing project binding remain separate future work.

## Local key/CSR slice implemented — 2026-09-27

The project status page now offers generation, repeated CSR download, and confirmed cancellation to authenticated project designers/admins for an explicitly assigned external provider. One pending enrollment is stored in `pending_enrollment_<PID>` system settings with an encrypted local key, separate from active identities. It persists until cancellation or future activation; cancellation deletes active pending storage, retains public audit metadata, and does not erase backups. Stale IDs cannot cancel/download a replacement. No private-key export path exists. The initial CSR subject is UUID-only CN; requested extensions describe the existing document-signing profile.

Next: certificate upload, chain/profile/key validation, and atomic activation of the exact pending request. Controlled imported-key enrollment and renewal remain separate follow-ups. The disposable registration fixture's CA key was discarded; it can test CSR preparation but cannot issue a returned certificate.

## Returned-certificate activation implemented — 2026-09-27

A project designer/admin can upload one PEM leaf for review and explicitly activate it. The backend checks pending key/CSR, assigned issuer/path, current validity and signing profile, rechecks the review hash/current identity under the project lock, and atomically persists identity, chain, binding, pending removal, and audit. Manual replacement preserves the usable signer until activation; old encrypted identity logs remain retained. External-chain B-B/B-T sealing and active expiry inventory are implemented. Not-yet-valid uploads are rejected with the CSR left pending for later resubmission; candidate-certificate staging and imported-key enrollment remain future work.

## Implemented retirement slice — 2026-09-27

Built-in and external providers now support CC-only retirement/reactivation with a public usage review and stale-review rejection. Retirement blocks assignments, local issuance, CSR generation, and external activation, including preexisting pending requests; active signers and the existing TSA remain operational while valid. Pending requests remain downloadable/cancelable. The default can be retired only with explicit assignment enabled, with an explicit confirmation to enable it atomically when needed. Reactivation preserves the assignment gate.

Project/configuration locks synchronize all new identity mutations and pending cancellation with retirement. Audit failure rolls back both lifecycle state and policy changes. Public downloads retain retired labels; expiry monitoring retains active signer/TSA dependencies and excludes unused retired CA chains. Diagnostic temporary issuance obeys retirement too. The user confirmed the browser retirement/reactivation test passes; see the implementation status and testing guide.

Next sequence: controlled project provider transition, then external TSA integration. Emergency blocking and revocation remain separate future decisions.

## Controlled provider transitions implemented — 2026-09-27

CC can prepare an external target while retaining the current provider/signer, or issue and activate a built-in replacement atomically. Project UUIDs and historical identities remain. External enrollment uses the pending target, and activation switches both provider and signer; timestamp policy follows the new provider only after activation. A no-signer project stays blocked during pending enrollment instead of issuing under its old provider.

One existing CSR or transition must be explicitly canceled before another is started. CC cancellation transactionally discards its pending request/key and clears the target; designer CSR cancellation leaves the selected target in place. Retirement accounts for pending assignments, blocks new enrollment against retired targets, and permits moving away from a retired source. The CC review is checked again under project/configuration locks. The user reported a positive transition workflow result after the AJAX fix and returned certificate; separate browser cancellation acceptance remains unconfirmed. See testing. Imported keys, same-provider built-in renewal, root rotation, and external TSA integration remain separate work.

## External TSA foundation implemented — 2026-09-27

The external TSA work is split into transport/validation and CC integration. The first slice adds a bounded HTTPS transport (anonymous or Basic authentication in memory), explicit timestamp-signing CA trust, optional requested policy, and fractional timestamp metadata support. The complete configured public chain must run from the direct issuing CA to a self-signed root; alternate/cross-signed path discovery and remote certificate/revocation fetching are not implemented. The HTTP transport requires the existing Core bounded-response helper and fails closed if unavailable. This is developer infrastructure; no external source can yet be configured through the module.

Validation follows [RFC 3161](https://www.rfc-editor.org/rfc/rfc3161.html), using the bundled codec for request binding, timestamp certificate purpose, CMS signature and ESS attributes, and OpenSSL for the explicitly configured timestamp certificate chain. The default external policy may be empty, while a configured policy is requested and enforced. HTTPS trust remains independent of token-signing trust. Unit transport tests do not establish real endpoint interoperability.

Next: CC source registration with encrypted credentials and an explicit source diagnostic; provider source/fallback editing and finalizer integration. Then test permitted alternative-source budgets/failure handling and obtain live external-source acceptance. Do not infer operational support, hidden fallback, or continuous remote expiry monitoring from this foundation.

## External TSA CC integration implemented — 2026-09-27

The next slice exposes immutable source registration, encrypted Basic credentials, explicit cached diagnostics and per-provider primary-source/B-B selection through CC-only JSMO.ajax. The finalizer uses the selected external provider; there is no implicit internal alternative. Configured CA chains enter expiry inventory; observed remote signer metadata is historical only. Project/public text distinguishes independent TSA trust. Sources cannot yet be edited/deleted, and external TSA public-chain downloads remain pending. Ordered alternatives are implemented in the 2026-09-30 slice below. FreeTSA source testing and user-reported Acrobat acceptance of an eConsent PDF with its embedded timestamp passed on 2026-09-28 after trusting the FreeTSA CA in Acrobat; see the testing guide for scope and remaining failure-policy checks.

## Manual built-in project renewal implemented — 2026-09-30

CC administrators can review and explicitly renew an existing built-in project signer, including an expired leaf. Renewal always uses a fresh key, preserves UUID/provider/history, checks pending work and active usable issuance, and switches binding with identity/audit in one transaction under project/configuration locks. A stale identity/issuer review is rejected. The public review does not decrypt keys. The project page remains read-only for built-in renewal, and expiry scans do not issue replacements. Automated PHP 8.2/8.5 and cryptographic finalizer checks pass; the user reported a passing renewal browser check on 2026-09-30. See the testing guide for scope. The subsequent built-in maintenance slices now automate routine root/TSA/project renewal; project revocation/recovery is now implemented; TSA/root revocation and compromise recovery remain separate work. Ordered TSA alternatives are implemented below.

## Deferred CA providers UI redesign — 2026-09-30

The CA providers tab has grown unwieldy as lifecycle workflows were added. Revisit its layout in a later UI slice: possible approaches are sub-tabs, or task links that continue a focused workflow in a dialog. Discuss the workflow design before implementing it; no approach is selected yet. Keep this separate from ordered TSA alternatives.

## Implemented ordered TSA alternatives — 2026-09-30

Each provider has an explicit primary plus up to two distinct ordered alternatives. CC settings and project summaries show the order. One signature imprint/nonce is reused; each provider validates its own policy/chain before selection. Attempts share a 20-second monotonic deadline and each HTTPS request is capped at 10 seconds or remaining time. No source is retried; no implicit internal choice occurs. Explicit final B-B fallback remains separate. Safe EM outcome/failure logs record attempt order and selected source, while project Logging marks alternative success/fallback. Alternative public CA chains enter expiry monitoring. Automated PHP 8.2/8.5 checks pass. On 2026-10-01 the user reported that the full live failover matrix and Acrobat checks passed in PID 524, records 2, 3, 4, 6, and 7. No case-to-record mapping or resulting PDFs/logs were supplied for independent inspection; deadline coverage remains automated. See the [testing guide](testing.md#ordered-tsa-alternatives).

## Built-in TSA lifecycle update — 2026-10-02

Current built-in TSA review offers Replace without revoking, Revoke as superseded, and Revoke for key compromise. The latter two permanently block the exact key with immutable public audit, prompt CRL publication and independent automatic fresh-key recovery; hourly cron retries failures. Ordinary replacement does not revoke earlier certificates. Superseded (4) preserves tokens before the revocation time under RFC 3161 §4; key compromise (1) withdraws trust in all tokens from that key. Capture/token checks and serialized PDF working-copy acceptance prevent a revoked captured TSA from publishing a new accepted result. Provider policies, external assignments and identity history remain intact. Root revocation/fresh-key recovery is the next lifecycle slice. Browser acceptance is pending in the testing guide.
