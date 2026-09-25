# REDCap PDF Sealer External Module
## Concrete Implementation Plan for Codex

**Status:** Implementation-ready design  
**Target:** Reference / proof-of-principle REDCap External Module producing genuine cryptographically sealed PDFs  
**Language:** PHP 8.2+  
**Primary standards target:** PAdES-B-T with PAdES-B-B fallback  
**Primary cryptographic stack:** `ext-openssl` + Tecnick PDF signing/parser libraries  
**Primary REDCap integration:** `redcap_pdf_finalize`

---

# 1. Purpose

Implement a REDCap External Module ("PDF Sealer") that applies a genuine cryptographic electronic seal to REDCap-generated PDFs, initially focusing on `econsent` PDFs.

The implementation must demonstrate a complete, standards-based chain:

```text
REDCap-generated PDF
        ↓
append-only PDF signature revision
        ↓
project-specific signing certificate
        ↓
PAdES signature
        ↓
RFC 3161 timestamp from instance TSA
        ↓
PAdES-B-T
        ↓
exact sealed bytes returned to REDCap
```

If timestamping fails but PDF signing remains possible, the module should fall back to PAdES-B-B.

If sealing itself fails, the finalizer must return failure and REDCap must retain/store the previously valid PDF according to the transactional `redcap_pdf_finalize` contract.

This is explicitly a **reference implementation / proof of principle**, not yet a complete production PKI product.

---

# 2. Guiding principles

1. **Do not reconstruct the REDCap PDF.**
   - The incoming PDF bytes are immutable revision 0.
   - Sealing is performed by appending a new incremental PDF revision.

2. **Use established cryptographic/PAdES components wherever possible.**
   - Do not implement generic ASN.1, CMS, CAdES, or RFC 3161 stacks from scratch.
   - Use Tecnick components for parsing and signing.
   - Implement only the missing adapter/responder logic.

3. **Keep PKI system-scoped.**
   - All PKI material and project-identity bindings are stored in EM system context (`project_id = NULL`).
   - No signing identities are stored as project settings.
   - Project copying/exporting must not duplicate signing keys or project sealing identities.

4. **Keep historical identities immutable.**
   - Renewal creates a new identity/version.
   - Existing identities are never overwritten.
   - Historical public certificates remain available for validation.

5. **Fail loudly on broken PKI.**
   - A corrupted/decryption-failed PKI state must never cause automatic creation of a replacement root.
   - Administrators must be notified, throttled to at most one email per alarm condition per hour.

6. **Favor interoperability over algorithm novelty.**
   - RSA/SHA-256 first.
   - No need to maximize cryptographic feature breadth in v1.

---

# 3. Dependencies and minimum environment

## 3.1 PHP

Minimum supported PHP version for this EM:

```text
PHP >= 8.2
```

REDCap itself may still support PHP 8.1, but this EM intentionally does not.

## 3.2 Required extensions

At minimum:

```text
ext-openssl
ext-hash
ext-pcre
```

plus ordinary REDCap/PHP requirements.

## 3.3 Composer dependencies

Primary libraries:

```text
tecnickcom/tc-lib-pdf-sign
tecnickcom/tc-lib-pdf-parser
```

Do not treat `tc-lib-pdf` as the document owner or PDF reconstruction engine.

The design is:

```text
arbitrary REDCap PDF bytes
        ↓
tc-lib-pdf-parser
        ↓
EM-owned incremental revision builder
        ↓
tc-lib-pdf-sign
        ↓
sealed PDF
```

---

# 4. REDCap hook integration

The EM declares one initial operation:

```json
{
  "pdf-finalize": [
    {
      "id": "seal",
      "purpose": "Apply cryptographic PDF seal",
      "document_types": ["econsent"],
      "is_terminal": true
    }
  ]
}
```

The operation must:

- accept the Framework-created working PDF;
- seal that working copy;
- return `modified + terminal=true` on successful B-T or B-B sealing;
- return `failed` on unrecoverable sealing failure;
- never modify the original/current previously accepted PDF outside the Framework working-copy contract.

Expected outcomes:

| Situation | Result |
|---|---|
| PAdES-B-T succeeds | `modified`, `terminal=true` |
| TSA fails, B-B fallback succeeds | `modified`, `terminal=true` |
| Signing fails | `failed`, `terminal=false` |
| Broken PKI | `failed`, `terminal=false` |
| Unsupported input PDF | `failed`, `terminal=false` |

The module must not attempt its own "restore original PDF" logic. The Framework already owns transactional working-copy behavior.

---

# 5. High-level internal architecture

Suggested namespaces/classes are illustrative rather than mandatory.

