# TASK-OPERATIONS-DISTRIBUTOR-ORDERS-PART-4-DISTRIBUTION-GROUPS-001 — FINAL REPORT
## Zone Tabs + Distribution Groups + Preparation-Aligned Distribution Window

**Status:** IMPLEMENTED + TESTED (47/47) + BROWSER VERIFIED — **STOPPED after Part 4**
**Date:** 2026-08-21
**Branch:** `develop` — **not committed**

---

# 0. HEADLINE

Live, authenticated, real data:

```
PREP-202608-000003 · Start 20:30 · Cutoff 08:00 · End 15:00 · Africa/Cairo
   ↓
All Orders (4) │ Zn (1) │ Za (3) │ Unassigned (0) │ Distribution Groups (1)
   ↓
Distribution Group DG-001 — Draft
   2 zones · 4 orders · 5 products · EGP 1,143.66 · Paid 0 · Unpaid/COD 4
   Vehicle: Not assigned   Driver: Not assigned
```

**No migration. No new schema. No new FK. No new RBAC. No new API contract.** Every requirement was met with persistence and endpoints that already existed.

---

# 1. AUDIT

## 1.1 Distribution Group — the entity already existed

The audit found `distribution_virtual_slots` + `distribution_slot_zones`, shipped by TASK-SHIPPING-DISTRIBUTION-CORE-001, matching the requested Distribution Group **point for point**:

| §  | Requirement | Existing structure |
|---|---|---|
| §9 | a planning container grouping Zones and their Orders | `distribution_virtual_slots` |
| §9 | must NOT contain Vehicle or Driver | the migration states the absence is deliberate: *"no vehicle_id or driver_id column exists here"* |
| §11 | 1 Zone ≠ 1 Vehicle; one group may hold several zones | *"A Slot may hold ONE OR MORE Zones"* |
| §12 | an Order in at most one active group per window | a Zone belongs to at most one Slot per window (**unique index**), and Orders inherit their slot from their Zone |
| §13 | zones are consumed, not altered | Slot↔Zone membership is a separate table; zone config is untouched |
| §14 | Draft is the only state | the table has **no status column** — one state exists |

**Endpoints already routed:** `POST /windows/{w}/slots`, `POST /windows/{w}/slots/{s}/zones`, `GET /windows/{w}/slots`. `assignZoneToSlot` already re-syncs orders already sitting in that zone, so a group formed after collection still holds them.

**Conclusion: Distribution Group = Virtual Capacity Slot, surfaced under the approved name.** Nothing was created.

## 1.2 Distribution Window ↔ Preparation Wave — partially persistable

| Boundary | Wave column | Window column | Verdict |
|---|---|---|---|
| Start | `preparation_waves.starts_at` | `distribution_windows.opens_at` | column exists |
| Cutoff | `intake_closes_at` | `closes_at` | column exists |
| **End** | `ends_at` | — | **no column** |
| **Timezone** | — | — | no column, but **`companies.timezone` is the certified operational timezone** — derivable |
| **Wave link** | — | — | **no column** |

Per §1 and §25 this is where a persistence decision would be required, so **none was made** — see §15.

---

# 2. EXISTING COMPONENTS REUSED

| Reused | Role |
|---|---|
| `distribution_virtual_slots` / `distribution_slot_zones` | the Distribution Group itself |
| `POST /windows/{w}/slots` · `/slots/{s}/zones` | group creation and zone membership |
| `ManualAssignmentService::assignZoneToSlot` | re-syncs already-collected orders into the group |
| `POST /windows/{w}/late-orders` | the approved carry-over path (§11 below) |
| `DistributionAggregationService` | the read model, extended — not replaced |
| `OrderZoneResolver` | zone resolution, untouched |
| `WaveStatus::activeValues()` | which wave is active — Preparation's own definition |
| `companies.timezone` | the operational timezone |
| `PaymentState::fromAmounts()` | the approved paid rule |
| `UniversalDataGrid`, `Tabs`, `Card`, `Checkbox`, `Input`, `Label`, `Badge` | existing DS/grid infrastructure (§4, §15) |
| Part 1's effective payment method | `payment_method` → `payment_method_manual` fallback (§7) |
| Parts 1–3 `unassigned_reason` | the Unassigned tab (§8) |

