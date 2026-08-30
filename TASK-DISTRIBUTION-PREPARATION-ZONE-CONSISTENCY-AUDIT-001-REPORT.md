# TASK-DISTRIBUTION-PREPARATION-ZONE-CONSISTENCY-AUDIT-001

**Type:** Audit only — read-only. No code, frontend, backend, database, configuration or business-data change. No commit. No deploy.
**Date:** 2026-08-23
**Environment:** `ecos_dev`, active wave `PREP-202608-000006` (`collecting`)

---

## 1. Executive Summary

**The count difference is real, it has exactly one cause, and the previous "this is normal" reading was wrong.**

The browser figures reconcile perfectly against the database:

```
Preparation wave members (active)          12
  − ORD-00017 (never reached Distribution)  1
                                          ────
Distribution eligible                      11   ✅
  − ORD-00001 (no zone → Unassigned)        1
                                          ────
Assigned                                   10   ✅   Zones tab: 11 ✅
```

**The single Preparation-only order is ORD-00017.** Two further orders — ORD-00013 and ORD-00014 — are the mirror defect: they are in Distribution but in **no wave at all**.

**The root cause is not what it appears to be.** The obvious hypothesis — "Preparation and Distribution use two different eligibility rules" — was tested adversarially and **refuted**. A single canonical helper exists, `OrderStatus::fulfilmentEligible() = ['in_progress','confirmed']`, and *every* predicate on the path derives from it. Sixteen call sites, one source.

The actual defect is a **timing asymmetry in how that one predicate is applied**:

> **Distribution re-evaluates the status on every read. Preparation evaluates it once, at admission, and has no eviction path.** Any order that is fulfilment-eligible for even a moment becomes a permanent wave member until it is postponed, released, or the wave closes.

ORD-00017 was eligible for **17 seconds**. The per-minute wave collector ticked inside that window.

A second finding compounds it: ORD-00017's `logistics_city_id` is NULL **because** it is `awaiting_payment` — the city binder gates on the *same* status list. The geography symptom is a consequence of the status cause, not a rival explanation.

**On the inline-editing requirement:** the backend chain for City/Governorate already exists and is correct end-to-end. What is missing is entirely on the Distribution UI side. For Zone, the authoritative service and its HTTP route both exist — `ManualAssignmentService::changeOrderZone()` behind `PATCH /assignments/{id}/zone` — and have **zero frontend callers**. There is also a decoy: `PATCH /orders/{order}/zone` writes free text only and would not move the counter.

---

## 2. Count Mismatch Analysis

| Surface | Count | Source of truth | Query |
|---|---|---|---|
| Preparation | **12** | `preparation_wave_orders` where `released_at IS NULL` | `WaveDemandController::waveOrders` (`:1124-1158`) — filters **only** `pwo.postponed_at IS NULL`; **no `orders.status` predicate at all** |
| Distribution eligible | **11** | `distribution_window_orders` ∩ wave, warehouse-scoped | `DistributionAggregationService::orders()` via `PreparationEligibilityReader::constrainToEligible()` (`:89-98`) |
| Assigned | **10** | `dwo.distribution_zone_id IS NOT NULL` | `DistributionAggregationService.php:695` |
| Unassigned | **1** | `dwo.distribution_zone_id IS NULL` | `zoneSummaries()` (`:88-119`) groups by zone id **including NULL** |
| Zones | **11** | same pool | `GET /windows/current` → `zoneSummaries()` |

Live totals: 19 orders · 12 active wave members · 13 `distribution_window_orders` rows (12 zoned + 1 unzoned) · 10 active zones.

**The 13 vs 11:** two Distribution rows (ORD-00013, ORD-00014) are excluded from the warehouse-scoped workspace by `DistributionAggregationService::scopeWarehouse()` (`:398-405`), because both have `assigned_warehouse_id IS NULL`.

---

## 3. Full Order Reconciliation

Every order, read-only:

