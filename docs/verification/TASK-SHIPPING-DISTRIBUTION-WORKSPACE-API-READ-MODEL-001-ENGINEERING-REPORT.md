# TASK-SHIPPING-DISTRIBUTION-WORKSPACE-API-READ-MODEL-001 — Engineering Report

**Date:** 2026-08-12 · **Runner:** `ecos-dev-testrunner` (`ecos_dev_test`) · **App:** `ecos-dev-app` (`ecos_dev`) · Backend/API only

> ## VERDICT: **CERTIFIED**
>
> Both approved gaps are closed, HTTP-tested, tenant-isolated and permission-guarded. The Warehouse boundary held — no selection logic entered Shipping and no `warehouse_id` column was added to Distribution.
>
> **One open business decision is recorded, not papered over:** `orders.payment_status` is a column nothing populates (§3.1). The filter and field are implemented correctly against the real column; the data behind them is empty until Orders decides who owns payment status.

---

## 1. Root Cause / API Gap

TASK-…-COMPLETION-001 stopped on two contract gaps:

1. **No endpoint listed late orders.** `POST /windows/{w}/late-orders` assigned one; nothing read the candidates.
2. **`GET /windows/{w}/orders` accepted only `zone_id` and `slot_id`** — 7 of 9 required filters had no server-side support, and the payload lacked `warehouse`, `governorate` and `payment_status`.

Both are now closed. The rejected alternative (`GET /planning/unassigned`) was **not** used: the Workspace stays on **one canonical Distribution contract**, as the architectural rule requires.

## 2. Late Orders Endpoint

```
GET /logistics/distribution/windows/{window}/late-orders
    middleware: permission:logistics.distribution.view
```

Read-only. **No new mutation was created** — `POST .../late-orders` remains the only way to pull an order in.

The set is defined by two conditions, both read from existing domain state rather than recomputed:

1. the Order carries **no** Distribution assignment (`NOT EXISTS` against `distribution_window_orders`), and
2. it was created at or after the Window's cutoff — `cutoff_reached_at ?? closes_at`.

Cutoff logic was **not redefined**; the Window's own recorded moment is used. Eligibility is the Window's own rule, `status->acceptsManualAssignment()`, surfaced as `current_window_eligible` so no UI ever decides it.

Only statuses in `config('distribution.eligible_order_statuses')` (`['new','in_progress']`) are considered — the same closed list automatic collection uses.

### Response fields (§ PART 4)

`order_id · order_number · customer_name · phone · warehouse_id · warehouse_name · governorate_id · governorate_name · zone_id · zone_name · order_status · payment_status · total · received_at · cutoff_at · late_reason · assignment_state · current_window_eligible`

Every value is read from the domain. `late_reason` is `received_after_cutoff` and `assignment_state` is `unassigned` — both are facts of the query's own predicate, not invented judgements.

## 3. Orders Read Model

`GET /windows/{w}/orders` gained, **without removing anything**:

`warehouse_id · warehouse_name · governorate_id · governorate_name · payment_status · distribution_status · is_late · received_at`

**`payment_method` was deliberately retained** alongside `payment_status` — no breaking change, no API version needed.

### 3.1 Open decision — `orders.payment_status` is never populated

The column exists, is **absent from `Order::$fillable`**, and **no production code writes it**. The only production reference is `OrderController:64`, which *reads* it as a query filter (`paid|unpaid|partial`) — so the Orders workspace already filters on a column that is always null.

This is the same defect class as `logistics_city_id` (TASK-…-CORE-002): column present, mass-assignment silently dropping it, downstream features quietly filtering nulls.

**Not fixed here, deliberately.** PART 4 requires identifying the source or stopping rather than inventing a value. The read model is correct — it surfaces the real column — but *who owns payment status on an Order and what derives it* (`deposit_amount`, `remaining_balance`, `date_paid`?) is an Orders-domain business decision. Adding it to `$fillable` would make it settable without deciding who sets it.

**Consequence:** `payment_status` returns `null` and the `payment_status` filter matches nothing until that is resolved. The endpoint is right; the data behind it is empty. The HTTP test seeds the column directly, with the reason written into the helper.

