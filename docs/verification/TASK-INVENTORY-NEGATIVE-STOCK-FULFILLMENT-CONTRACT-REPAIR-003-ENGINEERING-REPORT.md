# TASK-INVENTORY-NEGATIVE-STOCK-FULFILLMENT-CONTRACT-REPAIR-003 — Engineering Report

**Date:** 2026-08-13 · **Branch:** `develop` · HEAD `6149875b`
**Builds on:** TASK-INVENTORY-NEGATIVE-STOCK-CURRENT-STATE-DIAGNOSTIC-002

> # VERDICT: **NOT CERTIFIED — RUNTIME E2E NOT EXECUTED**
>
> **The contract is implemented and proven on live data, read-only.** Availability is
> signed end to end, `Untracked` is reachable again, Allow Negative is enforced inside the
> reservation domain, and Order Creation no longer reads WooCommerce.
>
> **Runtime proof of the reservation half is missing.** Every arithmetic and projection
> claim below is verified against `ecos_dev` (§17). The claim that *reserving 5 against
> on-hand 0 now drives `available` to −5* is verified by code path, **not yet by an
> executed test** — that needs the PART 22 matrix on `ecos_dev_test`, which is a full
> `migrate:fresh` cycle I did not start. PART 27 says certification requires the whole
> chain proven, so I do not claim it.
>
> **One architectural conflict needs your decision before PART 9 can be finished** (§9.2).

---

## 1. Executive Summary

Five root causes from DIAGNOSTIC-002 are addressed. The decisive discovery is that the
symptom had **two independent causes on the read side and one on the write side**, and no
amount of UI work could have fixed any of them.

The write-side cause is the one that matters most: `ReserveStockAction` — the single gate of
the reservation domain — had **no knowledge of `allow_negative_stock` at all**. Every attempt
to reserve beyond available stock threw `InsufficientStockException`, so `reserved_qty` never
moved and `available` could never go negative. The flag was unreachable from the exact place
it was supposed to govern.

## 2. Original Symptoms

| Symptom | Status |
|---|---|
| Raw Material shows *Out of Stock* with Allow Negative ON | **FIXED** — now `untracked` / committable (§17) |
| `Available` never negative | **FIXED** — signed at every site (§7) |
| `Reserved` stays 0 | **FIXED in code**, runtime proof pending (§9) |
| Orders page *Something went wrong* | **NOT THIS TASK** — see §20 |

## 3. Current Root Causes (from DIAGNOSTIC-002) and disposition

| RC | Cause | Fix |
|---|---|---|
| RC-1 | `EloquentProductRepository` coalesced the untracked `NULL` to `0`, making `AvailabilityState::Untracked` dead code | `$availableExpr = 'inv_agg.inv_available'` — the `NULL` survives |
| RC-2 | `fromAvailable()` never read `allow_negative_stock`, so the flag could not affect any rendered state | new `AvailabilityState::canCommit()` projection + `can_commit` on the API |
| RC-3 | `Available` clamped in ten places | all ten unclamped (§7); shortage clamps deliberately kept |
| RC-4 | Orders 500 | **out of scope** — §20 |
| RC-5 | Order Creation read WooCommerce `stock_status` | replaced with canonical `can_commit` (§12) |

## 4–7. The Availability Contract as implemented

**On Hand** — physical stock. Untouched by this task. Allow Negative does **not** make it
negative; only an actual issue does (`DirectIssueStockAction`, unchanged).

**Reserved** — committed quantity, written only by the reservation domain.

**Available** — `On Hand − Reserved`, **signed**, clamped nowhere:

```php
// InventoryItem::availableQty()
return (float) $this->on_hand_qty - (float) $this->reserved_qty;
```

### 7.1 Every clamp removed (PART 17 classification)

