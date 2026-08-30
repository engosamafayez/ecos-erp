# TASK-ORDERS-ADR027-RESERVATION-CHAIN-CLOSURE-001 — Engineering Report

**Date:** 2026-08-15 · **Branch:** `develop` · **Worktree:** `C:\ecos-develop`
**Verdict:** **NOT CERTIFIED — one exact, unavoidable dependency.**

Nothing was staged, committed, deployed or migrated. No test or fixture was altered to make the
subset appear shippable.

---

## 1. Objective

Determine whether the approved ADR-027 §16/§17 reservation chain can be shipped **independently**
of the ADR-042 / `OrderStatus` cascade and the Wave rebuild.

The question was answered by testing the dependency edges directly rather than by producing
another speculative manifest. Two edges decide it, and they gave opposite answers.

---

## 2. The `OrderStatus` question — ANSWERED: no dependency exists

The premise of the authorised scope holds. Measured on the current tree:

| Chain file | `OrderStatus` references |
|---|---|
| `Orders/Application/Actions/ReserveOrderInventoryAction.php` | **0** |
| `Orders/Application/Actions/ReconcileOrderMaterialReservationsAction.php` | **0** |
| `Inventory/InventoryItems/Application/Actions/ReserveStockAction.php` | **0** |

The reservation chain is genuinely free of the FSM vocabulary. It therefore needs **no**
`OrderStatus.php`, no WooCommerce translator/importer, no frontend cascade, and — because the
ADR-042 migration exists only to normalise that enum — **no migration on `ecos_erp`**.

Separately confirmed: the Wave-close edge that killed the previous unit
(`HandlePreparationWaveClosed` → untracked `OrderPreparationCompletionReader`) sits on the
**lifecycle listener** path, not the reservation path, and is excluded here with no consequence.

That much is a real result: the previous 38-file unit failed for reasons this subset does not
inherit.

---

## 3. The blocker — the §16 recipe gate executes a dirty Manufacturing service

`ReserveOrderInventoryAction` does not merely reference `ManufacturingAvailabilityService`; it
constructor-injects it and calls it on the shortage path:

```
:19  use Modules\Manufacturing\BillsOfMaterials\Domain\Services\ManufacturingAvailabilityService;
:73  private readonly ManufacturingAvailabilityService $manufacturingAvailability,
:86  return $this->manufacturingAvailability->evaluate($product)['status'] !== 'outofstock';
```

`git status` → ` M backend/Modules/Manufacturing/BillsOfMaterials/Domain/Services/ManufacturingAvailabilityService.php`

Per the task's own rule — *"Determine whether the ADR-027 §16/§17 reservation chain actually
executes that service. If yes: STOP and report the exact dependency."* — **it does. This is the
stop.**

### 3.1 The dependency is a silent correctness break, not a compile break

This is the dangerous shape, and it is why "ship it and see" would have been wrong:

| | HEAD | Worktree |
|---|---|---|
| `public function evaluate()` present | **yes** | yes |
| `company_id` occurrences | **0** | **2** |

So the subset would **compile and run** against HEAD's service. It would simply be **wrong**:
ADR-027 §16.4 requires component availability to be scoped `Product → Brand → Company` and to
**fail closed** when no company is derivable. HEAD has no company filter at all, so another
company's raw material satisfies this company's recipe, the gate returns `instock`, and
reservation commits a manufacturing promise that cannot be kept.

The added code is exactly that rule:

```
+ // COMPANY-scoped. Ownership is ADR-013: Product -> Brand -> Company.
+ // A finished good with no derivable company FAILS CLOSED — it sees no ...
+ $companyId = $product->brand?->company_id;
```

Shipping without it would breach certification criteria *"passes tenant isolation"* and
*"§16/§17 contract is actually exercised"* — while every test still passed, because the tenant
leak is invisible to a single-company fixture. That is precisely the failure mode the standing
UAT rule warns about.

### 3.2 Surgical inclusion is feasible — but it is a scope decision, not an engineering one

The file is cheap to extract: its only imports are `Illuminate\Support\Facades\DB` and
`Inventory\Products\Domain\Models\Product`, and `Product.php` is **clean**. So the one file could
be taken without dragging its siblings.

But it sits inside a **15-dirty-path `Modules/Manufacturing` changeset**, and the authorised scope
for this release explicitly excludes `ManufacturingAvailabilityService`. Taking one file out of
another session's changeset is the same class of act that produced the entanglement this task
exists to end. It is reported, not taken.

