# TASK-INVENTORY-NEGATIVE-STOCK-FULFILLMENT-CONTRACT-REPAIR-001 — Engineering Report

**Date:** 2026-08-13 · **Branch:** `develop` · HEAD `6149875b`
**Nothing was modified.** No service, no enum, no UI, no data, no test.

> # VERDICT: **NOT CERTIFIED — CONTRACT CONFLICT**
>
> Stopped at PART 1 (contract reconciliation), which instructs: *"إذا وجد تعارض … لا تختار بناءً على التخمين … إذا احتاج الأمر قرار Business: STOP قبل implementation لذلك الجزء."*
>
> **PART 4/E requires removing the zero-clamp from availability. The clamp is the certified contract**, in three places, one of which the last eight task specifications have explicitly protected as immutable. I will not silently override that.
>
> Two further blockers: the stated prerequisite task is **not complete**, and the shared runner is occupied so PART 22's cross-domain runtime proof is unobtainable.

---

## 1. Executive Summary

The business contract A–K is coherent and I have no objection to its intent. But one clause collides head-on with certified, repeatedly-protected behaviour, and that collision must be resolved by you rather than by me picking a side.

**One correction to my own earlier reporting, first:** in the Recovery report I listed ADR-027 §15 **C1** (`DirectIssueStockAction` ignoring `allow_negative_stock` at issuance) as a **known open gap, unverified**. It is **not open — it is fixed**:

```
DirectIssueStockAction.php:76  $allowNegative = (bool) Product::where('id', $dto->product_id)->value('allow_negative_stock');
DirectIssueStockAction.php:91  // Bypassed when allow_negative_stock = true (ADR-027 v1.1 P07).
```

So contract clause **K** (negative stock must survive through to consumption) is **already implemented at the issuance boundary**. My earlier entry was over-cautious; this report supersedes it.

## 2. Source-of-Truth Hierarchy

Unchanged from the Recovery report: **ADR-027 (Tier 1, "this matrix is the law")** → V3 migration → enums → certified task reports → docblocks → configuration data (never authoritative).

## 3. THE CONFLICT — availability clamping

### What this task requires

PART 4: *"لا تستخدم `max(available, 0)`"*
PART E: *"لا تحول -5 إلى 0"* — negative available must be representable.

### What the certified implementation does

The clamp exists in **three** independent places, all certified:

| Location | Code | Certification protecting it |
|---|---|---|
| `ManufacturingAvailabilityService:58-59` | `SUM(GREATEST(on_hand_qty - reserved_qty, 0.0))` / `GREATEST(SUM(on_hand_qty) - SUM(reserved_qty), 0.0)` | **ADR-027 §16.3** names this service the *sole* authority on recipe availability; F4 / Option B / RecipeGateTenantRepair / CrossBrandReuse all certified against it |
| **`MaterialDemandCalculator:132`** | **`$available = max(0.0, $onHand - $reserved);`** | **The `ce69612a` certified contract** — `on_hand 15, reserved 8 → available 7, missing 3` |
| `AvailabilityState:56` | `$available <= 0.0 => self::OutOfStock` | The canonical projection consumed by `ProductResource` |

`MaterialDemandCalculator` is the decisive one. **Eight consecutive task specifications in this programme have listed `15 − 8 = 7, missing = 3` as a must-not-change invariant**, and several explicitly forbade modifying that file at all. Removing its `max(0.0, …)` changes that contract directly: with `on_hand 0, reserved 3` it would report `available −3` and `missing = required + 3`, altering every shortage figure the Preparation pipeline produces.

### Why I cannot resolve this myself

Both readings are defensible:

- **PART 4/E's reading:** clamping hides reality; if the business permits going negative, the number should show it.
- **The certified reading:** `available` is a *commitment capacity* figure — how much can still be promised — which is never negative. The physical truth lives in `on_hand_qty`, which already goes negative at issuance (§1). Under this reading nothing is hidden; the negative is simply carried by the field that represents physical stock rather than by the field that represents remaining capacity.

The second reading is internally consistent with what the code already does — `DirectIssueStockAction` drives `on_hand_qty` negative while `available` stays clamped. That is arguably **exactly** the "physical quantities remain physical" separation clause A asks for, already implemented, just with the negative living in a different field than PART E expects.

**This is a business/architecture decision about what `available` means, not an engineering defect.** PART 1 requires me to stop here.

## 4–13. Contract Status (assessed, not implemented)

| Clause | Rule | Current implementation | Status |
|---|---|---|---|
| **A** | Allow Negative ON → *can proceed*, physical stays true | `ManufacturingAvailabilityService:95` `available > 0 \|\| allow_negative_stock` | **ALIGNED** |
| **B** | Allow Negative OFF → blocked when insufficient | same line; `DirectIssueStockAction` throws | **ALIGNED** |
| **C** | Reserved from canonical source | `inventory_items.reserved_qty`; written by `ReserveStockAction` | **ALIGNED** — needs runtime proof (§15) |
| **D** | `available = on_hand − reserved` | uniform across services | **ALIGNED** |
| **E** | **Negative available preserved, not clamped** | **clamped in 3 places** | **CONFLICT — §3** |
| **F** | untracked ≠ tracked-zero ≠ tracked-negative | `AvailabilityState` has `Untracked`/`OutOfStock`/`InStock`; **no negative state** | **PARTIAL — depends on E** |
| **G** | Order reservation gated by FG, not raw materials | `ReserveOrderInventoryAction` Case 1 evaluates FG first; recipe consulted only for the manufacturing branch (ADR-027 §16.2, *"a hard requirement"*) | **ALIGNED** |
| **H** | Recipe availability uses canonical material availability | `ManufacturingAvailabilityService`, sole authority (§16.3) | **ALIGNED** |
| **I** | FG availability separates stock / manufacturability / negative policy | `ReserveOrderInventoryAction` Cases 1/2/3 are distinct branches | **ALIGNED** |
| **J** | Preparation uses the same formula as Inventory | `MaterialDemandCalculator` uses `max(0.0, on_hand − reserved)` — same clamp as Inventory | **ALIGNED today; would BREAK if E is applied to only one side** |
| **K** | Negative survives to consumption | `DirectIssueStockAction:76,91` honours the flag | **ALIGNED** (correcting my earlier report) |