| Order | Status | Method | Paid/Total | Verified proof | In wave | In Distribution | Zone | `logistics_city_id` | City text | Warehouse | Reason |
|---|---|---|---|---|---|---|---|---|---|---|---|
| ORD-00001 | in_progress | cod | 188/188 | 0 | **YES** | **YES** | **—** | NULL | — | set | **Included, UNASSIGNED** — no city text at all (`area`='Old Cairo' only) → `address_incomplete` |
| ORD-00002 | in_progress | cod | 0/113 | 0 | YES | YES | DZ-0007 | 2 | Maadi | set | Common |
| ORD-00003 | awaiting_payment | instapay | 10000/10000 | 1 | no | no | — | NULL | — | — | Not eligible (status); no warehouse |
| ORD-00004 | awaiting_payment | instapay | 0/10000 | 0 | no | no | — | NULL | — | — | Not eligible (status) |
| ORD-00005 | awaiting_payment | instapay | 3000/10000 | 1 | no | no | — | NULL | — | — | Not eligible (status) |
| ORD-00006 | in_progress | cod | 0/113 | 0 | YES | YES | DZ-0007 | 2 | Maadi | set | Common |
| ORD-00007 | in_progress | cod | 0/199.11 | 0 | YES | YES | DZ-0009 | 23 | Obour City | set | Common |
| ORD-00008 | awaiting_payment | cod | 0/111.11 | 0 | no | no | — | NULL | Maadi | set | Not eligible (status) — legacy row, predates the payment work |
| ORD-00009 | in_progress | cod | 0/718.55 | 0 | YES | YES | DZ-0002 | 1 | Nasr City | set | Common |
| ORD-00010 | in_progress | cod | 0/310.22 | 0 | YES | YES | DZ-0008 | 7 | Helwan | set | Common |
| ORD-00011 | in_progress | cod | 0/199.11 | 0 | YES | YES | DZ-0001 | 9 | New Cairo | set | Common |
| ORD-00012 | in_progress | cod | 0/199.11 | 0 | YES | YES | DZ-0002 | 1 | Nasr City | set | Common |
| ORD-00013 | in_progress | cod | 0/171.11 | 0 | **no** | **YES** | DZ-0003 | 40 | Faisal | **NULL** | **DISTRIBUTION-ONLY** — no warehouse ⇒ no wave can collect it |
| ORD-00014 | in_progress | cod | 0/171.11 | 0 | **no** | **YES** | DZ-0003 | 43 | Giza City Center | **NULL** | **DISTRIBUTION-ONLY** — same |
| ORD-00015 | awaiting_payment | instapay | 150/150 | 0 | no | no | — | NULL | — | — | Not eligible (status) |
| ORD-00016 | in_progress | cod | 0/199.11 | 0 | YES | YES | DZ-0002 | 1 | Nasr City | set | Common |
| **ORD-00017** | **awaiting_payment** | mobile_wallet | 0/199.11 | 0 | **YES** | **no** | — | **NULL** | Nasr City | set | **PREPARATION-ONLY** — see §4 |
| ORD-00018 | in_progress | cod | 0/199.11 | 0 | YES | YES | DZ-0002 | 1 | Nasr City | set | Common |
| ORD-00019 | **confirmed** | cod | 0/199.11 | 0 | YES | YES | DZ-0002 | 1 | Nasr City | set | Common |

### The diff

- **Preparation-only (1):** `ORD-00017`
- **Distribution-only (2):** `ORD-00013`, `ORD-00014`
- **Common (11):** ORD-00001, 00002, 00006, 00007, 00009, 00010, 00011, 00012, 00016, 00018, 00019

ORD-00018 and ORD-00019 are healthy — both present on both sides, both zoned DZ-0002. ORD-00019 being `confirmed` rather than `in_progress` is correct; both are in `fulfilmentEligible()`.

---

## 4. ORD-00017 Analysis

**In Distribution? No — it has no `distribution_window_orders` row at all.** **Zone? None.**
**Payment:** `mobile_wallet`, `deposit_amount 0.00 / total 199.11`, **zero payment proofs**, unpaid and unproven. Under D1-A `mobile_wallet` resolves `required`, so `awaiting_payment` is the correct order state.

### Why it entered Preparation — proven by timestamps

`order_events.created_at` runs +3h against the `orders`/`preparation_wave_orders` clock (confirmed on every row). Normalised to the wave clock:

```
21:49:36  order_created                       → awaiting_payment
21:49:47  field_updated  instapay → cod
21:49:48  confirm_order  awaiting_payment → confirmed     ◄── becomes eligible
21:50:00  ADDED TO WAVE  (preparation_wave_orders.added_at)   ◄── the per-minute tick lands here
21:50:04  field_updated  cod → mobile_wallet
21:50:05  return_to_payment  confirmed → awaiting_payment  ◄── ceases to be eligible
```

The wave collector runs **every minute** — `routes/console.php:58-61`, `Schedule::command('wave:run-scheduler')->everyMinute()` → `RunWaveSchedulerCommand.php:146-147` → `WaveMembershipService::attachEligibleOrders()`. The 21:50:00 tick fell inside a **17-second** window during which ORD-00017 was genuinely `confirmed`. **The admission was correct.** Nothing has re-tested it since.

