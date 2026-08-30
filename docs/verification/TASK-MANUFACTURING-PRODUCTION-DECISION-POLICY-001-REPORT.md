# TASK-MANUFACTURING-PRODUCTION-DECISION-POLICY-001 — Report

**Date:** 2026-08-28
**Outcome: 🔴 OUTCOME B — BLOCKED / OWNER DECISION REQUIRED. No provider implemented, nothing registered, nothing deployed, no business data mutated.**
**Governing rule honored:** I did not invent any manufacturing decision rule. The business rules already exist and are frozen (MFG-000..008), but porting them onto the production Decision Kernel requires architectural decisions that only the owner can make — so I stopped.

---

## 1. Executive Summary

The live exception `NoProviderForContextException: No rule provider registered for context type [manufacturing]` is real: the production `manufacturing` Decision-Kernel rule provider is **unimplemented and unregistered** — only tests register a blanket-approve rule.

The manufacturing decision **business policy is explicitly defined and frozen** as rules **MFG-000..008** (`DECISION-ENGINE-SPEC.md` §3.1, `MANUFACTURING-PROCUREMENT-SPEC.md`, `MANUFACTURING-CTO-REVIEW.md`, frozen by `ARCHITECTURE-FREEZE.md`). So this is **not** a blank-slate "invent a policy" situation.

**However, the frozen policy cannot be ported to the built Decision Kernel without owner/architecture decisions**, because the spec and the implemented kernel disagree, and two later facts conflict with the spec:

- **Vocabulary mismatch** — the spec's 7 outcome codes (MANUFACTURE / MANUFACTURE_WITH_SHORTAGE / SKIP_NOT_MANUFACTURABLE / SKIP_STOCK_SUFFICIENT / FAIL_NO_RECIPE / FAIL_RECIPE_INACTIVE / FAIL_STOCK_SHORTAGE) do not map onto the kernel's 5 `DecisionType` cases (approve/reject/defer/partial/escalate). The kernel has **no "skip/no-op" outcome**. Choosing the mapping (e.g. FAIL_STOCK_SHORTAGE → Reject vs Defer; MANUFACTURE_WITH_SHORTAGE → Partial vs Approve) **is** inventing policy.
- **Priority direction** — spec "lowest number wins" vs kernel "highest value wins".
- **DecisionLog** — spec mandates log-before-execute to a `decision_logs` table; the kernel forbids it and no such table exists.
- **Missing context inputs** — MFG conditions need `can_manufacture`, `recipe.is_active`, and per-material `allow_negative_stock`; the `ManufacturingContextBuilder` does not provide them.
- **Spec ⟂ approved ADR** — MFG-001 (`can_manufacture = false → skip`) was **removed** by the later **approved** ADR-027 v1.5 (which made recipe-executability the sole authority and deleted the `can_manufacture` gate). The frozen draft therefore conflicts with the current approved architecture.
- **Duplication** — MFG-005..008 (stock-sufficiency + raw-material availability + allow_negative) are **already implemented** by `ManufacturingPolicy` (eligibility) + `InventoryAvailabilityEngine` (Sufficient/CanManufacture/Partial/CannotManufacture) + the workflow's `should_manufacture=false → ManufacturingNotNeeded`. A kernel provider re-encoding them would be a **second decision authority** (explicitly forbidden), and cannot even evaluate them at stage 1 (the stage-1 context passes `available_qty = 0.0`).

Deciding these six points defines the manufacturing decision architecture. Per the task ("Registration requires an architectural decision" / "any implementation would require inventing business rules" → OUTCOME B), **I STOP and request the exact owner decision** (§9, §19). No guessed provider was implemented.

---

## 2. Root Cause

`ManufacturingWorkflow::run()` stage 1 calls `orchestrator->orchestrate(builder: new ManufacturingContextBuilder, …)` → `DecisionOrchestrator` → `InMemoryRuleProviderRegistry::for('manufacturing')`. The registry is bound **empty** (`DecisionOrchestratorServiceProvider:27-30` — comment: "callers can register providers at boot"); nothing registers a `manufacturing` provider in production. `for()` throws `NoProviderForContextException`, which `ManufacturingWorkflow` does **not** catch (it only catches `RecipeResolverException` + `NoMatchingRuleException`), so it propagates uncaught. This is why `manufacturing_transactions = 0` system-wide and why ORD-00014's ECOS-FG line sits in `mfg_started_at`-set/state-NULL limbo (the trigger set the timestamp, then the coordinator threw).

## 3. Decision Kernel Architecture

`DecisionKernel::evaluate(trigger, context, provider)` runs `provider->rules()` through `RuleEvaluationPipeline`, picks the highest-priority matching rule, returns a `DecisionResult`; no match → `NoMatchingRuleException`. `DecisionType` = approve / reject / defer / partial / escalate (`isPositive` = approve|partial). Priority: **higher value wins** (`DecisionRuleInterface`). The kernel is pure/side-effect-free and **forbids** creating decision logs (`DecisionOrchestrator` contract). Registry maps context-type → provider; `DecisionKernelServiceProvider` registers only the kernel+pipeline (no provider bindings).

