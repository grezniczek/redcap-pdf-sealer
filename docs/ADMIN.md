# PDF Sealer — administrator guide

PDF Sealer maintains an installation-specific certificate authority and timestamp authority, issues project sealing certificates, and seals eligible eConsent PDFs. This guide covers setup and operation. The [project guide](PROJECT.md) explains what project users see; [PKI](pki.md) and [sealing and validation](sealing-and-validation.md) provide technical background.

## Requirements and installation

This is a reference implementation progressing toward v1. It requires:

- **PHP 8.4 or later in the PHP 8 series is recommended**, with OpenSSL, hash, JSON, PCRE, and zlib support. The minimum is PHP 8.2. PHP 8.4+ uses random 128-bit certificate serials; PHP 8.2/8.3 uses reserved EM log IDs as integer serials. The runtime running each issuance determines its serial format, so check both web and cron PHP versions. See [serial allocation and recovery](pki.md#certificate-serials).
- **For PDF sealing:** REDCap Core and an External Module Framework that implement the PDF finalization pipeline and `redcap_module_pdf_finalize`. The module declares Framework version 16, but that number alone does not establish availability of these features in a particular checkout or release. Missing PDF finalization support does not prevent installing the module or managing certificates.
- A complete module distribution, including `libraries/`, the module-owned `autoload.php`, and third-party licenses. Dependencies are already bundled with isolated namespaces; installation does not require Composer.
- Working REDCap encryption, database storage, and temporary-file support. REDCap email delivery is needed if alarm emails are configured.

Install and enable the module using REDCap's normal External Modules administration. The production sealing path uses PHP libraries and OpenSSL; it does not require qpdf, Poppler, or the OpenSSL command-line program. Those are development validation tools.

### Installations without PDF finalization support

The required Core/Framework additions are not yet released. PDF Sealer checks for both components' features rather than assuming support from a version number. If either is missing, **Certificates & sealing** in the Control Center and **PDF Sealer status** in projects display **PDF sealing is unavailable on this installation**, identify the missing component(s), and explain that certificate management remains available.

You can initialize and manage the PKI on such an installation, but PDF Sealer cannot seal generated PDFs. Passing PKI diagnostics or having a usable certificate does not establish PDF finalization support. When both features are present, project pipeline assignment and PKI readiness must still be checked separately. See [development readiness](https://github.com/grezniczek/redcap-pdf-sealer/blob/main/DEV_DOCS/release_readiness.md) for integration details.

## Initialize the installation

1. As a superuser, open **PDF Sealer administration** in the Control Center. The page title is **Certificates & sealing**.
2. On **Root CA**, enter the organization to appear in certificates and initialize the PKI. This creates the root and the dedicated timestamp authority together.
3. Confirm the resulting health and certificate details. Initialization is a one-time action; existing or incomplete PKI material prevents another initialization. Reloading the page does not repeat the submission.
4. Download the public root in PEM or DER if needed. The public-certificate link above the tabs provides the distribution page.
5. Configure timestamping and alarm recipients, then run the diagnostic self-test.

Choose the organization carefully: the page does not provide an organization rename workflow. An existing broken PKI requires investigation rather than reinitialization. See [storage and lifecycle limitations](pki.md).

## Renew or revoke the built-in Root CA

On **Root CA → Administrative workflows**, select **Renew or revoke built-in Root CA** to open a movable dialog. Review the current subject, fingerprints and known dependent certificate count, then choose an action using the radio buttons. The built-in step indicator shows your position in the workflow. Page one has **Cancel** and **Next**; page two has **Cancel**, **Back** and **Confirm**. **Back** preserves the action choice; **Confirm** on page two executes it. For CA key compromise, the permanent-block acknowledgment checkbox must be checked first. **Cancel** closes the dialog without applying an action; a stale review is rejected.

An accepted action automatically reloads the page and displays its outcome, including any pending CRL publication or recovery. An interrupted or rejected request cannot be retried using the same review: close the dialog, refresh and review again, since the action may already have changed saved state.

| Action | Effect |
| --- | --- |
| Renew without revoking (same key) | Performs an early ordinary renewal, preserves the issuing key and CRL URL, and activates a fresh TSA. Earlier certificates remain unrevoked. |
| Revoke issuing key as superseded | Permanently blocks every root certificate version using that key and its dependent signers; publishes superseded (4) for known project/TSA leaves. |
| Revoke for CA key compromise | Applies the same permanent block and publishes cACompromise (2) for known leaves. This is a response to compromise, not routine renewal. |

Both revocation actions automatically create a **fresh root key and TSA**. Root/TSA identities, provider/source references and the new key's initial CRL activate together. The old block commits first and survives failed publication or recovery. A damaged old private key or unavailable old CRL cannot prevent new-key deployment. Old CRL counters are never reset. Failed publication/recovery raises an alarm and cron retries; the confirmation reports each outcome separately.

The module immediately starts bounded replacement of existing built-in project signers; hourly maintenance resumes remaining work. UUIDs, CA assignments, timestamp policies and history are preserved. Retired CAs and pending enrollments/provider changes still defer project replacement. External assignments are never substituted locally. Any external CA or TSA configuration containing the blocked built-in key is also unusable until explicitly reconfigured through its normal external workflow.

**Distribute and verify the new root fingerprint before installing its trust in viewers.** A correctly sealed PDF using the new key may be reported as untrusted until this is done. All same-key versions of the old root are marked revoked on the public certificate page and retained for historical inspection. For compromise, withdraw trust in the old key from external viewer stores through your institution's process. The module cannot update those stores.

The old key's CRL lists known stored project/TSA certificates, preserving earlier explicit leaf revocation reasons and times. It does not list the self-signed root as a means of withdrawing anchor trust and cannot enumerate certificates an attacker forged with a compromised key. CRL publication cannot guarantee rejection of every compromised-root chain in viewers. Prior PDF bytes are unchanged; historical trust decisions remain the verifier's responsibility. See [root revocation semantics](pki.md#built-in-root-revocation) and [RFC 5280](https://www.rfc-editor.org/rfc/rfc5280.html#section-6.1.1).

Until replacement succeeds, a dependent sealing operation fails with **ROOT_CA_REVOKED**, even if B-B fallback is allowed. REDCap can still store or deliver the preceding unsealed PDF. Root block commit is serialized with PDF working-copy acceptance; an already accepted PDF may continue through Core storage/delivery.

## TSA: timestamp settings

Settings on the **TSA** tab apply to future sealing operations for projects assigned to the built-in CA. Save both choices together using the page's save action.

| Setting | Behavior |
| --- | --- |
| Internal timestamp authority (PAdES B-T) | Embeds a timestamp issued by this installation. This is the default. |
| Registered external TSA | Requests and validates an HTTPS RFC 3161 timestamp against its separately configured CA chain. |
| No timestamp (PAdES B-B) | Seals without a timestamp. |
| Allow sealing without a timestamp (B-B fallback) | If all selected timestamp sources fail or their shared budget expires, attempts a B-B seal. Enabled by default for the initial built-in policy; external registration leaves it off. |
| Fail the sealing operation | If all selected timestamp sources fail or their shared budget expires, the operation fails instead of using B-B. |

These CC-only controls apply to the CA provider selected in **TSA → Timestamping**, including projects with existing signers. Select the primary source, optionally select **First alternative TSA** and **Second alternative TSA**, and explicitly allow B-B fallback if wanted. Sources must be distinct and the first alternative must precede the second. Selecting a new primary clears alternatives and the fallback checkbox; **No timestamp** clears and disables both. Saving applies to future seals; an operation already running may finish under its prior policy. The built-in TSA uses its default policy without requiring an OID. Invalid or unavailable configuration fails closed.

**“Fail the sealing operation” does not mean “block the PDF.”** On failure, the Framework discards the failed working copy and retains the preceding PDF for REDCap to store or deliver. Project-key or signing-chain failures also fail the operation. A root private-key failure blocks new certificate issuance but does not by itself block an existing usable project signer. Decide how your local process handles failed or fallback seals.

## Replace or revoke the built-in TSA

On **TSA → Replace or revoke built-in TSA certificate**, review the current subject and SHA-256 fingerprint, select the action and confirm:

| Action | Effect on the previous TSA |
| --- | --- |
| Replace without revoking | Activates a fresh key/certificate; the previous certificate is not revoked. Use this for an optional early routine replacement. |
| Revoke as superseded | Permanently blocks the previous key and publishes reason superseded (4). RFC 3161 preserves trust in tokens generated before its revocation time, subject to the verifier's other checks. |
| Revoke for key compromise | Permanently blocks the previous key and publishes reason keyCompromise (1). All tokens signed with that key can no longer be trusted, including earlier tokens. |

These controls affect the installation's built-in TSA, including every CA provider that selects it. They do not revoke project signing certificates or change any provider's primary/alternative sources, policy OID or B-B fallback. External TSAs follow their operator's lifecycle process. Root revocation has separate controls on the Root CA tab.

Revocation commits the local block before independently attempting CRL publication and fresh-key replacement. Neither failure undoes the block. Confirmation reports publication and replacement separately; pending recovery raises an alarm and hourly maintenance retries. A revoked old private key is never needed for recovery. Review/revoke remains possible if the old key is damaged or its certificate has expired. Ordinary replacement requires the existing key's provenance checks; use revocation when a permanent block is intended.

Until recovery, a blocked built-in TSA attempt tries only the provider's configured alternatives and explicit B-B fallback. A strict timestamp policy can fail sealing while REDCap still stores/delivers the preceding unsealed PDF. A revocation that overlaps a completed token's PDF acceptance fails that seal with **TSA_CERTIFICATE_REVOKED**; a later sealing attempt can use the current sources. Already accepted PDFs may continue through Core storage/delivery.

Prior PDFs retain their signed bytes. Viewer caches can delay receipt of a changed CRL; publishing it does not withdraw installed root trust or add long-term validation evidence. See [TSA revocation semantics](pki.md#built-in-tsa-revocation) and [RFC 3161 §4](https://www.rfc-editor.org/rfc/rfc3161.html#section-4).

## Register and test an external TSA

On **TSA → External timestamp sources**, enter a unique source name and HTTPS endpoint, then paste the public TSA **issuing CA chain**: direct issuing CA first, any parents next, self-signed root last. Do not paste the TSA leaf or a private key. The limit is eight certificates / 128 KiB. The chain is independent of the document-signing CA and must be currently valid. Cross-signed/alternate path discovery and remote certificate fetching are not supported.

Leave the policy OID empty to accept this trusted TSA's default policy. A configured policy is sent as `reqPolicy` and must match the returned token. Anonymous access and HTTP Basic authentication are supported; credentials are encrypted using REDCap's installation encryption. Keep passwords or access tokens out of the URL; use the authentication fields. There is no private client certificate or custom authentication-header support.

**Register external TSA** stores configuration without making a network request. Then choose **Test source**. This sends a random test imprint, validates the complete response and configured trust, and saves the result, observation time, signer fingerprint and certificate expiration. The browser displays the observation time in your local zone and profile format. Refresh retains both successes and failures. A successful diagnostic is historical evidence, not continuous availability or remote expiry monitoring. It does not use a PDF or participant data.

Some services use the older RFC 3161 signing-certificate identifier based on SHA-1. PDF Sealer accepts that identifier for external TSA tokens after checking the configured CA chain, while still rejecting SHA-1 timestamp digests and signature algorithms. This compatibility does not apply to document signatures or the built-in TSA.

Select a CA provider under **Timestamping**, select the tested source, and save its policy. Test an actual eConsent workflow and inspect the resulting PDF. Every seal validates its own fresh timestamp. The request contains a signature hash and nonce, not the PDF, project title, record ID or participant details. A timestamp rejection or outage tries that provider's explicitly selected alternatives in order, then follows its explicit B-B policy; the internal TSA is used only when selected. Each attempt timestamps the same signature hash and validates its source's own policy and trust chain. A successful primary skips all alternatives.

Requests use REDCap's HTTPS trust/proxy configuration, with a 3-second connection limit, 10-second request limit, and 64 KiB response limit. The primary and up to two alternatives share a 20-second monotonic budget, including source preparation and validation. Each source is attempted once; subsequent HTTP timeouts shrink to the remaining budget. An exhausted budget starts no further request and a response validated after the deadline is rejected. Source tests probe only the selected source and do not use alternatives. Redirects and compressed responses are rejected. The Core `HttpClient::requestWithResponseLimit` helper is required; if unavailable, requests fail. Institutions can configure an internal HTTPS endpoint. The configured TSA CA chain validates timestamp signatures; it does not install a TLS trust anchor for the endpoint.

Source configurations are immutable in this version. To change an endpoint, credentials, policy constraint or trust chain, register a new named source and explicitly reassign providers. At most 16 external sources are supported; editing/removal remain future work. Old encrypted credentials remain stored with their source. Project Logging marks an alternative TSA success or B-B timestamp fallback. A corresponding restricted EM log records attempted source IDs, the selected source and profile; strict failure diagnostics also record attempt order. Primary-source success keeps the usual minimal project log. Source registration and policy changes are audited without secrets; generic diagnostic failures do not expose remote error bodies or URLs. Daily expiry checks include configured CA chains referenced by active providers/signers, but do not continuously inspect a remote TSA certificate. External TSA chains are not yet included in the public certificate downloads; distribute them through your institution's trust process as needed.

The **Diagnostic** tab continues to test the built-in CA/TSA independently of provider selection. Use **Test source** on the TSA tab for external services.

## Enable sealing in projects

1. Enable PDF Sealer in the project.
2. Confirm that the management/status pages do not report missing PDF finalization support. In that project's **External Modules PDF finalization settings**, assign **Apply a cryptographic document seal** once. Place it after operations that should alter the PDF; successful sealing is terminal.
3. Review **PDF Sealer status** for assignment warnings and instance PKI readiness. An earlier terminal operation or duplicate assignment needs review.
4. Complete an appropriate test eConsent workflow. The project certificate is issued lazily when sealing is first used.
5. Check the saved PDF and the project's **Logging** outcome. A passing diagnostic or an assigned operation alone does not demonstrate that the project's workflow reached sealing.

Only eligible completed eConsent PDFs are sealed. Existing archives and ordinary record/form PDFs are unaffected. The [project guide](PROJECT.md) covers project permissions and the optional menu link to public certificates.

## Require explicit CA assignment

On **CA providers**, enable **Require explicit CA assignment for new project identities** and select **Save assignment policy**. The switch defaults to **off** and is independent of the number of registered CAs.

- **Off:** an unassigned project uses the built-in default when its first eligible sealing operation runs.
- **On:** a project without a provider binding cannot obtain a signing identity until an administrator assigns a concrete CA using **Assign project provider**. Choosing the built-in CA explicitly permits normal local issuance; choosing an external CA requires its enrollment workflow.
- Existing provider bindings, including pending issuance, remain valid when this setting changes. The switch does not reassign projects or revoke certificates.

**This deliberately blocks sealing, not eConsent completion.** When assignment is missing, the sealing operation fails with `CA_ASSIGNMENT_REQUIRED`; project Logging says **PDF seal failed: CA assignment required**, with record/event context where available. REDCap can still complete the workflow and store or deliver the preceding **unsealed PDF**. Later assignment does not retroactively seal that archive.

For a busy project, enable the switch and **wait for the successful save confirmation before enabling or assigning the sealing pipeline**. Saving synchronizes with automatic first issuance. An automatic issuance that started earlier may finish before the save succeeds and retain its binding; after a successful save, an unbound project cannot automatically select the default CA. Inspect the project's status before assigning its provider. Use **Change project provider** for an existing binding.

## External CA registration and project assignment

On **CA providers**, register a named external provider by uploading its public PEM chain: issuing CA first, any parent intermediates next, and the self-signed root last. A directly issuing root can be uploaded alone. The limit is eight certificates / 128 KiB. Certificates must be currently valid CAs with certificate-signing usage; ordering, signatures, path constraints, and duplicate chains are checked. Private keys are rejected. Registration publishes these certificates on the public trust page and includes them in daily expiry checks while the provider is active, even before a project uses them.

Choose **No timestamp**, the initialized **internal TSA**, or a registered external source. B-B fallback is unchecked initially. Each provider has its own policy, editable on the **TSA** tab. Changing one provider's policy does not change other providers.

To assign a provider, choose a project by title or PID in the searchable selector and select an active provider. Retired providers are excluded. The selector lists active projects with PDF Sealer enabled that have no provider binding, excluding pending issuance/enrollment as well as active signers. Successfully assigned projects disappear from the list immediately. The server still checks for assignments made after the page was loaded. After success, the confirmation names both the provider and project and clears the selections for another assignment. Failed requests retain your selections. PDF Sealer must be enabled there. Assignment reserves a project UUID; it does not issue a certificate or assign a PDF pipeline operation. An existing binding uses the separate **Change project provider** workflow below. Registration does not change the installation default: unassigned projects use the built-in provider when initialized only if the explicit-assignment gate is off. An external-only setup requires explicit project assignments.

**External enrollment supports CSR preparation, certificate review, and activation.** An externally assigned project shows **Awaiting signing certificate**. Until activation, sealing reports `PROJECT_CERTIFICATE_REQUIRED` and does not issue a built-in certificate. REDCap's existing finalization failure behavior can still store/deliver the preceding unsealed PDF; this is not a delivery-blocking policy. Use a test project to verify the full enrollment and sealing workflow before operational use. The built-in health summary and diagnostic continue to describe the built-in CA/TSA.

Registration and assignment use authenticated CC-only AJAX and system-scoped audit records. No CA private key is requested or stored. Project designers and administrators can generate a local project key, download its CSR, and explicitly cancel a pending request on the project status page. They can then upload one PEM signing certificate, review its validated details, and activate it.

## Retire or reactivate a CA

On **CA providers**, select **Retire CA** for a built-in or external provider. Review the affected project IDs, active signers, and pending CSRs/transitions, then confirm. The list includes projects where the module is disabled. If assignments or enrollments change before confirmation, the operation fails and requires a fresh review.

Retirement blocks new assignments, built-in issuance, CSR generation, and returned-certificate activation—including certificates for requests already pending. Existing active signers continue sealing while otherwise valid. Pending CSRs remain downloadable and cancelable; their encrypted keys are retained until cancellation or later activation after reactivation. Certificates, bindings, keys, prior PDFs, and audit history are not deleted. The public trust page retains downloads and labels the provider as retired.

Retiring the default CA requires the explicit-assignment gate to be enabled. If it is currently off, the confirmation requires checking **Also enable required explicit CA assignment**; both changes are saved atomically. No alternative default is chosen. The gate cannot be disabled while the default is retired. Unassigned projects must be assigned an active provider; already assigned projects without a signer remain blocked by retirement.

**Blocked sealing does not block eConsent completion.** REDCap may still store or deliver an unsealed PDF. For a bound project without a signer, project Logging records **PDF seal failed: CA provider retired** with record/event context where available. A CC administrator can prepare a transition to an active provider; the existing signer remains available until replacement activation.

**Reactivate CA** uses the same review/confirmation flow. It restores eligibility for assignments and enrollment, subject to validity checks, and leaves the assignment gate unchanged. These CC-only actions are audited. An issuance or activation already holding the configuration lock may finish before retirement completes; a changed impact requires reviewing again.

Retirement does not revoke certificates or provide an emergency stop for existing signing. Retiring the built-in CA leaves its existing TSA operational subject to normal validity checks. The built-in diagnostic cannot issue its temporary signer while this CA is retired: signer issuance fails and B-B/B-T sealing checks are skipped, while TSA checks can still pass. A cached diagnostic predating retirement is historical evidence only.

## Change a project’s CA provider

On **CA providers → Change project provider**, select a project and choose **Review project provider**. The selector lists projects with PDF Sealer enabled and an existing binding. The review shows the current provider and whether an active signing identity is assigned; this is not a certificate-health check.

Choose a different **active** replacement provider, then confirm the action:

- **External CA:** **Prepare provider change** authorizes enrollment with that CA. A designer or administrator then uses the project status page to generate a fresh CSR, obtain its certificate, and activate it. The current signer and its provider's timestamp policy remain in use until activation. The project displays both current and replacement providers.
- **Built-in CA:** **Issue and activate built-in replacement** creates a new key/certificate and atomically switches provider and signer. The built-in CA must be active and usable for issuance. Issuance or audit failure leaves the previous binding and signer unchanged.

The project UUID remains unchanged. New signing keys are used; historical identities and public CA certificates are retained. Switching back to a previous provider creates a new identity rather than reactivating its old certificate. Subsequent seals follow the new provider's timestamp policy; an already running seal may finish using its previous signer/policy.

Only one transition and one pending CSR are permitted per project. Cancel an existing CSR on the project page before starting a provider change. To withdraw a prepared external transition, review the project in CC and select **Cancel provider change**. This explicitly discards its pending CSR/encrypted key, if any, and keeps the current provider/signer. Backups may retain canceled material. Canceling only a CSR on the project page leaves the administrator's target-provider assignment in place so a new CSR can be generated.

Transitions can move away from a retired provider. If the target is retired during enrollment, new CSR generation and activation are blocked, but existing signing and pending CSR download/cancellation remain available. CC cancellation is still allowed. Retirement reviews include pending transition assignments even before a CSR exists.

Without an active signer, a pending transition blocks sealing until activation; the old provider does not automatically issue a certificate in the meantime. Project Logging records **PDF seal failed: provider transition pending**. REDCap can still store/deliver an unsealed PDF. Canceling the transition restores the original assignment's behavior. Previously generated PDFs are unchanged.

All transition actions are CC-only, audited, and synchronized with retirement and enrollment. Confirmation rechecks the reviewed binding/pending-request state. A stale review is rejected; refresh and review again. Initial assignment policy and the installation default are unchanged by a project transition.

## Renew a built-in project certificate

On **Root CA → Renew built-in project certificate**, choose an enabled project with an existing built-in signing certificate. External CA projects use the [CSR replacement workflow](PROJECT.md#validate-and-activate-the-returned-certificate). A pending provider change or enrollment must be completed or canceled before renewal. The built-in CA must be active, currently valid, and have a usable issuing key.

1. Select **1. Review current certificate**. Check its subject, SHA-256 fingerprint, validity dates, and the issuing CA's expiry. Review does not decrypt keys or change stored state. An expired project certificate is eligible.
2. Select **2. Renew and activate certificate**. This creates a fresh 3072-bit RSA key and signing certificate valid for up to 730 days, capped at the issuing CA's remaining whole days. Issuance requires at least one full day of remaining CA validity. The active binding switches when the identity, binding, and audit transaction commits. Renewal does not extend the CA's lifetime; signing still requires a valid issuing chain.
3. Refresh **PDF Sealer status** in the project. Its UUID and provider remain the same, while the fingerprint changes. Complete a new eConsent and check its seal and project Logging.

Existing identity history and previously sealed PDFs are retained. Subsequent seals use the replacement; a seal already in progress may finish with its previous signer. Failure before commit leaves the original binding intact. A changed signer/issuer, retirement, or pending work invalidates a review. If the browser request is interrupted, inspect the saved project certificate before retrying: the transaction may already have committed.

Only CC administrators can manually renew through authenticated AJAX. The audit records the actor, project, provider, and old/new public identity references and fingerprints. Page refresh, sealing, and expiry monitoring never trigger renewal. The hourly [maintenance cron](#automatic-built-in-certificate-maintenance) automatically renews existing built-in TSA/project certificates separately.

## Diagnostic: capability check and saved result

**Run diagnostic self-test** checks encryption, the active root and TSA, temporary signer issuance, B-B sealing, an RFC 3161 timestamp response, and B-T sealing. Both sealing profiles are checked regardless of the saved production mode; a failed B-T check is not hidden by fallback.

The test uses temporary identities and sample PDFs. It does not create a project certificate, write project sealing logs, change sealing settings, or send alarm emails. It saves a small summary of its completion time and fixed check outcomes. On PHP 8.2/8.3, temporary signer issuance also adds a system-scoped serial reservation containing its role, issuing-root fingerprint, and diagnostic purpose; no test certificate or private key is stored.

The last completed result remains visible after refresh, with its timestamp in the browser's local time zone (including the zone label) and its age. The timestamp follows your REDCap profile's date/time format, including date order, separators, and 12/24-hour clock; REDCap's system default applies when no profile preference is set. Certificate validity dates remain in UTC. Passing results are green for the first 24 hours, neutral until day 7, then increasingly red at 7, 14, and 30 days. Failed results stay red. These colors describe a historical result's age and outcome; they are not continuous monitoring. Rerun after relevant configuration or certificate changes. Saved diagnostics record the exact root, TSA, issuing root and policy tested; a warning appears when those versions differ from current configuration, or an older result lacks version information. The original run time and outcomes stay unchanged.

Failed checks replace the previous completed result too. If the diagnostic cannot complete, the previous result remains. If saving fails, the page identifies the new result as unsaved.

This diagnostic does not verify project pipeline assignment, saved PDFs, browser trust, revocation, long-term validation, or independent PAdES conformance.

## Alarms: recipients and test delivery

On **Alarms**, enter addresses separated by commas, semicolons, or whitespace and select **Save alarm recipients**. Empty input disables alarm email. Save changes before using **Send test alarm**; the confirmation shows the saved destinations.

A test message is clearly labeled as a test. Successful submission means REDCap accepted it for delivery; confirm receipt separately. Test messages have their own one-hour throttle and do not suppress real alarm emails.

During sealing, actionable PKI health problems create system-scoped alarm entries and can trigger email. Repeated successful notifications for the same condition/identity are limited to one per hour. Failed or unconfigured delivery does not start the throttle. These sealing-time alarms contain diagnostic identifiers and time. Not every sealing failure sends an alarm: also review project Logging and detailed failure entries.

### Revoke the current built-in project certificate

On **CA providers → Revoke built-in project certificate**, select a project and review its current subject and fingerprint. Choose **Superseded** or **Key compromise**, then confirm the irreversible action. Use normal renewal for ordinary replacement: renewal leaves the previous certificate unrevoked. This control addresses the current built-in project signer; external certificates follow their issuer's process. The TSA tab provides its own replacement/revocation controls; root revocation has separate controls on the Root CA tab.

Confirmation first commits a durable local block and public lifecycle audit. It then attempts CRL publication and fresh-key replacement in separate transactions. The result reports these outcomes separately. Publication or replacement failure never undoes the block. The new signer retains the UUID/provider and history; no private key is exported. A damaged revoked project key is not needed for recovery.

Revocation remains available for disabled projects, retired CAs, pending enrollment/provider changes and unusable private keys. Disabled projects can recover without being enabled. Pending work and retirement can defer replacement, and unusable PKI can prevent it; resolve the cause and hourly maintenance retries with backoff. Revoked signers take priority over ordinary expiry renewal. Pending CRL entries are retried by hourly maintenance and daily publication. A missing/corrupt CRL counter/ledger requires repair, rather than restarting an empty list.

Until replacement commits, sealing fails explicitly with the original PDF still available to REDCap. A seal being built must pass a final revocation check under the project lock before its working copy is accepted. Revocation that commits before this check blocks that result. A copy accepted before revocation may already be proceeding through REDCap storage/delivery; the module cannot recall it.

The published CRL can change validation of earlier PDFs. Their signed bytes remain unchanged, and viewers may retain a cached earlier list. Publication does not guarantee immediate viewer awareness or repair evidence affected by key compromise. Back up the system EM log history as well as PKI/CRL settings: the immutable revocation audits are the local block and publication/recovery record.

### Automatic built-in certificate maintenance

The hourly `certificate_maintenance` Framework cron renews the built-in root when it has **820 days or less** remaining (one full leaf lifetime plus the 90-day renewal window). Routine root renewal retains its existing key and exact subject/extensions. A fresh TSA, active root/TSA references and refreshed CRL activate together; historical certificates remain available on the trust page. TSA and **existing** built-in project signers renew with fresh keys within 90 days of expiry or when they use a previous root generation. Project UUIDs, CA assignments and identity history are retained. Normal module enable/update registers the job; Framework's **ExternalModuleValidation** job also discovers newly added crons in a development checkout. REDCap cron and this job must be enabled and running.

Each run handles root renewal first, then the TSA if due, then starts at most five project renewals within a 60-second work budget. Root renewal counts as two renewed certificates (root and fresh TSA). Already started work is allowed to finish; lock waits and generation can extend the run. Enabled projects take priority, then earliest expiry. Existing signers in temporarily disabled projects are maintained without enabling their module. The next run catches up after downtime. No certificates or assignments are created for projects without an active binding, and external certificates are never replaced with built-in identities.

Pending enrollment/provider changes and retired CAs defer project renewal. The built-in TSA continues to be maintained independently of CA retirement because external providers may use it. Failures retain prior references, alarm, and retry after one hour, doubling to a maximum of 24 hours; a changed identity or root generation bypasses obsolete backoff. Deferred projects retry hourly. An expired identity remains unusable until replacement succeeds.

**Alarms → Automatic built-in certificate maintenance** shows the last result, run time, renewal/deferred/failure counts and work awaiting another run or retry. A result over two hours old is flagged. Lifecycle audits use a system actor and public identity references; maintenance does not create a per-PDF project Logging entry. Daily expiry checks remain separate and can report a certificate whose maintenance is deferred or failing.

**Routine built-in root renewal is automatic, including recovery after expiry/downtime.** It requires intact stored certificates, matching decryptable keys, coherent provider/source references and the existing CRL counter/entries. Renewal preserves the CRL URL and revoked serials while increasing its number. Missing/corrupt PKI is never reset. If root renewal fails, leaf replacement still requires at least 91 days of usable issuer validity.

No routine root import or approval step is requested. The development Acrobat experiments accepted same-key renewal using the original trusted root; other viewers may have different trust behavior. **Confirmed root revocation automatically deploys a fresh root key and TSA, then replaces dependent built-in project signers in bounded batches.** A new key requires external viewer trust distribution; see [root lifecycle controls](#renew-or-revoke-the-built-in-root-ca). Built-in project/TSA certificate revocation and automatic replacement are available. A compromised key must not be repaired by same-key renewal. Ordinary renewal does not revoke prior certificates, rewrite old PDFs or provide long-term validation.

## Scheduled certificate expiry checks

The **Alarms** tab also shows the latest daily certificate expiry scan. It checks active registered external CA chains, the configured root/TSA, each project's latest active signer, and their referenced issuing certificates. Issued identities in disabled projects remain monitored. Historical signers that are no longer active are excluded, while an old or retired CA still referenced by an active signer remains included. Retired CAs with no active signer or TSA dependency are excluded from routine expiry alarms. Shared issuing certificates are counted once.

Warning bands are **90 days**, **30 days**, **7 days**, and **expired**. Missing, unreadable, or not-yet-valid certificates are flagged separately. The table lists up to 50 affected identities in urgency order, with project IDs where applicable; summary counts include the whole inventory. Certificate dates stay in UTC. The scan time uses the browser time zone and REDCap profile format.

Configured recipients receive one summary rather than one email per certificate. Successful summaries are limited to one per urgency band per 24 hours; escalation to a different band has a separate throttle. Failed or unconfigured sends do not suppress later attempts. Email contains counts and directs administrators to the CC page; it contains no project titles, participant data, certificates, or keys. A failed inventory scan is shown explicitly and generates its own daily-throttled alarm.

The module declares the `certificate_expiry` Framework cron with a 24-hour interval. REDCap cron must be running, and the job must be registered/enabled. After adding this cron to an existing development version, refresh its cron registration; normal module enable/update registers it. A missing result or a result older than 48 hours is visibly flagged. Scheduling depends on REDCap cron availability, so the interval is not a guaranteed wall-clock delivery time.

These are public-certificate date checks, not key, chain, revocation, or remote-service validation. They neither issue nor renew certificates. Built-in root/TSA/project renewal is handled by the separate hourly maintenance job; a CC administrator can also manually renew a built-in project certificate. External projects require replacement through enrollment.

## Public certificates and trust

The link above the tabs opens the public certificate page at the configured survey URL, typically `/surveys/?pdf_sealer_certs`. It lists built-in public roots, including historical roots present in storage, and registered external issuing/intermediate/root certificates, with fingerprints, validity dates, and PEM/DER downloads. It does not disclose private keys or project-to-UUID mappings.

The page and its downloads use the survey endpoint; they do not require public API access or a `NOAUTH` URL parameter. The module's every-page hook settings permit this specific anonymous endpoint. No login-page link is injected; use standard REDCap configuration if you want to advertise it there.

Distribute trust instructions through your institution's established channels. Recipients should verify the root fingerprint against an independently trusted source before trusting it. Downloading the root alone does not configure trust. On Windows, choose the **DER (.cer)** download and compare **Details → Thumbprint** with the page's **Windows thumbprint (SHA-1)**, ignoring letter case and spaces. The SHA-256 fingerprint is also shown. See [validation findings](sealing-and-validation.md).

### Built-in certificate revocation lists

The public certificate page includes a CRL link for each built-in issuing key. Its survey URL is `/surveys/?pdf_sealer_crl=<issuer-key-id>`; it returns a signed DER CRL directly, without login, JavaScript, public API access, or a `NOAUTH` parameter. External CAs remain responsible for their own revocation services.

The `certificate_crls` Framework cron refreshes complete lists once daily. Lists are valid for up to **72 hours**, limited by the issuing root's expiry. A newly initialized PKI publishes its first empty list during initialization. Existing installations receive their first list when cron runs. Normal module enable/update registers the job; Framework's **ExternalModuleValidation** job also discovers a newly added cron in a development checkout. Check that REDCap cron and the CRL job are enabled and running.

Newly issued built-in project and TSA certificates include the CRL distribution URL. Existing certificates and previously sealed PDFs remain unchanged; they cannot acquire this extension retrospectively. The URL must remain stable and reachable from recipients' PDF viewers. Changing the REDCap survey address requires preserving the old endpoint for certificates that already contain it.

Refresh failures preserve the previous committed list and raise `CRL_PUBLICATION_FAILED` in the Alarms tab, with the normal email throttle. An absent, expired, future-dated, or corrupt list returns HTTP 503; an unknown issuing key returns 404. Anonymous downloads never trigger issuance or read private keys.

This is the publication foundation. **Confirmed built-in project revocation is available** and attempts prompt publication. Hourly maintenance and daily refresh retry unpublished project/TSA entries and complete known-leaf lists for revoked root keys. Failed old-key publication does not prevent fresh-key recovery. Routine same-key root renewal refreshes the existing list and preserves its entries and counter. Retirement and ordinary certificate renewal do not revoke a certificate. Publication neither establishes viewer trust nor makes a PDF LTV enabled. Viewer CRL caches can delay recognition of a subsequently published revocation. See [the CRL technical reference](pki.md#built-in-crl-publication).

## Troubleshooting

| Finding | Action |
| --- | --- |
| No sealing activity | Check document type, module enablement, operation assignment/order, and that the active Core/Framework installation supports finalization. |
| PKI uninitialized | Complete one-time setup on Root CA. |
| PKI degraded | Inspect TSA details, run the diagnostic, and check the timestamp/fallback choice. A usable root can still support B-B. |
| PKI broken | Investigate root records, encryption availability, key/certificate consistency and CRL state. Intact expired built-in identities can recover through hourly maintenance; missing/corrupt storage is never reset. |
| Project certificate incomplete, expired, or unusable | Inspect the project status and failure details. Viewing the page will not renew or repair it. |
| PDF seal failed | Use the project Logging reference to locate its corresponding system-scoped `seal_event` EM log entry. Record/event IDs are associated when supplied. |
| B-B timestamp fallback | Check TSA health, settings, and the diagnostic. An intact B-B seal has no embedded timestamp. |
| Untrusted certificate, unavailable revocation, or “not LTV enabled” | Review the [trust and validation explanation](sealing-and-validation.md); these messages differ from document modification. |
| Document reported modified | Preserve the original saved/downloaded bytes and investigate integrity separately from trust. Avoid resaving the evidence through a PDF editor. |

## Development and current limits

PAdES B-LT/B-LTA are not implemented. Confirmed root revocation and automatic fresh-root-key recovery are available; installing or withdrawing viewer trust remains an institutional task. Confirmed built-in project/TSA revocation with automatic replacement is available. Hourly built-in root/TSA/project renewal is available. Built-in CRL publication is available as described above. Plan certificate lifecycle and recovery before operational reliance; [PKI](pki.md) describes the stored material and current behavior.

For implementation history, reproducible tests, acceptance evidence, and release packaging, see the repository's [developer documentation](https://github.com/grezniczek/redcap-pdf-sealer/tree/main/DEV_DOCS). It is intentionally excluded from installation packages; the linked development branch may be newer than your installed version. See also the [overview](../README.md) and [third-party notices](../THIRD_PARTY_NOTICES.md).

## Pending enrollment storage and recovery

Pending enrollment is stored separately from active identities in system-scoped module settings, tied to the project UUID and provider. Its private key is encrypted with REDCap's installation-key protection; generation verifies an encryption/decryption round trip before saving. Include these settings and the installation encryption material in recoverable backups. Pending requests persist across refresh/restart and remain until explicit cancellation (or activation); disabling a project/module does not intentionally discard them.

Cancellation removes the encrypted pending key and CSR from active settings and retains only public audit identifiers/fingerprints. It cannot erase older backups or database recovery history. Restore keys and their related project/provider/CSR state consistently. A stale browser request cannot download or cancel a different, newer enrollment. Corrupt or mismatched pending storage is reported as unavailable and is not silently replaced.

For external certificates, there is no project-key export, private-key import, automatic renewal, or cancellation-recovery UI. See the [project CSR workflow](PROJECT.md#generate-and-download-a-csr).

### External certificate acceptance and signing

The returned certificate must match the pending RSA-3072 key, be currently valid and non-CA, and validate through the assigned provider's exact registered issuing CA and root. Key usage, when present, must permit digitalSignature or contentCommitment; extended key usage, when present, must include documentSigning (`1.3.6.1.5.5.7.3.36`). CA extended-purpose restrictions are also enforced. External subject naming may differ from the CSR subject. Unknown critical extensions and invalid paths are rejected by OpenSSL. This validates the configured chain; it does not check revocation or establish independent institutional trust.

Only a single leaf PEM certificate (64 KiB maximum) is uploaded. Extra intermediates/bundles, DER/PKCS#12 files, and private keys are not accepted. The CC provider must already contain the complete intended CA chain. No certificate URLs are fetched. Not-yet-valid certificates leave the CSR pending and must be resubmitted later.

Review is read-only. Activation atomically records the encrypted identity and its public CA chain, switches the project binding, consumes the pending request, and records the actor/fingerprints. A replacement requires the reviewed active identity to remain unchanged. Earlier identity records, including encrypted keys, remain in the existing append-only identity history; cancellation only removes pending settings. Expiry checks follow the latest active signer and its pinned chain.

Subsequent seals embed the project certificate and complete pinned CA chain. Timestamping follows that provider's CC policy, with independent TSA trust validation for an external source if selected. External signing does not require the built-in CA's private key. The CC diagnostic still exercises the built-in CA/TSA; use an actual project workflow to verify external enrollment and signing.
