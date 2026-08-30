# TASK-DISASSEMBLY-RECIPE-COST-SNAPSHOT-AND-VALUATION-001 — Engineering Report

**Date:** 2026-08-14 · **Branch:** `develop`

> ## STATUS: IMPLEMENTATION COMPLETE — STATIC VERIFIED — RUNTIME BLOCKED
>
> The cost contract is implemented end to end and verified against **real `ecos_dev`
> data (read-only)**. **PHPUnit was not run**: the shared runner is owned by another
> agent's session. No process was killed, no `migrate:fresh`, no competition for
> `ecos_dev_test`.
>
> **The migration has NOT been applied to any database.** It is additive and
> conflict-free, but running it is a deployment decision — see §9.

---

## 1. The contract

What a component is worth when a finished product is taken apart is **what the
approved recipe said it cost**. Not the product's cost, not its FIFO layer cost, not
the material's price today.

```
Component value  =  approved recipe's frozen unit cost  ×  quantity recovered
```

### 1.1 The mandatory example, and why the obvious answer is wrong

Finished product cost **1,000** · its FIFO layer **1,200** · recipe cost **800**.

| Component | Correct | Proportional allocation (WRONG) |
|---|---|---|
| Honey | **500** | 600 |
| Coffee | **200** | 240 |
| Hazelnut | **100** | 120 |
| **Total** | **800** | 1,200 |

The wrong column is what you get by spreading the FG's acquisition cost across the
components by recipe ratio (×1.2). It is dangerous precisely because it looks
principled: it reconciles neatly to the FG's book value. But it inflates every
component by the labour, overhead and margin the assembled product carried — value
the components never had. Disassembly would then *create* 400 of inventory value out
of nothing, every time it ran.

Pinned by `test_5_components_are_valued_at_recipe_cost_not_a_share_of_the_fg_cost`,
which asserts both the right answer **and** explicitly asserts the components are not
600 / 240 / 120.

## 2. Why a new structure was unavoidable

Both candidate sources were audited and neither can serve this contract:

| Source | Why not |
|---|---|
| `bill_of_material_lines` | Holds `quantity` + `waste_percentage`. **No cost column at all.** |
| `recipe_cost_histories.cost_snapshot` | Persists `RecipeCostSummaryDTO::toArray()` — **thirteen scalar totals, zero per-component entries.** |

An aggregate of 800 cannot be decomposed into 500 + 200 + 100 without inventing the
split. **Per-component costs have never been persisted anywhere, at any time.** No
archaeology recovers them — which is why §6 blocks rather than approximates.

`RecipeSnapshot` (the existing VO) was also checked and is **not** this: it is a
runtime resolution snapshot, re-resolved on every call, carrying no costs.

## 3. Schema — entirely additive

`2026_08_14_100000_create_recipe_cost_snapshots.php`

```
bills_of_materials  + approved_at (nullable), + approved_by (nullable)
recipe_cost_snapshots        id, company_id, bom_id, bom_version_number,
                             total_cost, yield_quantity, approved_at, approved_by
                             UNIQUE (bom_id, bom_version_number)
recipe_cost_snapshot_lines   id, snapshot_id, recipe_line_id, raw_material_id,
                             quantity, waste_percentage, unit_cost, extended_cost,
                             sku/name/unit_symbol snapshots
```

Nothing existing is altered. No data is migrated. **No historical cost is written.**

Three design decisions worth recording:

- **`yield_quantity` is frozen on the snapshot.** Component quantities are stated per
  *batch*. Without the yield captured alongside them, per-finished-unit cost is
  underivable, and editing `yield_quantity` later would silently re-scale historical
  valuations.
- **`company_id` is resolved at approval and stored.** A BOM has no `company_id`, and
  `products.company_id` **does not exist** (verified) — the only link is
  product → brand → company. Storing it stops tenant scoping depending on a join a
  later re-brand would redirect.
- **`recipe_line_id` is nullable and deliberately NOT a constrained FK.** Recipe lines
  are replaced wholesale on edit; a snapshot must survive its source line being
  deleted. That is the entire point of an immutable record (test 17).

## 4. Approval is the snapshot event

`is_active` alone could not anchor this: it is a boolean that can be toggled
repeatedly and records no moment. `SetBomStatusAction` — the existing activation
lifecycle — now records `approved_at` / `approved_by` and takes the snapshot **in the
same transaction**.

**Fail-closed:** if the recipe cannot be costed, `CreateRecipeCostSnapshotAction`
throws and the activation rolls back with it. There is no window in which a recipe is
active but unvalued. No second state machine was introduced.

Deactivation never touches snapshots — turning a recipe off says nothing about goods
already built under it (test 20).

## 5. One formula, one owner