By contrast Distribution collection is **not scheduled at all** — `collectForCompany()` has one production caller, the Refresh button (`DistributionWindowController.php:139-147`). That asymmetry is why the wave caught the 17 seconds and Distribution never did.

### Why it never reached Distribution — the ingestion gate

`DistributionCollectionService::eligibleUnassignedOrders()` (`:360-368`):

```php
$query = DB::table('orders')
    ->where('orders.company_id', $companyId)
    ->whereIn('orders.status', $statuses)      // ← line 362 — the only rejecting predicate
    ->whereNull('orders.deleted_at')
    ->whereNotExists(... 'distribution_window_orders as dwo' ...);
```

The row was never created. The read-side predicate (`PreparationEligibilityReader::constrainToEligible()`) would also reject it, so the exclusion is over-determined — but the evidence shows the row simply does not exist.

### The competing explanation — refuted, and inverted

The hypothesis "it is excluded because `logistics_city_id` is NULL" is **wrong**, three ways:

1. **No city predicate exists.** Collection attaches regardless — `DistributionCollectionService.php:125-127` resolves a null zone and attaches anyway at `:135-144`. `distribution_zone_id` is nullable by design (`2026_08_11_100003…:44`). The read joins to the city tables are **LEFT** joins (`:530-531`).
2. **ORD-00001 disproves it directly.** It also has NULL city and NULL `logistics_city_id`, and it **is** in the pool — as the single Unassigned row. The permanent "Unassigned" tab exists for exactly this population.
3. **The decisive inversion.** `OrderCityBinder` gates on the *same* status list — `:52,60-64`, `whereIn('status', config('distribution.eligible_order_statuses'))` + `whereNull('logistics_city_id')`. ORD-00017 was never a binder candidate, which is why its `'Nasr City'` was never resolved while ORD-00016/18/19 with identical text bound to city 1. **The geography symptom is downstream of the status cause.**

### Why membership was never re-evaluated

`ReturnToPaymentWorkflow::events()` returns `[]` (`:77-80`) — **no domain event is emitted**. Only two `Order` observers exist; the relevant one, `OrderPreparationObserver`, does fire on `wasChanged(['status', …])` (`:34`) and detaches via `DailyPreparationSessionManager::detachOrder` (`:74-78`) — but that writes **`preparation_session_orders.detached_at`, a different table**. `WaveMembershipService::detachOrder()` exists (`:149`) and has **zero callers outside its own class**.

**The eviction machinery exists and is wired to the wrong stack.**

---

## 5. Canonical Fulfilment Eligibility

**A single source of truth exists.** `OrderStatus::fulfilmentEligible()` (`:147-153`) → `['in_progress','confirmed']`, docblock at `:138-140`: *"Statuses that Preparation, Distribution and the Wave Engine treat as fulfilment-eligible (ADR-042 §7)."*

Complete inventory — 16 sites, all derived:

| # | Site | file:line | List | Source |
|---|---|---|---|---|
| 1 | **Canonical helper** | `OrderStatus.php:147` | `in_progress`, `confirmed` | SSOT |
| 2 | Preparation session policy default | `PreparationSessionPolicy.php:79` | same | derived from #1 |
| 3 | Preparation release engine | `PreparationReleaseEngine.php:38-43` | policy column or #2 | — |
| 4 | Session daily collector | `DailyPreparationSessionManager.php:100-105` | same | — |
| 5 | **Wave admission** | `WaveMembershipService.php:44` | `wave_engine_configurations.eligible_order_statuses` — live value `["in_progress","confirmed"]`, normalised by migration `2026_08_13_100000…:63,102`, not editable via the API | DB column |
| 6 | Wave HTTP entry gate | `PreparationWaveController.php:760-796` | delegates to #3 — *"Reused, never reimplemented."* | — |
| 7 | Distribution config | `config/distribution.php:57` | same | derived from #1 |
| 8 | Loading config | `config/distribution.php:93-99` | #1 **+ `ready_for_dispatch`** | documented superset |
| 9–13 | Distribution read / ingestion / triage predicates | `PreparationEligibilityReader.php:89,133,150`; `DistributionCollectionService.php:351-377`; `DistributionAggregationService.php:786-807` | #7 (or #8 for Loading) | config |
| 14 | **City binder** | `OrderCityBinder.php:52` | #7 | config |
| 15 | Fulfilment workflow guard | `MoveToPreparationWorkflow.php:42` | #1 directly | — |
| 16 | Payment re-evaluation | `ReevaluateOrderFulfillmentAction.php:125` | #1 directly | — |

