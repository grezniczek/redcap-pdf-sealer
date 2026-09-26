# Core pipeline and stored-PDF acceptance

## Automated Core/Framework check

Run only against a development instance, using an existing synthetic test project:

```sh
PDF_SEALER_LIVE_TEST=1 PDF_SEALER_TEST_PID=461 php tests/pdf_pipeline_live.php --preview
PDF_SEALER_LIVE_TEST=1 PDF_SEALER_TEST_PID=461 php tests/pdf_pipeline_live.php --run
```

`PDF_SEALER_REDCAP_ROOT` optionally selects the Core checkout (default `/home/gr/redcap/codebase`). The instance is determined by that checkout's REDCap configuration. The preflight prints the selected PID and expected profile and refuses uninitialized/unusable PKI, an unissued project signer, extra pipeline operations, or nontransactional tables. It never creates or repairs identities or changes settings. Review the preview before running.

The harness uses the five synthetic fixtures from `pdf_fixture_coverage.md`. A separate PHP process generates them using the installed REDCap PDF backend. The live process loads real REDCap and invokes both `PdfFinalizer::finalize()` and `PdfFinalizer::finalizeContents()`, which resolve the project execution plan and dispatch the actual EM hook.

Checks include:

- Original input bytes remain unchanged; the file entry point adopts a separate working file.
- Both entry points return sealed content accepted by qpdf, pdfsig, and OpenSSL.
- In configured B-T mode, the timestamp extracted from the finished PDF verifies against the actual CMS signature bytes; a silent B-B fallback fails this test.
- All rendered pages, text, footer links, and link rectangles are preserved.
- Framework events report the expected profile, terminal result, one accepted modification, and the SHA-256 of the returned artifact.
- A `record_pdf` context bypasses the eConsent-only sealer without changing bytes.
- An already-certified PDF produces a controlled failure; the Framework retains the input.

Framework hook dispatch rolls back before and after every hook. The harness therefore keeps **autocommit disabled** for the test, then rolls back and restores autocommit. An outer START TRANSACTION alone is insufficient. Project/EM test log writes and project activity updates are discarded. The harness checks for surviving test logs after rollback; it does not assert durable Logging entries. The existing direct-hook test covers log contents/record-event association separately. Framework diagnostic events are captured in a temporary local log and deleted with the generated fixtures. No edoc or email is created.

**2026-09-26 result:** all five fixtures passed through both entry points in PID 461 using configured PAdES B-T; both negative cases and cleanup checks passed. Independent dev-control inspection confirmed no synthetic project logs remained. This verifies the finalization handoff but does not invoke snapshot persistence, file upload, or an HTTP download.

## Browser step: saved snapshot and delivery

1. In PID 461, complete a new synthetic eConsent with a drawn test signature. Use only test data. Note the record ID and, if relevant, event/repeat instance.
2. Locate its **saved eConsent snapshot** in the File Repository/archive and download that exact artifact. Do not use a fresh Print/PDF export for this byte comparison: regeneration can create a new certificate signature and timestamp even when the visible content is unchanged.
3. Place the downloaded file at an accessible path and provide the record ID and path. The stored snapshot's edoc ID is useful if visible but is not required.
4. In Acrobat, confirm certification without a modification warning, the visible signature image, the footer link, and an embedded timestamp. Report certificate trust/revocation findings separately.

The agent then uses `redcap_devctl` to identify and inspect the matching stored edoc, calculate its SHA-256, and export a read-only copy to a temporary local file. It compares stored and downloaded bytes, checks size/MIME consistency, verifies the complete-document signature and embedded timestamp, and correlates the concise sealing log with the record/event. No existing edoc is replaced or resealed. Temporary exports are removed after verification; the user's downloaded copy is retained unless asked otherwise.

A matching hash verifies that downloading preserved the stored artifact. A valid whole-document signature on those bytes verifies that storage/delivery did not invalidate the seal. It does not prove every other PDF pathway behaves identically. Attachment-heavy eConsent workflows and confirmation-email attachment delivery need separate acceptance if they are in the v1 deployment scope. Sending test email requires explicit authorization.

**Status:** saved/downloaded artifact comparison is pending user input. No full PAdES/DSS validation is claimed by the CLI checks.
