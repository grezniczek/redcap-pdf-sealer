# PDF finalizer live acceptance — 2026-09-23

This is test-environment evidence for the `redcap_pdf_finalize` implementation. The live runs below predate the byte-changing demo watermark and logged `pdf_sealer:watermark` as `unchanged`.

## Setup

- Project: PID 461, Watermarked Signatures EM.
- Survey: Form 1, survey ID 964, Event 1 (event ID 1423), with e-Consent enabled.
- Snapshot: ID 266, triggered by survey completion, with e-Consent ID 169. It stores one PDF in the File Repository and another in the `fileupload` field in Event 2 (event ID 1465).
- Non-e-Consent test: Form 2 survey 1006 in Event 2 (event ID 1465); snapshot 267 is scoped to Form 2/Event 2 and saves to the File Repository only.
- Project PDF finalization plan: one `pdf_sealer:watermark` occurrence.
- The participant completed the surveys (and Form 1 e-Consent) in the browser. The checks below inspected database rows, edoc files, and PHP finalizer logs without changing the completed records.

## Initial attempt: record 9

Record 9 completed the survey at 22:02:23 Europe/Berlin. The File Repository commit succeeded: archive edoc 2228 contained a one-page PDF with SHA-256 `9ad85eef075aab1145940592610d89f96d402de5cd154ac3e70675f7ff4b7706`. The separately generated PDF for the field target was uploaded as edoc 2229, but its artifact commit failed; `fileupload` remained the participant's uploaded image (edoc 2227).

The cause was project configuration, not finalizer execution: snapshot 266 targeted Event 2, while Form 1, which owns `fileupload`, was then assigned only to Event 1. A non-writing `Records::saveData` validation for the exact Event 2 field write returned: `This field ('fileupload') exists on an instrument that is not designated for the event named 'Event 2'.`

## Retest after configuration correction: record 10

Form 1 was assigned to Event 2. Record 10 completed survey 964 at 22:15:35 Europe/Berlin. The snapshot's archive row has `contains_completed_consent = 1` and points to File Repository PDF edoc 2232. The Event 2 `fileupload` value points to PDF edoc 2233. Both artifact commits were reported as `artifact_committed`; no `artifact_commit_failed` event occurred for record 10.

| Destination | Generation ID | Edoc | Size | SHA-256 |
| --- | --- | ---: | ---: | --- |
| File Repository | `f82969606242df0deb908cc732c95402` | 2232 | 74,876 bytes | `390a006f19b0386afa1e60237d1fc455a9dc651004d8235c117123ce4592fa4a` |
| Event 2 `fileupload` | `f91e6008cfdb29cfb8a88beeb150110f` | 2233 | 74,876 bytes | `490e4e8daeb47d421f1e4599a36538f8e61906d50d772f62e56f0fb1eaef3517` |

Each generation logged `pipeline_started`, `operation_completed` for `pdf_sealer:watermark` with status `unchanged`, `pipeline_completed` with one invocation and zero accepted modifications, then `artifact_committed`. The on-disk edoc hashes matched the corresponding `final_sha256` and `artifact_sha256` log values. Both files had a `%PDF-1.3` header and `%%EOF` marker. The hashes differ because REDCap generated the two destination PDFs separately; matching across destinations is not required by this test.

## Non-e-Consent survey: document type excluded

Record 10 completed Form 2 in Event 2 at 23:22:01 Europe/Berlin, while `pdf_sealer:watermark` declared only `econsent`. Snapshot 267 archived a one-page PDF as edoc 2235 (`consent_id = null`, `contains_completed_consent = 0`). Generation `ead9fcbfc65a7388aff21515e954b5c4` logged `document_type = survey_pdf`, `document_type_mismatch`, `invoked_count = 0`, and `artifact_committed`. The stored SHA-256 matched both finalizer hashes: `a9def832b7c10a600903447476c3fc972d6c2665a10de146e4737ab326ca26af`.

## Non-e-Consent survey: document type included