## 4. Manufacturing Provider Contract

`RuleProviderInterface::rules(): list<DecisionRuleInterface>`. The docblock names the intended `ManufacturingRuleProvider` and adds "Current implementation: InMemoryRuleProvider. Future: EloquentRuleProvider, AiRuleProvider" — i.e. the production provider is an acknowledged **future placeholder**. `ManufacturingContextBuilder` builds: required `product_id, ordered_qty, available_qty, shortage_qty`; optional `branch_id, warehouse_id, allow_partial`; enriched with `recipe_id, bom_version_number, component_count, recipe_resolved`. It does **not** supply `can_manufacture`, `recipe.is_active`, or per-material `allow_negative_stock`.

## 5. Existing Providers

Only `InMemoryRuleProvider` (generic, closure-backed) implements `RuleProviderInterface`; only `DecisionRule` implements `DecisionRuleInterface`. **No `ManufacturingRuleProvider` / `EloquentRuleProvider` / any production provider class exists** (the names appear only in docblocks + `IMPLEMENTATION-PROGRESS.md` "Future Extension Points"). `new InMemoryRuleProvider(...)` never appears in production code. `DecisionOrchestratorServiceProvider` binds an **empty** registry; `ManufacturingWorkflowServiceProvider` registers **no rules**. `IMPLEMENTATION-PROGRESS.md:417` even lists the exact failure as a known risk: "No matching rule in production … Callers must always register at least one catch-all rule".

## 6. Test-Only Providers

Every `manufacturing` rule registered anywhere is a **test** with a trivial condition:
- `DecisionOrchestratorTest` `approveAllRule()` / `rejectAllRule()` — `condition: fn ($ctx) => true`.
- `WaveDrivenManufacturingTriggerTest` / `MtoManufacturingQuantityIntegrationTest` / `OrderManufacturingIntegrationTest` / `LineScopedManufacturingTest` — `registerRule()` builds one `DecisionRule(condition: fn => true, decision_type: Approve)` and must first `forgetInstance` the empty registry, proving production ships it unpopulated.
- `DecisionKernelTest` — generic `rule(...)` helpers.
**None encode MFG-001..008 or any business-meaningful condition.** They are mechanism scaffolding, not policy. Promoting them to production would be exactly the forbidden "always approve".

## 7. Business Rule Evidence

**The policy is defined + frozen** (not owner-blank):
- `DECISION-ENGINE-SPEC.md` §3.1 (rules table) and `MANUFACTURING-PROCUREMENT-SPEC.md:389-398,778-779` define MFG-001..008 (incl. the `allow_negative_stock` MFG-007/008 selector); `MANUFACTURING-CTO-REVIEW.md:156-162` adds MFG-000 (stock-sufficiency pre-check); `ARCHITECTURE-FREEZE.md:118` freezes "Decision Engine | ORDER_PREPARING → rules MFG-001 through MFG-008" and reserves rule changes to ADR.
- **But** both specs are headed **"Draft — Awaiting Approval" (v1.0, 2026-06-29)**, and MFG-001 conflicts with the later **approved** ADR-027 v1.5. ADR-027 itself does **not** define the MFG rules and never mentions the DecisionKernel — the "select MFG-007 vs MFG-008" phrasing lives only in code docblocks (`RecipeComponent.php:38`, `RawMaterialAvailability.php:18`).

Classification: MFG-000..008 = **frozen business policy in the ORIGINAL decision-engine design**; the availability subset (005..008) = **already realized as IMPLEMENTATION DETAIL** in ManufacturingPolicy + AvailabilityEngine; the kernel port = **UNDEFINED / conflicting**.

## 8. Production Policy (as documented)

APPROVE/MANUFACTURE when: recipe valid AND shortage>0 AND raw sufficient (MFG-006), or raw shortfall with `allow_negative=true` (MFG-007, "with shortage"). SKIP when: not manufacturable (MFG-001 — superseded) or stock sufficient (MFG-005). FAIL when: no recipe (MFG-002), recipe inactive (MFG-003), or raw shortfall with `allow_negative=false` (MFG-008). Inputs: product/recipe/availability/allow_negative. Outcomes: the 7 spec codes above — **not** the kernel's 5-case vocabulary.

## 9. Policy Gaps / Owner Decisions (the crux)

