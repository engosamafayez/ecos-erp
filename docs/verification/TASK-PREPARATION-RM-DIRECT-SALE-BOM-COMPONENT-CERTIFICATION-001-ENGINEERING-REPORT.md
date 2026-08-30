# TASK-PREPARATION-RM-DIRECT-SALE-BOM-COMPONENT-CERTIFICATION-001 — Engineering Report

**Date:** 2026-08-10
**Branch:** `develop` · **HEAD:** `6149875b`
**Runtime:** `ecos-dev-testrunner` → MySQL `ecos_dev_test` · PHP 8.4.24 · PHPUnit 11.5.55
**Verdict:** **B — the engine reports 15 available and IGNORES the competing reservation.**

---

## 0 — Disclosure: the first run was INVALID and was discarded

The first execution of this certification returned `available = 7`, `missing = 3` — i.e. **outcome A**,
"the engine already honours the competing reservation."

**That result was false.** It was produced by contaminated production code inside the test container,
not by `HEAD`. It is recorded here in full, and discarded.

The cancelled `TASK-PREPARATION-MATERIAL-DEMAND-CALCULATION-REPAIR-001` had implemented
`$available = max(0.0, $onHand - $reserved)` in `MaterialDemandCalculator`. That edit was reverted **on
the host worktree** when the task was cancelled. It was **not** reverted inside `ecos-dev-testrunner`,
which holds its own copy of the source tree. Six and a half minutes of green test output were therefore
measuring the very change this task was forbidden to make.

Detection, audit and remediation are in §11. No conclusion in this report rests on the discarded run.

---

## 1 — The question this task had to answer

Can one Raw Material be, at the same time:

- a **BOM component** consumed by Preparation to build a finished good, and
- a **directly ordered and reserved product** on a separate customer order?

And if so — is that reservation the *same* demand Preparation is already planning (in which case
subtracting it would double-count), or is it **competing** demand (in which case the stock behind it
is not freely available to Preparation)?

This mattered because the disputed line in `MaterialDemandCalculator` justifies itself on the claim
that order-level reservations "should not affect manufacturing demand calculations."

---

## 2 — Environment and integrity baseline

| Check | Value |
|---|---|
| HEAD / branch | `6149875b` / `develop` |
| Tracked diff, start and end of task | `8 files changed, 306 insertions(+), 27 deletions(-)` — **unchanged** |
| `MaterialDemandCalculator` modified | **No** — host md5 `4c2903b8…` ≡ `HEAD` md5 `4c2903b8…` |
| Reservation / Inventory / BOM / Preparation services modified **by this task** | **No** |
| Test DB resolved by runner | `ecos_dev_test` |
| Files added by this task | 1 test + 1 report |

The tracked diff is byte-for-byte identical before and after this task. The 8 modified files are the
pre-existing, separately authorised F4 + Option B + entry-gate work.

**Precision on one of those 8.** `Modules/Manufacturing/BillsOfMaterials/Domain/Services/ManufacturingAvailabilityService.php`
*is* modified in the worktree, and it *is* a BOM-domain service. It carries the pre-existing F4
company-scoping change, was already modified before this task began, and was not touched here. It does
not confound this result: the demand pipeline exercised in this certification
(`DemandCalculationService` → `DemandProjectionBuilder` → `MaterialDemandCalculator`) never calls it.
It is cited in §12 only as the *contrasting* availability formula, read from source.

---

## 3 — Scenario design

One company. One brand. One warehouse. Two orders.

```
Company A
└── Brand A
    ├── Finished Product A ──BOM(1 per unit)──► Raw Material X
    └── Raw Material X          on_hand = 15

Order 1 (PREPARATION demand)   line = Finished Product A × 10   status in_progress   → wave
Order 2 (COMPETING demand)     line = Raw Material X    × 8     status ready_for_dispatch, reserved
```

Required for X = 10 units of A × 1 per unit = **10**.
Business-contract availability = 15 − 8 = **7** → shortage **3**.

---

## 4 — Fixture legitimacy: what was real, and what was seeded

The task forbade inserting reservation rows or demand rows directly. Nothing of the sort was done.