**Restatements (same values, hand-written):** `DemandAnalysisService.php:30-33`; `PatchOrderAction.php:57` (different question, ADR-023 snapshot).

**The one genuinely divergent predicate — and it is off the path:** `DistributionPlanningController.php:17`, `private const READY_STATUSES = ['confirmed', 'preparing'];`. `'preparing'` is **not an OrderStatus case** and `'in_progress'` is missing, so it matches almost nothing. `module-navigation.ts:260-266` records that the canonical link deliberately bypasses that screen (*"its three endpoints all return 500… it carries a second zone resolver… no tenant filter"*). It cannot produce the 11/10/1 figures.

**Verdict: do not build a third eligibility implementation. There is one, and it is correct.**

---

## 6. Preparation Source

`WaveMembershipService::attachEligibleOrders()` (`:33-70`) is the only engine-path admission query:

```php
$orders = Order::where('company_id', $wave->company_id)
    ->where('assigned_warehouse_id', $wave->warehouse_id)     // ← excludes ORD-00013/00014
    ->whereIn('status', $config->eligible_order_statuses)
    ->whereNotExists(fn ($q) => $q->from('preparation_wave_orders')
        ->whereColumn('preparation_wave_orders.order_id', 'orders.id')
        ->whereNull('preparation_wave_orders.released_at'))
    ->get();
```

**Every way an active membership can end:**

| Mechanism | Writes | Trigger |
|---|---|---|
| `WaveLifecycleService::closeWave()` (`:135-137`) | `released_at` on every unreleased row | **time only** — `hasReachedEnd()` |
| `WaveMembershipService::detachOrder()` (`:155-157`) | hard DELETE | **NO PRODUCTION CALLER** |
| `RecalculateWaveAction` (`:50-52`) | hard DELETE | operator, and only while wave is Draft/Planning |
| `postponeOrder()` (`:202-205`) | `postponed_at` — row **stays** an active membership | operator HTTP |
| `active_membership = 0` | **impossible** — generated column `CASE WHEN released_at IS NULL THEN 1 ELSE NULL END` | — |

**Nothing listens for an order status change.** The wave read (`WaveDemandController::waveOrders`, `:1124-1158`) filters only `postponed_at IS NULL` — which is why ORD-00017 still counts toward 12. The denormalised `preparation_waves.orders_count` is moved only by attach/detach/postpone, never by a status change.

Snapshot columns (`delivery_zone_snapshot`, `governorate_snapshot`, `is_paid`, `payment_status_snapshot`) are written once at admission and never refreshed — every live row has `is_paid = 0` and `payment_status_snapshot = NULL`, including ORD-00001 which is fully paid.

---

## 7. Distribution Source

- **Ingestion (write):** `DistributionCollectionService::collectForCompany()` → `eligibleUnassignedOrders()` (`:351-377`) → `attach()` (`:242`). Status-filtered at write time. **Not scheduled** — only the Refresh button.
- **Read:** `DistributionAggregationService::orders()` (`:523`) / `zoneSummaries()` (`:90`), both through `PreparationEligibilityReader::constrainToEligible()` (`:89-98`), which re-applies the status filter **on every read**. The reader's own docblock (`:74-84`) explains why: *"Collection filters status itself and then never looks again… Requirement C ('no longer eligible → disappears') needs the status re-checked at read time, because that is the only moment a later status change is observed."*

**That docblock is the whole finding in one sentence — Distribution solved this problem; Preparation did not.**

- **Warehouse scope:** `scopeWarehouse()` (`:398-405`), driven by the workspace passing `warehouse_id` (`distribution-workspace-service.ts:105`).

---

## 8. Zone Source

**One column holds an order's zone: `distribution_window_orders.distribution_zone_id`.** Complete set of writers — 4 sites, 2 classes:

| # | Site | Writes |
|---|---|---|
| W1 | `DistributionCollectionService.php:242` `attach()` | initial stamp at collection (`auto`) |
| W2 | `DistributionCollectionService.php:331-338` `reconcileUnzoned()` | NULL → resolved only; **never moves a zoned order** |
| W3 | **`ManualAssignmentService.php:253-259` `changeOrderZone()`** | any → any, including null (`manual_move`) |
| W4 | `ManualAssignmentService.php:427-436` `assignLateOrder()` | re-stamp on window move (`manual_late`) |

Nothing else writes it — no seeder, command, job, observer or migration.