## 4. Filter Contract

```
GET /windows/{window}/orders
  ?zone_id=            (int)   — unchanged
  &slot_id=            (uuid)  — unchanged
  &governorate_id=     (int)
  &warehouse_id=       (uuid)
  &order_status=       (string)
  &payment_status=     (string)
  &distribution_status=(string)
  &late=               (bool)
```

All validated; invalid input returns **422**. `zone_id` / `slot_id` keep their exact prior behaviour, so existing callers are unaffected.

## 5. Filter Semantics

| Filter | Server-side meaning |
|---|---|
| `governorate_id` | via the canonical `orders.logistics_city_id → logistics_cities.governorate_id` FK — **not** the Order's free-text `governorate` column, which is a display value |
| `zone_id` | `distribution_window_orders.distribution_zone_id` |
| `slot_id` | `distribution_window_orders.virtual_slot_id` |
| `warehouse_id` | `orders.assigned_warehouse_id` — **read**, never written |
| `order_status` | `orders.status` (V3 enum) |
| `payment_status` | `orders.payment_status` (see §3.1) |
| `distribution_status` | **`assignment_source`** — `auto` / `manual_late` / `manual_move`. No separate distribution-status column exists and **none was invented** |
| `late` | `assignment_source = manual_late`. For an already-assigned order, "late" means it entered by manual late assignment; the cutoff decided that at assignment time, so this reads the recorded outcome rather than recomputing the rule — which is also what keeps a second lateness calculation out of React |

**Composition is server-side and ANDs.** A dedicated test asserts that flipping one condition in a seven-filter query excludes the row.

## 6. Warehouse Boundary — held

- **No** `warehouse_id` column was added to `distribution_window_orders`.
- **No** warehouse selection logic entered Shipping.
- `assigned_warehouse_id` is reached by a `leftJoin` on the Order — a read, not an assignment.

Warehouse remains decided upstream by Governorate + Zone + Brand Coverage. `DistributionWarehouseBoundaryTest` continues to assert the absence of that column.

## 7. Tenant Isolation

Late orders scope to **`$window->company_id`** — taken from the resolved Window, never from a request parameter. Two dedicated tests: a cross-company request 404s, and Company B's own window returns none of Company A's late orders.

## 8. Permissions

Both endpoints carry `permission:logistics.distribution.view`. Tested: 401 unauthenticated, **403 for an authenticated but unprivileged user** (`actingAsUnprivileged`), 200 for a permitted same-company user.

## 9. HTTP Tests

`tests/Feature/Logistics/DistributionReadModelApiTest.php` — **13 tests / 80 assertions, all green.**

Late orders: auth · unprivileged denial · same-company allow · cross-company 404 · empty when nothing is late · returns an order received after cutoff · **excludes an already-assigned order** · no cross-company leakage · complete field set with values asserted against the domain.

Orders: new read-model fields present (and `payment_method` retained) · **every filter asserted both matching and excluding** · seven-filter composition ANDs · invalid input 422.

Combined with the existing `DistributionWindowApiTest` (11/11), the Distribution HTTP surface now has **24 feature tests**.

## 10. Regression

**Distribution surface, run alone — fully green:**

```
OK (47 tests, 235 assertions)
```
Distribution Core **23/23** · Distribution API **11/11** · Read model **13/13**.

**Full combined run:**

```
Tests: 163, Assertions: 626, Failures: 2
```

The two failures are **outside Distribution and pre-existing**, both instances of a known multi-suite isolation defect classified earlier in this session — `NegativeStockReservationTest::test_manufacturing_product_with_hard_rm_shortage_still_resolves_to_reserved` passes **alone (5/5)** and **alongside `RecipeGateTenantRepairTest` (15/15)** with identical code, and fails only inside a larger combination. No Distribution test fails in any combination.

Warehouse boundary 1/1 · Warehouse Coverage **13/13** · BranchAssignmentEngine · Preparation Entry Gate · Wave Engine · V3 Transition · RecipeGateTenantRepair (F4) all green.