`CostCalculationEngine` declares itself the sole owner of cost formulas, so the line
arithmetic was **extracted into `calculateLines()` and `calculate()` refactored to sum
it** rather than duplicated into the snapshot.

```
effectiveQty  = quantity × (1 + waste% / 100)
extendedCost  = effectiveQty × material_cost
```

A snapshot whose lines add up to something other than the recipe cost displayed beside
it would be indefensible. Sharing the origin makes it impossible (test 19).

**Verified behaviour-preserving on real data** — see §8.1.

## 6. Disassembly refuses rather than guesses

`DisassemblyWorkflow` gained two blocking gates before plan building:

| Reason | When |
|---|---|
| `recipe_cost_snapshot_missing` | The recipe version has no approved snapshot |
| `recipe_cost_snapshot_stale` | A component exists in the recipe but not in the snapshot |

There is **no fallback** to current material cost, `recipe_cost`, or the FG's FIFO
cost. Each would produce a confident wrong number; an exception produces a correct
refusal. The stale gate matters most: valuing one component at zero *succeeds*, and a
disassembly that succeeds while quietly destroying value is worse than one that fails.

## 7. The FIFO gap this closes

`produceComponent()` added `on_hand_qty` and a ledger entry but **created no receipt
layer** — its own docblock deferred this to "PKG-12 (Cost Engine)". Components
returned by disassembly therefore entered inventory **with no cost basis at all**, to
be consumed later against unrelated layers or none.

It now opens a layer at the frozen snapshot cost. `supplier_id` / `goods_receipt_id`
stay null: this stock came from a disassembly, not a purchase, and inventing a receipt
would make it indistinguishable from real procurement (test 14).

A zero unit cost is never written — the workflow already blocks unpriced plans, so
reaching there means a gate was bypassed, and a zero-cost layer would bake the error
permanently into valuation.

## 8. Runtime verification against real `ecos_dev` (READ-ONLY)

### 8.1 The refactor is behaviour-preserving — proven, not asserted

`calculateLines()` was run against all six real recipes. No writes.

```
BOM-00001  computed=26000  stored=26000  unpriced=0  MATCHES stored recipe_cost
   RM-000001  unit=25000  qty=1  waste=2  eff=1.02  ext=25500
   RM-000002  unit=500    qty=1  waste=0  eff=1     ext=500
```

`1 × 1.02 × 25000 = 25500`, `+ 500` → **26000**, identical to the stored
`recipe_cost`. The extracted formula reproduces the existing engine exactly, waste
included, on production-shaped data.

### 8.2 Data impact — the finding that matters (PART 23)

```
BOM-00001  unpriced=0  → snapshots successfully on re-approval
BOM-00002  unpriced=1  (RM-000003 material_cost = NULL)  → WOULD BLOCK APPROVAL
BOM-00003  unpriced=1  (RM-000004 material_cost = NULL)  → WOULD BLOCK APPROVAL
BOM-00004  unpriced=1  (RM-000005 material_cost = NULL)  → WOULD BLOCK APPROVAL
BOM-00005  unpriced=1  (RM-000006 material_cost = NULL)  → WOULD BLOCK APPROVAL
BOM-00006  unpriced=1  (RM-000009 material_cost = NULL)  → WOULD BLOCK APPROVAL
```

**All 6 recipes are active. None has a snapshot. All 6 are therefore blocked from
disassembly until re-approved.** Nothing was backfilled — inventing historical costs
is precisely what this task forbids.

**The important part: this is pre-existing data debt, not damage done by this change.**
Those five recipes already report `recipe_cost = 0.0000` for real sellable products.
They have been silently producing a meaningless zero cost all along. This change does
not break them; it makes an existing breakage visible and refuses to build valuation
on top of it.

### 8.3 Safe re-approval path

1. Set `material_cost` on **RM-000003, RM-000004, RM-000005, RM-000006, RM-000009**
   (five materials — this is required regardless of this task, since their recipes
   currently cost zero).
2. Re-approve each recipe through the normal UI toggle (deactivate → activate) or
   `PATCH` on the recipe status endpoint. Approval writes the snapshot atomically.
3. **BOM-00001 needs no data fix** — re-approving it is sufficient and will snapshot
   at 26,000 immediately.

No SQL, no backfill, no migration of historical cost. Re-approval is the entire
procedure.

### 8.4 Migration is conflict-free

```
bills_of_materials.approved_at  : absent (safe to add)
bills_of_materials.approved_by  : absent (safe to add)
recipe_cost_snapshots           : absent (safe to create)
recipe_cost_snapshot_lines      : absent (safe to create)
```

## 9. Tests — written, NOT executed

`tests/Feature/Manufacturing/RecipeCostSnapshotValuationTest.php` — **21 cases**.