```text
PdfSealerExternalModule
│
├── Pdf/
│   ├── ExistingPdf
│   ├── PdfStructureInspector
│   ├── IncrementalRevisionWriter
│   ├── PdfSealBuilder
│   └── PreparedPdfSeal
│
├── Pki/
│   ├── PkiService
│   ├── RootCaService
│   ├── ProjectIdentityService
│   ├── TsaIdentityService
│   ├── CertificateProfile
│   ├── IdentityRepository
│   └── SecretProtector
│
├── Timestamp/
│   ├── TimestampProvider
│   ├── InternalTimestampProvider
│   ├── NullTimestampProvider
│   ├── Rfc3161RequestParser
│   ├── Rfc3161TokenBuilder
│   └── InternalTsaService
│
├── Storage/
│   ├── SystemSettingsRepository
│   ├── LogObjectRepository
│   ├── SealEventRepository
│   └── AlarmRepository
│
├── Alerts/
│   └── AdminAlarmService
│
└── Trust/
    └── PublicTrustPage
```

Keep boundaries explicit so future external TSA, imported identities, HSM-backed signers, or alternate PDF backends can be added without redesigning the REDCap hook integration.

---

# 6. Milestone 1 — Standalone RFC 3161 responder spike

## 6.1 Objective

Resolve the primary remaining technical uncertainty before integrating with PDFs or REDCap:

```text
TimeStampReq
    ↓
our PHP code
    ↓
TimeStampResp
```

The responder must generate a real RFC 3161 response that is accepted and independently validated.

## 6.2 Why this milestone comes first

The PDF side has a clear implementation path:

- parser exists;
- PAdES/CMS signing primitives exist;
- incremental revision semantics are understood.

The internal TSA is the only component for which the selected library appears to provide the **consumer/client side**, but not a complete server/responder.

Therefore isolate and prove this first.

## 6.3 Deliverable

A framework-independent PHP component:

```php
final class InternalTsaService
{
    public function respond(
        string $timestampRequestDer,
        TsaIdentity $identity,
        int $now
    ): string;
}
```

Input:

```text
DER-encoded RFC 3161 TimeStampReq
```

Output:

```text
DER-encoded RFC 3161 TimeStampResp
```

No REDCap code.
No PDF code.
No network transport.

## 6.4 Supported request profile for v1

Initially support:

```text
version              = 1
messageImprint       = SHA-256 only
reqPolicy            = absent or the selected TSA policy OID
nonce                = optional
certReq              = supported
extensions           = reject unsupported extensions
```

Do not implement broad algorithm support during this spike.

## 6.5 Generated `TSTInfo`

Construct:

```text
TSTInfo
    version          = 1
    policy           = built-in PDF Sealer TSA Policy v1 OID or explicit override
    messageImprint   = exact request imprint
    serialNumber     = cryptographically random positive 128-bit integer
    genTime          = UTC derived from REDCap/PHP request time
    nonce            = exact request nonce when supplied
```

### Time handling

When integrated into REDCap, use REDCap's request-stable `NOW` value as the source for `genTime`.

For the standalone component, inject `$now`.

Use second-level timestamp representation.

Do **not** initially include the optional RFC 3161 `accuracy` element. `NOW` gives stable second-resolution application time but does not by itself prove ±1 second traceability to UTC.

## 6.6 Timestamp serial generation

Do not use a database-backed monotonically increasing counter.

Generate random positive 128-bit serials.

Reason:

- RFC 3161 requires uniqueness, not monotonicity.
- database backup restore must not cause reuse of previously issued serials under the same TSA certificate;
- random serials remain safe across restores and even accidental concurrent clones;
- this avoids locking/counter complexity entirely.

Use the same general strategy for certificate serials.

## 6.7 TSA CMS token

The returned timestamp token must be encapsulated CMS:

```text
ContentInfo
  SignedData
    encapContentInfo
      eContentType = id-ct-TSTInfo
      eContent     = DER(TSTInfo)
```

The TSA signer certificate must be included according to RFC 3161 expectations.

The token must use the dedicated TSA key.

The signing certificate must include:

```text
basicConstraints = critical, CA:false
keyUsage         = critical, digitalSignature
extendedKeyUsage = critical, timeStamping
```

and no other EKU purpose.

## 6.8 Tecnick reuse investigation

Before implementing generic ASN.1/CMS code, inspect the public APIs in `tc-lib-pdf-sign` and reuse:

- ASN.1 DER encoding helpers;
- CMS OIDs;
- certificate parsing;
- signer-info construction;
- signing-certificate-v2 handling;
- signature generation;
- timestamp response/client parsing;
- timestamp token validation.

Expected missing functionality:

- RFC 3161 request-to-response orchestration;
- `TSTInfo` construction;
- encapsulated CMS `SignedData` builder if current CMS builder only supports detached content;
- outer `TimeStampResp` assembly.

If a small extension of existing Tecnick classes is possible, prefer composition/adaptation over copying substantial library internals.

