# Third-party notices

PDF Sealer is licensed under the MIT License. This distribution also includes third-party software that is licensed separately. The licenses listed below apply to their respective components and not to PDF Sealer as a whole.

PDF Sealer uses the libraries below under LGPL-3.0-or-later. Their original license texts and copyright notices are retained alongside their complete PHP source. The accompanying [GNU GPL version 3](licenses/GPL-3.0.txt) is also included, as required by LGPLv3 section 4(b). Neither these notices nor PDF Sealer's MIT license restrict modification of the libraries or reverse engineering to debug those modifications. The libraries remain separate, replaceable PHP sources in `vendor/`; the module source is supplied too.

## tecnickcom/tc-lib-pdf-filter

- Version: 2.11.3
- Upstream: [tecnickcom/tc-lib-pdf-filter](https://github.com/tecnickcom/tc-lib-pdf-filter)
- Source revision: `cefbf314e3c2749ede5470a8240535ff82646a06`
- License: LGPL-3.0-or-later
- Author: Nicola Asuni
- Copyright: 2011-2026 Nicola Asuni - Tecnick.com LTD
- Original license: [vendor/tecnickcom/tc-lib-pdf-filter/LICENSE](vendor/tecnickcom/tc-lib-pdf-filter/LICENSE)
- Bundled copy: unmodified (including original namespaces).

## tecnickcom/tc-lib-pdf-parser

- Version: 3.16.1
- Upstream: [tecnickcom/tc-lib-pdf-parser](https://github.com/tecnickcom/tc-lib-pdf-parser)
- Source revision: `4c3596dc3bf2e86e435d4d8f6de782a0755d7b4f`
- License: LGPL-3.0-or-later
- Author: Nicola Asuni
- Copyright: 2011-2026 Nicola Asuni - Tecnick.com LTD
- Original license: [vendor/tecnickcom/tc-lib-pdf-parser/LICENSE](vendor/tecnickcom/tc-lib-pdf-parser/LICENSE)
- Bundled copy: unmodified (including original namespaces).

## tecnickcom/tc-lib-pdf-sign

- Version: 2.0.4
- Upstream: [tecnickcom/tc-lib-pdf-sign](https://github.com/tecnickcom/tc-lib-pdf-sign)
- Source revision: `78c8c20aae5a16ccd335e1c8f528f9335bdcc40f`
- License: LGPL-3.0-or-later
- Author: Nicola Asuni
- Copyright: 2011-2026 Nicola Asuni - Tecnick.com LTD
- Original license: [vendor/tecnickcom/tc-lib-pdf-sign/LICENSE](vendor/tecnickcom/tc-lib-pdf-sign/LICENSE)
- Bundled copy: unmodified (including original namespaces).

## Composer autoloader and runtime

- Upstream: [Composer](https://github.com/composer/composer)
- Version: generated installation support; Composer does not record the generator version in the shipped metadata. These files are not a separately locked package.
- License: MIT
- Copyright: Nils Adermann, Jordi Boggiano
- ClassLoader authors: Fabien Potencier, Jordi Boggiano
- Original license: [vendor/composer/LICENSE](vendor/composer/LICENSE)
- Bundled copy: Composer-generated autoload maps and installation metadata, with upstream runtime classes; no PDF Sealer source edits.

## Distribution scope

PHP and its extensions, REDCap, the External Module Framework, and development validation tools such as qpdf and Poppler are host dependencies, not bundled components of this distribution. No third-party browser library is bundled.

Package contents were compared byte-for-byte with the Composer source-distribution archives for the pinned revisions on 2026-09-27. No library is namespace-prefixed or otherwise modified, so no library modification notices are needed. PDF Sealer's `PolicyOidAsn1` adapter resides in the module's own source; the upstream library remains unchanged.
