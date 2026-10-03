# PDF Sealer — project guide

PDF Sealer adds a cryptographic seal to completed eConsent PDFs when the module is configured for your project. The seal lets a PDF viewer check whether the document has changed since sealing and identify the project's sealing certificate. With timestamping enabled, it also includes a timestamp from the timestamp authority selected by your administrator, either internal or external.

The seal identifies the issuing project and organization. It does not establish the identity of the person who completed the consent form. It adds no visible stamp or watermark and does not replace the eConsent workflow.

## Getting started

Your REDCap administrator must initialize the built-in PKI or assign an external CA and complete project certificate enrollment before sealing can work. The module must also be enabled in your project and its **Apply a cryptographic document seal** operation assigned in the project's **External Modules PDF finalization settings**.

**Enabling the module alone does not activate sealing.** Ask someone with access to those settings to assign the operation once, after any operations intended to change the PDF. A successful seal ends the pipeline. An earlier successful terminal operation can prevent sealing from being reached.

The current operation applies to completed eConsent PDFs. It does not seal ordinary record/form downloads, retroactively seal existing files, or provide a manual upload-and-seal action.

## Check project status

Open **PDF Sealer status** from the project's menu. This page requires Project Design rights or administrator access. It shows:

| Section | What to check |
| --- | --- |
| PDF finalization pipeline | **Assigned** means the operation is available. **Not assigned**, **Cannot run**, **Unavailable**, or **Review pipeline** needs attention. Follow the page's explanation. |
| Instance PKI | Reports whether the installation's root and timestamping certificates are usable. Ask an administrator about any warning. |
| Project sealing certificate | Shows the project's seal UUID, subject, fingerprint, and validity dates, or explains why a certificate is not yet available. |

The project certificate is created on first sealing use. An unissued certificate can therefore be normal before the first eligible PDF. Incomplete, expired, or unusable certificate states need administrator investigation. Opening the status page never issues, renews, or repairs certificates and does not validate old PDFs. Certificate dates are shown in UTC.

## Renewing a project certificate

An administrator can revoke a built-in project certificate, permanently blocking that signer. Fresh-key replacement is attempted automatically and retried by maintenance if needed. Until a replacement is active, status shows **Revoked** and sealing fails; earlier PDFs are not rewritten. Pending enrollment/provider changes and retired CAs may delay recovery.

An hourly maintenance job automatically renews existing built-in signing certificates near expiry, or after expiry when catching up after downtime. Renewal uses a fresh key/certificate while preserving your project UUID, CA assignment and history. A CC administrator can also renew manually. Refresh the status page to see the new fingerprint. Opening the page or creating a PDF does not trigger renewal. Routine renewal of the built-in root and TSA is also automatic. Contact your administrator if expiry warnings persist: pending enrollment/provider changes, a retired CA or corrupt/unavailable PKI can defer or prevent automatic renewal. Externally issued certificates still require the CSR replacement workflow.

External CA projects obtain a replacement through the CSR workflow described below. Previously sealed PDFs keep their original certificates and signatures.

## Check a sealing outcome

Users with the **Logging** user right can inspect the project's standard REDCap **Logging** page:

- **PDF seal succeeded** reports PAdES B-T (with timestamp) or B-B (without timestamp). A timestamp fallback is identified explicitly.
- **PDF seal failed** includes a reference when available. Give that reference to your administrator so they can find the corresponding detailed failure entry.
- Record and event associations appear when REDCap supplies them for the PDF operation.

If no entry appears, first check that the PDF was an eligible eConsent output and that the pipeline could reach the sealing operation. A ready status page is not evidence that a particular PDF was sealed.

**A sealing failure does not block PDF creation or delivery.** REDCap retains the PDF from before the failed operation. Your local workflow must account for this if a seal is required.

## Inspect a PDF and understand trust

Download the original saved PDF and open it in a viewer with digital-signature support. PDF Sealer uses a certification signature; Acrobat may describe the document as “Certified.” A successful integrity check indicates that the signed document has not been modified. Avoid printing to a new PDF or resaving it through an editor when you need to preserve the original seal.

A viewer can report an intact signature while also reporting that its certificate is untrusted or revocation could not be checked. These are different findings. PDF Sealer uses your installation's own certificate authority, which is not automatically trusted by PDF viewers. See [sealing, timestamps, and validation](sealing-and-validation.md) for explanations, including “not LTV enabled” messages.

## Root certificates

The **PDF Sealer root certificates** project link opens a public page with certificate details, fingerprints, and PEM/DER downloads. It is visible to all signed-in project users by default. Someone with module configuration access can hide the project menu link using **Hide the link to PDF Sealer root certificates in this project**. Hiding the link does not disable sealing or make the public page private.

Downloading a certificate does not make it trusted. Follow your institution's guidance for verifying the fingerprint and configuring trust. The public page can also be shared with recipients of sealed PDFs.

## Help

Contact your REDCap administrator with the project, relevant record/event if available, approximate generation time, and any failure reference or viewer message. They can review [administration and troubleshooting](ADMIN.md). For background, see [certificates and PKI](pki.md) or the [PDF Sealer overview](../README.md).

## External CA assignments

An administrator may assign a project to an external CA. The project status page shows the provider and **Awaiting signing certificate** until enrollment is completed. Local key generation and CSR download are available on the project status page. Upload the returned certificate to validate, review, and activate it. An external assignment never silently switches to built-in issuance. A failed sealing operation can still leave an unsealed PDF available to REDCap, so review project Logging as well as the status page.

## CA assignment required

If the administrator has enabled the explicit-assignment policy, an unassigned project shows **CA assignment required**. Ask an administrator to assign a provider before expecting sealed PDFs. Project designers cannot change this policy or choose the provider.

