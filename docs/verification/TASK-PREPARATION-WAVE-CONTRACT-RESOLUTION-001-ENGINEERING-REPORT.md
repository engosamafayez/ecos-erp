# TASK-PREPARATION-WAVE-CONTRACT-RESOLUTION-001 — Engineering Report

**Date:** 2026-08-14
**Type:** AUDIT / CONTRACT RESOLUTION ONLY
**Verdict:** **CONTRACT PARTIALLY RESOLVED — 4 OWNER DECISIONS REQUIRED**

**Production code changed:** none.
**Migrations written:** none.
**Schema changed:** none.
**Data modified:** none. All runtime evidence is from `SELECT` / `SHOW` only.

> The working tree was already dirty on arrival (uncommitted work from prior tasks —
> see `git status`). No file in it was touched by this audit.

---

## 1. Executive Summary

The previous report (`TASK-PREPARATION-WAVE-CROSS-DAY-TRANSITION-001`) stopped on eight
open questions. Seven are now **resolved from code and runtime**; four of those resolutions
surface a **business decision** that engineering must not make.

**What changed versus the previous report:**

| # | Previous claim | Status after this audit |
|---|---|---|
| 1 | `UNIQUE(company_id, order_id)` blocks carry-over | **CONFIRMED** — and it is not the only blocker; three application-level exclusivity checks enforce the same rule independently |
| 2 | Order leaves the eligible set at preparation START | **CONFIRMED** — and the contract is *deliberate and test-pinned*, not accidental |
| 3 | `orders.preparation_completed_at` writer never fires | **CONFIRMED, plus a second independent cause** the previous report missed — its trigger event never fires on the engine path at all |
| 4 | Old waves are never closed | **CONFIRMED, with the live cause identified**: `auto_move_to_preparing = 0` in the only active config + manual `advance` on a stale wave |
| 5 | Reservation-across-days provisional | **RESOLVED — SAFE.** Reservations are ORDER-scoped, never wave-scoped; the wave-cancel return path deliberately does not release them |
| 6 | Wave vs PreparationSession canonicity undeclared | **RESOLVED.** Wave is canonical for operations; Session has no mounted UI and only one live behaviour |
| 7 | Timezone divergence "latent, both UTC" | **CORRECTED — the divergence is ACTIVE.** `companies.timezone = 'Africa/Cairo'` for every company in the database; no Preparation code reads it |
| 8 | "No code resolves which wave an order is in" | **CORRECTED.** Three sites do a company-scoped `order_id` existence check and would break under historical membership |

**The single most important finding.** There *is* a canonical, operator-declared,
reversible "preparation finished" fact in the system — but it is **product-scoped, not
order-scoped**: `wave_product_demand.preparation_completed_at`
(`2026_08_14_100000_add_preparation_completed_at_to_wave_product_demand.php`), written by
`WaveDemandController::completePreparation()`. It is the only such marker with a live
writer, a live reader, a live UI, and live data. `orders.preparation_completed_at` is an
orphaned column: one dead writer, **zero readers anywhere in the codebase or frontend**.

**Consequence for the Cross-Day task:** the carry-over predicate cannot be derived
from an order-level completion fact, because none exists. Deriving one from the
product-level marker is possible but is a **business rule**, not a mechanical mapping —
see §16 and G-1.

---

## 2. Existing Wave Architecture

### 2.1 The engine

`RunWaveSchedulerCommand` runs `->everyMinute()->withoutOverlapping()`
([routes/console.php:58-61](backend/routes/console.php:58)). One iteration per active
`wave_engine_configurations` row.

| Step | Guard | Action | Code |
|---|---|---|---|
| 1 — open | `auto_create` ∧ `time ≥ collection_start_time` ∧ ¬`hasActiveWave(…, today)` | create Collecting wave for **today** | [RunWaveSchedulerCommand.php:69-80](backend/Modules/Operations/Preparation/Infrastructure/Console/Commands/RunWaveSchedulerCommand.php:69) |
| — | `getActiveWave(…, today) === null` → **`return`** | — | [:87-91](backend/Modules/Operations/Preparation/Infrastructure/Console/Commands/RunWaveSchedulerCommand.php:87) |
| 2 — collect | `auto_assign_orders` ∧ status ∈ {Collecting, Preparing} | `attachEligibleOrders` | [:93-102](backend/Modules/Operations/Preparation/Infrastructure/Console/Commands/RunWaveSchedulerCommand.php:93) |
| 3 — start | `auto_move_to_preparing` ∧ status = Collecting ∧ `time ≥ preparation_start_time` | Collecting → Preparing | [:108-115](backend/Modules/Operations/Preparation/Infrastructure/Console/Commands/RunWaveSchedulerCommand.php:108) |
| 4 — rotate | status = Preparing ∧ `time ≥ wave_end_time` | Preparing → Closed **+ create next-day wave** | [:118-124](backend/Modules/Operations/Preparation/Infrastructure/Console/Commands/RunWaveSchedulerCommand.php:118) |

Every step after Step 1 operates on `$activeWave`, which is **date-scoped to today**
([:87](backend/Modules/Operations/Preparation/Infrastructure/Console/Commands/RunWaveSchedulerCommand.php:87)).
This is the mechanical root of §12.

### 2.2 Two "active" definitions — reconciled

- `WaveStatus::isActive()` = {Draft, Collecting, Planning, ShortageBlocked, Preparing}
  ([WaveStatus.php:37-43](backend/Modules/Operations/Preparation/Domain/Enums/WaveStatus.php:37)).
- `WaveManager::ACTIVE_STATUSES` = {Collecting, Preparing}
  ([WaveManager.php:13-16](backend/Modules/Operations/Preparation/Application/Services/WaveEngine/WaveManager.php:13)).

They are **not in conflict** once read by purpose:

- `WaveManager` is the **engine's** definition — the two statuses an *engine* wave can
  hold. `wave_type='engine'` waves only ever traverse `Collecting → Preparing → Closed`.
- `WaveStatus::isActive()` is the **archive/workspace** definition — it must also cover
  the manual `wave_type='standard'` path (Draft → Planning → ShortageBlocked → Preparing),
  and it is used only by the list filter
  ([PreparationWaveController.php:101-104](backend/Modules/Operations/Preparation/Presentation/Http/Controllers/PreparationWaveController.php:101))
  and the wave picker
  ([wave-picker.tsx:29-35](frontend/src/features/operations/components/wave-picker.tsx:29)).

**Verdict: G6 is NOT a contract gap.** Two audiences, two correct predicates. What is
missing is a comment saying so.

### 2.3 The manual path is structurally unreachable for engine waves

Three actions guard on statuses an engine wave never holds:

| Action | Required status | Reachable for engine wave? |
|---|---|---|
| `GenerateDemandAction` | `Draft` ([:29-31](backend/Modules/Operations/Preparation/Application/Actions/GenerateDemandAction.php:29)) | **No** |
| `StartPreparationAction` | `Planning` ∨ `ShortageBlocked` ([:42-46](backend/Modules/Operations/Preparation/Application/Actions/StartPreparationAction.php:42)) | **No** |
| `RecalculateWaveAction` | `Draft` ∨ `Planning` ([:23-29](backend/Modules/Operations/Preparation/Application/Actions/RecalculateWaveAction.php:23)) | **No** |

Because `GenerateDemandAction` is the only writer of `preparation_wave_items`, an engine
wave has **zero** wave items. Runtime confirms: `SELECT COUNT(*) FROM
preparation_wave_items` → **0**, with 5 waves and 3 memberships present.

Downstream consequences, all confirmed:

- `CompleteProductAction` (the per-item `quantity_prepared` writer) is unreachable.
- `StartPreparationAction` is the only caller of `SoftReservationService::reserve()`
  ([:161](backend/Modules/Operations/Preparation/Application/Actions/StartPreparationAction.php:161)),
  so **no `PreparationInventoryReservation` is ever created on the engine path**.
  Runtime: `SELECT COUNT(*) FROM preparation_inventory_reservations` → **0**.
- `SoftReservationService::totalReserved()` has **no production reader** — only
  `tests/Feature/Operations/SoftReservationTest.php:137,166`.

`CompleteWaveAction` **is** reachable (`POST /preparation/waves/{id}/complete`, guard
`status === Preparing`, [CompleteWaveAction.php:40-42](backend/Modules/Operations/Preparation/Application/Actions/CompleteWaveAction.php:40)),
but with zero items it produces zero pool entries and zero prepared units. It is the only
path that fires `WaveCompleted`. This matters for §5.

---

## 3. Existing Order Preparation Lifecycle

The real, executed chain:

```
Order (in_progress | confirmed)          ← OrderStatus::fulfilmentEligible()
  │
  ├─ Wave Engine Step 2: attachEligibleOrders
  │     → INSERT preparation_wave_orders
  │     → event OrderAddedToWave                  (no order write, no reservation)
  │
  ├─ Wave Engine Step 3: WavePreparationService::startPreparation
  │     → wave.status = preparing, wave.started_at = now
  │     → event OrderMovedToPreparing  (per order)   → DemandAnalysis read model only
  │     → event WavePreparationStarted
  │           └─ HandlePreparationWavePreparationStarted
  │                 └─ FulfillmentEngine.run(MoveToPreparationWorkflow)
  │                       → reserves inventory if not already reserved
  │                       → order.status = READY_FOR_DISPATCH      ◀── HERE
  │
  ├─ Physical preparation (product-level, operator-driven):
  │     PATCH  /waves/{w}/product-demand/{p}/prepared    → wave_product_demand.prepared_qty
  │     POST   /waves/{w}/product-demand/{p}/complete    → wave_product_demand.preparation_completed_at
  │     POST   /waves/{w}/product-demand/{p}/uncomplete  → …= NULL
  │        (no order is touched by any of the three)
  │
  ├─ Wave Engine Step 4: rotateWave → wave.status = CLOSED
  │     → event WaveClosed
  │           └─ DemandAnalysis\WaveClosedListener = DELIBERATE NO-OP
  │           └─ NO order-side listener exists anywhere
  │
  └─ Loading: DispatchVehicleAction → LoadVehicleWorkflow
        → order.status = OUT_FOR_DELIVERY
```

