# PDF Sealer

PDF Sealer is a REDCap External Module that adds cryptographic certification signatures to completed eConsent PDFs. It helps recipients check document integrity and identify the project's sealing certificate. Optional embedded timestamps are issued by the REDCap installation's own timestamp authority.

The module is a reference implementation progressing toward v1.

## Documentation

| Audience | Start here |
| --- | --- |
| Project users and project managers | [Project guide](docs/PROJECT.md): activation, status, Logging, and certificate links |
| REDCap administrators | [Administrator guide](docs/ADMIN.md): prerequisites, PKI setup, timestamp settings, diagnostics, and alarms |
| Readers seeking technical background | [Certificates and PKI](docs/pki.md) and [sealing, timestamps, and validation](docs/sealing-and-validation.md) |
| Developers and contributors | [Developer documentation](https://github.com/grezniczek/redcap-pdf-sealer/tree/main/DEV_DOCS): implementation, tests, acceptance evidence, and release maintenance |

In REDCap, the module's documentation link opens the project or administrator guide according to context. The guides and technical references are included in installation packages; developer documentation is available in the repository.

## What it provides

- An installation-specific root certificate authority, dedicated timestamp authority, and pseudonymous project sealing identities.
- PAdES B-T sealing with an embedded timestamp, or B-B sealing without one, with configurable fallback.
- Invisible certification signatures that preserve document appearance and clickable links.
- Project status and concise sealing outcomes in REDCap Logging.
- Control Center diagnostics and PKI alarm settings, plus public root-certificate downloads.

A seal identifies the issuing project and organization; it does not establish the identity of the consenting person. The current operation applies to eligible completed eConsent PDFs and does not retroactively seal existing documents or ordinary record/form downloads.

## Before using it

**PHP 8.4 or later in the PHP 8 series is recommended.** The minimum is PHP 8.2. PHP 8.4+ supports random 128-bit certificate serials; PHP 8.2/8.3 uses integer serials reserved through the EM Framework. See [certificate serials and recovery](docs/pki.md#certificate-serials).

The installation also needs the required PHP extensions and bundled PHP libraries, and REDCap Core/Framework support for the PDF finalization pipeline. See the [administrator guide](docs/ADMIN.md) for the full prerequisites. The module must be enabled and its sealing operation assigned to the project's pipeline.

A failed sealing operation leaves the preceding PDF available for REDCap to store or deliver. A self-issued certificate is not automatically trusted by viewers, and an embedded timestamp does not by itself provide long-term validation. Automatic certificate renewal/rotation, revocation publication, and PAdES B-LT/B-LTA are not implemented.

## Acknowledgment

PDF Sealer was developed with assistance from OpenAI's ChatGPT and Codex, including planning, implementation, testing, and documentation.

## License

PDF Sealer is licensed under the [MIT License](LICENSE). Bundled third-party software has separate licenses; see [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md) for the inventory, attribution, and original license locations.