## 6.9 Milestone 1 tests

### Unit tests

At minimum:

- valid SHA-256 request accepted;
- response status is granted;
- returned message imprint exactly matches request;
- returned nonce matches supplied nonce;
- absent nonce remains absent;
- configured/default policy behavior correct;
- unsupported digest rejected;
- unsupported requested policy rejected;
- malformed request rejected;
- unsupported extension rejected;
- random serial is positive;
- serial differs across many generated tokens;
- `genTime` corresponds to injected `$now`;
- TSA certificate included when requested/required.

### Round-trip test

Use Tecnick timestamp client code:

```text
tc-lib-pdf-sign builds TimeStampReq
        ↓
InternalTsaService
        ↓
TimeStampResp
        ↓
tc-lib-pdf-sign parses/verifies response
```

This round-trip must succeed.

### Independent validation

Validate generated responses/tokens with at least:

```text
OpenSSL
```

and preferably another independent RFC 3161/CMS-aware implementation where practical.

## 6.10 Milestone 1 acceptance gate

Proceed to the PDF sealing layer only when:

1. the module can produce a valid RFC 3161 response entirely in PHP;
2. the response is accepted by Tecnick's existing timestamp verification/client machinery;
3. an independent implementation validates the returned timestamp token;
4. no custom OpenSSL CLI invocation is required.

If these conditions fail, stop and reassess the TSA strategy before implementing the rest.

---

# 7. PKI model

## 7.1 Trust hierarchy

```text
Instance Root CA
    ├── Project sealing certificate A
    ├── Project sealing certificate B
    ├── Project sealing certificate C
    └── Instance TSA certificate
```

The following keys are always distinct:

- root CA key;
- each project sealing key;
- TSA key.

## 7.2 Initial algorithms

Use intentionally conservative/interoperable algorithms:

```text
Root CA key:       RSA 3072
Project seal key:  RSA 3072
TSA key:           RSA 3072

Digest:            SHA-256
Signature:         RSASSA-PKCS1-v1_5 + SHA-256
```

Do not introduce ECDSA or RSA-PSS in the first implementation.

## 7.3 Root certificate profile

Suggested initial profile:

```text
basicConstraints = critical, CA:true
keyUsage         = critical, keyCertSign, cRLSign
subjectKeyIdentifier
authorityKeyIdentifier
```

Self-signed.

## 7.4 Project sealing certificate profile

Initial experimental profile:

```text
basicConstraints = critical, CA:false
keyUsage         = critical, digitalSignature
extendedKeyUsage = non-critical, id-kp-documentSigning
```

Use document-signing EKU OID:

```text
1.3.6.1.5.5.7.3.36
```

This profile must be interoperability-tested.

If important validators behave poorly, test and revise the EKU profile rather than treating it as immutable architecture.

## 7.5 TSA certificate profile

```text
basicConstraints = critical, CA:false
keyUsage         = critical, digitalSignature
extendedKeyUsage = critical, timeStamping
```

No other EKU.

## 7.6 Certificate subject naming

At least organization (`O`) must be configurable in Control Center before PKI initialization.

Suggested subjects:

### Root

```text
O  = <configured organization>
CN = REDCap PDF Seal Root CA
```

### Project certificate

```text
O  = <configured organization>
OU = REDCap PDF Seal
CN = REDCap Project <stable project UUID>
```

### TSA

```text
O  = <configured organization>
OU = REDCap PDF Seal
CN = REDCap Instance Timestamp Authority
```

Never put the REDCap project title into a certificate.

## 7.7 Organization setting lifecycle

`O` is configurable before CA initialization.

Once a root exists:

- changing the UI setting must not mutate the certificate;
- v1 may simply treat the setting as locked while the active root exists;
- future root rollover can allow a new value for a newly created root.

## 7.8 Project pseudonymous identity

Each REDCap project first used by the sealer receives a generated stable UUID.

Maintain:

```text
REDCap pid
    ↕
project seal UUID
    ↕
one or more certificate identity versions
```

The UUID is stored in system context, not project context.

---

# 8. Lazy project certificate issuance

Project signing identities are issued on first use.

Flow:

```text
seal requested for pid 417
        ↓
lookup project UUID/binding
        ↓
create UUID if first use
        ↓
lookup active project sealing identity
        ↓
none exists
        ↓
generate RSA key
        ↓
issue certificate from active root
        ↓
encrypt and persist private key
        ↓
persist certificate metadata
        ↓
seal PDF
```

The first seal may therefore take a few seconds longer; this is acceptable.

Concurrency must be handled so two simultaneous first seals do not create competing active identities.

Use locking/atomic lookup around identity creation.

---

# 9. Certificate lifetimes and versioning

Initial defaults:

```text
Root CA       10 years
Project cert   2 years
TSA cert       2 years
```

Exact periods may later become configurable.