### 3.1 PART 3 — what does `ready_for_dispatch` mean?

**Answer: (A) preparation STARTED — as actually produced. The codebase contains three
mutually inconsistent readings, and the producer is the odd one out.**

| Site | Reading | Evidence |
|---|---|---|
| `MoveToPreparationWorkflow` docblock | "called by the Preparation OS **when all work is done**" | [:20-26](backend/Modules/Operations/Fulfillment/Application/Workflows/MoveToPreparationWorkflow.php:20) |
| Its only wave caller | fired at preparation **start**, before any unit is picked | [HandlePreparationWavePreparationStarted.php:31-72](backend/Modules/Commerce/Orders/Application/Listeners/HandlePreparationWavePreparationStarted.php:31) ← [WavePreparationService.php:74-84](backend/Modules/Operations/Preparation/Application/Services/WaveEngine/WavePreparationService.php:74) |
| `CancelOrderWorkflow` | "physical preparation work **is complete**" — requires `force_cancel_preparation` | [:52-61](backend/Modules/Operations/Fulfillment/Application/Workflows/CancelOrderWorkflow.php:52) |
| `LoadVehicleWorkflow` | consumes it as "ready to load" → `out_for_delivery` | [:94](backend/Modules/Operations/Fulfillment/Application/Workflows/LoadVehicleWorkflow.php:94) |
| `PreparationEntryGateTest` | ready_for_dispatch is **refused re-entry** into preparation | `test_ready_for_dispatch_is_refused_even_when_reserved` |

**Runtime proof of the contradiction.** ORD-00001/3/4 are all `ready_for_dispatch`, the
wave they belong to is still `preparing`, and the wave's only product-demand row shows
`prepared_qty = 1.0000` of `required_qty = 2.0000`. Three orders are declared "ready for
dispatch" while 50 % of the work is unfinished.

`ready_for_dispatch` therefore **cannot be treated as evidence of preparation
completion**, exactly as PART 1 instructed. It is a *dispatch-eligibility* state that the
wave engine currently sets one phase too early.

---

## 4. Preparation Completion Authority (PART 1)

### 4.1 The canonical fact

| Question | Answer |
|---|---|
| **1. Canonical source?** | `wave_product_demand.preparation_completed_at` — an explicit operator declaration, not an inference |
| **2. Stored where?** | Table `wave_product_demand`, key `(preparation_wave_id, product_id)` |
| **3. Written by whom?** | `WaveDemandController::completePreparation()` ([:142-153](backend/Modules/Operations/DemandAnalysis/Presentation/Http/Controllers/WaveDemandController.php:142)), route `POST /preparation/waves/{waveId}/product-demand/{productId}/complete`, permission `operations.preparation.update`. Cleared by `uncompletePreparation()` ([:165-176](backend/Modules/Operations/DemandAnalysis/Presentation/Http/Controllers/WaveDemandController.php:165)) and auto-invalidated by `DemandReadRepository::clearCompletionWhereRequiredChanged()` ([:112-146](backend/Modules/Operations/DemandAnalysis/Application/Services/DemandReadRepository.php:112)) |
| **4. When?** | Only when an operator explicitly declares the product done. Reaching `prepared_qty ≥ required_qty` deliberately does **not** auto-complete (migration docblock, and the controller comment at [:136-141](backend/Modules/Operations/DemandAnalysis/Presentation/Http/Controllers/WaveDemandController.php:136)) |
| **5. Per order or per product?** | **Per (wave, product).** There is no order dimension anywhere in this table |
| **6. Survives an order status change?** | **Yes.** It lives on the wave demand read model and is invalidated only by a change in that product's `required_qty`. It is entirely independent of `orders.status` |
| **7. Distinguishes entered / started / completed?** | **Partially, and at the wrong grain** — see below |

### 4.2 The three-phase question (PART 1.7)

| Phase | Fact that exists today | Grain |
|---|---|---|
| **Entered preparation** | `preparation_wave_orders` row exists, `postponed_at IS NULL` | **per order** ✔ |
| **Started preparation** | `preparation_waves.started_at` / `status = preparing`; per order, `OrderMovedToPreparing`; effectively `order.status = ready_for_dispatch` | **per wave** (order-level signal is the mis-timed status) |
| **Completed preparation** | `wave_product_demand.preparation_completed_at` | **per product** ✘ |

**There is no order-level "completed preparation" fact, and none can be read directly.**
It is *derivable* — an order is fully prepared in wave W iff every product on its lines has
a non-null `wave_product_demand.preparation_completed_at` for W — but no code computes
this today, and whether that derivation is the correct business rule is **G-1**.

### 4.3 Per PART 1's instruction — no new column is proposed

`wave_product_demand.preparation_completed_at` is a real canonical fact with live data. The
recommendation in §20 derives from it rather than introducing a competing column.
`orders.preparation_completed_at` is **not** that fact — see §5.

---

## 5. `orders.preparation_completed_at` Audit (PART 2)

### 5.1 Full inventory

| Aspect | Finding |
|---|---|
| **Migration** | [`2026_07_08_910002_add_preparation_completed_at_to_orders_table.php:17-19`](backend/Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_08_910002_add_preparation_completed_at_to_orders_table.php:17) — `timestamp nullable` |
| **Model** | `Order::$fillable` [:245](backend/Modules/Commerce/Orders/Domain/Models/Order.php:245) |
| **Cast** | `'datetime'` [:310](backend/Modules/Commerce/Orders/Domain/Models/Order.php:310) |
| **Writers** | Exactly one: `HandlePreparationWaveCompleted::handle()` [:44-50](backend/Modules/Commerce/Orders/Application/Listeners/HandlePreparationWaveCompleted.php:44) |
| **Readers** | **ZERO.** Not in any Resource, DTO, query, scope, report, or the frontend. Verified by exhaustive grep across `backend/Modules`, `backend/app`, `frontend/src` |
| **Workflows** | None reference it |
| **Listeners** | The single writer above |
| **Queries** | None |
| **Runtime** | `SELECT COUNT(*) FROM orders WHERE preparation_completed_at IS NOT NULL` → **0** (6 orders total) |

### 5.2 Why the writer never writes — **two independent causes**

**Cause A — the trigger event never fires on the engine path.**
`HandlePreparationWaveCompleted` is subscribed to `WaveCompleted`
([OrderServiceProvider.php:62](backend/Modules/Commerce/Orders/Infrastructure/Providers/OrderServiceProvider.php:62)).
`WaveCompleted` has exactly one producer: `CompleteWaveAction`
([:148-165](backend/Modules/Operations/Preparation/Application/Actions/CompleteWaveAction.php:148)).
The engine never calls it — Step 4 calls `rotateWave` → `closeWave`, which fires
**`WaveClosed`**. `WaveClosed` has **no order-side listener anywhere**; its only
subscribers are `DemandAnalysis\WaveClosedListener` (a documented no-op,
[:17-21](backend/Modules/Operations/DemandAnalysis/Application/Listeners/WaveClosedListener.php:17))
and the event-bus republisher
([EventPlatformServiceProvider.php:154](backend/Modules/Platform/EventPlatform/Infrastructure/Providers/EventPlatformServiceProvider.php:154)).

*The previous report did not identify this cause.* It matters: fixing only the WHERE clause
would still leave the column unwritten on every engine wave.

**Cause B — the WHERE clause matches nothing.**
```php
DB::table('orders')->whereIn('id', $orderIds)
    ->where('status', 'preparing')          // ← HandlePreparationWaveCompleted.php:46
```
`preparing` is not an `OrderStatus` case. It was removed and remapped to `in_progress`
twice — by `2026_07_22_100000_simplify_order_lifecycle_v3.php:30` and again by
`2026_08_13_100000_supersede_order_lifecycle_v3_canonical.php:56,76`. `orders.status` is
`varchar(255)` with no DB-level enum (verified: `SHOW COLUMNS FROM orders LIKE 'status'`),
so the mismatch fails **silently** — zero rows, no error, and the listener still logs
success with `order_count: 0`.

### 5.3 Candidate repairs — **RECORDED ONLY, NOT APPLIED**

| ID | Candidate | Note |
|---|---|---|
| **R-1** | `HandlePreparationWaveCompleted.php:46` — invalid status condition (`'preparing'` is not a lifecycle state). | Mechanical. **But repairing it alone changes nothing** — Cause A still applies |
| **R-2** | No order-side subscriber for `WaveClosed`. The engine's terminal event carries no order consequence. | Not mechanical — what *should* happen at wave close is **G-4** |
| **R-3** | [`2026_07_08_910002…:24-26`](backend/Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_08_910002_add_preparation_completed_at_to_orders_table.php:24) — `down()` has an inverted guard: `if (Schema::hasColumn(...)) { return; }` then drops. **The rollback can never drop the column.** | Mechanical, unrelated to this task |
| **R-4** | The listener's docblock claims it "stamps preparation_completed_at on all orders in a completed wave"; it stamps none. | Documentation only |

**Nothing was changed.**

---

## 6. Wave vs PreparationSession (PART 4)

Both lifecycles are live and scheduled ([routes/console.php:23-33](backend/routes/console.php:23) and
[:58-61](backend/routes/console.php:58)). The decisive evidence is not in the backend.

### 6.1 Evidence

