Please add complete and maintainable third-party licensing/attribution information to the PDF Sealer External Module.

## Goals

- The PDF Sealer EM itself remains licensed under MIT.
- Every third-party library actually shipped in the release ZIP must retain its own license and attribution.
- It must be obvious to someone inspecting the repository or release ZIP that the root MIT license applies to our EM code, not to bundled third-party code.
- Modified third-party libraries must be clearly identified as modified.
- Do not change the license of any third-party library.

## 1. Inventory the actual bundled dependencies

Inspect the codebase, Composer metadata, lock files, and packaged library directories and determine all third-party libraries that are actually included in the release ZIP.

In particular, check the full dependency chain around:

- `tecnickcom/tc-lib-pdf-sign`
- `tecnickcom/tc-lib-pdf-parser`
- any dependencies of those packages, e.g. `tecnickcom/tc-lib-pdf-filter`
- any other third-party code bundled by this EM

Do not assume the list above is complete.

For each bundled library, determine from the actual bundled version:

- package name
- exact version
- upstream project URL
- copyright holder/author information where supplied upstream
- SPDX-style license identifier if available
- location of the original license file
- whether our bundled copy has been modified

Use the package's own `composer.json`, source distribution, and license files as authoritative sources.

## 2. Preserve upstream license files

Retain the original license/copyright files shipped with each third-party package in their natural location beside that package's source.

For example:

    <bundled-libraries>/tecnickcom/tc-lib-pdf-sign/LICENSE
    <bundled-libraries>/tecnickcom/tc-lib-pdf-parser/LICENSE
    ...

Do not rewrite, shorten, or replace upstream license texts.

Make sure release packaging does not accidentally exclude these files.

## 3. Add a root THIRD_PARTY_NOTICES.md

Create:

    THIRD_PARTY_NOTICES.md

This file should state near the top:

> PDF Sealer is licensed under the MIT License. This distribution also
> includes third-party software that is licensed separately. The licenses
> listed below apply to their respective components and not to PDF Sealer
> as a whole.

Then add one section for every library actually bundled.

Use approximately this structure:

    ## tecnickcom/tc-lib-pdf-sign

    Version: <exact bundled version>
    Upstream: https://github.com/tecnickcom/tc-lib-pdf-sign
    License: LGPL-3.0-or-later
    Copyright: <preserve/describe upstream attribution accurately>

    The complete upstream license terms are included at:

        <relative path>/tc-lib-pdf-sign/LICENSE

    Bundled copy: unmodified

If our copy is modified, say instead:

    Bundled copy: modified

    Modifications are documented in:
        <relative path>/tc-lib-pdf-sign/MODIFICATIONS.md

Do not copy the entire LGPL/GPL text into `THIRD_PARTY_NOTICES.md`; link to the complete license file that is shipped with the library.

## 4. Document namespace-prefix modifications

Some bundled Tecnickcom sources may have their PHP namespaces changed/prefixed to prevent namespace collisions inside REDCap.

A namespace-only change still means that we distribute a modified copy of that library.

For every third-party package whose source has been changed this way, add:

    MODIFICATIONS.md

inside that package directory.

Use approximately:

    # Modifications

    This directory contains a modified copy of
    `tecnickcom/<package>` version <version>.

    Modified by the PDF Sealer project.

    Changes from upstream:

    - PHP namespaces were prefixed to avoid dependency namespace
      collisions with other REDCap components.
    - Corresponding namespace references/imports were adjusted.
    - No intentional functional changes were made.

    Initial modification date: YYYY-MM-DD

    The library remains licensed under its original
    LGPL-3.0-or-later license.

    Upstream:
    <URL>

Use the actual modification date and actual package/version.

If there are functional modifications in addition to namespace prefixing,
list those explicitly rather than claiming that there are none.

## 5. Mark modified source files

For source files that we actually modify, preserve all existing upstream
copyright and license comments.

Add a small modification notice without replacing the upstream notice,
for example:

    /*
     * Modified by the PDF Sealer project on YYYY-MM-DD:
     * PHP namespace prefixed to avoid dependency namespace collisions.
     * See MODIFICATIONS.md.
     */

Do this in a consistent location.

Do not add such notices to files that remain byte-for-byte upstream.

If namespace prefixing touches a very large number of files and inserting
a comment into every file would create unnecessary maintenance noise,
first inspect the applicable upstream license requirements and existing
file headers. Prefer a defensible, consistent solution using the package-
level `MODIFICATIONS.md` plus source notices where appropriate rather than
blindly modifying every file.

## 6. Root MIT LICENSE

Keep the project's root:

    LICENSE

as the MIT license for PDF Sealer.

Do not append LGPL/GPL license text to the root MIT license.

The separation should remain clear:

    LICENSE
        -> PDF Sealer: MIT

    THIRD_PARTY_NOTICES.md
        -> inventory and attribution of bundled third-party software

    bundled-package/LICENSE
        -> complete license governing that particular dependency

    bundled-package/MODIFICATIONS.md
        -> our modifications, where applicable

## 7. README

Add a short section or sentence to the README, e.g.:

    ## License

    PDF Sealer is licensed under the MIT License.
    This distribution includes third-party software under separate
    licenses. See `THIRD_PARTY_NOTICES.md` for details.

Do not imply that bundled LGPL components are MIT-licensed.

## 8. Release packaging

Inspect the process that creates the REDCap EM release ZIP.

Ensure that it includes:

- root `LICENSE`
- `THIRD_PARTY_NOTICES.md`
- each bundled dependency's original license file(s)
- each applicable `MODIFICATIONS.md`
- the corresponding third-party source code being distributed

If there is an allowlist/exclusion mechanism for release files, update it
accordingly.

## 9. Prefer generated/verifiable attribution over stale manual data

Where practical, derive package name and version information from the
actual dependency metadata used for the build rather than duplicating
versions manually in several places.

However, `THIRD_PARTY_NOTICES.md` itself should be present in the
repository and readable without running Composer.

Consider adding a lightweight build/test check that fails if:

- a bundled third-party package has no license file;
- a bundled package is missing from `THIRD_PARTY_NOTICES.md`; or
- a package marked as modified lacks `MODIFICATIONS.md`.

Do not introduce a large dependency-management framework just for this.

## 10. Scope

This task is about attribution and license compliance only.

Do not:
- relicense third-party code;
- change package functionality;
- replace LGPL libraries;
- restructure the library integration unnecessarily.

After implementation, report:

1. all third-party packages found in the release;
2. their versions and licenses;
3. which ones are modified;
4. every attribution/license file added or retained;
5. any uncertainty that requires human review.