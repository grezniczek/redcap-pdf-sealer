# Execution-plan transfer and Core terminal test-action acceptance

Implementation checkpoint: 2026-10-08. Isolated regressions, native read-only
export/configuration preflight on PID 524 and real Sealer-to-Core runner handoff
pass. Native browser export with the plan selected and excluded also passes,
and selected-plan native import passes stored-state and browser checks in PID 539.
Excluded-file import preserved absent plan state in PID 540; its subsequent
explicit-empty save/export/import passes, with stored `[]` in PID 541.
Ordered duplicate/unavailable native import, browser and re-export pass in PID 542.
Unsupported-version rejection/cleanup and scoped native adapter replacement/rollback also pass.
Empty-tag, repeated-tag and non-list JSON rejection/cleanup also pass. The planned
XML acceptance matrix is complete, with existing-target rollback established by
the scoped native adapter test. Selected-plan native intra-instance PMT passes
in PID 548, including completion and stored/browser destination checks. Excluded/
explicit-empty PMT and enabled-action acceptance remain **pending**.
Preserve previous sealing and enablement evidence; these are additional checks
for the expanded refactor.

## Selected-plan browser export — passed 2026-10-08

The user confirmed the default-checked **PDF Finalization Execution Plan** option
in PID 524; the supplied screenshot also shows the metadata-only XML download
button and checked surveys/eConsent options. The downloaded
`C:\Users\grezn\Downloads\PDFSealerTest_2026-10-08_1559.REDCap.xml`
is well-formed XML, 45,760 bytes, SHA-256
`91f7bc03d851fe54092b50f710c754643726a0af07ac54bfdacaca06b5182b74`.
Independent parsing finds exactly one version-1 execution-plan tag containing
`["pdf_sealer:seal"]`, no clinical subject records and no Core test-action
activation markers. Dev-control confirms the source's saved plan matches and
its Core workflow setting remains absent. No import/activation was performed.
This establishes selected metadata-only browser export; it does not establish
excluded export or native destination behavior. Keep this file for import tests.

## Excluded-plan browser export — passed 2026-10-08

The user unchecked only the plan option and supplied
`C:\Users\grezn\Downloads\PDFSealerTest_2026-10-08_1611.REDCap.xml`, reporting
that the plan group/tag is absent. Independent parsing confirms well-formed XML,
zero plan/group tags, zero clinical subject records and no Core test activation
markers. The file is 45,587 bytes, SHA-256
`d3c1e6af041cf4b6e1c76a350667a01ebe74507e59eba16515b0a2446329bc25`.
This completes the selected/excluded metadata-only browser export pair. Native
import/rollback and destination behavior remain pending. Keep both exports for
the disposable target checks.

## Selected-plan native import — stored state passed 2026-10-08

The user created development project 539, **PDF plan XML import included**, from
the selected export through native REDCap project creation. Dev-control confirms
the active, non-deleted target has the exact stored `["pdf_sealer:seal"]` plan,
no Core terminal workflow setting and no Sealer project settings. Sealer's system
default remains disabled; the target has no enabled/version override. Source
PID 524 still has its original plan and enabled Sealer override. Thus native
import preserves the identifier without activating the module or adding Sealer
project PKI/settings. Target browser retained-unavailable presentation is the
next check. Absent/empty/duplicates, existing-target replacement, rollback and
complete PMT acceptance remain pending. Keep 539 for these disposable tests.

The user subsequently confirmed all three destination checks: the imported
sealing assignment is retained and marked unavailable, Core test controls are
disabled, and Cancel closes normally. Follow-up dev-control reads confirm the
plan remains `["pdf_sealer:seal"]` and Core workflow settings remain absent.
Selected-file native import and retained-unavailable browser presentation pass.
No module activation or designer save was performed during this check.

## Excluded-plan native import — stored state passed 2026-10-08

