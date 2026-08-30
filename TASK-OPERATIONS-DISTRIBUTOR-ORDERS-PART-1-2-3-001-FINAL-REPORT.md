# TASK-OPERATIONS-DISTRIBUTOR-ORDERS-PART-1-2-3-001 — FINAL REPORT
## Distributor Orders — Eligible Orders → Address Binding → Zone Assignment → Zone Grouping

**Status:** IMPLEMENTED + BROWSER VERIFIED — **STOPPED at Zone Grouping as instructed**
**Date:** 2026-08-21
**Branch:** `develop` — **not committed**

---

# 0. HEADLINE

The workflow runs end to end on the live system with real data:

```
4 eligible orders (ADR-042)
   → 4 addresses bound to canonical cities
      → 4 zones resolved
         → 2 zone groups
            → 0 unassigned
```

| Verified in the browser | Result |
|---|---|
| Operations → Distributor Orders → Distribution Planning | opens the **canonical** Distribution Core workspace |
| Eligible orders | **4** — exactly the ADR-042 set |
| Address binding | **4 bound**, 0 unresolved |
| Zone assignment | **DZ-0002 "Zn"** (Nasr City) and **DZ-0007 "Za"** (Maadi) |
| Zone grouping | Zn: 1 order / 2 products / EGP 718.55 · Za: 3 orders / 3 products / EGP 425.11 |
| Unassigned | **0** — no resolvable order is stuck |
| Multi-product order | ORD-00009 appears **once**, reporting 2 products |
| Idempotency | second Refresh → `Collected 0 · cities bound 0 · unresolved 0 · re-zoned 0` |
| Reload | city and zone persist |

**Nothing was started beyond Zone Grouping.** No vehicle planning, no virtual vehicle, no vehicle or driver assignment, no approval, no finalize, no loading handoff.

---

# 1. ELIGIBLE ORDERS IMPLEMENTATION (PART 1)

## Source of truth — reused, not rebuilt

No eligibility engine was written. The existing chain is used verbatim:

```
OrderStatus::fulfilmentEligible()          ← ADR-042, the canonical definition
      ↓ (config derives, never restates)
config('distribution.eligible_order_statuses')
      ↓
DistributionCollectionService::eligibleUnassignedOrders()
```

A test asserts the config **derives** from the enum rather than restating it, so the two can never drift.

The legacy hardcode `DistributionPlanningController::READY_STATUSES = ['confirmed','preparing']` was **not** used and **not** repaired — it belongs to the non-canonical screen (§19).

## Anti-duplication

Prevented by the **database**, not by application logic: `distribution_window_orders.order_id` carries a global unique index, so a repeated sweep — or two concurrent sweeps — cannot create a second assignment. `attach()` already treats a duplicate as a normal outcome rather than an error.

## Order information surfaced (§3)

| Required | Delivered | Source |
|---|---|---|
| Order Number | yes | `orders.order_number` |
| Customer | yes (+ phone) | `customers.name` / `phone` |
| Order Value | yes | `orders.total` |
| Payment Status | yes | derived `PaymentState` + effective method |
| Products | yes | distinct `order_lines.product_id` |
| Total Products Count | yes (+ units) | `COUNT(DISTINCT product_id)`, `SUM(quantity)` |
| Order Entry Date/Time | yes | `orders.created_at` |
| Last Updated | yes | `orders.updated_at` |
| Order State | yes | `orders.status` |
| Address / Governorate / City | yes | `billing_address_1`, `logistics_governorates`, `logistics_cities` |
| Zone | yes | `distribution_window_orders.distribution_zone_id` |

**Nothing was invented.** Two fields the audit flagged as unpopulated are reported honestly:
`orders.payment_status` and `orders.payment_method` are NULL on every order, so **payment method falls back to `payment_method_manual`** (which holds `cod`) and **paid/unpaid is derived** by the approved `PaymentState` rule rather than read from the empty column.

---

# 2. ADDRESS BINDING IMPLEMENTATION (PART 2)

## The gap that was closed

