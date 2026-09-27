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

Initialization also creates the built-in CA provider and internal timestamp source. The CA provider records its issuing identity and timestamp policy; the source separately records its TSA identity, issuing certificate, and policy OID. Built-in sealing is available; external providers support assignment and local CSR preparation, including certificate activation and external-chain sealing; external timestamp services remain planned.

A project's UUID, provider assignment, and certificate binding are established on first sealing use. Subsequent operations reuse the identity and its recorded issuing certificate. Changing the default provider does not reassign existing projects. Locks serialize initialization and project issuance to prevent competing requests from creating conflicting active identities. Opening either status page is read-only with respect to certificate issuance.

## Certificate serials

The PHP runtime performing issuance selects the certificate serial format:

- **PHP 8.4+ (recommended):** a positive 128-bit random serial using OpenSSL's hexadecimal serial argument. The highest bit is set and the other 127 bits are random, keeping these serials outside the integer range.
- **PHP 8.2/8.3:** the integer ID returned by a new system-scoped `pki_serial_reservation` EM log entry. The database allocates the ID atomically, so simultaneous requests do not calculate or compete for a next serial. The entry also records the identity role, issuing-root SHA-256 fingerprint (empty for a self-signed root), and issuance/diagnostic purpose. It is an allocation record, not proof that issuance completed. A failed issuance can leave a harmless gap.

Serial allocation must succeed before signing. Nonpositive, malformed, or out-of-range IDs cause issuance to fail. The integer limit is PHP's signed integer maximum on Unix-like platforms; Windows is limited to 2,147,483,647 by OpenSSL's C `long` argument. PHP 8.4+ avoids this limit. An issued certificate is checked against its allocated serial before being returned.

Existing certificates are reused across PHP versions; changing PHP does not reissue them. New random and integer serials can coexist under one root. RFC 3161 **timestamp-token serials remain random** on all supported PHP versions; this version split applies only to certificates.

## Storage and recovery

Certificates, encrypted private keys, project bindings, and integer serial reservations are stored in system-scoped External Module log records. Active root/TSA references, provider/source configuration, and the default provider are held in system settings. Each project certificate also records its provider and issuing identity. These records are PKI storage, not disposable diagnostic logs.

Private keys are encrypted with REDCap's installation encryption helpers and decrypted for use in server memory. This is software key storage within the REDCap installation; the root signing key is online, and no hardware security module or offline root ceremony is provided. Public certificate downloads contain no private keys.

Backups and recovery must preserve the module's identity/binding records and settings together with the REDCap encryption material needed to decrypt the keys. A database copy alone is insufficient if the required encryption material is lost or changed. Do not purge identity or reservation records as routine log cleanup, truncate/reset the EM log ID sequence, or reset active pointers to force initialization. This version does not provide a PKI backup/export, migration, or repair wizard.

When integer serials have been used, restoring an older backup or cloning the installation can reuse previously allocated IDs under the same CA. Before issuing again on PHP 8.2/8.3, an administrator must ensure the EM log auto-increment sequence is above **every** ID previously allocated under that CA, including allocations after the backup. If that cannot be established, use PHP 8.4+ for further issuance or a new issuing key; do not resume integer allocation with that CA. Separate clones must not independently issue integer serials using the same CA. The module does not automatically reconcile these recovery cases.

## Health and lifecycle

| Health | Meaning |
| --- | --- |
| UNINITIALIZED | No root has been initialized. Sealing cannot proceed. |
| READY | Active root and TSA pass the module's checks, including validity and usable matching keys. Project identity health is checked separately. |
| DEGRADED | The root is usable but the TSA is unavailable or invalid. B-B can still be used according to timestamp settings. |
| BROKEN | Built-in root identity, binding, or key checks fail. New issuance fails; existing project signing capability is checked independently. |

The table summarizes built-in issuance health. Sealing checks the project key/certificate and its recorded public issuer certificate separately; a missing or corrupt root private key alone does not prevent reuse of a valid project signer. The internal TSA is likewise checked against its own public issuing certificate and private key. Certificate validity and chain checks still apply.