| Dimension | PreparationWave | PreparationSession |
|---|---|---|
| **Mounted UI routes** | 7 (`/operations/preparation/wave-workspace`, `…/products`, `…/materials`, `…/missing`, `…/wave-orders`, `…/settings`, `…/wave-archive`) — [routes.ts:119-125](frontend/src/router/routes.ts:119) | **0** |
| **API surface** | full | full (unused by any mounted page) |
| **Demand engine** (`wave_product_demand`, `wave_material_demand`, `wave_kpis`, missing materials) | keyed by `preparation_wave_id` | none |
| **Loading / Allocation input** | `AutoAllocationService` reads `PreparationWaveOrder` ([:262](backend/Modules/Operations/Loading/Application/Services/AutoAllocationService.php:262)); `loading_tasks.preparation_wave_id`; pool entries carry `preparation_wave_id` | none |
| **Runtime rows** | 5 waves, 3 memberships, 1 demand row with real operator data | 19 sessions, all `draft`, all `orders_count = 0`, `products_count = 0`; never frozen, never closed |
| **`preparation_waves.preparation_session_id`** | **0 of 5 populated.** `AddWaveToSessionAction` has an endpoint but no caller in any mounted UI | — |
| **Live behavioural effect** | the whole operational cycle | exactly one: `OrderPreparationObserver` auto-detaches an order from its session when it stops being eligible |

### 6.2 Answers

1. **Which represents the Operational Cycle?** — **PreparationWave.** It is the only unit
   with a scheduler-driven open/start/end, a demand projection, a UI, and a Loading
   consumer.
2. **Which represents actual preparation execution?** — **PreparationWave**, at
   product grain, via `wave_product_demand.prepared_qty` /
   `preparation_completed_at`. `PreparationSession` executes nothing;
   `preparation_wave_items` (the designed execution table) is empty for every engine wave.
3. **Is Wave membership sufficient?** — **Yes.** It is the only membership any live
   consumer reads.
4. **Does Session membership prevent re-preparation?** — **No.** `attachEligibleOrders`
   ([WaveMembershipService.php:37-45](backend/Modules/Operations/Preparation/Application/Services/WaveEngine/WaveMembershipService.php:37))
   never consults `preparation_session_orders`. The two membership systems are completely
   independent.
5. **Can an order be in Wave #1, then Wave #2, with an old Session?** — Today, no order can
   be in two waves at all. But sessions **already** behave this way: runtime shows three
   orders attached to `PS-20260812-0001` on 2026-08-12 and detached on 2026-08-13 18:56:26 —
   the exact second the wave moved to Preparing and `MoveToPreparationWorkflow` made them
   `ready_for_dispatch` (i.e. ineligible). Session membership is *already* released and
   historical; wave membership is not.
6. **Does an old Session prevent re-preparation?** — **No.** `whereDoesntHave('activeSessionOrder')`
   ([DailyPreparationSessionManager.php:106](backend/Modules/Operations/Preparation/Application/Services/DailyPreparationSessionManager.php:106))
   ignores detached rows by construction.
7. **Canonical authority on conflict?** — **PreparationWave.**

### 6.3 Decision

```
Wave    = the canonical Operational Cycle AND the canonical preparation-execution unit.
          One per (company, warehouse, planning_date). Owns demand, membership,
          product-level prepared/completed, and the Loading handoff.

Session = a supervisory/aggregation shell over waves (preparation_waves.preparation_session_id).
          NOT canonical for membership, demand, or execution. Currently dormant:
          no UI, no linked waves, zero attached orders.
          Its ONE live contribution is the auto-detach observer — which is precisely
          the membership-release mechanism the Wave path lacks (§9).
```

**G-7 is RESOLVED.** The Session lifecycle is dormant infrastructure, not a competing
authority. It should not be extended by the Cross-Day task — but its `detached_at`
pattern is the design precedent the Wave path should adopt (§9, §20).

---

## 7. Historical Membership (PART 5)

### 7.1 Complete caller inventory for `preparation_wave_orders`

Verified by exhaustive grep across `backend/Modules`. Every site classified by whether a
**second, historical row for the same order in a different wave** would change its result.

**Group A — wave-scoped. Structurally immune (14 sites).**

| Site | Scope | `postponed_at` filtered |
|---|---|---|
| `ProductDemandCalculator:28-34` | `= wave.id` | ✔ |
| `MaterialDemandCalculator::ownWaveMaterialReservations:291-294` | `= wave.id` | ✔ |
| `MissingMaterialCalculator:94-97, 112-119` | `= wave.id` | ✔ |
| `GenerateDemandAction:39, 58` | `= wave.id` | ✔ |
| `WaveDemandController::waveOrders:412-425` | `= wave.id` | ✔ |
| `WaveDemandController::productRelatedOrders:242-249` | `= wave.id` | ✔ |
| `WaveDemandController::materialRelatedOrders:287-315` | `= wave.id` | ✔ |
| `PreparationWaveController::productQueue:416` | `= wave.id` | ✘ (pre-existing) |
| `PreparationWaveController::productWorkspace:538` | `= wave.id` | ✘ (pre-existing) |
| `HandlePreparationWaveCompleted:25-30` | `= wave.id` | ✘ (pre-existing) |
| `WaveMembershipService::detachOrder:145`, `postponeOrder:192` | `= wave.id` | n/a |
| `PreparationWave::waveOrders()` + its callers (`WavePreparationService:59`, `StartPreparationAction:124`, `CancelWaveAction:41`, `RecalculateWaveAction:68`) | `= wave.id` | ✘ (pre-existing) |
| `AutoAllocationService:262-266` | `whereIn(waveIds)` | ✔ `->active()` |
| `PreparationEnterpriseController:59, 163` | `whereIn(waveIds)` filtered by `planning_date` / status | ✘ (pre-existing) |

The four ✘ entries are a **pre-existing inconsistency** (postponed rows counted where they
should not be). They are *not* aggravated by historical membership, because every one of
them is wave-scoped. Flagged, not in scope.

**Group B — NOT wave-scoped. These break under historical membership (4 sites).**

| # | Site | Query | Effect of a historical row |
|---|---|---|---|
| **B-1** | [`WaveMembershipService::attachEligibleOrders:40-44`](backend/Modules/Operations/Preparation/Application/Services/WaveEngine/WaveMembershipService.php:40) | `whereNotExists(… WHERE pwo.order_id = orders.id)` — no wave, no date, no status | **THE ROOT CAUSE.** Any order that ever joined any wave is excluded from every future wave, permanently |
| **B-2** | [`CreateWaveAction:36-43`](backend/Modules/Operations/Preparation/Application/Actions/CreateWaveAction.php:36) | `where(company_id)->whereIn(order_id)` → `OrderAlreadyInWaveException` | A carried-over order would be rejected from every future manual wave, forever |
| **B-3** | [`RecalculateWaveAction:35-44`](backend/Modules/Operations/Preparation/Application/Actions/RecalculateWaveAction.php:35) | same + `preparation_wave_id != wave.id` | same |
| **B-4** | [`PreparationWaveController::validate…:799-810`](backend/Modules/Operations/Preparation/Presentation/Http/Controllers/PreparationWaveController.php:799) | join `preparation_waves`, `whereNotIn(pw.status, ['completed','cancelled'])` | **Already broken today:** `closed` is absent from the exclusion list, so an order in a *closed* engine wave is reported "already in an active wave" |

**This corrects the previous report's §6 claim** that "no code resolves which wave an order
is in". Three application-level checks (B-2, B-3, B-4) enforce company-scoped order
exclusivity independently of the database constraint, and all three must be taught about
active-vs-historical membership. The previous report's conclusion that relaxation is
"essentially zero exception risk" is wrong: B-2 and B-3 throw
`OrderAlreadyInWaveException`, and B-4 `abort(422)`.

### 7.2 Which model does the system implement?

**`one Order → one Wave FOREVER`**, enforced at four independent layers:

1. DB: `uq_prep_wave_orders_company_order UNIQUE (company_id, order_id)` — confirmed live
   via `SHOW INDEX FROM preparation_wave_orders`.
2. Collector: B-1's unscoped `whereNotExists`.
3. Application: B-2, B-3, B-4.
4. Tests: `OrderExclusivityTest` (6 methods, incl.
   `test_db_unique_constraint_prevents_duplicate_company_order_pair`) and
   `PreparationEntryGateTest::test_duplicate_preparation_entry_remains_blocked`.

Nothing deletes membership rows on close/complete/rotate (`closeWave` writes only wave
columns, [:98-103](backend/Modules/Operations/Preparation/Application/Services/WaveEngine/WaveLifecycleService.php:98)).
The only delete path is `RecalculateWaveAction:46-50`, gated to Draft/Planning and
therefore unreachable for engine waves. **History is already preserved; nothing may
delete it.**

### 7.3 Can historical membership be added safely?

**Yes — conditionally.** Group A is immune. Group B is a closed, four-item list, all in
Preparation, all with a clear fix (add an active-membership predicate). No consumer must
be taught to "pick a winner", because no code asks *which* wave an order is in — only
*whether* it is in one.

**Condition:** exactly one membership row per order may be active at any instant. §8
defines "active"; §10 gives the enforcement.

---

## 8. Active Membership (PART 6)

### 8.1 What cannot define it

| Candidate | Verdict |
|---|---|
| **`orders.preparation_wave_id`** | **Does not exist.** `SHOW COLUMNS FROM orders LIKE '%wave%'` → empty. Same for `%session%`. Membership is exclusively junction-table |
| **Wave status** | **MUST NOT be the sole authority — proven.** See §12: a wave can sit in `preparing` indefinitely. Runtime: `PREP-202608-000001`, `planning_date = 2026-08-12`, still `preparing` on 2026-08-14, two newer waves behind it |
| **Order status** | **MUST NOT be the sole authority.** `ReturnToProcessingWorkflow` and `ReturnToPendingWorkflow` both write `in_progress` with no wave awareness, making a fully prepared order eligible again within 60 s |
| **Session membership** | Not canonical (§6) |
| **Membership row existence** | Insufficient — that is exactly today's bug (B-1) |