`orders.logistics_city_id` is the only field `OrderZoneResolver` reads. The column has existed since `2026_07_16_000004`, but the only thing that ever populated it was that migration's **one-time backfill**. No runtime writer existed, so 100% of orders carried NULL and could never be zoned.

## What was built — in Geography, which owns cities

**`Modules\Logistics\Geography\Domain\Services\OrderCityResolver`** — text → canonical city.

Resolution ladder, all exact and case/whitespace-insensitive:

1. `logistics_cities.name_en`
2. `logistics_cities.name_ar`
3. `logistics_city_aliases.alias` — the **existing** provider-alias table (§5)

Governorate text **narrows** candidates only to break a tie; it never widens them, and a wrong governorate cannot hide a city that already matched unambiguously.

**It never guesses (§6):**

- no fuzzy matching, no substring matching, no "closest city";
- **two candidates ⇒ `city_ambiguous`**, never the first row;
- a near-miss is reported as unresolved, because a silently mis-zoned order is worse than a visibly unzoned one.

**`Modules\Logistics\Geography\Domain\Services\OrderCityBinder`** — persists the result.

**Idempotent by construction (§7):**

- only rows `WHERE logistics_city_id IS NULL` are considered, so a second run cannot revisit a decided row;
- the `UPDATE` re-asserts both the tenant and the NULL precondition, so a concurrent binder makes it a no-op instead of an overwrite;
- a **manual correction survives** the next sweep — proven by test.

**Blast radius of one bound city:** exactly one column. The write is a query-builder `UPDATE`, not an Eloquent save, so no observer, no lifecycle transition and no `updated_at` bump fires. That last point is deliberate — `updated_at` is shown to operators as *Last updated*, and binding a city is bookkeeping, not an edit anyone made. A test compares the entire order row before and after with only `logistics_city_id` excluded.

## Why binding runs **before** collection

`attach()` stamps an order's zone **once**, at collection time. An order collected before its city is known is pinned to zone NULL forever and can never be re-collected — it already has an assignment. So the sweep is ordered:

```
1. BIND      resolve + persist cities        (Geography)
2. COLLECT   existing automatic collection   (Distribution, unchanged)
3. RECONCILE re-zone assignments made before their city was known
```

Step 3 (`DistributionCollectionService::reconcileUnzoned`) exists only for rows step 1 cannot help. It is strictly bounded: only `distribution_zone_id IS NULL` rows, using the **same** `OrderZoneResolver` and the **same** zone→slot map, and it leaves `assignment_source` untouched — learning a zone later does not make an order a manual move, and overwriting the source would destroy the audit answer to *"why is this order here?"*

---

# 3. ZONE ASSIGNMENT IMPLEMENTATION (PART 3)

**`OrderZoneResolver` is used unchanged. No second resolver was created (§8).**

```
Order → orders.logistics_city_id → logistics_cities.distribution_zone_id → distribution_zones
```

## Unassigned reasons (§9, §12) — derived, never stored

Four exhaustive answers, each computed at read time from state that already exists:

| Reason | Meaning | Operator action |
|---|---|---|
| `address_incomplete` | the order carries no city text at all | fix the order address |
| `city_not_resolved` | city text exists but matches no canonical city | fix the text, or add a city alias |
| `zone_not_configured` | city is known, but no zone is mapped to it | map the city to a zone |
| `unresolved` | city and zone both exist; the assignment predates binding | press Refresh |

No new column, no migration, and **no order is ever hidden**. When a city fails to resolve, the grid shows the **raw order text** rather than a blank — that string is what the operator has to fix.

---

# 4. ZONE GROUPING IMPLEMENTATION (§10, §11)

`DistributionAggregationService::zoneSummaries()` was extended with the three missing KPIs. Per zone the workspace now shows:

| KPI | Source |
|---|---|
| Orders Count | existing |
| **Products Count** | `SUM` of a correlated per-order `COUNT(DISTINCT product_id)` |
| Total Order Value | existing |
| **Paid Orders** | approved `PaymentState` rule, expressed in SQL |
| **Unpaid / COD Orders** | the complement — derived, so it cannot drift from `paid_orders` |

**No automatic priority. No scoring.** (§11)

