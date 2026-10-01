# Certificates and PKI

This reference describes the current PDF Sealer implementation. For actions in REDCap, use the [administrator guide](ADMIN.md) or [project guide](PROJECT.md).

## Certificate hierarchy

PKI means public key infrastructure: the certificates, keys, and trust relationships used for sealing and timestamping.

| Identity | Purpose | Issuer | Issuance validity |
| --- | --- | --- | --- |
| Root CA | Certifies the installation's TSA and project signers | Self-signed | 3,650 days |
| Timestamp authority (TSA) | Signs timestamp tokens | Root CA | Up to 730 days, capped by issuer validity |
| Project signer | Certifies PDFs for one project | Root CA | Up to 730 days, capped by issuer validity |

Each identity has a distinct RSA 3072-bit key. Certificates use SHA-256 signatures. These issuance durations do not guarantee a usable chain for that whole interval: the root and other validation conditions must also be satisfied. The actual certificate dates are authoritative and displayed in UTC.

Built-in leaf issuance uses the lesser of 730 days and the issuer's remaining whole days. At least one full day must remain; otherwise issuance fails until a usable issuer is available. The limit is checked before allocating a serial and recalculated after key generation. The issued certificate's expiry is also checked against the issuer's expiry. This applies to project, TSA and temporary diagnostic certificates; it does not renew the root automatically.

The root is named **REDCap PDF Sealer Root CA**. The TSA is **REDCap PDF Sealer Timestamp Authority** and has a critical timestamping extended key usage. Project certificates use the document-signing extended key usage and a subject common name of **REDCap Project &lt;UUID&gt;**. All include the organization chosen at initialization; TSA and project subjects also include the organizational unit **REDCap PDF Sealer**.

The project UUID is pseudonymous and stable within the stored project binding. The certificate subject does not include the REDCap PID, project title, record ID, or participant name. PDFs themselves still contain their normal project/participant content; a pseudonymous certificate does not anonymize a PDF.

## Initialization and project issuance

Explicit administrator initialization creates the root and TSA together. It refuses to overwrite existing or orphaned PKI material. Health inspection never silently creates replacement keys.

Initialization also creates the built-in CA provider and internal timestamp source. The CA provider records its issuing identity and timestamp policy; the source separately records its TSA identity, issuing certificate, and policy OID. Built-in sealing is available; external providers support assignment and local CSR preparation, including certificate activation and external-chain sealing. Administrators can also register an external timestamp source and select it per CA provider.

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

Built-in CRLs are published as described below. Manual revocation controls and OCSP are not implemented. Long-term validation evidence is not embedded by this version.

## Built-in CRL publication

Complete, direct X.509 v2 CRLs cover certificates issued by each built-in CA key. Their signatures use RSA/SHA-256; authorityKeyIdentifier matches the root's subjectKeyIdentifier and cRLNumber increases on every publication. Empty lists omit revokedCertificates. The DER profile follows [RFC 5280 section 5](https://www.rfc-editor.org/rfc/rfc5280.html#section-5); HTTP delivers `application/pkix-crl` as described in [RFC 2585](https://www.rfc-editor.org/rfc/rfc2585.html).

The stable issuer-key ID is SHA-256 of the certificate's subjectPublicKey BIT STRING contents, excluding the unused-bits octet. The canonical survey URL contains this ID. Newly issued built-in project/TSA certificates carry it in the noncritical CRL distribution-points extension. Root certificates and external enrollments are unchanged. Same-key, same-subject/profile root renewal can retain the URL, counter and revoked serials; a different key has a different URL. Routine root renewal itself is still future work.

A hidden system setting `crl_<issuer-key-id>` stores one versioned JSON snapshot: key ID, increasing integer number, thisUpdate, nextUpdate, complete revocation entries and base64 DER. Hexadecimal certificate serials are retained as strings, including 128-bit serials. The initial/current list is empty because administrative revocation is not implemented. The encoder supports keyCompromise, superseded and cessationOfOperation entries for that later workflow. A future revocation operation must durably block local use and publish the changed complete list promptly; waiting for daily cron is insufficient.

Publication uses the shared configuration lock and a transaction covering the snapshot and public `pki_crl_publication` EM audit. Read-back uses the primary database rather than cached settings. The worker deduplicates same-key root versions, preserves prior entries/counters, and retains a prior snapshot on failure. Corrupt storage, a backward clock and number exhaustion fail rather than resetting the list. Retired but still-valid historical issuing keys continue to publish; expired historical roots retain their last snapshot and require the future lifecycle policy before further publication. An expired active issuer fails publication and alarms.