### 8.2 Proposed canonical definition (PROPOSAL ONLY — NOT IMPLEMENTED)

> **An order is currently in a wave iff a `preparation_wave_orders` row exists for it whose
> membership has neither been postponed nor released** — i.e. a single row-local predicate,
> independent of both wave status and order status.

Formally: `postponed_at IS NULL AND released_at IS NULL`.

Rationale, all evidenced above:

- **Row-local** → immune to stale waves (§12) and to order-status regressions.
- **Two distinct facts, not one.** `postponed_at` is an *operator* decision about *this*
  cycle ([WaveMembershipService.php:168-183](backend/Modules/Operations/Preparation/Application/Services/WaveEngine/WaveMembershipService.php:168));
  a release stamp is a *lifecycle* fact. Overloading `postponed_at` to mean "cycle ended"
  is the semantics change PART 5 of the previous task forbade (**G-3 stands: do not
  redefine `postponed_at`**).
- **Matches the repo's own precedent** — `preparation_session_orders.detached_at` +
  `Order::activeSessionOrder()` (`hasOne(...)->whereNull('detached_at')`,
  [Order.php:375-381](backend/Modules/Commerce/Orders/Domain/Models/Order.php:375)) is
  the identical shape, already shipped, already producing correct historical rows in
  production data.

`PreparationWaveOrder::scopeActive()`
([:97-100](backend/Modules/Operations/Preparation/Domain/Models/PreparationWaveOrder.php:97))
is the existing home for this predicate.

**Not implemented. No column created.**

---

## 9. Membership Release (PART 7)

Options as posed, assessed against the existing architecture:

| Option | Assessment |
|---|---|
| **A — `released_at`** | ✅ **Recommended.** Names the fact precisely; row-local; mirrors `detached_at`; independent of the unreliable wave status |
| **B — `ended_at`** | Same shape, weaker name (reads as a wave-time fact, not a membership fact) |
| **C — `completed_at`** | ✘ Actively harmful — collides with `preparation_waves.completed_at` and with the completion semantics of §4 |
| **D — `detached_at`** | ✅ **Equally valid, and strictly more consistent.** It is the exact name the sibling junction already uses. Choose A or D on naming grounds alone |
| **E — membership status enum** | ✘ Over-engineered for a two-state fact; adds an enum, a cast, a migration and a backfill for what one nullable timestamp expresses |
| **F — Session completion** | ✘ Session is dormant and not canonical (§6) |
| **G — Wave status** | ✘ **Disproven by §12** — waves do not reliably reach a terminal status |

**Recommendation: a single nullable timestamp, named `released_at` (or `detached_at` for
exact parity with `preparation_session_orders`), stamped at wave close/rotate.**

Writer placement (design note, not code): `WaveLifecycleService::closeWave()`
is the single funnel — `rotateWave()` calls it
([:127](backend/Modules/Operations/Preparation/Application/Services/WaveEngine/WaveLifecycleService.php:127)),
it is already transactional and idempotent, and it is the only place an engine wave ends.
`CancelWaveAction` would need the same stamp.

**No column created. No migration written.**

---

## 10. Unique Constraints (PART 8)

### 10.1 Present state — verified live

```
SHOW INDEX FROM preparation_wave_orders;      -- ecos_dev, MySQL 8.4.10
  PRIMARY                                (id)
  uq_preparation_wave_orders_wave_order  (preparation_wave_id, order_id)   ← same-wave idempotency
  uq_prep_wave_orders_company_order      (company_id, order_id)            ← THE BLOCKER
```

Sources: [`2026_07_05_100200…:31`](backend/Modules/Operations/Preparation/Infrastructure/Database/Migrations/2026_07_05_100200_create_preparation_wave_orders_table.php:31)
and [`2026_07_06_120200_add_order_exclusivity_constraint.php:15-18`](backend/Modules/Operations/Preparation/Infrastructure/Database/Migrations/2026_07_06_120200_add_order_exclusivity_constraint.php:15).

### 10.2 Target domain model

Historical membership requires a **second row** while §7.2 forbids deleting the first.
`UNIQUE(company_id, order_id)` forbids exactly that. The target is:

```
(company_id, order_id, <active>)     UNIQUE     -- many historical rows, at most one active
(preparation_wave_id, order_id)      UNIQUE     -- unchanged: same-wave idempotency
```

### 10.3 Minimum change — and a portability constraint the previous report understated

Production is **PostgreSQL** (`docs/CLAUDE.md`); dev and test are **MySQL 8.4**
(verified: `ecos-dev-mysql`, `DB_CONNECTION=mysql`, `DB_DATABASE=ecos_dev`). MySQL has no
partial unique indexes. Two further facts:

- The existing exclusivity migration's `down()` uses `ALTER TABLE … DROP INDEX`
  ([:23-27](backend/Modules/Operations/Preparation/Infrastructure/Database/Migrations/2026_07_06_120200_add_order_exclusivity_constraint.php:23)) —
  **MySQL-only syntax; it will fail on PostgreSQL.**
- `DemandReadRepository`'s docblock claims "PostgreSQL ON CONFLICT"
  ([:22-23](backend/Modules/Operations/DemandAnalysis/Application/Services/DemandReadRepository.php:22))
  while running on MySQL. The codebase already carries engine-assumption drift.

**Portable minimum change (design only — NOT written):** because both engines treat `NULL`
as distinct in a unique index, a single generated/derived nullable discriminator gives
identical semantics on both without a partial index:

```
release_marker  =  NULL when membership is active,  <row id> when released
UNIQUE (company_id, order_id, release_marker)
```

This satisfies every stated requirement:

| Requirement | Satisfied by |
|---|---|
| same-wave idempotency | untouched `uq_preparation_wave_orders_wave_order` — the constraint `attachOrder()`'s `UniqueConstraintViolationException` catch relies on ([:99-101](backend/Modules/Operations/Preparation/Application/Services/WaveEngine/WaveMembershipService.php:99)) |
| historical membership | many rows sharing `(company_id, order_id)` with distinct non-null markers |
| one active wave per order | at most one row with `marker IS NULL` |
| tenant isolation | `company_id` remains the leading column |
| forbids Wave 1 active + Wave 2 active | ✔ by the above |

Rejected, with reasons:

- `(company_id, order_id, wave_id)` — degenerate; already implied by the wave-scoped unique.
- `(company_id, order_id, planning_date)` — permits two active memberships on one date
  across warehouses.
- Drop-and-guard-in-app (the `2026_07_20_100000_fix_inventory_items_soft_delete_unique.php`
  precedent) — that precedent's live consequence is that `inventory_items` in `ecos_dev`
  today has **no** unique on `(warehouse_id, product_id)` at all. Repeating it here would
  leave the one-active-wave rule enforced only by application code.

### 10.4 Blast radius

- **DB layer:** low. The relaxed constraint is read by nothing.
- **Application layer:** four sites, all in Preparation — B-1 … B-4 (§7.1). All must gain
  the active-membership predicate.
- **Test layer:** `OrderExclusivityTest` (6 methods) and
  `PreparationEntryGateTest::test_duplicate_preparation_entry_remains_blocked` **encode the
  current rule and will fail**. They must be rewritten to assert
  *one-active-membership* rather than *one-membership-ever*. This is a contract change to a
  pinned behaviour, not incidental test churn.

**Nothing implemented. No migration written.**

---

## 11. Reservation Across Waves (PART 9)

The trace the previous report could not finish. **Now complete.**

### 11.1 Who creates what

| Trigger | Creates a reservation? | Keyed to |
|---|---|---|
| **Wave attachment** (`WaveMembershipService::attachOrder`) | **No.** Inserts the membership row and fires events; touches no inventory table | — |
| **PreparationSession attachment** | **No** | — |
| **`PreparationInventoryReservation`** (soft) | Only via `StartPreparationAction:161` → **unreachable for engine waves** (§2.3). Runtime: **0 rows** | `preparation_wave_id` |
| **`MoveToPreparationWorkflow`** (fired at preparation START) | **Yes** — `ReserveOrderInventoryAction`, but only when `reservation_status ∉ {Reserved, PartialReserved}` ([:79-80](backend/Modules/Operations/Fulfillment/Application/Workflows/MoveToPreparationWorkflow.php:79)) | **the ORDER** |

### 11.2 What reservations are actually keyed to

`stock_ledger_entries` runtime distinct values:

```
reference_type          movement_type    count
manual_receipt          purchase_receipt   1
sales_order             reservation        3
sales_order_material    reservation        4
manual_adjustment       adjustment_in      2
```

`reference_id` is the **order id**. There is **no `wave_id` and no `session_id` on any
reservation record.** Order-level reservation state lives on `orders.reservation_status`,
`orders.inventory_reserved_at`, `order_lines.reserved_qty`, `inventory_items.reserved_qty`.

**Conclusion: reservations are ORDER-scoped, full stop.** Wave and Session are invisible to
the reservation layer.

### 11.3 The scenario

```
Order A → Wave 1 → (preparation starts) → Reservation X created, order = ready_for_dispatch
Wave 1 ends. Order A not shipped.
Order A → Wave 2
```

**Canonical behaviour: Reservation X remains authoritative. No second reservation is
created.** Proof:

1. Wave close writes only wave columns — no order write, no inventory write
   ([`closeWave:98-103`](backend/Modules/Operations/Preparation/Application/Services/WaveEngine/WaveLifecycleService.php:98)).
   `WaveClosed` has no order-side listener (§5.2 Cause A).