**Nothing parallel was built.** No second grid, no second payment mapping, no second resolver, no second cycle.

---

# 3. DISTRIBUTION WINDOW IMPLEMENTATION (§1, §2)

## What was implemented

`DistributionAggregationService::governingPreparationWave()` reports the company's **most recently started ACTIVE wave** — active being `WaveStatus::activeValues()`, Preparation's own definition, so a status added to Preparation later is classified by Preparation and not by a list copied into Distribution.

`GET /windows/current` now carries:

```json
"preparation_wave": {
  "wave_number": "PREP-202608-000003",
  "starts_at": "2026-08-20 17:30:00",
  "cutoff_at": "2026-08-21 05:00:00",
  "ends_at":   "2026-08-21 12:00:00",
  "status": "collecting",
  "timezone": "Africa/Cairo"
}
```

The header renders these in the **company's** timezone via `Intl.DateTimeFormat({ timeZone })` — not the browser's, so a planner in another zone still reads the cycle the warehouse actually runs on.

## The header shows ONE cycle

The previous header showed the window's own `opens_at` / `closes_at`. Those are now **removed from the header** — showing them beside the wave's boundaries is exactly the second clock §1 forbids. The window's status badge remains, because it describes ingestion, not schedule.

When no wave is active the header says so plainly rather than falling back to the window's own times.

**Nothing was written to Preparation. No wave configuration was modified. No Preparation lifecycle was changed.**

---

# 4. ZONE TABS IMPLEMENTATION (§3, §5)

```
[ All Orders (4) ] [ Zn (1) ] [ Za (3) ] [ Unassigned (0) ] [ Distribution Groups (1) ]
```

- **one tab per zone**, labelled with the zone name and its order count;
- each zone panel shows the zone's KPIs (Orders · Products · Order value · Paid · Unpaid/COD) then its orders;
- an order appears in exactly one zone tab, because `zone_id` holds exactly one value.

Verified live: the **Za** tab holds precisely ORD-00002, ORD-00006, ORD-00007; **Zn** holds ORD-00009.

---

# 5. UNASSIGNED IMPLEMENTATION (§8)

The Unassigned tab is rendered **unconditionally** — it is a sibling of the zone tabs, not a conditional branch. At zero it reads `Unassigned (0)` and shows *"Every order in this window resolved to a zone."*

Hiding it at zero would remove the only place an operator can look when an order later fails to resolve, and its absence would read as *"nothing can go wrong"* rather than *"nothing has"*.

Reasons come from the Parts 1–3 derived `unassigned_reason` (`address_incomplete` · `city_not_resolved` · `zone_not_configured` · `unresolved`). **No zone is guessed, and no manual zone assignment was added.**

---

# 6. FULL ADDRESS IMPLEMENTATION (§6)

## The defect this fixes

The read model selected `billing_address_1` — **NULL on every manually-entered order**. The workspace showed "Maadi / Cairo", which is not an address anyone can deliver to.

## What is shown now

A dedicated `Shipping Address` column rendering the Order's **own** fields: recipient · phone(s) · street (`shipping_address`) · `building` / `floor` / `apartment` · `landmark` · `area` · city · governorate · postcode · `address_notes`.

Live example (ORD-00002):

```
OSAMA FAYEZ AHEMD
01150006267
2 shalaby
Bldg ششششششششششششششش · Apt 22222222
Maadi · Maadi · Cairo
Landmark: Next to City Stars Mall
```

## Two rules enforced

1. **Nothing is reconstructed.** A missing street is never replaced by the zone, the area or the city.
2. **Missing data is named.** When street / city / governorate are absent the cell shows `Missing: street`, because a silently short address looks like a complete one.

## One deliberate ordering decision

The address prefers the **Order's** `customer_name` / `billing_phone` over the customer master record. A delivery goes where *this order* says — a gift, a workplace, a second address — and preferring the profile would send the driver to the wrong door. The unrelated top-level `phone` field keeps its original customer-first behaviour, so no existing consumer changes.

**No schema change. No change to the Order address model.**

