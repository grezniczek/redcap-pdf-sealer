# Core-owned PDF finalization — change plan

**Status:** Agreed direction; implementation pending.  
**Date:** 2026-10-03.  
**Scope:** REDCap Core, the External Module Framework, and PDF Sealer's integration contract.

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

The current stack provides Core entry points and a transactional EM finalization pipeline. The [historical finalization plan](redcap_module_pdf_finalize_implementation_plan.md) and [PR description](redcap_module_pdf_finalize_pr_description.md) retain the original rationale. This plan supersedes their ownership and unrestricted-ordering assumptions where Core reserves termination.

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

Core owns copy/transfer semantics. Preserve existing direct-copy behavior. XML/PMT plan transfer is a separately scoped deferred feature: moving ownership must not erase plans or claim missing transfer support is implemented. Test/document actual behavior; certificate and project-identity transfer is outside this refactor.

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
12. Existing plans and direct copies survive; XML/PMT limits are accurately tested/documented.
13. Caller guards do not bypass Core-only actions, and nested paths do not finalize twice.
14. Generation IDs and hashes correlate final/storage/delivery artifacts; action failure and commitment failure remain distinct.
15. Add another synthetic terminal action for a different workflow using only Core code; frozen Framework and adapted EMs work unchanged.

Use focused engine/contract tests and relevant existing PDF Sealer suites. Reuse cryptographic/content-preservation tests where integration could change bytes. Repeat real storage/download signature/hash acceptance for changed delivery paths; unit tests do not establish browser, email, or Acrobat acceptance.

For local database/edoc work, prefer `redcap_devctl` and preview mutations. Use synthetic/disposable projects. Outbound test email requires explicit authorization. Synthetic-action tests require no live PKI mutation.

## 12. Follow-on work and boundaries

Built-in sealing is a separate later Core action. Its port includes PKI/provider/binding/revocation persistence, serial allocation, administration, scheduled maintenance, stable certificate/CRL routes, dependency distribution, and any migration of existing EM identities.

Successful sealing as a delivery prerequisite is a separate workflow policy decision; this refactor establishes its enforcement mechanism.

No PAdES B-LT/B-LTA, provider UI redesign, key/certificate replacement, module publication, or deferred XML/PMT transfer is included.
