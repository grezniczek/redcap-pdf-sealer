# PDF Sealer — administrator guide

PDF Sealer maintains an installation-specific certificate authority and timestamp authority, issues project sealing certificates, and seals eligible eConsent PDFs. This guide covers setup and operation. The [project guide](PROJECT.md) explains what project users see; [PKI](pki.md) and [sealing and validation](sealing-and-validation.md) provide technical background.

## Requirements and installation

This is a reference implementation progressing toward v1. It requires:

- **PHP 8.4 or later in the PHP 8 series is recommended**, with OpenSSL, hash, JSON, PCRE, and zlib support. The minimum is PHP 8.2. PHP 8.4+ uses random 128-bit certificate serials; PHP 8.2/8.3 uses reserved EM log IDs as integer serials. The runtime running each issuance determines its serial format, so check both web and cron PHP versions. See [serial allocation and recovery](pki.md#certificate-serials).
- REDCap Core and an External Module Framework that implement the PDF finalization pipeline and `redcap_module_pdf_finalize`. The module declares Framework version 16, but that number alone does not establish availability of these features in a particular checkout or release.
- A complete module distribution, including `libraries/`, the module-owned `autoload.php`, and third-party licenses. Dependencies are already bundled with isolated namespaces; installation does not require Composer.
- Working REDCap encryption, database storage, and temporary-file support. REDCap email delivery is needed if alarm emails are configured.

Install and enable the module using REDCap's normal External Modules administration. The production sealing path uses PHP libraries and OpenSSL; it does not require qpdf, Poppler, or the OpenSSL command-line program. Those are development validation tools.

## Initialize the installation

1. As a superuser, open **PDF Sealer administration** in the Control Center. The page title is **Certificates & sealing**.
2. On **Root CA**, enter the organization to appear in certificates and initialize the PKI. This creates the root and the dedicated timestamp authority together.
3. Confirm the resulting health and certificate details. Initialization is a one-time action; existing or incomplete PKI material prevents another initialization. Reloading the page does not repeat the submission.
4. Download the public root in PEM or DER if needed. The public-certificate link above the tabs provides the distribution page.
5. Configure timestamping and alarm recipients, then run the diagnostic self-test.

Choose the organization carefully: the page does not provide a rename or certificate-replacement workflow. An existing broken PKI requires investigation rather than reinitialization. See [storage and lifecycle limitations](pki.md).

## TSA: timestamp settings

Settings on the **TSA** tab apply to future sealing operations for projects assigned to the built-in CA. Save both choices together using the page's save action.

| Setting | Behavior |
| --- | --- |
| Internal timestamp authority (PAdES B-T) | Embeds a timestamp issued by this installation. This is the default. |
| No timestamp (PAdES B-B) | Seals without a timestamp. |
| Allow sealing without a timestamp (B-B fallback) | If internal timestamping fails, attempts a B-B seal. Enabled by default. |
| Fail the sealing operation | If internal timestamping fails, the operation fails instead of using B-B. |

These controls configure the built-in CA provider and remain available only in the Control Center. The fallback preference is retained while timestamping is disabled. Invalid stored settings produce a warning and need correction. The built-in TSA policy is used automatically; normal setup does not require an OID to be configured. See [timestamp policy](sealing-and-validation.md) for the advanced override.

**“Fail the sealing operation” does not mean “block the PDF.”** On failure, the Framework discards the failed working copy and retains the preceding PDF for REDCap to store or deliver. Project-key or signing-chain failures also fail the operation. A root private-key failure blocks new certificate issuance but does not by itself block an existing usable project signer. Decide how your local process handles failed or fallback seals.

## Enable sealing in projects

1. Enable PDF Sealer in the project.
2. In that project's **External Modules PDF finalization settings**, assign **Apply a cryptographic document seal** once. Place it after operations that should alter the PDF; successful sealing is terminal.
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

For a busy project, enable the switch and **wait for the successful save confirmation before enabling or assigning the sealing pipeline**. Saving synchronizes with automatic first issuance. An automatic issuance that started earlier may finish before the save succeeds and retain its binding; after a successful save, an unbound project cannot automatically select the default CA. Inspect the project's status before assigning its provider. Existing bindings cannot currently be switched through this UI.

## External CA registration and project assignment

On **CA providers**, register a named external provider by uploading its public PEM chain: issuing CA first, any parent intermediates next, and the self-signed root last. A directly issuing root can be uploaded alone. The limit is eight certificates / 128 KiB. Certificates must be currently valid CAs with certificate-signing usage; ordering, signatures, path constraints, and duplicate chains are checked. Private keys are rejected. Registration publishes these certificates on the public trust page and includes them in daily expiry checks, even before a project uses them.

Choose **No timestamp** or explicitly choose the **internal TSA** if initialized. B-B fallback is unchecked initially. These choices belong to this provider; changing the built-in provider's TSA-tab settings does not change external providers. External TSA endpoints and editing an external provider's saved policy are not available yet.

To assign a provider, choose a project by title or PID in the searchable selector and select the provider. The selector lists active projects with PDF Sealer enabled that have no provider binding, excluding pending issuance/enrollment as well as active signers. Successfully assigned projects disappear from the list immediately. The server still checks for assignments made after the page was loaded. After success, the confirmation names both the provider and project and clears the selections for another assignment. Failed requests retain your selections. PDF Sealer must be enabled there. Assignment reserves a project UUID; it does not issue a certificate or assign a PDF pipeline operation. A project with a different existing provider binding cannot be reassigned in this version. Registration does not change the installation default: unassigned projects use the built-in provider when initialized only if the explicit-assignment gate is off. An external-only setup requires explicit project assignments.

**External enrollment supports CSR preparation, certificate review, and activation.** An externally assigned project shows **Awaiting signing certificate**. Until activation, sealing reports `PROJECT_CERTIFICATE_REQUIRED` and does not issue a built-in certificate. REDCap's existing finalization failure behavior can still store/deliver the preceding unsealed PDF; this is not a delivery-blocking policy. Use a test project to verify the full enrollment and sealing workflow before operational use. The built-in health summary and diagnostic continue to describe the built-in CA/TSA.

Registration and assignment use authenticated CC-only AJAX and system-scoped audit records. No CA private key is requested or stored. Project designers and administrators can generate a local project key, download its CSR, and explicitly cancel a pending request on the project status page. They can then upload one PEM signing certificate, review its validated details, and activate it.

## Diagnostic: capability check and saved result

**Run diagnostic self-test** checks encryption, the active root and TSA, temporary signer issuance, B-B sealing, an RFC 3161 timestamp response, and B-T sealing. Both sealing profiles are checked regardless of the saved production mode; a failed B-T check is not hidden by fallback.

The test uses temporary identities and sample PDFs. It does not create a project certificate, write project sealing logs, change sealing settings, or send alarm emails. It saves a small summary of its completion time and fixed check outcomes. On PHP 8.2/8.3, temporary signer issuance also adds a system-scoped serial reservation containing its role, issuing-root fingerprint, and diagnostic purpose; no test certificate or private key is stored.

The last completed result remains visible after refresh, with its timestamp in the browser's local time zone (including the zone label) and its age. The timestamp follows your REDCap profile's date/time format, including date order, separators, and 12/24-hour clock; REDCap's system default applies when no profile preference is set. Certificate validity dates remain in UTC. Passing results are green for the first 24 hours, neutral until day 7, then increasingly red at 7, 14, and 30 days. Failed results stay red. These colors describe a historical result's age and outcome; they are not continuous monitoring. Rerun after relevant configuration or certificate changes.

Failed checks replace the previous completed result too. If the diagnostic cannot complete, the previous result remains. If saving fails, the page identifies the new result as unsaved.

This diagnostic does not verify project pipeline assignment, saved PDFs, browser trust, revocation, long-term validation, or independent PAdES conformance.

## Alarms: recipients and test delivery

On **Alarms**, enter addresses separated by commas, semicolons, or whitespace and select **Save alarm recipients**. Empty input disables alarm email. Save changes before using **Send test alarm**; the confirmation shows the saved destinations.

A test message is clearly labeled as a test. Successful submission means REDCap accepted it for delivery; confirm receipt separately. Test messages have their own one-hour throttle and do not suppress real alarm emails.

During sealing, actionable PKI health problems create system-scoped alarm entries and can trigger email. Repeated successful notifications for the same condition/identity are limited to one per hour. Failed or unconfigured delivery does not start the throttle. These sealing-time alarms contain diagnostic identifiers and time. Not every sealing failure sends an alarm: also review project Logging and detailed failure entries.

### Scheduled certificate expiry checks

The **Alarms** tab also shows the latest daily certificate expiry scan. It checks all registered external CA chains, the configured root/TSA, each project's latest active signer, and their referenced issuing certificates. Issued identities in disabled projects remain monitored. Historical signers that are no longer active are excluded, while an old CA still referenced by an active signer remains included. Shared issuing certificates are counted once.

Warning bands are **90 days**, **30 days**, **7 days**, and **expired**. Missing, unreadable, or not-yet-valid certificates are flagged separately. The table lists up to 50 affected identities in urgency order, with project IDs where applicable; summary counts include the whole inventory. Certificate dates stay in UTC. The scan time uses the browser time zone and REDCap profile format.

Configured recipients receive one summary rather than one email per certificate. Successful summaries are limited to one per urgency band per 24 hours; escalation to a different band has a separate throttle. Failed or unconfigured sends do not suppress later attempts. Email contains counts and directs administrators to the CC page; it contains no project titles, participant data, certificates, or keys. A failed inventory scan is shown explicitly and generates its own daily-throttled alarm.

The module declares the `certificate_expiry` Framework cron with a 24-hour interval. REDCap cron must be running, and the job must be registered/enabled. After adding this cron to an existing development version, refresh its cron registration; normal module enable/update registers it. A missing result or a result older than 48 hours is visibly flagged. Scheduling depends on REDCap cron availability, so the interval is not a guaranteed wall-clock delivery time.

These are public-certificate date checks, not key, chain, revocation, or remote-service validation. They neither issue nor renew certificates. Arrange replacement before expiry; external projects can prepare and activate a replacement through enrollment; built-in renewal and automatic renewal are not yet available.

## Public certificates and trust

The link above the tabs opens the public certificate page at the configured survey URL, typically `/surveys/?pdf_sealer_certs`. It lists built-in public roots, including historical roots present in storage, and registered external issuing/intermediate/root certificates, with fingerprints, validity dates, and PEM/DER downloads. It does not disclose private keys or project-to-UUID mappings.

The page and its downloads use the survey endpoint; they do not require public API access or a `NOAUTH` URL parameter. The module's every-page hook settings permit this specific anonymous endpoint. No login-page link is injected; use standard REDCap configuration if you want to advertise it there.

Distribute trust instructions through your institution's established channels. Recipients should verify the root fingerprint against an independently trusted source before trusting it. Downloading the root alone does not configure trust. See [validation findings](sealing-and-validation.md).

## Troubleshooting

| Finding | Action |
| --- | --- |
| No sealing activity | Check document type, module enablement, operation assignment/order, and that the active Core/Framework installation supports finalization. |
| PKI uninitialized | Complete one-time setup on Root CA. |
| PKI degraded | Inspect TSA details, run the diagnostic, and check the timestamp/fallback choice. A usable root can still support B-B. |
| PKI broken | Investigate root records, validity, encryption availability, and key/certificate consistency. No automatic replacement occurs. |
| Project certificate incomplete, expired, or unusable | Inspect the project status and failure details. Viewing the page will not renew or repair it. |
| PDF seal failed | Use the project Logging reference to locate its corresponding system-scoped `seal_event` EM log entry. Record/event IDs are associated when supplied. |
| B-B timestamp fallback | Check TSA health, settings, and the diagnostic. An intact B-B seal has no embedded timestamp. |
| Untrusted certificate, unavailable revocation, or “not LTV enabled” | Review the [trust and validation explanation](sealing-and-validation.md); these messages differ from document modification. |
| Document reported modified | Preserve the original saved/downloaded bytes and investigate integrity separately from trust. Avoid resaving the evidence through a PDF editor. |

## Development and current limits

Automatic renewal/rotation, revocation publication, external TSA configuration, and PAdES B-LT/B-LTA are not implemented. Plan certificate lifecycle and recovery before operational reliance; [PKI](pki.md) describes the stored material and current behavior.

For implementation history, reproducible tests, acceptance evidence, and release packaging, see the repository's [developer documentation](https://github.com/grezniczek/redcap-pdf-sealer/tree/main/DEV_DOCS). It is intentionally excluded from installation packages; the linked development branch may be newer than your installed version. See also the [overview](../README.md) and [third-party notices](../THIRD_PARTY_NOTICES.md).

## Pending enrollment storage and recovery

Pending enrollment is stored separately from active identities in system-scoped module settings, tied to the project UUID and provider. Its private key is encrypted with REDCap's installation-key protection; generation verifies an encryption/decryption round trip before saving. Include these settings and the installation encryption material in recoverable backups. Pending requests persist across refresh/restart and remain until explicit cancellation (or activation); disabling a project/module does not intentionally discard them.

Cancellation removes the encrypted pending key and CSR from active settings and retains only public audit identifiers/fingerprints. It cannot erase older backups or database recovery history. Restore keys and their related project/provider/CSR state consistently. A stale browser request cannot download or cancel a different, newer enrollment. Corrupt or mismatched pending storage is reported as unavailable and is not silently replaced.

There is no project-key export, private-key import, automatic renewal, or cancellation-recovery UI. See the [project CSR workflow](PROJECT.md#generate-and-download-a-csr).

### External certificate acceptance and signing

The returned certificate must match the pending RSA-3072 key, be currently valid and non-CA, and validate through the assigned provider's exact registered issuing CA and root. Key usage, when present, must permit digitalSignature or contentCommitment; extended key usage, when present, must include documentSigning (`1.3.6.1.5.5.7.3.36`). CA extended-purpose restrictions are also enforced. External subject naming may differ from the CSR subject. Unknown critical extensions and invalid paths are rejected by OpenSSL. This validates the configured chain; it does not check revocation or establish independent institutional trust.

Only a single leaf PEM certificate (64 KiB maximum) is uploaded. Extra intermediates/bundles, DER/PKCS#12 files, and private keys are not accepted. The CC provider must already contain the complete intended CA chain. No certificate URLs are fetched. Not-yet-valid certificates leave the CSR pending and must be resubmitted later.

Review is read-only. Activation atomically records the encrypted identity and its public CA chain, switches the project binding, consumes the pending request, and records the actor/fingerprints. A replacement requires the reviewed active identity to remain unchanged. Earlier identity records, including encrypted keys, remain in the existing append-only identity history; cancellation only removes pending settings. Expiry checks follow the latest active signer and its pinned chain.

Subsequent seals embed the project certificate and complete pinned CA chain. Timestamping follows that provider's CC policy, including a separately issued internal TSA chain if selected. External signing does not require the built-in CA's private key. The CC diagnostic still exercises the built-in CA/TSA; use an actual project workflow to verify external enrollment and signing.
