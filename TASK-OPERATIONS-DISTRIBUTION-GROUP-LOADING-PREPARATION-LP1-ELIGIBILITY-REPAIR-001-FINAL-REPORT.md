# TASK-OPERATIONS-DISTRIBUTION-GROUP-LOADING-PREPARATION-LP1-ELIGIBILITY-REPAIR-001 — FINAL REPORT

**Date:** 2026-08-21
**Scope:** LP-1.0 — the Distribution read-eligibility boundary. Nothing else.
**Commit status:** **NOT COMMITTED** (per instruction).

---

## 1. Executive Summary

LP-1 rendered an empty screen in production-shaped data. The cause was not in LP-1: starting a preparation wave moves every order in it to `ready_for_dispatch`, and every Distribution read filtered that status out. LP-1's projection, warehouse scoping, aggregation and UI were all correct — **only the eligibility predicate was wrong.**

**The fix is one config key, one reader method, and four call-site substitutions.**

```
A  entry/ingestion      = config('distribution.eligible_order_statuses')        UNCHANGED
                        = [in_progress, confirmed]

B  operational/loading  = config('distribution.loading_eligible_order_statuses') NEW
                        = [in_progress, confirmed, ready_for_dispatch]
```

`constrainToLoadingEligible()` composes the **same** `excludePostponed()` as `constrainToEligible()`, so both halves of Preparation's rule survive verbatim. Only the status list differs.

**No migration. No new route. No new permission. No new table. No Preparation change. No Order change. No Inventory change. No Loading change. No frontend change. No business data created or modified.**

### Result — real DG-001, real browser

| | Before | After |
|---|---|---|
| Group card `ORDERS` | **0** | **3** |
| Loading Preparation rows | **0 (blank screen)** | **2** |
| Workspace `ELIGIBLE ORDERS` | 2 | 8 |

```
Loading Preparation
Products required for this group's planned departure. Required quantities are specific to this group.
WAREHOUSE Main Warehouse | ZONES Maadi | ORDERS 3 | MAX ORDERS Not limited | REMAINING CAPACITY Not limited
Product              SKU              Required  Unit
Honey Jar 250g       FG-HONEY-250     2         pcs
تجربة التعليقات        ECOS-FG-000001   1         kg
Prepared quantities are recorded per preparation wave, not per group, so they are not shown here.
```

Byte-identical to LP-1's originally-verified output — with all three orders now at `ready_for_dispatch`, which is exactly the state that used to blank it.

### Not claimed

LP-2 is not started. No Prepared, no Remaining, no Prepared table, no Prepared route, no Vehicle, no Driver, no Actual Loading, no full Distribution certification.

---

## 2. Root Cause

```
WaveStarted / WavePreparationStarted
  → HandlePreparationWaveStarted / HandlePreparationWavePreparationStarted
      → FulfillmentEngine::run(MoveToPreparationWorkflow, $order)
          → $order->update(['status' => OrderStatus::ReadyForDispatch])
```

`HandlePreparationWaveClosed` states that status's meaning in its own comment: **"CASE B — done, waiting to be loaded."** It is the entry ticket to Distribution/Loading, not an exit from fulfilment.

But `config('distribution.eligible_order_statuses')` derives from `OrderStatus::fulfilmentEligible()` = `[in_progress, confirmed]`, and every Distribution read passes through `PreparationEligibilityReader::constrainToEligible()`.

**Net effect: the Group lost its work at the moment that work became loadable.**

Live evidence, before the fix:

| | |
|---|---|
| DG-001 orders in `distribution_window_orders` | 3 — all present, membership intact |
| Their `orders.status` | all three `ready_for_dispatch` |
| Group product aggregation **without** the status filter | 2 rows |
| Group product aggregation **with** `constrainToEligible` | **0 rows** |

The membership rows were never deleted — `DistributionCollectionService` *"never touches `orders`"*, `detachZone()` nulls `virtual_slot_id` rather than deleting, and `orders()` documents that hiding an order is *"reversible, deleting it would force a re-collection"*. **The link was intact; only the read hid it.** That is why this repair is a predicate change and not a data repair.

---

## 3. Approved Decisions

| Decision | Applied in LP-1.0 |
|---|---|
| **D-1** Permission — no new permission; `operations.preparation.update` is for the future LP-2 write | **No permission change.** LP-1.0 adds no write path, so no permission was touched |
| **D-2** Eligibility — dedicated read-side predicate, `excludePostponed()` intact | **Implemented.** §4 |
| **D-3** Attribution — Group + Product, LP-2 only | **No change** |
| **D-4** Prepared — LP-2 only | **No Prepared write, no Prepared read** |
| **D-5** Prepared source — LP-2 only | **No change** |
| **D-6** Group membership — LP-2 only | **No change** |
| **D-7** New route — not yet | **No route created** |
| **D-8** New table — not yet | **No table created, no migration** |
| **D-9** LP-1.0 — predicate/read eligibility only | **Held.** §5, §6 |
| **D-10** Capacity — no enforcement; `capacity_orders` remains the only constraint | **No capacity code touched** |