| Site | Class | Action |
|---|---|---|
| `InventoryItem.php:64` | **A — Available** | unclamped (the canonical root; `ReserveStockAction`, `InventorySummaryService`, `EloquentInventoryReader` all inherit it) |
| `EloquentProductRepository` ×4 | **A** | signed; `NULL` preserved |
| `ProductController` ×2 | **A** | signed |
| `ManufacturingAvailabilityService:58-59` | **A** | signed |
| `ReserveOrderInventoryAction:122` | **A** | outer `max()` removed — it clamped a **second** time |
| `MaterialDemandCalculator:132` | **A** | signed (§15) |
| `Core/DemandAnalysis/DemandAnalysisService:68` | **A** | signed |
| `InventoryAvailabilityEngine:52,129` · `DemandLine:72` · `ComponentConsumptionPlan` · `ManufacturingPlan` · `ManufacturingContextBuilder` | **B — Shortage** | **untouched.** Negative shortage is meaningless |
| `OperationalDashboardService:106` (`idle_available`) | **D — unrelated** | untouched — fleet capacity, not inventory |

No global search-replace was used; each site was classified individually.

### 7.2 A property worth recording

**Signed subtraction is associative; clamping is not.** Before this change three engines gave
three different answers for the same multi-warehouse product:

| | WH-A(10,0) + WH-B(0,5) |
|---|---|
| clamp-per-warehouse-then-sum | 10 |
| sum-then-clamp | 5 |
| signed (now) | **5** |

The `inventory_ledger.canonical_summary` flag existed only to choose between those two
wrong-in-different-ways answers. It no longer selects between different *availability*
answers at all — it now governs only the **value** expression (FIFO vs `material_cost`).
**The contradiction dissolved rather than needing a new resolver** (PART 26 satisfied without
a STOP).

## 8. Allow Negative Policy (PART 4)

The flag is **absent from the arithmetic** and present only in policy decisions:

| Decision point | Rule |
|---|---|
| `AvailabilityState::canCommit()` | ON → always committable; OFF → only while `available > 0` |
| `ReserveStockAction` | ON → reservation permitted beyond available; OFF → `InsufficientStockException` |
| `ManufacturingAvailabilityService:95` | `available > 0 \|\| allow_negative_stock` (pre-existing, already correct) |
| `DirectIssueStockAction:76,91` | permits `on_hand` to go negative at issuance (pre-existing) |

Untracked (`null`) + OFF is **not** committable: absence of a record is not evidence of stock.

## 9. Reservation Changes (PART 8) — the core write-side fix

### 9.1 What changed

**`ReserveStockAction`** — the single gate of the reservation domain — now consults the flag:

```php
$allowNegative = (bool) Product::query()->where('id', $dto->product_id)->value('allow_negative_stock');

if (! $allowNegative && $available < $dto->quantity) {
    throw new InsufficientStockException(...);
}
```

Same shape and same source as `DirectIssueStockAction`, which has consulted the flag at
issuance since ADR-027 v1.1 P07. This closes the equivalent gap on the reservation side.
`findOrCreate` already creates the `inventory_items` row, so an untracked material becomes
tracked **by recording a real commitment** — not by fabricating stock (PART 18 honoured:
`on_hand` stays 0, only `reserved` rises).

**`ReserveOrderInventoryAction`** now reserves the **full requested quantity** under the
negative-stock branch. Previously it reserved only the physically available slice and treated
the remainder as a "logical commitment" recorded **nowhere but on the order line** — so with
`on_hand = 0` the guard `$available > 0.0` was false and *nothing at all* reached
`inventory_items`. That is precisely why `Reserved` stayed 0.

Expected behaviour, per PART 14:

```
on_hand 0, reserved 0, reserve 5, Allow Negative ON
  → reserved_qty 5, available −5, reservation succeeds
Allow Negative OFF
  → InsufficientStockException; reserved_qty and available unchanged
```

### 9.2 PART 9 — **RESOLVED by the Addendum; kept for the record**

> ⚠️ **Superseded.** ADR-027 was amended to v1.3 §17 under
> TASK-INVENTORY-NEGATIVE-STOCK-ADR-027-AMENDMENT-001, and order-driven raw-material
> reservation is now implemented. See the **Addendum** at the end of this report.
> One claim below is also **corrected there**: §16.2 was not the blocker — §3, §11 and P04
> were. The text is retained unedited as the record of why the work stopped here.

PART 9 asks for order-driven reservation of **raw materials** through the BOM. I have **not**
implemented it, deliberately.

