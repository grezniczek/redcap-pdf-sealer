# Core-owned PDF finalization — change plan

**Status:** Core contracts, coordinator/provider wiring, plan management and PDF Sealer integration implemented; the completed backend/browser/eConsent acceptance and test-fixture cleanup remain valid. On 2026-10-08 the user expanded this refactor to include XML/PMT execution-plan transfer and a test-only Core terminal action with an enable/disable interface. Those integrations and their acceptance are pending, so the overall refactor is not yet complete. The corrected native enablement test's execution limitation is recorded in testing.

**Date:** 2026-10-03.  
**Scope:** REDCap Core, the External Module Framework, and PDF Sealer's integration contract.

## Implementation progress — 2026-10-04

### Scope update: test-only Core terminal action and controls — 2026-10-08

The user also requests a dummy Core terminal sealing action to exercise the
enable/disable interface before a real signer and PKI are implemented. Keep this
within Core; do not add a Framework feature or another EM for the action.
This adds manual acceptance of a selected Core terminal step to the existing
synthetic-action engine tests. XML/PMT transfer remains required in parallel.

Required behavior:

- Use the existing `PdfTerminalAction`, Core policy resolver, fixed-step preview,
  and `terminal_action_reserved_for_core` contract. A selected test action runs
  last, including without an EM plan/provider. PDF Sealer yields nonterminal
  unchanged through its already implemented reservation handling.
- Name and identify the action as a test action; no cryptographic sealing is
  performed or claimed. A dummy success can return terminal unchanged bytes,
  but the UI/log evidence must identify test finalization, not certification.
  Existing sealed PDFs must not be presented as newly Core-certified.
- Persist Core enablement separately from the designer's ordered EM list. Never
  encode the Core action as a movable/removable EM operation. Apply the native
  design/admin rights, CSRF, audit and persistence/recovery rules to its controls.
  Runtime selection and preview must use the same stored setting and resolver.
- Keep the placeholder unavailable for ordinary production use behind an
  explicit development/test gate. Normal defaults are disabled. Enable it only
  for disposable acceptance projects; afterward disable their settings and the
  gate. Shipping an unsigned PDF must not be described as successful sealing.
- Do not confuse action availability with signer readiness. The eventual real
  action must retain a selected reservation when PKI prerequisites fail and
  follow Core's failure policy, rather than falling back to EM sealing.
- Test save/reopen, enable/disable, fixed final placement, EM yielding, Core-only
  execution, unchanged bytes and deactivation. Preserve the completed EM-only
  sealing regression and perform no PKI/key/certificate mutation for dummy tests.

Control granularity is being clarified with the user: per project and PDF
workflow is recommended; a single project-wide switch is the alternative.
Core currently enumerates `econsent`, `record_pdf`, and `survey_pdf` workflows.
Do not assume all eConsent-containing exports are governed eConsent snapshots.

The XML/PMT slice must consider these new Core settings as well as the EM plan:
declare which eventual production workflow choices are portable, while test
gates/test-action activation must not become enabled on a destination through
transfer. No runtime placeholder or enablement implementation is claimed here.

### Scope update: XML/PMT execution-plan transfer — 2026-10-08

The user requires project XML and Project Migration Tool transfer as part of
Core execution-plan ownership. This supersedes the earlier deferral. Preserve
the completed ownership/enablement acceptance; add the transfer work and its
acceptance before closing the overall refactor. Actual Core sealing remains
separate future work.

Source inspection shows both workflows already use Core's ODM metadata path.
`Classes/ODM.php::getOdmMetadata()` exports selected custom metadata;
`ODM::parseOdm()` imports vendor extensions inside the metadata transaction.
`Classes/ProjectMigration.php` provides the shared XML/PMT metadata checkboxes
and migration-category labels, and `getRemoteProjectXML()` forwards the selected
metadata options to the project-XML API. A Core-owned adapter can serve both
workflows without a second PMT serializer or Framework/EM contract changes.

Required implementation slice:

1. Add a focused Core `PdfExecutionPlanOdmAdapter` using the existing
   `PdfExecutionPlanRepository` for persistence and identifier-list validation.
   Serialize only the ordered EM operation identifiers in a dedicated ODM
   vendor extension. Keep the existing storage key for compatibility.
2. Add a default-checked **PDF Finalization Execution Plan** option to the
   shared XML/PMT metadata controls when a saved plan exists, including `[]`.
   Honor selected-option exports, export-all, and ordinary project-XML API
   exports using the existing ODM selection rules. Keep this independent of
   eConsent settings and the separate EM-settings transfer option.
3. Import through ODM's existing metadata transaction and error handling,
   consuming the dedicated extension before generic table import. Validate
   the whole payload before replacing a plan; malformed/ambiguous payloads or
   persistence failure must produce an import error and roll back. Retain native
   import authorization and audit behavior rather than treating import as a
   designer-editor submission.
4. Preserve exact order, duplicates, and unavailable identifiers. An omitted
   extension leaves an existing target plan untouched; on a new target it leaves
   the plan absent. An explicit `[]` creates/replaces an explicit empty plan.
   Do not resolve identifiers against installed modules during import: operation
   availability is destination state and is reported by the existing Core UI.