The user created development project 540, **PDF plan XML import excluded**, from
the excluded export through native project creation. Dev-control confirms the
active, non-deleted target has neither an execution-plan setting nor a Core
terminal workflow setting. There are zero Sealer project settings; the unchanged
disabled system default leaves Sealer disabled. PID 539 retains its imported
`["pdf_sealer:seal"]` plan and absent Core workflow setting. This establishes
omitted-tag import leaves a new destination plan absent; it did not create `[]`.
Opening/canceling the empty destination editor is the next browser check, before
deliberately saving an explicit-empty source fixture. No agent mutation occurred.

The user subsequently confirmed the assigned-operation list is empty in PID 540.
Follow-up dev-control reads still find neither the execution-plan setting nor
the Core workflow setting. The browser inspection therefore preserves absent
plan state. Cancellation was requested but not separately reported; the empty
presentation and unchanged stored state are independently recorded. The next
deliberate step is saving the empty list to create an explicit `[]` source fixture.

## Explicit-empty source save — passed 2026-10-08

The user clicked Save & Close with no assigned operations in PID 540. Dev-control
confirms its previously absent execution-plan setting is now stored as `[]`,
while the Core workflow setting remains absent. Native audit lookup finds one
`Modify PDF finalization execution plan` event, ID 1394, attributed to `gr`, with
`[]` data. The read-only native preflight also passes on 540: selected/excluded
and export-all XML, ordinary API/PMT option routing, shared default-checked
metadata option/category and disabled Core configuration all preserve the
explicit-empty setting. Browser export of this fixture and native import of its
empty tag remain pending. PID 540 is now deliberately an explicit-empty source;
the earlier absent-state evidence records its pre-save checkpoint.

## Explicit-empty browser export — passed 2026-10-08

The user exported metadata only from PID 540 with the plan option checked and
supplied `C:\Users\grezn\Downloads\PDFPlanXMLImportExcl_2026-10-08_1916.REDCap.xml`.
Independent parsing confirms well-formed XML with exactly one version-1 plan
tag and the literal `operations="[]"`, zero clinical subject records and no
Core test activation markers. The file is 45,624 bytes, SHA-256
`c7b8acf85a28c30a6fe032c13a22cf805a7666a89624cb824af6c592251faab3`.
Thus explicit-empty browser export retains the tag instead of omitting it.
Native import into a fresh destination is the next check; leave its plan editor
unsaved until stored state is inspected. No agent live mutation occurred.

## Explicit-empty native import — passed 2026-10-08

The user created development project 541, **PDF plan XML import empty**, from
the explicit-empty export through native project creation. Dev-control confirms
its execution-plan setting exists with value `[]`, Core workflow settings are
absent, and there are zero Sealer project settings. The unchanged disabled
Sealer system default leaves the target disabled. PID 540 retains its deliberate
`[]` source setting; PID 539 retains `["pdf_sealer:seal"]`. Thus the selected,
omitted and explicit-empty cases each preserve their intended native import
state. This completes explicit-empty browser export/native stored-state import;
no additional target designer save was needed to create `[]`.

## Ordered duplicate/unavailable import fixture — prepared 2026-10-08

Prepared `/tmp/pdf-plan-duplicates-unavailable.REDCap.xml`, available to the
Windows browser file picker as
`\\wsl.localhost\Ubuntu\tmp\pdf-plan-duplicates-unavailable.REDCap.xml`.
It preserves the selected source XML except for the single plan element, whose
ordered list is:

```json
["pdf_sealer:seal","pdf_finalize_transfer_missing:annotate","pdf_sealer:seal"]
```

Dev-control confirms `pdf_finalize_transfer_missing` is not a registered module.
Independent parsing verifies well-formed XML, exactly one version-1 plan tag,
the exact list above, no clinical subject records and no Core test activation
markers. The file is 45,839 bytes, SHA-256
`b80efbeabf32525f6a9bff3d9ef6a5f17a518af31b73bab3035ecb463200815c`.
This is an intentionally edited import fixture, not an assertion that native
export has already produced this list. Native import/browser retention followed
by export of the resulting destination will test that round trip. No live
settings/module registration/activation was changed during preparation.

