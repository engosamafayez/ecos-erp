# TASK-MANUFACTURING-DECISION-ARCHITECTURE-DECISION-001 — Architecture Decision Report

**Date:** 2026-08-28
**Type:** Architecture decision analysis (Option A vs Option C). **Nothing implemented, registered, deployed, or mutated. ORD-00014 untouched.**
**Recommendation:** **Option A — Thin Manufacturing Kernel Gate** (contingent on the owner approving its pass-through semantics — see §12/§13). Option C is viable but more invasive and contradicts the frozen "everything flows through the Decision Engine" principle.

---

## Executive Summary

Every frozen manufacturing rule (MFG-000..008) is **already implemented and authoritative** in `ManufacturingPolicy` (eligibility) + `InventoryAvailabilityEngine` (availability, quantity, allow-negative). The generic Decision-Kernel stage-1 gate contributes **no additional manufacturing authority** for order-driven MTO today — its only effects are (a) requiring a provider that doesn't exist (→ the live exception) and (b) feeding a `DecisionResult.isPositive()` flag into the planner.

Both options therefore rest on the **same principle** — *the MTO manufacturing authority is `ManufacturingPolicy` + `InventoryAvailabilityEngine`; the kernel gate is not a second authority.* They differ only in how that is expressed:

- **A (thin gate):** register a minimal `manufacturing` provider that returns a single **Approve** (a documented admission pass-through). Additive; no change to the canonical pipeline; no vocabulary mapping, priority reconciliation, DecisionLog, or context expansion.
- **C (retire stage 1 for MTO):** bypass/remove the stage-1 `DecisionOrchestrator` call on the order-lifecycle trigger and rework the planner's decision dependency. Requires surgery on the shared `ManufacturingWorkflow`/`ManufacturingPlanner` and special-cases MTO inside a generic service.

A is **LOW** complexity/risk and additive; C is **MEDIUM–HIGH** and touches the canonical path. Neither duplicates MFG-005..008 (both keep them solely in `InventoryAvailabilityEngine`, preserving the quantity fix). Neither resurrects MFG-001 (ADR-027 v1.5 preserved). **A is recommended**, but the owner must explicitly bless the pass-through semantics (the "do not invent always-approve" rule means I cannot choose it unilaterally).

---

## Part 1 — Architecture Trace

**Option A (thin gate) — path retained, provider added:**
```
PrepareOrderManufacturingAction.processLine
→ OrderLifecycleCoordinator → ManufacturingLifecycleHandler
     → ManufacturingPolicy.evaluate            (LAYER 1: eligibility, authoritative)
→ ManufacturingApplicationService.manufactureProduct
→ ManufacturingWorkflow.run
     Stage 1: DecisionOrchestrator.orchestrate → registry.for('manufacturing')
              → [NEW] ManufacturingRuleProvider → single Approve rule → DecisionResult(approve)
     Stage 2: InventoryAvailabilityEngine.analyse  (LAYER 2: availability + quantity, authoritative)
     Stage 3: ManufacturingPlanner.plan(availability, decision=approve)
→ ExecutionPipeline → ManufacturingExecutor → manufacturing_transaction
```
The thin provider MUST do exactly one thing: return `Approve` so `decision.isPositive()` is true and the planner's `canProceed = eligibility && isPositive()` reduces to `eligibility`. It reads no context, encodes no MFG rule.

**Option C (retire stage 1 for MTO) — stage 1 removed/bypassed:**
```
… ManufacturingPolicy.evaluate (LAYER 1)
→ ManufacturingApplicationService.manufactureProduct
→ ManufacturingWorkflow.run
     Stage 1: [BYPASSED for trigger_type='order_lifecycle'] — synthesize an implicit approve
     Stage 2: InventoryAvailabilityEngine.analyse (LAYER 2)
     Stage 3: ManufacturingPlanner.plan(availability, <synthesized/omitted decision>)
→ ExecutionPipeline → ManufacturingExecutor
```
What must change for C: `ManufacturingWorkflow.run` must skip the orchestrate call on the MTO trigger, and the **planner's decision dependency must be satisfied** — the planner's `canProceed`/metadata currently require a `DecisionResult`. So C is not a pure deletion; it requires either synthesizing an approve `DecisionResult` or editing `ManufacturingPlanner`/`ManufacturingWorkflow` to drop the `decision.isPositive()` term for MTO. The orchestrator's recipe resolution is redundant (the availability engine re-resolves), so nothing else is lost.

## Part 2 — Authority Analysis (MFG-000..008)

