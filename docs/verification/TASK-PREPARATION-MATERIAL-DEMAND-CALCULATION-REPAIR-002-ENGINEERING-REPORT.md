# TASK-PREPARATION-MATERIAL-DEMAND-CALCULATION-REPAIR-002 — Engineering Report

**Date:** 2026-08-11
**Branch:** `develop` · **HEAD:** `6149875bd8a01820116b5deacbbfb8ef0e51cc05`
**Runtime:** `ecos-dev-testrunner` → MySQL `ecos_dev_test` · PHP 8.4.24 · PHPUnit 11.5.55
**Verdict:** **MATERIAL DEMAND CALCULATOR = CERTIFIED**

---

## 1 — Executive Summary

`MaterialDemandCalculator` computed raw-material availability as physical on-hand alone, ignoring
`reserved_qty`. Stock already committed to other orders was counted as available to a Preparation
wave, so shortages were under-reported — a wave could be planned against stock it can never consume.

The business contract was settled by two preceding runtime certifications, so this task applied the
repair:

```php
- $available = max(0.0, $onHand);
+ $available = max(0.0, $onHand - $reserved);
```

One expression. One production file. No schema change, no new service, no change to reservation,
F4, Option B, the Preparation entry gate, or demand aggregation.

The defect reproduced at baseline (6 failures), the repair resolved all of them, and the required
success criterion now holds through the real production pipeline:

| | required | on_hand | reserved | available | missing |
|---|---|---|---|---|---|
| **BEFORE** | 10 | 15 | 8 | **15** | **0** |
| **AFTER** | 10 | 15 | 8 | **7** | **3** |

Preparation regression is clean at the certified count (47 tests, 0 failures), the entry gate is
13/13, and F4 / Option B are untouched and green. Three reservation-suite failures were found and
**proven pre-existing with a HEAD control** — they fail identically with the calculator reverted to
the HEAD baseline.

---

## 2 — Starting Commit

| Item | Value |
|---|---|
| HEAD | `6149875bd8a01820116b5deacbbfb8ef0e51cc05` |
| Branch | `develop` |
| Tracked diff **before** | `8 files changed, 306 insertions(+), 27 deletions(-)` |
| Tracked diff **after** | `9 files changed, 326 insertions(+), 31 deletions(-)` |
| Delta | `MaterialDemandCalculator.php` only (+20 / −4, of which the logic is 1 line) |
| Containers | DEV `ecos-dev-*`; MAIN `ecos-*` running, untouched |
| Host PHP processes | 0 |
| Other agents owning these files | none |

The pre-existing 8 modified files are prior authorised F4 / Option B / entry-gate / test-infra work.
No artifact from a previous certification task was deleted, reset or reformatted.

---

## 3 — Source Parity / Container Hash

Mandatory precondition, verified three ways **before** any runtime evidence was trusted:

```
HEAD      : 4c2903b8fc751d05755b6fb8cdfa3546
host      : 4c2903b8fc751d05755b6fb8cdfa3546
container : 4c2903b8fc751d05755b6fb8cdfa3546
PARITY: OK
```

This matches the hash recorded by the previous certification, confirming the container had not
drifted. After the repair, parity was re-verified at the new hash `ce69612a5910ad7eb84c354895b45140`,
and re-verified again after the HEAD control run restored it.

**Part 19 — database safety**, verified inside the runner:

```
config database   = ecos_dev_test
SELECT DATABASE() = ecos_dev_test
PART 19: OK
```

The certified `DevTestEnvironmentSmokeTest` additionally asserts config, live connection and
server-side database all equal `ecos_dev_test`, and that `ecos_erp` is unreachable. `ecos_dev`,
`ecos_erp` and `ecos_erp_test` were never targeted.

---

## 4 — Baseline Reproduction

Executed **before** the source change:

```
Tests: 50, Assertions: 156, Failures: 6
```

All six failures are the same defect:

| Failing case | expected | actual |
|---|---|---|
| `missing_qty_uses_available_not_on_hand` (pre-existing test) | 7.0 | **15.0** |
| CASE B partially reserved | 7.0 | 10.0 |
| CASE C over reserved | 0.0 | 10.0 |
| CASE D short and reserved | 3.0 | 5.0 |
| availability is never negative | 0.0 | 10.0 |
| each material evaluated independently | 7.0 | 10.0 |

Baseline reproduced exactly as specified. Two checks already passed pre-repair and were expected to
be invariant: **demand aggregation** and **expected_today / in_transit**.

---

## 5 — Root Cause

`MaterialDemandCalculator.php:116` deliberately discarded `reserved_qty`:

