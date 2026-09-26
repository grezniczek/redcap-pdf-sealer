# Acrobat and DSS manual acceptance

## Current checkpoint (2026-09-26)

The initial bundle `acceptance-20260926-01` failed the user's two initial Acrobat checks: multipage consent reported modifications; the merged/object-stream PDF showed an empty Signature Panel. Keep these results distinct from the local cryptographic checks, which passed.

The replacement bundle is `DEV_DOCS/interop-artifacts/acceptance-20260926-02`. It externalizes inline links on every page and retains cross-reference streams when signing stream-based inputs. Local verification passes for all ten files. The user confirmed that Acrobat reports no modifications since certification for both initial B-T files. DSS v6.5 identifies both as PAdES-BASELINE-T and passes structure, signature and timestamp cryptography; overall validation is INDETERMINATE/NO_CERTIFICATE_CHAIN_FOUND because the disposable root is not trusted. See [recorded acceptance and report hashes](pdf_interop_acceptance.md). Other files' manual checks remain pending; each bundle has its own disposable root.

## Bundle and test order

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

For the initial two BT files, send Acrobat's modification/timestamp findings and the DSS Detailed Report + Diagnostic Data paths. Screenshots are helpful for unexpected Acrobat messages. If Windows supplies a local path, provide it as-is; it can be read through `/mnt/c` when accessible.