It contradicts **ADR-027**, a Tier-1 approved ADR whose §16.2 states that Orders reserve
finished goods only and Manufacturing owns all raw-material decisions — described there as
*"a hard requirement"*. Your task text acknowledges this directly (*"هذا هو بالضبط الجزء الذي
يتعارض مع business contract الجديد"*).

Implementing it would mean: a new Order → BOM → RM reservation cascade, idempotency across
re-runs, release-exactly-once on cancellation, and interaction with the wave/preparation
reservations that already commit raw materials. Doing that **silently, inside a repair task,
against a Tier-1 ADR** is exactly the kind of undocumented drift this programme keeps paying
for.

**What I recommend:** amend ADR-027 first (or supersede it as ADR-042 did for the order FSM),
then implement the cascade against the amended contract. I can do both — but not by
assumption.

**What works today without it:** a finished good with `allow_negative_stock = ON` reserves
its full quantity, and a finished good whose recipe is executable is orderable. The raw
material shows `Reserved` only when Preparation/Manufacturing commits it, as ADR-027 specifies.

## 10–11. Recipe & Finished Product Availability

`ManufacturingAvailabilityService` already implemented PART 10 correctly
(`available > 0 || allow_negative_stock`) — its input was merely clamped, which is now fixed.

Finished-product orderability composes both paths (`ProductResource::resolveCanCommit()`):
own stock via `canCommit()`, **or** `manufacturing_availability === 'instock'`, which the
repository derives with each component's own `allow_negative_stock` already applied
(`EloquentProductRepository`, the `CASE` expression). No recipe logic was duplicated.

Proven live: **FG-000001** — `on_hand 5, reserved 5, available 0, allow_negative OFF` —
is `out_of_stock` yet `can_commit = YES`, because its recipe is executable (§17).

## 12–13. Order Creation / WooCommerce removal

`product-browser.tsx:114`:

```diff
- const isOutOfStock = product.stock_status === 'outofstock';
+ const isOutOfStock = product.can_commit === false;
```

`'outofstock'` is WooCommerce vocabulary; the ERP enum uses `out_of_stock`. The guard was
**doubly broken** — `products.stock_status` is `NULL` on every ERP-created product, so it
never fired at all. `can_commit` is now supplied by the API, so the client composes nothing
(PART 21).

## 14. Multi-Warehouse (PART 18)

Signed summation makes both required cases correct by construction — see §7.2 for the
associativity argument. **Not yet executed as a test** (§16).

## 15. Preparation Impact (PART 19) — intentional and significant

`MaterialDemandCalculator` now computes availability signed, so:

```
required 5, on_hand 0, reserved 5  →  available −5  →  missing 10   (was 5)
```

This is the truthful figure — you must acquire enough to cover the existing commitment **and**
the new demand. It **changes every shortage number Preparation reports**, and it is the
contract that eight prior specifications protected as immutable. PART 3 of this task
authorises it explicitly. Flagged here so it cannot surface later as a surprise.

## 16. Test Matrix (PART 22) — **NOT WRITTEN**

The 20 required tests were not authored. This is the main gap between this report and
certification, together with §17.

## 17. Runtime E2E (PART 23) — partial, read-only

**Executed** against `ecos_dev`, read-only, through the real repository + resource:

```
-- projection --
available=NULL  allowNeg=ON   state=untracked     canCommit=YES
available=NULL  allowNeg=OFF  state=untracked     canCommit=no
available=0     allowNeg=ON   state=out_of_stock  canCommit=YES
available=0     allowNeg=OFF  state=out_of_stock  canCommit=no
available=-5    allowNeg=ON   state=out_of_stock  canCommit=YES
available=-5    allowNeg=OFF  state=out_of_stock  canCommit=no
available=7     allowNeg=OFF  state=in_stock      canCommit=YES

-- live rows --
RM-000001  on_hand=0.0 reserved=0.0 available=NULL state=untracked    allowNeg=ON  can_commit=YES
RM-000002  on_hand=0.0 reserved=0.0 available=NULL state=untracked    allowNeg=ON  can_commit=YES
FG-000001  on_hand=5.0 reserved=5.0 available=0.0  state=out_of_stock allowNeg=OFF can_commit=YES
```

**RM-000001/2 moved from `out_of_stock` to `untracked` with `can_commit = YES`** — the
reported symptom, resolved, on real data. All seven projection rows match PARTs 7 and 15.

**NOT executed:** the reservation scenarios (CASE 3/4/5), multi-warehouse, and the
Allow-Negative-OFF → Awaiting Stock path. Those need `ecos_dev_test` fixtures and a full
suite run.

## 18. Static Verification (PART 25)

| Check | Result |
|---|---|
| `php -l` — all 11 changed backend files | ✅ clean |
| **PHPStan L0** | ✅ **[OK] No errors** |
| **TypeScript** (`-p tsconfig.app.json`) | ✅ **24 errors = the documented baseline; 0 in files this task changed** |
| PHPStan core L6 · Pint · ESLint · Vite · `git diff --check` | ❌ not run |

### 18.1 A measurement error of mine, corrected

I first reported TypeScript as passing. It was not — I had written

```bash
npx tsc … | tail -12; echo "tsc exit=$?"
```

which captures **`tail`'s** exit status, never `tsc`'s. It printed `exit=0` regardless of the
result. Re-run without the pipeline:

```
tsc real exit = 2
error count   = 24
```

**24 is exactly the documented baseline** — the parallel Cost Management session recorded
`TypeScript: 24 before → 24 after` on this same tree. The errors are spread across fourteen
files (`manual-order-form` 6, `configuration-os-page` 4, marketing/engineering/hr/logistics/
business-accounts the rest) and are all i18n index-signature complaints of the same shape.

**Neither file this task changed appears in the list**: `product.ts` and
`product-browser.tsx` contribute **zero** errors. So this task introduced none, and the
`can_commit` field type-checks cleanly through both the type definition and its consumer.

## 19. Database Safety (PART 24)

`ecos_dev` **read-only**. No `POST`/`PUT`/`PATCH`/`DELETE`, no migration, no `migrate:fresh`,
no `truncate`, no fixtures, no fabricated inventory rows. The runtime proof in §17 is a
`SELECT`-only path. No migration was required by this task — every change is code-level.

## 20. Deployment Drift (PART 26)

```
ecos_dev migrations = 706
supersede_order_lifecycle_v3_canonical applied = NO
```

**The Orders page failure is unrelated to this task and remains open.** `ORD-00002` still
holds `status = 'new'`, which the post-ADR-042 enum cannot hydrate. Per PART 14 of this task
I did not touch that data. It clears with one command, which needs your authorisation:

```bash
docker exec ecos-dev-app php artisan migrate --force
```

## 21. Scope

**Changed (11 backend + 2 frontend):** `InventoryItem` · `AvailabilityState` ·
`ReserveStockAction` · `InventorySummaryService` · `EloquentProductRepository` ·
`ProductController` · `ProductResource` · `ManufacturingAvailabilityService` ·
`ReserveOrderInventoryAction` · `MaterialDemandCalculator` ·
`Core/DemandAnalysis/DemandAnalysisService` · `product.ts` · `product-browser.tsx`

**Not touched:** `DirectIssueStockAction`, stock ledger, returns/reversals, warehouse
liability, IAM, Distribution, order lifecycle, product media.

## 22. Known Issues

1. **PART 9 unimplemented** — order-driven RM reservation conflicts with ADR-027 §16.2 (§9.2).
2. **PART 22 test matrix not written.**
3. **PART 23 reservation scenarios not executed.**
4. **Preparation shortage figures change** (§15) — intended, but downstream reports move.
5. **Orders page still 500s** until the lifecycle migration runs (§20).
6. Static verification incomplete (§18).

## 23. Certification Verdict

# NOT CERTIFIED — RUNTIME E2E NOT EXECUTED

| Layer | Status |
|---|---|
| Signed Available arithmetic | ✅ implemented + proven read-only |
| Untracked vs tracked-zero | ✅ proven on RM-000001/2 |
| Allow Negative as permission | ✅ implemented at the canonical gate |
| Availability/orderability projection | ✅ proven, 7/7 cases |
| Order Creation source of truth | ✅ WooCommerce dependency removed |
| Recipe / FG availability | ✅ proven on FG-000001 |
| Reservation drives Available negative | ⚠️ **code path complete, runtime unproven** |
| Raw-material reservation cascade (PART 9) | ❌ **blocked on ADR-027 amendment** |
| Test matrix (PART 22) | ❌ not written |
| Full static verification | ⚠️ partial |
| Database safety | ✅ `ecos_dev` read-only throughout |

**No failure is hidden. No UI workaround. No invented values. No duplicate availability
engine. No data fabricated in `ecos_dev`.**

---

# ADDENDUM — TASK-INVENTORY-NEGATIVE-STOCK-ADR-027-AMENDMENT-001 (2026-08-13)

## A1. ADR-027 Amendment — the blocker is cleared

**ADR-027 is now v1.3** with a new **Section 17 — Order-Driven Raw Material Reservation**.

### A1.1 A correction to my own earlier citation

I told you §16.2 was the blocker. **It was not.** §16.2 says *"Direct Finished Product stock
remains independently reservable"* — a **protective** rule ensuring an order that can ship
from FG stock is never blocked by an unexecutable recipe. It is fully compatible with the new
contract and **survives unchanged** (recorded in §17.6).

The actual FG-only decision lived in three other places:

| Site | Text | Disposition |
|---|---|---|
| **§3** | *"Raw material availability is irrelevant at reservation time"* | raw-material clause **superseded** |
| **§11** | *"Raw Material reservations do not exist in the ECOS Orders reservation system"* | **superseded** |
| **§13 P04** | *"FG only — manufacturing responds"* | replaced by **P04-v1.3** |
| §14 P04 row | compliance check *"No RM query in ReserveOrderInventoryAction ✅ PASS"* | **inverted** — the query is now the compliant state |

### A1.2 Why the amendment is justified, not merely asserted

§11 gave a specific technical reason for FG-only:

> *"Multiple BOMs may apply; production scheduler selects. Orders cannot know."*

**That premise expired.** `Product::activeRecipe()` resolves exactly one recipe
deterministically — `->where('is_active', true)->ofMany('bom_version_number', 'max')` — and
`ManufacturingAvailabilityService` has been its single authority since v1.2 §16.3. The order
side can now derive its requirement without guessing, because the platform already picks the
BOM for it.

Sections 3, 11, 13 and 14 are **left unedited**; §3 and §11 carry inline supersession
pointers so neither can be read in isolation. The FG-only rule is preserved as the record of
a decision that was correct for its time.

## A2. FG + Raw Material Reservation Contract

| Tier | Reserved when | Authority |
|---|---|---|
| Finished Good | order line commits it (§3 Cases 1–4, unchanged) | Orders |
| **Raw Material** | **FG commitment requires it via the active Recipe/BOM** | **Orders (new)** |

Implemented in `ReconcileOrderMaterialReservationsAction`, written through the same
`ReserveStockAction` as every other reservation — canonical domain state, never UI arithmetic.
Requirements honour `yield_quantity`: a recipe producing 10 units from 5 KG requires 0.5 KG
per finished unit, not 5.

## A3. Reservation Reconciliation (PARTS 14–16)

The action **reconciles**; it never accumulates:

```
target > held → reserve (target − held)
target < held → release (held − target)
target = held → no-op
```

`held` is derived from the **canonical stock ledger** — reservation minus release entries
carrying `reference_type = 'sales_order_material'` and this order's id — rather than a new
tracking table. Both movements already write there, so there is no second source of truth to
drift.

This satisfies PART 14 (repeat processing converges), PART 15 (quantity change moves only the
delta) and PART 16 (release returns the commitment exactly once). Materials that a revised
order no longer requires are released explicitly, so a changed recipe cannot strand a
commitment.

**Ordering matters and is deliberate:** reconciliation runs *after* the finished-goods
decision, so §16.2 still holds. It is also non-fatal — a material blocked by
`allow_negative_stock = OFF` is reported in the result rather than thrown, because letting
inventory code rewrite order status would violate §4.

## A4. Verification performed

| Check | Result |
|---|---|
| `php -l` (both files) | ✅ clean |
| **PHPStan L0** | ✅ **[OK] No errors** |
| Pint | ✅ applied |
| Container DI graph resolves | ✅ both actions resolve; `REFERENCE_TYPE = sales_order_material` |
| Host ↔ container parity | ✅ verified per file |

## A5. NOT done — and therefore not certified

| PART | Item | Status |
|---|---|---|
| 19 | Test matrix A–R (18 cases) | ❌ not written |
| 20 | Runtime E2E on `ecos_dev_test` | ❌ not executed |
| 21 | PHPStan L6 · ESLint · Vite · `git diff --check` | ❌ not run |
| — | Regression | ❌ not run |

The reconciliation logic is verified by construction, static analysis and DI resolution —
**not by an executed test**. In particular, the headline scenario (RM on-hand 0, Allow
Negative ON, order 1 × FG requiring 5 → `Reserved = 5`, `Available = −5`) has **not** been
observed at runtime.

## A6. Database Safety

`ecos_dev` untouched: no migration, no `migrate:fresh`, no truncate, no data mutation.
**ORD-00002 was not altered** (PART 23 honoured). No migration was required — the amendment is
ADR + code only; the ledger already carries `reference_type`/`reference_id`.

One process note: the amendment was first appended to the wrong path because the shell cwd was
`backend/`, creating a stray `backend/docs/adr/`. Caught immediately by a line-count check,
the content was moved to the real ADR and the stray removed. The canonical ADR went 603 → 708
lines with Section 17 present exactly once.

## A7. Final Certification Verdict

# NOT CERTIFIED — RUNTIME E2E NOT EXECUTED

**The architectural blocker is cleared.** ADR-027 §17 authorises order-driven raw-material
reservation, the implementation exists and reconciles correctly by construction, and static
verification is clean.

**What remains is proof.** PART 24 requires reservation, availability, recipe, finished
product, order creation, preparation, multi-warehouse, idempotency and release all proven at
runtime. None of that has been executed, so certification is not claimed.

Next step is the PART 19 matrix on `ecos_dev_test`, starting with the idempotency case — a
reservation that accumulates on retry is the single most damaging way this could fail.

---

# ADDENDUM 2 — Runtime Certification Attempt (2026-08-13)

## B0. Execution attempt — a real defect found, then the gate held

The runner freed briefly and the suite was launched. It did **not** produce results, and the
two reasons are worth recording separately.

**1. A genuine defect in my own test file, caught by execution.**

```
PHP Fatal error: Access level to OrderDrivenMaterialReservationTest::component()
must be protected (as in class Illuminate\Foundation\Testing\TestCase) or weaker
```

`component()` is a protected method on Laravel's `TestCase` (Blade component testing), and I
declared a `private` helper with the same name — illegal in PHP. The existing
`RecipeToOrderAvailabilityE2ETest` had named its equivalent `addComponent` for exactly this
reason; I did not follow the local convention. Renamed to `addComponent`, `php -l` clean.

This is the second time in this task that running something proved what reading it could not:
`php -l`, PHPStan L0 and L6 and Pint **all passed** on a file PHP refuses to load. Static
analysis does not check inherited method visibility.

**2. The gate then held, correctly.**

On re-run the occupancy gate aborted:

```
ABORT: ecos_dev_test is in use by another process. Not competing.
```

The other agent had reclaimed the runner. **No process was killed, no test database was
touched, nothing was forced.** That is the behaviour that was missing earlier in this
programme when I dropped a foreign session's tables; the script now makes it automatic.

## B1. Environment Gate — **BLOCKED**

```
PHP_TEST_PROCESSES = 2
BUSY 10611 :: php vendor/bin/phpunit tests/Feature/Operations/WavePostponeOrderTest.php --testdox
```

`ecos_dev_test` is owned by **another agent's session**. Per the task's own instruction —
*"إذا كان ecos_dev_test مشغولًا: NOT CERTIFIED — RUNTIME BLOCKED … ولا تحاول إصلاح قاعدة
الاختبار أثناء وجود Agent آخر عليها"* — **no PHPUnit was run, no process was killed, and the
test database was not touched.**

This was re-checked three times over the course of the work; it remained busy throughout.

Earlier in this programme I dropped the test database underneath a foreign run by not gating a
destructive reset on its own occupancy check. That will not be repeated.

## B2. The 18-case matrix — **WRITTEN, NOT RUN**

`tests/Feature/Inventory/OrderDrivenMaterialReservationTest.php` — 18 cases, numbered to the
brief. `php -l` clean, Pint passes.

| # | Case | Assertion | Result |
|---|---|---|---|
| 1 | **Idempotency** | 3× reconcile → `Reserved` stays 5, **ledger entry count unchanged** | ⏸ not run |
| 2 | ON, on-hand 0 | `Reserved 5`, `Available −5` | ⏸ |
| 3 | OFF, on-hand 0 | nothing reserved, `blocked[]` reported, **order status untouched** | ⏸ |
| 4 | positive stock | on-hand 10, `Reserved 5`, `Available 5` | ⏸ |
| 5 | reserved > on-hand | on-hand 5, `Reserved 8`, `Available −3` | ⏸ |
| 6 | revised down 8→5 | releases delta 3 only | ⏸ |
| 7 | revised up 5→8 | reserves delta 3 only | ⏸ |
| 8 | material dropped | full release, no orphan | ⏸ |
| 9 | two materials | each reconciles independently | ⏸ |
| 10 | `yield_quantity` | 5 KG → 10 FG, order 1 ⇒ **0.5 KG** | ⏸ |
| 11 | multi-warehouse | Σ signed = −5 (clamp-per-warehouse would give 10) | ⏸ |
| 12 | signed available | negative survives summary + projection | ⏸ |
| 13 | shortage semantics | `missing = 10`, never negative | ⏸ |
| 14 | **FG protection §16.2** | FG in stock reserves despite blocked material | ⏸ |
| 15 | order-driven | commitment written from the order path, ledger-referenced | ⏸ |
| 16 | failure safety | permitted material reserves, blocked one does not; no partial | ⏸ |
| 17 | convergence | 5× reconcile → target 6, not 30 | ⏸ |
| 18 | FG regression | FG reservation unchanged | ⏸ |

Case 1 asserts on **ledger-entry count as well as quantity**, deliberately: a converging
quantity could still conceal duplicated movements, and duplicated movements would corrupt the
`held` figure that idempotency itself depends on.

Every quantity assertion reads the **persisted `inventory_items` row**, never a return value —
the original defect was a commitment that existed only in memory.

## B3. Static Verification — complete and clean

| Check | Result |
|---|---|
| `php -l` (all changed + new files) | ✅ clean |
| **PHPStan L0** | ✅ **[OK] No errors** |
| **PHPStan core L6** | ✅ **[OK] No errors** |
| **Pint** | ✅ passes (new suite + both actions) |
| **TypeScript** | ✅ 24 errors = documented baseline; **0 in files this task changed** |
| **`git diff --check`** | ✅ clean |
| Container DI graph | ✅ both actions resolve |
| **ESLint** | ✅ **0 errors** (1 pre-existing warning in `manual-order-form.tsx:1697`, present at HEAD, outside this task's diff) |
| **Vite build** | ✅ **built in 11.07s** |

## B4. Failure Classification

| Class | Count | Detail |
|---|---|---|
| **NEW** | 0 | nothing executed, so nothing attributable |
| **PRE-EXISTING** | 24 | TypeScript baseline, none in this task's files |
| **ENVIRONMENTAL** | 1 | `ecos_dev_test` owned by a foreign session — the certification blocker |
| **FIXED DURING THIS TASK** | 1 | `component()` visibility collision in the new suite (B0) — my defect, found by execution, not by static analysis |

## B5. Certification Verdict

# NOT CERTIFIED — RUNTIME BLOCKED

**Not a code failure.** The architecture is approved (ADR-027 v1.3 §17), the implementation is
complete and reconciling, static verification is fully clean, and the 18-case matrix is written
and lint-clean. The single missing element is execution, and it is unavailable because another
agent owns the test database.

**Explicitly not claimed:** `code path verified` is not certification. No case in B2 has been
observed at runtime, including the headline scenario (on-hand 0, Allow Negative ON, order 1×FG
requiring 5 ⇒ `Reserved 5`, `Available −5`).

**To certify, on a free runner:**

```bash
bash scratchpad/gated_run.sh tests/Feature/Inventory/OrderDrivenMaterialReservationTest.php
```

then the Inventory, Reservation and Order regression suites. The gating script aborts rather
than competing.

`ecos_dev` untouched · ORD-00002 unaltered · no `migrate:fresh` · no direct SQL · no process
killed · no fixtures outside `ecos_dev_test`.