---

# 7. PAYMENT METHOD (§7)

The column is **Payment Method**, not Payment Status. The value is Part 1's approved effective method (`orders.payment_method`, falling back to `payment_method_manual` for manually-entered orders). A display table spells out the known values (`cod` → *Cash on Delivery*, `instapay` → *InstaPay*, `visa`, `mastercard`, `mobile_wallet` → *Wallet*, `bank_transfer`); **an unknown value is shown as stored**, so no second payment vocabulary can come into existence here.

The paid/unpaid badge remains beneath it as secondary information — it is a different fact and is labelled as one.

---

# 8. DISTRIBUTION GROUP IMPLEMENTATION (§9–§16)

## Creation (§10)

Inside Distribution Planning → **Distribution Groups** tab: tick the zones, optionally name the group, press **Create Distribution Group**. A live preview shows `2 zones · 4 orders · EGP 1,143.66` before anything is created.

The group number is derived (`DG-001`, `DG-002`, …) so the operator never invents a code; the backend's unique index on (window, code) is what actually guarantees it.

## Two guards, both structural

- **A zone already in a group is not offered again.** Re-assigning it would silently empty the first group, so it is made impossible to ask for rather than reported as an error afterwards. Verified live: after DG-001 took both zones, the selector offered none.
- **An order cannot be in two groups.** `virtual_slot_id` is one column with one value, and a zone maps to at most one slot per window.

## Group summary (§15)

Group Number · Status (**Draft**) · Zones count · Zone names · Orders · Products · Total order value · Paid · Unpaid/COD · the aligned Preparation Wave.

**Vehicle and Driver are displayed as "Not assigned" and are inert** — present so the operator can see the plan is incomplete, non-editable because assigning them is a later phase.

**No capacity was sent or invented.** `capacity_orders` and friends stay NULL: a null dimension means *"not constrained on this axis"*, which is not the same as a capacity of zero.

## State (§14)

`draft` is reported by the backend as a literal, because the table has exactly one state. **No status column was added and no state machine was created.** No Approval, Finalize or Loading state exists anywhere in this Part.

---

# 9. DATA MODEL / PERSISTENCE

| Concept | Storage | Created here? |
|---|---|---|
| Distribution Group | `distribution_virtual_slots` | **no — existed** |
| Group ↔ Zone | `distribution_slot_zones` | **no — existed** |
| Order ↔ Group | `distribution_window_orders.virtual_slot_id` | **no — existed** |
| Group number | `distribution_virtual_slots.code` | **no — existed** |
| Group state | none — one state, reported as a literal | **no** |
| Cycle boundaries | `preparation_waves` (read-only) | **no** |
| Timezone | `companies.timezone` (read-only) | **no** |

**Zero migrations. Zero new tables. Zero new columns. Zero new FKs.**

---

# 10. TESTS

`backend/tests/Feature/Logistics/DistributionGroupsTest.php` — 23 tests through the real router, middleware and database.

| § | Required | Test |
|---|---|---|
| 1 | Window follows Preparation Wave boundaries | `test_the_distribution_cycle_reports_the_active_preparation_wave` — asserts start/cutoff/end/status equal the wave row |
| 2 | Timezone matches | `test_the_cycle_timezone_is_the_companys_operational_timezone` |
| — | A closed wave does not govern | `test_a_closed_wave_does_not_govern_the_cycle` |
| — | Cycle is tenant-scoped | `test_the_cycle_is_scoped_to_the_acting_company` |
| 3 | All Orders shows each order once | `test_all_orders_shows_each_eligible_order_exactly_once` (incl. a 2-line order) |
| 4 | Zone tabs show only their orders | `test_a_zone_tab_returns_only_that_zones_orders` |
| 5,6 | Unassigned present and correct | `test_unresolved_orders_are_reported_with_their_reason`, `test_the_unassigned_bucket_is_zero_not_absent_when_everything_resolves` |
| 7 | Full address from the Order | `test_the_full_shipping_address_comes_from_the_order`, `test_a_missing_address_field_stays_null_and_is_never_reconstructed` |
| 8 | Payment Method from Orders | `test_payment_method_uses_the_orders_source_of_truth` |
| 9 | Create a group | `test_a_distribution_group_can_be_created_from_one_zone` |
| 10 | Multiple zones per group | `test_one_group_can_hold_several_zones` |
| 11 | No duplicate grouping | `test_a_zone_cannot_end_up_in_two_groups` |
| 12 | Order in one group only | `test_an_order_belongs_to_at_most_one_group_in_a_window`, `test_orders_already_collected_join_the_group_when_their_zone_does` |
| 13 | Group totals correct | `test_group_totals_match_the_orders_it_holds` |
| 14 | Group contains correct orders | `test_group_orders_are_retrievable_by_group` |
| 15 | Reload preserves groups | `test_groups_persist_across_requests` |
| 16 | Tenant isolation | `test_groups_are_invisible_to_another_company`, `test_group_creation_is_refused_without_a_company_scope` |
| 17–21 | No Preparation / order / line / ledger / GR / PO mutation | `test_grouping_mutates_no_other_domain` — full row comparison before/after |
| 22,23 | No `vehicle_plan*` / `loading_*` writes | same test, plus `test_a_group_carries_no_vehicle_and_no_driver` (asserts the columns do not exist) |