| Artefact | How it was produced |
|---|---|
| Products (X, A) | `Product::factory()` with explicit `brand_id` + `company_id` |
| Physical stock (15) | `InventoryItem` + matching `InventoryReceiptLayer` (FIFO layer), before any reservation |
| BOM | `bills_of_materials` + `bill_of_material_lines` — the tables the engine reads |
| Orders | Real `Order` + `OrderLine` models |
| **Reservation** | **`POST /api/fulfillment/orders/{id}/transition` → `ready_for_dispatch`** |
| Wave | **`POST /api/preparation/waves`** |
| Product demand | **`DemandCalculationService::recalculate()`** — the production entry point the wave listeners call |
| Material demand | **`DemandProjectionBuilder` → persisted `wave_material_demand`** |
| Result read back | **`GET /api/preparation/waves/{id}/material-demand`** |

`inventory_items.reserved_qty` was **never** written by the test. It was written by the real
reservation path, as a consequence of a real status transition.

---

## 5 — The competing demand is real

```
direct order              = 019feb56-ad1f-73ad-8e26-cb7210052465   line = X qty 8
order status              = ready_for_dispatch
order reservation_status  = reserved
X on_hand / reserved      = 15 / 8
```

Asserted: the order reached `ReservationStatus::Reserved` through the workflow; `reserved_qty` = 8;
`on_hand_qty` = 15 (reservation does not consume physical stock).

This also settles the prerequisite business question raised before this task: **a product typed
`raw_material` can be sold directly and does carry a real inventory reservation.** It is not a
theoretical case.

---

## 6 — The Preparation demand comes from a different order

```
preparation order         = 019feb56-ada5-7310-90a5-b2fb145092bb   line = A qty 10
BOM parent / component    = A / X  @ 1 per unit
wave product demand (A)   = 10
```

X enters the wave's demand **only** by BOM explosion of A. No order line in the wave names X.

---

## 7 — Core proof: the two demands are provably distinct

Asserted at runtime — these hold regardless of which availability rule is correct:

| Fact | Assertion |
|---|---|
| The reservation's order line product **is** X | `directLine.product_id === X` |
| The wave's order line product is **A, not X** | `prepLine.product_id === A` |
| X reaches Preparation only as a component | `bomLine.raw_material_id === X` |
| The two demands are different orders | `directOrder.id !== preparationOrder.id` |
| Both orders belong to the same company | `company_id` equal on both |
| **The reserving order is NOT in the wave** | `assertNotContains(directOrder.id, waveOrders)` |

The last row is decisive. The reserving order is not attached to the wave, so its 8 units are **not**
part of the 10 the wave is planning. There is no overlap and therefore **no double-count**.

This is reinforced structurally: per `TASK-GOLIVE-PREPARATION-ENTRY-GATE-REPAIR-002`,
`ready_for_dispatch` is a post-Preparation state that the entry gate refuses. The reserving order is
not merely absent from this wave — it is **ineligible** for any wave.

---

## 8 — Engine result (unmodified production code)

Definitive run. The md5 of the committed test file and of the calculator under test were printed by
the **same command** that executed the suite, so provenance and result are one atomic piece of evidence:

```
ecd8bea844276cc994265b9f8b625d77  tests/.../RawMaterialDirectSaleBomComponentTest.php   ← committed artifact
4c2903b8fc751d05755b6fb8cdfa3546  Modules/.../MaterialDemandCalculator.php             ← ≡ HEAD

required_qty  (X)           = 10
available_qty (X)           = 15     ← physical on-hand, reservation not subtracted
reserved_qty  (X, reported) = 8      ← the engine SEES the reservation
missing_qty   (X)           = 0      ← no shortage reported
api available / missing     = 15 / 0

OUTCOME: B — engine reports 15 available; competing reservation IGNORED
OK (2 tests, 33 assertions)
```

Reproduced identically across two independent clean-code runs with distinct fixtures
(entity UUIDs `019feb56-…` and `019feb6a-…`), ruling out cached or stale state.

The engine **reads** `reserved_qty`, **stores** it on the row (8), **publishes** it through the read
model — and then excludes it from `available_qty`. This is not a case of missing data. The reservation
is visible to the calculation and deliberately not applied.

Business contract expects `available = 7`, `missing = 3`. The engine reports `15` and `0`.

**A wave planning 10 units of X against 15 on-hand will be told it has no shortage, when 8 of those
15 are already committed to another customer's order. Only 7 are actually obtainable.**

---

## 9 — Control: no competing reservation

```
on_hand = 15, reserved = 0, required = 10  →  available = 15, missing = 0
```

Asserted hard (both candidate rules agree when `reserved = 0`), and it passes. This proves the
15/0 result in §8 is caused specifically by the reservation being ignored — not by a broken fixture,
a missing inventory row, or a warehouse mismatch.

