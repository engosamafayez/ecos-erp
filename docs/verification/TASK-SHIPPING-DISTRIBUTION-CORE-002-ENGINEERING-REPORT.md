# TASK-SHIPPING-DISTRIBUTION-CORE-002 — Engineering Report

**Date:** 2026-08-12 · **Runner:** `ecos-dev-testrunner` · **DB:** `ecos_dev_test` · **Branch:** `develop`

> ## LAYER STATEMENT (mandated by the new global UI rule)
>
> | Layer | Status |
> |---|---|
> | **BACKEND** | ✅ **PASS** — 23/23 Distribution Core, boundary PASS, 139/504 total green |
> | **API** | ⚠️ **EXISTS, UNTESTED** — 13 window/slot endpoints implemented and permission-guarded; **zero HTTP feature tests** cover them |
> | **UI** | ❌ **ABSENT** — the window/slot API has no frontend consumer at all |
> | **REAL E2E / SMOKE** | ❌ **NOT POSSIBLE** — no UI to drive |
>
> ## VERDICT: **SHIPPING DISTRIBUTION CORE = NOT CERTIFIED**
>
> **Not for a defect.** The write-path blocker is fixed and every backend contract now passes. Certification is withheld solely because PART 24 requires Backend + API + UI, and two of those three are incomplete.

---

## 1. Root Cause

**`orders.logistics_city_id` was never declared in `Order::$fillable`.**

The column, its FK, and a backfill have existed since `2026_07_16_000004_add_logistics_city_id_to_orders`. But because the model never declared it, **every mass-assignment silently dropped the value** — `Order::create([... 'logistics_city_id' => $city ...])` persisted `NULL`.

Distribution resolves an Order's Zone through that column (`orders.logistics_city_id → logistics_cities.distribution_zone_id`). With it always NULL:

```
order → zone = NULL → slot = NULL → no aggregation, no capacity, no overflow, no suggestions
```

A row *was* still written to `distribution_window_orders`, but with no zone and no slot — which is why the failures read as `0 is identical to 1` and `null is identical to 1` rather than as missing rows.

Proven with a disposable probe before any change:

```
before:  order logistics_city_id=NULL   ← created with $city, persisted NULL
after:   order logistics_city_id=1
```

**What was NOT wrong**, and was therefore not touched: `attach()` was already correct — it guards on an existing `order_id` and catches `UniqueConstraintViolationException`, so idempotency was never broken; cutoff logic, Zone aggregation, and Capacity logic were all correct and blocked only by the missing city id. Per PART 2, none of them was modified before the write path was proven.

## 2. Collection Fix

One production line, in `Modules/Commerce/Orders/Domain/Models/Order.php`:

```php
'governorate',
'city',
'logistics_city_id',   // ← added
'shipping_address',
```

**No assertion was changed. No test was rewritten. No Distribution file was modified.**

`git diff` over `Modules/Logistics/Distribution/`, `config/distribution.php`, `DistributionCoreTest.php` and `routes/api.php` is **empty** of my changes — the 42 insertions visible in `routes/api.php` are the other agent's pre-existing uncommitted work.

**Result: Distribution Core 12/23 → 23/23 PASS (110 assertions).**

## 3. Manual Late Assignment

**PASS** — `test_6_manager_manually_adds_late_order_to_current_window` and `test_19_late_manual_assignment_updates_aggregation_immediately` both green.

`ManualAssignmentService::assignLateOrder()` moves the existing assignment row rather than creating a second one (an Order holds exactly one assignment), retaining `previous_window_id` for audit. The `POST /windows/{window}/late-orders` endpoint exists and is permission-guarded. No direct DB write path exists for the UI to misuse.

## 4. Individual Reassignment

**PASS** — `test_21_individual_order_moves_between_slots_without_disturbing_its_zone_or_peers` and `test_22_live_aggregation_updates_on_both_slots_after_reassignment` green.

`changeOrderSlot()` writes only `virtual_slot_id`, stamps `ManualMove`, and dispatches `DistributionAssignmentChanged`. The Order's Zone is untouched, peers are untouched, and — proven separately in §12 — the Order's Warehouse is untouched.

## 5. Zone Aggregation

**PASS** — `test_7`, `test_8`, `test_10` green. Zone summaries aggregate by Zone (not Zone × Slot), so a Zone spanning several Slots is not double-counted, while `planned_slot` / `spans_slots` preserve the real deviation signal.