**`ManualAssignmentService::changeOrderZone()` EXISTS and is the approved path.** In one transaction it sets `distribution_zone_id`, recomputes `virtual_slot_id` from the destination zone's group **for that order's warehouse** (`:227-235`), enforces `GroupCapacityGuard::assertHasHeadroom()` (`:243-252`), and stamps `assignment_source`/`assigned_by`/`assignment_reason`. Blocked only by a **Closed** window (`assertManualAllowed`, `:448-455`) — cutoff deliberately passes.

**Zone resolution from geography:** `logistics_city_id` → `logistics_cities.distribution_zone_id` via `OrderZoneResolver::resolve()` (`:22-33`).

**Zone counters:** `GET /logistics/distribution/windows/current` (`api.php:1681`, `permission:logistics.distribution.view`) → `DistributionAggregationService::zoneSummaries()` (`:88-119`), which groups by `dwo.distribution_zone_id` including NULL — that NULL group is the "Unassigned" tab.

**`DistributionAssignmentChanged` is dispatched (`:261`) but has NO listener** — the only registered mapping is `OrderGeographyChanged => SyncOrderGeographyListener`.

---

## 9. Geography Architecture

**Canonical field: `orders.logistics_city_id`.** Exactly two runtime writers exist:

1. **`OrderCityBinder::bindForCompany()`** — the automatic sweep. **NULL-only, with two guards**: `whereNull('logistics_city_id')` in the candidate filter (`:64`) and re-asserted inside the UPDATE (`:85-89`). Also status-filtered (`:52,60-64`).
2. **`OrderCityBinder::rebindOrder()`** — reachable **only** from `SyncOrderGeographyListener` on an explicit `OrderGeographyChanged` event. **Not NULL-only; it overwrites, including to null.**

**The contract is explicit in the source** (`OrderCityBinder.php:101-103, 180-190`):

> *"an already-bound Order is never re-examined and never re-written, so a later geography edit cannot silently move an Order that operators have already planned around"* — governing the **automatic** pass — and, for `rebindOrder()`: *"This method is the opposite situation: an operator has just changed this Order's city ON PURPOSE. Nothing is silent, nothing is a sweep."*

Regression test proving the sweep guard: `DistributionOrderGeographySyncTest.php:238-253`.

**The full explicit-edit chain, which already exists and works:**

```
PATCH /orders/{id}/quick-update  (or PUT /orders/{id})  with city / governorate
  → PatchOrderAction::announceGeographyChange (:269-305)   [only fires if city or governorate actually changed]
  → event OrderGeographyChanged
  → SyncOrderGeographyListener (:61-70)                     [registered LogisticsDistributionServiceProvider:31-42]
  → OrderCityBinder::rebindOrder      → logistics_city_id
  → OrderZoneResolver::resolve        → zone id
  → ManualAssignmentService::changeOrderZone (:75-102)      → distribution_zone_id + virtual_slot_id
```

**No second resolver.** `rebindOrder()` calls the same `OrderCityResolver` the sweep calls.

Matching is **exact** against `logistics_cities.name_en` / `name_ar` / `logistics_city_aliases.alias`, with no fuzzy matching (`OrderCityResolver.php:21-38`); ambiguity yields null (`:95-97`). `logistics_city_aliases` is documented as currently empty.

---

## 10. Inline City / Governorate Capability

**The cell is read-only.** `distribution-workspace-page.tsx:399-412` — two `<span>`s, no affordance. Its own comment says the raw text is *"what the operator has to fix"* while offering no way to fix it.

| Capability | Status |
|---|---|
| Cell renderer | EXISTS, read-only |
| Any edit affordance in the Distribution grid or drawer | **MISSING** (the drawer is mounted without its optional `onEdit` prop — `distribution-order-detail.tsx:37-43`) |
| Backend endpoint accepting `city` + `governorate` | **EXISTS ×2** — `PATCH /orders/{id}/quick-update` (`api.php:553`) and `PUT /orders/{id}` (`api.php:561`) |
| That endpoint updating `logistics_city_id` | **EXISTS** — indirectly, via the §9 event chain |
| Cascading governorate→city API | **EXISTS ×3** — logistics geography (`api.php:2916/2925`, the catalogue `logistics_city_id` actually points at), brand shipping (`api.php:362/364`), master geography (`api.php:1064/1071`) |
| Reusable cascading-select cell | **EXISTS in Orders** — `order-zone-editor.tsx` (Governorate→City popover), `order-area-cell.tsx` (auto-save with Saving/Saved/Failed states), `group-loading-preparation.tsx:283-373` (the in-workspace editable-cell precedent) |
| Generic editable-cell primitive | **MISSING** — `DataGridColumnDef` (`components/data-grid/types.ts:29-65`) has no `editable` / `editor` / `onCellChange` |
| Distribution mutation hook touching `/orders/` | **MISSING** — 15 mutations, none |
| Query invalidation | EXISTS, root-level (`use-distribution-workspace.ts:205-208`) |
| Optimistic update | **NOT FOUND** |
| Cross-feature invalidation | **MISSING** — `usePatchOrder` invalidates Orders keys only (`use-orders.ts:185`) |