To wire a production provider, the owner must decide **all** of:
1. **Architectural role of the kernel `manufacturing` gate** — one of:
   - **(1a) Thin admission gate:** the provider returns a minimal decision and the real MFG rules stay where they are already implemented (ManufacturingPolicy + AvailabilityEngine + workflow `should_manufacture`). Avoids duplication, but the provider's rule/outcome must still be defined (approve-always is forbidden to invent).
   - **(1b) Authoritative provider (spec-faithful refactor):** move MFG-001..008 into the provider, extend the ContextBuilder, and retire the overlapping logic in ManufacturingPolicy/AvailabilityEngine. Large refactor + duplication removal.
   - **(1c) Retire the stage-1 DecisionOrchestrator call** for order-driven MTO (treat ManufacturingPolicy + AvailabilityEngine as the authority). (Task says don't bypass the kernel — so this needs an explicit decision.)
2. **Outcome mapping** (only if 1b/partly 1a) — spec codes → `DecisionType`: FAIL_NO_RECIPE/FAIL_RECIPE_INACTIVE/FAIL_STOCK_SHORTAGE → Reject or Defer? MANUFACTURE_WITH_SHORTAGE → Partial or Approve? SKIP_STOCK_SUFFICIENT/SKIP_NOT_MANUFACTURABLE → (kernel has no skip).
3. **Priority direction** — reconcile lowest-wins (spec) vs highest-wins (kernel).
4. **DecisionLog** — implement the spec-mandated `decision_logs` (new table + writer) or formally drop the log-before-execute invariant.
5. **ContextBuilder inputs** — add `can_manufacture`(if retained), `recipe.is_active`, per-material `allow_negative_stock`, and real availability — or confirm the availability stage remains the authority for 005..008.
6. **Spec ⟂ ADR-027 v1.5** — formally supersede MFG-001 (drop `can_manufacture` gating) and re-approve the spec against the current architecture.

Each is an architecture/business decision. Picking any without authority = inventing policy or duplicating authorities → forbidden.

## 10. Implementation
**NONE.** No provider was implemented (doing so faithfully requires the §9 decisions; doing so minimally requires inventing the outcome mapping or promoting the test blanket-approve).

## 11. Registration
**NONE.** No registration added.

## 12. Tests
**NONE added** (the task authorizes tests only when a provider is implemented; a "provider exists" test is explicitly disallowed).

## 13. Regression
**N/A** — no code changed this task. Prior-task suites remain green (MTO quantity + integration + line-scope: `OK (17)`, broader regression `OK (77, 570)`); nothing here affects them.

## 14. Live Deployment
**NONE.** No manufacturing-provider code deployed. (The already-approved MTO quantity fix remains deployed to `ecos-dev-app` from the prior precondition task; unchanged here.)

## 15. Live Resolution Verification
`registry->for('manufacturing')` on `ecos-dev-app` **still throws `NoProviderForContextException`** — intentionally unresolved, because resolving it requires the §9 owner decision. No source change was made to the provider/registry.

## 16. ORD-00014 Safety Verification
Unchanged: status `ready_for_dispatch`, `manufacturing_transactions = 0`, Honey FG on_hand 0. This task was read-only investigation; nothing manufactured/reconciled/transferred; custody/reservations/order state untouched.

## 17. Remaining Blockers
- **Blocker E (manufacturing decision provider): STILL BLOCKING — now precisely characterized as an architecture reconciliation, not a blank-slate invention.** The frozen MFG policy exists but cannot be ported to the kernel without the §9 decisions. This is systemic: no MTO manufacturing can run in production until it is resolved.
- (Unchanged from prior tasks, out of this task's scope: ECOS-FG phantom custody; Marketing DecryptException; line-scoped seam not yet deployed to the app.)

## 18. Gate 1 Readiness
**🔴 BLOCKED.** A (quantity fix) deployed+proven and B (line-scoped seam) implemented+tested (prior task); C/D non-blocking. But Blocker E is unresolved and now requires an owner/architecture decision (§9). The ORD-00014 reconciliation cannot proceed.

## 19. Final Recommendation

**OUTCOME B — BLOCKED / OWNER DECISION REQUIRED.** Do not implement a guessed provider.

**Exact owner decision requested:** choose the manufacturing-decision architecture (§9.1: thin gate vs authoritative-provider refactor vs retire-the-stage) and, for whichever is chosen, resolve §9.2–§9.6 (outcome mapping, priority direction, DecisionLog, ContextBuilder inputs, and formally supersede MFG-001 vs ADR-027 v1.5).

**Engineering recommendation (advisory, for the owner to approve — NOT implemented):** the lowest-risk, least-duplicative path is **§9.1a/1c** — treat `ManufacturingPolicy` + `InventoryAvailabilityEngine` as the already-built, ADR-027-aligned authority for MFG-002..008 (they encode exactly those outcomes), and either register a **single documented catch-all** for the kernel gate or remove the vestigial stage-1 DecisionOrchestrator call for the order-lifecycle trigger. This avoids a second decision authority and needs no DecisionLog table. But because it still requires defining the gate's semantics (and the task forbids me choosing "always approve"), it is **the owner's call**, not mine. Once decided, a follow-up task can implement + register the provider, add MFG-policy tests, deploy, and then Gate 1's precondition set is complete.