**Note on J:** it is currently aligned *because* both sides clamp. Applying PART E to Inventory but not to `MaterialDemandCalculator` — or vice versa — would create exactly the "Preparation calculator ≠ Inventory calculator" divergence PART 10 forbids. Whatever is decided must be applied to **both**, which is precisely why it cannot be done piecemeal.

## 14. Duplicate availability engines (PART 17)

Four calculators found. They are **not** duplicates with conflicting formulas — they answer different questions:

| Engine | Question | Formula |
|---|---|---|
| `InventorySummaryService` | canonical per-product availability | clamp-per-warehouse-then-sum |
| `ManufacturingAvailabilityService` | is the recipe executable? | `available > 0 \|\| allow_negative`, company-scoped |
| `MaterialDemandCalculator` | wave material shortage | `max(0, on_hand − reserved)`, then `missing` |
| `ReserveOrderInventoryAction` | can this FG line be committed? | `InventoryItem::availableQty()` at one warehouse |

**No third engine should be created, and none needs consolidating.** The flag-gated `inventory_ledger.canonical_summary` (currently `false`) already exists to unify the sum-then-clamp vs clamp-then-sum difference; flipping it is a separate, previously-identified cutover.

## 15. Blocker 2 — prerequisite not satisfied

This task lists **TASK-ORDER-LIFECYCLE-V3-CANONICAL-REPAIR-001** as a prerequisite. That task was reported **NOT CERTIFIED — BUSINESS DECISION REQUIRED** and **not implemented**, blocked on B1–B4 (disposition of live `new` rows, superseding the V3 migration, downstream eligibility, Confirm's role).

Clause G and CASE J here reference `awaiting_stock` semantics, and CASE I/J reference order reservation outcomes — both of which shift depending on B4 (whether Confirm becomes the reservation trigger). Implementing this task first risks encoding assumptions the lifecycle decision then invalidates.

## 16. Blocker 3 — verification unavailable

`foreign phpunit: 1` — another agent is running against the shared `ecos_dev_test`. **PART 0.10 says STOP**; PART 22 forbids certifying without the full cross-domain runtime path executing, and PART 13 mandates 13 E2E cases (A–M) spanning Inventory → Reservation → Preparation → Pick → Load → Shipment.

## 17–19. Static / Database / Scope

Not applicable — no code changed. `git status` for this task is empty.

`ecos_dev` untouched and read-only throughout (556 tables, 4 orders). `ecos_erp` / MAIN never contacted. No `migrate:fresh`, `db:wipe`, destructive seed, or manual data repair. **No inventory rows were fabricated** for untracked materials (PART 18 honoured).

## 20. Known Data Gaps

`inventory_items` is **empty across the entire `ecos_dev` database** — 0 rows. Every product is therefore `untracked`, which is why raw materials render as they do. PART 18 explicitly forbids populating rows to make the UI show stock, so this remains a **data** gap, not a code defect. Several E2E cases (A, B, D, E, F) require real inventory rows and cannot be exercised against `ecos_dev` without violating that instruction — they belong in `ecos_dev_test` fixtures.

## 21. Remaining Risks

1. Applying PART E to one calculator only would break clause J's alignment.
2. Removing the clamp changes every `missing` figure the Preparation pipeline computes.
3. `AvailabilityState` has no negative case; PART E would require a new canonical state — and PART 5 forbids adding one without a source.

## 22. Final Certification

# NOT CERTIFIED — CONTRACT CONFLICT

**Primary blocker:** PART 4/E (no clamping) versus the certified clamp in `MaterialDemandCalculator:132`, `ManufacturingAvailabilityService:58-59` and `AvailabilityState:56`.

### The decision needed

**N1 — What does `available` mean?**
(a) *Commitment capacity* — never negative; physical truth lives in `on_hand_qty`, which already goes negative at issuance. **This is what the code does today and it is internally consistent.**
(b) *Signed physical surplus* — may be negative; requires removing the clamp from all three sites, adding a negative `AvailabilityState`, and re-certifying `MaterialDemandCalculator` against a new `missing` contract.

If (a), most of this task is **already satisfied** (§4–13: ten of eleven clauses ALIGNED) and the real work is the **UI**: showing On Hand / Reserved / Available / Availability State / Allow Negative distinctly, and not collapsing `untracked` into "Out of Stock" — PARTS 14–16, which need no backend change at all.

If (b), it needs its own task with explicit authority to modify `MaterialDemandCalculator`, since eight prior specifications forbade exactly that.

**Secondary blockers:** prerequisite lifecycle task incomplete (§15); shared runner unavailable (§16).

---

### A note on sequencing

I'd flag that (a) looks likely to be the intended answer, because the symptom you described — *"Allow Negative ON shows the material as Out of Stock"* — is a **projection** problem, not an arithmetic one: `AvailabilityState::fromAvailable()` maps `0 → OutOfStock` with no knowledge of `allow_negative_stock`, and the raw-materials UI collapses `untracked → out_of_stock`. Both are fixable without touching a single availability formula.

That is a hypothesis, not a finding, and I have not acted on it.

**Nothing was modified. No UI-only workaround was applied. No availability formula was changed. No inventory row was invented.**
