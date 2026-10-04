# PDF Sealer v1 readiness review

## Current release-candidate status — 2026-10-04

At the user's request, the current module state is designated a **release candidate for the current REDCap Core and EM Framework implementation of `redcap_module_pdf_finalize`**. The UI refinement round is complete. The hook implementation and contract may still change; compatibility must be reviewed against the eventual released Core/Framework contract.

The recorded integration baseline is:

| Component | Current committed baseline | Branch |
| --- | --- | --- |
| PDF Sealer | `f40e4163e0400441d5dead913950674aa6e54947` | `main` |
| REDCap Core | `372ca1bc40903da646c55c4da17ea4707154b842` | `signature-and-pdf-lifecycle-hooks` |
| EM Framework | `82a5966c3dff40a0b68bc3bd957f23223878bb04` | `signature-and-pdf-lifecycle-hooks` |

All three checkouts were clean before this documentation update. This designation records implementation status; it does not designate the older archive below as a package of the current candidate. Its packaging evidence remains specific to its recorded commit. No new archive, release tag or publication was created in this slice.

## Conclusion — 2026-10-03

The unpublished package reviewed on this date is a direct archive of committed source **b271be0c9393d6ba1861180ab7370c145e3cf881**, including the accepted sealing-support notices and subsequent HTML-label changes. Dependency reproducibility, notices, namespace isolation, runtime syntax and package-content gates pass on PHP 8.2/8.5. The preceding review repaired the dependency-build omission described below; this refresh changes no runtime source. The UI/UX refactor rounds planned at that time are now complete, as recorded above.

**Installation policy:** the module intentionally remains installable for certificate management when PDF finalization support is absent. The required Core/Framework additions are not in released versions yet. Sealing requires both feature markers described below; missing support is reported on the project status and CC management pages. A published version number alone must not be presented as proof of sealing support. The bounded HTTP helper remains a separate external TSA requirement. The inspected installation runs dedicated development branches. No release was tagged, published or installed by this review; no Core/Framework or live database/PKI state was changed.

## Refreshed committed candidate — 2026-10-03

Built with **git archive** from the exact commit above, with no working-tree overlays or dependency downloads. The checkout was clean when generated. No release metadata, tag, installation or publication was changed.

| Gate | Result |
| --- | --- |
| Dependencies and notices | Reproducible 61-file bundle; three prefixed LGPL packages; checkout and extracted notices pass on PHP 8.2.34 and 8.5.11 |
| Extracted loader | Both load orders pass on both runtimes: 48 isolated dependency symbols and module adapter, without Composer |
| Runtime syntax | All 140 packaged PHP files pass on both runtimes; all five JavaScript assets parse |
| Contents and exclusions | 169 files; required runtime, shared support notice, all libraries/license records and three configured guides present; no Composer, developer/editor files or PDF/certificate/key fixtures |
| Documentation | All 57 relative Markdown file links resolve within the package |
| Support notices | All four marker combinations and existing pipeline states pass on both runtimes against the extracted runtime/view/language files |
| Archive | ZIP integrity passes; ZIP commit comment matches the source commit |

The support harness is the committed **tests/project_pipeline_status.php**, copied outside the extraction with only its three package-root expressions retargeted. No tests or tooling were added to the package. Cryptographic, lifecycle and browser matrices were not repeated: the changes since the earlier candidate affect support detection, notices and UI labels. User-reported capable/non-capable installation acceptance is recorded below; installing this exact candidate on a fresh instance remains a separate check.

- Temporary candidate: **/tmp/pdf-sealer-candidate-UsWE2y/pdf-sealer-candidate.zip**
- ZIP SHA-256: **9d7946c50a4e9730aa7e5ad3253c257ede3a15a87f21266c7842495d32bd9a22**
- Content-tree SHA-256: **83b847337ee441d584c21eb2fdf9422b4a6bc21fa52190fb26aff8931a1659a4**
- Evidence receipt: **/tmp/pdf-sealer-candidate-UsWE2y/verification.json**
- Per-file hashes: **/tmp/pdf-sealer-candidate-UsWE2y/content-SHA256SUMS.txt**
- Content hash method: SHA-256 of file-hash, two spaces, relative path and LF for all 169 files, sorted by UTF-8 path bytes.

These temporary artifacts are unpublished and may disappear. Regenerate from the final commit after the remaining UI/UX work; preserve the development directory convention.

## Feature-presence notices — 2026-10-03

The shared **SealingSupport** check requires both the Core constant **Vanderbilt\REDCap\Classes\Settings\ProjectSettingKeys::EXTERNAL_MODULES_PDF_FINALIZE_EXECUTION_PLAN** and the Framework class **ExternalModules\PdfFinalize**. It does not write configuration, block installation or disable certificate management. Both management and project status pages identify missing components; the project pipeline inspector avoids reading configuration when either marker is absent. Assignment, Framework storage availability and PKI health remain separate checks.

The isolated support/notice tests pass on PHP 8.2.34 and 8.5.11 for neither marker, Core only, Framework only and both. They render the shared notice and confirm that no warning is shown when both markers are present. On 2026-10-03, the user reported successful tests on both sealing-capable and non-capable installations, completing manual acceptance of these support notices. This does not establish installation of the final release archive. The refreshed candidate above includes these runtime/view changes. A new archive from the final committed source will still be needed after the planned UI/UX rounds.

## Evidence checked in the preceding packaging slice