5. Write no module-enablement settings or Core terminal-action policy from this
   extension. Normal destination/global defaults and existing PMT module-disable
   behavior remain authoritative. Do not execute finalization, transfer signing
   identities/keys/project bindings, or transfer instance PKI.
6. Add meaningful round-trip and integration tests: multiple operations,
   duplicates, unavailable modules/operations, absent versus empty, existing
   target plans, option exclusion, malformed payloads, persistence failure and
   rollback. Retain direct-copy regressions. Exercise the native XML and PMT
   paths with disposable projects, including metadata-only PMT, then verify
   imported plan/status before destination module activation and execution after
   deliberate activation where needed.

No runtime transfer implementation or transfer acceptance is claimed by this
scope update. Earlier XML/PMT tests established fresh destination identities
after manual plan reassignment; they do not establish automatic plan transfer.

### Global-enable browser acceptance — 2026-10-08

The user subsequently deleted all three disposable projects through the native UI. Dev-control confirms their soft deletion, zero fixture settings/active version, the original twelve active Sealer-only plans, unchanged audit baselines and inspected Sealer state. [Final acceptance](testing.md#refactor-browser-acceptance-and-cleanup-complete--2026-10-08) closes the guided browser checklist; older progress entries below retain their historical status.

The inert test fixture passed the skipped-project warning, checkbox persistence, project availability and global-disable transition checks across explicit empty, placed/disabled, and absent plans. Independent dev-control reads confirm preservation of all saved plans, native plan-audit baselines and inspected Sealer state. Explicit disabled overrides remain authoritative; the initial acceptance expectation and native test fixture were corrected accordingly, without production changes. The fixture's active version, settings and installed source copies are removed; disposable projects 536/537/538 are confirmed soft-deleted. [Global-enable acceptance](pdf_global_enable_acceptance.md) records the evidence and the corrected native test's execution limitation.

### Activation approval correction — 2026-10-08

The administrator To-Do approval page for user-created request 14 reached Core placement but supplied an empty candidate version. Browser/server evidence showed HTTP 400 from Core's candidate validation. The Framework approval page now resolves the installed version explicitly; an isolated actual-page regression reproduced the failure and passes after the change on PHP 8.2/8.5. Core validation and contracts remain unchanged. The pending request was reused for browser retry and the cancel/approval sequence. See [testing](testing.md#activation-request-approval-version-fix--2026-10-08).

The user reports successful placement loading after that correction. Cancellation then left the To-Do popup empty. Framework now closes the outer popup on placement cancellation through Core's existing frame helper, while direct approval pages retain a retry button. Five focused JavaScript regressions and the user-performed browser cancellation retest pass. Inspection at that checkpoint confirmed request 14 still pending, Sealer disabled and the plan empty. The subsequent user-performed approval also passes: dev-control confirms request 14 completed by `gr`, one saved sealing assignment, Sealer enabled and native plan/module audits 1082/1083. [Testing](testing.md#activation-request-browser-acceptance-complete--2026-10-08) records the completed sequence. No Core hook, policy or persistence contract changed. Global-enable UI acceptance remains pending.

### Guided browser and fresh eConsent acceptance

The user passed project plan-management checks in PIDs 533 and 524, including explicit empty plans, canceled/unassigned/assigned enablement, saved duplicates/removal, unavailable assignment retention/restoration, and unchanged saves. Independent dev-control reads confirm saved state and the expected four deliberate plan-change audits without extra unchanged-save entries. The user also accepted the rcDialog initialization/sizing refinement; eleven Node checks pass.

PID 524 record 10's fresh eConsent snapshot (edoc 2378) passes user-reported Acrobat certification/timestamp acceptance and independent cryptographic/content checks on the supplied download. The user-supplied stored-file digest exactly matches the independently calculated download digest, completing the stored/downloaded comparison for this pathway. [Pipeline acceptance](pdf_pipeline_acceptance.md#record-10-post-refactor-acceptance--passed) records the evidence and provenance. Dev-control's edoc inspect/hash/export JSON-envelope failure remains a tooling issue; no permission or artifact changes were needed. Activation-request/global-enable UI remains a separate pending check. Production Core sealing and XML/PMT transport remain outside this refactor's implemented scope.

### Editor browser feedback

The user passed initial opening/cancellation/duplicate-preview checks and both Core/Framework entry points. Storage inspection confirms preserved plans/enablement. The requested PDF button icon and migration to Core's documented `rcDialog` are implemented, including native dismissal/buttons, movable/resizable/fullscreen controls, fresh per-opening state and guarded asynchronous work. Eight Node tests and Core 90 tests/356 assertions on PHP 8.2/8.5 pass. [Testing](testing.md#core-editor-rcdialog-refinement--2026-10-04) records the refinement's browser spot-check and remaining deliberate-save/enablement acceptance.

### Slice 4 — native management and pipeline acceptance

Native acceptance identified and corrected audit-result handling (`Logging::logEvent()` rather than the void `REDCap::logEvent()` wrapper) and Framework recovery of an absent enabled override (`getSetting()` rather than the inheriting `getProjectSetting()`). The audit fixture now reflects the real API. No ownership or hook-contract change was needed.

The new gated, preview-first management harness passed on PHP 8.2/8.5 in PID 533. It exercises real Core rights, view/projection, settings/audits and Framework enablement, including failed enablement preserving an absent override, duplicate/unavailable retention and explicit nonassignment. Writes are rollback-contained; independent dev-control checks confirm restoration. The extended pipeline harness passed in PID 524 on both runtimes: five actual REDCap PDF fixtures through both entry points with signature/timestamp/content/hash checks, plus real Sealer dispatch under successful and failed synthetic reserved Core actions. Sealer yields unchanged; Core executes last and required failure blocks commitment.

Core **90 tests/356 assertions** and Framework **32 tests/193 assertions** still pass on both runtimes. [Testing](testing.md#native-coreframework-acceptance--2026-10-04) records exact prerequisites and limitations. Computer-use tooling could not launch, so no interactive browser acceptance is claimed. Activation-request/global-enable UI and actual stored/delivered artifact correlation remain next. No production Core terminal implementation, PKI port, edoc or outbound email was added; XML/PMT transport remains deferred.

### Slice 3 — Core plan management and Sealer status

`PdfExecutionPlanManager` now owns operation catalog projection, ordered assignment state, warnings, workflow previews, placement decisions, designer saves and audit/recovery. It consumes full declarations from the existing provider and preserves the stored identifier-list setting, duplicate occurrences and unavailable entries. Core uses the neutral warning `operation_unavailable` rather than asking Framework for a second module inventory just to distinguish unavailable modules from removed operations. A new unavailable identifier cannot be inserted through a designer save; previously stored unavailable intent remains editable and retainable.

Project Setup → PDF Finalization opens the Core-owned editor and endpoints, even without a Framework/provider or active EM operations. The existing Framework management button and routes delegate to the same implementation. Framework supplies pending declarations and enablement/restoration callbacks; Core owns the placement handshake and plan snapshots. Configured projects require an explicit decision when a new module has no placement, including explicitly leaving its operations unassigned. Global enablement queries Core for placement requirements and retains Framework's existing enabled-module overrides without inserting operations.

Core project membership permits read-only inspection; writes require Design/Setup rights or a nonimpersonating administrator. Both standalone saves and enablement-submitted plans enforce this requirement. The save endpoint checks the native CSRF token explicitly; the legacy Framework wrapper captures it before bootstrap consumes it. Changed plans are audited, persistence is verified by readback, and write/audit/explicit-enablement failures restore prior plan state. Framework restores its enabled override on a failed explicit placement handshake. This is compensating recovery, not an atomic transaction spanning EM hooks; arbitrary hook side effects and initialized defaults are outside generic rollback.

The editor requests updated previews from Core after order changes. `PdfFinalizationPolicyResolver::getWorkflowPolicies()` selects policies through the same `resolve()` used at runtime, without checking readiness or executing finalizers. Core steps are displayed after the applicable EM segment as fixed actions and never enter the editable/submitted identifier list. Reserved workflows explain the nonterminal requirement without disallowing terminal-capable EM declarations or falsely reporting that their accepted terminal result will stop later steps. Future conditional/instrument-specific contexts must be enumerated in Core and marked as representative rather than covering an entire document type. Adding an action or preview requires only Core changes.

PDF Sealer status consumes Core's management contract and projection. Fully reserved eConsent types report **Core finalization reserved** and explain that EM sealing returns unchanged; conditional/mixed reservations prompt review of the previews. Reservations for unrelated document types do not affect eConsent sealing status. Assignment and reservation remain distinct from readiness and success. Administrator guidance points to the Core interface.

Verification: **Core 90 tests/356 assertions**, **Framework 32 tests/193 assertions** on PHP 8.2.34/8.5.11; **six Node tests**; focused PDF Sealer support/status and no-side-effect reservation checks on both PHP versions; changed PHP syntax and whitespace checks. Core checks include actual save handlers with isolated bootstrap/security doubles, project membership, impersonation, expiry, CSRF, audit/write failure, absent versus empty recovery, pending enablement, fixed action previews and Core-only view rendering. Browser controller checks cover permissions, fixed-action exclusion, stale previews and busy/cancel guards. Details and pending manual acceptance are in [testing](testing.md#core-execution-plan-management--2026-10-04).

No live database, settings, PKI, remote TSA or edocs were accessed or changed. Direct-copy semantics remain; XML/PMT plan transport stays deferred. No production Core terminal action or sealing/PKI port is installed. This supersedes slice 2's pending management/status ownership notes below.

### Slice 2 — active executor and provider

Core now executes the hook workflow through `PdfFinalizer` and `PdfFinalizationRunner`; it no longer delegates coordination to the Framework. It creates generation IDs, resolves the stored identifier list against a provider snapshot, filters document types, isolates every working copy, validates/adopts results, disposes rejected/intermediate artifacts, enforces reserved terminal ownership, and emits correlated pipeline/commit events. Both file and byte-string entry points enforce `canCommit()` and throw `PdfFinalizationRequiredException` for failed required Core finalization.

`PdfFinalizationPolicyResolver` is the Core-only selection point and currently reserves no production action. Trusted Core callers can supply a policy through the optional trailing argument to the existing entry points. `hasExecutionPlan()` includes the Core potential-action gate, so future selection can admit Core-only workflows through existing export gates without an EM plan. Tests execute two different synthetic actions without a Framework provider. An unavailable provider, including construction or catalog failure, is reported and cannot prevent the reserved Core action. Empty EM plans do not consult the provider. Failure does not release reservation or reopen EM execution.

The Framework implements `PdfOperationProvider` in `PdfFinalizeOperationProvider`: active declared-operation discovery, version/declaration recheck before invocation, generation-project scoping with restoration, and targeted dispatch through existing hook machinery. Its old execution methods delegate to Core; there is no independent adoption/execution loop. Existing Framework result objects are translated by the provider, while PDF Sealer now returns Core results. The legacy adapter remains for test/example consumers during the ownership move.

`PdfExecutionPlanRepository` now owns persistence in Core using the existing setting key, version boundary and identifier-list semantics. Framework persistence methods are delegates, retaining stored unresolved entries and duplicates. Configuration projection/warnings, the editor/endpoints, authorization/audit and enablement/default placement are still in Framework; those are the next slice. PDF Sealer's status currently reads that projection through its existing facade.

PDF Sealer checks the mandatory Boolean `terminal_action_reserved_for_core` before any Framework/PKI/logging service access for its applicable sealing operation. A reservation returns nonterminal unchanged; malformed/missing flags fail explicitly without sealing. Support detection now requires active `CONTRACT_VERSION >= 1` markers on both Core `PdfFinalizer` and Framework `PdfFinalize`; the presence of the earlier setting key or class alone is insufficient.

Installed-checkout verification on PHP 8.2.34 and 8.5.11:

- Core: **65 tests, 254 assertions** per runtime, including the earlier contracts, reserved ordering/rejection, cleanup, strict failure, Core-only extensibility, file/byte delivery, persistence via fake settings, and correlated artifact events.
- Framework: **32 tests, 193 assertions** per runtime, including existing execution regressions through the Core delegate, active operation catalogs, stale declaration rejection, result bridging, and real safeguarded hook dispatch with fake rollback queries. Success and exceptions restore hook/module/project context.
- PDF Sealer: reservation/no-side-effect and malformed-context checks, support notices (including legacy markers), and existing disposable sealing/PKI/timestamp regressions; detailed evidence is in [testing](testing.md#core-finalization-executor-and-provider--2026-10-04).

These are isolated source/contract/service checks. They do not establish browser, real storage/delivery or plan-editor acceptance. `redcap_devctl` is available; this slice needs no live database or edoc access and performed no live settings, PKI, remote TSA or edoc mutation. No production Core terminal action or sealing/PKI port was installed. No release archive was created.

### Slice 1 — contract foundation

Slice 1 now has executable Core-owned contracts under `Classes/PdfFinalization/`:

| Contract | Concrete API |
| --- | --- |
| Operation result | `Vanderbilt\REDCap\Classes\PdfFinalization\PdfFinalizeResult`, preserving the existing factory/accessor semantics |
| EM discovery/dispatch | `PdfOperationProvider::getOperations($projectId)` and `invoke($workingPdfPath, $resolvedOperation, $context)` |
| Reserved Core action | `PdfTerminalAction::getIdentifier()`, `getLabel()`, and `finalize($workingPdfPath, $context)` |
| Generation policy | `PdfFinalizationPolicy`: selected action, captured identity/label, reservation context, EM terminal-violation checks, and optional required success |
| Pipeline outcome | `PdfFinalizationOutcome`: last accepted path, generation ID, validated Core-action status, failure details, and `canCommit()` |

Discovery descriptors contain `identifier`, `module_prefix`, `module_version`, `module_name`, and the full validated declaration under `operation`. The provider returns mixed hook results so Core can report invalid consumers explicitly. A Framework implementation must retain existing targeted dispatch, exception handling, and transaction safeguards and check that active version/declaration still matches the resolved snapshot. Source inspection confirms that current hook dispatch rolls back before and after module invocation; artifact rollback must not be confused with an enclosing database transaction.

The policy always supplies the Boolean reservation flag, overriding any incoming context value. Successful terminal EM results are rejected under reservation; failed results cannot acquire terminal effect. Action identifier/label are captured independently of readiness. A completed reserved workflow must report succeeded or failed, never not reserved. Preservation remains the default; strict required success is an explicit Core policy and blocks commitment on failed action status. The status is supplied after coordinator validation, not copied blindly from a handler's raw result.

The isolated Core `UnitTests/PdfFinalization/PdfFinalizationContractsTest.php` suite passes on PHP 8.2.34 and 8.5.11: **39 tests, 100 assertions** on each runtime. All six new PHP files pass syntax checks. These tests load no application bootstrap, Framework, database, remote service, or PKI state. `Classes/PdfFinalization/README.md` documents the APIs and standalone commands.

**Transition boundary at the end of slice 1 (superseded by slice 2):** The active `Classes/PdfFinalizer.php` and Framework executor remain unchanged. The new result type is not yet the active hook return type; PDF Sealer still uses the Framework result and has not yet been adapted to reservation. No Core action is installed or selected. Class presence alone must not be used as a marker for active Core coordination. Publish a versioned active-contract marker when the coordinator/provider wiring is installed, and adapt support detection with that transition. The synthetic new-action extensibility acceptance, working-copy enforcement, and live storage/delivery checks remain pending.

Planned follow-up after slice 1: move the executor and correlation into Core, implement the Framework provider, and switch result consumption together. Slice 2 implements this plus persistence and reservation/support adaptation; Core management and status projection remain pending. No module package, live settings, certificates, or edocs changed in the contract-foundation slice.

## 1. Objective and agreed decisions

Move PDF finalization coordination and PDF Finalization Execution Plan management into REDCap Core. The Framework supplies the declared operations of active EMs and dispatches individual hook invocations. Core owns ordering, applicability, working copies, result acceptance, terminal enforcement, audit correlation, and the handoff to artifact storage or delivery.

The refactor must make it possible to add a terminal PDF finalization action later, entirely within Core, for any supported PDF workflow. Adding such an action must require no further Framework or EM changes. No sealing, certificate, TSA, or eConsent policy belongs in the Framework discovery/dispatch contract.

Agreed decisions:

- Discover individual declared operations, not merely interested modules. An EM can declare several independently ordered operations.
- Core owns the execution plan and management interface.
- Core selects at most one reserved terminal action for a particular PDF generation, after the editable EM portion of the plan.
- Every hook invocation receives an immutable Boolean context field, `terminal_action_reserved_for_core`.
- When the flag is true, successful EM results must be nonterminal. Core enforces this regardless of whether an EM reads the flag.
- PDF Sealer consumes the flag and returns nonterminal `unchanged` for its sealing operation when Core reserves termination.
- Without a Core reservation, existing declared/runtime EM terminal behavior remains available.
- The stack is greenfield. Establish clean contracts by updating Core, Framework, and PDF Sealer together now.

This plan prepares for built-in sealing. It does not port PDF Sealer's PKI, administration, or cryptographic services into Core, or decide to publish or abandon the EM.

## 2. Current implementation and gaps

The starting stack provided Core entry points and a transactional EM finalization pipeline; this table records the ownership baseline for the remaining refactor. The [historical finalization plan](redcap_module_pdf_finalize_implementation_plan.md) and [PR description](redcap_module_pdf_finalize_pr_description.md) retain the original rationale. This plan supersedes their ownership and unrestricted-ordering assumptions where Core reserves termination.

| Component | Current location | Required change |
| --- | --- | --- |
| Core entry points | Core `Classes/PdfFinalizer.php` | Own execution, generation IDs, and commit correlation instead of delegating them |
| Execution/configuration state | Framework `classes/PdfFinalize.php` | Move coordination, resolution, and configuration projection into Core |
| Result value object | Framework `classes/PdfFinalizeResult.php` | Establish a Core-owned result contract |
| Plan editor | Framework `manager/js/pdf-finalize-plan.js` and `manager/templates/pdf-finalize-plan-modal.php` | Move authoritative management into Core |
| Plan endpoints | Framework `manager/ajax/get-pdf-finalize-plan.php` and `save-pdf-finalize-plan.php` | Core owns authorization, validation, writes, and audit |
| Plan storage | Core setting `EXTERNAL_MODULES_PDF_FINALIZE_EXECUTION_PLAN` | Already in Core; ownership transfer need not rename it |
| PDF Sealer hook | [Adapter](../PDFSealerExternalModule.php) and [service](../src/Pdf/PdfFinalizeService.php) | Consume the reservation flag and new result contract |
| PDF Sealer status/support | [Pipeline status](../src/Pdf/ProjectPipelineStatus.php) and [support detection](../src/Pdf/SealingSupport.php) | Read Core-owned state and reliable contract capabilities |

An accepted terminal EM result currently stops the Framework loop; appending a Core action would not ensure it runs. Missing Framework finalization support bypasses Core finalization entirely. Some callers also gate entry on a nonempty EM plan. Path/string returns and broad exception fallback cannot distinguish failed required finalization from successful processing.

Existing [pipeline acceptance](pdf_pipeline_acceptance.md), [testing](testing.md), and [implementation status](implementation_status.md) provide a regression baseline, not evidence that this refactor works.

## 3. Ownership boundary

| Core owns | Framework provides |
| --- | --- |
| Plan persistence, configuration state, permissions, editor, and audit | Validated operation declarations from active project EMs |
| Generation context, document types, filtering, and effective plan | Module identity/version and stable operation identity |
| Working copies, result validation, adoption, cleanup, and terminal enforcement | Targeted hook dispatch with existing Framework safeguards |
| Core action selection, invocation, failure policy, and final artifact | Declaration validation during installation/enablement |
| Generation IDs and pipeline/commit events | Enablement handoff into Core when placement is required |

Framework must have no independent executor or authoritative plan editor after the move.

### Operation discovery and dispatch

Discovery returns each declared operation, including:

- Stable identifier, `module_prefix:operation_id`.
- Module prefix, active version, and display name.
- Resolved declaration: `id`, `purpose`, `document_types`, and `is_terminal`.

Core resolves stored identifiers against this catalog and composes the workflow. Do not trust client-supplied versions or declarations. Take a consistent policy/catalog snapshot for each generation and report an operation becoming unavailable before dispatch explicitly.

A narrow dispatch entry point receives one resolved operation, a Core-owned working-copy path, and immutable context. It invokes exactly one hook through existing Framework machinery and returns its result or failure to Core.

Preserve Framework exception and database-transaction safeguards; do not call module methods directly. Artifact rollback is distinct from database transaction handling and does not undo arbitrary EM side effects.

Exact API names and namespaces are selected in the contract slice. Avoid introducing a general plugin registry or callback platform.

## 4. Core execution model

Core resolves applicability from trusted project/system configuration and the original generation context. Selection may use document type, generation reason, instrument, snapshot policy, or delivery target.

```text
Complete PDF construction and ordinary transformations
    ↓
Resolve Core policy and optional terminal reservation
    ↓
Run designer-ordered matching EM operations
    ↓
Run reserved Core terminal action, if selected
    ↓
Apply failure policy and select final artifact
    ↓
Hash / store / deliver exactly those bytes
```

Persist the editable EM identifier list separately from Core action configuration. The reserved action is derived and cannot be impersonated through a posted EM identifier, moved, duplicated, or removed in the EM plan editor.

Preserve duplicate EM occurrences, wildcard/type matching, unresolved-entry retention, and isolated failure handling.

Core actions use the same working-copy acceptance/cleanup guarantees. A selected action runs with an empty EM plan or no EM provider. Failure to access an expected Framework provider must be reported rather than presented as a normal empty catalog.

### Core terminal-action interface

A small internal Core interface is sufficient, conceptually:

```php
interface PdfTerminalAction
{
    public function finalize(
        string $workingPdfPath,
        array $context
    ): PdfFinalizeResult;
}
```

The result belongs to Core; names and namespaces are provisional. A Core descriptor supplies a stable action identifier and display label. A Core resolver selects the action for the generation. Runtime handlers are not serialized into settings.

If several Core policies select different actions for one generation, resolve precedence explicitly or report a configuration error before execution.

Use an injectable synthetic action in tests. No production sealer or user-visible dummy configuration is needed in this refactor. Success finishes byte-level processing; failure never reopens EM execution.

## 5. Hook context and terminal enforcement

Retain the three-argument hook shape, using the Core-owned result type:

```php
redcap_module_pdf_finalize(
    string $temporaryPdfPath,
    array $operation,
    array $context
): PdfFinalizeResult
```

Every invocation receives:

```php
$context['terminal_action_reserved_for_core'] = true; // or false
```

Meaning:

> For this PDF generation, Core reserves the terminal action. EM operations may process the PDF but must return nonterminal results.

The Boolean is mandatory on the new contract and fixed across a generation's EM invocations. Context describes the original generation; EMs cannot use it as a mutable coordination channel. Retain existing project/record/event/instrument context and null/absence conventions.

Reservation expresses ownership, not readiness. Missing keys, certificates, services, or handlers must not clear the flag and permit an unexpected EM takeover. Core handles failure while preserving the reservation.

| Result/condition | Core behavior |
| --- | --- |
| Valid `modified`, nonterminal | Adopt working copy and continue |
| `unchanged`, nonterminal | Discard working copy; retain preceding bytes and continue |
| Failed, exceptional, invalid result, or invalid PDF | Discard that output; report failure and retain preceding bytes |
| EM terminal result without declared capability | Reject as the existing declaration-contract violation |
| EM terminal result while Core reserves termination | Reject output; report reservation violation and continue |
| Accepted EM terminal result without reservation | Preserve existing behavior: stop later EM operations |
| Successful reserved Core action | Adopt validated result and finish modification |
| Failed reserved Core action | Retain preceding artifact and apply Core policy; no further EM calls |

Failed results never acquire terminal effect. An unchanged-terminal EM result also violates a reservation.

Do not silently rewrite terminal results to nonterminal. The EM may have certified its output on the assumption that nothing follows; adopting those bytes could invalidate its contract.

Terminal-capable declarations remain valid before Core actions because capability does not require terminal behavior on every invocation. Show the restriction in configuration and enforce actual results at runtime.

This governs cooperating finalization consumers. It is not a security boundary against arbitrary PHP or unrelated hooks modifying artifacts outside this workflow.

## 6. Results and failure policy

Move or introduce the operation result in Core with the existing useful semantics: `modified`, `unchanged`, `failed`, accepted output path, terminal flag, metadata, and safe error details. Core-only execution must not require a Framework result class.

Update Framework and PDF Sealer together. A minimal compatibility translator is optional if actual consumers require it; never retain a second executor as a compatibility mechanism.

Distinguish operation results from the coordinator's structured overall outcome. Include the selected artifact, generation ID, reserved-action identity/status, and failures. Callers must be able to distinguish unsuccessful required finalization from success.

Retain preservation of the last accepted PDF as the regression baseline. Do not silently make successful sealing mandatory. Establish a Core-owned policy mechanism now so later workflows can block commitment on required-action failure without changing Framework or EM contracts.

For preservation workflows, return the preceding artifact with an explicit unsuccessful-action outcome. For strict workflows, Core blocks commitment through its own handling. Broad path/string fallback catches must not erase that distinction.

Define temporary-file ownership and cleanup for success, fallback, blocked commitment, and caller exceptions.

## 7. Execution-plan management in Core

Provide Core-owned management and endpoints under Core project permissions, available even without active EMs. Display the same effective workflow runtime executes:

```text
Completed eConsent PDFs

1. Institutional watermark       [move / remove]
2. Metadata normalization        [move / remove]
3. REDCap PDF sealing            [fixed final step]
```

The sealer is illustrative; it is not implemented by this refactor.

Required behavior:

- Designers explicitly assign, remove, duplicate, and order EM operations.
- A reserved Core action appears as a fixed final step. Configure it separately under appropriate Core permissions.
- Show previews by document type/workflow; one operation may be restricted for eConsent yet terminal for record PDFs.
- Explain that overlapping terminal-capable EMs must return nonterminal results. Do not reject capability declarations alone.
- Retain unavailable stored EM entries and warnings instead of silently deleting or reordering intent.
- Show reservation separately from action readiness; missing prerequisites do not remove the fixed step.
- Opening the interface never enables actions or executes finalizers.
- Reevaluate module/policy changes, including upgrades and enablement changes.
- Enforce policy in server-side configuration and runtime, independently of browser controls.
- Audit plan changes and restrict writes to authorized designers.

Framework enablement can query Core for placement requirements and open/link the Core editor. Remove its separate editor and write implementation. Preserve explicit placement decisions; global enablement must not silently insert newly available operations.

## 8. Persistence and caller integration

Retain the current identifier-list setting initially unless a concrete requirement justifies migration. Preserve order, duplicates, and unresolved identifiers. A setting-key rename requires explicit migration and coordinated capability detection.

Core owns copy/transfer semantics. Preserve existing direct-copy behavior and implement XML/PMT execution-plan transfer as part of this refactor. Preserve exact order, duplicates, unavailable identifiers and absent versus explicit empty state; honor existing metadata-selection controls. Omitted payloads must not erase existing target plans. Validate imported structure before persistence and use native import transaction/error handling. Transfer the plan without changing module activation or transferring signing identities, keys, project bindings or instance PKI. Test/document native XML and PMT behavior.

Audit existing finalization callers:

- `Classes/PDF.php`: browser/API, inline, and all-record exports.
- `Classes/PdfSnapshot.php`: repository snapshots and PDF file-field saves.
- `Classes/Survey.php`: generated confirmation-email attachments.
- `Classes/REDCap.php`: `REDCap::getPDF()`.
- Record-locking archives and other existing callers.

Replace EM-plan-only guards with Core applicability checks for EM operations or selected Core actions. Retain blank-form bypass and avoid double finalization in nested generation paths.

Review actual generation context. Do not accidentally broaden eConsent eligibility by conflating a governed snapshot with an export merely containing completed consent.

Generation IDs, pipeline events, hashes, and commit/delivery correlation belong to Core and work without Framework. Distinguish action success from storage/delivery success. No merge, metadata edit, backend re-output, or other byte-changing step follows finalization.

## 9. PDF Sealer adaptation

Check reservation in the operation service, before sealing side effects, so direct tests and hook dispatch share behavior:

```php
if ($context['terminal_action_reserved_for_core']) {
    return PdfFinalizeResult::unchanged();
}
```

The result is Core-owned and nonterminal. Core guarantees the field; any support for an older stack must be explicit and tested. Do not treat malformed new context as permission to seal.

Preserve operation/type applicability checks. With no reservation, eligible eConsent sealing retains its current terminal behavior. With reservation, preserve identical bytes and perform no identity issuance, key access, timestamp request, sealing, or sealing-success logging. A generic unchanged operation event is acceptable.

Update:

- Hook return type/imports and result factories.
- Pipeline status to use Core state and distinguish assigned-but-skipped from active EM sealing.
- Support notices to check agreed contract capabilities rather than a Framework coordinator class.
- Project/admin documentation and focused tests.

Preserve the development-only `pdf_sealer_v9.9.9` directory convention. No live PKI changes, project reassignment, or release metadata changes are needed for this adaptation.

## 10. Implementation slices

### Slice 1 — Establish contracts

Select concrete APIs/namespaces, result/outcome types, discovery/dispatch signatures, capability detection, immutable context, and Core action selection. Map Framework transaction handling and caller fallback behavior before moving code.

Deliverable: Core-only execution with no Framework dependency and a purpose-neutral extension contract.

Suggested commit: `Define Core-owned PDF finalization contracts`.

### Slice 2 — Move coordination

Move execution, effective-plan resolution, working copies, terminal enforcement, and event correlation into Core. Implement Framework discovery/targeted dispatch; remove its independent executor. Inject a synthetic terminal action in tests.

Deliverable: one engine supports EM-only, Core-only, and mixed workflows.

Suggested commit: `Move PDF finalization coordination into REDCap Core`.

### Slice 3 — Move plan management

Move state projection, endpoints, editor, permissions, audit, and enablement handoff. Show fixed Core actions and workflow restrictions. Preserve storage/direct-copy behavior and document deferred transfer support.

Deliverable: one Core-owned interface uses the same policy as execution.

Suggested commit: `Manage PDF finalization execution plans in Core`.

### Slice 4 — Adapt PDF Sealer and callers

Update reservation handling, result contract, status, support, and guides. Replace EM-only caller guards and carry structured outcomes through storage/delivery while preserving bytes and cleanup.

Deliverable: PDF Sealer seals normally without reservation and skips without side effects with reservation.

Suggested commit: `Adapt PDF Sealer to Core terminal reservations`.

### Slice 5 — Demonstrate extensibility and acceptance

After contracts are frozen, add another synthetic action using only Core changes. Exercise file/contents entry points, changed delivery paths, failure policies, and the editor. Record scoped evidence.

Deliverable: future Core terminal actions require no further Framework or EM edits.

Suggested commit: `Verify Core terminal actions across PDF workflows`.

### Slice 6 — Transfer execution plans through XML and PMT

Add the Core ODM adapter and shared metadata-option integration described in the
2026-10-08 scope update. Retain native module-activation semantics and transfer
only the execution plan. Verify round trips, validation/rollback and native
XML/PMT acceptance without repeating unrelated completed browser tests.

Deliverable: Core-owned plans survive project XML and PMT transfer, alongside
the already supported direct-copy path.

Suggested commit: `Transfer PDF finalization execution plans through XML and PMT`.

### Slice 7 — Exercise Core terminal-action enablement

Implement the explicitly test-only Core terminal action and Core-owned controls
described in the 2026-10-08 scope update. Confirm control granularity, keep normal
defaults off, and exercise the selected fixed step through the existing engine.
Finish acceptance with the dummy action and test gate deactivated. Actual
cryptographic Core sealing and PKI remain separate future work.

Deliverable: usable/tested Core enablement and reservation plumbing, with no
active placeholder masquerading as a production sealer.

Suggested commit: `Add test-only Core terminal finalization controls`.

Coordinate dependent changes across repositories so development installations do not run mismatched contracts.

## 11. Acceptance criteria

1. Core owns execution and authoritative plan management; Framework only discovers operations, dispatches them, and integrates enablement.
2. Multiple operations per EM, active versions, wildcard matching, duplicates, and unavailable entries resolve correctly.
3. Every invocation receives the Boolean reservation flag and original generation context.
4. Without reservation, accepted EM terminal results stop later operations as before.
5. With reservation, modified-terminal and unchanged-terminal EM results are rejected without adopting bytes; later operations and Core action still run.
6. Failures, exceptions, invalid outputs, and violations preserve the last accepted PDF and clean up working files.
7. Reserved PDF Sealer invocations return unchanged identical bytes without issuance, key access, timestamp calls, or success logs. Normal sealing remains functional.
8. Core actions run with an empty EM plan or no EM provider; unavailable prerequisites never clear reservation.
9. Reservation is per generation/workflow; unrelated workflows retain normal terminal behavior.
10. Core actions run exactly once at the end. Failure cannot reopen EM processing.
11. Editor, endpoints, enablement, and runtime agree on policy, fixed steps, warnings, and permissions.
12. Existing plans and direct copies survive; XML and PMT transfer preserve order, duplicates, unavailable identifiers and absent/empty semantics without implicitly enabling modules or transferring signing identities. Invalid imports roll back, and omitted/unselected plan metadata leaves existing target plans intact.
13. Caller guards do not bypass Core-only actions, and nested paths do not finalize twice.
14. Generation IDs and hashes correlate final/storage/delivery artifacts; action failure and commitment failure remain distinct.
15. Add another synthetic terminal action for a different workflow using only Core code; frozen Framework and adapted EMs work unchanged.
16. The test-only Core terminal action can be enabled/disabled through authorized Core controls, appears as the fixed terminal step, and causes EM Sealer to yield. It is clearly identified as a non-signing test action and is deactivated after acceptance; production defaults/gates cannot activate it accidentally through project transfer.

Use focused engine/contract tests and relevant existing PDF Sealer suites. Reuse cryptographic/content-preservation tests where integration could change bytes. Repeat real storage/download signature/hash acceptance for changed delivery paths; unit tests do not establish browser, email, or Acrobat acceptance.

For local database/edoc work, prefer `redcap_devctl` and preview mutations. Use synthetic/disposable projects. Outbound test email requires explicit authorization. Synthetic-action tests require no live PKI mutation.

## 12. Follow-on work and boundaries

Built-in sealing is a separate later Core action. Its port includes PKI/provider/binding/revocation persistence, serial allocation, administration, scheduled maintenance, stable certificate/CRL routes, dependency distribution, and any migration of existing EM identities.

Successful sealing as a delivery prerequisite is a separate workflow policy decision; this refactor establishes its enforcement mechanism.

No PAdES B-LT/B-LTA, provider UI redesign, key/certificate replacement, or module publication is included. XML/PMT execution-plan transfer is required within this refactor following the 2026-10-08 scope update.