## Ordered duplicate/unavailable native import — stored state passed 2026-10-08

The user created development project 542, **PDF plan XML import duplicates**,
from the prepared fixture. Dev-control confirms the exact three-entry ordered
plan shown above, absent Core workflow settings, zero target project settings
for Sealer/the missing prefix, and the unchanged disabled Sealer system default.
Native import therefore retains duplicate occurrences and an unregistered
operation identifier without enabling or registering their modules. PHP 8.2
read-only native preflight also passes on 542, including export-option routing,
exact serialized list retention, shared controls and disabled Core configuration.
Browser order/unavailable/duplicate warning presentation and its metadata-only
re-export remain pending. No designer save was needed to retain the imported list.

## Ordered duplicate/unavailable browser and re-export — passed 2026-10-08

The user confirmed PID 542's exact three-entry order, unavailable warnings on
all entries and duplicate warnings on the two sealing occurrences, then supplied
`C:\Users\grezn\Downloads\PDFPlanXMLImportDupl_2026-10-08_1935.REDCap.xml`.
Independent parsing confirms a well-formed metadata-only export with exactly one
version-1 tag and the same ordered three-entry list, no clinical subject records
and no Core test activation markers. The file is 45,744 bytes, SHA-256
`7f4cc651d71bb8f96362dbccdf33b054ec46329a3f024fc79f19f732db655a85`.
Follow-up dev-control reads confirm the target plan is unchanged and Core
workflow settings remain absent. Native import/browser/re-export retention of
duplicate and unavailable identifiers now passes; no resolving/activation or
designer save was needed.

## Unsupported-version native rejection fixture — prepared 2026-10-08

Prepared `/tmp/pdf-plan-invalid-version.REDCap.xml`, accessible as
`\\wsl.localhost\Ubuntu\tmp\pdf-plan-invalid-version.REDCap.xml`.
It changes only the selected source plan's version from `1` to `2`. Independent
parsing confirms well-formed XML, one deliberately unsupported plan, its original
one-item list and no clinical subject records. The file is 45,760 bytes, SHA-256
`600b7013db2b9a552c53e015be09dccc8e88ecf606e06b1a5e09394dfe38fbc4`.
The next user test attempts native creation under **PDF plan XML invalid version**
and should return the localized plan-import error. Dev-control preflight finds
zero projects under that title and next project allocation 543; this is a
baseline, not an assertion that 543 belongs to the attempt before inspection.

Source inspection shows full ODM metadata import is used during project
creation; the existing-project ODM conversion path imports data only. Thus the
browser rejection checks native parser/adapter wiring and failed-creation
cleanup. Existing-target absent/empty replacement and direct transaction
rollback need a separate, scoped native Core adapter test. Failed-creation
cleanup alone does not prove rollback preserved a pre-existing target plan.
Dev-control can inspect/preview database state but cannot run this application
workflow; a native application-service acceptance runner would be a useful
enhancement. No malformed import or live mutation is claimed by preparation.

## Unsupported-version rejection and cleanup — passed 2026-10-08

The user received the expected localized PDF execution-plan import error during
native creation with the version-2 fixture. Dev-control confirms no project
under the chosen title or PID 543, zero metadata/project-setting/EM-setting/user-
rights rows for 543, and allocation advanced from 543 to 544. The four successful
fixtures retain their expected plans and absent Core workflow settings. This
establishes native parser/adapter rejection and the inspected failed-creation
cleanup; it is separate from the existing-target transaction test below.

## Native adapter replacement and transaction rollback — passed 2026-10-08

Added `tests/pdf_plan_transfer_live.php` with read-only `--preview` and a
rollback-contained `--run`. Native Design rights, exact disposable empty-fixture
title, record-free status, disabled Sealer/Core gate, transactional tables and
fresh-connection autocommit are checked before any mutation. Dev-control also
confirmed the projects/settings tables are InnoDB. Preview and the PHP 8.2 run
pass on PID 541 as `gr`; PHP 8.2/8.5 syntax checks pass.

