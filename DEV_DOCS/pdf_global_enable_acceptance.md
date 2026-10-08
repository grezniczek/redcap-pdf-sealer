# Global PDF-operation enablement browser acceptance

## Fixture — 2026-10-08

Use the local development module **PDF Finalization Acceptance (test only)**,
prefix `pdf_finalize_acceptance`, directory `pdf_finalize_acceptance_v9.9.9`.
Its source is retained in `tests/fixtures/pdf_finalize_acceptance_v9.9.9/` and
copied to the shared development-module directory for this browser test.
It declares `first` and `second`, both nonterminal and applicable to all PDF
types; both return Core's `unchanged(false)` without reading/writing a PDF.
There are no settings defaults, project-enable hooks, page hooks, links, cron,
PKI operations or email behavior. Do not publish this fixture.

This tests the real Framework system configuration and Core placement query on
the main development instance. The **module** is isolated from PDF Sealer;
the instance is not a separate database. Global enablement can add this fixture's
disabled overrides to existing configured projects that lack its operations.
Their execution plans must remain untouched. Projects without a saved plan
inherit the fixture's global default, but no operation is inserted or executed
merely by enabling it. Cleanup will remove only this fixture's test settings.
Keep PDF Sealer's system/project settings and PKI unchanged.

Preflight found no existing module registration or directory for this prefix.
Before installation, PIDs 524 and 533 retained `["pdf_sealer:seal"]`, Sealer
enabled, and Sealer's system default was disabled. Recheck those sentinels
after the test and cleanup.

## Browser sequence

1. As administrator, enable the test module at the **system** level through
   Control Center → External Modules. Leave **Enable module on all projects by
   default** unchecked. This registers the installed version; no project gets
   an operation assignment.
2. Create three new, blank development projects through REDCap's normal UI:
   **PDF Global Test — Empty**, **PDF Global Test — Placed**, and
   **PDF Global Test — Default**. Record their PIDs. Use these for mutations;
   PIDs 524/533 are unchanged sentinels. Native project creation is performed
   through the UI because dev-control currently has no previewable native
   project/service test runner; raw SQL project creation is not equivalent.
3. In **Empty**, open Core PDF Finalization and save an explicit empty plan.
   Leave the fixture disabled. Verify a stored `[]` rather than an absent plan.
4. In **Placed**, enable the fixture for the project and save the exact plan
   `["pdf_finalize_acceptance:first","pdf_finalize_acceptance:second",
   "pdf_finalize_acceptance:first"]`. Confirm the duplicate warning and order.
   Disable the fixture for this project, retaining the saved plan. This prepares
   an existing disabled override that the normal global-enable transition clears
   because placement already exists.
5. In **Default**, leave the Core plan absent and the fixture's project override
   absent. Do not save a plan or enable the fixture for this project.
6. Recheck all three starting states, then in Control Center configure the test
   module, check **Enable module on all projects by default**, and save. Expect
   a warning listing projects skipped because placement is required. The list
   can include existing configured projects as well as **Empty**; it must exclude
   **Placed** and **Default**. Dismiss the warning and reopen configuration to
   confirm the system checkbox persisted.
7. Verify the matrix below using browser availability and independent dev-control
   reads. Counted usage, duplicate occurrences, and ordering must survive exactly.
   Plan-change audits must not appear merely from toggling global enablement.
8. Uncheck the system default and save. Verify the skipped-project warning does
   not recur for this transition, and no plan changes occur.

| Project | Saved plan before/after global enablement | Expected effective availability |
| --- | --- | --- |
| Empty | `[]` | Disabled by the skip override; named in warning |
| Placed | `first`, `second`, `first` | Enabled through the system default; not named in warning |
| Default | No saved plan | Enabled through the system default; no plan created; not named in warning |

The fixture has no single-project side effects to undo. The global setting uses
normal Framework behavior for enabled overrides; it is not an atomic rollback
of every operation should some later configuration hook fail.

## Cleanup and evidence

Setup evidence on 2026-10-08: **PDF Global Test — Empty**, PID **536**, was
created through the browser in development status. Its initial Core plan and
fixture enabled override were absent. After the user saved the empty execution
plan through Core's editor, dev-control confirmed the stored value `[]` and
an absent fixture enabled override. With the system default still off, the
fixture remains disabled for this project. The Empty starting state is ready;
this does not establish the later global-enable warning/skip result.

**PDF Global Test — Placed**, PID **537**, was created in development status
with no saved plan or fixture override. As superuser `gr`, the user enabled the
fixture for this project; dev-control confirmed `enabled=true` and no automatic
plan creation. The user saved `first`, `second`, `first` and confirmed the
duplicate warning. After the user disabled the fixture, dev-control confirmed
`enabled=false` with the exact saved plan
`["pdf_finalize_acceptance:first","pdf_finalize_acceptance:second","pdf_finalize_acceptance:first"]`
retained. The Placed starting state is ready; global enablement must later clear
this disabled override while preserving every occurrence and its order.

Disable the fixture through Control Center after testing, then remove only its
copied development directory. Native system disable removes the active version
but retains other settings; inspect these and use previewed, prefix-scoped
dev-control cleanup for any remaining fixture settings. Remove its assigned entries from the
disposable project before retaining that project for another purpose; otherwise
delete the three disposable projects through REDCap's normal UI. Check no fixture
settings/active version remain and the sentinel plans/Sealer state are unchanged.
A disabled registry entry may remain as normal Framework metadata.
Keep this source fixture and the test results in the module checkout.

Record actual project IDs, browser warning text/list, saved states and native
audits. Module discovery, global save, and cleanup are user-directed browser
actions; the agent uses dev-control for read-only inspection. Existing automated
Core/Framework skip/override tests do not replace these browser results.

**Status:** source fixture installed and enabled at system level through the
browser. Dev-control confirms registration ID 83, active version `v9.9.9`, and
no system `enabled` setting, so the global project default remains off.
Disposable project setup and global-enable browser acceptance are pending. JSON and PHP syntax
checks pass on PHP 8.2/8.5. An isolated hook smoke check confirms both operations
return nonterminal unchanged with either reservation flag and without an input
file. Installed copies match the tracked fixture. No native bootstrap or live
database mutation occurred during preparation. Sentinel plans/Sealer state are
unchanged. No browser global-enable pass is claimed.
