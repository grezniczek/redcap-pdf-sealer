# Execution-plan transfer and Core terminal test-action acceptance

Implementation checkpoint: 2026-10-08. Isolated regressions, native read-only
export/configuration preflight on PID 524 and real Sealer-to-Core runner handoff
pass. Native browser export with the plan selected and excluded also passes,
and selected-plan native import passes stored-state and browser checks in PID 539.
Excluded-file import preserves absent plan state in PID 540. Explicit-empty transfer and
duplicate cases, rollback, complete PMT and
enabled-action acceptance remain **pending**.
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
5. Use native metadata replacement on a disposable existing target to check
   omitted tag leaves its plan untouched, while explicit `[]` replaces it.
6. On disposable targets, exercise malformed version, malformed/non-list JSON,
   repeated plan tags and an empty plan tag. Expect a native import error with
   no committed metadata or plan replacement. Capture before/after plan and a
   metadata sentinel through dev-control to establish native transaction rollback.
   Isolated adapter error tests alone do not establish this rollback.

## Project Migration Tool

1. Use a disposable source with an ordered plan containing duplicates and an
   unavailable identifier. Confirm the shared plan option is checked, including
   for an explicit-empty fixture; absent plans offer no option.
2. Perform native **metadata-only** PMT to the second development instance with
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