The actual Core adapter/repository retains ordered duplicates/unavailable
identifiers, leaves an existing row exactly unchanged on omitted metadata,
replaces a nonempty plan with explicit `[]`, and reports five malformed payloads
without changing the preceding plan (unsupported version, JSON object, numeric
identifier, multiple rows, empty row). It also verifies omitted/empty behavior
after preparing absent state within the same transaction. A project-note
sentinel written through Core's project helper and all temporary settings
changes are unconditionally rolled back. Native readback verifies the original
setting ID/value/timestamps, absent Core setting and project note/activity.
Follow-up dev-control confirms all four fixture plans unchanged, PID 541's note
null and activity timestamps still `2026-10-08 19:20:36`.

This exercises native database replacement/validation and caller-owned rollback
with actual Core APIs. It does not call full `ODM::parseOdm()` on an existing
project or claim a browser metadata-replacement route. Transaction-control SQL
is confined to the harness; application writes use Core helpers, not direct SQL
data mutation. No record/activation/PKI/edoc/email action occurs. Temporary error
logging is removed afterward. Dev-control does not yet provide this native
application-service runner; preview and mutation inspection still use its tools.

## Empty-tag native parser fixture — prepared 2026-10-08

Prepared `/tmp/pdf-plan-empty-tag.REDCap.xml`, accessible as
`\\wsl.localhost\Ubuntu\tmp\pdf-plan-empty-tag.REDCap.xml`.
Only the selected source's plan element changes to
`<redcap:PdfFinalizeExecutionPlan/>`. It is well-formed XML with exactly one
attribute-free plan tag and no clinical subject records: 45,705 bytes, SHA-256
`622a13b9949bac19aae040f3c91f037fa94310252db02d3953ad7f07f83ee0b2`.
The next native browser creation attempt is **PDF plan XML empty tag** and must
report a plan-import error. This checks the parser consumes an incomplete
present tag instead of ignoring it as absent metadata. Native rejection/cleanup
for this fixture remains pending; preparation changed no live project state.

## Empty-tag native rejection and cleanup — passed 2026-10-08

The user received the expected localized plan-import error from the attribute-
free plan-tag fixture. Dev-control confirms no project under **PDF plan XML
empty tag** or PID 544, zero inspected metadata/project-setting/EM-setting/user-
rights rows for 544, and allocation advanced to 545. Successful fixture plans
539–542 and absent Core settings remain unchanged. The parser therefore rejects
a present incomplete plan instead of treating it as omitted metadata. Native
rejection and inspected failed-creation cleanup pass; no agent mutation occurred.

## Repeated-tag native parser fixture — prepared 2026-10-08

Prepared `/tmp/pdf-plan-repeated-tags.REDCap.xml`, accessible as
`\\wsl.localhost\Ubuntu\tmp\pdf-plan-repeated-tags.REDCap.xml`.
It duplicates the single selected-source plan element, producing two otherwise
valid version-1 plan tags containing `["pdf_sealer:seal"]`. XML parsing verifies
both tags and no clinical subject records. Native creation under **PDF plan XML
repeated tags** must reject this ambiguous payload, even though both plans match.
The fixture is 45,852 bytes, SHA-256
`f1c6717d43ef25cf3e73df1229f8685a1cb5d29e5a82bed51d640a15924356a5`.
No project under that title exists before the attempt; allocation 545 is the
current baseline. Native rejection/cleanup remains pending.

## Repeated-tag native rejection and cleanup — passed 2026-10-08

The user received the expected localized plan-import error from the repeated-tag
fixture. Dev-control confirms no project under **PDF plan XML repeated tags**
or PID 545, zero inspected metadata/project-setting/EM-setting/user-rights rows
for 545, allocation advanced to 546, and valid fixture plans/Core absence remain
unchanged. Thus the native parser rejects multiple plans even when both are
otherwise valid and identical. Native rejection and inspected cleanup pass.