**The multi-product trap was avoided deliberately.** Product counts use a *correlated subquery*, never a join to `order_lines`: a join would emit one row per line and a two-product order would double its own zone's order count and value. A test asserts `order_count = 1` and `total_value = 100.00` for a 2-line order, and the browser confirms ORD-00009 appears once.

---

# 5. EXISTING CODE REUSED

| Reused as-is | Role |
|---|---|
| `OrderStatus::fulfilmentEligible()` | eligibility SSOT (ADR-042) |
| `config('distribution.eligible_order_statuses')` | the Distribution contract |
| `DistributionCollectionService::collectForCompany()` / `attach()` | collection, idempotency |
| `OrderZoneResolver::resolve/resolveMany` | **the** zone resolver |
| `DistributionWindowService` | window lifecycle, cutoff |
| `DistributionAggregationService` | the live read model |
| `PaymentState::fromAmounts()` | approved paid/unpaid rule |
| `logistics_city_aliases` | existing provider-alias mapping surface |
| `UniversalDataGrid`, `DataGridColumnDef` | the platform grid (§15 — no new grid) |
| `Tabs`, `Card`, `Badge`, `Button`, `Skeleton` | existing DS components |
| `WorkspaceBreadcrumbs`, `useNavLabel`, `useFormatter` | existing platform infra |
| `ZoneOrdersDrawer` | existing zone drill-down |
| `ModuleNavGroup` / `subtree` resolution | the IA-001 mechanism, unchanged |

**Nothing was rebuilt that already existed.**

---

# 6. SOURCE OF TRUTH