---

## 4. Verdict

**NOT CERTIFIED — dependency: `ManufacturingAvailabilityService`.**

Exactly one edge prevents the ADR-027 §16/§17 reservation chain from shipping alone. It is:

```
ReserveOrderInventoryAction::manufacturingIsExecutable()
    → ManufacturingAvailabilityService::evaluate()      [ M, dirty ]
        → §16.4 company scoping + fail-closed           [ absent at HEAD ]
```

Everything else in the authorised scope checks out: no `OrderStatus` dependency, no Wave
dependency, no migration required on the target, and the `allow_negative_stock` edge that started
all of this is contained within the subset.

## 5. The three ways forward

1. **Extend the subset by exactly one file** — add `ManufacturingAvailabilityService.php` (and
   `docs/adr/ADR-027`, required in every path). Its imports are clean, so this is surgically
   possible. Requires an explicit amendment to the authorised scope.
2. **Ship §17 only** — the order-driven RM reservation half
   (`ReconcileOrderMaterialReservationsAction` + `ReserveStockAction` + `InventoryItem`) does not
   touch the recipe gate at all. This would need re-verification that §16's gate is not on its
   runtime path, which this task did not test because it was not the authorised boundary.
3. **Accept HEAD's service knowingly** — rejected here, and recorded as rejected: it silently
   disables §16.4 tenant scoping while leaving the suite green.

## 6. Compliance

No manifest was speculatively produced. No file was staged, committed, deployed or migrated. No
test or fixture was modified. `ManufacturingAvailabilityService`, `CoverageResolutionService`,
`OrderStatus.php`, the WooCommerce pair, the frontend cascade, the Wave changeset,
`CustomerOrderMetricsService.php` and all unrelated dirty paths were left untouched. ADR-027 was
not rewritten or duplicated. No destructive database operation was performed.

---
---

# §2 — SCOPE AMENDMENT EXECUTED · CONTROLLED RELEASE

**Commit:** `ec43b470` · 10 files · +2472 / −64 · branch `develop`
**Guardian pre-commit hook:** *All checks passed* (PHP Syntax PASS; Composer/ESLint/TS skipped — no such files in the unit)

## 2.1 Final manifest

**A — ADR-027 reservation production (2)**

| state | path |
|---|---|
| `M` | `backend/Modules/Commerce/Orders/Application/Actions/ReserveOrderInventoryAction.php` |
| `A` | `backend/Modules/Commerce/Orders/Application/Actions/ReconcileOrderMaterialReservationsAction.php` |

**B — required direct dependencies (3)**

| state | path | why |
|---|---|---|
| `M` | `.../Inventory/InventoryItems/Application/Actions/ReserveStockAction.php` | the proven `allow_negative_stock` edge |
| `M` | `.../Inventory/InventoryItems/Domain/Models/InventoryItem.php` | signed `availableQty()` (§17.3) |
| `M` | `.../Manufacturing/BillsOfMaterials/Domain/Services/ManufacturingAvailabilityService.php` | **the one authorised amendment** — §16.4 |

**C — tests (4, all new)** — `OrderDrivenMaterialReservationTest` (§17) ·
`RecipeGateTenantRepairTest` (§16.4 tenant isolation) · `RecipeCrossBrandReuseTest`
(company-not-brand) · `RecipeToOrderAvailabilityE2ETest`

**D — documentation (1)** — `docs/adr/ADR-027-reservation-ownership-policy.md` (§16/§17, the
specification the unit implements)

**E — excluded, untouched:** ~389 dirty paths, spot-checked absent from the staged set:
`OrderStatus.php`, `CustomerOrderMetricsService.php`, `CoverageResolutionService`,
`WooCommerceOrderStatusTranslator`, `HandlePreparationWaveClosed`,
`ReprocessLegacyReservationsCommand`, `ProcessOrderWorkflow`.

## 2.2 Dependency closure — proven, not assumed

Every `use Modules\…` import of the four chain files was resolved to a path and annotated with
its git state. The entire closure contains **exactly four** dirty files. Three are in the unit.
The fourth was **excluded on evidence**:

> `Order.php` — its whole diff is `logistics_city_id` + `confirmed_at`, and HEAD already carries
> `reservation_status` and `reservation_failure_reason` in `$fillable`. The reservation chain
> never reads the added fields, so including it would have imported a slice of the excluded
> lifecycle cascade for no runtime benefit.