---

## 4. Eligibility Predicate

### 4.1 The new config key

`backend/config/distribution.php` — additive. The existing `eligible_order_statuses` is **byte-for-byte unchanged**.

```php
'loading_eligible_order_statuses' => array_values(array_unique(array_merge(
    array_map(
        static fn (OrderStatus $s): string => $s->value,
        OrderStatus::fulfilmentEligible(),
    ),
    [OrderStatus::ReadyForDispatch->value],
))),
```

Sourced from the enum for the same reason the original list is: a future status rename cannot silently empty it. **`OrderStatus` itself is not modified** — the enum is read, never changed, so `MoveToPreparationWorkflow::guard()` and `PreparationSessionPolicy` are unaffected.

Verified at runtime in the dev container:

```
$ php artisan tinker --execute="echo json_encode(config('distribution.loading_eligible_order_statuses'));"
["in_progress","confirmed","ready_for_dispatch"]
```

### 4.2 The new reader method

`PreparationEligibilityReader::constrainToLoadingEligible()` — a sibling of `constrainToEligible()`, differing in **exactly one expression**: which config key supplies the status list.

```php
public function constrainToLoadingEligible(Builder $query, string $alias = 'orders'): Builder
{
    /** @var list<string> $statuses */
    $statuses = (array) config('distribution.loading_eligible_order_statuses', []);

    return $this->excludePostponed(
        $query->whereIn($alias.'.status', $statuses),
        $alias,
    );
}
```

**`excludePostponed()` is reused, not reimplemented.** It is the identical `NOT EXISTS` on `preparation_wave_orders WHERE released_at IS NULL AND postponed_at IS NOT NULL`. Preparation's rule is restated nowhere, and there is still exactly one implementation of it.

`constrainToEligible()` and `isEligible()` are untouched.

### 4.3 What the predicate deliberately does not do

It does **not** scope by warehouse, company, window or Group. Those stay in the caller — `scopeWarehouse()`, the window id, the slot id — applied *outside* this wrapper, so it composes with them rather than becoming a second definition of any of them.

---

## 5. Consumers Changed

Four call sites, all in `DistributionAggregationService`, each identified by what the LP-1 surface actually renders — not by pattern-matching the method name.

| # | Method | Feeds | Evidence it is LP-1's surface |
|---|---|---|---|
| 1 | `productAggregation()` | Loading Preparation's Required rows | It **is** LP-1. `GET /windows/{window}/products?slot_id=&warehouse_id=` |
| 2 | `slotRollup()` | `SlotSummary.orders_count`, `products_count`, `total_value`, `paid_orders` | LP-1's context strip reads `SlotSummary` for every field (LP-1 report §3); the Group card that hosts the entry point renders these |
| 3 | `slotOrderCounts()` | `SlotSummary.demand_orders` → utilisation / overflow | The **other half of the same `slotSummaries()` payload** as #2. Splitting them would report one Group's size twice, differently, in one API response |
| 4 | `orders()` | the Group's order list | `distribution-groups-panel.tsx:425` renders `orders.filter(o => o.virtual_slot_id === group.slot_id)` **inside the same card**, under a header using `group.orders_count` (line 419). Leaving it narrow while #2 widened would print "3 orders" above an empty table |

Each site carries a comment naming why it widened, so the reasoning survives the next reader.

---

## 6. Consumers Intentionally Unchanged

The audit identified nine consumers of the eligibility mechanism. **Five were left exactly as they were.**

| Consumer | Kind | Why it must not widen |
|---|---|---|
| `zoneSummaries()` | read — the **planning board** | Its consumer is the zone-selection list for building a Group (`selectable` in `distribution-groups-panel.tsx`), which offers Zones not yet grouped. That asks "what can still enter planning?" — the narrower question |
| `DistributionCollectionService::eligibleUnassignedOrders()` | **WRITE** — creates `distribution_window_orders` | Widening would newly ingest post-preparation orders into a Window. The brief is explicit: *"do not recollect it automatically unless the existing system already does so"* |
| `OrderCityBinder::bindForCompany()` | **WRITE** — updates `orders.logistics_city_id` | An Orders-column write in `Modules/Logistics/Geography`, outside LP-1.0's boundary |
| `lateOrders()` | read — **entry triage** | An entry question whose sibling POST creates membership. `assignLateOrder()` performs no eligibility check at all, so widening the read would build a UI path into an unguarded write |
| `PreparationEligibilityReader::isEligible()` | single-order predicate | Zero callers today; unchanged |

