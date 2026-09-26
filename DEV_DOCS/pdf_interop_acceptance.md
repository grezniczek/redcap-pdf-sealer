# Acrobat and DSS acceptance — 2026-09-26

## Scope and outcome

This records the two B-T fixtures from `DEV_DOCS/interop-artifacts/acceptance-20260926-02`, after all-page inline-link normalization and preservation of xref streams. On 2026-09-26 the user confirmed that Acrobat reports **“Document has not been modified since it was certified”** for both. This closes the earlier multipage modification warning and the merged/object-stream file's empty Signature Panel.

The supplied DSS v6.5 Detailed Reports and Diagnostic Data identify both signatures as **PAdES-BASELINE-T**. For both files:

- Signature structural validation is valid; PDF format checking passes, including ByteRange consistency, signature dictionary consistency and DocMDP checks.
- The document message digest is intact and the document signature is cryptographically valid.
- The embedded signature timestamp has an intact message imprint and a cryptographically valid TSA signature. Signature acceptance and algorithm checks pass for the signature and timestamp.
- DSS resolves both the project signer → root and TSA → root chains. The included disposable root has `Trusted=false`.
- The overall signature and timestamp validation results are **INDETERMINATE / NO_CERTIFICATE_CHAIN_FOUND**. The Detailed Reports explicitly attribute this to the absence of a trust anchor. These are not overall trusted-validation passes.

No revocation evidence is present. No LT/LTA or qualified-signature result is claimed. Other bundle fixtures have local verification only; this result does not extend manual acceptance to them or to untested REDCap merge workflows.

## Artifact correlation

DSS names the uploads `consent-multipage-BT (1).pdf` and `merged-object-streams-BT (1).pdf`. The reports were correlated to the bundle by their signature field names, ByteRanges, digests of covered bytes, CMS signature values, root fingerprint and timestamp times. The DSS `OriginalDocuments/SignerData` digest matches the original unsigned revision's bytes, rather than the entire sealed file. The timestamp message imprints match SHA-256 of the signature values. The sealed PDFs' full SHA-256 values below were independently checked against the original bundle manifest.

| Fixture | Embedded timestamp (UTC) | DSS validation time (UTC) | PDF SHA-256 |
| --- | --- | --- | --- |
| `consent-multipage-BT.pdf` | 2026-09-26T17:20:55+00:00 | 2026-09-26T19:00:36Z | `af98be24b7f21817187dc52b48efae5e8c7931d427e64a251382b220aea8e025` |
| `merged-object-streams-BT.pdf` | 2026-09-26T17:20:56+00:00 | 2026-09-26T19:07:09Z | `383eea28e3d45a7e982c9798695a4dc7017545ae1f613f59213f55bcd4b649d5` |

Disposable root SHA-256: `81194a6299bb700ed398dddd50930ea224cf1919a1a283f770cd29642341fbfd`.

## Preserved reports

The four user-supplied reports were copied without alteration to the bundle's Git-ignored `reports/` directory, using the normalized filenames below. Original downloads remain unchanged. Their hashes and the individual manual outcomes are also recorded in the bundle manifest. This document preserves the conclusions and artifact identifiers in Git without committing generated PDFs or reports.

| Report | SHA-256 |
| --- | --- |
| `consent-multipage-BT-diagnostic.xml` | `e2035fc17d9bb28d218f009876cdeb49660ad52b37f6c2082d97db64d1a14b60` |
| `consent-multipage-BT-detailed-report.pdf` | `6ca1c48fa13d8e3b8d0d287cdf50067600550269fe3417c995ce5ff51a4e5d39` |
| `merged-object-streams-BT-diagnostic.xml` | `79341ee8ff9c624242259ebec48fcd94f43640db35179d171682353ff3848b26` |
| `merged-object-streams-BT-detailed-report.pdf` | `d36a17639f11c9a720b42392babab5a31a15ce70c1455e3115a41e042b689b03` |

## Follow-up

The two reported compatibility failures are resolved for these fixtures. Remaining external checks can cover the B-B counterparts and unrotated merged attachment if broader manual coverage is needed. A full trusted DSS result would require a validation environment configured with the intended private trust anchor; the public demo result above provides evidence of the tested structure and cryptography, but does not establish trust or revocation status.