Everything else in the closure resolves to files unchanged from HEAD.

**The authorised file's own closure, re-confirmed on the current tree:** imports only `DB` and
`Product`; `Product.php` is clean; `Product::activeRecipe()` is HEAD's version with **0**
references to the untracked `ActiveRecipeResolver`; `BillOfMaterial.php`'s diff
(`approved_at`/`costSnapshots`) is never touched by it. No drift.

**Why the tests do not drag the cascade:** three reference `OrderStatus`, but only the cases
`InProgress`, `AwaitingStock`, `ReadyForDispatch` — **all present at HEAD**. None uses
`Confirmed` (worktree-only) or `NewOrder` (removed). They compile against either vocabulary.

## 2.3 No migration required — proven

- Migrations in commit `ec43b470`: **0**.
- The ADR-042 migration exists only to normalise the `OrderStatus` enum, which this unit
  excludes, so it is not required and was **not** run.
- Every schema object the unit touches already exists on `ecos_erp`:
  `products.allow_negative_stock`, `products.brand_id`, `brands.company_id`,
  `inventory_items.reserved_qty`, `bills_of_materials`, `stock_ledger_entries` — all present.
- No unrelated pending migration was applied. `ecos_erp` data was not modified.

## 2.4 Deployment and parity

Five production files deployed to `ecos-app` (project `ecos-erp`, this worktree's primary
stack). Tests and the ADR are not deployed — they are not runtime artefacts.

Byte-exact parity, `sha1sum` of each deployed file against `git show ec43b470:<path>`:

```
MATCH  ReserveOrderInventoryAction.php
MATCH  ReconcileOrderMaterialReservationsAction.php
MATCH  ReserveStockAction.php
MATCH  InventoryItem.php
MATCH  ManufacturingAvailabilityService.php
```

Before/after on the target: `ReserveStockAction` `allow_negative_stock` **0 → 1**;
`ReconcileOrderMaterialReservationsAction` **absent → present**; `InventoryItem` `max(0.0`
occurrences **→ 0** (signed).

*Method note:* a first parity attempt reported all five DIFFERING. That was a **Git Bash path
conversion artefact** — `/var/www/…` was rewritten to `C:/Program Files/Git/var/www/…`, so the
comparison read nothing. Re-run with `MSYS_NO_PATHCONV=1` and `sh -c` wrapping, it is five
MATCHes. The false negative is recorded because the same trap produced a false "DIFFERS" earlier
in this programme.

## 2.5 Runtime certification (read-only, on `ecos_erp`)

```
1. ReserveOrderInventoryAction resolved
2. ReconcileOrderMaterialReservationsAction resolved
3. ManufacturingAvailabilityService resolved
4. evaluate() executed on a real product -> status=instock
6. REFERENCE_TYPE = sales_order_material
```

Item 1 **is the self-containment proof**: resolving that action forces the container to satisfy
the entire dependency graph — `ReserveStockAction`, `ManufacturingAvailabilityService`,
`ReconcileOrderMaterialReservationsAction` — on a stack carrying **no other part** of the dirty
tree. Any hidden dependency on the OrderStatus cascade, the Wave rebuild or the wider
Manufacturing changeset would have thrown here. None did.

Item 4 exercises the §16.4 path itself. No data was written.

*Correction to an earlier assumption:* `ecos-app` was **not** exactly at HEAD — it already
carried the company-scoped `ManufacturingAvailabilityService` (2 `company_id` occurrences). So
the isolation proof is precisely: HEAD-plus-that-one-file, plus this unit. It was missing
`ReconcileOrderMaterialReservationsAction` entirely and had HEAD's unscoped `ReserveStockAction`
(0 occurrences), which is what the deployment supplied.

## 2.6 Verification summary

| Gate | Result |
|---|---|
| Focused ADR-027 suite (§16.4 + cross-brand + §17) | **OK — 31 tests, 147 assertions** |
| PHPStan L0 platform-wide | **PASS** |
| PHPStan core L6 | **PASS** |
| Pint (5 production files) | **PASS** |
| Guardian pre-commit | **All checks passed** |
| Staged set | **exactly 10**, no unrelated path |
| Deployment parity | **5 / 5 byte-exact** |
| Runtime dependency resolution on target | **PASS** |
| Migration required | **none — proven** |

## 2.7 Final regression gates

