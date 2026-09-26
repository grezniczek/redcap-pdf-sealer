# Acrobat and DSS manual acceptance

## Current checkpoint (2026-09-26)

The initial bundle `acceptance-20260926-01` failed the user's two initial Acrobat checks: multipage consent reported modifications; the merged/object-stream PDF showed an empty Signature Panel. Keep these results distinct from the local cryptographic checks, which passed.

The replacement bundle is `DEV_DOCS/interop-artifacts/acceptance-20260926-02`. It externalizes inline links on every page and retains cross-reference streams when signing stream-based inputs. Local verification passes for all ten files. The selected six-fixture manual round is complete: Acrobat reports no modifications for multipage consent, merged/object-stream output and the unrotated attachment, each in B-B and B-T. DSS v6.5 identifies the expected Baseline B/T profiles and passes structure and cryptography, including B-T timestamp verification. Overall validation remains INDETERMINATE/NO_CERTIFICATE_CHAIN_FOUND because the disposable root is not trusted. See [recorded acceptance and report hashes](pdf_interop_acceptance.md). Single-page/no-footer controls have local coverage only.

## Completed follow-up: four additional variants

The following PDFs from `DEV_DOCS/interop-artifacts/acceptance-20260926-02` have user-confirmed Acrobat no-modification results and correlated DSS reports. Their hashes and sizes match the original manifest. No PDFs or certificates were regenerated.

| File | Pages / footer links | Expected timestamp | Coverage |
| --- | --- | --- | --- |
| `consent-multipage-BB.pdf` | 3 / 3 | None | Multipage certification without a TSA token |
| `merged-object-streams-BB.pdf` | 2 / 1 | None | Xref/object streams and rotated attachment without a TSA token |
| `merged-attachment-BB.pdf` | 2 / 1 | None | Unrotated landscape attachment, classic xref, B-B |
| `merged-attachment-BT.pdf` | 2 / 1 | 2026-09-26 17:20:56 UTC | Same unrotated layout with a signature timestamp |

DSS confirmed PAdES-BASELINE-B with no timestamps for the first three files, and PAdES-BASELINE-T with one valid signature timestamp for the last. Structure, signature and applicable timestamp cryptographic checks pass. The existing disposable root remains untrusted by the public DSS demo. Acrobat's page appearance, manual link clicks and timestamp display were not separately reported; local rendering/text/link preservation checks passed.

Together with the previously accepted `consent-multipage-BT.pdf` and `merged-object-streams-BT.pdf`, this closes the selected round. No repeat checks are needed now. The single-page and no-footer fixtures remain optional comparison controls if a new failure occurs. The procedure below is retained for future runs.

## Generating a future bundle and initial test order

Generate an isolated bundle from the module root:

```sh
mkdir -p DEV_DOCS/interop-artifacts
php tests/pdf_redcap_fixtures.php --export-dir "$PWD/DEV_DOCS/interop-artifacts/acceptance-01"
```

The output directory must be new. A run generates five synthetic fixtures in both B-B and B-T (ten PDFs), public `test-root.pem` and `test-root.cer`, `manifest.json`, and this checklist. It exports PDFs only after local signature and content-preservation checks pass. An incomplete run removes its exported files. It never exports private keys or reads the instance PKI/database. Every run issues a fresh test root, so previously trusted test certificates will not apply to a new bundle. The output directory is Git-ignored.

Start with these two files:

1. **consent-multipage-BT.pdf** — three portrait pages; clickable footer on each; synthetic signature image on page 3.
2. **merged-object-streams-BT.pdf** — two pages; merged attachment; rotated landscape page; footer link on page 1.

Then check **merged-attachment-BT.pdf** (unrotated merged layout) and the matching **BB** files. The one-page **consent-signature** and two-page **consent-no-footer-link** files are comparison controls if a problem appears. BB files should have no signature timestamp; BT files should have one.

Keep the bundle's original filenames and manifest together. The manifest records SHA-256, profile, page/link counts, and timestamp time. Its manual result fields start as pending; local success is not an Acrobat/DSS result. Give reports names matching the tested PDF, for example `consent-multipage-BT-diagnostic.xml`.

## Acrobat

Open the original exported PDF without saving edits. For each file report:

- Does Acrobat say it has not been modified since certification, or report changes/invalidity?
- Do all pages look correct, including the signature image and attachment orientation?
- Do the expected footer links work?
- For BT, does the Signature Panel report an embedded timestamp? For BB, no timestamp is expected.
- Record certificate trust, revocation, and LTV findings separately from any document-integrity finding.

This is a newly issued disposable test CA, unrelated to the instance CA you previously trusted. Initial signer/TSA trust warnings are expected. Optional: use Acrobat's certificate trust controls to trust the bundled public test root for this test, after comparing its SHA-256 with `manifest.json`. No private-key import is needed. If you add test trust, record that fact alongside the result. Root trust alone does not supply revocation evidence or make these PDFs LTV-enabled.

If there is a modification warning, provide the exact wording and any View Report output. Keep the original PDF unchanged so its manifest hash still identifies the artifact.

## DSS

Use the European Commission's [DSS signature validation demo](https://ec.europa.eu/digital-building-blocks/DSS/webapp-demo/validation). Select one exported PDF as **Signed file**, leave **Original file(s)** empty for this embedded PDF signature, and begin with the default validation policy. Record any options you change. The demo transmits submitted files to the Commission's infrastructure; the bundle contains synthetic test material only. [Demo information](https://ec.europa.eu/digital-building-blocks/DSS/webapp-demo/home).

Download **Detailed Report** and **Diagnostic Data** (XML where offered), plus the Simple Report or ETSI report if convenient. DSS documents these report types in its [validation reporting guide](https://ec.europa.eu/digital-building-blocks/DSS/webapp-demo/doc/dss-documentation.html). Return the reports with the exact PDF filename and DSS version shown by the page.

The public demo's normal trust policy will not generally trust this private test CA. Supplying an adjunct certificate is not equivalent to designating a trust anchor. Preserve the actual indication/sub-indication and underlying report; do not treat a trust-policy failure as proof of altered PDF bytes, and do not treat local cryptographic success as full DSS acceptance. We will inspect signature integrity, detected profile, timestamp imprint/signature, certificate-path findings, and any PDF structural errors separately.

No LT/LTA, qualified-signature status, or revocation availability is claimed by these fixtures. A failed or indeterminate overall result needs its detailed reasons recorded, rather than being relabeled as a pass.

## Minimal reply

For a future requested run, send Acrobat's modification/timestamp and page/link findings, plus the DSS Detailed Report + Diagnostic Data paths. A grouped reply is sufficient if all files behave as expected; identify any exception by filename. Screenshots are helpful for unexpected Acrobat messages. If Windows supplies a local path, provide it as-is; it can be read through `/mnt/c` when accessible. No further reply is needed for the completed six-fixture round.