---

## 10 — Independent corroboration from the pre-existing suite

`MaterialDemandCalculatorTest` at `HEAD`, run on the decontaminated container:

```
✘ Missing qty uses available not on hand
  Failed asserting that 15.0 matches expected 7.0   (MaterialDemandCalculatorTest.php:153)
```

The repository's own pre-existing test encodes the business contract (`available = 7`) and fails
against current production code with exactly the value this certification observed. Two independent
fixtures, same number.

Whole-suite context — `tests/Feature/Operations/DemandEngine/` on the decontaminated tree:

```
Tests: 36, Assertions: 102, Failures: 1
```

That single failure is the one above. Every other Demand Engine test passes, including this task's two
new tests. The defect is narrow and specific: it is the availability formula, not the demand engine.

**Additional observation, provenance disclosed.** Before decontamination the container also held a
5-case reserved-matrix test that was residue from the cancelled REPAIR-001 task (not present at `HEAD`,
not authorised, since removed). Its output is recorded here as observation only, because it maps the
behaviour precisely:

| Case | on_hand | reserved | engine available | contract available |
|---|---|---|---|---|
| A nothing reserved | — | 0 | **pass** | pass |
| B partially reserved | 10 | 3 | 10 | 7 |
| C over reserved | 10 | 12 | 10 | 0 |
| D short and reserved | 5 | 2 | 5 | 3 |
| E surplus | 50 | 5 | 50 | 45 |

`available === on_hand` in every case, including case C where the material is **over-reserved** and
genuinely has nothing free. This is a uniform rule, not an edge-case slip.

---

## 11 — The contamination incident

**Detection.** The first run returned `available = 7` — the business-contract answer. That directly
contradicted `MaterialDemandCalculator.php:116` (`$available = max(0.0, $onHand)`) and the known-failing
pre-existing test. A result that agrees with the desired outcome while contradicting the source is a
signal to verify the runtime, not to publish.

**Audit.** Manifest comparison, host worktree vs container:

| Tree | Files compared | Drifted |
|---|---|---|
| `Modules app config routes` | 4,212 | **1** — `MaterialDemandCalculator.php` |
| `tests` | 266 | 2 — `MaterialDemandCalculatorTest.php` (REPAIR-001 residue), `RawMaterial…Test.php` (own, expected) |

No files existed only in the container or only on the host.

> A first attempt at this audit reported "no drift" — a **false negative**. `md5sum` had emitted a
> binary-mode `*` prefix on host paths, so `join` matched 0 of 4,212 rows and the empty output looked
> like a clean result. Caught by checking the join count rather than trusting the empty diff. Corrected
> audit joins all 4,212 rows.

**Remediation.** Both files restored from the host worktree; md5 parity verified across container and
host for the calculator and both test files before the final run.

**Blast radius.** Exactly one production file drifted, and it is the one under examination. Prior
certifications in this session did not depend on `MaterialDemandCalculator`, and the REPAIR-001 edit
post-dates them. No earlier certification is retracted.

---

## 12 — The engine contradicts the platform's own availability contract

Every other implementation of "available" in the codebase subtracts reserved:

| Site | Formula |
|---|---|
| `ManufacturingAvailabilityService:58` — ADR-027 §16.3 **sole authority for material availability** | `SUM(GREATEST(on_hand_qty - reserved_qty, 0.0))` |
| `InventorySummaryService:22` / `InventoryItem::availableQty()` | `Σ max(on_hand − reserved, 0)` |
| `EloquentProductRepository:76`, `:250` | `GREATEST(on_hand_qty - reserved_qty, 0)` |
| `ProductController:275` | `SUM(GREATEST(on_hand_qty - reserved_qty, 0))` |
| `ProcessOrderWorkflow:219` | `(on_hand_qty - reserved_qty) > 0` |
| **`MaterialDemandCalculator:116`** | **`max(0.0, $onHand)`** |

`MaterialDemandCalculator` is the **only** outlier. ADR-027 §16.3 names
`ManufacturingAvailabilityService` the single authority on material availability; this calculator
answers the same question with a different formula.

ADR-027 §16.5 already models this exact entity — "Raw Material X" shared across recipes within one
Company — and treats it as a **Company-level pooled resource**. A pooled resource that is committed to
one consumer is not simultaneously available to another.

---

## 13 — Is this double-counting? No.

The concern that prompted the analysis-only pause was: orders in `new`, `in_progress`, `confirmed`,
`scheduled` may already be reserved, so subtracting `reserved` could subtract demand Preparation is
itself planning.