## Result

**23 tests.** First gated run: 22 passed, 1 failed — and the failure was in the test, not the code. `assertSame` on `stdClass` rows from `DB::table()->get()` compares object *identity*, and `get()` returns fresh instances on every call, so the diff showed only a differing object hash (`#50228` vs `#14552`) with every column identical. The sibling assertions in the same test passed because they cast a single row with `(array)`; the lines assertion used `->toArray()`, which leaves an array *of objects*. Rows are now cast to arrays and ordered deterministically before comparison. **No production code was changed to make it pass.**

**Confirmed re-run — Part 4 together with the Parts 1–3 suite:**

```
Tests: 47, Assertions: 294   —   47 / 47 (100%)
```

(The trailing "PHPUnit Deprecations: 32" is platform-wide and appears in every suite on this branch.)

**Frontend:** `module-navigation.test.ts` 21/21 · `tsc -p tsconfig.app.json` **23 errors = the pre-existing baseline**, none in touched files · `vite build` clean.

---

# 11. BROWSER ACCEPTANCE

Live, authenticated, real data.

| § | Check | Result |
|---|---|---|
| 1 | Distribution Planning opens | Operations shell, Distributor Orders active parent |
| 2,3 | Active window matches the wave | `PREP-202608-000003 · Start 20:30 · Cutoff 08:00 · End 15:00 · Africa/Cairo` |
| 4 | All Orders works | 4 orders, each once |
| 5 | Zone tabs visible | `Zn (1)` · `Za (3)` |
| 6 | Za shows its orders | ORD-00002, ORD-00006, ORD-00007 |
| 7 | Zn shows its order | ORD-00009 |
| 8 | Unassigned visible at 0 | `Unassigned (0)` present |
| 9 | Full shipping address visible | street, building, apartment, area/city/governorate, landmark |
| 10 | Payment Method visible | **Cash on Delivery** |
| 11 | Create group works | DG-001 created |
| 12 | Select Zn + Za | both ticked |
| 13 | Preview | **`2 zones · 4 orders · EGP 1,143.66`** |
| 14 | Group opens correctly | Draft · aligned to PREP-202608-000003 |
| 15 | Group orders correct | 2 zones · 4 orders · 5 products · EGP 1,143.66 · Paid 0 · Unpaid 4 |
| 16 | Reload preserves the group | DG-001 intact with all totals after a hard reload |
| 17 | No duplicate orders | 4 rows, 4 distinct order ids |
| 18 | No Vehicle/Driver assignment possible | both render as inert text; no control exists |
| 19 | Preparation unaffected | all 3 waves unchanged, PREP-…-000003 still `collecting` |
| 20 | Loading unaffected | all loading tables 0 rows |

## One operational note encountered during acceptance

The distribution window rolled from 2026-08-20 to 2026-08-21 mid-session, and the 4 orders stayed attached to the previous window — Distribution has **no automatic carry-over** (unlike Preparation). They were brought forward using the **existing approved endpoint** `POST /windows/{w}/late-orders`, which moves an assignment between windows and records `previous_window_id` for audit. No data was fabricated and no new mechanism was used. This is a real operational gap worth a decision — see §14, L-1.

