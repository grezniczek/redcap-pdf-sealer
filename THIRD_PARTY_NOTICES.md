# Third-party notices

PDF Sealer is licensed under the MIT License. This distribution also includes third-party software that is licensed separately. The licenses listed below apply to their respective components and not to PDF Sealer as a whole.

PDF Sealer uses the libraries below under LGPL-3.0-or-later. Their original license texts and copyright notices are retained alongside their complete PHP source. The accompanying [GNU GPL version 3](licenses/GPL-3.0.txt) is also included, as required by LGPLv3 section 4(b). Neither these notices nor PDF Sealer's MIT license restrict modification of the libraries or reverse engineering to debug those modifications. The libraries remain separate, replaceable PHP sources in `libraries/`; the module source and its own autoloader are supplied too.

## tecnickcom/tc-lib-pdf-filter

- Version: 2.11.3
- Upstream: [tecnickcom/tc-lib-pdf-filter](https://github.com/tecnickcom/tc-lib-pdf-filter)
- Source revision: `cefbf314e3c2749ede5470a8240535ff82646a06`
- License: LGPL-3.0-or-later
- Author: Nicola Asuni
- Copyright: 2011-2026 Nicola Asuni - Tecnick.com LTD
- Original license: [libraries/tecnickcom/tc-lib-pdf-filter/LICENSE](libraries/tecnickcom/tc-lib-pdf-filter/LICENSE)
- Bundled copy: modified; see [libraries/tecnickcom/tc-lib-pdf-filter/MODIFICATIONS.md](libraries/tecnickcom/tc-lib-pdf-filter/MODIFICATIONS.md).

## tecnickcom/tc-lib-pdf-parser

- Version: 3.16.1
- Upstream: [tecnickcom/tc-lib-pdf-parser](https://github.com/tecnickcom/tc-lib-pdf-parser)
- Source revision: `4c3596dc3bf2e86e435d4d8f6de782a0755d7b4f`
- License: LGPL-3.0-or-later
- Author: Nicola Asuni
- Copyright: 2011-2026 Nicola Asuni - Tecnick.com LTD
- Original license: [libraries/tecnickcom/tc-lib-pdf-parser/LICENSE](libraries/tecnickcom/tc-lib-pdf-parser/LICENSE)
- Bundled copy: modified; see [libraries/tecnickcom/tc-lib-pdf-parser/MODIFICATIONS.md](libraries/tecnickcom/tc-lib-pdf-parser/MODIFICATIONS.md).

## tecnickcom/tc-lib-pdf-sign

- Version: 2.0.4
- Upstream: [tecnickcom/tc-lib-pdf-sign](https://github.com/tecnickcom/tc-lib-pdf-sign)
- Source revision: `78c8c20aae5a16ccd335e1c8f528f9335bdcc40f`
- License: LGPL-3.0-or-later
- Author: Nicola Asuni
- Copyright: 2011-2026 Nicola Asuni - Tecnick.com LTD
- Original license: [libraries/tecnickcom/tc-lib-pdf-sign/LICENSE](libraries/tecnickcom/tc-lib-pdf-sign/LICENSE)
- Bundled copy: modified; see [libraries/tecnickcom/tc-lib-pdf-sign/MODIFICATIONS.md](libraries/tecnickcom/tc-lib-pdf-sign/MODIFICATIONS.md).

## Distribution scope

The libraries' PHP namespaces and references are prefixed with `DE\RUB\PDFSealerExternalModule\Dependencies\` to isolate them from other REDCap modules. Modified source files carry dated notices; package-level modification records describe the changes and omitted upstream installation metadata. The signing library also includes a reviewed opt-in legacy ESSCertID SHA-1 compatibility change for external TSA tokens; content digests and signature algorithms remain restricted. See that package's modification record.

Composer is used only during development to obtain pinned upstream source. No Composer autoloader, runtime, manifests, or lockfile is included in the module distribution. PHP and its extensions, REDCap, the External Module Framework, and development validation tools such as qpdf and Poppler are host dependencies, not bundled components. No third-party browser library is bundled.
