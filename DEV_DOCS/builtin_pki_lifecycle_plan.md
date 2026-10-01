# Automatic maintenance of built-in PKI

## Status and target — 2026-10-01

**Design only; automatic maintenance and revocation are not implemented.** The user clarified that the built-in root CA, TSA and dependent project certificates must require no routine administrator renewal or rotation. This replaces the earlier proposal for manual TSA renewal, staged root activation and subsequent manual project renewals. See [current status](implementation_status.md) and the broader [provider design](provider_lifecycle_design.md).

After initial setup, the module should generate, validate and deploy built-in replacements automatically through Framework cron. An administrator explicitly revokes an identity when necessary; replacement and deployment then run automatically. External CA enrollment and externally supplied certificates keep their required manual workflows. External TSA services maintain their own signing certificates.

## Feasibility and trust boundaries

Certificate generation and activation inside REDCap are feasible without routine administrator intervention. Root replacement can likewise be generated and activated automatically, with historical public roots retained on the trust page.

A fresh root key creates a new trust anchor. Its publication on the trust page does not cause Acrobat or another external validator to trust it. [RFC 5280, section 6.1.1](https://www.rfc-editor.org/rfc/rfc5280.html#section-6.1.1) defines the anchor by trusted issuer name and public key supplied through a trustworthy external procedure; [Adobe documents certificate trust configuration](https://helpx.adobe.com/acrobat/using/trusted-identities.html). Managed viewer installations can have separate institutional trust distribution. Arbitrary survey respondents' viewers are outside the module's control.

Therefore, **automatic REDCap operation is the target; uninterrupted third-party trusted status after a root key change is not promised**. New PDFs can remain correctly sealed while a viewer reports an unknown root. Before implementing root rollout, confirm whether preserving already configured viewer trust is also a requirement. A stable anchor with rotating intermediates or a cross-signing strategy would be a separate architectural decision requiring interoperability evidence. Reissuing a certificate with the same name/key must not be assumed to preserve every viewer's trust settings, and cannot remedy compromise of that key.

Routine rotation does not rewrite existing PDFs, retrospectively extend their validation lifetime, or implement B-LT/B-LTA.

## Proposed maintenance policy

Use a dedicated, bounded Framework maintenance cron, separate from the existing daily read-only expiry scan. An hourly run is a starting recommendation; it must handle downtime by processing overdue work on the next run.

| Managed item | Automatic trigger | Deployment |
| --- | --- | --- |
| Built-in root | Renewal window, expiry after downtime, or explicit revocation | Generate a fresh root/TSA pair and atomically update active references |
| Built-in TSA | Renewal window, expiry after downtime, explicit revocation, or root replacement | Generate a fresh key/certificate under the current usable root |
| Existing built-in project signer | Renewal window, expiry, explicit revocation, or previous root generation | Generate a fresh key/certificate under the current issuer; preserve project UUID/provider |
| External project identity/CA material | Approaching expiry or external revocation information | Report required enrollment/configuration action; do not replace with built-in credentials |
| External TSA | Source diagnostic or configured trust-chain problem | Report the problem and use only explicitly permitted alternatives/fallback |

Initial timing recommendations are 90 days before leaf expiry and a root window of one full leaf lifetime plus 90 days. With current lifetimes of 730 days and 3,650 days, that means root rollover before 820 days of remaining validity. Derive this from issuer policy rather than duplicating constants. These are implementation defaults to verify with fake-clock tests.

Maintain existing built-in project bindings, including retained bindings for temporarily disabled projects, without enabling modules or creating certificates for every project. Prioritize enabled projects and revoked identities; process a bounded number per run. A missed schedule must not require an administrator to click renew after re-enabling a project.

Preserve explicit provider assignments, the assignment gate, timestamp policy, external enrollments and pending provider transitions. The worker must never select the built-in CA for a project assigned to an external CA. Do not overwrite pending transition work; record deferred maintenance and alarm if it threatens availability.

Retirement remains distinct from revocation. Do not silently reactivate an intentionally retired provider or bypass its issuance prohibition. Built-in TSA/root maintenance may still be needed by other CA providers selecting the internal TSA; handle that dependency separately from project issuance. Review how existing built-in retirement controls fit the intended simple default administration before shipping.

## Prerequisites from current code inspection

1. **Issuer validity limits.** The fixed leaf lifetime can currently exceed its root's remaining validity. Cap every built-in TSA/project/diagnostic leaf to the lesser of its configured lifetime and the issuer's remaining whole-day window; verify the resulting expiry. If issuance has insufficient time remaining, maintenance must replace the root first. Signing paths must not silently reset PKI.
2. **Coherent identity capture.** The finalizer reads an internal source, checks health by reading it again, then constructs a provider. Capture the source's TSA/issuer/policy together and validate exactly those immutable versions. A writer transaction alone does not make separate reader queries coherent.
3. **Time and diagnostic versions.** Generate tokens using actual server UTC at token creation; a replacement TSA's validity must not be compared with an earlier request-start time. Cached diagnostics should record the identity versions tested and show when they precede a replacement without changing their original run time.
4. **Cron-safe issuance primitives.** Reuse crypto/transaction rules from manual renewal, with a system maintenance actor. Do not fabricate a human session or invoke CC AJAX handlers from cron.

These findings are not yet fixes or reproduced live failures.

## Activation, concurrency and recovery

Keep stable `builtin-ca` and `builtin-tsa` IDs and append immutable identity generations with encrypted private keys. Preserve exact issuer associations, public history and audit records. Validate certificate/key match, encrypted-key round trips, certificate profiles and a strict RFC 3161 sample before deploying a new pair. Private keys remain unavailable for download; use Framework temporary files where needed.

For root replacement, commit the new root/TSA identities, active pointers, built-in provider issuer, internal source issuer/identity and audit together. Preserve timestamp choices and all assignment/default policy. Publish the committed root automatically. There is no administrator candidate-download/acknowledgment step in routine maintenance.

Update projects in subsequent bounded transactions. The binding's recorded issuer determines whether work remains, so interruption can resume without rotating the same project repeatedly. An old, valid, nonrevoked signer stays usable until its replacement commits. After a root replacement, it may temporarily coexist with the new TSA and its independent chain.

Use the established **project → configuration** lock order. The root/TSA phase uses the configuration lock only and releases it before entering project work. Never hold that lock across external HTTP attempts. Serialize competing cron runs and administrative actions, recheck current references before committing, and make repeated runs idempotent.

A failed replacement keeps a usable previous identity active and retries with bounded backoff. An expired or revoked identity must never be used simply because replacement failed. Explicit revocation takes effect independently of replacement success, including checks that prevent an in-flight operation from completing with a revoked identity after the operation's defined activation boundary. Implement and test that boundary rather than assuming a captured identity remains usable.

Distinguish expected expiry/revocation recovery from corrupt settings, missing identity records or failed key decryption. Storage inconsistencies require an alarm and safe failure; cron must not erase history, choose an arbitrary old key or reset the installation. Serial reservations consumed by aborted issuance may remain gaps.

## Manual revocation and automatic replacement

Offer deliberate, confirmed CC revocation actions with public identity details, reason and audit:

- **Project:** block that generation; enqueue a fresh built-in project identity.
- **TSA:** block that generation; enqueue a fresh TSA under a usable root.
- **Root:** block local use of that root and dependent project/TSA generations; enqueue a fresh root/TSA pair and dependent project replacements.

A revoke request must durably record both the block and replacement work. A failed replacement cannot undo the block. External identities follow their external CA's revocation/re-enrollment process and must not trigger local substitution.

A local block prevents future module use; it does **not** inform Acrobat that an already distributed certificate is revoked. CRL publication and certificate distribution-point extensions, or another supported revocation service, require an explicit implementation slice. Withdrawing a self-signed root's trust from external viewer stores also remains outside the module. Do not label a local stop as externally verifiable certificate revocation before the corresponding mechanism exists.

Revocation reason matters for historical evidence. [RFC 3161, section 4](https://www.rfc-editor.org/rfc/rfc3161.html#section-4) describes compromised TSA keys as undermining tokens made with them. Automatic replacement restores future operation; it cannot repair previous tokens. Normal renewal must not automatically classify an old key as compromised.

## Administrator experience and alarms

CC should show current health, expiry, last successful maintenance, next due work and failed/deferred work. Routine built-in operation should require no renewal prompts, pending candidate management or per-project confirmation. Existing manual renewal need not be the primary workflow once cron maintenance is available.

Successful scheduled replacement gets a restricted lifecycle audit with system actor, reason, previous/new identity IDs, issuer references, fingerprints and time. No private material belongs in logs. Keep per-PDF project Logging minimal; maintenance is not a PDF sealing event.

Alarm on persistent failed maintenance, an overdue/stale worker, revoked identity awaiting replacement, or external certificates requiring action. Avoid routine expiry alarms for built-in identities that were already successfully replaced. Refresh expiry inventory after maintenance; keep the read-only monitor useful when the maintenance worker itself fails.

## Small implementation slices and acceptance

1. **Validity and capture prerequisites:** leaf validity caps, coherent internal timestamp references and versioned diagnostics. Verify crypto, expiry boundaries, concurrency and PHP 8.2/current-runtime behavior.
2. **Automatic leaf maintenance:** bounded cron for built-in TSA/project replacements, stable UUID/provider/history, system audit, downtime catch-up, retry/rollback and external/pending-work isolation. Accept browser status and a newly sealed PDF in Acrobat.
3. **Automatic root rollover:** resolve viewer-trust expectations, then atomic pair activation, resumable dependent project replacement and retained public root history. Test old/new issuer coexistence and strict timestamps with fake clocks before live rollover.
4. **Revocation and recovery:** reviewed manual block, prioritized automatic replacement, in-flight use boundaries and explicit public revocation scope. Implement separately from ordinary renewal.

No application code, live database, certificate, Core or Framework changes are made by this document. Packaged guides must continue to describe implemented capabilities until each slice is complete.