`RedistributionSuggestionService::overflows()` needed no decision — it delegates to `slotSummaries()` and inherits #2/#3 automatically.

### The one visible consequence, stated rather than hidden

Once a Zone's orders are all `ready_for_dispatch`, **the Zone leaves the Zones board while its Group keeps reporting the work.** Observed live: the workspace shows `ZONES 1` (Giza, which still holds `in_progress` orders) while DG-001 correctly reports its 3 Maadi orders.

That is the two surfaces answering their own questions, not a disagreement about one — and it is documented in the `zoneSummaries()` docblock so it reads as a decision rather than an oversight. Test 9 asserts it deliberately.

---

## 7. `ready_for_dispatch` Behavior

| | |
|---|---|
| Visible to Loading Preparation | **yes** — this is the repair |
| Visible to the Group card, its counts and its order list | **yes** — the same card, one predicate |
| Visible to the Zones planning board | **no** — deliberate (§6) |
| Collected into a Window automatically | **no** — collection stays narrow (§6) |
| Order status changed by LP-1.0 | **never.** LP-1.0 writes nothing at all |
| `MoveToPreparationWorkflow`, Preparation waves, reservations, wave completion | **untouched** |

Statuses **past** loading — `out_for_delivery`, `delivered`, `returned` — are in neither predicate. Widening stopped at exactly one status. Test 5 asserts this rather than trusting it.

---

## 8. Postponed / Cancelled Behavior

Preserved, and asserted rather than assumed.

| Rule | How it holds | Test |
|---|---|---|
| A postponed order is excluded even though its status is eligible | `constrainToLoadingEligible` composes the same `excludePostponed()` | 3 |
| A **postponed `ready_for_dispatch`** order is excluded | both halves move together — the hardest case, and the one that would silently regress had the predicate been written as a plain status list | **3** |
| A cancelled order is excluded | `cancelled` is in neither list | 4 |
| A **cancelled `ready_for_dispatch`** order is excluded | widening must not degrade into "everything after preparation counts" | **4** |
| `on_hold`, `awaiting_payment`, `awaiting_stock`, `scheduled` | in neither list | — |
| Group structure is never auto-destroyed | no write exists in LP-1.0; membership rows untouched | 8 (LP-1) |
| An order that becomes eligible again returns | both predicates evaluate per request; nothing cached or stored | 1 |

---

## 9. Warehouse Scope

Part 5A / 5B preserved exactly.

- `scopeWarehouse()` is applied **outside** the eligibility wrapper in all four changed reads, so the new predicate composes with it and cannot bypass it.
- No `warehouse_id` column was added to `orders`, to any Distribution table, or anywhere else.
- Warehouse ownership is still `distribution_virtual_slots.warehouse_id` (NOT NULL), read and never duplicated.
- No warehouse is inferred; no header display fallback is used as a selection.
- The client continues to send `warehouse_id` alongside `slot_id` — verified live in the network request.

Test 6 proves the boundary holds under the *widened* predicate: two warehouses both finish preparing the same Zone on the same day, and Warehouse A's Group reports only its own 3 units, never B's 900, and never B's second product at all.

---

## 10. Group Scope

Untouched. No change to Group creation, Group ownership, Zone ownership, Zone assignment, Zone movement, Group capacity, or any membership mutation. `ManualAssignmentService` was not opened. `capacity_orders` remains the only Group constraint and no enforcement was added.

LP-1.0 contains **zero** write paths.

---

## 11. Required Projection

Unchanged. `DistributionAggregationService::productAggregation()` remains the canonical and only Required calculation:

```
Required(group, product) = Σ order_lines.quantity
  WHERE order ∈ this Group's orders
    AND orders.assigned_warehouse_id = the Group's warehouse
    AND constrainToLoadingEligible(orders)     ← the ONLY line that changed
```

`SUM(ol.quantity)`, the grouping, the unit join, the ordering and the response shape are all byte-identical. No second Required calculation was created, none was added in React, no order-line aggregation was duplicated in the frontend, no new API was introduced, and Required is still not stored.

Live proof through the service itself:

```
productAggregation(window, null, DG-001, Main Warehouse)
→ [{FG-HONEY-250, PCS, 2}, {ECOS-FG-000001, KG, 1}]
```

---

## 12. Preparation Boundary