| Rule | Current authority | Used by MTO? | A duplicates it? | C preserves it? |
|---|---|---|---|---|
| MFG-000 stock-sufficiency pre-check | `InventoryAvailabilityEngine` (Sufficient / qty_to_manufacture=0) + workflow `should_manufacture=false` | Yes | No (thin gate doesn't) | Yes |
| MFG-001 `can_manufacture=false`→skip | **SUPERSEDED** by ADR-027 v1.5 (removed) | No (gate removed) | Must NOT (thin doesn't gate can_manufacture) | Yes (removal preserved) |
| MFG-002 no recipe→fail | `ManufacturingPolicy` R4 (RecipeNotFound) + `InventoryAvailabilityEngine` NoRecipe | Yes | No | Yes |
| MFG-003 recipe inactive→fail | `InventoryAvailabilityEngine` (resolver → NoRecipe) + `ManufacturingPolicy` has_active_recipe | Yes | No | Yes |
| MFG-005 stock sufficient→skip | `InventoryAvailabilityEngine` Sufficient | Yes | No | Yes |
| MFG-006 raw sufficient→manufacture | `InventoryAvailabilityEngine` CanManufacture | Yes | No | Yes |
| MFG-007 raw shortfall + allow_negative→manufacture w/ shortage | `InventoryAvailabilityEngine` Partial (RC-2) | Yes | No | Yes |
| MFG-008 raw shortfall + no allow_negative→fail | `InventoryAvailabilityEngine` CannotManufacture | Yes | No | Yes |

**Conclusion:** all MFG authority lives in `ManufacturingPolicy` + `InventoryAvailabilityEngine`. A **thin** gate duplicates none; C preserves all. A **only** introduces a second authority if it re-encodes MFG rules — which is why A must be thin. The InventoryAvailabilityEngine quantity fix remains the sole authority for production quantity under both.

## Part 3 — Decision Outcome Mapping (7 spec codes vs 5 kernel cases)

1. **Mapping required under A?** Only if A re-encodes MFG rules. A **thin (Approve-only)** provider needs **no** mapping. A-authoritative would need it.
2. **Mapping unnecessary under C?** Yes — no kernel decision is produced for MTO; outcomes are expressed by the downstream `AvailabilityEngine` eligibility enum, never the kernel's `DecisionType`.
3. **Which avoids inventing semantics?** Both A-thin and C. A-authoritative would force inventing the mapping.
4. **Which preserves the frozen outcomes?** Both — the frozen MANUFACTURE / SKIP / FAIL outcomes are preserved *downstream* (AvailabilityEngine eligibility + workflow `should_manufacture`), not re-expressed in kernel vocabulary.

If (and only if) the owner picks an **authoritative** provider instead, these mappings each need explicit approval: `FAIL_NO_RECIPE`/`FAIL_RECIPE_INACTIVE`/`FAIL_STOCK_SHORTAGE` → Reject or Defer?; `MANUFACTURE_WITH_SHORTAGE` → Partial or Approve?; `SKIP_STOCK_SUFFICIENT`/`SKIP_NOT_MANUFACTURABLE` → (kernel has **no** skip outcome). **Neither A-thin nor C requires these.**

## Part 4 — Priority Semantics (spec lowest-wins vs kernel highest-wins)

- **A-thin:** a single rule → **no priority interaction**; the conflict is moot.
- **A-authoritative:** the lowest-wins/highest-wins conflict is real → OWNER DECISION.
- **C:** no kernel rules → no priority; the downstream `AvailabilityEngine.classifyEligibility` uses deterministic precedence (hard-blocker > soft-shortage > can-manufacture), not numeric priority.

A-thin and C **avoid** the priority conflict. It arises **only** under A-authoritative.

## Part 5 — DecisionLog

- **Does MTO require DecisionLog today?** No. The MTO path already records `manufacturing_transactions` (source-of-truth execution record, RC-10 traced to the order line) + `order_lines.manufacturing_result` + order events. No `decision_logs` table exists or is consumed anywhere.
- **Already produced elsewhere?** Functional auditability exists via `manufacturing_transactions` + `manufacturing_result`.
- **A require kernel change?** A-thin: **no** (the kernel forbids logs; the thin provider adds none). A-authoritative honoring the spec's log-before-execute: yes (new table + writer + kernel contract change).
- **C avoid the conflict?** Yes — no kernel involvement for MTO.
- **Schema change?** A-thin: none. C: none. A-authoritative-with-log: yes.
- **Operational vs spec requirement?** DecisionLog is a **generic-Decision-Engine spec requirement**, not an operational MTO requirement (execution audit already exists).

**Owner decision:** confirm DecisionLog is NOT required for the MTO path (evidence: never built/consumed; `manufacturing_transactions` suffice). If the owner wants the spec's log-before-execute invariant, that is a separate build outside both A-thin and C.

## Part 6 — Context Inputs

- **A-thin:** no context expansion (Approve reads nothing).
- **A-authoritative:** would need `recipe.is_active`, per-material `allow_negative` (and `can_manufacture`, which is superseded — must NOT be added). But those are **already read canonically** by the downstream authority (`ManufacturingPolicy` gets `ProductContext.has_active_recipe`; `InventoryAvailabilityEngine` reads per-material `allow_negative` from recipe components). Expanding the kernel context would **duplicate a data source** → discouraged.
- **C:** the existing `ManufacturingPolicy`/`InventoryAvailabilityEngine` already obtain these inputs canonically → no context, no duplication.

A-thin and C **avoid** context expansion. Only A-authoritative needs it (and risks a second data source).

## Part 7 — ADR-027 Compatibility

MFG-001 (`can_manufacture` gate) was **removed** by the approved ADR-027 v1.5 (`ManufacturingPolicy` Rule 3 deleted; recipe-executability is the sole authority).
- **A-thin:** returns Approve; does not gate on `can_manufacture` → **does not resurrect MFG-001**. Safe.
- **A-authoritative:** faithfully implementing the frozen MFG-001 would **resurrect** the removed gate → conflicts with ADR-027 → the owner must formally drop MFG-001.
- **C:** naturally preserves ADR-027 (no kernel gate; `ManufacturingPolicy` already dropped it).

Both A-thin and C preserve ADR-027. A documentation reconciliation (formally supersede MFG-001 in the frozen spec) is advisable regardless (owner decision).

## Part 8 — MTO Architectural Fit

ECOS MTO is **order-driven, reservation-first, assemble-to-order**: the decision *to manufacture* is made upstream by the order/reservation, and the concrete constraints are enforced by `ManufacturingPolicy` (eligibility) + `InventoryAvailabilityEngine` (availability + exact quantity). **The generic kernel gate adds no manufacturing decision authority for MTO today** (it has no rules; a provider would be pass-through). 
- **Value of the kernel to MTO today:** none in terms of decisions.
- **Future value:** the frozen spec positions the Decision Engine as the "central nervous system" and the intended home for future AI / make-to-stock decisioning. Keeping the kernel in the path (A) preserves that hook additively; removing it (C) would require re-introducing it for future MTS/AI. There is **no current evidence** of such rules, so this is option-value, not a present requirement.

## Part 9 — Global Impact

- **A (thin provider):** affects **only** the `manufacturing` context; `goods_receipt`/`procurement`/`ai`/disassembly contexts and their (absent/own) providers are untouched. `simulate`/`validate` manufacturing paths (which also call `ManufacturingWorkflow`) would now **resolve instead of throw** — a strict improvement. Tests that `forgetInstance` + register their own provider still override it (no test breakage). Make-to-stock (future) would reuse the same manufacturing provider. **Additive, bounded.**
- **C:** modifies the shared `ManufacturingWorkflow` (used by manufacture + simulate + validate + future MTS) and the `ManufacturingPlanner` decision dependency. To stay scoped it must branch on `trigger_type='order_lifecycle'` inside a generic service — a special-case with wider blast radius; non-MTO triggers must still enter the orchestrator. **Bounded surgery, higher blast radius.**
- **C is NOT "remove the Decision Kernel from Manufacturing globally"** — smallest scope = bypass on the MTO trigger only. Even so it touches the canonical shared path.

## Part 10 — Implementation Complexity

| Aspect | A (thin gate) | C (retire stage-1 for MTO) |
|---|---|---|
| Provider class | LOW (tiny) | n/a |
| Registration | LOW (one service-provider line) | n/a |
| Vocabulary mapping | NONE | NONE |
| Priority | NONE | NONE |
| Context | NONE | NONE |
| DecisionLog | NONE | NONE |
| Canonical-pipeline change | NONE (additive) | MEDIUM (workflow bypass + planner decision rework) |
| Regression surface | LOW | MEDIUM–HIGH (shared workflow, all callers) |
| Tests | LOW | MEDIUM |
| **Overall** | **LOW** | **MEDIUM–HIGH** |

## Part 11 — Failure-Mode Analysis

| Risk | A (thin) | C |
|---|---|---|
| Duplicate authority | LOW (thin approve; no MFG re-encoding) | LOW |
| Policy drift | LOW (MFG single-sourced downstream) | LOW |
| Semantic ambiguity | LOW (single approve) | LOW |
| Maintenance | LOW (additive) | MODERATE (special-case in shared workflow) |
| Regression | LOW | MEDIUM (canonical surgery) |
| Architectural complexity | LOW | MODERATE |
| Auditability | unchanged (`manufacturing_transactions`) | unchanged |
| Explainability | MODERATE — a permanent "always-approve" rule can mislead future devs unless clearly documented as a pass-through | HIGH — "MTO does not enter the kernel" is explicit |
| Frozen-architecture consistency | HIGH — honors "everything flows through the Decision Engine" | LOWER — a deliberate exception to that principle |

## Part 12 — Recommendation

**Recommend Option A — Thin Manufacturing Kernel Gate**, contingent on the owner approving the pass-through semantics (§13.2).

Rationale, grounded in the current frozen architecture + ADR-027 + the real MTO execution path:
1. **Least invasive** — additive provider + one registration; **no surgery** on the canonical `ManufacturingWorkflow`/`ManufacturingPlanner` (C requires both).
2. **No duplicate authority** — a thin Approve keeps MFG-005..008 solely in `InventoryAvailabilityEngine`; the quantity fix stays authoritative.
3. **No invention of kernel semantics** — a single Approve needs no 7→5 mapping, no priority reconciliation, no context expansion, no DecisionLog (all of which A-authoritative or the spec would force).
4. **Preserves ADR-027 v1.5** — does not gate on `can_manufacture`; MFG-001 not resurrected.
5. **Honors the frozen "central nervous system" principle** — the kernel stays in the path (C is a deliberate exception to it), and future AI / make-to-stock rules can be added as kernel rules later **without re-plumbing**.
6. **Improves simulate/validate** — those workflow paths stop throwing.

The one genuine caveat: a thin Approve is functionally "approve the manufacturing context." That is defensible **only** as an explicit, documented admission pass-through (real authority = `ManufacturingPolicy` + `InventoryAvailabilityEngine`), **not** a business rule — and the task forbids me choosing "always approve" unilaterally. Hence it is an **owner decision** (§13.2), not something to implement here.

Option C remains valid if the owner prefers that order-driven MTO explicitly not enter the kernel; it is cleaner in explainability but costs canonical-path surgery, special-casing, higher regression, and contradicts the frozen kernel-central principle. **B (authoritative provider refactor) is not recommended** — it forces vocabulary/priority/DecisionLog/context decisions and duplicates the already-authoritative downstream engines.

## Part 13 — Owner Decision Matrix

| # | Decision | Under A | Under C | Owner decision needed? |
|---|---|---|---|---|
| 1 | MTO manufacturing decision authority | `ManufacturingPolicy` + `InventoryAvailabilityEngine` (kernel = pass-through) | same (kernel not entered) | **YES** — approve the principle |
| 2 | Kernel gate semantics for MTO | thin **Approve** (documented pass-through) | **bypass** stage-1 on MTO trigger | **YES** — approve pass-through (A) or bypass (C); I cannot choose "always approve" |
| 3 | Vocabulary mapping (7→5) | not needed | not needed | NO (avoided by both; only needed for B) |
| 4 | Priority direction reconcile | not needed | not needed | NO (avoided by both; only for B) |
| 5 | DecisionLog for MTO | not needed | not needed | **YES** — confirm not an operational MTO requirement (`manufacturing_transactions` suffice) |
| 6 | ContextBuilder expansion | not needed | not needed | NO (avoided by both; only for B) |
| 7 | MFG-001 vs ADR-027 v1.5 | not resurrected | preserved | **YES** — formally supersede MFG-001 in the frozen spec (doc reconciliation) |
| 8 | Scope | `manufacturing` context only (additive) | MTO trigger only (bounded surgery) | **YES** — confirm NOT a global kernel removal |
| 9 | Documentation guardrail | mark the thin gate as a documented pass-through (not the real policy) | mark MTO's kernel exception explicitly | **YES** — prevent future "this is the policy" confusion |
| 10 | Deployment of the fix path | deploy provider + the (already-tested) line-scoped seam to `ecos-dev-app` | deploy workflow change + seam | **YES** — authorize deployment (separate follow-up task) |

## Gate 1 Impact & Next Step

Gate 1 remains **BLOCKED** on this decision. Once the owner picks **A or C** and resolves the matrix rows marked YES (principle, gate semantics, DecisionLog-not-required, MFG-001 supersession, scope, doc guardrail, deploy authorization), a **separate implementation task** can: implement the chosen mechanism, add MFG-policy/regression tests, deploy it (+ the already-tested line-scoped seam) to `ecos-dev-app`, and verify `registry->for('manufacturing')` resolves — after which the ORD-00014 controlled reconciliation can proceed. **No implementation was done in this task; ORD-00014 and all business data are unchanged.**