---

# 12. SIDE EFFECTS (§24)

Verified against the live database after the run:

| Area | Rows | Verdict |
|---|---|---|
| `vehicle_plans`, `vehicle_plan_slots`, `vehicle_plan_slot_orders`, `vehicle_plan_adjustment_log` | **0** | untouched |
| `loading_sessions`, `vehicle_assignments`, `allocation_records`, `vehicle_inventory_items` | **0** | untouched |
| `stock_movements`, `goods_receipts`, `purchase_orders`, `distribution_trips` | **0** | untouched |
| `order_lines` | 10 | unchanged |
| Order statuses | 4 awaiting_payment · 1 awaiting_stock · 1 confirmed · 3 in_progress | **unchanged** |
| `preparation_waves` | 3, boundaries and statuses identical | **unchanged** |

**The only new data is the approved planning data**: 1 row in `distribution_virtual_slots` (DG-001, no capacity) and 2 rows in `distribution_slot_zones`, plus `virtual_slot_id` set on the 4 existing assignment rows.

---

# 13. TENANT ISOLATION

Unchanged and still fails closed. Every group endpoint resolves the window through `DistributionWindowController::companyId()`, which aborts **403** for an actor with no company, and loads the window `where company_id = :acting` — another company's window reads as **404**, not 403.

`VirtualCapacitySlot` rows carry `company_id`, stamped from the window. Two tests cover it: groups are invisible to another company, and creation without a company scope is refused.

**NF-3 unchanged:** `distribution_zones` still carries no `company_id`. No cross-tenant **order** or **group** exposure exists in this workspace, so the condition requiring action did not arise. Shared zone configuration remains the open platform question.

---

# 14. KNOWN LIMITATIONS

| # | Limitation |
|---|---|
| **L-1** | **No automatic carry-over between distribution windows.** An order collected into yesterday's window stays there when the day rolls; bringing it forward requires the manual late-order action. Preparation has wave carry-over; Distribution does not. **Decision needed.** |
| **L-2** | **Window boundaries are reported from the wave, not stored on the window.** `distribution_windows` has no `ends_at`, no timezone and no wave link, so the *stored* `opens_at`/`closes_at` remain config-driven while the *displayed* cycle is the wave's. Aligning storage needs a migration — see §15. |
| **L-3** | **A group cannot be renamed, emptied or deleted from the UI.** `detachZone` exists in the domain service but has **no route**; adding one is a new API contract, which §25 forbids. |
| **L-4** | **Re-assigning a zone moves it** rather than being refused. That is the existing unique-index behaviour; the UI prevents asking for it, but a direct API call would still move the zone. |
| **L-5** | **The workspace is English-only** — the whole `distribution-workspace` feature predates this task and has no `useTranslation`; only the page title and breadcrumb are translated. |
| **L-6** | **Group capacities are always NULL.** Deliberate: capacity belongs to vehicle planning. |
| **L-7** | Zone names in dev are placeholder data (`Zn`, `Za`). Operator configuration, not code. |

---

# 15. PERSISTENCE DECISIONS DEFERRED (§1, §25 — STOP + REPORT)

Two parts of §1 cannot be satisfied without schema, so **neither was implemented**:

| Requirement | What it needs | Status |
|---|---|---|
| Store the wave link on the window | `distribution_windows.preparation_wave_id` | **NOT DONE — migration required** |
| Store the cycle End on the window | `distribution_windows.ends_at` | **NOT DONE — migration required** |
| Make the window's stored `opens_at`/`closes_at` derive from the wave | no new column, but it rewrites boundaries the Distribution Core deliberately **froze at creation**, and would change ingestion behaviour for windows already running | **NOT DONE — contract decision required** |

**What was done instead:** the cycle is *reported* from the wave, so the operator sees exactly one schedule and it is Preparation's. Nothing in Distribution invents a competing schedule. The window row's own times remain an internal ingestion detail and are no longer displayed.

