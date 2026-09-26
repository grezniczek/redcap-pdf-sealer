# PDF Sealer developer documentation

Start here for implementation, testing, acceptance evidence, and release maintenance. This directory is excluded from release packages by `.gitattributes`. User and administrator instructions and the packaged technical references are in [docs](../docs/PROJECT.md); the [repository overview](../README.md) introduces the module.

## Recommended reading order

1. [Current status and implementation history](implementation_status.md): begin with its current summary; older milestone sections record the work at that time.
2. [Development and testing](testing.md): local setup, standalone suites, disposable fixtures, and live-test boundaries.
3. [Fixture coverage](pdf_fixture_coverage.md), [Core pipeline and stored-PDF acceptance](pdf_pipeline_acceptance.md), and [Acrobat/DSS acceptance](pdf_interop_acceptance.md): what has actually been verified and what those results do not establish.
4. [Dependency notices and release packaging](release_licensing.md): audit dependencies, regenerate notices, build a complete package, and check its contents.

## Current working references

| Document | Purpose |
| --- | --- |
| [Implementation status](implementation_status.md) | Current summary followed by chronological implementation and verification notes |
| [Testing](testing.md) | Commands and prerequisites formerly mixed into the root README |
| [Fixture coverage](pdf_fixture_coverage.md) | Automated PDF matrix and coverage boundaries |
| [Pipeline acceptance](pdf_pipeline_acceptance.md) | Real dispatch harness and stored/downloaded-byte evidence |
| [Interop acceptance](pdf_interop_acceptance.md) | Six selected B-B/B-T PDFs, report correlation, and recorded trust limitations |
| [Manual validation procedure](pdf_manual_validation.md) | How to collect evidence when a new interoperability check is needed |
| [Release licensing](release_licensing.md) | Dependency review, license notices, and packaging procedure |

The selected interoperability round is complete. The manual procedure is available for future changes; it is not a request to repeat already accepted checks. Generated PDFs, public test certificates, and validator reports under `interop-artifacts/` are Git-ignored and must stay out of release packages.

## Historical plans and deferred proposals

These preserve design rationale and earlier evidence. Their proposed behavior and old test results are not the current feature specification.

| Document | Status |
| --- | --- |
| [Original EM implementation plan](PDF_Sealer_EM_Implementation_Plan.md) | Design baseline; consult current status and code for implemented behavior and subsequent decisions |
| [Core/Framework finalization plan](redcap_module_pdf_finalize_implementation_plan.md) | Integration design history, including examples unrelated to current module functionality |
| [Finalization PR description](redcap_module_pdf_finalize_pr_description.md) | Historical Core/Framework change description |
| [2026-09-23 live acceptance](live_acceptance_2026-09-23.md) | Earlier finalizer/demo evidence, predating current cryptographic sealing acceptance |
| [Core footer-link change request](redcap_core_pdf_link_change.md) | Deferred; the EM currently handles inline links and no Core change is scheduled |
| [Licensing brief](license.md) | Completed implementation brief; results and ongoing procedure are in release licensing |

## Maintaining documentation

- Keep project tasks in [PROJECT.md](../docs/PROJECT.md), system administration in [ADMIN.md](../docs/ADMIN.md), and stable technical explanations in [PKI](../docs/pki.md) and [sealing/validation](../docs/sealing-and-validation.md).
- Keep test commands, agent notes, plans, local paths, fixture IDs, and development evidence here. Developer tools and executable tests live in `tools/` and `tests/` and are also excluded from releases.
- Link packaged guides to this directory using a GitHub URL, since it is absent from installations. Use relative links between packaged documents.
- When a finding is resolved, update the current summary and point older pending statements to the resolution. Preserve the scope and date of historical evidence; do not turn a fixture result into a claim about every document or workflow.
- After changing guides or `config.json`, check all three documentation entries, relative links, and release inclusion. The Framework uses `docs/PROJECT.md` in projects, `docs/ADMIN.md` in the Control Center, and root `README.md` as the general fallback.