| Question | SSOT | Owner |
|---|---|---|
| Is this order eligible? | `orders.status` + `OrderStatus::fulfilmentEligible()` | Commerce (ADR-042) |
| Is it already in the pool? | `distribution_window_orders.order_id` (unique) | Distribution |
| Which city is this address? | `logistics_cities` + `logistics_city_aliases` | Geography |
| Which city does this order have? | `orders.logistics_city_id` | Commerce (written by Geography's binder) |
| Which zone does this city have? | `logistics_cities.distribution_zone_id` | Geography |
| Which zone is this order in? | `distribution_window_orders.distribution_zone_id` | Distribution |
| Is this order paid? | derived: `deposit_amount` vs `total` | Commerce (`PaymentState`) |
| How many products? | `order_lines` | Commerce |

---

# 7. TESTS

**`backend/tests/Feature/Logistics/DistributorOrdersAddressBindingTest.php` — 24 tests, 118 assertions, all green.** Every test drives the real router, middleware stack and database.

| § | Required coverage | Test |
|---|---|---|
| 1 | ADR-042 eligibility | `test_eligible_statuses_come_from_the_adr042_contract` — also asserts config **derives** from the enum |
| 2 | In Progress included | `test_in_progress_orders_are_collected` |
| 3 | Confirmed included | `test_confirmed_orders_are_collected` |
| 4 | Non-eligible excluded | `test_non_eligible_statuses_are_never_collected` — cancelled, delivered, returned, awaiting_payment, awaiting_stock |
| 5 | City resolution | `test_city_is_resolved_and_persisted_from_the_order_address`, `..._from_the_arabic_name_as_well`, `..._through_the_alias_table` |
| 6 | Unresolvable stays unassigned | `test_unresolvable_address_stays_unassigned_with_a_reason` + `test_an_ambiguous_city_name_is_never_guessed` |
| 7 | City persistence | asserted directly against the `orders` row |
| 8 | Idempotent binding | `test_binding_is_idempotent_and_never_rewrites_a_bound_city` — a manual correction survives |
| 9 | Zone resolution | `test_zone_is_resolved_from_the_bound_city` |
| 10 | Zone configuration respected | `test_governorate_breaks_an_ambiguous_city_tie` |
| 11 | Unconfigured zone stays unassigned | `test_a_city_with_no_zone_configured_stays_unassigned_with_that_reason` |
| 12 | Orders grouped once | `test_each_order_appears_exactly_once_in_the_pool` |
| 13 | Multi-product order once | `test_a_multi_product_order_appears_once_and_reports_its_product_count` |
| 14 | Company isolation | `test_a_company_never_sees_another_companys_orders`, `test_binding_only_touches_the_acting_companys_orders`, `test_an_actor_without_a_company_is_refused` |
| 15 | No vehicle tables touched | `test_the_sweep_touches_no_vehicle_or_loading_table` |
| 16 | No Loading tables touched | same test — all 8 tables asserted at 0 |
| 17 | Existing Core tests green | see below |
| — | Re-zoning after late binding | `test_an_order_collected_before_binding_is_rezoned_by_the_next_sweep` |
| — | Paid/unpaid KPI | `test_zone_grouping_reports_paid_and_unpaid_counts` |
| — | Column-level blast radius | `test_binding_changes_only_the_city_column_on_an_order` |

## Regression run — `--filter Distribut`

```
Tests: 135, Assertions: 639, Failures: 3
```

**`DistributionCoreTest` and `DistributionWindowApiTest`: fully green.**

The 3 failures are **pre-existing and provably unrelated**:

| Test | Assertion |
|---|---|
| `DistributionReadModelApiTest::test_each_filter_narrows_server_side` | `?order_status=new` |
| `DistributionReadModelApiTest::test_filters_compose_in_a_single_query` | `&order_status=new` |
| `DistributionOrdersFilterApiTest::test_new_filters_compose_with_existing_ones_using_and` | `&order_status=new` |

Their own fixtures create `'status' => 'in_progress'` (both files, one line each), and `'new'` is not a case in `OrderStatus` at all. The assertion cannot pass regardless of any code change — it is ADR-042 fixture drift left behind when the FSM moved to `in_progress`/`confirmed`.

**Proof it is not mine:** in the failing loop, `zone_id`, `governorate_id` and `warehouse_id` all matched **through the modified read model** before execution reached `order_status=new`. The select-list change cannot affect a `WHERE o.status = 'new'` clause, and the filter block was not touched.

I did **not** repair these — they are outside this task's scope (§21). Recorded in §11.

## Frontend

- `module-navigation.test.ts` — **21/21**, extended with three guards: Distribution Planning points at the canonical surface; the legacy deep link still resolves inside Operations; navigation never reaches the retired Distribution Board.
- Full suite: **129 passed / 6 failed** — the 6 are the pre-existing `new-count-dialog.test.tsx` failures (unchanged count from before this task).
- `tsc -p tsconfig.app.json`: **23 errors = the exact pre-existing baseline**, none in any touched file.
- `vite build`: **clean**.

---

# 8. BROWSER ACCEPTANCE

Live runtime, authenticated, `localhost:5173`, real data.

| § | Check | Result |
|---|---|---|
| 1 | Distributor Orders opens | Operations shell visible, **Distributor Orders active parent**, Distribution Planning active link |
| 2 | Eligible orders appear | **4** |
| 3 | The current 4 eligible orders are handled | ORD-00002, ORD-00006, ORD-00007, ORD-00009 |
| 4 | Address binding occurs | `cities bound 4 · unresolved 0` |
| 5 | City visible | Maadi / Cairo · Nasr City / Cairo |
| 6 | Zone visible | Za (DZ-0007) · Zn (DZ-0002) |
| 7 | Orders leave Unassigned when resolvable | **Unassigned = 0** |
| 8 | Zones show grouped orders | Zn → ORD-00009 · Za → ORD-00002/6/7 |
| 9 | Multi-product order once | ORD-00009 appears once, `products = 2` |
| 10 | No unrelated orders | the 4 `awaiting_payment` and 1 `awaiting_stock` orders never appear |
| 11 | Reload preserves city/zone | after a hard reload: `city = Maadi Cairo`, `zone = Za`, KPIs unchanged |
| 12 | Company isolation | fails closed — `403` without a company scope (covered by test) |

**Observed KPI header:** `4 Eligible · 4 Assigned · 0 Unassigned · 2 Zones · EGP 1,143.66`
**Observed breadcrumb:** `Home > Operations > Distributor Orders > Distribution Planning`
**Observed tabs:** `All Orders (4) · Zones (2) · Unassigned (0)`
**Observed columns:** Order · Customer · Value · Payment · Products · City / Governorate · Zone (+ Received, Last updated, Warehouse available in the column manager)
**Idempotency, live:** a second Refresh returned `Collected 0 · cities bound 0 · unresolved 0 · re-zoned 0` with every count unchanged.

---

# 9. SIDE EFFECTS (§26)

Verified against the live database after the run:

| Area | Rows | Verdict |
|---|---|---|
| `vehicle_plans`, `vehicle_plan_slots`, `vehicle_plan_slot_orders`, `vehicle_plan_adjustment_log` | **0** | untouched |
| `loading_sessions`, `vehicle_assignments`, `allocation_records`, `vehicle_inventory_items` | **0** | untouched |
| `distribution_trips` | 0 | untouched |
| `stock_movements`, `goods_receipts`, `purchase_orders` | 0 | untouched |
| Order lifecycle | 4 awaiting_payment · 1 awaiting_stock · 1 confirmed · 3 in_progress | **identical to the pre-implementation snapshot** |
| `orders.updated_at` | 20:39, 22:32, 23:28, 23:32 — all **pre-dating** the run | **not bumped by binding** |

**The only persisted change is `orders.logistics_city_id` on 4 orders** — the explicitly approved Address Binding persistence, and nothing else.

Inventory, reservations and the stock ledger are untouched by construction: no code path in this change reads or writes them, and `test_binding_changes_only_the_city_column_on_an_order` proves column-level isolation on the one table that is written.

---

# 10. TENANT ISOLATION

**Preserved, and it fails closed.** `DistributionWindowController::companyId()` aborts 403 for an actor with no company — it never degrades into "see everything".

| Layer | Scoping |
|---|---|
| Collection | `orders.company_id = :acting` |
| **Binding (new)** | `orders.company_id = :acting`, re-asserted inside the `UPDATE` |
| Window | `distribution_windows.company_id`; another company's window is **404, not 403** |
| Read model | anchored at `distribution_window_orders` for a window already proven to belong to the acting company |

Three tests cover it, including one proving binding never touches another company's orders.

**NF-3 was NOT "fixed" (§18).** `distribution_zones`, `logistics_shipping_companies` and `logistics_drivers` still carry no `company_id`. No cross-tenant *order* exposure exists in this workspace — every order-bearing query is company-scoped — so the condition that would have required action did not arise. The shared **zone configuration** remains a platform-level question, recorded in §11 and unchanged here.

---

# 11. KNOWN LIMITATIONS

| # | Limitation | Note |
|---|---|---|
| L-1 | **3 pre-existing test failures** in `DistributionReadModelApiTest` / `DistributionOrdersFilterApiTest` — fixtures create `in_progress` while assertions filter `order_status=new` | ADR-042 fixture drift; not repaired (out of scope) |
| L-2 | **Zone names are placeholder data** — "Zn", "Za", codes DZ-0002/DZ-0007 | operator configuration, not a code issue |
| L-3 | **The workspace is not localised** | the entire `distribution-workspace` feature has zero `useTranslation` calls and predates this task; only the title and breadcrumb (nav keys) are translated. Localising it is its own change |
| L-4 | **Collection is manual only** | no scheduler or job runs `windows/collect`; the operator presses Refresh. Unchanged from the shipped design |
| L-5 | **`payment_status` / `payment_method` are NULL platform-wide** | worked around honestly via `PaymentState` derivation and `payment_method_manual`; the empty columns are a Commerce data question |
| L-6 | **No manual zone override was added** (§17) | an override contract exists (`ManualAssignmentService`, `assignment_source = manual_move`) but was deliberately not surfaced |
| L-7 | **Zone configuration is global** (NF-3) | not changed; see §10 |
| L-8 | **Legacy `/logistics/distribution/planning` still 500s** | its route is untouched so deep links resolve exactly as before; retiring or redirecting it is unapproved decision D-9 |

---

# 12. PART 4 BLOCKERS

**BLOCKER VP-1 — the `vehicle_plan*` schema cannot reference reality.** Confirmed again against the live schema; **nothing in this task touched it** (§19).

| Column | Type | Real referent | Type |
|---|---|---|---|
| `vehicle_plans.zone_id` | `char(36)` | `distribution_zones.id` | **bigint** |
| `vehicle_plans.governorate_id` | `char(36)` | `logistics_governorates.id` | **bigint** |
| `vehicle_plans.shipping_company_id` | `char(36)` | `logistics_shipping_companies.id` | **bigint** |
| `vehicle_plan_slots.vehicle_id` | `char(36)` | `logistics_vehicles.id` | **bigint** |
| `vehicle_plan_slots.driver_id` | **absent** | `logistics_drivers.id` | bigint |
| `vehicle_plans.distribution_window_id` | **absent** | — | — |

Compounding: `AssignVehicleRequest` requires a **uuid** `vehicle_id` and accepts registration, type and capacity as client-supplied snapshots — the live Loading OS never reads the fleet registry.

Resolving this requires migrations and an owner decision on the key strategy (**D-E**). It blocks Parts 4–7 only; Parts 1–3 are complete without it.

Also still open for later parts: **D-J** (tenant scope of zones / shipping companies / drivers) must be settled before Vehicle Planning, because a plan groups by zone + shipping company.

---

# 13. FILES CHANGED

## New (3)

| File | Purpose |
|---|---|
| `backend/Modules/Logistics/Geography/Domain/Services/OrderCityResolver.php` | text → canonical city; never guesses |
| `backend/Modules/Logistics/Geography/Domain/Services/OrderCityBinder.php` | persists `orders.logistics_city_id`; idempotent |
| `backend/tests/Feature/Logistics/DistributorOrdersAddressBindingTest.php` | 24 tests |

## Modified — backend (3)

| File | Change |
|---|---|
| `.../Distribution/Presentation/Http/Controllers/DistributionWindowController.php` | `collect()` now orchestrates bind → collect → reconcile and reports all four counts |
| `.../Distribution/Domain/Services/DistributionCollectionService.php` | added `reconcileUnzoned()`; **collection logic itself unchanged** |
| `.../Distribution/Domain/Services/DistributionAggregationService.php` | read model extended: city, products, last-updated, payment state, `unassigned_reason`; zone KPIs extended |

## Modified — frontend (5)

| File | Change |
|---|---|
| `features/logistics/distribution-workspace/pages/distribution-workspace-page.tsx` | rebuilt as the operational workspace: breadcrumb, KPI header, 3 tabs, grid, zone groups |
| `features/logistics/distribution-workspace/types/index.ts` | transport types extended to match the API |
| `features/logistics/distribution-workspace/services/distribution-workspace-service.ts` | `collect()` typed as `CollectResult` |
| `config/module-navigation.ts` | Distribution Planning repointed to the canonical surface |
| `config/module-navigation.test.ts` | 3 new guards |

## Untouched, deliberately

No migration · no seed · no RBAC or permission change · no route added or removed · no Loading API, service, DTO or contract · no Preparation change · no order status · no `vehicle_plan*` table · no Orders UI · no Inventory, Procurement or Finance code · **no ADR edited** · **nothing committed**.

---

# 14. IA — UNCHANGED (§14)

```
Operations
├── Preparation Workspace
├── Distributor Orders
│   ├── Distribution Planning   → canonical Distribution Core workspace
│   └── Zones
└── Loading Drivers
```

No sidebar entry was added for Vehicle Allocation, Distribution Approval or Virtual Vehicles. Parts 1+2+3 are **one** workspace reached through the existing Distribution Planning entry, exactly as §15 requires.

The legacy `/logistics/distribution/planning` **route remains registered and untouched**; only the navigation target moved. `subtree` (`/logistics/distribution`) already covers both paths, so the Operations shell survives on either — verified in the browser.

---

# 15. STOP

Parts 1, 2 and 3 are implemented, tested and browser-verified.

**Stopping here as instructed (§28).** Not started: Vehicle Planning · Virtual Vehicle · Vehicle Assignment · Driver Assignment · Approval · Finalize · Loading Handoff.

Awaiting owner verification on the real system and explicit approval before the next phase. No commit was made.
