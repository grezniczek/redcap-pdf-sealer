# Synthetic PDF fixture coverage

## Running the suite

```sh
PDF_SEALER_REDCAP_ROOT=/home/gr/redcap/codebase php tests/pdf_redcap_fixtures.php
```

The default Core path is `/home/gr/redcap/codebase`. A missing backend or tool fails the suite rather than silently skipping coverage. Requirements: PHP GD, qpdf, OpenSSL, and Poppler's `pdfsig`, `pdfimages`, `pdftoppm`, and `pdftotext`.

Fixtures are generated from synthetic text and an artificial zigzag PNG. No participant data or signature is used. REDCap's installed `tFPDF.php`, `FPDF_HTML.php`, and `PDF::setFooterImage()` generate the initial PDFs; only footer date formatting and the `System::powered_by_redcap` constant have isolated test substitutes. The generator uses built-in Arial/Helvetica fonts, avoiding font-cache writes. It does not call `PDF::output()`/`renderPDF()`, initialize a project, invoke the Framework hook, or access the database/edocs. qpdf produces the merged variants, not a REDCap merge workflow.

## Matrix

| Fixture | Pages | Footer links | Additional coverage |
| --- | ---: | ---: | --- |
| consent-signature | 1 | 1 | Transparent 240×80 signature PNG and soft mask |
| consent-multipage | 3 | 3 | Signature on final page; footer link on every page |
| consent-no-footer-link | 2 | 0 | Core's footer suppression setting |
| merged-attachment | 2 | 1 | Consent plus landscape attachment merged with qpdf |
| merged-object-streams | 2 | 1 | Merged PDF with rotated second page, object streams, and xref stream |

Every case is sealed separately as B-B and B-T with disposable root, project-profile, and TSA identities. All ten combinations passed on 2026-09-26.

## Assertions

- The original PDF remains an exact prefix of the sealed PDF.
- qpdf accepts both input and output without repair warnings.
- The expected certification field, widget, DocMDP P=1, and whole-document ByteRange are present.
- Poppler `pdfsig -nocert -no-ocsp` recognizes one valid ETSI.CAdES.detached signature covering the complete output.
- OpenSSL verifies the detached CMS and its chain to the disposable root; changing a covered byte invalidates verification.
- The B-T request imprint hashes the actual CMS signature bytes; returned token serial/time match the seal result.
- OpenSSL independently verifies the RFC 3161 response against the request and, separately, the timestamp token extracted from the finished PDF against the CMS signature bytes.
- Poppler confirms the synthetic signature image exists in every input. Every page renders pixel-identically at 72 dpi before and after sealing; extracted text is identical.
- Page order, footer URI targets, and link rectangles are unchanged. Link storage may change from inline to indirect on the first page under the existing sealer workaround.
- Fixture PDFs, image files, certificate configuration files, and render outputs are removed in cleanup. Private keys remain in memory.

Verification helpers under `tests/support/` are shared with `tests/pdf_seal_bb.php` and `tests/pdf_seal_bt.php`. The original suite also passed on its eight available generated/local inputs after extraction of those helpers.

Tools used: qpdf 11.9.0, Poppler 24.02.0, OpenSSL 3.0.13.

## Remaining acceptance boundaries

This confirms local structure, cryptography, and content preservation. It does not establish complete PAdES compliance, viewer trust, revocation/LTV, or EU DSS acceptance. Existing live eConsent/Acrobat acceptance remains valid, but these particular multipage/merged synthetic outputs have not been checked in Acrobat or DSS. Chinese/Japanese backends and embedded Unicode font coverage remain outside this matrix.

The next integration step is exercising representative signature-image and attachment PDFs through the real Framework finalization lifecycle, including checking the final stored/delivered bytes. Broader Acrobat/DSS acceptance should use explicitly disposable synthetic documents.