```php
// Use physical on-hand stock for deterministic demand planning.
// Order-level soft reservations are volatile (change with order status transitions)
// and should not affect manufacturing demand calculations.
$available = max(0.0, $onHand);
```

`$reserved` was read from the query on the line above and used only for reporting — it was written
onto the output row and published through the read model, but excluded from the arithmetic. The
defect was therefore invisible in the UI: the row *displayed* `reserved_qty = 8` while reporting
`available = 15`.

The stated rationale ("soft reservations are volatile") does not hold. Reserved stock is committed to
other orders and cannot be consumed by the wave, so counting it as available understates shortage.

---

## 6 — Business Contract

```
available_qty = max(on_hand_qty - reserved_qty, 0)
missing_qty   = max(required_qty - available_qty, 0)
```

Runtime-proven by the two preceding certifications:

1. **TASK-PREPARATION-RM-DIRECT-SALE-BOM-COMPONENT-CERTIFICATION-001** — a raw material can be both a
   BOM component and directly ordered; the direct order's reservation is **competing** demand, and the
   engine ignored it.
2. **TASK-PREPARATION-FG-OWN-RESERVATION-DEMAND-CERTIFICATION-001** — the calculator never reads
   finished-good stock (it aggregates by `boml.raw_material_id`), and reserving a finished good never
   reserves its components. So a component's `reserved_qty` is *always* competing demand and can never
   double-count the wave's own material demand.

---

## 7 — Production Change

One file, one expression:

```php
- $available = max(0.0, $onHand);
+ $available = max(0.0, $onHand - $reserved);
```

Diff excluding comments:

```
-            $available = max(0.0, $onHand);
+            $available = max(0.0, $onHand - $reserved);
```

The surrounding comment was replaced because it documented the now-incorrect rationale; it now cites
the authoritative contract and the two certifications. `$missing`, `$coveragePct`, precision
(`round(..., 4)`) and all existing types and conventions are unchanged — `$missing` and coverage
derive from `$available` and follow automatically.

No second availability service was introduced. No rule was duplicated from another module.

---

## 8 — Before / After Runtime Evidence

Through the real production pipeline (real order → real reservation transition → real wave route →
`DemandCalculationService` → `DemandProjectionBuilder` → persisted `wave_material_demand` → real HTTP
read model):

**BEFORE** (calculator `4c2903b8…`)

```
required_qty  = 10
on_hand       = 15
reserved      = 8
available_qty = 15
missing_qty   = 0
```

**AFTER** (calculator `ce69612a…`)

```
required_qty  = 10
on_hand       = 15
reserved      = 8
available_qty = 7
missing_qty   = 3
```

The required success criterion is met.

---

## 9 — Standard Cases

All four specified cases pass post-repair, plus the zero-floor case:

| Case | on_hand | reserved | required | available | missing | result |
|---|---|---|---|---|---|---|
| A nothing reserved | 10 | 0 | 10 | 10 | 0 | PASS |
| B partially reserved | 10 | 3 | 10 | 7 | 3 | PASS |
| C over reserved | 10 | 15 | 10 | 0 | 10 | PASS |
| D short and reserved | 5 | 2 | 10 | 3 | 7 | PASS |
| Zero floor (extreme) | 10 | 999 | 10 | 0 | 10 | PASS |

**Part 5 — the floor holds.** Availability never goes negative; coverage collapses to 0%, and missing
equals the full requirement.

---

## 10 — Direct RM + BOM Component (Part 7)

`RawMaterialDirectSaleBomComponentTest` remains valid and its **business expectation was not
rewritten**. It asserts only facts true under either candidate rule, and records the disputed values
observationally — so it passed both before and after, while the recorded values moved from
`15 / 0` to `7 / 3`.

Because that test computes its own classification line from the runtime result, the *unmodified*
artifact reports the change in its own words:

```
BEFORE:  OUTCOME: B — engine reports 15 available; competing reservation IGNORED
AFTER:   OUTCOME: A — engine ALREADY honours the competing reservation
```

Full post-repair evidence block, real pipeline end to end:

```
X on_hand / reserved        = 15 / 8
wave product demand (A)     = 10
required_qty  (X)           = 10
available_qty (X)           = 7
reserved_qty  (X, reported) = 8
missing_qty   (X)           = 3
api available / missing     = 7 / 3
```

The `api` line is the persisted row read back through the real HTTP read model, confirming the
corrected value reaches consumers and not merely the in-memory calculation.

Its control also still holds: with `reserved = 0`, `on_hand 15 / required 10` → `available 15,
missing 0` — unchanged by the repair, as it must be.