**Decision required from the owner** before storage alignment proceeds:

- **D-P4-1** — add `ends_at` + `preparation_wave_id` to `distribution_windows`? (migration)
- **D-P4-2** — should `windowFor()` derive `opens_at`/`closes_at` from the governing wave, and if so what happens to a window already created under the old boundaries? (freeze-at-creation contract)
- **D-P4-3** — which wave governs when a company runs several concurrently (per warehouse / brand / channel)? Today's rule is *most recently started active wave*, which is unambiguous with one warehouse but not in general.
- **D-P4-4** — L-1: should distribution windows carry orders over automatically?

---

# 16. VP-1 BLOCKER — UNCHANGED

Reconfirmed against the live schema. **Nothing in this Part touched it.**

| Column | Type | Real referent | Type |
|---|---|---|---|
| `vehicle_plans.zone_id` | `char(36)` | `distribution_zones.id` | **bigint** |
| `vehicle_plans.governorate_id` | `char(36)` | `logistics_governorates.id` | **bigint** |
| `vehicle_plans.shipping_company_id` | `char(36)` | `logistics_shipping_companies.id` | **bigint** |
| `vehicle_plan_slots.vehicle_id` | `char(36)` | `logistics_vehicles.id` | **bigint** |
| `vehicle_plan_slots.driver_id` | **absent** | `logistics_drivers.id` | bigint |
| `vehicle_plans.distribution_window_id` | **absent** | — | — |

**Distribution Groups are completely independent of `vehicle_plan*`.** A group is a Virtual Capacity Slot — a different table, with different keys, that has never been able to hold a vehicle.

---

# 17. EXPLICIT CONFIRMATION — VEHICLE PLANNING WAS NOT TOUCHED

- No file under `Modules/Operations/Loading` was modified.
- No `vehicle_plan*` table was read, written, or altered. All four remain at **0 rows**.
- No vehicle or driver was assigned; both render as inert text with no control.
- No migration, no schema change, no uuid↔bigint conversion, no workaround.
- No `loading_sessions` write; `/api/loading/*` untouched.
- No Approval, Finalize, Virtual Vehicle or Trip concept was introduced. The words *Trip*, *Route Trip*, *Virtual Vehicle* and *Vehicle Assignment* appear nowhere in the UI (§21).

---

# 18. FILES CHANGED

## New (3)

| File | Purpose |
|---|---|
| `frontend/.../distribution-workspace/components/order-address-cell.tsx` | full shipping address; names what is missing |
| `frontend/.../distribution-workspace/components/distribution-groups-panel.tsx` | group creation + group list |
| `backend/tests/Feature/Logistics/DistributionGroupsTest.php` | 23 tests |

## Modified (5)

| File | Change |
|---|---|
| `backend/.../Distribution/Domain/Services/DistributionAggregationService.php` | full address on `orders()`; group rollup + zone names + `status` on `slotSummaries()`; new `governingPreparationWave()` |
| `backend/.../Distribution/Presentation/Http/Controllers/DistributionWindowController.php` | `current()` now returns `preparation_wave` |
| `frontend/.../distribution-workspace/pages/distribution-workspace-page.tsx` | cycle header, per-zone tabs, permanent Unassigned tab, Groups tab, Payment Method + Shipping Address columns |
| `frontend/.../distribution-workspace/types/index.ts` | group, cycle and address transport types |
| `frontend/.../distribution-workspace/services/…-service.ts` + `hooks/…` | `createGroup`, `addZoneToGroup`, `useCreateDistributionGroup` |

## Untouched

No migration · no seed · no RBAC · no route added or removed · no Preparation code · no Loading code · no `vehicle_plan*` · no Orders UI · no Inventory/Procurement/Finance · no ADR · **navigation unchanged** (no sidebar entry for Distribution Groups, per §20) · **nothing committed**.

---

# 19. STOP

Part 4 is implemented, tested and browser-verified.

**Stopping here (§26).** Not started: Vehicle Planning · Vehicle Plan · Virtual Vehicle · Vehicle Assignment · Driver Assignment · Approval · Finalize · Loading.

Awaiting owner verification on the real system, plus the four §15 decisions, before any further phase.
