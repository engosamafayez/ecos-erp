# TASK-DRIVER-02 — DRIVER LOADING + ACTUAL QUANTITY + VEHICLE CUSTODY HANDOFF — REPORT

**Date:** 2026-08-25
**Mode:** IMPLEMENTATION — focused verification only.
**Final status:** **IMPLEMENTED / BLOCKED — OWNER DECISION REQUIRED.**

Driver Loading is implemented and focused-verified (§17a, §18–§20): the forward path — record the actual quantity → hand it to canonical vehicle custody → accumulate → finalize idempotently — is proven by 13 backend tests (181 assertions) and 21 frontend tests. **But testing surfaced a P1 defect in the correction path**: a driver can never revise a loaded quantity **downwards**, and the failure returns raw SQL to a mobile client (§23 gap 1). Its root fix needs either a migration or a change to the sole canonical custody writer — both explicit STOP conditions (§32.2, §32.4) — so it is reported, not worked around.

Not certified. Nothing committed, nothing deployed, no migration, no backend production change, no live business data touched.

> **Actual Loaded Quantity is the physical custody quantity.**
>
> **Driver Loading does not create a second vehicle inventory engine.**
>
> **Loading finalization is idempotent and cannot duplicate vehicle custody.**

All three statements are proven below from the existing code paths (§7, §10, §13) and from tests.

---

## 1. Scope

Driver Loading only: the `/driver/loading` screen, the actual-quantity contract, and the handoff into the existing vehicle custody engine. **No** Vehicle Stock UI, Orders, Delivery, Failed Delivery, Expenses, Wallet or Driver Closing. No Distribution business logic, Group/Trip lifecycle or Order lifecycle changed. No new Driver/Trip status. No migration. No new permission.

**Backend production code was NOT modified** — the canonical engine already implements this business contract (it shipped in TASK-DRIVER-WAVE-1). This task verified it against the spec line by line, then closed the real frontend gaps: the driver runtime's **authentication defect**, the **missing Blocked/Error/Completed states**, honest empty-vs-failure rendering, accessibility, and tests.

## 2. Existing Loading Architecture (reused, not rebuilt)

| Layer | Canonical component |
|---|---|
| Driver adapter | `DriverLoadingController` — `GET /api/driver/loading`, `POST /api/driver/loading/products/{productId}`, `POST /api/driver/loading/complete` |
| Manifest read | `DistributionAggregationService::productAggregation` (Required) + `GroupPreparationService::preparedByProduct` (Prepared) + `loading_tasks` (Loaded) |
| Loading session | `GroupLoadingContextService::open` → existing `LoadingSession` + `VehicleAssignment` (no `driver_loading_sessions`, no duplicate table) |
| Load write | `LoadProductAction` (Operations/Loading) |
| Custody write | `VehicleInventoryService::recordLoad` → `vehicle_inventory_items` + append-only `vehicle_inventory_movements` |

The parallel **Vehicle Custody Architecture Audit** (TASK-VEHICLE-CUSTODY-AUDIT-001) confirms `vehicle_inventory_items` (UNIQUE `vehicle_assignment_id` + `product_id`, sole writer `VehicleInventoryService`) is the canonical custody store. This task reuses it and invents nothing.

## 3. Driver Ownership

Enforced server-side, fail-closed, on **every** call: the driver is resolved from the token (`logistics_drivers.user_id = Auth::id()`, 403 if the user is not a driver); the shipment is the driver's **own** active Trip (`whereHas('driverVehicleAssignment', driver_id)`); the Group is re-fenced to the acting company. **No `driver_id`, `assignment_id`, `trip_id` or `session_id` is ever accepted from the client** — the product id is the only client input, and it is validated against the driver's own Group rows ("This product is not part of your shipment." → 422). Driver A therefore cannot address Driver B's loading at all.

## 4. Trip Readiness (TASK-1-C preserved)

`GroupLoadingContextService::open()` runs on every load **and** every finalize, and enforces the existing contract server-side — `assertConsistent(group, trip)` (Group↔Trip integrity), `assertManifestStillBelongsToGroup` (Trip→Group membership), `assertManifestIsComplete` (Group finalized/manifest completeness), a required **vehicle + driver pairing** (`driverVehicleAssignment`, else "Assign them before opening Loading"), and a resolvable operational warehouse. Nothing was bypassed, relaxed or duplicated; a refusal surfaces as a 422 the UI shows verbatim.