The important invariant is:

> Identities are immutable historical objects.

Example:

```text
Project UUID
    ├── identity v1 [retired]
    ├── identity v2 [retired]
    └── identity v3 [active]
```

Renewal never overwrites a previous identity.

The same principle applies to TSA identities and future root rollover.

No automatic root rotation is required in v1, but storage must support multiple historical roots.

---

# 10. PKI storage

## 10.1 Storage scope

All PKI-related records must use:

```text
project_id = NULL
```

including project-specific sealing identities.

This is intentional.

Project PKI identities are instance-level security objects associated with a project; they are not project settings.

Consequences:

- users cannot copy/export private keys with projects;
- project duplication does not duplicate signing identities;
- a copied project receives a new project seal identity on first use;
- normal project export cannot expose PKI material.

## 10.2 REDCap storage mechanisms

Use only established EM storage:

```text
redcap_external_module_settings
redcap_external_module_logs
```

No custom tables in the initial implementation.

## 10.3 Division of responsibilities

### System settings

Use for small mutable configuration/pointers:

```text
schema_version
organization

active_root_identity_id
active_tsa_identity_id

timestamp_mode
bb_fallback

tsa_policy_oid

admin_alert_recipients
```

The default TSA policy is PDF Sealer TSA Policy v1, OID
`2.25.186172099785128831488612506224552954430`, derived from UUID
`8c0f7132-9d42-4240-b259-da71e931ca3e`. `tsa_policy_oid` is an optional
override. An unset value must not by itself trigger B-B fallback. The TSA
accepts an absent `reqPolicy` or the selected OID and rejects other values
with RFC 3161 `unacceptedPolicy`.

### EM logs table

Treat `redcap_external_module_logs` as an append-oriented object store for historical/security objects:

```text
pki_identity
project_identity_binding
seal_event
pki_alarm
pki_event
```

All with `project_id = NULL`.

The table may be used more generally in CRUD-like ways in REDCap EMs, but this implementation should prefer append-only historical records.

---

# 11. Private-key protection

## 11.1 Encrypt secrets

Encrypt:

- root CA private key;
- project sealing private keys;
- TSA private key.

Do not encrypt:

- public certificates;
- certificate chains;
- serial numbers;
- fingerprints;
- validity metadata;
- project UUIDs;
- timestamp serials;
- seal event metadata.

## 11.2 REDCap encryption

Use REDCap's existing global encryption/decryption methods through module-local wrappers.

Example abstraction:

```php
final class SecretProtector
{
    public function encrypt(string $plaintext): string;
    public function decrypt(string $ciphertext): string;
}
```

This wrapper allows storage format/versioning and avoids scattering direct REDCap global calls throughout the codebase.

## 11.3 Private-key representation

Store private keys as ordinary unencrypted PEM/PKCS#8 **before** REDCap encryption.

Do not introduce another persistent private-key passphrase layer.

Conceptually:

```text
PKCS#8/PEM private key
        ↓
REDCap encrypt()
        ↓
stored ciphertext
```

Do not do:

```text
password-encrypted PEM
        ↓
store/manage password
        ↓
encrypt password again
```

## 11.4 In-memory handling

Decrypt only immediately before an operation requiring the key.

Release references immediately afterward.

Do not claim guaranteed memory zeroization in PHP.

Never log key material or decrypted PEM.

---

# 12. PKI health states

Represent operational health explicitly:

```text
UNINITIALIZED
READY
DEGRADED
BROKEN
```

## UNINITIALIZED

No root has ever been initialized.

PKI creation is permitted.

## READY

Root and required active identities are structurally valid and keys decrypt/match certificates.

## DEGRADED

Example:

- TSA unavailable/broken;
- project signing remains possible;
- B-B fallback can still produce a valid seal.

## BROKEN

Examples:

- active root reference missing;
- root private key decryption fails;
- private key does not match stored certificate;
- certificate malformed;
- project identity is referenced but unusable;
- instance secret changed/restored incorrectly.

Important:

> BROKEN must never automatically trigger creation of a new root or silent replacement identity.

Sealing fails loudly.

---

# 13. Admin alarm handling

Critical/degraded PKI problems must create persistent alarm records and notify configured administrators.

Requirement:

```text
at most one email per alarm condition per hour
```

Suggested alarm fingerprint:

```text
SHA-256(error_code + relevant_identity_id)
```

Examples:

```text
ROOT_KEY_DECRYPT_FAILED + root-uuid
PROJECT_KEY_MISMATCH + identity-uuid
TSA_KEY_DECRYPT_FAILED + tsa-uuid
```

Before sending:

1. obtain an advisory/application lock for the alarm fingerprint;
2. query most recent successfully mailed alarm record;
3. send only if at least one hour has passed;
4. append a new alarm/log event;
5. release lock.