## Non-list JSON native fixture — prepared 2026-10-08

Prepared `/tmp/pdf-plan-non-list-json.REDCap.xml`, accessible as
`\\wsl.localhost\Ubuntu\tmp\pdf-plan-non-list-json.REDCap.xml`.
Only the source's plan element changes to version `1` with `operations="{}"`.
Independent parsing verifies one plan, a valid JSON object instead of a list,
well-formed XML and no clinical subject records. The file is 45,733 bytes,
SHA-256 `3335c1ceaf5b7928d85651ced06d38dbc8b65c079de6015a6a3a571aa7573ead`.
The final planned malformed browser case attempts native creation as **PDF plan
XML non-list JSON**; no project under that title exists before the attempt,
and allocation 546 is the current baseline. Expect the localized plan-import
error, not conversion to an explicit empty list. Native browser rejection and
cleanup for this fixture remain pending. The separate native adapter test has
already rejected this payload while preserving existing target state.

## Non-list JSON native rejection and cleanup — passed 2026-10-08

The user received the expected localized plan-import error from the version-1
fixture containing `operations="{}"`. Dev-control confirms no project under
**PDF plan XML non-list JSON** or PID 546, zero inspected metadata/project-
setting/EM-setting/user-rights rows for 546, and unchanged valid fixture plans
539–542 with no Core workflow settings. Allocation is now 548; PID 547 belongs
to a separate active project and is outside this test and its cleanup scope.
The invalid object was rejected instead of becoming an explicit empty plan.
Native browser rejection and inspected failed-creation cleanup pass.

The planned native XML cases are complete: selected/excluded exports, selected/
omitted/explicit-empty imports, ordered duplicate/unavailable round trip, and
version/empty-tag/repeated-tag/non-list rejection with failed-creation cleanup.
Existing-target replacement/preservation and rollback have separate native
adapter evidence; this does not claim full existing-project ODM replacement.

## PMT receiver readiness — inspected 2026-10-08

Dev-control reports healthy development instances with no warnings. Main is
17.5.3 at `https://dev-redcap/`; pool-1 is 17.5.1 at `https://dev-redcap1/`.
Read-only source inspection finds no PdfFinalization classes in pool-1, so it
cannot establish destination execution-plan acceptance with its current code.
Main PMT configuration permits source/destination migration and leave-as-is;
actual browser availability still needs checking. No feature gate was changed.

Read-only `rcm status` identifies slots 4 and 5 as 17.5.3, currently serving
`annotation-feature` and `mlm-redesign` Core branches respectively, with the
`testing` Framework. Either can temporarily select the refactor Core/Framework
for a same-version receiver, then restore its prior selections. Slot choice
is pending because these instances serve other feature work. No code selection,
instance database, project or migration key has been changed. Cross-instance
PMT acceptance remains pending; a same-instance test would have narrower scope.

## Intra-instance PMT acceptance selected — 2026-10-08

The user points out that PMT also works within one instance. Source inspection
of migration-key validation, API connection validation, metadata fetching and
the project-creation route finds no rejection of an identical source/destination
base URL. The normal destination Instance ID still must validate. We will use
main as both source and destination with fresh disposable targets; no second
slot or branch switch is needed, superseding the pending receiver-choice question.
This exercises the native migration-key/API/ODM/project-creation/completion
path. It does not establish cross-instance deployment or differing-version
compatibility; those are separate from this plan-transfer acceptance.

Source PID 542 retains the ordered duplicate/unavailable fixture. Dev-control
reports `gr` has Design rights but no API Export right or API token there.
Prepare API Export and a token through native project UI before generating a
metadata-only key. Keep the token/key in the browser. Select leave-as-is for
source completion; exclude records, logs, files and EM settings. The Core test
gate remains off. This checkpoint changes documentation only; no rights, token,
key, project or slot configuration has yet been changed by the agent.

