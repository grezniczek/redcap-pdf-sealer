# Dependency builds, notices, and release packaging

## Distribution model

The committed `libraries/` tree contains three source libraries: `tecnickcom/tc-lib-pdf-filter` 2.11.3, `tecnickcom/tc-lib-pdf-parser` 3.16.1, and `tecnickcom/tc-lib-pdf-sign` 2.0.4. All remain `LGPL-3.0-or-later`. `libraries/manifest.json` records versions, exact upstream revisions, attribution, and license fingerprints without including Composer metadata.

All library namespaces and references are prefixed with `DE\RUB\PDFSealerExternalModule\Dependencies\`. The module-owned root `autoload.php` loads both module classes and these private dependency classes. No global `Com\Tecnick` aliases are registered. The prefix is module-specific, not tied to the development directory version.

Composer is a development-only way to obtain the reviewed upstream source. Root `composer.json`, `composer.lock`, ignored `vendor/`, and `tools/` are excluded by `.gitattributes`. **No Composer runtime, autoloader, manifests, or lockfile is shipped.** Ordinary module use and standalone tests load the committed bundle without Composer. Do not copy `vendor/` into a release.

## Licensing and modifications

The original unmodified package contents were compared byte-for-byte with cached Composer distribution archives for their pinned revisions on 2026-09-27. Those input tree hashes remain in `tools/third-party-review.json`.

The distributed copies are now **modified**. Each PHP source file retains its upstream copyright/license header and gains a dated PDF Sealer modification notice. Each package includes `MODIFICATIONS.md` describing namespace and reference prefixing, PHPDoc changes, and omission of upstream Composer metadata and installation README. The signing library also has a reviewed opt-in legacy ESSCertID SHA-1 compatibility change for external TSA tokens. It does not enable SHA-1 content digests or signature algorithms. The reproducible build applies this exact change after namespace prefixing. All upstream runtime PHP source is retained, including code not currently used by the module, along with original `LICENSE`, `VERSION`, and `SECURITY.md` files.

The original license texts contain LGPLv3, which incorporates GPLv3. `licenses/GPL-3.0.txt` supplies the complete accompanying GPLv3 text required by [LGPLv3 section 4(b)](https://www.gnu.org/licenses/lgpl-3.0.html); it was copied unchanged from `/usr/share/common-licenses/GPL-3`. Root `LICENSE` remains the unchanged MIT license for PDF Sealer. Composer's MIT runtime notice was removed from the distribution inventory because its code is no longer shipped. Module code and its new autoloader are covered by the root MIT license.

## Reproduce the bundle

Run from the module root with PHP 8.2+ and Composer available for the development step:

```sh
composer install --no-dev --prefer-dist --no-interaction --no-scripts --no-plugins
php tools/build-dependencies.php
```

The default build command checks reproducibility without writing. It verifies the installed package set, versions, pinned revisions, complete upstream tree hashes, and package licensing against the reviewed development inputs. It then compares the expected output byte-for-byte with `libraries/`. It does not execute upstream code.

For an intentional rebuild:

```sh
php tools/build-dependencies.php --write
php tools/third-party-notices.php
```

The build is deterministic: it uses the reviewed modification date rather than the current clock, stable file ordering, and fixed input revisions. It tokenizes PHP to prefix qualified names while retaining global PHP classes/functions, adjusts PHPDoc references, and adds modification notices. Reviewed functional patches in tools/third-party-review.json require exact input/output SHA-256 hashes and unique replacement contexts; missing targets fail the build. It refuses unexpected dynamic Tecnick class strings, include/eval/resource-path constructs, and unknown package files. This is a small transformer for these pinned and audited packages, not a general-purpose PHP namespace isolation tool. New package versions require review.

`--write` overwrites generated bundle files. Do not hand-edit `libraries/`; make deliberate source changes reproducible in the build first. Extra or stale files cause verification to fail and require inspection; the builder does not silently delete them.

## Dependency upgrades and review

1. Update development dependencies deliberately. Compare the exact pinned distributions, their source, copyright/license terms, resource access, and dynamic loading behavior. Refresh the input revisions/tree hashes in `tools/third-party-review.json` only after review.
2. Adjust the scoped transformer if necessary. Document any functional change in package modification records and source notices. Preserve original license texts. Update the modification date for the new build.
3. Generate the bundle, review its diff, and update `bundled_tree_sha256` in `tools/third-party-review.json` only after that review. A tree hash is SHA-256 of concatenated lines `file_sha256 + two spaces + relative_path + LF`, sorted by relative path in byte order. The bundle hash covers every file under `libraries/`, including its manifest and modification records. `PDFSealerBuild\digest(PDFSealerBuild\files($directory))` in `tools/dependency-build.php` implements this calculation.
4. Run `php tools/third-party-notices.php --write`, inspect the notice, then run both check commands again. Notice versions and attribution come from the generated bundle manifest and are checked against the matching checkout's lockfile/review metadata.
5. Run the isolation test and affected signing/timestamp tests. Never relabel a modified library as pristine to make a check pass.

The notices checker checks package sets and metadata, retained license fingerprints, modification records, the entire reviewed bundle, and notice freshness. It accepts an unpacked release path and reads development review metadata from the calling checkout; it does not require Composer files inside that release. For an external package directory it rejects `vendor/`, `tools/`, and Composer manifests/lockfiles/PHARs anywhere in the tree. It is a consistency gate, not a general license scanner or proof of legal compliance.

## Release packaging

Commit the intended source, generated libraries, manifest, notices, and documentation together. Git archives now include the complete runtime. A small release procedure is sufficient:

```sh
php tools/build-dependencies.php
php tools/third-party-notices.php
pdf_sealer_zip=/absolute/new/path/pdf_sealer.zip
# Use a new output filename; stop if any command fails.
test ! -e "$pdf_sealer_zip" && git archive --format=zip --output="$pdf_sealer_zip" HEAD
pdf_sealer_verify=$(mktemp -d)
unzip -q "$pdf_sealer_zip" -d "$pdf_sealer_verify"
php tools/third-party-notices.php "$pdf_sealer_verify"
PDF_SEALER_PACKAGE_ROOT="$pdf_sealer_verify" php tests/dependency_isolation.php
```

Use the same commit's tooling to inspect the package. The archive must contain `autoload.php`, module source, all `libraries/` files and modification/license records, root `LICENSE`, `THIRD_PARTY_NOTICES.md`, `licenses/GPL-3.0.txt`, root README, and packaged `docs/`. Check all three configured documentation targets and their relative links.

The archive must exclude Composer files/runtime, `vendor/`, developer tools/tests, `DEV_DOCS/`, generated PDF/certificate fixtures, and editor/Git files. The development directory remains `pdf_sealer_v9.9.9`; public release naming is separate. No release is published by these commands.

## Verification boundaries

The original licensing slice tested a Composer-containing staging package. That packaging model has been superseded by the committed, prefixed source bundle described here. The 2026-10-03 readiness review found that the accepted 2026-09-28 legacy ESS compatibility change had not been incorporated into the build recipe or reviewed bundle hash. It is now reproduced exactly, recorded in the generated manifest and accurately disclosed in the notices. Runtime library PHP and its dated modification record are unchanged by this repair. See [v1 readiness](release_readiness.md).

Current isolation tests use deliberately incompatible stand-ins for every upstream class name, in separate processes for both load orders, and check that all 48 bundled symbols come from `libraries/`. They also exercise the module adapter and can run against an extracted package with no Composer files.

The standalone suites validate functionality through the same module-owned loader. The [testing guide](testing.md) and [implementation status](implementation_status.md) record current coverage and results. No changes to Core/Framework or stored PKI are needed for this dependency migration.
