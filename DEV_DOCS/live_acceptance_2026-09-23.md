# PDF finalizer live acceptance — 2026-09-23

This is test-environment evidence for the `redcap_pdf_finalize` implementation, not a claim that the placeholder PDF Sealer operations modify PDF bytes. The `pdf_sealer:watermark` operation currently returns `unchanged`.

## Setup

- Project: PID 461, Watermarked Signatures EM.
- Survey: Form 1, survey ID 964, Event 1 (event ID 1423), with e-Consent enabled.
- Snapshot: ID 266, triggered by survey completion, with e-Consent ID 169. It stores one PDF in the File Repository and another in the `fileupload` field in Event 2 (event ID 1465).
- Project PDF finalization plan: one `pdf_sealer:watermark` occurrence.
- The participant completed the survey and e-Consent in the browser. The checks below inspected database rows, edoc files, and PHP finalizer logs without changing the completed record.

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

## Scope and follow-up

This live test validates e-Consent survey completion, project-plan invocation, generation-level correlation, both storage targets, and byte/hash agreement at each commit boundary. It does **not** test a byte-changing operation, multiple operations, download/email delivery, or the non-e-Consent survey snapshot path. Those behaviors remain covered by automated tests or require separate live exercises.