## 6. Capacity

**PASS** — `test_9` (overflow detected), `test_11` (suggestions produced and **mutate nothing**), `test_12` (manager approval is what applies the move). Suggestion and application are separate services, so a suggestion structurally cannot self-apply.

## 7. Late Orders

**PASS** — before cutoff, eligible Orders enter the current Window automatically; after cutoff automatic ingestion stops and only a manager may pull an Order in. Late Orders not manually added fall to the next Window.

## 8. Live Updates

**Backend PASS, UI unverifiable.** `test_22` proves both source and destination aggregations update after a reassignment, and `test_19` proves aggregation updates immediately on late assignment — so the "received 5, total 7, remaining 2" arithmetic has correct server-side inputs.

The PART 9 scenario itself is a **UI** behaviour (driver view showing `REMAINING = 2`). With no Distribution Workspace it cannot be exercised, and no claim is made about it.

## 9. API

**Complete, permission-guarded, and untested at the HTTP layer.**

13 endpoints under `logistics/distribution` (`routes/api.php:1572-1605`): `GET windows/current`, `windows/{w}/zones|slots|orders|products|overflows`, `POST windows/collect`, `POST windows/{w}/slots`, `POST windows/{w}/slots/{s}/zones`, `PATCH assignments/{a}/zone|slot`, `POST windows/{w}/late-orders`. Each carries `permission:logistics.distribution.view|create|update`.

**PART 19 is NOT met.** `DistributionModuleTest` makes 67 HTTP assertions, but a targeted search shows **zero** touching `windows/current`, `late-orders`, or `assignments/*`. The window/slot HTTP surface — including its permission and tenant guards — has **no** feature-test coverage. All 23 passing Core tests exercise the **service** layer directly.

## 10. UI

**ABSENT.**

The window/slot API has **no frontend consumer**. A repo-wide search for `windows/current`, `late-orders`, `assignments/*/slot` and `windows/collect` across `frontend/src` returns nothing.

Distribution frontend that *does* exist — `features/logistics/distribution-planning` and `features/operations/distribution-board` — consumes a different, older endpoint group (`/stats`, `/zones`, `/unassigned`, `/zones/{id}/detail`). It is not the Window/Slot workspace and does not become one by configuration.

Therefore PARTS 11–16 are unbuilt: no Distribution Workspace page or route, no KPI cards, no Zone list, no Zone detail with Orders, no Move Order dialog with capacity impact, no Late Orders panel, no React Query service/hook layer for this API, no i18n keys.

**Nothing was mocked, stubbed, or faked to disguise this.** No static arrays, no placeholder service, no UI state pretending to be a backend capability.

## 11. E2E / Smoke

**NOT PERFORMED — not possible.** PART 20's ten steps all begin with "open the Distribution Workspace." No new E2E framework was invented, per instruction.

## 12. Warehouse Boundary

**PASS — `DistributionWarehouseBoundaryTest`, 9 assertions.**

Deliberately **not** asserted as `distribution_window_orders.warehouse_id == assigned warehouse`. No such column exists, and adding one to satisfy a test would hand Warehouse selection to Shipping — the exact inversion PART 17 forbids.

The real contract is proven instead: the Warehouse chosen upstream by Governorate + Zone + Brand Coverage is **retained unchanged** through

1. **collection** into the Window,
2. **Zone → Slot** planning,
3. **individual Slot reassignment**,

and the test additionally asserts that `distribution_window_orders` has **no `warehouse_id` column**, so Distribution structurally cannot select or substitute a Warehouse.

## 13. Runtime Tests

```
Distribution Core .................................. 23/23   (110 assertions)
Distribution Warehouse Boundary .....................  1/1    (  9 assertions)
Warehouse Coverage + Brand Assignment .............. 13/13
BranchAssignmentEngine (A–D) ....................... PASS
Preparation Entry Gate ............................. PASS
Wave Engine ........................................ PASS
V3 Transition Resolution ........................... PASS
RecipeGateTenantRepair (F4) ........................ PASS
NegativeStockReservation (Option B) ................ PASS
─────────────────────────────────────────────────────────────
OK (139 tests, 504 assertions)
```

Tenant isolation and idempotency are covered inside the Core suite (`test_20`, `test_21`) and now pass.

## 14. Regression