The pre-existing `MaterialDemandCalculatorTest::test_missing_qty_uses_available_not_on_hand`, which
*does* encode the business expectation, now passes — **because production changed, not because the
expectation moved.** Its expected value was never edited.

---

## 11 — Same-Wave Direct Component (Part 8)

```
X on_hand / reserved          = 20 / 8      ← reserved by an order INSIDE the wave
X as PRODUCT demand  (ship)   = 8
X as MATERIAL demand (build)  = 10
total true need               = 18
engine available / missing    = 12 / 0      ← was 20 / 0 before the repair
```

Exactly as specified: available 12, material requirement 10, missing 0.

This confirms the reservation is competing demand and is not duplicated. The reserved 8 covers X's
**product** demand (shipped as-is); the material demand of 10 is for *building* A and is not reserved
by anything. Two distinct demands totalling 18 of 20 — not one demand counted twice.

---

## 12 — Finished Good Safety (Part 9)

`FinishedGoodOwnReservationDemandTest` — 3/3 green post-repair.

The architectural behaviour is preserved: the calculator does **not** read finished-product inventory
for material demand. It aggregates by `boml.raw_material_id`, and with the finished good at
`on_hand 10 / reserved 10` it still produces **no row** for the finished good. A finished-product
reservation never becomes a raw-material reservation (component `reserved` stayed `0 → 0`).

**Part 11 of the task spec is satisfied: finished-product behaviour did not change.**

---

## 13 — Multiple Materials (Part 10)

| Material | required | on_hand | reserved | available | missing |
|---|---|---|---|---|---|
| RM A | 10 | 10 | 3 | 7 | 3 |
| RM B | 5 | 8 | 0 | 8 | 0 |

No material inherits another's reservation. RM B retains its full stock despite RM A being reserved.

---

## 14 — Demand Aggregation (Part 11)

Unchanged, and asserted explicitly:

```
Order A demand = 3, Order B demand = 2  →  total material demand = 5, single aggregated row
```

This test passed **before and after** the repair, confirming only the availability arithmetic moved.

---

## 15 — Preparation Regression (Part 12)

Standalone Preparation-owned suite, post-repair:

```
OK (47 tests, 188 assertions)
```

**Test count reproduces the certified 47 exactly, with 0 failures.**

| Suite | tests | failures |
|---|---|---|
| Preparation Bypass Guard | 4 | 0 |
| Preparation Entry Gate | 13 | 0 |
| Preparation Lifecycle E2E | 3 | 0 |
| Preparation Session Lifecycle | 7 | 0 |
| Preparation Wave Actions | 14 | 0 |
| Wave Preparation Transition | 6 | 0 |
| **Total** | **47** | **0** |

Assertions measure 188 against the 171 previously recorded for the same 47 tests. The most likely
cause is that `PreparationEntryGateTest` gained assertions during
`TASK-GOLIVE-PREPARATION-ENTRY-GATE-REPAIR-002` earlier in this session — the same 13 tests now probe
more per test. Flagged rather than asserted: it was not independently re-derived. The
regression-relevant facts — identical test count, zero failures, no test lost — are unambiguous.

Lifecycle evidence still green end to end:

```
PARTIAL:   required=10 prepared=6  short=4  status=short
COMPLETED: required=10 prepared=10 short=0  status=prepared
WAVE COMPLETE http=200   WAVE STATUS=completed
PREPARED POOL HANDOFF: rows=1 qty=10
```

**No NEW regression.**

---

## 16 — Entry Gate (Part 13)

**13/13 PASS.** Not modified. Runtime probes:

| Status | Result | Expected |
|---|---|---|
| `new` | http 201, attached=1 | ALLOW |
| `in_progress` | http 201, attached=1 | ALLOW |
| `confirm` (V3 mapping, `confirmed_at` set) | http 201, attached=1 | ALLOW |
| `in_progress` (unreserved) | http 201, attached=1 | ALLOW |
| `awaiting_stock` | http 422, attached=0 | REFUSE |
| `ready_for_dispatch` | http 422, attached=0 | REFUSE |
| `out_for_delivery` | http 422, attached=0 | REFUSE |
| `delivered` | http 422, attached=0 | REFUSE |
| `cancelled` | http 422, attached=0 | REFUSE |
| cross-company (`in_progress`) | http 422, attached=0 | REFUSE |
| `recalculate(awaiting_stock)` | http 422, attached=0 | REFUSE |
| duplicate entry | http 422, attached=1 (1 row) | REFUSE |

Policy line confirmed: `ENTRY GATE POLICY: new, in_progress`.