2. Postponement releases nothing — pinned by
   `WavePostponeOrderTest::test_postpone_changes_no_reservation_or_inventory_state`.
3. Wave *cancel* returns orders via `ReturnToProcessingWorkflow`
   ([HandlePreparationWaveCancelled](backend/Modules/Commerce/Orders/Application/Listeners/HandlePreparationWaveCancelled.php)),
   and that workflow **deliberately does not release inventory** — it is not among
   `ReleaseOrderInventoryAction`'s four callers (`UpdateOrderAction`,
   `ReturnToPendingWorkflow`, `ReturnToPaymentWorkflow`, `CancelOrderWorkflow`). Compare
   `ReturnToPendingWorkflow:57-60`, which *does* release. **The distinction is deliberate**:
   returning an order for re-preparation keeps its stock committed; unlocking an order for
   editing frees it.
4. Re-entry into Wave 2 re-runs `MoveToPreparationWorkflow`, whose reservation guard
   short-circuits on `Reserved` / `PartialReserved` — **no double reservation**.

### 11.4 One caveat the owner must know

If carry-over were ever routed through **`ReturnToPendingWorkflow`** instead of
`ReturnToProcessingWorkflow`, the reservation *would* be released and
`confirmed_at`, `inventory_reserved_at`, `reservation_status` and
`partial_reservation_approved_at` all nulled
([:64-73](backend/Modules/Operations/Fulfillment/Application/Workflows/ReturnToPendingWorkflow.php:64)).
The order would then have to re-compete for stock and could land in `awaiting_stock`.
**Which return path carry-over uses is therefore a reservation-safety decision, not a
cosmetic one** — see §16 and G-4.

**G-8 is RESOLVED: reservation-across-waves is SAFE, on the condition that carry-over uses
the non-releasing return path.** No reservation code was touched.

---

## 12. Stale Wave Closure (PART 10)

### 12.1 The mechanism — code