Missing or corrupt existing identities are not treated as permission to silently replace them. Automatic certificate renewal and rotation are not implemented. A daily Framework cron checks active/public certificate dates and warns at 90, 30, and 7 days and after expiry; see [scheduled expiry checks](ADMIN.md#scheduled-certificate-expiry-checks) for scope and notification behavior. An expired project certificate is reported and rejected rather than automatically renewed. Retaining historical root certificates supports public inspection but is not a rotation workflow.

No CRL or OCSP publication service is provided. A root certificate's `cRLSign` key usage does not imply that a CRL is published. Long-term validation evidence is not embedded by this version.

## Trust boundary

The root is self-signed and belongs to this REDCap installation. Being embedded in a PDF or available for download does not make it a trusted anchor in a viewer. Institutions and recipients decide whether to trust it and how to verify its fingerprint.

The TSA runs in the same installation and uses the server's time. It is not an independent external time authority. Maintain the host's clock synchronization and protect its PKI/encryption material. Read [sealing and validation](sealing-and-validation.md) for what an embedded timestamp establishes and what it does not provide.

## Registered external CA chains

External provider configuration stores only public CA certificates (ordered issuing CA to self-signed root), SHA-256 hashes, a display name, and an explicit timestamp source/fallback policy. Registration validates current CA validity, certificate-signing usage, issuer signatures, chain order, and path constraints without fetching remote certificates or revocation data. These checks do not establish institutional trust or revocation status.

External chains are published on the trust page and included in expiry monitoring; shared external certificates are deduplicated by SHA-256 in that inventory. Provider registration is serialized with built-in initialization. Project assignment uses the same project lock as local issuance and atomically stores the UUID/provider binding and administrative audit. External-only pending bindings do not prevent later built-in initialization.

Assigned external projects can prepare a local key/CSR, activate a validated returned certificate, and seal using its pinned CA chain. Existing provider assignments remain immutable; built-in renewal, provider transitions, and provider retirement remain future work.

## Explicit-assignment gate

The system setting `require_ca_assignment` defaults to off when absent. Enabling it requires a concrete administrator assignment before a project without a binding may obtain its first identity. The setting is independent of CA count; malformed values prevent automatic issuance rather than being interpreted as off. Existing bindings remain usable.

Automatic first issuance takes the project issuance lock, then the shared PKI configuration lock, and reads the policy from the primary database. It holds both through binding, issuance, and activation. Policy saves take the configuration lock and commit the setting with an administrative audit. This ordering prevents a save from reporting success while an earlier automatic first issuance is still running. Already bound projects use their project lock and do not wait for the policy lock. Earlier completed bindings are retained.

A required assignment returns an explicit sealing failure without creating a UUID, serial reservation, or certificate. The original PDF remains available to REDCap; eConsent completion and delivery are not blocked by this policy. Project Logging records the reason, while the corresponding EM failure entry retains diagnostic context.

## Pending key and CSR lifecycle

`pending_enrollment_<PID>` is a system-scoped JSON setting containing a random enrollment ID, project UUID, provider ID, creation time, public PEM CSR/file SHA-256, and encrypted private key. It is separate from `pki_identity` and never becomes an active signer merely by being generated. Generation and cancellation use the project issuance lock and a transaction covering storage plus the public `project_enrollment` audit. Repeat generation reuses the pending request. Download/cancel require its exact enrollment ID, preventing stale requests from affecting a replacement.

Keys are RSA 3072; CSRs use SHA-256 and request `CA:false`, digitalSignature, and documentSigning (`1.3.6.1.5.5.7.3.36`). The subject contains only `CN=REDCap Project <UUID>`. No CA key is involved and no certificate serial is allocated. OpenSSL configuration uses Framework temporary files, which are removed; private keys are never written there. The key stays encrypted in durable settings, with no download endpoint. Page display and repeat downloads do not decrypt it.

Pending enrollment is retained until explicitly canceled or consumed by activation. Cancellation transactionally removes it from active settings while preserving public audit metadata, leaving any active signer unchanged. Backups/history may retain encrypted copies. Activation rechecks the exact pending ID, key match, provider chain, validity, and signing profile under the same project lock. It also verifies the reviewed certificate/chain hash and expected active identity. Imported private keys are not accepted yet.

## External signer activation and chain provenance

External project identities store their exact issuing chain in `issuer_chain_json` alongside the encrypted project key and certificate. `issuer_identity_id` is the issuing certificate's SHA-256 for external identities; built-in identities retain their internal issuer identity ID. Chain records are integrity-checked and carried with the immutable identity, rather than substituted from current provider configuration when signing.

Validation checks the leaf against the pending key/CSR and the entire registered path with OpenSSL, plus the module's RSA/document-signing profile. The project UUID/provider binding establishes ownership; the external CA may change the subject. Certificate review does not persist the upload. Activation repeats validation under the project lock and transactionally appends the identity, replaces the expected binding, removes pending storage, and audits activation. Both first activation and manual replacement use this path.

External sealing validates the stored leaf and pinned chain on use and includes the entire chain in CMS. Built-in issuer-key health is only relevant to built-in issuance. Timestamp validation continues against the separately selected TSA source. Expiry inventory includes the active external signer and pinned issuing certificates, without reading private-key fields.
