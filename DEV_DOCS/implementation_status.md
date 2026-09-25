# PDF Sealer implementation status

The repository is progressing through `PDF_Sealer_EM_Implementation_Plan.md` in bounded slices. The sole declared `seal` operation still returns unchanged; no cryptographic PDF seal is active in REDCap yet.

## Milestone 1: standalone RFC 3161 responder

`src/Timestamp/InternalTsaService.php` accepts DER `TimeStampReq` and returns a signed DER `TimeStampResp` for the v1 SHA-256 profile. It uses Tecnick's public ASN.1 codec, OIDs, certificate parser, request client, and CMS/token verifier. `tc-lib-pdf-sign` 2.0.4 has no public encapsulated CMS builder, so this module constructs only the `TSTInfo` CMS `SignedData` envelope and its required signed attributes. No OpenSSL CLI is invoked by production code.

`php tests/timestamp_spike.php` exercises request acceptance and rejection, imprint/policy/nonce/time/serial handling, a Tecnick round trip, and independent `openssl ts -verify` validation. This satisfies the plan's go/no-go gate for proceeding to PKI and PDF work.

## Milestone 2: PKI foundation in progress

`src/Pki/CertificateIssuer.php` creates distinct RSA 3072 keys and SHA-256 certificates for a self-signed root, a dedicated TSA, and a project UUID identity. In REDCap, construct it with `new CertificateIssuer([$module->framework, 'createTempFile'])`; the Framework supplies and tracks the temporary OpenSSL configuration file. The root, TSA, and project certificate profiles follow the plan. `GeneratedIdentity` is transient and prevents serialization of private key PEM. `SecretProtector` wraps REDCap's global encryption/decryption functions with a version marker and rejects failures.

`php tests/pki_primitives.php` verifies certificate profiles, distinct keys, the root chain, invalid-root rejection, and an RFC 3161 response signed by the issued TSA. Both Tecnick and `openssl ts -verify` accept that response.

`IdentityRepository` now appends encrypted identity records to system-scoped EM logs and keeps active root/TSA IDs in system settings. `PkiHealthService` reports `UNINITIALIZED`, `READY`, `DEGRADED`, or `BROKEN` without creating replacement identities. A missing active root pointer after a root record exists is `BROKEN`; an unusable TSA leaves the signing root in `DEGRADED` state. `php tests/pki_storage.php` checks scope, encrypted storage, pointer integrity, key mismatches, and these health transitions with a Framework test double. No live database records were created by this slice.

Lazy project binding/issuance, concurrency locks, alarm persistence and throttling remain in Milestone 2. The encryption wrapper and Framework pseudo-query behavior have not yet been exercised inside a live REDCap request. The module hook does not yet instantiate these services.