`processWarehouse` fetches `$activeWave` **date-scoped to today**
([:87](backend/Modules/Operations/Preparation/Infrastructure/Console/Commands/RunWaveSchedulerCommand.php:87))
and returns immediately if none exists
([:89-91](backend/Modules/Operations/Preparation/Infrastructure/Console/Commands/RunWaveSchedulerCommand.php:89)).
Step 4 — the only closure path — therefore **can only ever see a wave whose
`planning_date` is today**. The code states this is deliberate
([:82-86](backend/Modules/Operations/Preparation/Infrastructure/Console/Commands/RunWaveSchedulerCommand.php:82): *"Stale waves
are left untouched — they are history"*).

Precisely:

- **condition** — `$activeWave->status === Preparing && $time >= wave_end_time`
- **query** — `getActiveWave(company, warehouse, $today)`, `whereIn(status, {collecting, preparing})`, `where('planning_date', $today)`
- **date assumption** — a wave's end is evaluated only on its own `planning_date`
- **timezone assumption** — `$today`/`$time` from `Carbon::now()->setTimezone($config->timezone)` ([:59-61](backend/Modules/Operations/Preparation/Infrastructure/Console/Commands/RunWaveSchedulerCommand.php:59)), while the rotation clamp uses `Carbon::now()->startOfDay()` in **app** timezone ([WaveLifecycleService.php:138](backend/Modules/Operations/Preparation/Application/Services/WaveEngine/WaveLifecycleService.php:138)) — §14
- **status assumption** — only `Preparing` closes. A wave stuck in `Collecting` is unreachable by Step 4 **by design**

### 12.2 The live cause — runtime

```
wave_engine_configurations (the ONE active row):
  collection_start_time   00:00:00
  preparation_start_time  23:59:00
  wave_end_time           23:59:59
  auto_create             1
  auto_assign_orders      1
  auto_move_to_preparing  0        ◀── Step 3 is DISABLED
  timezone                UTC

preparation_waves:
  PREP-202608-000003  2026-08-14  collecting  0 orders
  PREP-202608-000002  2026-08-13  collecting  0 orders
  PREP-202608-000001  2026-08-12  preparing   1 order   started_at = 2026-08-13 18:56:25
  PREP-202607-000002  2026-07-30  collecting  0 orders
  PREP-202607-000001  2026-07-29  closed      0 orders
```

Two distinct stranding mechanisms, both live:

1. **`auto_move_to_preparing = 0`** → today's waves never reach `Preparing` → Step 4's
   guard never holds → they never rotate. `2026-08-13`, `2026-08-14` and `2026-07-30` are
   stranded in `Collecting` **permanently**.
2. **Manual start of a past-dated wave.** `PREP-202608-000001` has `planning_date =
   2026-08-12` but `started_at = 2026-08-13 18:56:25` — it was started a day late through
   `POST /preparation/waves/{id}/advance`
   ([PreparationWaveController::advance:248-257](backend/Modules/Operations/Preparation/Presentation/Http/Controllers/PreparationWaveController.php:248)),
   not by the scheduler. From that instant it is **permanently invisible to the engine**:
   its `planning_date` is not today, so `getActiveWave` never returns it.

There is also **no manual close endpoint** — only `complete` (requires no in-progress items)
and `cancel`. An operator's only exits from a stranded `Preparing` wave are Complete or
Cancel.

*Also confirmed:* the "stranded wave" clamp at
[`WaveLifecycleService.php:137-142`](backend/Modules/Operations/Preparation/Application/Services/WaveEngine/WaveLifecycleService.php:137)
is unreachable — its only caller always passes a wave dated today, so `$nextDate` is
always tomorrow and `$nextDate < $today` can never hold.

### 12.3 The correct lifecycle rule

**Cannot be settled from architecture alone.** The correct rule depends on
`wave_end_time`'s meaning, which the schema does not record: `preparation_waves` has
`planning_date DATE` plus event stamps (`started_at`, `completed_at`) but **no scheduled
`ends_at`** (verified: `SHOW COLUMNS FROM preparation_waves`). "Now ≥ ends_at" is not
expressible against stored data — `ends_at` would have to be recomputed from config every
tick, and config is mutable, so a historical wave's end time is not reconstructible.

What the audit *can* state:

- Any lifecycle-aware uniqueness **must not depend on wave status** (§8.1, proven).
- The engine's `return` at [:89-91](backend/Modules/Operations/Preparation/Infrastructure/Console/Commands/RunWaveSchedulerCommand.php:89)
  is what makes stale waves unreachable. A sweep independent of `$activeWave` is the
  minimum structural change.
- Whether stranded waves should be auto-closed at rollover — and whether their orders
  should then be returned (§15) — is **G-4**.

**Not repaired.**

---

## 13. Cross-Day Timing (PART 11)

### 13.1 Does the schema support Day 1 18:00 → Day 2 08:00 → Day 2 15:00?

**No. It is forbidden by a database CHECK constraint.**

```sql
-- 2026_07_16_100000_create_wave_engine_configurations_table.php:45
ALTER TABLE wave_engine_configurations ADD CONSTRAINT chk_wave_engine_config_times
CHECK (preparation_start_time > collection_start_time
   AND wave_end_time        > preparation_start_time)
```

The requested model needs `collection_start (18:00) > preparation_start (08:00)` — a
direct violation. All three columns are `varchar(8)` wall-clock times with no day offset,
and `preparation_waves` carries a single `planning_date DATE`. **The entire time model is
single-day by construction.**

### 13.2 Live proof that operators are already fighting it

The one active configuration reads `00:00:00 / 23:59:00 / 23:59:59` — the columns pushed to
their extremes to satisfy the CHECK while keeping the collection window open all day. This
is the schema constraint being worked around in production data.

### 13.3 Minimum change if the gap is to be closed (DESIGN ONLY)

Three additive elements, no rewrite:

1. A **day-offset** per phase (e.g. `preparation_start_day_offset`, `wave_end_day_offset`,
   default 0) so a phase can land on `planning_date + N`.
2. Replace the CHECK with one comparing **(offset, time)** pairs rather than bare times.
3. Materialise the resolved boundaries on the wave row at creation
   (`collection_starts_at`, `preparation_starts_at`, `ends_at`, all `timestampTz`) so
   closure becomes `now() >= ends_at` — evaluable **independently of `planning_date`**,
   which simultaneously fixes §12 and removes the two-clock problem in §14.

Element 3 is the load-bearing one: it converts wave lifecycle from a recomputed
wall-clock comparison into stored facts.

**Note:** `wave_engine_configurations` has **no API and no UI** — verified, zero
controllers, zero routes, zero frontend references. It is DB-only configuration. Any
cross-day model will need an admin surface, or it will be unmanageable in production.

**Not implemented. No migration written.**

---

## 14. Timezone (PART 12)

### 14.1 Four clocks — and the previous report identified only two

| # | Source | Value (runtime) | Read by |
|---|---|---|---|
| 1 | `config('app.timezone')` | `UTC` (env default; `APP_TIMEZONE` unset) | `now()`, `today()`, `Carbon::now()` everywhere |
| 2 | `wave_engine_configurations.timezone` | `UTC` | **only** `RunWaveSchedulerCommand:59` |
| 3 | **`companies.timezone`** | **`Africa/Cairo`** — for **all 4 companies** | **nothing in Operations, Preparation, or Fulfillment** |
| 4 | MySQL session/global | `SYSTEM` | driver-level only |

`warehouses` has no timezone column. `preparation_session_policies` has none.

### 14.2 Correction to the previous report

The previous report concluded the divergence was *latent* because "both are UTC". That is
true of clocks 1 and 2 — but **clock 3 already declares `Africa/Cairo`**, seeded that way
by `CompanySeeder:24` and `CompanyFactory:38`. The business timezone of every company in
the database is UTC+2/+3, and **no Preparation code reads it**.

**Present, active consequence:** orders placed in Cairo between 00:00 and 02:00/03:00 local
carry a UTC timestamp on the *previous* date, so the wave engine files them into the
previous operational day's wave. The scheduler's own `$today` is likewise a UTC date, so a
Cairo warehouse's "day" starts 2–3 hours before its business day does.

### 14.3 Divergence points in code

| Site | Clock |
|---|---|
| `RunWaveSchedulerCommand:59-61` (`$today`, `$time`) | 2 — config |
| `WaveLifecycleService:138` (rotation clamp `Carbon::now()->startOfDay()`) | 1 — app |
| `CreateDailyPreparationSessionsCommand` (`today()`) | 1 — app |
| `FreezePreparationSessionsCommand` (`now()`, `Carbon::today()`) | 1 — app |
| `DailyPreparationSessionManager::todaySession` / `warehousesNeedingSession` (`today()`) | 1 — app |
| Every `now()` writing `added_at`, `postponed_at`, `started_at`, `completed_at` | 1 — app |

### 14.4 Recommendation (PROPOSAL ONLY)

**`wave_engine_configurations.timezone` should be declared authoritative for the Preparation
operational day**, seeded from `companies.timezone` rather than defaulting to `'UTC'`
(`…:35`). It is the only *warehouse-grain* business timezone in the domain, and warehouse
grain is correct: a company may operate warehouses in different zones.

`companies.timezone` should be the **default source** for that value, not a second
authority. Whether that is the owner's intent is **G-2**.

**No configuration changed.**

---

## 15. Wave End Order Handling (PART 13)

### 15.1 Present state

**Nothing happens.** `WaveClosed` has no order-side listener (§5.2 Cause A). At wave end an
order simply keeps whatever status it holds — in practice `ready_for_dispatch`, set at
preparation *start*.

### 15.2 The only existing precedent

Wave **cancel** — not wave end — does return orders:

```php
// HandlePreparationWaveCancelled
Order::whereIn('id', $event->orderIds)
     ->where('status', OrderStatus::ReadyForDispatch)   // ← the ONLY status returned
     ->get()  → FulfillmentEngine.run(ReturnToProcessingWorkflow)   // → in_progress
```

`ReturnToProcessingWorkflow` guards `status === ReadyForDispatch`
([:22-29](backend/Modules/Operations/Fulfillment/Application/Workflows/ReturnToProcessingWorkflow.php:22))
and writes `InProgress` ([:35](backend/Modules/Operations/Fulfillment/Application/Workflows/ReturnToProcessingWorkflow.php:35)),
**without releasing inventory** (§11.3).

Two defects in that precedent, relevant to any wave-end rule modelled on it:

- It uses `$event->orderIds` = **all** wave orders (`CancelWaveAction:41`), including
  **postponed** ones — a postponed order is returned as though it were still in the cycle.
- It applies **no completion test whatsoever** — a fully prepared order and an untouched one
  are treated identically.

### 15.3 The questions, answered as far as code permits

**1. Should all non-shipped orders return?** — Code has no opinion; only the cancel path
expresses one, and it returns exactly `ready_for_dispatch`.

**2. What about each state?** — Mechanically:

| State at wave end | `ReturnToProcessingWorkflow` accepts? | Note |
|---|---|---|
| `ready_for_dispatch` | ✔ | the only accepted state |
| `out_for_delivery` / `delivered` | ✘ (guard throws) | already shipped — correctly excluded |
| `cancelled` / `returned` | ✘ | terminal |
| `awaiting_payment` | ✘ | payment block outranks fulfilment (`OrderStatus` docblock :123-142) |
| `awaiting_stock` | ✘ | never entered preparation; `PreparationEntryGateTest::test_awaiting_stock_is_refused_even_when_reserved` |
| `on_hold` / `scheduled` | ✘ | outside fulfilment execution |
| `in_progress` / `confirmed` | ✘ (guard throws) | already eligible; nothing to return |

**There is no "packing" state.** `OrderStatus` has eleven cases and packing is not one of
them.

**3. May all of these return to In Progress?** — No. Only `ready_for_dispatch` can, and
that is enforced by a guard, not a convention.

**4/5. Only orders that did not start, or did not complete?** — **This is the decision.**
Today the system cannot distinguish them at order grain (§4.2). Both "did not start" and
"did not complete" collapse to the same observable state: `ready_for_dispatch`.

### 15.4 What is settled

- **Only `ready_for_dispatch` orders are eligible to return.** Every other state is either
  terminal, already shipped, blocked on a non-fulfilment condition, or already eligible.
  Confirmed by the workflow guard, `OrderStatus` predicates, and `PreparationEntryGateTest`.
- **The return target is `in_progress`**, via `ReturnToProcessingWorkflow`
  (non-releasing) — **not** `ReturnToPendingWorkflow` (releasing, §11.4).
- **Postponed memberships must be excluded** from any return set — the cancel path's
  omission is a defect, not a precedent to copy.

### 15.5 What is NOT settled — G-1

Whether a **fully prepared, un-shipped** order should return to `in_progress` at wave end.
Code cannot answer it: no order-level completion fact exists (§4.2, §5).

---

## 16. Carry-over Eligibility (PART 14)

### 16.1 Why `status IN ('in_progress','confirmed')` is insufficient

It is exactly today's collector predicate
([`attachEligibleOrders:39`](backend/Modules/Operations/Preparation/Application/Services/WaveEngine/WaveMembershipService.php:39)),
and it is *status-only*. Once B-1's `whereNotExists` is scoped to active membership, order
status becomes the sole guard — and it is defeatable through supported paths:

- `ReturnToProcessingWorkflow` → `in_progress` (wave cancel)
- `ReturnToPendingWorkflow` → `in_progress` (operator unlock)
- `RescheduleOrderWorkflow:59` → `ReadyForDispatch ⇒ in_progress`

Any of these makes a fully prepared order collectible again **within 60 seconds**, with no
signal it was ever prepared.

### 16.2 The predicate the evidence supports (PROPOSAL — NOT IMPLEMENTED)

```
CARRY_OVER_ELIGIBLE(order, wave_now) ⇔

  (1) order.status ∈ OrderStatus::fulfilmentEligible()          -- {in_progress, confirmed}
        AND
  (2) order.assigned_warehouse_id = wave_now.warehouse_id       -- PreparationReleaseEngine
        AND
  (3) NO active membership row exists for the order             -- §8.2
        (postponed_at IS NULL AND released_at IS NULL)
        AND
  (4) order.inventory_shipped_at IS NULL                        -- not physically dispatched
        AND
  (5) NOT PREPARATION_COMPLETE(order, previous_wave)            -- ◀ G-1: undefined today
```

Clauses 1–4 are **derivable from existing canonical facts** and require no new business
decision:

- (1) is `OrderStatus::fulfilmentEligible()` — the single source, re-derived by
  `PreparationSessionPolicy::defaultEligibleStatuses()` and `config/distribution.php:57-60`.
  It correctly excludes `ready_for_dispatch`, `awaiting_stock`, `awaiting_payment`,
  `scheduled`, `on_hold` and the terminals — and it preserves
  `PreparationEntryGateTest::test_ready_for_dispatch_is_refused_even_when_reserved`.
- (2) is `PreparationReleaseEngine::ineligibilityReason`'s second rule.
- (3) is §8.2.
- (4) is the flag `LoadVehicleWorkflow` already uses for shipment idempotency
  ([:84](backend/Modules/Operations/Fulfillment/Application/Workflows/LoadVehicleWorkflow.php:84)).

**Clause (5) cannot be written today.** Because an order that reaches wave end at
`ready_for_dispatch` fails clause (1), the *return* step of §15 must run first — and that
return is precisely what clause (5) must gate. The two are the same decision seen from
opposite ends.

### 16.3 The three candidate definitions for clause (5) — for the owner

| Option | Definition | Consequence |
|---|---|---|
| **5a — product-derived** | Complete ⇔ every product on the order's lines has `wave_product_demand.preparation_completed_at IS NOT NULL` for that wave | Uses the real canonical fact (§4). But completion is *product × wave*, so an order sharing a product with another order inherits that product's declaration — completion is **not order-specific** |
| **5b — quantity-derived** | Complete ⇔ for every line, that product's `prepared_qty ≥ Σ required` in the wave | Same aliasing problem; and the controller comment at [WaveDemandController:136-141](backend/Modules/Operations/DemandAnalysis/Presentation/Http/Controllers/WaveDemandController.php:136) explicitly rejects quantity as a completion signal |
| **5c — return everything** | Every un-shipped order returns; a re-prepared order is accepted as the cost of never stranding one | Simplest, matches the wave-cancel precedent, risks duplicate physical work |

**Engineering does not select among these. That is G-1.**

---

## 17. Material Demand Safety (PART 15)

**Answer: moving an order from Wave #1 to Wave #2 does NOT double-count — provided at most
one membership is active at a time.**

### 17.1 Proof

1. **Every demand query is wave-scoped.** `ProductDemandCalculator:31`,
   `MaterialDemandCalculator::ownWaveMaterialReservations:292`,
   `MissingMaterialCalculator:96,114`, and all `WaveDemandController` drill-downs pin
   `pwo.preparation_wave_id = <this wave>`. A membership row belonging to a different wave
   is outside every one of them.
2. **No join fan-out is possible.** Within one wave, `uq_preparation_wave_orders_wave_order
   (preparation_wave_id, order_id)` guarantees at most one `pwo` row per order — so
   `pwo ⋈ order_lines` cannot multiply. That constraint is **untouched** by §10's proposal;
   this is why the minimum change deliberately preserves it.
3. **Demand is a full rebuild, not an accumulation.** `DemandReadRepository::upsertProductDemand`
   overwrites `required_qty` by `(preparation_wave_id, product_id)`
   ([:79-89](backend/Modules/Operations/DemandAnalysis/Application/Services/DemandReadRepository.php:79)),
   and `deleteProductDemandNotIn` prunes rows the recalculation no longer produces
   ([:162-169](backend/Modules/Operations/DemandAnalysis/Application/Services/DemandReadRepository.php:162)).
   Wave 2's Required is computed from scratch from Wave 2's memberships.
4. **Postponed rows are already excluded symmetrically** — from Required *and* from the
   reservation netting ([MaterialDemandCalculator:254-262](backend/Modules/Operations/DemandAnalysis/Application/Services/MaterialDemandCalculator.php:254)).
   Pinned by `WavePostponeOrderTest::test_postponed_order_is_excluded_from_product_demand_aggregation`
   and `…_from_missing_materials_join`.

### 17.2 The one real risk

`MaterialDemandCalculator` reads `available_qty` from **global warehouse inventory**, not
from a per-wave allocation. If an order were **active in two waves simultaneously**, both
waves would count its Required against the *same* available stock, and both would under-report
`missing_qty`. This is not a fan-out bug — it is over-commitment, and it is exactly why the
one-active-membership invariant (§8, §10) is load-bearing rather than cosmetic.

`ownWaveMaterialReservations` is structurally immune either way: it joins `pwo` scoped to
one wave, so each wave nets only its own orders' reservations.

**`TASK-PREPARATION-RESERVATION-DEMAND-CONSISTENCY-001` remains deployed and unchanged.
No formula was touched, and none needs to be.**

---

## 18. Confirmed Contracts

### 18.1 CATEGORY A — CONFIRMED EXISTING CONTRACT (binding; must be preserved)

| ID | Contract | Authority |
|---|---|---|
| **A-1** | Fulfilment eligibility = `{in_progress, confirmed}` — a closed list. Unknown/future statuses are NOT eligible | `OrderStatus::fulfilmentEligible()`; re-derived by `PreparationSessionPolicy` and `config/distribution.php`; ADR-042 §7 |
| **A-2** | `ready_for_dispatch` **may not re-enter preparation** | `PreparationEntryGateTest::test_ready_for_dispatch_is_refused_even_when_reserved` |
| **A-3** | `awaiting_stock` may not enter preparation, even when reserved | `PreparationEntryGateTest`, `PreparationBypassGuardTest` |
| **A-4** | An order may be in **at most one ACTIVE wave** per company | `uq_prep_wave_orders_company_order`, `OrderExclusivityTest` ×6, B-2/B-3/B-4 |
| **A-5** | Membership rows are **never deleted** on close/complete/rotate — history is retained | `closeWave` writes only wave columns; `detachOrder` has no production caller |
| **A-6** | `postponed_at` = *this membership left the current cycle*. It releases nothing, is never cleared, and is a **membership** state, not an order-lifecycle one | `WaveMembershipService:168-183`; `WavePostponeOrderTest` ×14 |
| **A-7** | Postponed memberships contribute **zero** demand and zero loading allocation | `ProductDemandCalculator:34`, `MaterialDemandCalculator:294`, `AutoAllocationService::active()`; 4 pinned tests |
| **A-8** | Same-wave attachment is idempotent via `uq_preparation_wave_orders_wave_order`; races are absorbed by the DB | `attachOrder:99-101`; `WaveIdempotencyTest` |
| **A-9** | Product-level Prepared is **operator-owned** and survives demand rebuilds; completion is an **explicit, reversible declaration**, never inferred from quantity | `upsertProductDemand` update-list; `completePreparation`/`uncompletePreparation`; migration docblock |
| **A-10** | Completion is auto-invalidated when that product's Required moves | `clearCompletionWhereRequiredChanged:112-146` |
| **A-11** | `ready_for_dispatch → out_for_delivery` is owned **exclusively** by vehicle dispatch | `LoadVehicleWorkflow:94`; `HandlePreparationWaveCompleted` docblock |
| **A-12** | Returning an order for re-preparation (`ReturnToProcessingWorkflow`) **does not release inventory**; unlocking it for editing (`ReturnToPendingWorkflow`) **does** | The four callers of `ReleaseOrderInventoryAction` |
| **A-13** | Wave is canonical over Session for the operational cycle, membership, demand and execution | §6 |

### 18.2 CATEGORY B — CONFIRMED TECHNICAL FACT (defect or dead code; no decision needed to *state* it)

| ID | Fact | Evidence |
|---|---|---|
| **B-1** | The collector's exclusion is unscoped — any order that ever joined any wave is excluded from every future wave | `attachEligibleOrders:40-44` |
| **B-2** | `orders.preparation_completed_at` has **1 dead writer and 0 readers**; runtime count = 0 | §5.1 |
| **B-3** | `WaveCompleted` never fires on the engine path; `WaveClosed` has no order-side listener | §5.2 Cause A |
| **B-4** | `where('status','preparing')` matches zero rows — `preparing` is not an `OrderStatus`; `orders.status` is `varchar`, so it fails silently | §5.2 Cause B |
| **B-5** | Engine waves have **zero** `preparation_wave_items` — `GenerateDemandAction` requires `Draft` | runtime `COUNT(*) = 0` |
| **B-6** | **No `PreparationInventoryReservation` is ever created on the engine path**; `totalReserved()` has no production reader | runtime `COUNT(*) = 0`; `StartPreparationAction:161` |
| **B-7** | Stale waves are unreachable by the scheduler — Step 4 only sees today's wave | `RunWaveSchedulerCommand:87-91` |
| **B-8** | `auto_move_to_preparing = 0` strands every wave in `Collecting` forever | live config + 3 stranded waves |
| **B-9** | A manually-`advance`d past-dated wave is permanently invisible to the engine | `PREP-202608-000001`: `planning_date` 08-12, `started_at` 08-13 |
| **B-10** | `WaveLifecycleService:137-142`'s stranded-wave clamp is unreachable | its only caller always passes today's wave |
| **B-11** | The CHECK constraint on `wave_engine_configurations` makes cross-day windows **impossible**; live config is at the extremes (`00:00 / 23:59 / 23:59:59`) | migration :45 + runtime |
| **B-12** | `preparation_waves` has **no scheduled `ends_at`** — only `planning_date` + event stamps | `SHOW COLUMNS` |
| **B-13** | `companies.timezone = 'Africa/Cairo'` for all companies, read by **nothing** in Operations | runtime + grep |
| **B-14** | Reservations are **order-scoped**; no reservation record carries a wave or session id | `stock_ledger_entries` reference types |
| **B-15** | `PreparationWaveController:799-810` excludes only `completed`/`cancelled` — an order in a **closed** wave is already wrongly reported "already in an active wave" | :803 |
| **B-16** | `wave_engine_configurations` has **no API and no UI** | zero controllers/routes/frontend refs |
| **B-17** | `2026_07_08_910002…::down()` has an inverted guard and can never drop its column | :24-26 |
| **B-18** | `2026_07_06_120200…::down()` uses MySQL-only `ALTER TABLE … DROP INDEX`; will fail on PostgreSQL (production) | :23-27 |
| **B-19** | Session `auto_close_time` is configurable and validated but has **no consumer**; `closeSession()` has no caller. 19 sessions, all still `draft` | grep + runtime |
| **B-20** | 4 wave-scoped consumers omit the `postponed_at` filter (`productQueue:416`, `productWorkspace:538`, `HandlePreparationWaveCompleted:25`, `PreparationEnterpriseController:59,163`) | §7.1 Group A |
| **B-21** | `HandlePreparationWaveCancelled` returns **all** wave orders including postponed ones, with no completion test | `CancelWaveAction:41` |
| **B-22** | `MoveToPreparationWorkflow`'s docblock ("when all work is done") contradicts its only wave caller (fired at start) | §3.1 |

---

## 19. Contract Gaps — OWNER DECISION REQUIRED

Four. Everything else is resolved.

### G-1 — BLOCKER. What does "this order is fully prepared" mean?

The system has a **product × wave** completion fact and **no order-level one**. Carry-over
(§16 clause 5) and wave-end return (§15.5) both require an order-level answer.

Options: **5a** product-derived · **5b** quantity-derived · **5c** no completion test.
Trade-offs in §16.2. All three are business policy.

**Nothing else in the Cross-Day task can be finalised until this is answered.**

### G-2 — Which timezone is authoritative for the Preparation operational day?

Four clocks (§14). `companies.timezone = Africa/Cairo` is declared and ignored;
`wave_engine_configurations.timezone` defaults to `'UTC'` and is the only one the engine
reads. Engineering recommends config-at-warehouse-grain, seeded from company. Owner must
confirm — this shifts every wave boundary by 2–3 hours for existing data.

### G-3 — Should stranded waves be auto-closed, and what happens to their orders?

Today they are deliberately left alone (§12). Three sub-decisions:
(a) auto-close on rollover, yes/no; (b) if yes, at what boundary (§13.3 element 3 makes
`now() >= ends_at` possible); (c) do their orders return (§15) or stay put.
Note: 3 of 5 live waves are stranded right now.

### G-4 — Which return path does wave-end carry-over use?

`ReturnToProcessingWorkflow` (keeps the reservation — §11.3) or `ReturnToPendingWorkflow`
(releases it, nulls `confirmed_at` and all reservation fields — §11.4). Engineering
recommends the former; it is a stock-commitment policy decision, not an implementation
detail.

### Previously-open items now CLOSED

| Was | Now |
|---|---|
| G6 — two "active wave" definitions | **CLOSED.** Two audiences, both correct (§2.2) |
| G7 — Wave vs PreparationSession | **CLOSED.** Wave canonical; Session dormant (§6) |
| G8 — reservation lifecycle across days | **CLOSED — SAFE**, conditional on G-4 (§11) |
| G3 — `postponed_at` semantics | **CLOSED by preserving it.** Do not redefine it; add a separate release stamp (§9) |

---

## 20. Recommended Architecture

**Architecture only. No code. No migration.** Every item is conditional on §19.

### 1. Wave operational cycle
`PreparationWave` is the canonical operational cycle and the canonical preparation-execution
unit. One per `(company, warehouse, planning_date)`. `PreparationSession` stays dormant;
do not extend it.

### 2. Cross-day timestamps
Add a per-phase **day offset** to `wave_engine_configurations` and replace the CHECK with an
`(offset, time)` comparison. Materialise `collection_starts_at` / `preparation_starts_at` /
`ends_at` as `timestampTz` on `preparation_waves` at creation.

### 3. Wave lifecycle
Drive transitions from the materialised timestamps (`now() >= ends_at`), **not** from
`planning_date == today`. This lets the scheduler act on any open wave, closing §12 by
construction.

### 4. Intake cutoff
`collection_starts_at → preparation_starts_at`. No new field: the cutoff is already the
emergent behaviour of Steps 1–2, and rotation already creates the next cycle's wave.

### 5. End
`ends_at`. Closure becomes a wave-independent sweep over `status ∈ {collecting, preparing}
AND now() >= ends_at`, not a lookup of today's wave.

### 6. Historical membership
Retain every `preparation_wave_orders` row forever (A-5). No backfill; runtime volume is 3
rows.

### 7. Active membership
`postponed_at IS NULL AND released_at IS NULL` — row-local, independent of wave status and
order status (§8.2). Home: `PreparationWaveOrder::scopeActive()`.
Enforced by `UNIQUE (company_id, order_id, <null-when-active discriminator>)`, with
`uq_preparation_wave_orders_wave_order` **preserved unchanged** (§10.3, §17.1).
Teach B-1 … B-4 the predicate. Rewrite `OrderExclusivityTest` and
`PreparationEntryGateTest::test_duplicate_preparation_entry_remains_blocked` to assert
one-**active**-membership.

### 8. Carry-over
Predicate of §16.2, clauses 1–4 fixed, clause 5 per **G-1**. Postponed memberships are never
carried over (A-6/A-7).

### 9. Preparation completion
`wave_product_demand.preparation_completed_at` remains the canonical fact. **Do not add an
order-level column** — derive per G-1. `orders.preparation_completed_at` should be either
correctly written or **dropped**; a column with one dead writer and zero readers is worse
than none.

### 10. Reservation safety
No change. Reservations are order-scoped and survive the wave boundary (§11). Carry-over
must use the non-releasing return path (**G-4**). `MoveToPreparationWorkflow`'s existing
guard already prevents a second reservation.

### 11. Stale wave closure
Per **G-3**. If yes: a sweep independent of `$activeWave`, keyed on `ends_at`, plus the
missing manual close endpoint (today only `complete`/`cancel` exist).

### 12. Timezone
Per **G-2**. Recommended: `wave_engine_configurations.timezone` authoritative, seeded from
`companies.timezone`; unify `WaveLifecycleService:138` onto it. Note that §20.2's
`timestampTz` columns make most of this moot — a stored absolute instant has no timezone
ambiguity.

### Ordering constraint

```
G-2 (timezone)   ──┐
G-3 (stale waves)──┼──▶ items 2,3,5,11,12   (time model — independently implementable)
                   │
G-1 (completion) ──┴──▶ items 7,8,9         (membership + carry-over — BLOCKED on G-1)
G-4 (return path)──────▶ items 8,10
```

The **time-model half (2, 3, 5, 11, 12) is not blocked by G-1** and could be scoped as an
independent task. The membership/carry-over half cannot start until G-1 is answered.

---

## 21. Implementation Readiness

| Area | Ready? | Blocker |
|---|---|---|
| Time model / cross-day windows | ⚠️ **Ready after G-2 + G-3** | Owner decisions only; design settled (§13.3) |
| Stale wave closure | ⚠️ **Ready after G-3** | — |
| Timezone unification | ⚠️ **Ready after G-2** | Shifts existing wave boundaries — needs a data statement |
| Historical membership + unique relaxation | ⚠️ **Design ready, gated** | Depends on active-membership definition, which depends on G-1's return semantics |
| Active-membership predicate | ⚠️ **Design ready, gated** | Same |
| Carry-over predicate | ❌ **NOT ready** | **G-1** — clause 5 undefined |
| Wave-end order return | ❌ **NOT ready** | **G-1** + **G-4** |
| `preparation_completed_at` repair | ❌ **NOT ready** | Repairing R-1 alone is useless (Cause A). What should happen at wave close is **G-3/G-4** |
| Reservation handling | ✅ **No change required** | — |
| Material demand | ✅ **No change required** | — |

**Known collateral for whoever implements the membership half:**
`OrderExclusivityTest` (6 methods) and
`PreparationEntryGateTest::test_duplicate_preparation_entry_remains_blocked` **will fail by
design** and must be rewritten. Both encode A-4 in its current absolute form.

**Independently fixable, no decision needed** (B-15, B-17, B-18, B-20, B-21, B-22, and the
`WaveManager`/`WaveStatus` comment from §2.2). None were fixed here.

---

## 22. Stop Conditions

| PART | Stop condition | Encountered? | Action |
|---|---|---|---|
| 1 | Do not propose a new column if a canonical fact exists | **Yes** | Reported `wave_product_demand.preparation_completed_at`; proposed no new completion column |
| 1 | Do not treat `ready_for_dispatch` as proof of completion | **Yes** | §3.1 documents the three-way contradiction; it is explicitly refused as evidence |
| 2 | Mechanical repair obvious → record, do not fix | **Yes** | R-1 … R-4 recorded; **no file changed** |
| 7 | Do not create a column | **Yes** | §9 evaluates options A–G; nothing created |
| 8 | Do not implement | **Yes** | §10 gives a design; **no migration written** |
| 9 | Do not modify reservation code | **Yes** | Read-only trace |
| 10 | Do not fix stale-wave closure | **Yes** | §12 diagnoses; nothing repaired |
| 11 | Do not implement cross-day model | **Yes** | §13 gives minimum change only |
| 12 | Do not change configuration | **Yes** | Timezone divergence reported; nothing changed |
| 13 | Do not invent a transition | **Yes** | §15 uses only existing workflows and guards |
| 15 | Do not change formulas / re-run RESERVATION-DEMAND-CONSISTENCY | **Yes** | §17 is analysis only |
| 16 | Do not choose business policy | **Yes** | G-1…G-4 left open; options presented with trade-offs |
| 19 | Runtime testing requiring mutation → STOP and report | **Not required** | All evidence from `SELECT`/`SHOW`. No writes, no migrations, no `migrate:fresh`, no artisan mutation |

**Reported rather than acted on:** ORD-00001, ORD-00003 and ORD-00004 are locked to a
2026-08-12 wave that can never rotate; 3 of 5 waves are stranded. Repairing the live data
would require writes and is outside this task's mandate.

---

## 23. Final Verdict

### **CONTRACT PARTIALLY RESOLVED — 4 OWNER DECISIONS REQUIRED**

**Resolved from code and runtime (no further investigation needed):**

- Preparation completion authority — `wave_product_demand.preparation_completed_at`,
  product-grain, operator-declared, reversible (§4).
- `orders.preparation_completed_at` — fully audited: one dead writer, **zero readers**,
  **two** independent causes of failure (§5).
- Wave vs Session canonicity — **Wave**, decided by mounted UI, demand ownership, Loading
  coupling, and runtime data (§6).
- Historical membership safety — complete 18-site caller inventory; 14 immune, **4 must
  change**, all in Preparation (§7).
- Active-membership definition — row-local `postponed_at IS NULL AND released_at IS NULL`;
  wave status **proven** unusable (§8).
- Membership release mechanism — a nullable release stamp, exactly mirroring
  `preparation_session_orders.detached_at`, which already works in production data (§9).
- Minimum schema change — a null-when-active discriminator, portable across MySQL and
  PostgreSQL, preserving same-wave idempotency (§10).
- **Reservation-across-waves — SAFE.** Reservations are order-scoped; the return path
  deliberately does not release; no double reservation is possible (§11).
- Stale-wave mechanism — mechanically *and* with the live cause identified (§12).
- Cross-day time model — **structurally forbidden** by a CHECK constraint; minimum change
  specified (§13).
- Material demand — **no double-count**, proven from queries and constraints (§17).

**Open — business decisions only:**

1. **G-1 (BLOCKER)** — what "this order is fully prepared" means at order grain.
2. **G-2** — authoritative timezone.
3. **G-3** — stale-wave auto-closure and its order consequence.
4. **G-4** — which return path carry-over uses.

**Assessment.** The membership and schema half of the Cross-Day task is now fully specified
and low-risk: the constraint change is portable, the consumer list is closed at four sites,
and demand and reservations are provably unaffected. The lifecycle half still rests on a
single missing fact — an order-level notion of "prepared" — and that remains a business
definition, not an engineering choice.

The **time-model half (§20 items 2, 3, 5, 11, 12) is unblocked by G-1** and could be issued
as its own implementation task once G-2 and G-3 are answered.

---

**Nothing was implemented. No production code changed. No migration written. No schema
changed. No data modified.**

**STOPPED as instructed.** Not continuing to
TASK-PREPARATION-WAVE-CROSS-DAY-TRANSITION-001, Loading, Vehicle, Driver, Delivery,
Settlement, or Route Optimization. Awaiting owner review of G-1 … G-4.