**Not modified.** Explicitly untouched: `MoveToPreparationWorkflow`, `HandlePreparationWaveStarted`, `HandlePreparationWavePreparationStarted`, `HandlePreparationWaveClosed`, every Preparation wave service and action, `refreshKpis`, `wave_product_demand`, `preparation_wave_items`, `prepared_products_pool`, wave completion semantics, and `OrderStatus`.

LP-1.0's only relationship to Preparation is that it **reads** `PreparationEligibilityReader` — as LP-1 already did — and reuses `excludePostponed()` unchanged.

No Prepared and no Remaining was added. `preparation_wave_items.quantity_prepared` and `prepared_products_pool` are not read, not written, not referenced.

---

## 13. Inventory Boundary

**Nothing.** No inventory mutation, no reservation, no stock movement, no FIFO, no inventory table change. LP-1.0 reads `order_lines`, Group membership and `orders.status` — no inventory table is opened. Reservation continues to happen where it already did, inside `MoveToPreparationWorkflow` at wave start, untouched by this task.

---

## 14. Vehicle / Loading Boundary

**Nothing touched:** vehicle assignment, driver assignment, `loading_sessions`, `loading_tasks`, `vehicle_assignments`, `allocation_records`, Actual Loading, `vehicle_plan_slots`. Virtual Vehicle Planning remains permanently removed and was not referenced or revived.

All of these remain at 0 rows, before and after (§18).

---

## 15. Tests

### 15.1 What was added

**9 focused tests**, added to the existing `DistributionGroupLoadingPreparationTest` so its fixtures are reused rather than duplicated, plus one 3-line helper (`setStatus`). **The 8 existing LP-1 tests are unmodified.** No other test file was changed, and none was deleted or weakened.

### 15.2 The task's ten required behaviours, mapped to evidence

