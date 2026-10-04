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

## Core ownership refactor — 2026-10-04

The extended `tests/pdf_pipeline_live.php` passes on PHP 8.2.34/8.5.11 in PID 524 with the refactored Core coordinator, Framework provider and real Sealer hook. Both entry points preserve all five fixtures' rendering/text/links and produce independently verified B-T signatures/timestamps with matching final-byte hashes. Under a synthetic reserved Core policy, Sealer returns nonterminal unchanged, Core adopts its own result last, and required Core failure prevents commitment. The synthetic Core operation is a test action, not a Core sealing implementation. Transactional test logs are rolled back and generated files removed; existing signer/provider state is preserved. Details are in [testing](testing.md#native-coreframework-acceptance--2026-10-04).

This verifies the native finalization handoff after refactoring. The fresh record 10 download/Acrobat checks are recorded below; its stored-byte hash comparison remains pending. Earlier saved-artifact/Acrobat results predate the refactor. No new edoc, record, external TSA request or email was created by the automated harness.

## Browser step: saved snapshot and delivery

For the current ownership-refactor acceptance, use **PID 524 (PDF Sealer Test)** with its existing **Example Survey** (`survey`) eConsent workflow and single saved `pdf_sealer:seal` assignment. The earlier PID 461 results below remain historical evidence.

1. In PID 524, complete a new synthetic eConsent through Example Survey with a drawn test signature. Use only test data. Note the record ID and, if relevant, event/repeat instance.
2. Locate its **saved eConsent snapshot** in the File Repository/archive and download that exact artifact. Do not use a fresh Print/PDF export for this byte comparison: regeneration can create a new certificate signature and timestamp even when the visible content is unchanged.
3. Place the downloaded file at an accessible path and provide the record ID and path. The stored snapshot's edoc ID is useful if visible but is not required.
4. In Acrobat, confirm certification without a modification warning, the visible signature image, the footer link, and an embedded timestamp. Report certificate trust/revocation findings separately.

The agent then uses `redcap_devctl` to identify and inspect the matching stored edoc, calculate its SHA-256, and export a read-only copy to a temporary local file. It compares stored and downloaded bytes, checks size/MIME consistency, verifies the complete-document signature and embedded timestamp, and correlates the concise sealing log with the record/event. No existing edoc is replaced or resealed. Temporary exports are removed after verification; the user's downloaded copy is retained unless asked otherwise.

A matching hash verifies that downloading preserved the stored artifact. A valid whole-document signature on those bytes verifies that storage/delivery did not invalidate the seal. It does not prove every other PDF pathway behaves identically. Attachment-heavy eConsent workflows and confirmation-email attachment delivery need separate acceptance if they are in the v1 deployment scope. Sending test email requires explicit authorization.

## Record 10 post-refactor download verification — stored hash pending

On 2026-10-04 the user added a drawn-signature field to PID 524's Example Survey (it had no signature field), completed a new synthetic eConsent for record **10**, and downloaded `C:\Users\grezn\Downloads\pid524_formExampleSurvey_id10_2026-10-04_234310.pdf`. The user reports Acrobat accepted it as certified, timestamped and without warnings, with the Root CA already trusted. This is user-reported Acrobat acceptance.

Read-only `redcap_devctl` database inspection identifies edoc **2378**, event **1589**, survey **1016**, instance **1**, snapshot **268**, with `contains_completed_consent=1`. Metadata reports **71,947 bytes**, `application/pdf`, and storage time **2026-10-04 23:43:09** in the instance's local time. The download matches the recorded filename and size. Native project log **1291** records `PDF seal succeeded`, `Profile: PAdES B-T`, for record 10/event 1589 at that time.

The downloaded file's SHA-256 is:

```text
30f0ad6f1268411b8c796a7ceac76f09461f58f5925d8d49c916e52484bd70b1
```

Independent verification of the downloaded bytes passes:

- qpdf accepts the PDF, and pdfsig recognizes one valid `ETSI.CAdES.detached` signature covering the complete file. ByteRange is `[0, 38444, 71214, 733]`; DocMDP is P=1 and the signature widget references that certification signature.
- OpenSSL verifies the detached CMS and its certificate chain. The embedded root matches the independently queried public identity inventory, SHA-256 `089f702cc4d8610aceff0bee43f40f8a789758790ccd22352fd2ea4e2ebf13d8`. No private-key fields were queried or decrypted. A modified protected byte fails signature verification.
- One embedded RFC 3161 signature timestamp verifies with OpenSSL against the actual CMS signature bytes and the same root. Generation time is **2026-10-04 21:43:10 UTC**, serial `DC7F51F7941D964B755D464DDD6EC082`, policy `2.25.186172099785128831488612506224552954430`.
- The preceding unsigned revision is retained (38,037 bytes). Its one-page Poppler render and extracted text match the finished PDF, and its single footer link target/rectangle is preserved. The PDF contains the drawn-signature image and its transparency mask.

The stored/downloaded byte comparison is **pending**. `edoc_inspect`, `edoc_hash`, and `edoc_export` again fail with “The REDCap CLI did not return a valid JSON envelope”; the failed export leaves no output file. Matching metadata and a valid download do not independently establish equality with stored bytes. The tool enhancement noted below remains needed: expose sanitized underlying CLI diagnostics and restore read-only edoc inspection/hash/export.

Database metadata identifies the stored file as `/home/gr/edocs/20261004234310_pid524_e2WGZq.pdf`. A user-performed read-only hash can complete this check without changing permissions or the artifact:

```sh
sudo sha256sum /home/gr/edocs/20261004234310_pid524_e2WGZq.pdf
```

The agent made no live mutations. Temporary copies, certificate material and verification files are removed after verification; the user's download is retained. The downloaded artifact and browser/Acrobat pathway pass, while stored-byte equality remains an explicitly outstanding check.

## Record 18 stored/downloaded acceptance — passed

On 2026-09-26 the user completed a test eConsent in PID 461, record 18, and supplied the saved snapshot download `pid461_formForm1_id18_2026-09-26_190215.pdf`. The user also confirmed that Acrobat accepted the file.

Database inspection through `redcap_devctl` identified the archive entry as edoc **2335**, event **1423**, instance **1**, snapshot **266**, with `contains_completed_consent=1`. Its metadata reports **89,378 bytes**, MIME type `application/pdf`, stored at **2026-09-26 19:02:15** in the instance's local time. The downloaded file is also 89,378 bytes. Project Logging contains B-T success entries for record 18 associated with event 1423 at that time (log IDs 1328 and 1330).

The downloaded file's SHA-256 is:

```text
f5d84731e23e4d15c73d9596457a381decd6f9d225b5613ebefef24e6e110594
```

The user ran `sudo sha256sum` on the stored archive file identified by the database metadata and returned **the same hash**. Thus the stored snapshot and download match; the stored-file digest was supplied by the user rather than obtained directly by the agent.

Independent checks on the downloaded bytes passed:

- qpdf found no syntax or stream-encoding errors.
- pdfsig recognized one valid `ETSI.CAdES.detached` signature covering the complete file.
- Structural checks confirmed DocMDP P=1 and the signature widget/field; the original unsigned revision is preserved.
- OpenSSL verified the detached CMS and its chain against the embedded root whose SHA-256 matched the public certificate fingerprint read independently from the module's PKI records: `3b46357df86ae4d145fc2a4dc393c308cbb035a7857ecbe387c8fa7d39764734`.
- OpenSSL verified the embedded RFC 3161 token and its message imprint against the actual CMS signature bytes. The TSA is `REDCap PDF Sealer Timestamp Authority`; generation time is **2026-09-26 17:02:15 UTC** and policy is the built-in `2.25.186172099785128831488612506224552954430`.
- The one-page PDF contains the signature image and one HTTPS footer link. Poppler rendering at 72 dpi and extracted text match the preceding unsigned revision; the footer target and rectangle are preserved.
- The user confirmed Acrobat acceptance for this exact download.

No edocs, project data, or PKI settings were modified. Temporary verification files were removed, and the user's downloaded copy was retained. This completes the saved-snapshot/download acceptance for this eConsent pathway; it is not a full PAdES/DSS conformance claim or proof of email/other storage pathways.

### Dev-control tool limitation encountered

`edoc_inspect`, `edoc_hash`, and `edoc_export` failed with “The REDCap CLI did not return a valid JSON envelope.” Database queries remained available. Direct reads of the identified edoc were denied by filesystem permissions, and passwordless sudo was unavailable. The user's stored-file hash closed the comparison without changing permissions. The edoc tools should expose the underlying CLI error in a sanitized diagnostic so this failure can be investigated; their envelope/export failure remains unresolved.

## PHP 8.2 first-project acceptance — PID 524

On 2026-09-27, following the certificate-serial compatibility fix, the user reported that all Control Center diagnostic checks pass. With the web runtime previously reported as PHP 8.2.34, they created **PID 524**, enabled PDF Sealer, configured eConsent, submitted a survey for **record 1**, and downloaded the resulting PDF from the **File Repository**. The user confirms Acrobat accepted it as **certified and not modified**.

Read-only database inspection through `redcap_devctl`, followed by OpenSSL parsing of the stored public certificate, established:

| Evidence | Value |
| --- | --- |
| Project UUID | `08246aca-8f7d-4e44-8be1-293cd171d9d1` |
| Integer serial reservation | EM log **35937**, purpose `issuance`, role `project` |
| Certificate identity record | EM log **35938**, identity `41754b15ec74d6b2ec773d511f6b0236` |
| Active project binding | EM log **35939** |
| Certificate serial | **35937**, hexadecimal **8C61**; matches the reservation |
| Certificate SHA-256 | `5ea92dd263b4bc7d4495263ea5f3953f0a0349a8751ecffcaeea8d7f3fec73d9` |
| Issuing-root SHA-256 recorded in reservation | `3b46357df86ae4d145fc2a4dc393c308cbb035a7857ecbe387c8fa7d39764734` |
| Project Logging | Entry **1033**, `PDF seal succeeded`, **PAdES B-B**, record **1**, event **1589** |
| Logged workflow time | **2026-09-27 13:07:15**, instance local time; identity/binding persisted by **13:07:16** |

This completes the requested live acceptance of the PHP 8.2 integer-serial path: a new project certificate was issued, used in the eConsent workflow, and the downloaded artifact was accepted by Acrobat. It also confirms that the success log retains record/event context. The Control Center diagnostic tests both B-B and B-T; this specific live PDF was logged as **B-B**, so it is not additional live timestamp evidence.

The downloaded PDF was not supplied for independent analysis. No stored/downloaded hash comparison, new DSS validation, or concurrent-issuance test was performed in this round. The stored public certificate was parsed without reading private-key material. Database access was read-only; no edocs, project data, or PKI settings were modified by the agent.