---

## 17 — Reservation Regression (Part 14)

Not redesigned, not modified. Green:

- `Reserve action stamps timestamp and decrements available qty` ✔
- `Cancellation releases reservation and stamps released at` ✔
- `Release idempotency` / `Release without prior reservation` ✔
- `Completion ships inventory` / `Ship idempotency` / `Ship throws when not reserved` ✔
- `Inventory status query reflects lifecycle state` ✔
- Soft Reservation 5/5 ✔ · Inventory Reservation 10/10 ✔

`new → in_progress creates a reservation when a warehouse is assigned` was runtime-proven in the FG
certification (`reservation_status = reserved`, `reserved_qty = 10`).
`in_progress → scheduled retains the reservation without duplicating it` was runtime-proven in the
earlier reservation audit (AUDIT B2).

**Three pre-existing failures — proven, not assumed:**

| Test | with repair | at HEAD baseline |
|---|---|---|
| `manufacturing_product_with_hard_rm_shortage_still_resolves_to_reserved` | ✘ | ✘ |
| `reserve_idempotency_throws_already_reserved_exception` | ✘ | ✘ |
| `reserve_throws_on_insufficient_stock` | ✘ | ✘ |

HEAD control: the container's `MaterialDemandCalculator` was reverted to `4c2903b8…` and the suites
re-run — the same 3 failures occurred with identical messages (`Tests: 16, Assertions: 44, Failures: 3`).
The repaired file was then restored and parity re-verified at `ce69612a…`.

Corroborated structurally: `MaterialDemandCalculator` has exactly one consumer,
`DemandProjectionBuilder`, and `ReserveOrderInventoryAction` references it **0** times. It is not in
the reservation call path and cannot affect these tests.

**Classification: PRE-EXISTING. Not caused by this task, and not repaired here (out of scope).**

---

## 18 — F4 (Parts 15, 16)

`ManufacturingAvailabilityService`, `EloquentProductRepository`, `ReserveOrderInventoryAction` — **not
modified**, verified byte-identical between host and container:

| File | md5 |
|---|---|
| `ReserveOrderInventoryAction.php` | `670ba67a…` |
| `ManufacturingAvailabilityService.php` | `14701fd3…` |
| `EloquentProductRepository.php` | `d7e4753c…` |

`RecipeGateTenantRepair` **10/10** green, including:

- Other company stock cannot satisfy this company's recipe → isolated
- Reverse direction → isolated
- Own company stock makes recipe executable → **instock**
- Fail-closed when finished good has no company → **outofstock**
- Direct finished-good stock bypasses the recipe gate → reservation proceeds
- Unexecutable recipe blocks manufacturing reservation
- `allow_negative_material` keeps reservation alive
- Missing recipe does not block reservation
- Cross-brand reuse survives company scoping

`RecipeCrossBrandReuse` **3/3** — one raw material serves recipes of two and three brands in one
company → **instock**.

`RecipeToOrderAvailabilityE2E` **8/8** — including `recipe outofstock off-negative component`,
`shortage permitted by allow negative`, `can-manufacture precedence`, and tenant isolation.

`InventoryAvailabilityEngine` **18/18**.

---

## 19 — Option B

Not modified and not weakened. Exercised live: in the FG certification the finished good has an
active BOM, so reservation passed through the recipe gate and succeeded (`reserved_qty = 10`),
demonstrating the gate is active. `Part15 unexecutable recipe blocks manufacturing reservation` and
`Part13 recipe missing does not block reservation` both pass. **No blocked recipe reservation.**

---

## 20 — Company Isolation (Part 16)

The calculator's isolation mechanism is warehouse scoping — it reads stock only from the wave's own
warehouse (`where('warehouse_id', $wave->warehouse_id)`, line 97), and a warehouse belongs to exactly
one company. The repair did not widen that scope.

Directly asserted: a material with **100 units in another company's warehouse** and none in the
wave's warehouse yields `available = 0`, `missing = 10` — foreign stock is invisible, and the output
row is stamped with the wave's own `company_id`.

Corroborated at the order layer: cross-company order → wave returns **422, attached=0**.

**No new ownership model was introduced.**

---

## 21 — Negative Stock (Part 17)

`allow_negative_stock` is referenced **0** times in `MaterialDemandCalculator`, before and after. The
policy is not duplicated and no second rule was invented.

No contradiction with the authoritative service. `ManufacturingAvailabilityService` toggles between
two clamp shapes via `inventory_ledger.canonical_summary`:

```
ON  : SUM(GREATEST(on_hand_qty - reserved_qty, 0.0))     clamp-per-warehouse-then-sum
OFF : GREATEST(SUM(on_hand_qty) - SUM(reserved_qty), 0)  sum-then-clamp
```

`MaterialDemandCalculator` is scoped to a **single warehouse**, so both shapes reduce to the identical
`max(on_hand − reserved, 0)` for one row. The repair therefore agrees with the authority under
**either** configuration branch.

---

## 22 — Expected Today / In Transit (Part 18)

Out of scope and unchanged. Both remain hard-coded `0.0`, asserted explicitly post-repair. The test
also asserts `reserved_qty` is still surfaced on the row (8), confirming reporting behaviour was not
altered — only the arithmetic.

---

## 23 — PHPStan (Part 20)

```
phpstan.neon.dist       (L0)   [OK] No errors
phpstan-core.neon.dist  (L6)   [OK] No errors
```

---

## 24 — Guardian / Pint (Part 21)

**Scoped Pint — PASS** on every file this task changed:

- `MaterialDemandCalculator.php`
- `MaterialAvailabilityContractTest.php`
- `RawMaterialDirectSaleBomComponentTest.php`
- `FinishedGoodOwnReservationDemandTest.php`

**Guardian — 8 of 9 validators PASS**: PHP Syntax, Laravel Bootstrap, PHPStan, ESLint, TypeScript,
Vite Production Build, Docker Build (Composer skipped). **Pint FAIL.**

The Pint failure is **pre-existing and unrelated**, proven with a HEAD control:

| File | fixer | in my diff? | fails at HEAD? |
|---|---|---|---|
| `tests/Feature/Inventory/ProductPopulationScopeTest.php` | `ordered_imports` | **No** | **Yes** |
| `tests/Feature/Operations/V3TransitionResolutionTest.php` | `binary_operator_spaces` | **No** | **Yes** |

Both are unmodified in the worktree (identical to HEAD) and already violate Pint at HEAD. Guardian
derives its file list from the push range `f0d7822a...HEAD`, which includes commit `6149875b` — the
commit that introduced them. This is the same pre-existing failure recorded by
`TASK-GOLIVE-RECIPE-GATE-TENANT-REPAIR-001`. Not fixed here: those files are outside this task's scope.

---

## 25 — Failure Classification

| Failure | Class | Evidence |
|---|---|---|
| 6 baseline availability failures | **REPAIRED** | all green post-repair |
| 3 reservation-suite failures | **PRE-EXISTING** | HEAD control reproduces identically |
| 2 Guardian Pint violations | **PRE-EXISTING** | files unmodified; fail at HEAD |
| NEW regressions | **NONE** | — |

---

## 26 — Files Changed

**Production (1):**
- `backend/Modules/Operations/DemandAnalysis/Application/Services/MaterialDemandCalculator.php`
  (+20 / −4; effective logic = 1 expression)

**Tests (1 added):**
- `backend/tests/Feature/Operations/DemandEngine/MaterialAvailabilityContractTest.php`

**Docs (1 added):** this report.

No existing test expectation was modified. No unrelated production file was touched.

---

## 27 — Schema Impact

**None.** No migration added or modified (0 migration files in the diff). No table, column, index or
reservation-schema change. Zero schema keywords (`Schema::`, `Blueprint`, `CREATE TABLE`,
`ALTER TABLE`, `addColumn`) in the production diff.

---

## 28 — Final Verdict

> ### MATERIAL DEMAND CALCULATOR = CERTIFIED
>
> `required = 10, on_hand = 15, reserved = 8` produces `available = 7, missing = 3` through the real
> production pipeline.
>
> All four standard cases pass, the zero floor holds, materials are independent, aggregation is
> unchanged, finished-product behaviour is unchanged, and company isolation holds.
>
> **Preparation Backend remains CERTIFIED** — 47/47 tests, entry gate 13/13, F4 and Option B untouched
> and green.
>
> Every failure observed outside the repaired scope was proven **pre-existing** with a HEAD control.
> **No NEW regression was introduced.**

**Scope honoured.** The formula was not generalised to finished-product availability, order
reservation, the reservation engine, inventory-wide availability, or the Preparation entry gate. No
second availability engine was created. Reservation, F4, Option B and the entry gate are unmodified.

**Out of scope and still open** (not started, per the final rule): `expected_today` and
`in_transit_qty` remain hard-coded `0.0`; the 3 pre-existing reservation failures and the 2
pre-existing Pint violations remain unrepaired.

**Work stops here.** IAM, Shipping, Logistics, Picking, Allocation, Driver, Vehicle, Packing and
Delivery were not started.