## PMT source prerequisites — passed 2026-10-08

The user enabled API Export for `gr` in PID 542 and created its API token.
Dev-control verifies `api_export=1` and token presence using a Boolean query;
the token was not retrieved or printed. The read-only native PHP 8.2 preflight
passes again on 542, including selected/excluded PMT export routing, shared
controls/category, disabled Core configuration and preserved settings. This
establishes source readiness, not completed native PMT transfer. The user will
generate a metadata-only key using main’s destination Instance ID and
leave-as-is, then create a fresh destination on main. Actual PMT completion,
destination plan/activation and browser warnings remain pending.

## Selected-plan native intra-instance PMT — passed 2026-10-08

The user created PID 548, **PDF plan PMT included**, on main from source 542
and confirmed unavailable-operation and duplicate warnings in the destination
editor. Dev-control verifies the exact source/destination ordered list:
`["pdf_sealer:seal","pdf_finalize_transfer_missing:annotate","pdf_sealer:seal"]`.
Both projects remain active in development and source 542 is unchanged.

Native migration row 3 records origin 542, destination 548, source API
`https://dev-redcap/api/`, version 17.5.3, completion action `leave_as_is`,
start `2026-10-08 21:53:18` and end `2026-10-08 21:54:02` (database timestamps).
The destination project status is `COMPLETED`; migration token/signature columns
are cleared, verified only as Booleans. Inspected data/file/EM-settings/logging/
email-log/file-repository/PDF-archive queue fields are null. Native data inspection
finds zero destination records.

There are zero EM setting rows in either fixture and no Core workflow setting.
Sealer’s system default remains false, installed version v9.9.9, with no target
override, so the retained sealing identifiers do not enable it. The read-only
PHP 8.2 native export/configuration preflight passes on 548, including selected/
excluded PMT routing, shared option/category, disabled Core gate and preserved
settings. No cryptographic finalization is invoked by these inspections.

This establishes a completed native migration-key/API/ODM/project-creation/
completion path within one instance and destination warnings. The user did not
separately report source default-checkbox/key-validation-label or cancellation
checks; category/default behavior has native preflight evidence. Cross-instance
deployment compatibility is outside this intra-instance result. Excluded and
explicit-empty native PMT cases remain pending. No agent live mutation occurred.

## Starting state

- Main development instance: Core v17.5.3. PID 524 has one
  `pdf_sealer:seal` assignment and Sealer enabled. Use its export controls only;
  do not alter its existing records, execution plan or signing identities.
- New option: **PDF Finalization Execution Plan**, key `pdffinalizationplan`.
  Its version-1 `redcap:PdfFinalizeExecutionPlan` extension contains only an
  ordered JSON `operations` list. Saved `[]` is different from an omitted tag.
- Core test action is `core:test_terminal`, explicitly **no signing**. Its
  `REDCAP_PDF_FINALIZATION_TEST_ACTION` constant must be Boolean true to expose
  activation. The native gate is currently undefined/disabled and no project
  has a workflow selection. Do not activate it on accepted sealing projects.
- Use fresh disposable projects for import/activation checks, created through
  REDCap's native UI. Inspect plans/settings/audits using dev-control. Do not
  create projects through raw SQL.

## XML export and import

1. As `gr`, open PID 524's **Other Functionality → Download metadata & data**
   XML dialog. Confirm **PDF Finalization Execution Plan** exists and is checked.
   Export metadata only with it checked; provide the downloaded local path for
   inspection. Expect one tag and `["pdf_sealer:seal"]`, no test activation.
2. Export metadata only with this option unchecked. Expect no dedicated tag.
   The execution-plan choice must be independent of eConsent settings and EM
   settings choices. Avoid exporting module settings/PKI for these fixtures.
3. Create a disposable project from the selected XML. Inspect its exact plan
   before deliberately activating any EM. Opening its Core editor must retain
   an unavailable identifier if the destination module is disabled/unavailable;
   import must not silently enable Sealer. Existing native/global activation
   rules still apply. Confirm no new Core test setting/signing identity.