Concurrent PDF generations must not cause email storms.

Alert recipients are configured in Control Center.

A sensible REDCap administrator address may be suggested/defaulted where possible, but the EM setting is authoritative.

---

# 14. Timestamp provider abstraction

Define:

```php
interface TimestampProvider
{
    public function timestamp(
        string $digestAlgorithm,
        string $digest
    ): TimestampResult;
}
```

Initial implementations:

```text
InternalTimestampProvider
NullTimestampProvider
```

Future:

```text
ExternalRfc3161TimestampProvider
```

The internal provider calls `InternalTsaService` directly.

It must not make an HTTP request back into the same REDCap instance.

The PAdES signing layer must not care whether the token came from:

- internal TSA;
- future external TSA;
- no TSA.

---

# 15. Timestamp fallback policy

Initial default:

```text
internal TSA
        ↓
if success → PAdES-B-T
        ↓
if TSA failure and fallback enabled → PAdES-B-B
        ↓
if signing failure → finalizer failure
```

B-B fallback should be enabled by default for this reference implementation.

Record exact outcome:

```text
pades-b-t
pades-b-b
failed
```

Never report B-T when the RFC 3161 timestamp step did not succeed.

---

# 16. PDF input handling

## 16.1 Core rule

Treat the REDCap PDF bytes as immutable base revision.

Never reconstruct through TCPDF page import.

Required output property:

```php
substr($sealedPdf, 0, strlen($originalPdf)) === $originalPdf
```

for successful incremental sealing.

## 16.2 Parser

Use `tc-lib-pdf-parser` to resolve:

- latest trailer;
- `/Root`;
- catalog;
- page tree;
- AcroForm where present;
- xref tables;
- xref streams;
- `/Prev` chains;
- object streams;
- highest/current object numbers;
- existing signatures where detectable;
- encryption state.

## 16.3 Initial unsupported inputs

Reject with controlled sealing failure:

```text
encrypted PDF
existing certification / DocMDP signature
structurally invalid/unresolvable PDF
```

Do not implement PDF encryption handling in v1.

Ordinary existing AcroForms should be supported where practical.

---

# 17. Incremental PDF signature revision

Create an EM-owned narrow writer.

Suggested class:

```php
final class IncrementalRevisionWriter
{
    public function append(
        string $originalPdf,
        array $objects,
        TrailerUpdate $trailer
    ): string;
}
```

This is **not** a general PDF writer.

It needs to serialize only the PDF COS types required for revised/new dictionaries and objects:

```text
null
boolean
integer
real
name
literal string
hex string
array
dictionary
indirect reference
indirect object
```

No page rendering.
No font handling.
No image handling.
No content-stream generation.

## 17.1 Signature objects

For the initial invisible certification seal, create/revise as required:

```text
catalog
first page
AcroForm
signature field/widget
signature dictionary
```

Use zero-size signature widget rectangle:

```text
/Rect [0 0 0 0]
```

## 17.2 Existing AcroForm

If `/AcroForm` exists:

- preserve existing dictionary semantics;
- append our field to `/Fields`;
- preserve existing fields;
- set appropriate signature flags as needed.

If absent:

- create a new AcroForm dictionary;
- reference it from a revised catalog.

## 17.3 Page annotations

Preserve existing annotations and actions. Before appending the invisible signature widget reference, write any direct `/Link` annotation on the first page as an equivalent indirect object in the signing revision. This preserves the original PDF bytes and clickable link while avoiding an Acrobat certification failure observed when REDCap's inline footer link and the widget share a rewritten page object.

## 17.4 Certification / DocMDP

Use a certification signature with:

```text
DocMDP P=1
```

meaning no document changes are permitted after sealing, except changes allowed by the PDF signature model such as future document timestamps where applicable.

Reuse Tecnick signing/PAdES structures rather than manually inventing the signature-reference semantics where public library components exist.

---

# 18. Object numbering

Do not trust only trailer `/Size`.

Allocate new object numbers using:

```text
max(
    trailer /Size - 1,
    highest actually observed object number
) + 1
```

and increment monotonically for newly created objects.

When revising an existing object:

- keep the same object number;
- preserve appropriate generation semantics.

---

# 19. Cross-reference output

Input PDFs may use:

- classic xref tables;
- xref streams;
- object streams;
- incremental revisions.

The new sealer revision may remain deliberately simple.

Initial writer should emit:

```text
ordinary indirect objects
classic xref table
classic trailer
/Prev = previous startxref
startxref
%%EOF
```

No need to emit xref streams in v1.

---

# 20. PAdES/CMS signing

Use `tc-lib-pdf-sign` for cryptographic/PAdES construction as far as possible.

Target:

```text
PAdES-B-T
```

Fallback:

```text
PAdES-B-B
```

Use:

```text
SubFilter /ETSI.CAdES.detached
SHA-256
RSA 3072
PKCS#1 v1.5
signingCertificateV2
embedded certificate chain
```

The EM-owned layer should own:

```text
where PDF signature objects are placed
ByteRange preparation
placeholder location
incremental revision
```

Tecnick should own as much as possible of:

```text
CMS SignedData
CAdES attributes
signature algorithm encoding
certificate inclusion
RFC 3161 signature timestamp handling
```

---

# 21. Signature placeholder and ByteRange

The final signing flow should be:

```text
parse original PDF
        ↓
construct signature revision with /Contents placeholder
        ↓
write final ByteRange values
        ↓
hash ByteRange-covered bytes
        ↓
create CMS/CAdES signature
        ↓
obtain RFC 3161 signature timestamp if configured
        ↓
embed timestamp into CMS
        ↓
DER encode final CMS
        ↓
hex-encode into reserved /Contents region
        ↓
pad remaining placeholder without moving offsets
```

Once the placeholder is written, perform final CMS injection by known offsets.

Do not parse/rewrite the document again after signature offsets are fixed.

---

# 22. Seal-event persistence

Write a short success or failure entry to the project's standard REDCap Logging page through `REDCap::logEvent` for every seal attempt with a trustworthy project context. Supply the project ID and, when present in the finalization context, the record ID and event ID. The project entry contains only the outcome and a concise profile/fallback note on success, or a generation-ID reference on failure. Do not put certificate metadata, hashes, exception details, or clinical context into the visible description.

Append a system-scoped `seal_event` EM log entry with extended diagnostics only for failed attempts. An invalid or mismatched project context must not be attributed to an arbitrary project Logging page; retain its failure details in the system-scoped EM log.

Suggested failure fields:

```text
event = seal
generation_id
pid
project_uuid
certificate_identity_id
certificate_serial
certificate_sha256
input_sha256
output_sha256
profile = failed
timestamp_source = none
timestamp_serial
timestamp_time
success = 0
error_code
error_message
created_at
```

Avoid persisting REDCap record ID, instrument, or unnecessary clinical context in EM logs. The project Logging entry receives record and event identifiers through the dedicated `REDCap::logEvent` arguments so authorized users can filter it.

Use `generation_id` to correlate a failed project Logging entry with the EM diagnostic and Framework logging.

---

# 23. Minimal Control Center UI

Keep v1 intentionally small.

## Before PKI initialization

Fields/actions:

```text
Organization (required)
Initialize PDF Seal PKI
```

## After initialization

Display:

```text
PKI status
root subject
root fingerprint (SHA-256)
root validity
TSA subject
TSA fingerprint
TSA validity
timestamp mode
B-B fallback status
configured admin alert recipients
```

Actions:

```text
download root certificate (PEM)
download root certificate (DER)
run diagnostic self-test
```

Do not expose private-key export in the initial GUI.

---

# 24. Minimal project-level UI

Only show useful status.

Possible items:

```text
Project seal UUID
project certificate status
certificate subject
certificate SHA-256 fingerprint
certificate validity
last seal result
```

Do not provide private-key controls.

Certificate issuance may remain lazy and occur on first actual seal.

---

# 25. Public trust page

Provide a stable public endpoint containing:

```text
current root certificate
historical root certificates
SHA-256 fingerprints
subjects
validity
PEM/DER downloads
short explanation of trust model
description of instance TSA
```

Do not claim that an embedded/self-signed root is automatically trusted by Acrobat or other software.

The page supports trust distribution; it is not the validation engine.

---

# 26. Verification scope

Do not build a comprehensive PAdES validator in v1.

The module may display:

- certificate metadata;
- hashes;
- seal status recorded at generation;
- basic signature information exposed by dependencies.

Acceptance/interoperability testing must use independent validators.

Primary external validation targets:

```text
Adobe Acrobat
EU DSS validator
pdfsig / Poppler
OpenSSL where applicable
qpdf --check for PDF structure
```

---

# 27. Implementation milestones

## Milestone 1 — Standalone internal RFC 3161 responder

Goal:

```text
TimeStampReq → PHP → TimeStampResp
```

No REDCap/PDF integration.

**Gate:** independently validated token.

---

## Milestone 2 — PKI primitives and storage

Implement:

- root generation;
- TSA identity generation;
- project sealing identity generation;
- certificate profiles;
- REDCap encryption wrappers;
- system-context storage;
- immutable identity records;
- lazy project identity creation;
- PKI health checking;
- alarm persistence/throttling.

Tests:

- key/certificate matching;
- encryption/decryption round trip;
- broken key state detection;
- no silent root regeneration;
- concurrent lazy issuance protection.

---

## Milestone 3 — Existing-PDF structural adapter

Implement:

- parser wrapper;
- input eligibility checks;
- trailer/catalog/page/AcroForm inspection;
- object allocation;
- COS serializer;
- incremental revision writer.

