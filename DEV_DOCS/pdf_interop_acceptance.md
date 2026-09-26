# Acrobat and DSS acceptance — 2026-09-26

## Scope and outcome

This completes the selected six-fixture interoperability round from `DEV_DOCS/interop-artifacts/acceptance-20260926-02`: multipage consent, merged/object-stream output and an unrotated merged attachment, each in B-B and B-T. All use the corrections for inline links on every page and preservation of xref streams. The user confirmed that Acrobat reports **“Document has not been modified since it was certified”** for the first two B-T files and **“not modified”** for all four follow-up files. This closes the earlier multipage modification warning and the merged/object-stream file's empty Signature Panel.

The supplied DSS v6.5 Detailed Reports and Diagnostic Data identify the three B-B signatures as **PAdES-BASELINE-B** with no timestamp, and the three B-T signatures as **PAdES-BASELINE-T** with one signature timestamp each. Across all six files:

- Signature structural validation is valid; PDF format checking passes, including ByteRange consistency, signature dictionary consistency and DocMDP checks.
- The document message digest is intact and the document signature is cryptographically valid. Signature acceptance and algorithm checks pass.
- For each B-T file, the timestamp message imprint is intact and the TSA signature is cryptographically valid; timestamp acceptance and algorithm checks pass.
- DSS resolves the project signer → root chain and, where applicable, TSA → root. The included disposable root has `Trusted=false`.
- Overall signature validation, and timestamp validation where applicable, is **INDETERMINATE / NO_CERTIFICATE_CHAIN_FOUND**. The Detailed Reports explicitly attribute this to the absence of a trust anchor. These are not overall trusted-validation passes.

No new structural or cryptographic failures were found in the follow-up reports. No revocation evidence is present. No LT/LTA or qualified-signature result is claimed.

The user's confirmations establish Acrobat's no-modification result; page appearance/orientation, manual footer-link clicks and Acrobat's timestamp presentation were not separately reported in this round. Local rendering/text/link preservation checks already passed. Single-page and no-footer control fixtures retain local verification only, and untested REDCap merge workflows remain outside this acceptance scope.

## Artifact correlation

The initial B-T upload names in DSS include a browser-added ` (1)` suffix; the four follow-up names match the bundle filenames. Reports were correlated to their PDFs by signature field names, ByteRanges, digests of covered bytes, CMS signature values, root fingerprint and, where present, timestamp times. The DSS `OriginalDocuments/SignerData` digest matches each original unsigned revision's bytes, rather than the entire sealed file. B-T timestamp message imprints match SHA-256 of the CMS signature values. The sealed PDFs' full hashes below were independently checked against the original bundle manifest.

| Fixture | Profile | Embedded timestamp (UTC) | DSS validation time (UTC) | PDF SHA-256 |
| --- | --- | --- | --- | --- |
| `consent-multipage-BB.pdf` | B-B | None | 2026-09-26T19:51:34Z | `0d7bc17e73ccc7637e0a4c357881b9157a81b99a4697c6569c5408d3ba44afd0` |
| `consent-multipage-BT.pdf` | B-T | 2026-09-26T17:20:55+00:00 | 2026-09-26T19:00:36Z | `af98be24b7f21817187dc52b48efae5e8c7931d427e64a251382b220aea8e025` |
| `merged-attachment-BB.pdf` | B-B | None | 2026-09-26T19:54:29Z | `92aefa914d668fb2474c174335db46002c61e32929ee5617775472d4a62e77b4` |
| `merged-attachment-BT.pdf` | B-T | 2026-09-26T17:20:56+00:00 | 2026-09-26T19:55:47Z | `235b66acbe07dc697414eb7f760bcf07ea12c22915d8ed77fc02eec2eaf95fa0` |
| `merged-object-streams-BB.pdf` | B-B | None | 2026-09-26T19:52:15Z | `b3f3773124fea07a2f0a0549d348a03bb3fb9255e38cceeb4f0ad0e242d5b405` |
| `merged-object-streams-BT.pdf` | B-T | 2026-09-26T17:20:56+00:00 | 2026-09-26T19:07:09Z | `383eea28e3d45a7e982c9798695a4dc7017545ae1f613f59213f55bcd4b649d5` |

Disposable root SHA-256: `81194a6299bb700ed398dddd50930ea224cf1919a1a283f770cd29642341fbfd`.

## Preserved reports

All twelve user-supplied reports (six diagnostic XML files and six Detailed Report PDFs) were copied without alteration to the bundle's Git-ignored `reports/` directory, using normalized filenames. Original downloads remain unchanged. Their hashes and individual manual outcomes are recorded in the bundle manifest. This document preserves the conclusions and identifiers in Git without committing generated PDFs or reports.

| Report | SHA-256 |
| --- | --- |
| `consent-multipage-BB-diagnostic.xml` | `61f0732deeba3af1ddf3117d1d4f22e7f2500c0ebd976b3c46f79be7a6d591ae` |
| `consent-multipage-BB-detailed-report.pdf` | `dbf87170ded2b3be75cad300fb4e805a3e45edebc73eccffc54492bdcbc76c39` |
| `consent-multipage-BT-diagnostic.xml` | `e2035fc17d9bb28d218f009876cdeb49660ad52b37f6c2082d97db64d1a14b60` |
| `consent-multipage-BT-detailed-report.pdf` | `6ca1c48fa13d8e3b8d0d287cdf50067600550269fe3417c995ce5ff51a4e5d39` |
| `merged-attachment-BB-diagnostic.xml` | `ea81d0652f7b9913ba23b6eafeeabb74686e53c1158c0fd0ac6bf78ec00103ae` |
| `merged-attachment-BB-detailed-report.pdf` | `671e66a0650cb6ba0b22bde6a7c09e6b458ba0a8f9f5c60a782a5b2f143c17ce` |
| `merged-attachment-BT-diagnostic.xml` | `131f738ea65cad445d1a8243da8f3a9a28794e499efa13cf6aa4fab509a6a54d` |
| `merged-attachment-BT-detailed-report.pdf` | `7f9c46edbf990a66096d23c1b2f1fde90b927cc2b284f6a641fca5c4d6e7cc4a` |
| `merged-object-streams-BB-diagnostic.xml` | `242f46ff28b2e860291174b2be849c4ee53b9e91bf328b06eb96a2dcb62ecda8` |
| `merged-object-streams-BB-detailed-report.pdf` | `6c6db08307b3e55e66f21ebb004782eb375f4c730f703e1e365b30b46d0e4519` |
| `merged-object-streams-BT-diagnostic.xml` | `79341ee8ff9c624242259ebec48fcd94f43640db35179d171682353ff3848b26` |
| `merged-object-streams-BT-detailed-report.pdf` | `d36a17639f11c9a720b42392babab5a31a15ce70c1455e3115a41e042b689b03` |

## Closure and limits

This selected interoperability round is complete, with no further repeat checks recommended for these six files unless implementation changes or a new failure requires them. No production-code or PKI change was needed for the final report review.

A full trusted DSS result would require a validation environment configured with the intended private trust anchor; the public demo result provides evidence of the tested structure and cryptography, but does not establish trust or revocation status. The single-page/no-footer controls remain available for focused diagnostics, without extending this round to additional manual tests.