| # | Required behaviour | Covered by |
|---|---|---|
| 1 | `in_progress` remains visible | `..._remain_visible_alongside_ready_for_dispatch` + all 8 pre-existing LP-1 tests, whose fixtures are `in_progress` |
| 2 | `confirmed` remains visible | `..._remain_visible_alongside_ready_for_dispatch` (1 + 20 + 300 = 321 across all three states) |
| 3 | `ready_for_dispatch` becomes visible | `test_a_ready_for_dispatch_order_is_visible_to_loading_preparation` — asserts before **and** after the transition |
| 4 | postponed `ready_for_dispatch` remains excluded | `test_a_postponed_ready_for_dispatch_order_stays_excluded` — both halves moved at once |
| 5 | cancelled orders remain excluded | `test_a_cancelled_ready_for_dispatch_order_stays_excluded` + pre-existing LP-1 test 3 |
| 6 | foreign warehouse orders remain excluded | `test_ready_for_dispatch_work_stays_inside_its_own_warehouse` + pre-existing LP-1 test 2 |
| 7 | foreign tenant orders remain excluded | `test_a_foreign_tenant_cannot_read_another_companys_loading_preparation` → 404 |
| 8 | unrelated consumers retain their eligibility | `test_unrelated_consumers_keep_the_narrow_predicate` + `DistributionPreparationEligibilityTest` (10) + the module regression set (§15.4) |
| 9 | canonical `productAggregation` remains the Required source | pre-existing LP-1 test 1 (endpoint output identical to the service's own) + `DistributionCoreTest` (23) |
| 10 | LP-1 shows the expected Required for real DG-001 | browser verification, §16 |

Two tests were added beyond the ten, because the widening needed bounding in both directions:

- `test_statuses_past_loading_are_not_loading_eligible` — `out_for_delivery`, `delivered` and `returned` must **not** become eligible. Widening stopped at exactly one status, and this asserts it rather than trusting it.
- `test_the_group_headline_count_and_its_order_list_agree_after_preparation` — `slotRollup`, `slotOrderCounts` and `orders()` are three queries the same card renders side by side; this fails if any subset had been widened.

### 15.3 One test strengthened during review

`test_unrelated_consumers_keep_the_narrow_predicate` originally asserted only that a post-preparation order is not collected. That assertion could have passed **for the wrong reason**: `OrderCityBinder` is itself on the narrow predicate, so an unbound order fails to collect regardless of status. The test now binds `logistics_city_id` explicitly first, and adds a **control** — the same order, bound identically, *does* collect the moment its status is narrow-eligible. Status is now the only variable.

### 15.4 Results

**Run 1 — the change and its two owning suites**

```
tests/Feature/Logistics/DistributionGroupLoadingPreparationTest.php   (17)
tests/Feature/Logistics/DistributionCoreTest.php                      (23)
tests/Feature/Logistics/DistributionPreparationEligibilityTest.php    (10)

OK (50 tests, 372 assertions)     Time: 08:29
```

The two companion suites are not padding: `DistributionCoreTest` **owns** `productAggregation` (the LP-1 precedent for guarding a change to it), and `DistributionPreparationEligibilityTest` **owns the eligibility contract** — the single suite most likely to fail if the narrow predicate had shifted. Both green, unmodified.

**Run 2 — the rest of the Distribution module** *(the seven remaining suites that exercise the four changed reads)* — see §15.5.

Run through `GATE_WAIT=2400 scripts/test-gate.sh`, never bare phpunit, because the test schema is pinned and shared; run 1 queued behind another agent's `migrate:fresh` before acquiring the advisory lock. `config:clear` and `route:clear` were run in the container first, and every changed file was `docker cp`-ed in, so the run executed against the edited code and not a stale image.

**No full-ERP regression was run, and none is claimed.** No fabricated business data: every fixture lives inside `RefreshDatabase` and is torn down with it.

### 15.5 Module regression

The task forbids a full-ERP regression, but requirement 8 ("unrelated Distribution consumers retain their previous eligibility") cannot be proven by argument alone. So the **seven remaining Distribution suites that exercise the four changed reads** were run — targeted regression on exactly the module that changed, not the ERP.

```
DistributionGroupManagementTest · DistributionGroupsTest · DistributionGroupWarehouseOwnershipTest
DistributionReadModelApiTest · DistributionWarehouseScopedReadsTest · DistributionWindowApiTest
DistributorOrdersAddressBindingTest

Tests: 117, Assertions: 773, Failures: 2      Time: 07:52
```

**115 pass. The 2 failures are pre-existing and are proven so, not argued so.**

### 15.6 The two failures — proven pre-existing by control

Both are in `DistributionReadModelApiTest`, both of the form *expected 1, got 0*, on the filter `?order_status=new`:

```
1) test_each_filter_narrows_server_side    — "Filter should have matched: ?order_status=new"   :324
2) test_filters_compose_in_a_single_query                                                      :356
```

**The control.** The four changed call sites were reverted to `constrainToEligible` **in the container only**, and the same suite re-run. The diff between the two states was verified to be exactly 8 lines, **0** of which are anything but the method name — a perfectly isolated experiment.

```
CONTROL (pre-LP-1.0 code):  Tests: 13, Assertions: 62, Failures: 2
                            → test_each_filter_narrows_server_side  :324   ?order_status=new
                            → test_filters_compose_in_a_single_query :356
```

**Identical failures, identical tests, identical lines.** The container was then restored from backup and confirmed byte-identical to the working copy by md5.

Three further independent lines of evidence agree:

1. **Logical.** `constrainToLoadingEligible` returns a strict **superset** of `constrainToEligible` — adding a value to a `whereIn` list can only ever *add* rows. A failure of the form "expected 1, got 0" is impossible to cause by widening. LP-1.0 could only ever produce the opposite shape.
2. **Fixture.** The test creates its order as `'status' => 'in_progress'` (`DistributionReadModelApiTest:102`) and then filters `?order_status=new`. The other six filters in the *same loop* match that same row, so the order is visible — only the one filter *value* is stale.
3. **Documentary.** `2026_08_13_100000_supersede_order_lifecycle_v3_canonical.php` maps `'new' => 'in_progress'` and states *"any row still holding 'new' throws"*. `new` was **retired on 2026-08-13** by the ADR-042 canonical lifecycle supersession, eight days before LP-1.0, in `Modules/Commerce/Orders` — which LP-1.0 never opened. The fixtures were migrated to `in_progress`; these two filter strings were not.

**A third instance exists and is reported rather than left to be discovered.** `DistributionOrdersFilterApiTest:247` carries the same `order_status=new` filter against the same `'status' => 'in_progress'` fixture (line 101). It was not in the run set above and will fail the same way, for the same reason. It is **not** LP-1.0's to fix (see §21.9).

### 15.7 Totals

| Run | Suites | Tests | Assertions | Failures |
|---|---|---|---|---|
| 1 — the change + its two owning suites | 3 | 50 | 372 | **0** |
| 2 — the rest of the Distribution module | 7 | 117 | 773 | 2 — **both pre-existing** |
| Control — pre-LP-1.0 code, same suite | 1 | 13 | 62 | 2 — **identical** |
| **LP-1.0 total** | **10** | **167** | **1,145** | **0 attributable to LP-1.0** |

Every run went through `GATE_WAIT=2400 scripts/test-gate.sh`, never bare phpunit, because the test schema is pinned and contended — runs 1 and 2 each queued behind another agent's suite before acquiring the advisory lock. `config:clear` and `route:clear` were run in the container first, and every changed file was `docker cp`-ed in, so each run executed against the edited code rather than a stale image.

**No full-ERP regression was run, and none is claimed.** No fabricated business data: every fixture lives inside `RefreshDatabase` and is torn down with it. No existing test was modified, weakened or deleted.

---

## 16. Browser Verification

Real dev stack (`127.0.0.1:5173` → `ecos-dev-app` → `ecos_dev`), real DG-001, real data. Signed in with the repository's own documented local dev account (`DEVELOPMENT.md:24`). **No Group, Warehouse, Order, Zone or membership was created or modified.**

| # | Check | Result |
|---|---|---|
| 1 | Workspace loads | **PASS** |
| 2 | Eligible-orders KPI | **PASS** — 8 (six `ready_for_dispatch`, two `in_progress`); was 2 before the fix |
| 3 | Distribution Groups tab | **PASS** — 1 group |
| 4 | Group identity | **PASS** — DG-001, Draft |
| 5 | Warehouse | **PASS** — Main Warehouse |
| 6 | Zones | **PASS** — Maadi |
| 7 | **Order count** | **PASS — 3** (was **0** before the fix) |
| 8 | Products / value / paid split | **PASS** — 3 · EGP 425.11 · Paid 0 · Unpaid 3 |
| 9 | Loading Preparation opens | **PASS** — `loading-preparation-DG-001` |
| 10 | **Product rows** | **PASS — 2** (was **0**, a blank panel) |
| 11 | SKU | **PASS** — `FG-HONEY-250`, `ECOS-FG-000001` |
| 12 | Required | **PASS** — 2 and 1 |
| 13 | Unit | **PASS** — `pcs`, `kg` |
| 14 | Canonical endpoint used, unchanged | **PASS** — `GET /api/logistics/distribution/windows/{id}/products?slot_id=…&warehouse_id=…` → 200 |
| 15 | Prepared / Remaining absent | **PASS** — the LP-1 note still renders; no Prepared column, no input |
| 16 | Zones board asymmetry | **OBSERVED** — `ZONES 1` (Giza only); Maadi has left the planning board while its Group reports it. Deliberate (§6) |

Rendered panel:

```
Loading Preparation
Products required for this group's planned departure. Required quantities are specific to this group.
WAREHOUSE Main Warehouse | ZONES Maadi | ORDERS 3 | MAX ORDERS Not limited | REMAINING CAPACITY Not limited
Product              SKU              Required  Unit
Honey Jar 250g       FG-HONEY-250     2         pcs
تجربة التعليقات        ECOS-FG-000001   1         kg
Prepared quantities are recorded per preparation wave, not per group, so they are not shown here.
```

**Classification: BROWSER VERIFIED** for items 1–16 in English, against real DG-001 whose three orders are all `ready_for_dispatch`.

**NOT browser-verified:** postponed exclusion, cancelled exclusion, two-warehouse isolation and foreign-tenant refusal — proving them live would require mutating real business data or creating a second warehouse, both forbidden. All four are covered by focused tests (§15). Arabic was not re-verified because **no frontend file and no i18n key changed**; LP-1's Arabic verification stands unaltered.

---

## 17. Static Gates

Backend files were touched, so the backend gates were run rather than skipped. **No frontend file was touched, so no frontend gate was run** — and none is claimed.

| Gate | Scope | Result |
|---|---|---|
| `php -l` | all 4 touched files | **No syntax errors** |
| PHPStan | `PreparationEligibilityReader.php`, `DistributionAggregationService.php`, `config/distribution.php` | **[OK] No errors** |
| Pint | `config/distribution.php`, `PreparationEligibilityReader.php`, the test file | **PASS — 3 files** |
| Pint | `DistributionAggregationService.php` | **9 fixers — all pre-existing, zero added.** Measured, not asserted: Pint was applied to a copy and diffed; **0** of its changes touch any LP-1.0 line. They land on import ordering, `'Zone ' . $id` concat spacing, `\DateTimeInterface` / `\BackedEnum` FQ usage, and indentation inside `zoneSummaries()` — whose body LP-1.0 did not change |
| ESLint / tsc / Vite | — | **not run — no frontend file was touched** |

**Full-repository cleanliness is not claimed.** Only the gates above were run, on the files above.

---

## 18. Side Effects

**None.** LP-1.0 issues no writes; every verification was a `GET` or a read-only `SELECT`.

Row counts before and after the browser verification — 19 tables spanning orders, preparation, distribution, vehicle planning, loading and inventory:

```
orders 14 · order_lines 17 · preparation_waves 3 · preparation_wave_orders 11
wave_product_demand 4 · distribution_virtual_slots 1 · distribution_slot_zones 1
distribution_window_orders 9 · distribution_windows 2 · distribution_zones 10
vehicle_plans 0 · vehicle_plan_slots 0 · loading_sessions 0 · loading_tasks 0
vehicle_assignments 0 · inventory_items 5 · stock_ledger_entries 24
prepared_products_pool 0 · preparation_inventory_reservations 0

diff(before, after) → IDENTICAL
```

DG-001 itself, after verification:

```
DG-001 · warehouse Main Warehouse · capacity_orders NULL
ORD-00002 ready_for_dispatch · ORD-00006 ready_for_dispatch · ORD-00007 ready_for_dispatch
```

**Nothing was mutated to make the UI show data.** The three orders are still `ready_for_dispatch` — the exact state that previously blanked the screen — and the screen now works.

*Environment note, stated for honesty:* `distribution_window_orders` read 7 at the start of the audit and 9 when the LP-1.0 baseline was taken. That change was made by another agent working in this shared worktree, before any LP-1.0 code existed. The before/after pair above brackets **this** task's verification and is identical.

---

## 19. Files Changed

**Four files. All were already untracked (`??`) — the Distribution module is uncommitted work-in-progress.**

| File | Change |
|---|---|
| `backend/config/distribution.php` | **+1 key** `loading_eligible_order_statuses`, with its rationale. `eligible_order_statuses` byte-for-byte unchanged |
| `.../Distribution/Domain/Services/PreparationEligibilityReader.php` | **+1 method** `constrainToLoadingEligible()`. `excludePostponed()`, `constrainToEligible()` and `isEligible()` unchanged |
| `.../Distribution/Domain/Services/DistributionAggregationService.php` | **4 call sites** switched to the new predicate, each with a comment naming why; **+1 docblock note** on `zoneSummaries()` explaining why it deliberately did *not* |
| `backend/tests/Feature/Logistics/DistributionGroupLoadingPreparationTest.php` | **+9 focused tests** and one 3-line fixture helper (`setStatus`). The 8 existing LP-1 tests are unmodified |

**Migrations created: zero. Routes created: zero. Permissions created: zero. Tables created: zero. Frontend files touched: zero.**

Because neither `ecos-dev-app` nor `ecos-dev-testrunner` bind-mounts the source, each changed file was `docker cp`-ed into both containers and `config:clear` / `route:clear` run, so every verification above ran against the edited code rather than a stale image.

---

## 20. STOP Conditions

All fifteen were evaluated. **None triggered.**

| # | Condition | Status |
|---|---|---|
| 1 | Requires modifying Preparation | **No** — the reader is Distribution-owned; `excludePostponed()` reused unchanged |
| 2 | Requires changing Order status semantics | **No** — `OrderStatus` untouched; LP-1.0 writes no status |
| 3 | Requires changing wave completion | **No** |
| 4 | Requires changing reservation behavior | **No** |
| 5 | Requires modifying Inventory | **No** |
| 6 | Requires changing Group ownership | **No** |
| 7 | Requires changing Zone ownership | **No** |
| 8 | Requires widening unrelated Distribution consumers | **No** — five deliberately left narrow (§6), one asserted by test 9 |
| 9 | Requires a migration / schema change | **No** |
| 10 | Requires a new permission | **No** |
| 11 | Requires a new route | **No** |
| 12 | Requires a new table | **No** |
| 13 | Requires creating business data | **No** — every fixture lives inside `RefreshDatabase`; live data untouched |
| 14 | Cannot preserve `excludePostponed()` | **No** — it is reused verbatim and asserted by tests 3 and 4 |
| 15 | Canonical Required aggregation must be replaced | **No** — `productAggregation()` is unchanged except its predicate |

---

## 21. Limitations

Stated rather than implied.

1. **The Zones board no longer shows a fully-prepared Zone** while its Group still reports it (§6). Deliberate, documented in code, asserted by test 9 — but it is a visible asymmetry an operator can notice, and it is worth a UI label in a later phase.
2. **An order that finishes Preparation without ever being collected stays uncollected.** `DistributionCollectionService` and `lateOrders()` remain narrow, so such an order is in neither the pool nor the triage list. Pre-existing hole, deliberately not closed here, asserted by test 9. Follow-up.
3. **`assignLateOrder()` performs no eligibility check at all**, and `PreparationEligibilityReader::isEligible()` has zero callers, despite its docblock claiming otherwise. Pre-existing; reported in the decision audit; not this task's to fix — and the reason `lateOrders()` was left narrow.
4. **Postponed, cancelled, two-warehouse and foreign-tenant exclusions are test-verified, not browser-verified** (§16). That classification is not upgraded.
5. **Arabic was not re-verified.** No frontend file and no i18n key changed; LP-1's Arabic verification stands.
6. **Prepared and Remaining remain absent**, by design. LP-2 is not started.
7. **`DistributionGroupLoadingPreparationTest` test 6** — *"…never reports a group prepared or remaining quantity"* — still passes and is still correct. It will need retiring or inverting when LP-2 ships; LP-1.0 does not touch it.
8. **`stock_ledger_entries` at 24 and `distribution_window_orders` at 9** are pre-existing rows from other work in this shared worktree, not from LP-1.0 (§18).
9. **Three Distribution tests carry a retired order status and are red independently of LP-1.0** — `DistributionReadModelApiTest:318,354,362` and `DistributionOrdersFilterApiTest:247` all filter `?order_status=new`, which the ADR-042 supersession retired on 2026-08-13. Proven pre-existing by control (§15.6). **Deliberately not fixed here:** correcting them means editing tests that LP-1.0 did not break, in service of a contract LP-1.0 does not own. Reported as a follow-up so it is not mistaken for LP-1.0 debt.
10. **`OrderStatus.php` shows 169 uncommitted insertions in `git diff`.** Those are another agent's in-flight work in this shared worktree — the file was already modified at session start and LP-1.0 never opened it. Its contract is verified intact at runtime: `fulfilmentEligible()` returns exactly `[in_progress, confirmed]`, and `config('distribution.eligible_order_statuses')` still resolves to `["in_progress","confirmed"]`, so `MoveToPreparationWorkflow::guard()` is unaffected.

---

## 22. Final Verdict

### **LP-1.0 — COMPLETE / VERIFIED**

Measured against the task's own criteria:

| Criterion | Status | Evidence |
|---|---|---|
| `ready_for_dispatch` visible to Loading Preparation | **PASS** | Test 1; live DG-001 renders 2 rows where it rendered 0 |
| Postponement exclusion preserved | **PASS** | `excludePostponed()` reused verbatim; tests 3 and 4 assert both halves, including the postponed-**and**-`ready_for_dispatch` case |
| Cancelled / past-loading statuses stay excluded | **PASS** | Tests 4 and 5 — widening stopped at exactly one status |
| Warehouse scope preserved | **PASS** | Test 6 under the *widened* predicate; `scopeWarehouse()` still applied outside the wrapper |
| Tenant scope preserved | **PASS** | Test 7 → 404 |
| Unrelated consumers unchanged | **PASS** | 5 of 9 left narrow; test 9 asserts it in both directions, with a control; `DistributionPreparationEligibilityTest` 10/10 green unmodified |
| Canonical `productAggregation` remains the Required source | **PASS** | LP-1 test 1 + `DistributionCoreTest` 23/23 green; no second calculation, no client arithmetic, no new API |
| Focused tests green | **PASS** | **167 tests, 1,145 assertions, 0 failures attributable to LP-1.0** |
| Static gates green | **PASS** | `php -l` ×4 · PHPStan **no errors** · Pint PASS ×3, **0 new debt measured** on the fourth |
| Real DG-001 browser verification | **PASS** | §16 items 1–16 |
| Side effects | **PASS** | 19-table before/after diff **identical**; DG-001 untouched |
| STOP conditions | **NONE TRIGGERED** | §20, all fifteen evaluated |

### Browser classification

**BROWSER VERIFIED** in English against real DG-001 whose three orders are all `ready_for_dispatch` — the exact state that used to blank the screen.

**NOT BROWSER VERIFIED:** postponed exclusion, cancelled exclusion, two-warehouse isolation, foreign-tenant refusal — each would require mutating real business data or creating a second warehouse, both forbidden. All four are test-verified. **That classification is not upgraded.** Arabic was not re-verified because no frontend file and no i18n key changed.

### Boundaries held

No migration. No new route. No new permission. No new table. No Prepared. No Remaining. No Preparation change. No Order-status change. No wave-completion change. No reservation change. No Inventory change. No Group-ownership or Zone-ownership change. No Vehicle, Driver, Actual Loading or Virtual Vehicle Planning. No frontend file touched. No business data created or modified. **Not committed.**

### Explicitly NOT claimed

LP-2 is not started · Prepared recording is not implemented · Vehicle Planning is not implemented · Actual Loading is not implemented · full Distribution certification is not claimed · full-repository static cleanliness is not claimed · full-ERP regression was not run.

---

**STOPPING here.** LP-2, the Prepared table, the Prepared write route, Vehicle/Driver assignment and Actual Loading all remain unstarted and await separate approval.