| # | Case |
|---|---|
| 1–4 | Snapshot shape, cost at approval, waste applied, total = Σ lines |
| **5** | **Mandatory example — 500/200/100, explicitly NOT 600/240/120** |
| 6 | Material cost change never alters an existing snapshot |
| 7 | Re-approving the same version is idempotent |
| 8–9 | Unpriced / empty recipe cannot be approved; activation rolls back |
| 10–11 | Disassembly blocked without a snapshot; blocked run mutates nothing |
| 12 | Blocked when a component was added after approval (stale) |
| 13–15 | Receipt layer created at snapshot cost; not disguised as a purchase; ignores later price change |
| 16 | Approval anchor + frozen yield + per-unit derivation |
| 17 | Snapshot survives deletion of its source recipe line |
| 18–21 | Company scoping · engine/snapshot agreement · deactivation preserves · direct vs approval path |

**Not executed.** The runner was gated twice and was busy both times with another
agent's suites (`OrdersInventoryExecutionLifecycleTest`, then
`tests/Feature/Operations/WaveEngine`).

The `component()`-style visibility trap from an earlier session was checked explicitly
this time: all 14 helper names were reflected against `PHPUnit\Framework\TestCase` and
`Illuminate\Foundation\Testing\TestCase` → **NO COLLISIONS**. `php -l`, PHPStan and
Pint cannot catch that class of failure, so it was verified directly.

## 10. Regression repaired in an existing suite

`tests/Feature/Manufacturing/DisassemblyTest.php` (24 tests) builds recipes with
`Recipe::create(['is_active' => true])` — **bypassing the approval path entirely**.
Every one of those tests would now hit the new gate and fail with
`recipe_cost_snapshot_missing`.

Fixed at the fixture, not by weakening the gate: `makeComponent()` now sets a
`material_cost`, and `addLine()` re-takes the snapshot so it always describes the whole
recipe rather than the first component added.

`ManufacturingApplicationServiceTest` was checked and is **unaffected** — its three
disassembly tests use products with no recipe, so they block earlier at
`recipe_not_found`.

**This repair is unverified** — it is the main reason runtime execution matters here.

## 11. Static quality

| Check | Result |
|---|---|
| PHP syntax (all changed files) | ✅ clean |
| **PHPStan L0** (the platform gate) | ✅ **[OK] No errors** |
| **Pint** | ✅ passed on changed files |
| Test-helper name collisions | ✅ none (reflection-verified) |
| Frontend | not touched — no changes |

`phpstan.neon.dist` confirms **level 6 covers `app/Core` + Contracts + Traits only**;
`Modules` is level 0 platform-wide. An exploratory L6 run on the new files surfaced
only Eloquent magic-property noise (no Larastan installed), but two findings were fixed
on merit anyway: a redundant `!== null` on a `mixed` value, and missing `@property`
annotations on both new models.

## 12. Findings recorded, not acted on

1. **`DisassemblyWorkflow` treats batch quantity as per-unit.** `RecipeComponent::quantity`
   is the raw line quantity, but the workflow assigns it to `required_per_unit` and
   multiplies by the requested amount. **For any recipe with `yield_quantity != 1` the
   quantity recovered is wrong by a factor of the yield.** All six live recipes have
   yield 1.0, so it is currently latent. This is a *quantity* contract, predates this
   task, and correcting it changes how much inventory disassembly produces — a business
   behaviour change, so it is reported rather than silently altered.
   *(Cost is unaffected: the layer uses cost per unit of material, which is
   dimensionally correct regardless of yield.)*
2. **Five raw materials have `material_cost = NULL`** — RM-000003/4/5/6/9 — leaving five
   recipes at `recipe_cost = 0`. Needs a business data fix regardless of this task.
3. **Waste on disassembly.** The recipe's waste percentage inflates the *cost* of a
   component (correctly — waste was paid for), but disassembly recovers the stated
   quantity. Whether taking a product apart should return waste-adjusted or stated
   quantity is undefined in the contract and was not invented here.

## 13. Verdict

**NOT CERTIFIED** — and not claimed.

Implemented and evidenced: the cost basis is defined, frozen at approval, immutable
against later price changes, enforced fail-closed at both approval and disassembly, and
now carried into FIFO layers that previously did not exist. The refactor is proven
behaviour-preserving against real data, static quality is clean at the platform gate,
and the live data impact is measured with a re-approval path that requires no backfill.

Outstanding: **the suite has not run.** 21 new tests and one repaired 24-test suite are
unexecuted, and the migration is unapplied.

**FINAL STATUS: IMPLEMENTATION COMPLETE — STATIC VERIFIED — RUNTIME BLOCKED**

No `ecos_dev` mutation. No `migrate:fresh`. No process killed. No historical cost
invented, and no existing recipe backfilled.