| Gate | Result and scope |
| --- | --- |
| Reproducible bundle | Pass on PHP 8.2.34 and 8.5.11: 61 generated files from the pinned, locally installed development inputs; no dependency download/upgrade |
| Third-party notices | Pass in checkout and extracted candidate: three prefixed LGPL packages, retained license texts, complete reviewed tree and accurate functional-change metadata |
| Runtime library preservation | Every library PHP source and existing package modification record is unchanged; only the manifest gains functional-change metadata |
| External TSA compatibility | Existing external_timestamp suite passes: legacy ESSCertID accepted only with the opt-in flag; strict verification and SHA-1 CMS signature rejection remain enforced |
| Package namespace isolation | Pass on PHP 8.2/8.5, both foreign-first and module-first: all 48 bundled symbols plus module adapter load from the extracted package without Composer |
| Package runtime syntax | All 138 packaged PHP files pass lint on PHP 8.2.34 and 8.5.11 |
| Contents | 167 files; module source, assets/views, all 61 library files, autoloader, notices/license texts and configured documentation present |
| Exclusions | No Composer metadata/runtime, vendor, tools/tests, DEV_DOCS, editor/Git files or generated PDF/certificate/key fixtures |
| Packaged Markdown links | 57 relative links resolve inside the extracted package, including configured README/project/admin guides and technical/license references |
| Archive integrity | ZIP integrity check passes |
| Latest manual evidence | User reports root superseded-key recovery passed in PID 534, record 4, and the Windows-thumbprint browser spot-check passed |

The accepted pipeline, PDF fixture, Acrobat/DSS, copy/import/PMT, external provider/source and lifecycle matrices retain their original scope in [implementation status](implementation_status.md) and [testing](testing.md). This review did not rerun all cryptographic or browser tests: runtime implementation did not change. It is not a clean installation test on another REDCap instance or a general security/license compliance audit.

## Dependency omission repaired

The pre-review build differed in exactly two files: the signing library's **Cms/SignedDataVerifier.php** and **MODIFICATIONS.md**. Commit **f7b9b10** had correctly added and documented the external-TSA-only legacy ESSCertID exception on 2026-09-28, but the build recipe still regenerated the original behavior, the reviewed output hash was stale, and the overall notice incorrectly said no functional changes had been made.

The audited functional patch is now declared in [third-party-review.json](../tools/third-party-review.json), with its modification/review dates, exact prefixed input/output hashes and four unique replacements. [The builder](../tools/dependency-build.php) rejects changed input, ambiguous context, unexpected output or an unapplied target. It reproduces the accepted PHP bytes and modification record exactly. The generated manifest records the change; [the notice checker](../tools/third-party-notices.php) compares that record against reviewed metadata and the complete bundle hash. The notices describe the exception accurately. See [the ongoing build procedure](release_licensing.md).

## Earlier candidate provenance

This temporary, unpublished candidate uses committed source **6b2dbe30e0afa282d798107fb6a5dd92b1c023bf** plus the reviewed working-tree overlays **libraries/manifest.json** and **THIRD_PARTY_NOTICES.md**. Developer documentation and tooling changes are intentionally outside the package. It is not an archive of a final release commit.

- Temporary candidate: **/tmp/pdf-sealer-release-R0S4Vt/pdf-sealer-candidate.zip**
- ZIP SHA-256: **9a84fc362caf016f226dbba9045505cb8e271d42948a254e818d1d1bd6fb0c28**
- Content-tree SHA-256: **cc48728403de88d8f693998f9d43db1108fbf1f49af219745421618e1a219498**
- Content hash method: SHA-256 of sorted lines consisting of each file's SHA-256, two spaces, relative path and LF, covering all 167 files.

The temporary files may disappear. After committing the final source and choosing release metadata, create a new **git archive** from that exact commit and rerun [the release gates](release_licensing.md#release-packaging). Do not publish this working-tree candidate as the final version.

## Integration and remaining release decisions

Read-only source inspection confirms the necessary features in the current development checkouts:

| Component | Inspected state | Required capability |
| --- | --- | --- |
| Core | signature-and-pdf-lifecycle-hooks; HEAD 372ca1bc40 | Classes/PdfFinalizer.php and Core dispatch before PDF artifact commitment |
| Framework | signature-and-pdf-lifecycle-hooks; HEAD 82a5966c | pdf-finalize declaration/assignment and redcap_module_pdf_finalize contract |
| Core HTTP | Same inspected Core checkout | HttpClient::requestWithResponseLimit and Classes/Http/ResponseByteLimit.php for bounded external TSA responses |

Both integration worktrees were clean during inspection. Their presence on these branches and live acceptance do not establish availability in a published release. The Framework hook documentation still marks the introduction version **TBD**. Declaring Framework version 16 alone cannot guarantee these added features, as the [administrator guide](../docs/ADMIN.md#requirements-and-installation) already explains.

The UI/UX refactor rounds are complete. Before a public v1 distribution:

1. Document the two installation modes: certificate management without PDF finalization support, and sealing with both feature markers. State integration prerequisites without inventing a REDCap minimum version or blocking certificate-only installations. Published Core/Framework feature availability remains to be recorded when released.
2. Choose the final public version/tag and build its exact committed archive. Preserve the development-only **pdf_sealer_v9.9.9** checkout convention.
3. Perform clean installation smoke tests of that archive in both modes. Without finalization support, check enablement, initialization, cron registration, certificate/CRL access and the notices on both pages. On a sealing-capable stack, also check project operation assignment, diagnostics and one sealed eConsent. The current instance's acceptance is valuable but does not prove a new installation consumes the package correctly.

The following remain explicit scope decisions rather than missing capabilities silently claimed by v1: B-LT/B-LTA and OCSP; CA-provider UI redesign; external TSA source editing/removal and public TSA-chain downloads; the Core footer-link proposal; and XML/PMT pipeline transfer in Core/Framework. XML/PMT currently requires explicit pipeline reassignment, as documented. The user has deferred these changes; revisit a specific item only if it is selected for the v1 scope.