This scenario is immune to that concern, and shows the concern does not generalise:

- The reserved quantity belongs to an order that is **not in the wave** and is **ineligible** for any
  wave (`ready_for_dispatch`).
- The wave's own demand for X is **derived**, via BOM explosion of A. Reserving A would consume A's
  stock, not X's.
- Nothing reserved X on behalf of this wave. Preparation does not reserve components.

So for a component reached through BOM explosion, an order-level reservation on that component is
**always** competing demand. The double-count risk is real only where a wave's own order lines reserve
the *same product* the wave is planning — which is a different case, and is scoped out below.

---

## 14 — What this certification does NOT prove

Stated explicitly, so the boundary is not overread:

1. It does **not** certify a fix. No production code was changed.
2. It does **not** resolve the finished-good case: when a wave's own order lines reserve the *same*
   product being planned, a naive `on_hand − reserved` could under-report availability. This scenario
   deliberately isolates the **component** case, where no such overlap exists.
3. It does **not** establish the correct treatment of `expected_today` / `in_transit_qty`, which the
   engine currently hard-codes to `0.0`.
4. It does **not** re-certify any other Preparation scenario.

Point 2 is the one open design question a repair task must answer before changing line 116.

---

## 15 — Classification

| Attribute | Finding |
|---|---|
| Class | **Correctness defect — under-reported material shortage** |
| Introduced | Pre-existing at `HEAD 6149875b`; not caused by this task |
| Trigger | Any BOM component carrying an order reservation from outside the wave |
| Effect | Shortage reported as 0 when the true shortage is 3; wave planned against stock it cannot consume |
| Detectability in production | Low — the row *displays* `reserved_qty = 8`, so the data looks complete |
| Severity | High for planning integrity; no data loss, no corruption |
| Fix authorised here | **No** |

---

## 16 — VERDICT

> ### B — CURRENT ENGINE RETURNS 15 AVAILABLE
>
> The same Raw Material can be a BOM component and a directly-ordered, reserved product at the same
> time. Runtime-proven, via the real order, reservation, wave and demand pipeline.
>
> That reservation is **competing demand**, not the demand Preparation is planning — proven by the
> reserving order being a different, wave-ineligible order.
>
> The engine sees the reservation (`reserved_qty = 8` on its own output row) and excludes it from
> availability, reporting `available = 15`, `missing = 0` where the business contract requires
> `available = 7`, `missing = 3`.

---

## 17 — Artifacts and reproduction

**Added by this task (test + report only):**
- `backend/tests/Feature/Operations/DemandEngine/RawMaterialDirectSaleBomComponentTest.php`
- `docs/verification/TASK-PREPARATION-RM-DIRECT-SALE-BOM-COMPONENT-CERTIFICATION-001-ENGINEERING-REPORT.md`

**Reproduce:**
```bash
docker exec ecos-dev-testrunner sh -lc \
  'cd /var/www/html && php -d memory_limit=2G \
   vendor/bin/phpunit tests/Feature/Operations/DemandEngine/ --testdox'
```

**Before trusting any run, verify the container is not drifted:**
```bash
docker exec ecos-dev-testrunner md5sum \
  /var/www/html/Modules/Operations/DemandAnalysis/Application/Services/MaterialDemandCalculator.php
# must equal HEAD: 4c2903b8fc751d05755b6fb8cdfa3546
```

---

## 18 — Attestations

- No production code was modified. Tracked diff identical before and after: `8 files, 306+, 27−`.
- `MaterialDemandCalculator`, Reservation, Inventory, BOM, Preparation and Order services: **untouched by
  this task**. `ManufacturingAvailabilityService` was already modified by the prior authorised F4 work
  and is part of the unchanged 8-file baseline — see the precision note in §2.
- No existing test expectation was changed. The pre-existing failure is reported as found.
- No reservation row and no demand row was inserted directly. Both came from real workflows.
- The certification test asserts only facts true under **both** candidate rules; it cannot pre-decide
  the answer. The disputed values are recorded observationally.
- The first run was invalid, is disclosed in §0/§11, and supports no conclusion here.
- Pint passes on the added test file.
- The answer was **not inferred**. It was read from the engine's persisted output and its HTTP read
  model, and independently corroborated by the pre-existing suite.

**Per the task instruction, work stops here.** The `MaterialDemandCalculator` repair is **not**
authorised by this task and has not been started. IAM and Shipping have not been started.
