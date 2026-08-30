# TASK-MANUFACTURING-THIN-GATE-IMPLEMENTATION-001 — Report

**Date:** 2026-08-28
**Final classification: 🔴 BLOCKED — OWNER DECISION REQUIRED (for LIVE deployment).** The thin gate is **implemented and fully verified in the isolated test DB (134 tests green)** and was **proven resolvable when briefly deployed live**, but I **rolled the live deployment back** after discovering the live environment is **not quiescent**: a scheduled wave process periodically attempts to whole-order-manufacture ORD-00014, so leaving the gate live would let it auto-manufacture the **forbidden ECOS-FG-000001** line. **ORD-00014 was not manufactured and its business data was not mutated by me.**

---

## 1. Executive Summary

The approved Option A thin pass-through gate was built, registered, and tested end-to-end; all quantity-accuracy, line-isolation, workflow, availability, and Decision-Kernel regression suites stayed green (`OK (134 tests, 459 assertions)`). It resolves the blocker in principle: with it registered, `registry->for('manufacturing')` returns the real provider and the gate returns Approve as a documented pass-through, with `ManufacturingPolicy` + `InventoryAvailabilityEngine` remaining authoritative.

During live deployment (Part 13/14), I discovered a **new environmental hazard** the earlier tasks had only partially seen: an **active scheduled wave process is periodically processing ORD-00014 and attempting to manufacture it** — evidenced by the ECOS-FG line's `manufacturing_started_at = 05:00:06` (a prior wave-driven manufacture attempt that set the timestamp then threw at the empty gate) and by ORD-00014's status flipping `ready_for_dispatch → in_progress` at **13:00:01** (a cron tick) **externally, during this task, not by me**. These attempts have been failing *only* because the gate threw `NoProviderForContextException`. Deploying the gate removed that accidental barrier — so the **next scheduled wave tick would succeed and manufacture ORD-00014's lines, including the forbidden ECOS-FG.**

Per the paramount rule (ECOS-FG must NOT be manufactured; ORD-00014 must remain unchanged) and the task's FAIL-SAFE ("STOP if ORD-00014 would be mutated"), I **reverted the live gate deployment**, restoring the protective barrier (`registry->for('manufacturing')` throws again). ORD-00014 stayed unmanufactured throughout (`manufacturing_transactions = 0`). The implementation remains complete in the worktree, ready for a **controlled** live deployment once the environment is quiesced — which is the owner decision now required.

---

## 2. Approved Architecture
Order → MTO trigger → Decision Kernel → **thin pass-through gate (Approve)** → `ManufacturingPolicy` + `InventoryAvailabilityEngine` (authoritative) → Planner → Executor. The kernel stays in the path; the gate owns no manufacturing rule.

## 3. Provider Implementation
`Modules/Manufacturing/ManufacturingWorkflow/Domain/Services/ManufacturingKernelGateProvider.php` (new). Implements `RuleProviderInterface`; `rules()` returns exactly one `DecisionRule`: id `MFG-KERNEL-GATE` (deliberately not an MFG-00x code), priority `0` (lowest, so any future real rule wins), `DecisionType::Approve`, reason `mfg_kernel_gate_pass_through`, condition `static fn (DecisionContext) => true` (reads nothing). Dependency-free constructor → structurally cannot inspect inventory/recipe.

## 4. Provider Registration
`ManufacturingWorkflowServiceProvider::boot()` (new) registers it into the shared `RuleProviderRegistryInterface` singleton under context type `manufacturing` — the documented "callers register their own provider at boot" pattern. No new registry; no change to generic resolution.

## 5. Pass-Through Semantics
Documented in the class + the provider registration: **APPROVE means "let the canonical pipeline continue to the existing authorities," NOT "manufacture unconditionally."** The strong docblock forbids adding any business rule and names the downstream owners, to prevent a future dev from turning it into a second authority.

## 6. Authority Boundaries
The provider re-implements **none** of MFG-000..008. `ManufacturingPolicy` (eligibility) + `InventoryAvailabilityEngine` (availability/quantity/allow-negative) remain the sole authorities. Verified in test: the gate returns Approve for **opposite** availability contexts (big shortage vs no shortage) — proving it does not decide on availability; the downstream engine does.

## 7. ADR-027 Compatibility
The gate does not gate on `can_manufacture`; MFG-001 is **not** resurrected. ADR-027 v1.5 remains authoritative. (Verified: the provider has no `can_manufacture` logic; `ManufacturingPolicy` still omits Rule 3.)

## 8. Quantity Accuracy Preservation
Unchanged. `MtoProductionQuantityAccuracyTest` green, including reserved 6 → **1** (not 7) and reserved 15 → **1** (not 16). The `max(0, on_hand − reserved)` clamp is untouched and remains live in `ecos-dev-app` (deployed in the prior task; not reverted).

## 9. Per-Line Manufacturing Preservation
`PrepareOrderManufacturingAction::executeForLines()` is intact in the worktree (the exact additive, `execute()`-unchanged implementation from TASK-MTO-GATE1-PRECONDITION-CLOSURE-001). `LineScopedManufacturingTest` green. **It was NOT left deployed to `ecos-dev-app`** (reverted with the rollback — see §12) because it is only needed at reconciliation time and its presence is irrelevant while the gate barrier is restored.

## 10. Tests
`ManufacturingKernelGateProviderTest` (new): registry resolves the real provider via the actual boot registration (no mock); `has('manufacturing')`; gate returns Approve for opposite availability contexts (pass-through); exactly one Approve rule at priority 0; constant predicate matches empty/shortage/no-shortage contexts; dependency-free constructor (no injected authority).