### Stale-value risk — six real paths

1. **`PATCH /orders/{id}/zone`** writes `delivery_zone`/`delivery_zone_id` only. No event, no rebind.
2. **`area` without `city`** — `announceGeographyChange` returns early (`:278-282`). `OrderAreaCell` sends exactly `{area, governorate}`; if the governorate is unchanged, **no event fires and the zone goes stale**.
3. **The listener swallows its own failure by contract** (`SyncOrderGeographyListener.php:96-112`, documented `:45-51` — *"NEVER FAILS THE OPERATOR'S EDIT"*). Triggers: a Closed window, or the destination group at capacity. Result: new city text, **old zone**, HTTP 200.
4. **An unresolvable city CLEARS the zone** rather than leaving it. A free-text box that lets an operator type `Nasr city ` will un-zone a planned order; a canonical selector emitting `Nasr City` will not. **This is the strongest argument for a selector rather than a text input.**
5. **No assignment row → the write lands and nothing re-zones** (`:75-82`). This is exactly ORD-00017's shape.
6. **The status-gated sweep** never binds an ineligible order (§4).

---

## 11. Inline Zone Capability

**The cell is read-only.** `ZoneCell` (`distribution-workspace-page.tsx:247-272`) renders two `Badge`s — no `onClick`, no `Select`, no mutation. This is the cell that renders ORD-00001's "Unassigned".

| Capability | Status |
|---|---|
| Zone cell editable | **NO** |
| Authoritative service | **EXISTS** — `ManualAssignmentService::changeOrderZone()` (`:215-265`) |
| HTTP route | **EXISTS** — `PATCH /logistics/distribution/assignments/{assignment}/zone` (`api.php:1780`, `permission:logistics.distribution.update`) → `DistributionWindowController::changeZone` (`:976-997`) |
| Frontend caller for that route | **NONE — zero callers anywhere in `frontend/src`** |
| Distribution zone-change hook | **MISSING** — `use-distribution-workspace.ts` has no `useChangeOrderZone` |
| Group membership recompute on zone change | **ALREADY HANDLED** inside `changeOrderZone` (`:227-235`) |
| Audit | `assignment_source`/`assigned_by`/`assignment_reason` stamped; `DistributionAssignmentChanged` dispatched but **has no listener** |

### ⚠ The decoy — `PATCH /orders/{order}/zone` is not a zone endpoint

`OrderController::updateZone` (`:616-650`) writes **only** `orders.delivery_zone_id` + `orders.delivery_zone`. No `distribution_window_orders`, no `logistics_city_id`, no event. And `delivery_zone_id` is a **different catalogue** — `exists:config_delivery_zones,id` (a UUID, brand delivery-cost config), whereas `distribution_zones.id` is an integer.

Calling it on ORD-00001 would change a text label and leave `distribution_zone_id` NULL — **the "1 Unassigned" would not move.**

`OrderZoneEditor` currently fires both `PATCH /orders/{id}` and `PATCH /orders/{id}/zone`; only the first half does anything to zoning.

### ⚠ Permission split — a hard design constraint

| Permission | Guards | Held by |
|---|---|---|
| `sales.orders.update` | the Orders geography routes | company-admin, sales, sales-manager, sales-representative, customer-service, fulfillment-supervisor, branch-manager |
| `logistics.distribution.update` | the authoritative zone route | company-admin, dispatcher, shipping-coordinator, fleet-manager, driver |

**The two sets are disjoint except `company-admin`.** `dispatcher` — the role that actually runs the Distribution workspace — holds `sales.orders → ['view']` only, so **a dispatcher cannot edit an order's city today**. Conversely no sales role can call the authoritative zone route. Any inline-editing design must resolve this; it is an owner decision, not a technical detail.

---

## 12. Existing Services / Endpoints