**All green** — see §13. F4 markers as specified (`F4_FORWARD`, `F4_REVERSE`, `MATRIX 6/6`, `DIRECT_FG`, `NEG_STOCK`, `RECIPE_MISSING`, `CROSS_BRAND`); Option B: `recipe=outofstock fg_stock=0 → awaiting_stock`.

`MaterialDemandCalculator` untouched; container parity `ce69612a` (certified host version); contract `available = on_hand − reserved` (`15 − 8 = 7`, `missing 3`) intact. IAM untouched — no IAM file modified this session.

## 15. PHPStan

**L0 platform-wide: [OK] No errors.** **Core L6: [OK] No errors.**

## 16. Pint

**PASS — 2 files** (`Order.php`, `DistributionWarehouseBoundaryTest.php`). `Order.php` also verified clean at `git HEAD`, so no pre-existing violation was introduced or "fixed". No unrelated violation was touched.

## 17. MAIN Control

**UNTOUCHED.** `ecos_erp` on the separate `ecos-mysql` container: **551 tables**, no migration, no writes. The DEV MySQL server hosts only `ecos_dev` and `ecos_dev_test`. No destructive Docker operation.

## 18. Files Changed

| File | Change |
|---|---|
| `backend/Modules/Commerce/Orders/Domain/Models/Order.php` | **+1 fillable entry** (`logistics_city_id`) + explanatory comment — the entire root-cause fix |
| `backend/tests/Feature/Logistics/DistributionWarehouseBoundaryTest.php` | **new** — TEST 12 boundary proof (my own file; the other agent's suite untouched) |

A temporary probe (`ZZProbeTest.php`) was used to isolate the root cause and has been **deleted** from both host and runner.

## 19. Pre-existing Findings

- **PART 19 gap:** the window/slot HTTP surface has no feature tests (§9). Pre-existing — the endpoints shipped without them.
- **UI gap:** the window/slot API has never had a frontend consumer (§10). Pre-existing.
- **Distribution not deployed to `ecos-dev-app`** — the module is present in the test runner but the newest window/slot files are absent from the app container, so the API cannot be exercised against `ecos_dev` yet.
- **Ownership:** the Distribution module, `config/distribution.php`, `DistributionCoreTest.php` and the `routes/api.php` additions remain **another agent's uncommitted work**. Verified idle ~25h with the runner free before I touched anything; none of it was modified.
- Two `OrderReservationLifecycleTest` failures and one multi-suite isolation flake remain pre-existing (classified in earlier tasks, out of scope here).

## 20. Final Verdict

# SHIPPING DISTRIBUTION CORE = **NOT CERTIFIED**

| Condition | Result |
|---|---|
| 23/23 backend tests PASS | ✅ **23/23** (was 12/23) |
| HTTP/API tests PASS | ❌ **none exist** for the window/slot surface |
| UI uses real APIs | ❌ **no UI exists** for this API |
| Late Assignment works | ✅ backend |
| Individual reassignment works | ✅ backend |
| Live aggregation works | ✅ backend; UI behaviour unverifiable |
| Warehouse boundary preserved | ✅ proven, without giving Shipping a Warehouse |
| Regression PASS | ✅ 139 tests / 504 assertions |
| MAIN untouched | ✅ |
| PHPStan / Pint | ✅ clean |

**Reason for non-certification:** PART 24 requires Backend **and** API **and** UI. Backend is now genuinely complete and the long-standing write-path blocker is resolved at its root — but the window/slot HTTP surface has no feature tests, and the Distribution Workspace UI does not exist. Certifying on backend evidence alone is exactly what the new global rule prohibits.

### Required to certify

1. **API feature tests** for all 13 window/slot endpoints, including permission and tenant guards (PART 19).
2. **Distribution Workspace UI** against the real API — page, route, service + React Query layer, KPI cards, Zone list, Zone detail with Orders, Move Order with capacity impact, Late Orders panel, invalidation-driven refresh (PARTS 11–16).
3. **Runtime smoke** of the ten PART 20 steps once the UI exists.
4. **Deploy the Distribution module into `ecos-dev-app`** so the API is reachable outside the test runner.

Items 2 and 3 are a substantial frontend build and were **not started in this task** — no partial or mocked UI was left behind.

**STOPPED.** No Loading, Vehicle Inventory, Driver, Delivery, Cash Settlement, Route Optimization, Packing, Order Splitting, or Warehouse Transfer work was started.