Initially create a benign test revision before adding cryptography.

Acceptance:

```text
output starts with exact original bytes
new revision parses
/Prev points to original startxref
qpdf --check succeeds
```

Test against:

- classic xref PDF;
- xref-stream PDF;
- PDF containing ObjStm;
- PDF with existing AcroForm;
- representative REDCap PDFs.

---

## Milestone 4 — PAdES-B-B sealing

Implement:

- signature field/widget;
- DocMDP P=1;
- `/ByteRange`;
- reserved `/Contents`;
- Tecnick CMS/CAdES integration;
- project certificate chain inclusion;
- final CMS injection.

Acceptance:

- original source preserved byte-for-byte as revision 0;
- Adobe/DSS/pdfsig identify the signature;
- signature is mathematically valid;
- root chain is inspectable;
- trusting root produces expected trust result;
- altering protected bytes invalidates signature.

---

## Milestone 5 — PAdES-B-T integration

Connect:

```text
PdfSealBuilder
    ↓
InternalTimestampProvider
    ↓
InternalTsaService
```

Embed RFC 3161 signature timestamp through Tecnick PAdES/CMS support.

Acceptance:

- resulting PDF validates as B-T;
- timestamp message imprint is correct;
- timestamp certificate is valid for timestamping;
- timestamp serial/time stored in seal event;
- external validators accept token/signature.

---

## Milestone 6 — REDCap `redcap_pdf_finalize` integration

Implement actual EM hook operation.

Flow:

```text
Framework working PDF
        ↓
ensure PKI ready
        ↓
ensure project identity
        ↓
try B-T
        ↓
if TSA failure + fallback → B-B
        ↓
return modified + terminal
```

On signing/PKI failure:

```text
log
alarm when appropriate
return failed
```

Framework retains previous valid PDF.

---

## Milestone 7 — Minimal UI/trust distribution

Implement:

- Control Center organization + initialize action;
- PKI status;
- root/TSA details;
- alert recipient configuration;
- root download;
- public trust page;
- minimal project status page;
- diagnostic self-test.

---

## Milestone 8 — Interoperability and REDCap fixture testing

Run against realistic PDFs generated by REDCap, especially e-Consent.

Include:

- simple survey/e-Consent PDFs;
- PDFs with signature images;
- merged/extended PDFs where REDCap produces them;
- existing AcroForm where applicable;
- multiple PDF structural styles.

Validate independently.

---

# 28. Explicitly deferred features

Not required for this reference implementation:

```text
external RFC 3161 TSA
imported issuing CA
imported project signing identity
HSM / PKCS#11
remote signing
ECDSA
RSA-PSS
CRL
OCSP
PAdES-B-LT
PAdES-B-LTA
automatic archival timestamp renewal
qualified trust services
public trust-list integration
encrypted source PDF support
pre-existing certification-signature support
visible seal page/block
root rollover UI
private-key export UI
full in-EM PAdES validator
retrospective sealing of existing edocs
```

Design interfaces so these can be added later without changing the fundamental hook integration.

---

# 29. Failure semantics

## TSA failure

If project signing identity is healthy:

```text
internal TSA failure
        ↓
persistent warning/alarm
        ↓
hourly-throttled admin email where appropriate
        ↓
PAdES-B-B fallback
        ↓
successful terminal seal
```

provided B-B fallback is enabled.

## Signing identity/root failure

```text
root/project identity unusable
        ↓
BROKEN state
        ↓
persistent critical alarm
        ↓
hourly-throttled admin email
        ↓
return finalizer failure
        ↓
Framework keeps previous PDF
```

Never silently generate a replacement root.

## Unsupported PDF

```text
unsupported input
        ↓
controlled failure event
        ↓
return finalizer failure
        ↓
Framework keeps previous PDF
```

---

# 30. Security invariants

Codex should preserve these throughout implementation:

1. Private keys never leave server-side module code.
2. Private keys never appear in logs.
3. All stored private keys are REDCap-encrypted.
4. PKI records are system-scoped (`project_id = NULL`).
5. Project copy/export never carries a signing identity.
6. Each project uses a distinct signing key.
7. TSA uses a distinct dedicated key.
8. Root key never signs PDFs directly.
9. Existing PDF bytes are never reconstructed or modified during sealing.
10. Seal operation is terminal after success.
11. BROKEN PKI never auto-regenerates.
12. Historical identities are never overwritten.
13. B-T is reported only when the RFC 3161 token succeeds.
14. B-B fallback is explicit and logged.
15. No claim is made that the internal TSA provides independent/qualified time.
16. No claim is made that the self-created root is externally trusted without explicit trust configuration.

---

# 31. Test fixture strategy

Check representative static PDFs into the test suite where licensing permits.

