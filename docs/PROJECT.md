# PDF Sealer — project guide

PDF Sealer adds a cryptographic seal to completed eConsent PDFs when the module is configured for your project. The seal lets a PDF viewer check whether the document has changed since sealing and identify the project's sealing certificate. With timestamping enabled, it also includes a timestamp from your REDCap installation's timestamp authority.

The seal identifies the issuing project and organization. It does not establish the identity of the person who completed the consent form. It adds no visible stamp or watermark and does not replace the eConsent workflow.

## Getting started

Your REDCap administrator must initialize PDF Sealer's certificates before sealing can work. The module must also be enabled in your project and its **Apply a cryptographic document seal** operation assigned in the project's **External Modules PDF finalization settings**.

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

An administrator may assign a project to an external CA. The project status page shows the provider and **Awaiting signing certificate** until enrollment is completed. Certificate enrollment is not available in this version; local key/CSR generation and certificate upload will follow. An external assignment never silently switches to built-in issuance. A failed sealing operation can still leave an unsealed PDF available to REDCap, so review project Logging as well as the status page.

## CA assignment required

If the administrator has enabled the explicit-assignment policy, an unassigned project shows **CA assignment required**. Ask an administrator to assign a provider before expecting sealed PDFs. Project designers cannot change this policy or choose the provider.

Until assignment, the sealing operation fails and project Logging records **PDF seal failed: CA assignment required**. This intentionally does **not** block eConsent completion: REDCap can still store or deliver the unsealed PDF. Assignment later permits subsequent sealing once the signing identity is ready; it does not seal previously generated PDFs retroactively. Existing project provider bindings continue working when this policy is enabled.
