# PDF Sealer v1 readiness review

## Conclusion — 2026-10-03

The module's candidate package passes the reviewed dependency, notice, namespace isolation, runtime syntax and package-content gates after repairing a dependency-build omission. The previously accepted legacy ESS compatibility change is now reproducible and correctly disclosed; no runtime library PHP changed in this slice.

**Public release coordination remains open:** identify the supported REDCap Core and EM Framework releases providing PDF finalization and the bounded HTTP helper before claiming compatibility with a normally installed REDCap version. The inspected installation runs dedicated development branches. No release was tagged, published or installed by this review; no Core/Framework or live database/PKI state was changed.

## Evidence checked in this slice

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

## Candidate provenance

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

Before a public v1 distribution:

1. Establish the supported released Core/Framework versions or an explicitly supported coordinated deployment. Then set any justified compatibility floor and state the exact prerequisites; do not invent a REDCap minimum version now.
2. Choose the final public version/tag and build its exact committed archive. Preserve the development-only **pdf_sealer_v9.9.9** checkout convention.
3. Perform a clean installation smoke test of that archive on the intended supported stack: enablement, initialization, cron registration, project operation assignment, diagnostics, one sealed eConsent and public certificate/CRL access. The current instance's acceptance is valuable but does not prove a new installation consumes the package correctly.

The following remain explicit scope decisions rather than missing capabilities silently claimed by v1: B-LT/B-LTA and OCSP; CA-provider UI redesign; external TSA source editing/removal and public TSA-chain downloads; the Core footer-link proposal; and XML/PMT pipeline transfer in Core/Framework. XML/PMT currently requires explicit pipeline reassignment, as documented. The user has deferred these changes; revisit a specific item only if it is selected for the v1 scope.