## 5. Loading UI

Existing `/driver/loading` route and `DriverLoadingPage` (no second page, route or engine). Mobile-first stacked product cards (not a dense desktop table): product name, a status chip, and a 4-cell quantity grid (Required · Prepared · Loaded · Remaining), then a per-product quantity input + **Confirm**, and a full-width **`تم التحميل`** finalize button. States implemented: **Loading** (skeletons), **Empty**, **Blocked**, **Error**, **Completed** — see §22 of the task, all five present.

## 6. Required Quantity

`quantity_required` comes from the canonical Group aggregation over the Trip's own orders — never company-wide, warehouse-wide, another Trip's, another driver's, or another Group's products. It is labelled *Required* (never "Vehicle Quantity"), is displayed alongside the actual figure, and is never assumed equal to it.

## 7. Actual Quantity

**Actual Loaded Quantity is the physical custody quantity.** The driver types the quantity physically loaded; the UI sends it as `quantity_loaded`, and `LoadProductAction` writes `loading_tasks.quantity_loaded` (with `quantity_short = planned − loaded`) and moves **that** figure into `vehicle_inventory_items`. Planned 20 / actual 18 ⇒ custody receives **18**, not 20 (asserted by test).

**Validation is server-side and canonical, not React-only**: `quantity_loaded` is validated `required|numeric|min:0`, and `LoadProductAction` refuses over-load — `if ($quantityLoaded - $quantityPlanned > 0.00005) throw` → 422 with the canonical message, which the screen displays verbatim in a `role="alert"` panel. The input's `min`/`max` are convenience only.

**Zero:** the existing contract **allows** `0` (`min:0`, and the create path only calls `recordLoad` `if ($quantityLoaded > 0)`, so a zero load records the task with no custody movement). Preserved as-is — no new rule invented.

## 8. Partial Loading

Required 20, loaded 15 ⇒ the manifest reports `quantity_remaining = max(0, required − loaded) = 5` and the row renders **partial**. The remainder is **not** treated as shortage, waste, liability or delivered: no waste/liability row is created (the audit confirms driver/vehicle waste is not even representable), and `quantity_short` on the loading task is a planning delta, not a liability. "Not loaded" stays distinct from waste and from shortage.

## 9. Loading Finalization