| Concern | Artefact | Status |
|---|---|---|
| Wave admission | `WaveMembershipService::attachEligibleOrders()` | EXISTS |
| Wave eviction | `WaveMembershipService::detachOrder()` | EXISTS, **no caller** |
| Status-change → detach machinery | `OrderPreparationObserver` → `PreparationReleaseEngine` → `DailyPreparationSessionManager::detachOrder` | EXISTS, wired to **`preparation_session_orders`**, not waves |
| Distribution ingestion | `DistributionCollectionService::collectForCompany()` | EXISTS, manual trigger only |
| Distribution read filter | `PreparationEligibilityReader::constrainToEligible()` | EXISTS |
| Zone change | `ManualAssignmentService::changeOrderZone()` + `PATCH /assignments/{id}/zone` | EXISTS, **no UI** |
| Geography edit → re-zone | `OrderGeographyChanged` → `SyncOrderGeographyListener` → `rebindOrder` → `changeOrderZone` | EXISTS, complete |
| Governorate/city catalogues | 3 APIs + `useGovernorates`/`useCities` hooks | EXISTS |
| Editable cell precedent | `order-zone-editor.tsx`, `order-area-cell.tsx`, `group-loading-preparation.tsx` | EXISTS |
| Generic editable-cell primitive | — | **MISSING** |
| Single-order eligibility check | `PreparationEligibilityReader::isEligible()` (`:150-165`) | EXISTS, **no caller** — and `assignLateOrder()` performs no status check, so an operator could manually attach an ineligible order and produce a row that exists but never displays |

---

## 13. Root Causes

**RC-1 — Wave membership is never re-evaluated (causes ORD-00017; the 12 vs 11).**
One predicate, two application moments. Distribution re-checks on every read; Preparation checks once at admission with no eviction path. Any order eligible for a moment is a permanent member. The 17-second window was sufficient. The eviction machinery exists but is bound to `preparation_session_orders`.

**RC-2 — `ReturnToPaymentWorkflow` emits no domain event** (`:77-80`, `events()` returns `[]`), so nothing downstream can react to an order leaving eligibility.

**RC-3 — Warehouse-less orders are collected by Distribution but can never enter a wave** (causes ORD-00013/00014). Wave admission requires `assigned_warehouse_id = wave.warehouse_id`; Distribution ingestion has no warehouse predicate. Distribution is planning work Preparation is not preparing.

**RC-4 — No Distribution-side edit affordance** for City/Governorate or Zone, despite complete, correct backend chains for both.

**RC-5 — `PATCH /orders/{order}/zone` is a decoy** that writes a display label in a different catalogue and cannot move the authoritative assignment.

**RC-6 — The permission split** puts the Orders geography routes and the authoritative zone route in disjoint role sets.

---

## 14. Proposed Implementation Boundaries

Boundaries only — nothing implemented.

**B-1 — Wave eviction on ineligibility (closes RC-1/RC-2).** Reuse the existing chain rather than adding a third one: have `ReturnToPaymentWorkflow` (and any workflow leaving `fulfilmentEligible()`) emit a domain event, and extend the **existing** `OrderPreparationObserver` → `PreparationReleaseEngine` path to call the **existing** `WaveMembershipService::detachOrder()` / a release that stamps `released_at`. Do **not** add a status filter to the wave read — that would hide the row while leaving `orders_count` wrong. **Owner decision required:** should eviction *release* (`released_at`, preserving history, symmetrical with `closeWave`) or *postpone*? Release is the closer analogue.

**B-2 — Warehouse-less orders (RC-3).** Decide whether Distribution should collect an order with no warehouse at all. Options: exclude them from ingestion; or surface them as a distinct "no warehouse" reason. **Owner decision.**

**B-3 — Inline City/Governorate.** Frontend-only. Add an editable cell to the Distribution grid reusing `OrderZoneEditor`'s cascading pattern, backed by `useGovernorates`/`useCities` (the **logistics geography** catalogue — the one `logistics_city_id` points at), writing through the existing `PATCH /orders/{id}/quick-update` with **`city` + `governorate`** (never `area` alone — see §10 path 2). Must invalidate the Distribution root key as well as Orders. **Selector only, never free text** (§10 path 4). No backend change required.

**B-4 — Inline Zone.** Frontend-only. Add a `useChangeOrderZone` mutation calling the existing `PATCH /assignments/{assignment}/zone`. **Never** write `orders.delivery_zone` for this purpose. Group membership already recomputes inside `changeOrderZone`.

**B-5 — Surface the swallowed re-zone failure (§10 path 3).** Today the operator gets a 200 while the zone silently did not move. At minimum the response should report whether the re-zone applied. **Owner decision** on whether to change that contract.