Until assignment, the sealing operation fails and project Logging records **PDF seal failed: CA assignment required**. This intentionally does **not** block eConsent completion: REDCap can still store or deliver the unsealed PDF. Assignment later permits subsequent sealing once the signing identity is ready; it does not seal previously generated PDFs retroactively. Existing project provider bindings continue working when this policy is enabled.

## When your CA is retired

The status page displays a retirement notice. Existing active signing certificates continue to seal PDFs while valid, but you cannot generate a new CSR or activate a returned certificate with that retired CA—even for a pending request. An active replacement provider chosen by an administrator can be used for enrollment. You can still download or cancel that request. Cancellation removes the pending key; it does not remove an active signer.

A project without an active certificate shows **CA retired — no active signer**. Sealing fails and is recorded in project Logging, but REDCap may still store or deliver an unsealed PDF. Contact an administrator: only CC administrators can reactivate a provider or prepare a change to a different CA. Retirement leaves previously sealed PDFs unchanged.

## Generate and download a CSR

After an administrator assigns an external CA, a project designer or administrator can open **PDF Sealer status** and select **Generate key and download CSR** under **External certificate enrollment**. The module creates a 3072-bit RSA key locally, encrypts it in REDCap, and downloads a public PEM certificate signing request (`.csr`). The subject uses the project's pseudonymous UUID, not its title or participant information.

Send the CSR to the assigned CA through your institution's process. The private key is not downloadable, including by administrators. The CSR requests a non-CA certificate for digital/document signing; the CA controls the certificate it issues. Generating a CSR alone does not enable sealing; the returned certificate must be validated and activated.

Only one pending request is allowed. **Download CSR** retrieves the same request after refresh; retrying generation also returns that request instead of replacing its key. The page shows the request subject, file SHA-256, and creation time in your browser time zone and REDCap profile format.

Use **Cancel pending request** only if you intend to discard it. After confirmation, the encrypted pending key is removed from active module storage; a returned certificate for that request cannot subsequently be activated. Backups can retain old data. Generate again to create a new key/CSR, and send the new CSR to the CA. Cancellation does not change an existing signing certificate or provider assignment.

## Validate and activate the returned certificate

Upload **one public PEM signing certificate** (maximum 64 KiB) under the pending request, then select **Validate and review certificate**. Do not upload a private key or a CA bundle. The full CA chain is taken from the provider assigned by your administrator.

Review the subject, issuing CA, SHA-256 fingerprint, and validity dates. The subject may follow your CA's naming rules; the key must match the pending CSR. Select **Activate signing certificate** to use it for subsequent seals. The module rechecks the certificate, pending request, provider chain, and current signer before committing activation.

Invalid, expired, not-yet-valid, wrong-key, or wrong-CA certificates are rejected without changing the pending request or current signer. A not-yet-valid certificate can be submitted again once valid. If another browser session canceled the request or changed the signer, refresh and review again.

Activation consumes the pending request. If the project already has a signer, it remains usable while you prepare its replacement and switches only on successful activation. Existing PDFs are unchanged. The project page should then show a ready signing certificate; complete a new eConsent and verify its seal and project Logging.

## When an administrator changes your CA

The status page shows the current provider and the **Replacement provider**. For an external replacement, use the enrollment section to generate a new CSR for that replacement provider, then validate/review and activate its returned certificate. You cannot select or substitute a CA yourself.

Your current signer and timestamp policy remain in use while the replacement is pending, subject to their normal validity checks. Activation switches both provider and certificate; subsequent seals use the new provider's timestamp policy. The project UUID and signing history are retained. A switch to the built-in CA is issued and activated directly by the CC administrator.

Canceling a CSR discards only its pending key/request, not the administrator's provider-change decision. Ask the administrator to **Cancel provider change** to withdraw that decision; it also discards any pending CSR but keeps the current signer. Old returned certificates cannot activate canceled requests.

If no active signer exists, the page shows **Provider change — awaiting certificate** and sealing remains blocked until activation. REDCap may still store or deliver an unsealed PDF. If the target CA is retired, contact an administrator before continuing enrollment.

### Timestamp source

The project status page shows the current provider's timestamp source and B-B fallback policy read-only. Only CC administrators can change them. A successful source diagnostic does not guarantee future requests will succeed. If a required timestamp fails, sealing fails; REDCap can still store or deliver the preceding unsealed PDF. Check project Logging and contact an administrator.

## Built-in TSA lifecycle

The installation's built-in TSA is renewed automatically. A CC administrator can also replace or revoke it; projects cannot perform that action. During pending replacement, sealing follows your provider's configured alternative TSAs and B-B fallback. A strict policy can fail sealing while REDCap still stores/delivers the preceding unsealed PDF. Check project Logging after a workflow completes.

TSA revocation does not change your project's signing certificate or existing PDF bytes. Non-compromise TSA withdrawal preserves earlier tokens under RFC 3161; a compromised TSA key means tokens signed with that key can no longer be trusted. See [sealing and validation](sealing-and-validation.md#tsa-replacement-and-revocation).

## When your issuing CA is revoked

An administrator can revoke the built-in CA's issuing key. Your old signer becomes unusable immediately; automatic maintenance replaces eligible built-in signers while preserving the project UUID and provider assignment. Pending enrollment/provider changes or a retired provider can delay replacement. Refresh the project status and contact your administrator if recovery remains pending. Externally assigned projects retain their external enrollment requirements.

A fresh root key requires new viewer trust distribution through your institution. An intact new seal may be reported as untrusted until the recipient trusts that root. Revocation and recovery do not change archived PDF bytes or retroactively seal an earlier unsealed archive. A failed seal can still leave REDCap's preceding PDF available for storage/delivery; review project Logging.