`POST /driver/loading/complete` is the **explicit** action — the UI never finalizes automatically, even when every line is filled (asserted by test). The backend validates against the existing vehicle-assignment state machine (Pending → Loading → LoadingComplete), refusing any other origin status with 422, and stamps `loading_completed_at`. Completion is assignment-scoped (this driver's vehicle), deliberately not the shared warehouse-day session, so it cannot complete another driver's work.

## 10. Vehicle Custody Handoff

**Driver Loading does not create a second vehicle inventory engine.** The handoff is `LoadProductAction` → `VehicleInventoryService::recordLoad` → `vehicle_inventory_items` (+ a `vehicle_inventory_movements` audit row) — the canonical engine named by the custody audit. The quantity handed over is the **actual** loaded quantity. Custody is written **per product at load time** (inside the same transaction as the loading task), which is also why finalization cannot double it (§13).

## 11. Existing Vehicle Stock

**Accumulates, never overwritten** — proven in code: `recordLoad` does `firstOrNew(['vehicle_assignment_id','product_id'])` then

```php
$item->quantity_loaded    = ($item->quantity_loaded    ?? 0) + $quantity;
$item->quantity_on_hand   = ($item->quantity_on_hand   ?? 0) + $quantity;
$item->quantity_unallocated= ($item->quantity_unallocated?? 0) + $quantity;
```

so existing 10 + new 15 = **25** (asserted by test). The vehicle is never assumed empty. Note the canonical scope: custody is keyed to the **vehicle assignment**, so a *new* assignment legitimately starts a new row (audit-confirmed design, not a defect introduced here).

## 12. Atomicity

Reused, not rebuilt. `LoadProductAction::execute()` wraps the whole unit in one `DB::transaction()` — the over-load check, the `loading_tasks` upsert, `recordLoad` (custody + movement row) and the assignment weight increment. `recordLoad` opens a nested transaction (savepoint), so a custody failure rolls the loading-task write back with it: **loading cannot be recorded while custody silently isn't**. No second transaction architecture was introduced.

This is now **empirically confirmed, not just read**: the downward-correction defect (§23 gap 1) fails *inside* `recordLoad` at the movement-ledger constraint, and the test proves the whole unit rolls back — the loading task keeps its previous quantity, custody stays at 18, and exactly one movement row exists. A mid-write failure leaves no partially committed physical state.

## 13. Idempotency

**Loading finalization is idempotent and cannot duplicate vehicle custody.** Two independent guarantees:

1. **Per-product load is an absolute set, not an increment.** The task row is located `->lockForUpdate()` under the unique `(vehicle_assignment_id, product_id)` index; `quantity_loaded` is **SET**; custody moves by the **delta** only (`abs(delta) > EPSILON`). Posting 18 twice ⇒ one task row, custody 18 (**not 36**), one movement row — asserted by test. An **upward** correction (12 → 18) moves custody by +6, asserted by test.

   > **CORRECTION.** An earlier draft of this report claimed a **downward** correction (18 → 12) applies −6 "proving set-semantics". **That claim was wrong** — it was read from `LoadProductAction`'s docblock ("a correction downwards is reflected rather than ignored") and not tested. Testing disproves it: a downward correction is **impossible** today. See §23 gap 1 (P1). The idempotency guarantee above is unaffected — it is the *repeat* case, which is proven.
2. **Finalize touches no inventory at all.** `complete()` only flips the assignment status and stamps a timestamp, and returns early when already `LoadingComplete`. Calling it twice therefore cannot add custody — there is no inventory write on that path to repeat.

## 14. Failure Handling

A custody failure aborts the surrounding transaction, so loading is not marked recorded (§12). Canonical refusals (over-load, readiness) return 422 and the screen renders the server's own message in a `role="alert"` panel instead of a generic failure. No compensation mechanism was invented.

## 15. Security / Tenancy

Unchanged D-02 contract: route group `auth:sanctum` + **existing** `permission:loading.driver.operate` (no new permission, nothing weakened). Tenancy is enforced by the controller (`Trip.company_id = tenant->companyId()` + Group re-fence) — necessary because, per the custody audit, **none of the five custody models carries a tenant global scope**; that pre-existing platform gap is recorded in §24, not worked around here.

**Defect found and fixed — the driver runtime was unauthenticated.** `driver-mobile-service.ts` created its own bare axios instance (`axios.create({ baseURL: '/api' })`), bypassing the shared client that attaches the **bearer token**. Live evidence: `/api/auth/me` → 200 while `/api/driver/loading` and `/api/driver/trips` → **401**. Fixed by using the shared `@/lib/axios` client (same `baseURL`, plus centralised 401→logout); the multipart payment-proof upload now clears the JSON content-type so axios sets the boundary itself. After the fix the same calls return **403** — correctly refused for a non-driver user, which also demonstrates the guard is real.

A repo-wide sweep (`grep -rn "axios.create" src`, not a guessed list) confirms the defect was **unique to the driver runtime**: the only other private instance is HR's `publicApi` for the **public careers portal**, which is deliberately unauthenticated. Every other feature service already uses the shared client.

## 16. i18n

All new strings in EN + AR, referenced via `t()`, no hardcoded labels: `loadingScreen.back / error / retry / blocked.{title,reason} / completedTitle / summary.{items,totalLoaded} / next`. Arabic values are real translations (`لا يمكن بدء التحميل حاليًا`, `تم التحميل بنجاح`, `عدد الأصناف`, `إجمالي الكمية المحملة`, `الطلبات`). **Parity: driver-mobile EN 131 = AR 131.**

## 17. Backend Tests

Existing Wave-1 suite `GroupGrainDriverLoadingTest` (unchanged, still the canonical coverage) already proves: group-grain load persists with null pool provenance; **actual** loaded quantity reaches vehicle inventory (not Required); **inventory accumulates across cycles without reset**; **over-loading is rejected and writes nothing**; pool-based loading still records provenance; a non-driver cannot read the manifest; a driver with no shipment gets an empty manifest.

A new focused suite `DriverLoadingCustodyHandoffTest` was written to close the remaining §27 items — driver-vs-driver isolation, cross-company denial, canonical Required quantity, actual-quantity persistence, partial loading, **idempotent re-post (18 twice ⇒ 18, one movement)**, downward correction, **pre-existing stock accumulation (10 + 15 = 25)**, explicit + idempotent finalization, over-load writing nothing, and real permission enforcement. *(Result recorded in §17a below.)*

### 17a. Backend test result

`DriverLoadingCustodyHandoffTest` — **`OK (13 tests, 181 assertions)`** through the shared-DB isolation gate (`GATE_WAIT=2400 ./scripts/test-gate.sh`, `RefreshDatabase`, driven over HTTP through `/api/driver/loading*`). `php -l` clean; **Pint passed**. Nothing skipped. No production code, migration or existing test was modified (`git status` shows exactly one new untracked file).

| # | Test | Result |
|---|---|---|
| 1 | driver reads only their own loading manifest | PASS |
| 2 | a driver cannot reach another driver's loading (same company) | PASS |
| 3 | a driver cannot see or load another company's shipment | PASS |
| 4 | Required quantity is the canonical Group aggregation | PASS |
| 5 | the ACTUAL loaded quantity is persisted and is what custody receives | PASS |
| 6 | partial loading leaves a remainder and **no liability row** | PASS |
| 7 | **re-posting the same quantity does not double custody** | PASS |
| 8 | a downward correction is **refused by the movement ledger** | PASS *(test renamed to the real behaviour — see §23 gap 1)* |
| 8b | an **upward** correction moves custody by the delta | PASS |
| 9 | **existing vehicle stock accumulates rather than being overwritten** | PASS |
| 10 | completing the loading is **explicit and idempotent** | PASS |
| 11 | an over-load is **refused and writes nothing** | PASS |
| 12 | the driver runtime permission is enforced | PASS |

Test 8 was **not** bent to force a pass: the spec'd behaviour does not exist, so the test asserts what the system actually does (refusal at the constraint) and proves the refusal is **atomic** — custody stays at 18 with exactly one movement row.

## 18. Frontend Tests

**21/21 passing** across `driver-loading-page.test.tsx` (13) and `driver-home-page.test.tsx` (8):
page renders + order count · products with Required vs **actual** vs remaining · the **actual typed quantity** is what is submitted (not Required) · partial row renders partial · finalize happens **only** on the explicit CTA · Blocked state (canonical `dispatch_blocked`) hides the loading UI · Error state + Retry · canonical 422 refusal surfaced verbatim · Completed state shows item count + total loaded and **no** wallet/expense/settlement/currency text · Empty state · **no NaN** · and two regression guards that an unresolved/failed fetch is **not** rendered as "no shipment"/"no trip".

## 19. Static Verification

- **`tsc -p tsconfig.app.json`**: **23 errors = the existing baseline; 0 in any file touched.** Baseline is pre-existing and unrelated — not claimed clean.
- **ESLint**: exit **0** on the whole `driver-mobile` feature (an Arabic-literal finding in a test fixture was fixed properly, not suppressed).
- **php -l / Pint / PHPStan**: **not applicable to production code — no backend production file was changed.** They were run on the new test file (see §17a).

## 20. Browser Verification

Live at `localhost:5173` with the existing authenticated session; **no live data mutated, nothing fabricated**:

- **Auth fix — VERIFIED.** Before: `/api/driver/loading` + `/api/driver/trips` → **401** while `/api/auth/me` → 200. After: → **403** (authenticated, then correctly refused for a non-driver). This is the proof the bearer token is now attached.
- **State D (Error) — VERIFIED.** `/app/driver/loading` renders "Couldn't load the shipment manifest." + **Retry**; Driver Home likewise renders the identity plus the same error + Retry.
- **Honest-state correction — VERIFIED.** Before the fix, the 401 rendered as **"No shipment assigned yet"**. **This corrects the TASK-DRIVER-01 report**, which recorded that screen as "State B verified": it was in fact this masking bug, not a true empty state. Both screens now separate *fetching* → skeleton, *settled/failed* → error + retry, *manifest says none* → empty.
- **Scenario A (driver with an eligible Trip: product rows, quantities, `[تم التحميل]`) — NOT VERIFIED.** Requires assigning a driver to a trip and seeding a finalized Group manifest; the dev DB has **0 trips with a driver assignment** and all custody tables are empty. Fabricating that (or mutating live assignments/inventory) is forbidden by §31 → `BROWSER NOT VERIFIED — DATA SAFETY CONSTRAINT`. Covered by component tests + the backend suite instead.
- **Scenario C (readiness-blocked Trip) — NOT VERIFIED** for the same reason; covered by the Blocked-state component test and the server-side `open()` guards.

## 21. Data Safety

No live business data created, modified or deleted. No live Loading started or finalized, no inventory moved, no ORD-00007 change, no driver assignment / Trip / Order change, no waste, no liability, no financial entry. No migration, no backend production change, nothing committed or deployed. Verification used component tests, isolated backend fixtures, and read-only browser inspection.

## 22. Files Changed

| File | Change |
|---|---|
| `…/driver-mobile/services/driver-mobile-service.ts` | **Auth fix**: use the shared `@/lib/axios` client instead of a private axios instance; multipart upload clears the JSON content-type |
| `…/driver-mobile/pages/driver-loading-page.tsx` | Blocked / Error(+Retry) / Completed(item count + total loaded + next action) states; verbatim server-refusal panel; icon+text status; per-product accessible labels; larger touch targets; honest skeleton-vs-error-vs-empty branching |
| `…/driver-mobile/pages/driver-home-page.tsx` | Same honest skeleton-vs-error-vs-empty branching + error state with Retry (was masking 401/403 as "no trip assigned") |
| `…/driver-mobile/pages/driver-loading-page.test.tsx` | **new** — 13 tests |
| `…/driver-mobile/pages/driver-home-page.test.tsx` | +3 tests (unresolved/failed-fetch guards) |
| `i18n/locales/{en,ar}/driver-mobile.json` | +9 keys each (back, error, retry, blocked.*, completedTitle, summary.*, next) |
| `backend/tests/Feature/Operations/DriverLoadingCustodyHandoffTest.php` | **new** — focused §27 coverage (test-only; no production code) |

**Unchanged on purpose:** every backend production file (controller, actions, services, migrations), `GroupGrainDriverLoadingTest`, routes, permissions, and the whole Operations/Loading + Distribution engine.

*Reading `git status` in this worktree:* several backend files under `Modules/Operations/Loading` (including `LoadProductAction.php`) and `DriverLoadingController.php` already show as modified/untracked. Those are **uncommitted changes from the earlier TASK-DRIVER-WAVE-1 work**, not from this task — the working tree carries ~438 dirty paths from prior sessions. This task's backend footprint is exactly one new file: `DriverLoadingCustodyHandoffTest.php`.

## 23. Remaining Gaps

1. **P1 — a driver can never correct a loaded quantity DOWNWARDS, and the failure leaks raw SQL.** Found by testing, then verified independently in the source. Loading 18 and correcting to 12 returns **422** carrying the raw database error (table, column and constraint names) to a mobile client.

   Three compounding causes, each confirmed:
   - `VehicleInventoryService::recordLoad()` adjusts the item by the delta correctly, then appends a **movement row with that same negative quantity**.
   - `vehicle_inventory_movements` carries `ALTER TABLE … ADD CONSTRAINT chk_vehicle_inventory_movements_quantity CHECK (quantity > 0)` (migration `2026_07_05_121000`, line 39) — a negative movement can **never** be written, so the transaction rolls back.
   - `DriverLoadingController::loadProduct()` (and `complete()`) `catch (RuntimeException $e)` and return `$e->getMessage()` as a 422. **`QueryException extends PDOException extends RuntimeException`**, so *every* database fault is reclassified as a business rejection **and its message is echoed to the client**.

   **Business impact:** a driver who types 18 when they physically loaded 12 has no way to fix it — custody permanently overstates physical stock, and the overstatement flows into delivery and reconciliation. **Security impact:** database schema disclosure to an authenticated mobile client.

   **Not fixed here, deliberately.** The root fix is either a **migration** (allow signed movements — STOP condition §32.4) or a change to `VehicleInventoryService`, the sole canonical custody writer, which is owned by the parallel Vehicle Custody audit (§32.2). The over-broad `catch` is a contained fix, but it is **coupled** to whichever root fix is chosen (with signed movements the fault disappears; with a compensating positive movement the catch should narrow to the business exception only), so changing it alone would mask a correctness defect behind a friendlier error. **Minimum owner decision:** allow signed movement rows (migration) **or** have `recordLoad` emit a positive compensating movement of a distinct `movement_type`; then narrow the controller's `catch` to the intended business exception and stop echoing DB messages. **Recommended:** the compensating-movement route — it needs no migration, keeps the ledger append-only and non-negative, and preserves the audit trail of the correction.

2. **Loading does not deduct warehouse stock** (custody audit): deduction happens at DISPATCH via `LoadVehicleWorkflow`, and the Group/driver path creates no `allocation_records`, so it **may never deduct at all** ⇒ a double-count window between warehouse and vehicle. Per §17 of the task this was **recorded, not invented** — no ledger integration was added. **Owner decision required** before Warehouse→Vehicle is trustworthy in the ledger.
3. **No post-finalization write lock.** `complete()` is idempotent, but `loadProduct` does **not** refuse an assignment already in `LoadingComplete`, so the API would still accept a load after finalization (the UI disables the inputs). §19 forbade adding a reopen/lock rule in this task, so this is reported for an owner ruling.
4. **No tenant global scope on any of the 5 custody models** (`company_id` is a plain unindexed column) — tenancy depends on controller scoping. Pre-existing platform gap.
5. **Blocked state has no granular canonical reason** for the driver: readiness detail lives in `open()`'s exceptions (write-time) and in dispatcher-gated `dispatchReadiness`; there is no non-mutating driver-facing readiness read (calling `open()` on a GET would create an assignment). The UI shows the canonical blocked condition generically and surfaces the exact 422 on the write.
6. **`VehicleInventoryService::recordReturn()` has zero callers** and `order_lines.loaded_qty/delivered_qty/returned_qty` have **zero writers** — the inbound half of custody (return/waste/shortage/liability) does not exist. Out of scope here; blocks Vehicle Stock / Returns tasks.
7. Zero-quantity loads record a task with **no** custody movement — correct under the existing contract, but it means a deliberate "loaded nothing" is indistinguishable from "not yet touched" in custody.

## 24. Next Task

**Resolve §23 gap 1 first** (the downward-correction defect + SQL leak) — it is a P1 on the contract this task just delivered, and the fix is small once the owner picks the route.

Then **Vehicle Stock** (the next step in the approved flow) — but it should be sequenced after an owner ruling on gaps 2 and 6, because a Vehicle Stock screen that shows `quantity_on_hand` while returns are never posted and warehouse stock is never deducted would display a knowingly incomplete picture. Then Orders → Started Delivery → Failed Delivery → Expenses → Wallet → Driver Closing.

---

## Final status

**IMPLEMENTED / BLOCKED — OWNER DECISION REQUIRED.**

**Implemented and verified:** the driver records the ACTUAL loaded quantity; it flows through the existing `LoadProductAction` → `VehicleInventoryService` path into the canonical `vehicle_inventory_items` custody store — accumulating over existing stock, inside one transaction, idempotently, with over-load refused and TASK-1-C readiness enforced on every call. The five UI states ship with EN/AR parity and accessible controls, and a real authentication defect that made the entire driver runtime return 401 was found and fixed. 13 backend tests (181 assertions) + 21 frontend tests; tsc at baseline; ESLint 0. No new engine, table, status, permission or migration.

**Blocked on one owner decision:** §23 gap 1 — a loaded quantity cannot be corrected **downwards** (`CHECK (quantity > 0)` on `vehicle_inventory_movements` rejects the negative delta), and because `QueryException` is a `RuntimeException` the controller reclassifies the DB fault as a 422 and echoes the raw SQL to the client. Custody can therefore be permanently overstated by a typo, and schema detail leaks to a mobile client. Recommended fix: emit a **positive compensating movement** of a distinct `movement_type` (no migration, ledger stays append-only and non-negative), then narrow the controller `catch` to the business exception. Two smaller rulings also stand: no post-finalization write lock (gap 3) and warehouse stock never deducted at load (gap 2).

Nothing committed or deployed.