**B-6 — Permissions (RC-6).** Resolve which role may perform inline edits from the Distribution workspace. **Owner decision.**

**Explicitly not proposed:** no third eligibility implementation (§5), no second zone resolver, no second assignment writer, no change to the `OrderCityBinder` NULL-only sweep, no new table.

---

## 15. Required Tests (proposed, not written)

| # | Test | Notes |
|---|---|---|
| 1 | Preparation eligible count == Distribution eligible count for one wave at one instant | The invariant §5 asks for |
| 2 | An `awaiting_payment` order is never admitted to a wave | Guards the admission predicate |
| 2b | **An order admitted while eligible is evicted when it leaves eligibility** | The actual ORD-00017 regression — reproduce the 17-second window with a controlled clock |
| 3 | An `in_progress` order enters Preparation when eligible | |
| 4 | A payment-method change does not auto-confirm | |
| 5 | Changing City via quick-update updates canonical `logistics_city_id` | |
| 6 | Changing City resolves the zone per contract (automatic — §16) | |
| 7 | Explicit Zone edit changes `distribution_window_orders.distribution_zone_id` | via `changeOrderZone` |
| 8 | An Unassigned order can be resolved from the Zones table | |
| 9 | Inline City edit creates no duplicate zone assignment | |
| 10 | Inline Zone edit creates no duplicate assignment | |
| 11 | Zone counters update after an inline edit | `zoneSummaries` |
| 12 | Distribution group membership updates after a zone change | `virtual_slot_id` recompute |
| 13 | Preparation/Distribution stay consistent after a geography change | |
| 14 | **The automatic `OrderCityBinder` NULL-only sweep still refuses to rebind a bound order** | An existing test already covers this — `DistributionOrderGeographySyncTest.php:238-253`. Must stay green. |
| 15 | `area`-only edit does **not** silently leave a stale zone | §10 path 2 |
| 16 | An unresolvable city does not un-zone a planned order without the operator knowing | §10 path 4 |
| 17 | A warehouse-less order is handled per the B-2 decision | |

---

## 16. City Change → Zone Behaviour: the current contract

**Current contract: (A) AUTOMATIC ZONE RESOLUTION.** Extracted from the existing architecture, not invented.

An explicit operator edit of `city`/`governorate` through `quick-update` or `PUT` fires `OrderGeographyChanged` → `SyncOrderGeographyListener` → `rebindOrder()` → `OrderZoneResolver::resolve()` → `changeOrderZone()`. No confirmation step exists anywhere in that chain.

Three qualifications that matter for any UI built on it:

- The re-zone is **best-effort** — failure is logged and swallowed, and the operator still gets a 200.
- An **unresolvable** city clears the zone (`logistics_city_id = null` → zone null → unzoned and slotless).
- An order with **no assignment row** gets the city bound and nothing else.

**Recommended implementation boundary:** keep contract A — it is already built, tested and consistent. Add only *visibility*: the UI should show the resolved zone (or the failure reason) immediately after saving, so "automatic" does not mean "invisible". Do **not** introduce contract C (propose-and-confirm) without an explicit owner decision; it would fork the geography chain.

---

## 17. Data Safety

**Nothing was mutated.** Every query was `SELECT` / `SHOW`. No order, status, payment method, payment state, city, zone, group or wave membership was touched. No test data created.

Verified unchanged during the audit: orders 19 · preparation_wave_orders 39 · preparation_waves 6 · distribution_window_orders 13 · distribution_zones 10 · distribution_trips 2 · payment_proofs 4 · order_events 247 · orders with `logistics_city_id` 12.

ORD-00017 untouched: `awaiting_payment`, `mobile_wallet`, 0.00/199.11, `logistics_city_id` NULL, still an active wave member.

**Files created by this audit: this report only.**

---

## 18. Explicit Out of Scope

- Any code, frontend, backend, database, configuration or migration change.
- The WooCommerce gateway vocabulary blocker (AUDIT-002) and ORD-00003's browser re-trigger.
- `DistributionPlanningController`'s dead `READY_STATUSES` predicate and its 500-ing endpoints — pre-existing, off the observed path.
- `PreparationEligibilityReader::isEligible()` having no caller, and `assignLateOrder()` performing no status check — reported, not addressed.
- The unrefreshed wave snapshot columns (`is_paid = 0` on a fully-paid ORD-00001).
- `DistributionAssignmentChanged` having no listener.
- Loading-stack eligibility (`AutoAllocationService` selects wave members by `->active()` with no status check).

---

# STATUS

# AUDIT COMPLETE — IMPLEMENTATION NOT STARTED
