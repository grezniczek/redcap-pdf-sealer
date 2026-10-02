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
| PAdES B-T | Successful internal or configured external timestamping | B-B evidence plus an embedded signature timestamp |

The project signer and root certificate are embedded in the document signature. For B-T, the timestamp token includes the TSA signer certificate; an external service may omit intermediate or root certificates. External responses are validated against the separately configured TSA CA chain before embedding. A PDF viewer may expose only some of these details in its interface.

PAdES B-LT and B-LTA are not implemented. No revocation evidence or archival timestamp renewal is added. Selected synthetic B-B/B-T outputs have passed Acrobat's no-modification check and DSS structure/profile/cryptographic checks. Those results establish tested interoperability for those files, not universal acceptance or automatically trusted validation of every output.

## How the timestamp works

The internal TSA issues an RFC 3161 timestamp over a SHA-256 digest of the document signature value. The token binds that imprint to a generation time, policy, serial number, and TSA signature. The sealing path verifies the response before embedding it as an unsigned CMS signature-timestamp attribute. Internal requests and responses are exchanged in-process. An external source uses a bounded HTTPS request with independent token-signing CA trust. The module does not expose a general public timestamp service. External policy OIDs are optional constraints: if provided they are requested and enforced; otherwise the trusted service chooses its policy. Fractional timestamp seconds are preserved in the embedded token, while log metadata uses whole Unix seconds.

External tokens may carry a legacy SHA-1 ESSCertID to identify their signer certificate; [RFC 5816](https://www.rfc-editor.org/rfc/rfc5816.html) permits this form. PDF Sealer checks that identifier against the certificate and configured CA chain. The timestamp message digest and signature still require stronger algorithms; the document signature and built-in TSA keep their strict defaults.

A verifier that trusts and validates the TSA can use this evidence to establish that the signature existed at the timestamp time. A timestamp does not by itself supply all the certificate, revocation, trust, and archival evidence needed for indefinite future validation. It also depends on the TSA's time source and key protection.

Consequently, an embedded timestamp can coexist with a viewer's **“not LTV enabled”** or certificate-expiration warning. B-T is not the same as long-term validation. The built-in TSA uses this installation's root; an external TSA may have an unrelated chain. Recipients need a separate appropriate trust decision for the timestamp chain.

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
| Revocation could not be checked | The verifier lacks satisfactory revocation evidence. Newly issued built-in project/TSA certificates point to the public CRL; older certificates lack that URL. Network reachability, viewer settings, trust, and the external CA/TSA matter. OCSP is not provided. |
| Signature includes an embedded timestamp | A timestamp is present. Its trust and cryptographic validity still need verification. |
| Not LTV enabled / validity ends at a certificate date | The viewer has not established long-term validation evidence. B-T alone does not promise indefinite validation. |
| Document changed or signature invalid | Investigate integrity and validation details. This is not explained merely by an untrusted root. |

DSS reported the expected Baseline B/T profiles and valid structure/cryptography for the selected acceptance fixtures. Its overall `INDETERMINATE/NO_CERTIFICATE_CHAIN_FOUND` result reflected the untrusted disposable root; signer-to-root and TSA-to-root certificate material was present. Do not generalize that explanation to an arbitrary file without inspecting its diagnostic data.

For certificate distribution, use the installation's public root page and an independently verified fingerprint. For a failed seal or unexpected viewer result, retain the original saved/downloaded PDF and give your administrator the project Logging reference when available. Operational troubleshooting is in the [administrator guide](ADMIN.md).

## TSA replacement and revocation

Routine TSA replacement does not revoke the previous certificate or alter existing PDFs. If the built-in TSA is revoked as **superseded**, RFC 3161 allows trust in tokens issued before the revocation time to remain, subject to other validation requirements. If revoked for **key compromise**, all tokens signed with that key can no longer be trusted, even though their bytes and cryptographic signatures remain intact. A fresh TSA restores timestamping for new seals; it cannot repair earlier compromised evidence. Viewer CRL caching can delay recognition. See [the PKI reference](pki.md#built-in-tsa-revocation) and [RFC 3161 §4](https://www.rfc-editor.org/rfc/rfc3161.html#section-4).