## 11. Regression Results
Isolated `ecos_dev_test`, one invocation: **`OK (134 tests, 459 assertions)`** across `ManufacturingKernelGateProviderTest` · `MtoProductionQuantityAccuracyTest` · `MtoManufacturingQuantityIntegrationTest` · `LineScopedManufacturingTest` · `WaveDrivenManufacturingTriggerTest` · `ManufacturingWorkflowTest` · `InventoryAvailabilityEngineTest` · `DecisionOrchestratorTest` (Unit) · `DecisionKernelTest` (Unit) · `InventoryReservationTest`. **No regression.** Notably `DecisionOrchestratorTest` (which asserts `NoProviderForContextException` for `goods_receipt` using its own registry) stays green — confirming the boot registration does not affect the generic kernel or other contexts.

## 12. Deployment (attempted, then REVERTED)
Deployed 3 files to `ecos-dev-app` (provider, SP `boot()`, per-line seam) + `optimize:clear`; the quantity fix was already live. Live resolution was confirmed working (§13). Then, on discovering the scheduled-wave hazard (§14/§15), I **reverted the live app** to its pre-deploy state: restored the `boot()`-less `ManufacturingWorkflowServiceProvider` and the seam-less `PrepareOrderManufacturingAction` (both from HEAD), removed the provider file, and `optimize:clear`. Post-revert verification: `executeForLines` absent, `register('manufacturing')` absent, provider file absent, quantity fix still present (2). The worktree implementation is untouched and intact.

## 13. Live Provider Resolution
While deployed, `registry->for('manufacturing')` resolved to `ManufacturingKernelGateProvider` and the gate returned Approve (proven read-only). **After the safety rollback**, `registry->for('manufacturing')` **throws `NoProviderForContextException` again** (barrier intentionally restored). So live resolution is demonstrably achievable, but is **not currently in place** by design.

## 14. Live Safety Verification — the discovered hazard
- ORD-00014's ECOS-FG line has `manufacturing_started_at = 2026-08-28 05:00:06` with NULL state and no transaction — a wave-driven `PrepareOrderManufacturingAction` attempt that set the timestamp, then threw at the empty gate.
- ORD-00014's status changed `ready_for_dispatch → in_progress` at **13:00:01** (a cron tick), **externally, during this task**. `in_progress` is a manufacturing-eligible status.
- Conclusion: a **scheduled wave process periodically processes ORD-00014 and attempts whole-order manufacturing** (all lines, including ECOS-FG). It has failed only because the gate threw. **Deploying the gate would let the next tick succeed → auto-manufacture the forbidden ECOS-FG.** Hence the rollback.

## 15. ORD-00014 Before/After
| Metric | Before my deploy | After rollback |
|---|---|---|
| manufacturing_transactions (system) | 0 | 0 |
| Honey FG on_hand | 0 | 0 |
| Glass Jar / custody | 540 / 1 | 540 / 1 |
| ECOS-FG manufactured? | no | no |
| status | in_progress (already, at my snapshot) | in_progress |

**I did not mutate ORD-00014's business data.** Its status was already `in_progress` when I snapshotted (the external cron change at 13:00:01 preceded my deploy). It remains unmanufactured. The external status change is flagged as evidence of the non-quiescent environment (§14).

## 16. Phantom Custody Safety
Untouched. Honey custody = 1, ECOS-FG custody = 1, `vehicle_custody_transfer` rows = 0 — unchanged. The provider does not interact with custody. No delete/decrement/transfer/ledger.

## 17. Marketing Job Safety
Untouched. The Marketing `CheckProviderHealthJob` DecryptException was not modified; the manufacturing provider registration does not depend on it.

## 18. Remaining Blockers
- **Live deployment of the gate is blocked by a non-quiescent environment:** an active scheduled wave attempts to manufacture ORD-00014; with the gate live it would auto-manufacture the forbidden ECOS-FG. The gate cannot be safely left live until this is addressed.
- (Pre-existing, unchanged: ECOS-FG phantom custody; Marketing DecryptException.)

## 19. Gate 1 Readiness
**🔴 NOT READY.** The gate is implemented + fully tested + proven deployable, but is **not currently deployed** (rolled back for safety). Gate 1 preconditions remain incomplete because the manufacturing path cannot be safely activated in the live environment as-is.

## 20. Final Status & Required Owner Decision

**BLOCKED — OWNER DECISION REQUIRED.** Success criteria met: 1–11 (implemented, registered in worktree, thin pass-through, authorities preserved, no MFG-001, no second authority, quantity + line-scope + regression green). Success criteria **not** met: 12 (live provider resolution is not currently in place — intentionally reverted) and, by extension, Gate-1-ready — because satisfying 12 in the current environment would violate 13 (ORD-00014 unchanged / ECOS-FG not manufactured).

**Exact owner decision:** how to safely activate the manufacturing path given the scheduled wave that attempts ORD-00014 manufacture. Options (each ops/owner-scoped — I must not pause schedulers or hold live orders under this task):
1. **Quiesce then deploy:** pause the scheduled wave for ORD-00014's warehouse (or exclude/hold ORD-00014 from waves) during a controlled window, deploy the gate, run the **line-scoped Honey** reconciliation, then resume.
2. **Deploy-and-immediately-reconcile:** in a controlled window with no wave tick imminent, deploy the gate + seam and run `executeForLines(ORD-00014, [HoneyLineId])` at once, before any wave can whole-order-manufacture it.
3. **Fix the scheduled wave first:** investigate why the wave repeatedly re-processes ORD-00014 (the 05:00 attempt + 13:00 status flip) and whether ORD-00014 should even still be in the wave set.

Until one is chosen, the gate stays **undeployed** (barrier restored) and **TASK-MTO-CONTROLLED-RECONCILIATION-001 must not run.** The implementation is committed-ready in the worktree; no code was committed/pushed. **Gate 1 is NOT closed.**
