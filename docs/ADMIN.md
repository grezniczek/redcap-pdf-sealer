# PDF Sealer — administrator guide

PDF Sealer maintains an installation-specific certificate authority and timestamp authority, issues project sealing certificates, and seals eligible eConsent PDFs. This guide covers setup and operation. The [project guide](PROJECT.md) explains what project users see; [PKI](pki.md) and [sealing and validation](sealing-and-validation.md) provide technical background.

## Requirements and installation

This is a reference implementation progressing toward v1. It requires:

- PHP 8.2 or later in the PHP 8 series, with OpenSSL, hash, JSON, PCRE, and zlib support, as required by the bundled libraries.
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

Settings on the **TSA** tab apply to future sealing operations across all enabled projects. Save both choices together using the page's save action.

| Setting | Behavior |
| --- | --- |
| Internal timestamp authority (PAdES B-T) | Embeds a timestamp issued by this installation. This is the default. |
| No timestamp (PAdES B-B) | Seals without a timestamp. |
| Allow sealing without a timestamp (B-B fallback) | If internal timestamping fails, attempts a B-B seal. Enabled by default. |
| Fail the sealing operation | If internal timestamping fails, the operation fails instead of using B-B. |

The fallback preference is retained while timestamping is disabled. Invalid stored settings produce a warning and need correction. The built-in TSA policy is used automatically; normal setup does not require an OID to be configured. See [timestamp policy](sealing-and-validation.md) for the advanced override.

**“Fail the sealing operation” does not mean “block the PDF.”** On failure, the Framework discards the failed working copy and retains the preceding PDF for REDCap to store or deliver. Root or project-key failures also fail the operation. Decide how your local process handles failed or fallback seals.

## Enable sealing in projects

1. Enable PDF Sealer in the project.
2. In that project's **External Modules PDF finalization settings**, assign **Apply a cryptographic document seal** once. Place it after operations that should alter the PDF; successful sealing is terminal.
3. Review **PDF Sealer status** for assignment warnings and instance PKI readiness. An earlier terminal operation or duplicate assignment needs review.
4. Complete an appropriate test eConsent workflow. The project certificate is issued lazily when sealing is first used.
5. Check the saved PDF and the project's **Logging** outcome. A passing diagnostic or an assigned operation alone does not demonstrate that the project's workflow reached sealing.

Only eligible completed eConsent PDFs are sealed. Existing archives and ordinary record/form PDFs are unaffected. The [project guide](PROJECT.md) covers project permissions and the optional menu link to public certificates.

## Diagnostic: capability check and saved result

**Run diagnostic self-test** checks encryption, the active root and TSA, temporary signer issuance, B-B sealing, an RFC 3161 timestamp response, and B-T sealing. Both sealing profiles are checked regardless of the saved production mode; a failed B-T check is not hidden by fallback.

The test uses temporary identities and sample PDFs. It does not create a project certificate, write project sealing logs, change sealing settings, or send alarm emails. It saves only a small summary of its completion time and fixed check outcomes.

The last completed result remains visible after refresh, with its UTC timestamp and age. Passing results are green for the first 24 hours, neutral until day 7, then increasingly red at 7, 14, and 30 days. Failed results stay red. These colors describe a historical result's age and outcome; they are not continuous monitoring. Rerun after relevant configuration or certificate changes.

Failed checks replace the previous completed result too. If the diagnostic cannot complete, the previous result remains. If saving fails, the page identifies the new result as unsaved.

This diagnostic does not verify project pipeline assignment, saved PDFs, browser trust, revocation, long-term validation, or independent PAdES conformance.

## Alarms: recipients and test delivery

On **Alarms**, enter addresses separated by commas, semicolons, or whitespace and select **Save alarm recipients**. Empty input disables alarm email. Save changes before using **Send test alarm**; the confirmation shows the saved destinations.

A test message is clearly labeled as a test. Successful submission means REDCap accepted it for delivery; confirm receipt separately. Test messages have their own one-hour throttle and do not suppress real alarm emails.

During sealing, actionable PKI health problems create system-scoped alarm entries and can trigger email. Repeated successful notifications for the same condition/identity are limited to one per hour. Failed or unconfigured delivery does not start the throttle. Alarm contents are limited to diagnostic identifiers and time. This is not a scheduled expiry monitor, and not every sealing failure sends an alarm: also review project Logging and detailed failure entries.

## Public certificates and trust

The link above the tabs opens the public certificate page at the configured survey URL, typically `/surveys/?pdf_sealer_certs`. It lists public roots, including historical roots present in storage, with fingerprints, validity dates, and PEM/DER downloads. It does not disclose private keys or project-to-UUID mappings.

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
