# Sealing, timestamps, and validation

See the [project guide](PROJECT.md) for checking an outcome and the [administrator guide](ADMIN.md) for settings. The [PKI reference](pki.md) explains the certificate hierarchy.

## What the seal protects

PDF Sealer appends a certification signature to the PDF supplied by REDCap's finalization pipeline. The signature covers the complete resulting document apart from the reserved signature-value container. It uses a project certificate, SHA-256, and a detached CAdES signature with the PDF subfilter `ETSI.CAdES.detached`.

The certification permission is DocMDP P=1: changes after certification are not permitted. The signature field is invisible, so the document's appearance remains unchanged. The original PDF bytes remain as the complete prefix of the sealed file; the module appends a signing revision. Clickable links are preserved, with inline Link annotations represented as indirect objects on every page to avoid viewer compatibility problems. The latest input cross-reference format (table or stream) is retained.

Encrypted PDFs, already-certified PDFs, PDFs with existing signed fields, and unsupported or malformed structures are rejected. The sealer is not a general PDF repair tool. Failed operations leave the preceding PDF available to REDCap.

## Profiles

| Profile | Produced by | Included evidence |
| --- | --- | --- |
| PAdES B-B | Timestamp mode disabled, or permitted fallback | Document signature and signer certificate material |
| PAdES B-T | Successful internal timestamping | B-B evidence plus an embedded signature timestamp |

The project signer and root certificate are embedded in the document signature. For B-T, the timestamp token includes the TSA certificate and root chain. A PDF viewer may expose only some of these details in its interface.

PAdES B-LT and B-LTA are not implemented. No revocation evidence or archival timestamp renewal is added. Selected synthetic B-B/B-T outputs have passed Acrobat's no-modification check and DSS structure/profile/cryptographic checks. Those results establish tested interoperability for those files, not universal acceptance or automatically trusted validation of every output.

## How the timestamp works

The internal TSA issues an RFC 3161 timestamp over a SHA-256 digest of the document signature value. The token binds that imprint to a generation time, policy, serial number, and TSA signature. The sealing path verifies the response before embedding it as an unsigned CMS signature-timestamp attribute. Requests and responses are exchanged in-process; the module does not expose a general public timestamp service.

A verifier that trusts and validates the TSA can use this evidence to establish that the signature existed at the timestamp time. A timestamp does not by itself supply all the certificate, revocation, trust, and archival evidence needed for indefinite future validation. It also depends on the TSA's time source and key protection.

Consequently, an embedded timestamp can coexist with a viewer's **“not LTV enabled”** or certificate-expiration warning. B-T is not the same as long-term validation. This module's TSA is issued by the same installation root as the project signer, so recipients also need an appropriate trust decision for that chain.

The PDF's displayed signing date is separate from the embedded cryptographic timestamp. Viewer wording and date displays vary. When investigating a disagreement, inspect the original signature and token rather than relying on a summary label alone.

## Timestamp policy

The default **PDF Sealer TSA Policy v1** OID is:

```text
2.25.186172099785128831488612506224552954430
```

It is derived from UUID `8c0f7132-9d42-4240-b259-da71e931ca3e` under the `2.25` arc. It identifies this built-in policy; it does not claim external accreditation. An unset policy does not cause B-B fallback.

The internal timestamp source stores its policy OID; there is currently no UI control for overriding it. Normal administration should use the built-in policy. If a timestamp request includes `reqPolicy`, the responder accepts only the active policy and rejects unsupported values with `unacceptedPolicy`. Normal module requests omit this optional field and validate the response's policy.

## Interpreting viewer results

| Finding | Interpretation |
| --- | --- |
| Document has not been modified since certification | The viewer accepts the document integrity/certification check. Inspect trust and timestamp findings separately. |
| Unknown or untrusted issuer | The verifier has not established trust in this installation's root. The embedded chain alone is insufficient. |
| Revocation could not be checked | The verifier lacks satisfactory revocation evidence. PDF Sealer does not publish CRL/OCSP information. |
| Signature includes an embedded timestamp | A timestamp is present. Its trust and cryptographic validity still need verification. |
| Not LTV enabled / validity ends at a certificate date | The viewer has not established long-term validation evidence. B-T alone does not promise indefinite validation. |
| Document changed or signature invalid | Investigate integrity and validation details. This is not explained merely by an untrusted root. |

DSS reported the expected Baseline B/T profiles and valid structure/cryptography for the selected acceptance fixtures. Its overall `INDETERMINATE/NO_CERTIFICATE_CHAIN_FOUND` result reflected the untrusted disposable root; signer-to-root and TSA-to-root certificate material was present. Do not generalize that explanation to an arbitrary file without inspecting its diagnostic data.

For certificate distribution, use the installation's public root page and an independently verified fingerprint. For a failed seal or unexpected viewer result, retain the original saved/downloaded PDF and give your administrator the project Logging reference when available. Operational troubleshooting is in the [administrator guide](ADMIN.md).
