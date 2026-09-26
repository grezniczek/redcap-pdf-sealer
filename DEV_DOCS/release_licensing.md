# Dependency notices and release packaging

## Reviewed distribution

The 2026-09-27 review found three production Composer packages: `tecnickcom/tc-lib-pdf-filter` 2.11.3, `tecnickcom/tc-lib-pdf-parser` 3.16.1, and `tecnickcom/tc-lib-pdf-sign` 2.0.4. All declare `LGPL-3.0-or-later`. Every installed file matched its corresponding cached Composer distribution archive; archive roots match the pinned Git revision prefixes. No namespace prefixing or other vendor edits exist. All original source headers and package `LICENSE` files are retained. No `MODIFICATIONS.md` is appropriate for these unmodified copies.

Composer's generated installation support is also shipped, with its original MIT `vendor/composer/LICENSE`. Its generator version is not recorded by Composer; `ClassLoader.php` and `InstalledVersions.php` match this machine's Composer 2.7.1 runtime classes. This comparison does not establish the generator version. The review fingerprints these two classes so later edits require review.

The original Tecnick license files contain LGPLv3, which incorporates GPLv3. `licenses/GPL-3.0.txt` supplies the complete accompanying GPLv3 text required by [LGPLv3 section 4(b)](https://www.gnu.org/licenses/lgpl-3.0.html). It was copied unchanged from this development machine's `/usr/share/common-licenses/GPL-3`; the [FSF publishes the text](https://www.gnu.org/licenses/gpl-3.0.html). Root `LICENSE` remains byte-for-byte unchanged and applies to the module's MIT code.

The module's `src/Timestamp/PolicyOidAsn1.php` extends an upstream interface without changing vendor files. The UI uses REDCap-provided assets; `assets/admin.css` is module code. PHP/extensions, REDCap/Framework, and external test tools are not bundled. Development fixtures and their disposable certificates must never be included in release ZIPs.

## Check and update

```sh
php tools/third-party-notices.php
```

This reads files without loading vendor code or bootstrapping REDCap. It checks the production package set against installed metadata and reviewed packages, versions/revisions/licenses, original license text fingerprints, complete reviewed package trees, and the generated notice. Missing or altered package files fail, as do unlisted vendor packages. A package marked modified must contain `MODIFICATIONS.md`. This is a distribution consistency check, not a general license scanner or proof of legal compliance.

After a dependency upgrade or deliberate library edit:

1. Review `composer.lock`, installed package metadata, upstream copyright/license files, and source differences against the exact pinned distribution. Include any newly bundled non-Composer components in the inventory/check too.
2. If modified, preserve upstream notices, add a dated package `MODIFICATIONS.md`, and mark each changed source file appropriately. Keep a reproducible patch/build step outside ignored `vendor/` so a clean install retains the notices and edits. Do not label modified copies as pristine.
3. Update `tools/third-party-review.json` only after this review: revision, modification status, copyright, and package tree SHA-256. The tree hash is SHA-256 of concatenated lines `file_sha256 + two spaces + relative_path + LF`, sorted by relative path in byte order, covering every package file. License and Composer runtime fingerprints are separate. This intentionally cannot be refreshed by the notice generator itself.
4. Run `php tools/third-party-notices.php --write`, inspect the readable notice, then run the check again. Package names, versions, licenses, authors, and upstream URLs in the notice come from Composer metadata rather than a second version list.

## Release staging

No release builder or existing release ZIP was present at review time. `.gitattributes` excludes development docs, tests, tools, and editor files; it retains the root notice and `licenses/`. `vendor/` is Git-ignored, so **GitHub source ZIPs and `git archive` alone are not installable release packages** and do not contain the dependency license files.

After committing the intended release files, stage a release from that commit, install the locked production dependencies in the staging directory, then check that directory. Run from the checkout with PHP, Composer, Git, tar, zip, and unzip available:

```sh
pdf_sealer_stage=$(mktemp -d)
git archive HEAD | tar -x -C "$pdf_sealer_stage"
composer install --working-dir="$pdf_sealer_stage" --no-dev --prefer-dist --no-interaction --no-scripts --no-plugins
php tools/third-party-notices.php "$pdf_sealer_stage"
```

**Stop if any command fails.** Use a new absolute ZIP path and run the archive-content check before distribution:

```sh
pdf_sealer_zip=/absolute/new/path/pdf_sealer.zip
test ! -e "$pdf_sealer_zip" && (cd "$pdf_sealer_stage" && zip -qr "$pdf_sealer_zip" .)
pdf_sealer_verify=$(mktemp -d)
unzip -q "$pdf_sealer_zip" -d "$pdf_sealer_verify"
php tools/third-party-notices.php "$pdf_sealer_verify"
```

The ZIP contains module source, the unchanged root MIT license, generated third-party notices, complete GPLv3 text, all package source and original LGPL licenses, Composer metadata/runtime and MIT license, and applicable modification notices. Keep dependencies as editable PHP source. Any future obfuscation, namespace rewriting, binary bundling, or additional distribution restrictions need a fresh review.

The check requires a matching reviewed checkout; the development checker and review manifest are deliberately excluded from the release. Run this procedure with the same commit's tooling. Keep the development directory named `pdf_sealer_v9.9.9`; public release naming is separate.

## Verification of this slice

On 2026-09-27, a disposable staging directory was assembled from the current Git archive plus the new README, notices, and GPL text (not yet committed). A clean production Composer install passed the checker. Its 130-file ZIP was extracted and passed the same check; dependency licenses/source were present and development docs, tests, tools, and fixtures were absent. No release was published.

Separate disposable mutations confirmed that the check rejects a missing package license, an undocumented source edit, stale notices, an installed-version mismatch, an unlisted vendor library, and a package marked modified without `MODIFICATIONS.md`. PHP lint and `git diff --check` passed; root `LICENSE` was verified identical to HEAD. The installed Composer 2.7.1 emitted deprecation notices under PHP 8.5 but completed successfully. PDF functionality was not changed or retested for this documentation/packaging slice.