The daily job issues lists valid for up to 72 hours, capped at root expiry. Public reads verify the cached signature against a known public root and match the signed content to the stored metadata. Missing/expired/future/corrupt lists are unavailable, never synthesized as empty lists. Success responses permit a five-minute HTTP cache bounded by nextUpdate; viewers may maintain their own caches. No private-key access occurs on public requests. Include these system settings in PKI backups: recovery must preserve published revoked serials and monotonic counters, and this version has no restoration reconciliation wizard.

CRLs do not withdraw external trust in a self-signed root, implement OCSP, or embed revocation evidence into PDFs. Existing leaf certificates cannot be retrofitted with distribution URLs. See [administrator operation](ADMIN.md#built-in-certificate-revocation-lists).

## Trust boundary

The root is self-signed and belongs to this REDCap installation. Being embedded in a PDF or available for download does not make it a trusted anchor in a viewer. Institutions and recipients decide whether to trust it and how to verify its fingerprint.

Each internal timestamp attempt captures its source's TSA identity, issuing certificate and policy together. Health validation and signing use those exact versions even if configuration changes during the operation. Production tokens use current server UTC at token creation, and both the TSA certificate and its chain must be valid at that time. Diagnostic timestamp checks follow the same capture rules.

The built-in TSA runs in the same installation and uses the server's time. It is not an independent external time authority. Maintain the host's clock synchronization and protect its PKI/encryption material. Read [sealing and validation](sealing-and-validation.md) for what an embedded timestamp establishes and what it does not provide.

## Registered external CA chains

External provider configuration stores only public CA certificates (ordered issuing CA to self-signed root), SHA-256 hashes, a display name, and an explicit timestamp source/fallback policy. Registration validates current CA validity, certificate-signing usage, issuer signatures, chain order, and path constraints without fetching remote certificates or revocation data. These checks do not establish institutional trust or revocation status.

External chains are published on the trust page and included in expiry monitoring; shared external certificates are deduplicated by SHA-256 in that inventory. Provider registration is serialized with built-in initialization. Project assignment uses the same project lock as local issuance and atomically stores the UUID/provider binding and administrative audit. External-only pending bindings do not prevent later built-in initialization.

Assigned external projects can prepare a local key/CSR, activate a validated returned certificate, and seal using its pinned CA chain. Provider assignment can change only through the explicit CC transition workflow. CA retirement/reactivation and manual built-in project certificate renewal are implemented; automatic renewal and root/TSA rotation remain future work.

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

## Provider retirement

A system setting `ca_provider_retired_<provider-id>` stores the provider's retirement flag (`true` / `false`; absence means active). Malformed values fail closed for lifecycle operations. Public chains and identity/binding history remain intact. Retirement is independent of cryptographic validity and of the TSA source's health.

CC retirement/reinstatement reviews use the latest public binding and enrollment audit records, including disabled projects, and a digest of that impact plus current state/default/assignment policy. The server recomputes this digest at confirmation. Retirement, reactivation, and any required assignment-gate change are transactional and audited. Retiring the default is permitted only with required explicit assignment; reactivation does not turn that policy off.

Mutations that can change retirement impact acquire the project lock, then the configuration lock: explicit assignment, first local issuance (including bound projects), CSR generation/cancellation, provider transitions, and external activation. Retirement takes only the configuration lock. This ordering prevents a stale page from assigning, issuing, or activating after a successful retirement. Existing active signer reuse does not need the configuration lock. Diagnostic temporary issuance also takes the configuration lock and checks provider eligibility.

Retirement prevents new enrollment/activation but retains pending encrypted keys and downloadable CSRs. Cancellation is still permitted. Active external signers retain their pinned chains, and existing built-in signers retain their recorded public issuer. Expiry monitoring excludes unused retired CA chains but retains dependencies of active signers and the existing TSA. Public certificate publication/downloads remain available with retirement labelling. No revocation or emergency signing-stop behavior is implied.

## Project provider transitions

A binding can now contain `pending_provider_id` and `transition_id` alongside its current `provider_id` and `identity_id`. Pending fields are both absent/null when no transition exists. The current provider stays paired with its active signer; the pending provider is used only for enrollment. The project UUID is invariant. A provider change in binding history is accepted only when the preceding binding authorized that target and the new binding activates a different, non-null identity.

CC preparation and cancellation take the project lock followed by the configuration lock. A review digest includes the current binding, transition ID, and pending enrollment ID; mutations recheck it under both locks. Preparation refuses an existing CSR or transition instead of silently overwriting it. Targets must be active; retirement of the current source does not prevent moving away from it.

External transitions append the pending target without creating a key. Existing enrollment then uses that target for key/CSR storage and returned-certificate validation. Activation transactionally appends the identity, switches provider/identity, clears pending binding fields, removes the pending key setting, and audits the transition ID and previous provider/signer. CC cancellation transactionally cancels any pending CSR, clears the target, and audits cancellation. Designer CSR cancellation alone retains the target assignment.

A transition to the built-in CA issues a fresh identity and activates it within the same CC transaction; failure rolls back identity/binding/audit writes. It does not reuse historical identities or interrupted first-issuance recovery. Transitions do not change the installation default, assignment gate, or provider-specific timestamp configuration.

Signing uses the current identity and its provider's timestamp policy until activation. An in-flight seal can finish under the identity it already selected. Projects without an active identity fail with `PROVIDER_TRANSITION_PENDING` while enrollment is pending; they cannot automatically issue under the previous assignment. Expiry inventory continues to follow active signers, and retirement impact includes both current and pending provider associations. Historical chains remain available.

## External timestamp source storage and trust

External sources are system-scoped `tsa_source_remote-tsa-…` settings with stable IDs, names, HTTPS endpoint, optional policy OID, a pinned public issuing-CA-to-root chain, and encrypted Basic credentials (or no credentials). The catalog is `external_tsa_source_ids`; the latest bounded diagnostic observation is `tsa_diagnostic_<source-id>`. Sources are immutable in this version and remain stored after providers switch away. Registration and policy changes share the PKI configuration lock and commit atomically with an EM audit entry. Browser summaries and audit payloads exclude credentials and endpoint URLs. Project copy/export must not transfer these system-scoped secrets.

A CA provider stores a primary source and an ordered list of up to two distinct alternatives in `timestamp_alternatives` (absent means an empty list). No-timestamp policies cannot have alternatives. Sources may be internal or external and each is explicitly selected; the finalizer validates every response's signature, ESS binding, purpose, chain, imprint, nonce, time and policy. HTTPS endpoint trust and TSA signing trust are independent. An ordered provider reuses the same signature imprint/nonce across attempts and validates a response before selecting it; each source's own policy and external trust chain apply. HTTPS timeouts consume a shared 20-second monotonic budget, with a 10-second maximum per request. Invalid responses, source-preparation failure or transport failure proceed to the next selected source while time remains. Explicit B-B fallback applies after exhaustion; otherwise sealing fails. There is no implicit source. Project Logging marks alternative success; restricted EM outcome/failure logs contain safe attempted/selected source IDs, never endpoints, credentials or response/error text. No remote AIA/CRL/OCSP fetching or revocation status check is performed. Diagnostics observe one response, and expiry inventory includes referenced configured CA chains rather than assuming the remote service keeps using a previously observed signer. See [external TSA administration](ADMIN.md#register-and-test-an-external-tsa).

External TSA tokens may use the RFC 3161 legacy ESSCertID certificate identifier, which hashes the signer certificate with SHA-1. This is accepted only for external timestamp tokens; SHA-1 token content digests and signature algorithms remain rejected. The built-in TSA and document signature verifier retain their stricter defaults.

## Manual built-in project renewal

The CC-only renewal service takes the project lock followed by the shared configuration lock. Its read-only review checks an existing built-in binding, public certificate provenance and validity dates, current issuer, module enablement, retirement, and absence of pending enrollment/provider transition. Review does not decrypt keys and permits an expired project leaf. Its SHA-256 review digest binds the project UUID/provider, current identity/certificate, and configured issuer ID/certificate; mutations recheck this state under both locks.

Confirmation uses the existing built-in replacement issuer, always generating a new key. Issuer/key checks, encrypted identity append, active-binding replacement, and a system-scoped `project_certificate_renewal` audit commit in one transaction. The audit records the actor, PID, UUID, provider, issuer reference, old/new identity IDs and public certificate fingerprints. Failure rolls back the identity/binding/audit together. Integer serial allocation follows the normal PHP 8.2/8.3 reservation rules. No private material is returned through AJAX.

The old project identity/key remains in append-only history; the existing expiry inventory follows the latest binding. No old PDFs are rewritten, and an in-flight seal may use the identity it acquired before renewal. Renewal preserves provider timestamp policy and does not extend issuer validity or rotate the root/TSA. See [the CC procedure](ADMIN.md#renew-a-built-in-project-certificate).