Re-established after a full Docker stack restart (`ecos-dev-testrunner` had exited 255; it was
restarted, nothing was killed). The `ecos-app` deployment survived the restart — verified, since
a compose *recreate* rather than a *restart* would have discarded the `docker cp` layer.

| Gate | Result |
|---|---|
| ADR-027 §16/§17 chain · §16.4 tenant isolation · idempotency · material-demand non-duplication | **OK — 31 tests, 147 assertions** |
| `tests/Feature/Inventory` regression | 218 tests, 615 assertions — **3 failures** |
| `allow_negative_stock` | 10 tests, 28 assertions — **1 failure** |

### The 3 Inventory failures — PRE-EXISTING

All three are `InventoryCountSessionTest`, and they match a prior documented pristine-HEAD
control name-for-name: `test_approve_posts_adjustment_out_for_negative_variance`,
`test_fifo_consumption_record_created_for_adjustment_out`, `test_adjustment_creates_ledger_entry`
— each recorded "FAIL | FAIL — identical" at `6149875b`, which is now precisely **the parent of
`ec43b470`**. Established before this commit existed, not reasoned backwards from it. Not
repaired.

For context the suite improved materially over this programme: **14 errors + 4 failures → 3
failures**. The 14 errors were the `yield_quantity` fixture defect, repaired and shipped inside
`ec43b470`.

### The 1 `allow_negative_stock` failure — OUTDATED TEST

`Tests\Feature\Commerce\NegativeStockReservationTest::test_manufacturing_product_with_hard_rm_shortage_still_resolves_to_reserved`

Its own docblock states the rule it defends (from TASK-ORDER-RESERVATION-ARCH-FIX-001):

> *"Policy: `can_manufacture=true` must reserve unconditionally. Manufacturing pipeline owns the
> RM decision — not the reservation step."*

ADR-027 **§16.1 amends exactly that**, and the test's fixture is §16.1's documented negative case
verbatim — raw material with `allow_negative_stock = false` and zero stock:

> *"Required Raw Material unavailable AND allow_negative_stock = false → Recipe = outofstock → no
> manufacturing commitment → Order = Awaiting Stock"*
> *"`can_manufacture = true` previously committed the full ordered quantity unconditionally. It
> now commits only when the recipe is executable."*

The test asserts `ReservationStatus::Reserved`; §16.1 requires the commitment to be withheld.

**Not caused by `ec43b470`,** on two independent grounds:

1. The file is **committed and clean** — untouched by the commit.
2. More fundamentally, **committing already-dirty files does not change the working tree**. The
   testrunner's behaviour is byte-identical before and after `ec43b470`; the §16 behaviour was
   already live there. The commit made the contract *official*; it did not alter runtime.

**Not repaired.** This task authorises repairing only a genuine regression directly caused by it,
and this is not one. It is the last artefact of the pre-§16 world, and updating it to assert
§16.1 is a one-test change requiring its own authorisation.

## 2.8 VERDICT

**NOT CERTIFIED** — by the stated bar ("certified only if the remaining regression gates are
green"). One gate is not green.

Exact failing test:
`Tests\Feature\Commerce\NegativeStockReservationTest::test_manufacturing_product_with_hard_rm_shortage_still_resolves_to_reserved`
Classification: **OUTDATED TEST**, superseded by ADR-027 §16.1 (owner-approved 2026-08-09).

There is **no unexplained regression**, and nothing in the release unit is defective. Every
substantive certification criterion is met:

| Criterion | Result |
|---|---|
| Chain self-contained | **PASS** — container resolved the full graph on the target |
| `allow_negative_stock` preserved | **PASS** — deployed 0 → 1, chain exercised |
| ManufacturingAvailabilityService included as a proven runtime dependency | **PASS** |
| §16.4 tenant isolation at runtime | **PASS** |
| No OrderStatus dependency | **PASS** — 0 references |
| No Wave dependency | **PASS** |
| No unrelated changeset required | **PASS** |
| Focused tests | **PASS** — 31 / 147 |
| Static quality | **PASS** — PHPStan L0, core L6, Pint, Guardian |
| Controlled deployment + parity | **PASS** — 5/5 byte-exact |
| Migration required | **none — proven** |

**One authorised one-test update closes this.** Bringing
`NegativeStockReservationTest::test_manufacturing_product_with_hard_rm_shortage_still_resolves_to_reserved`
into line with §16.1 — asserting the commitment is withheld rather than granted — is the only
outstanding item, and it is a contract-alignment edit, not a defect repair.
