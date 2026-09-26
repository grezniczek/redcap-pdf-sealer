# Certificates and PKI

This reference describes the current PDF Sealer implementation. For actions in REDCap, use the [administrator guide](ADMIN.md) or [project guide](PROJECT.md).

## Certificate hierarchy

PKI means public key infrastructure: the certificates, keys, and trust relationships used for sealing and timestamping.

| Identity | Purpose | Issuer | Issuance validity |
| --- | --- | --- | --- |
| Root CA | Certifies the installation's TSA and project signers | Self-signed | 3,650 days |
| Timestamp authority (TSA) | Signs timestamp tokens | Root CA | 730 days |
| Project signer | Certifies PDFs for one project | Root CA | 730 days |

Each identity has a distinct RSA 3072-bit key. Certificates use SHA-256 signatures. These issuance durations do not guarantee a usable chain for that whole interval: the root and other validation conditions must also be satisfied. The actual certificate dates are authoritative and displayed in UTC.

The root is named **REDCap PDF Sealer Root CA**. The TSA is **REDCap PDF Sealer Timestamp Authority** and has a critical timestamping extended key usage. Project certificates use the document-signing extended key usage and a subject common name of **REDCap Project &lt;UUID&gt;**. All include the organization chosen at initialization; TSA and project subjects also include the organizational unit **REDCap PDF Sealer**.

The project UUID is pseudonymous and stable within the stored project binding. The certificate subject does not include the REDCap PID, project title, record ID, or participant name. PDFs themselves still contain their normal project/participant content; a pseudonymous certificate does not anonymize a PDF.

## Initialization and project issuance

Explicit administrator initialization creates the root and TSA together. It refuses to overwrite existing or orphaned PKI material. Health inspection never silently creates replacement keys.

A project's UUID and certificate binding are established on first sealing use. Subsequent operations reuse the identity. Locks serialize initialization and project issuance to prevent competing requests from creating conflicting active identities. Opening either status page is read-only with respect to certificate issuance.

## Storage and recovery

Certificates, encrypted private keys, and project bindings are stored in system-scoped External Module log records. Active root/TSA references and configuration are held in system settings. These records are PKI storage, not disposable diagnostic logs.

Private keys are encrypted with REDCap's installation encryption helpers and decrypted for use in server memory. This is software key storage within the REDCap installation; the root signing key is online, and no hardware security module or offline root ceremony is provided. Public certificate downloads contain no private keys.

Backups and recovery must preserve the module's identity/binding records and settings together with the REDCap encryption material needed to decrypt the keys. A database copy alone is insufficient if the required encryption material is lost or changed. Do not purge identity records as routine log cleanup or reset active pointers to force initialization. This version does not provide a PKI backup/export, migration, or repair wizard.

## Health and lifecycle

| Health | Meaning |
| --- | --- |
| UNINITIALIZED | No root has been initialized. Sealing cannot proceed. |
| READY | Active root and TSA pass the module's checks, including validity and usable matching keys. Project identity health is checked separately. |
| DEGRADED | The root is usable but the TSA is unavailable or invalid. B-B can still be used according to timestamp settings. |
| BROKEN | Root identity, binding, or key checks fail. Sealing fails. |

Missing or corrupt existing identities are not treated as permission to silently replace them. Automatic certificate renewal, rotation, and expiry-warning scheduling are not implemented. An expired project certificate is reported and rejected rather than automatically renewed. Retaining historical root certificates supports public inspection but is not a rotation workflow.

No CRL or OCSP publication service is provided. A root certificate's `cRLSign` key usage does not imply that a CRL is published. Long-term validation evidence is not embedded by this version.

## Trust boundary

The root is self-signed and belongs to this REDCap installation. Being embedded in a PDF or available for download does not make it a trusted anchor in a viewer. Institutions and recipients decide whether to trust it and how to verify its fingerprint.

The TSA runs in the same installation and uses the server's time. It is not an independent external time authority. Maintain the host's clock synchronization and protect its PKI/encryption material. Read [sealing and validation](sealing-and-validation.md) for what an embedded timestamp establishes and what it does not provide.