At minimum:

```text
foreign-classic-xref.pdf
foreign-xref-stream.pdf
foreign-object-stream.pdf
foreign-acroform.pdf
redcap-econsent-simple.pdf
redcap-econsent-signatures.pdf
```

The key invariant for every successful incremental-signing test:

```php
self::assertSame(
    $base,
    substr($sealed, 0, strlen($base))
);
```

Structural assertions:

```text
sealed length > original length
at least one additional %%EOF
latest trailer /Prev == original/latest previous startxref
qpdf --check succeeds
```

Cryptographic assertions:

```text
signature validates
certificate chain matches expected root
DocMDP is present with P=1
B-B/B-T profile matches intended result
timestamp token validates
```

---

# 32. Initial diagnostic/self-test

The Control Center diagnostic should eventually exercise the entire stack using a generated in-memory/sample PDF:

```text
PKI readable
    ↓
project/test identity available
    ↓
create B-B seal
    ↓
internal TSA request/response
    ↓
create B-T seal
    ↓
locally verify expected structures
```

Do not modify a real project during this diagnostic.

Report individual subsystem results:

```text
Root CA                  OK
TSA identity             OK
Project/test signer      OK
RFC 3161 responder       OK
PAdES-B-B                OK
PAdES-B-T                OK
Storage encryption       OK
```

---

# 33. Codex implementation guidance

Before writing implementation code for each milestone:

1. inspect the actual current Tecnick public APIs;
2. avoid coding against protected/internal implementation details unless absolutely necessary;
3. prefer composition over subclassing library internals;
4. verify assumptions with small isolated tests;
5. do not broaden scope merely because the library exposes additional functionality;
6. document every deliberate deviation from this plan.

Especially for Milestone 1:

> First determine exactly which public ASN.1/CMS/Timestamp primitives in `tc-lib-pdf-sign` can be reused for building an RFC 3161 response. Only implement the smallest missing layer.

Do not start PDF integration until Milestone 1's responder has passed independent validation.

---

# 34. Milestone 1 first task for Codex

A suitable first Codex task is:

> Inspect the installed/current `tecnickcom/tc-lib-pdf-sign` source and public API specifically for RFC 3161 response generation. Identify reusable classes for ASN.1, OIDs, CMS signing, certificate handling, `TSTInfo`, `TimeStampReq`, `TimeStampResp`, and timestamp-token verification. Compare the findings against this plan. If the plan assumes unavailable APIs, revise only the Milestone 1 design accordingly. Then implement the smallest standalone PHP prototype that accepts a DER `TimeStampReq` and returns a standards-conformant DER `TimeStampResp` signed with a dedicated test TSA certificate. Add automated round-trip tests and independent OpenSSL verification where practical. Do not begin PDF or REDCap integration yet.

This milestone is intentionally a go/no-go technical spike.

---

# 35. Reference implementation completion criteria

The first reference implementation is successful when all of the following are true:

1. PHP 8.2+ installation works without custom native extensions.
2. The EM can initialize its own instance root CA.
3. The EM can create a dedicated TSA identity.
4. The EM can lazily issue a unique project sealing identity.
5. Private keys are stored encrypted in system context.
6. The internal TSA produces valid RFC 3161 responses.
7. Arbitrary supported REDCap PDFs remain byte-for-byte intact as revision 0.
8. Sealing is applied only through an appended incremental revision.
9. PAdES-B-B validates independently.
10. PAdES-B-T with the internal TSA validates independently.
11. The certificate chain terminates at the downloadable instance root.
12. DocMDP P=1 certification is present.
13. Post-seal modification invalidates the signature.
14. TSA failure can cleanly fall back to B-B.
15. Broken signing PKI causes finalizer failure, not silent regeneration.
16. Finalizer failure leaves REDCap's prior valid PDF available for storage.
17. Critical PKI alarms generate persistent logs and throttled admin email.
18. Project copying/exporting does not copy signing identities.
19. Historical identities are retained rather than overwritten.
20. The result validates with at least Adobe Acrobat, EU DSS, and one additional independent PDF validator.

---

# 36. Architectural summary

The reference implementation should end up with four cleanly separated layers:

```text
REDCap integration
    redcap_pdf_finalize
            ↓
PDF incremental revision layer
    parse foreign PDF
    append signature revision
            ↓
PAdES / CMS layer
    tc-lib-pdf-sign
            ↓
PKI / timestamp layer
    REDCap-encrypted keys
    internal RFC 3161 TSA
```

The crucial design principle is:

> REDCap owns the original PDF. The PDF Sealer never reconstructs it; it only appends a standards-conformant cryptographic certification revision.

And the first implementation milestone is deliberately narrower:

> Prove that the module can act as a standards-conformant RFC 3161 timestamp responder in pure PHP before building any PDF or REDCap integration around it.