After `survey_pdf` was added to the dummy watermark declaration, record 9 completed Form 2 in Event 2 at 23:44:07 Europe/Berlin. Snapshot 267 archived a one-page PDF as edoc 2237 (`consent_id = null`, `contains_completed_consent = 0`). Generation `52fc937de0775ab9b1a483c7a00a9ca2` logged `operation_completed` for `pdf_sealer:watermark` with status `unchanged`, `invoked_count = 1`, `accepted_modification_count = 0`, and `artifact_committed`. The stored SHA-256 matched both finalizer hashes: `58ef09e24785824a9fc4e079072d05303266027b12b95cf22354151d3a2616c1`.

Both edocs had PDF headers and EOF markers and were identified as one-page PDFs. Record 9's earlier e-Consent file-field failure belongs to snapshot 266 and was not changed by this Form 2 run.

## Survey confirmation email: received attachment

On 24 September 2026 at 09:22:50 Europe/Berlin, record 8 completed Form 2 in Event 2 with the survey confirmation email's PDF attachment enabled. Email generation `8a83db371863a9ff9debec91313454cf` logged `document_type = survey_pdf`, one `pdf_sealer:watermark` invocation with status `unchanged`, and `artifact_committed` with `storage_target = email_attachment`, `survey_id = 1006`, and no edoc ID. Both `final_sha256` and `artifact_sha256` were `47db2c3c1b2a00ff5f2c0e107e669e5da5bfe1b1a845cf0b318dd1808c7edb34`.

The PDF actually received in the email, `20260924092250_survey_ba64b5ae.pdf`, was 51,789 bytes, identified as a one-page PDF, and had that exact SHA-256. This establishes that the finalized bytes reached the recipient as the attachment; `artifact_committed` alone would only establish that `Message::send()` reported success. A separate File Repository generation seven seconds earlier produced edoc 2245 with a different hash, as expected for a separately generated PDF.

## Byte-changing demo prepared; live retest pending

The `pdf_sealer:watermark` implementation now uses Ghostscript to add a visible `FINALIZER TEST` mark to each page and returns `modified`. A no-send local smoke test on a copy of the received record 8 PDF changed its SHA-256 from `47db2c3c1b2a00ff5f2c0e107e669e5da5bfe1b1a845cf0b318dd1808c7edb34` to `adcd8612d8e3eca26c293e00ff3dee9b4c4ee8cd51b0b71d35c864694091e21a`, retained one page, and exposed one extractable `FINALIZER TEST` mark. With Ghostscript unavailable, the operation returned a controlled failure and left the input bytes unchanged. No live REDCap generation with this mutation has yet been checked.

## Acceptance coverage and remaining live evidence

These runs establish live evidence for e-Consent and non-e-Consent survey paths, document-type exclusion and inclusion, the persisted `module_prefix:operation_id` plan, correlated logging, File Repository archival, corrected e-Consent file-field storage, survey confirmation email attachment delivery, and hash agreement between finalized and committed bytes. This supports section 35 criteria 4 and 25, the exact-match portion of criterion 8 (not wildcard matching), and the storage/archival/email portion of criterion 24. Criteria 23–24 remain only partly established live because all recorded live runs used the earlier `unchanged` operation.

The highest-value remaining gaps are:

1. A byte-changing finalizer on the real survey path, proving that its changed bytes are committed without later modification (criteria 23–24). Core's `UnitTests/PdfSnapshotFinalizationTest.php` exercises exact stored-byte equality with a test operation, but that is automated evidence.
2. Reproducible manual evidence for pipeline UI and activation paths—ordering, duplicate occurrences, removal, unresolved warnings, and enablement decisions (criteria 3, 5, 18–21). Earlier manual checks may exist, but are not recorded in this note.

Core's `UnitTests/PdfSnapshotFinalizationTest.php` verifies changed bytes and context for both email document types. Existing-edoc attachments are not finalized again. Other direct PDF generation such as `Files::archiveRecordAsPDF` and downloads remains a separate scope decision.

Framework tests `PdfFinalizeTest.php`, `PdfFinalizeResultTest.php`, `PdfFinalizePersistenceTest.php`, and `PdfFinalizeEnablementTest.php` cover failure isolation, terminal rules, missing entries, multi-operation behavior, persistence, and enablement. Their coverage was inspected for this audit, not rerun here; live repetitions are optional if automated evidence satisfies acceptance.