4. Create another project from the excluded XML. Expect no plan setting, not a
   newly saved `[]`. On disposable source fixtures also test saved `[]`, ordered
   duplicates and an unavailable operation identifier. Export/import must retain
   exact order/duplicates/identifiers; an imported `[]` must remain explicitly
   configured. Unavailable entries are warnings/skips, not import failures.
5. Use a scoped native Core adapter test on a disposable existing target to check
   omitted tag leaves its plan untouched, while explicit `[]` replaces it. Full
   ODM metadata replacement is not exposed by the existing-project browser route.
6. Through native project creation, exercise malformed version, malformed/non-list
   JSON, repeated plan tags and an empty plan tag. Expect a native import error
   and cleanup of the failed new target. Establish preservation/rollback of
   existing target state separately with the native adapter test and transaction
   sentinel. Isolated adapter error tests and failed-creation cleanup alone do
   not establish rollback of an existing target.

## Project Migration Tool

1. Use a disposable source with an ordered plan containing duplicates and an
   unavailable identifier. Confirm the shared plan option is checked, including
   for an explicit-empty fixture; absent plans offer no option.
2. Perform native **metadata-only** PMT to a fresh project on main with
   the plan option selected. Inspect destination order/unavailable/empty state
   and category label. No plan-specific records/data queue is needed. Destination
   module disabling remains native PMT behavior; no test gate/selection or PKI
   state is transferred by the plan extension.
3. Repeat with the option excluded and verify a new target has no plan setting.
   For execution acceptance, deliberately enable/assign appropriate EMs only in
   disposable destinations after inspecting imported state. Verify no imported
   operation executes automatically during import.

## Core test controls and terminal handoff

1. With the gate still off, open Project Setup → PDF Finalization. Confirm the
   Core test section identifies no signing, activation choices are disabled,
   and workflow previews have no reserved Core terminal action. Cancel/reopen
   must leave EM plans and Core selections unchanged.
2. For an agreed temporary development test window, enable the Boolean gate
   through a local, removable bootstrap setting; do not commit the gate or use
   PKI settings. Use a disposable project with Design rights and test a member
   without Design rights for read-only behavior.
3. Select `record_pdf` only and **Save Core test settings**. Reopen; expect just
   that choice, one native Core-settings change audit, and a fixed final Core
   test step only in its preview. Other workflow previews remain unreserved.
   Unchanged save must create no extra audit; canceled checkbox edits must not
   persist. Verify EM assignments do not change during this separate save.
4. Generate a fresh record PDF in a disposable project without EM assignments.
   Capture correlated Core events: selected test identity, terminal success,
   `test_only: true`, `cryptographic_seal_applied: false`, and no accepted byte
   modification. The artifact must have no new signature/certification claim.
5. In a disposable project with Sealer deliberately enabled/assigned, reserve
   `econsent`. Generate a fresh artifact. Confirm Sealer yields nonterminal
   unchanged before PKI/logging, Core runs last, and no new cryptographic seal
   is applied. Do not use an already certified PDF as the unsigned test artifact.
   The focused CLI handoff regression passes, but native browser/delivery
   acceptance is still required for this step.
6. Clear selections and save/reopen. Confirm the Core reservation disappears,
   the EM list is unchanged, and ordinary EM processing resumes where selected.
   Remove the development gate after testing. If stale selections remain when
   the gate is off, **Disable Core test finalization** must still clear them.
7. Finish with test selections empty, gate absent/off, disposable projects
   removed through native UI, and inspected accepted-project plans/enablement
   unchanged. Record actual evidence here or in `testing.md` before declaring
   the expanded refactor complete.

Suggested next slices: native XML import/rollback; metadata-only PMT; temporary
Core-control browser/runtime acceptance followed by deactivation. Real Core
signing and the necessary PKI remain a separate future implementation.