> **Process note, recorded rather than hidden.** An earlier attempt reported 108 errors. That was **my** error: I started a foreground regression while a background one was still executing, so two `RefreshDatabase` suites contended for `ecos_dev_test` and dropped each other's tables. The clean single run above is the valid result. Never run two DB-backed suites against the same test database concurrently.

`MaterialDemandCalculator` untouched — parity `ce69612a`, contract `15 − 8 = 7 / missing 3` intact. IAM untouched.

## 11. PHPStan

**L0:** `[OK] No errors` · **core L6:** `[OK] No errors`.

## 12. Pint

**PASS — 3 files.** One `global_namespace_import` / `ordered_imports` violation was introduced by my edit and fixed; the baseline was verified clean beforehand, so nothing pre-existing was masked or "repaired".

## 13. MAIN Safety

**UNTOUCHED.** `ecos_erp` on the separate `ecos-mysql` container: **551 tables**. No migration, no writes, no schema change, no destructive Docker operation. **No migration was created by this task at all** — every field came from existing columns.

## 14. API Contract for UI

The UI task can now proceed with no frontend business logic:

```
GET  /logistics/distribution/windows/current                  → window + zones + slots
GET  /logistics/distribution/windows/{window}/orders          → filtered, composable
       ?zone_id&slot_id&governorate_id&warehouse_id
       &order_status&payment_status&distribution_status&late
GET  /logistics/distribution/windows/{window}/late-orders     → triage list
POST /logistics/distribution/windows/{window}/late-orders     → add to current window
PATCH /logistics/distribution/assignments/{assignment}/slot   → move one order
```

The frontend never has to determine: whether an order is late (`is_late`, `late_reason`), whether it belongs to the window (membership *is* the endpoint), its warehouse (`warehouse_id/name`), zone (`zone_id/name`), distribution status (`distribution_status`) or payment status (`payment_status`).

## 15. Files Changed

| File | Change |
|---|---|
| `Modules/Logistics/Distribution/Domain/Services/DistributionAggregationService.php` | `orders()` extended with 6 composable filters + 8 read-model fields; **new** `lateOrders()` |
| `Modules/Logistics/Distribution/Presentation/Http/Controllers/DistributionWindowController.php` | `orders()` validates + forwards filters; **new** `lateOrders()` action |
| `routes/api.php` | **new** `GET /windows/{window}/late-orders` |
| `tests/Feature/Logistics/DistributionReadModelApiTest.php` | **new** — 13 HTTP tests |

**No UI file was touched** (PART 1). No Distribution Core business rule, collection, assignment, zone aggregation or capacity engine was reimplemented. No `git reset`/`clean`/`checkout`. No migration.

Deployed to **both** containers with caches rebuilt; the runner's route cache was cleared first — the stale-cache trap that silently 404'd new routes previously.

## 16. Final Verdict

# CERTIFIED

| Criterion | Result |
|---|---|
| Late Orders API | ✅ PASS |
| Orders filters | ✅ PASS — all 7 + existing 2, composable |
| Read model complete | ✅ PASS — one field returns null pending §3.1 |
| HTTP tests green | ✅ 13/13 new, 24 total on the surface |
| Tenant isolation | ✅ PASS |
| Permission checks | ✅ PASS (401 / 403 / 200) |
| Distribution Core 23/23 | ✅ green (47/47 across the whole Distribution surface) |
| Regression | ✅ no new failure — 2 pre-existing non-Distribution isolation flakes (§10) |
| No Warehouse selection in Shipping | ✅ boundary held, no column added |
| MAIN untouched | ✅ |

### Open decision for the owner

**Who populates `orders.payment_status`, and from what?** Until answered, the field returns null and its filter matches nothing (§3.1). Everything else in the contract is live.

**STOPPED.** No UI was modified — completion returns to TASK-SHIPPING-DISTRIBUTION-WORKSPACE-COMPLETION-001. No Loading, Vehicle, Driver, Delivery, Settlement, Route Optimization, Packing, Order Splitting or Warehouse Transfer work was started.
