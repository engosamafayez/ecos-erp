# TASK-DRIVER-APP-RUNTIME-GATING-AND-PHASE6-READ-CLOSURE-001 — Engineering Report

**Driver App Runtime Closure — Delivery Gating + Wallet + Reports Read Failures**

Date: 2026-08-29
Scope: DEV only. No commit / no push / no deploy outside DEV. No DEV business‑data mutation.

---

## 0. Executive summary

Three reported DEV runtime defects were audited and resolved with the **minimum** change:

| # | Defect | Root cause | Class | Fix |
|---|--------|-----------|-------|-----|
| 1 | *Start Delivery* exposed while Trip is `Loading` | Frontend gated **only** on `stop.status === 'pending'`; never consulted the Trip lifecycle | FE source | Gate on the canonical `acceptsDeliveryExecution` (mirror of backend `TripStatus::acceptsDeliveryExecution`) |
| 2 | Driver **Reports** fail ("تعذر تحميل التقرير") | DEV container `ecos-dev-app` was missing `DriverReportsController.php` → HTTP **500** class‑not‑found on dispatch | DEV runtime drift | `docker cp` the missing file (+ its service) into the container |
| 3 | Driver **Wallet** fail ("تعذر تحميل المحفظة") | Same missing files (Wallet is the same controller) → HTTP **500** | DEV runtime drift | Same copy |

The backend **source** (Phase‑6) and the **frontend error/empty/loaded states** were already correct. The Wallet/Reports failure was purely **DEV runtime drift** — two Phase‑6 files had never been copied into the running container.

No new business authority was created. No Driver‑App redesign. No route cache was cleared (there was none). One pending **additive** migration is documented and **not applied** (§10).

---

## 1. Start Delivery — root cause

`frontend/src/features/operations/driver-mobile/pages/driver-stop-detail-page.tsx` exposed the *Start Delivery* button on the single condition `stop.status === 'pending'`. The stop‑detail payload (`DeliveryStopDetail`) carries **no trip status**, so the page never had — and never checked — the parent Trip's lifecycle state.

Consequently a `pending` stop on a `Loading` trip showed *Start Delivery*, and pressing it produced the correct backend rejection:

```
DeliveryService::startStop → assertTripOnTheRoad → 422 "delivery … not on the road"
```

The backend was right; the frontend was offering a control the trip could not legally perform.

## 2. Canonical Trip gating (the predicate used)

The backend guard is:

```php
// TripStatus.php
public function isOnTheRoad(): bool            { return in_array($this, [Dispatched, OutForDelivery, InProgress], true); }
public function acceptsDeliveryExecution(): bool { return $this->isOnTheRoad(); }
// DeliveryService::assertTripOnTheRoad() throws deliveryNotOnTheRoad() (422) otherwise.
```

The frontend already owns the mirror vocabulary in the single canonical lifecycle file `lib/trip-lifecycle.ts`:

```ts
export const ON_THE_ROAD = ['dispatched', 'out_for_delivery', 'in_progress']; // == backend isOnTheRoad()
```

**No second lifecycle interpretation was created.** A named predicate was added to the *same* file, defined purely in terms of the existing `ON_THE_ROAD` group and named after the backend method it mirrors:

```ts
export function acceptsDeliveryExecution(status: string | null | undefined): boolean {
  return status != null && ON_THE_ROAD.includes(status);
}
```

The stop‑detail page now reads the canonical trip summary (`useDriverTrip(tripId).status`) and gates on `acceptsDeliveryExecution(trip?.status)` (fail‑closed until the summary loads).

Resulting behaviour (matches the task's expected matrix exactly):

| Trip status | `acceptsDeliveryExecution` | Start Delivery |
|-------------|:--:|----|
| `loading` / `loading_completed` / `driver_accepted` | false | **unavailable** (hint shown) |
| `ready_for_dispatch` (ready, not departed) | false | **unavailable** (hint shown) |
| `dispatched` / `out_for_delivery` / `in_progress` | true | **available** |
| `completed` / `settlement_pending` / `closed` | false | unavailable (unlike `hasTripDeparted`, a finished trip does not accept new execution) |

The backend guard was **not weakened** — no backend file was touched.

## 3. Stop action gating (§2 — consistency)

Every delivery **execution** control now folds in the same trip‑level gate, so a stop‑level state can never expose an action the parent trip cannot perform:

| Control | Old gate | New gate |
|---------|----------|----------|
| Start Delivery | `stop.status === 'pending'` | `pending` **AND** `tripAcceptsDelivery` |
| Phone / WhatsApp / Payment‑proof / POD upload | `stop.status !== 'pending'` (`deliveryStarted`) | `deliveryStarted` **AND** `tripAcceptsDelivery` |
| Delivered quantities (`canDeliver`) | `stop.status === 'in_progress'` | `in_progress` **AND** `tripAcceptsDelivery` |
| Failed‑delivery outcomes | `stop.status === 'in_progress'` | reuses `canDeliver` (trip‑aware) |
| Change payment method (`canEditMethod`) | `stop.status === 'in_progress'` | `in_progress` **AND** `tripAcceptsDelivery` |

Read‑only history (recorded action summary, address/maps) is intentionally **not** trip‑gated. A regressed/cancelled trip beneath an unsettled stop now exposes **no** execution control (proven by test #5, §14).

## 4. Reports — failing endpoint

`GET /api/driver/reports/orders`, `/reports/goods-movement`, `/reports/shortages`, `/reports/advances`, `/statement` — all mapped to `DriverReportsController` in `routes/api.php` (§3231‑3242 host / §3231‑3236 container).

## 5. Reports — root cause

- Container `ecos-dev-app` had **no route cache** (`bootstrap/cache` had no route file) → routes load fresh each request.
- The container's `routes/api.php` **did** reference `DriverReportsController` (`use` + 6 `Route::get`).
- **`DriverReportsController.php` was absent from the container.** `Route::get(..., [DriverReportsController::class, 'orders'])` registers fine (the `::class` constant is a compile‑time string), but at **dispatch** Laravel instantiates the controller → *"Class … DriverReportsController does not exist"* → **HTTP 500**.
- The frontend rendered that 500 as its (correct) error state "تعذر تحميل التقرير".

`php artisan route:list --path=driver` in the container reproduced the fault deterministically (it reflects controllers): `Class "…DriverReportsController" does not exist`.

## 6. Wallet — failing endpoint

`GET /api/driver/wallet` → `DriverReportsController::wallet` (same controller as Reports).

## 7. Wallet — root cause

Identical to §5 — the Wallet action lives on the same missing `DriverReportsController`, and the read service `DriverReportsReadService` was **also** absent from the container. Result: HTTP **500**, rendered as "تعذر تحميل المحفظة".

## 8. Phase‑6 source audit

Phase‑6 = `TASK-DRIVER-APP-PHASE-6-WALLET-REPORTS-CLOSURE-001`. Source is **complete and canonical**:

- `DriverReportsController` — thin; resolves identity (`Driver::user_id = Auth::id()`) + tenancy (`TenantOwnershipResolver`) + server‑side date window; delegates all figures to the read service. No write, no `Order.status`, no Finance entry.
- `DriverReportsReadService` — read‑only. Money is **derived per trip** from the canonical `SettlementService::financialSummary()` + the `distribution_payment_collections` ledger, summed across the **authenticated driver's own** trips over a server‑resolved range. **No React aggregation. No new ledger. No GL entry.** Goods movement / shortages sourced from `vehicle_inventory_items` + `vehicle_shift_reconciliation_lines`. Advances/expenses/liability surfaced as explicit `available:false` (no fabrication).

The Phase‑6 implementation was **reused as‑is** — nothing was rewritten. Server‑side period resolution (`resolvePeriod`) and driver self‑scoping are intact, so requested filters remain server‑side and a driver reads only their own data.

## 9. DEV runtime drift

| Artifact | Host source | `ecos-dev-app` container (before) | Action |
|----------|:--:|:--:|--------|
| `routes/api.php` (Phase‑6 refs) | present | **present** | none |
| Route cache | n/a | **none** | none (nothing to clear) |
| `DriverReportsController.php` | present | **MISSING** | `docker cp` → present |
| `DriverReportsReadService.php` | present | **MISSING** | `docker cp` → present |
| `DriverRuntimeController`, `DriverLoadingController`, `DriverDaySettlementController`, `DeliveryService`, `SettlementService`, `DriverDaySettlementReadService` | present | present | none |

Only the two Phase‑6 files were missing. After copy, `route:list --path=driver` resolves all six routes (no error). The PSR‑4 autoloader picked the new files up with **no `dump-autoload` and no cache clear** required. The earlier "route‑cache incident" was **not** repeated: the route source was confirmed current first, and no cache was touched.

Frontend serving model (audited): the DEV SPA is served by **Vite :5173** (HMR, base `/app/`), which **proxies `/api` → `http://127.0.0.1:8081` (dev nginx) → `ecos-dev-app`**. dev nginx :8081 root serves the Laravel backend. Vite confirmed serving the edited `trip-lifecycle.ts` (contains `acceptsDeliveryExecution`), so the FE fix is live on the same path as the backend fix.

## 10. Schema dependencies (§7 — reported, NOT applied)

One migration exists in source but is **NOT RUN** in DEV:

```
2026_08_28_120000_add_return_receipt_classification_to_reconciliation_lines
  → adds quantity_accepted, quantity_damaged, damage_reason, warehouse_receipt_at
    to vehicle_shift_reconciliation_lines  (ADDITIVE ONLY, safe defaults 0 / nullable)
```

DEV `vehicle_shift_reconciliation_lines` therefore lacks `quantity_damaged` and `damage_reason`, which `DriverReportsReadService` reads.

**Impact assessment — this does NOT break the endpoints:**
- No Eloquent strict mode is enabled anywhere (`preventAccessingMissingAttributes` absent), so a missing column read on a loaded model returns `null`/`0` — **not** an exception.
- Both columns are read PHP‑side over already‑loaded models (never in a WHERE/SELECT/GROUP BY), so no SQL fault.
- `vehicle_shift_reconciliation_lines` currently has **0 rows** system‑wide, so the read path is not even exercised; goods‑movement/shortage `damaged`/`reason` would be `0`/`null` with or without the migration.

Per §7 this migration is **reported, not applied** — the task does not authorize schema parity, it is not required for the reported defects, and no field was fabricated or mutated. Applying it later is a safe, additive, separate step.

## 11. Fixes applied

**Frontend (source):**
1. `lib/trip-lifecycle.ts` — added `acceptsDeliveryExecution()` (named mirror of the backend guard; built on the existing `ON_THE_ROAD` group).
2. `pages/driver-stop-detail-page.tsx` — read `useDriverTrip`, compute `tripAcceptsDelivery`, fold it into every execution gate, gate Start Delivery, add an explanatory hint when the trip is not yet on the road.
3. `i18n/locales/en/driver-mobile.json` + `i18n/locales/ar/driver-mobile.json` — new key `stop.startBlockedTripNotOnRoad`.
4. `pages/driver-stop-detail-gating.test.tsx` — new focused test (5 cases).

**Backend (DEV runtime only — no source change; files already correct on host):**
5. `docker cp` `DriverReportsController.php` → `ecos-dev-app`.
6. `docker cp` `DriverReportsReadService.php` → `ecos-dev-app`.

## 12. Security (§9)

- Driver identity is resolved server‑side from `Driver::user_id = Auth::id()`; tenancy from `TenantOwnershipResolver` (= authenticated user's `company_id`). No route parameter can widen scope.
- Every read query is fail‑closed to the driver's `company_id` **and** their own `driver_id` (`whereHas('driverVehicleAssignment', driver_id = …)`). No cross‑driver / cross‑tenant leakage.
- A non‑driver user hitting these routes gets a correct `403` (`abort_if($driver === null, 403)`) — that is a correct authorization result, not a runtime defect, and was **not** weakened.
- Route group gate `permission:loading.driver.operate` unchanged.

## 13. Files changed

| File | Type | Change |
|------|------|--------|
| `frontend/src/features/operations/driver-mobile/lib/trip-lifecycle.ts` | FE src | + `acceptsDeliveryExecution()` |
| `frontend/src/features/operations/driver-mobile/pages/driver-stop-detail-page.tsx` | FE src | trip‑lifecycle gating for all execution controls + hint |
| `frontend/src/i18n/locales/en/driver-mobile.json` | FE i18n | + `stop.startBlockedTripNotOnRoad` |
| `frontend/src/i18n/locales/ar/driver-mobile.json` | FE i18n | + `stop.startBlockedTripNotOnRoad` |
| `frontend/src/features/operations/driver-mobile/pages/driver-stop-detail-gating.test.tsx` | FE test | new (5 cases) |
| `ecos-dev-app:/…/DriverReportsController.php` | DEV runtime | copied from host (no host change) |
| `ecos-dev-app:/…/DriverReportsReadService.php` | DEV runtime | copied from host (no host change) |

## 14. Focused verification

**A — Delivery gating (frontend)**
- `driver-stop-detail-gating.test.tsx` — **5/5 pass** (exercises the *real* `acceptsDeliveryExecution`):
  1. trip `loading` + stop `pending` → Start Delivery **hidden**, hint shown.
  2. trip `ready_for_dispatch` + stop `pending` → Start Delivery **hidden**.
  3. trip `dispatched` + stop `pending` → Start Delivery **exposed**, no hint.
  4. trip `in_progress` + stop `in_progress` → outcomes + change‑method **exposed**; Start Delivery absent.
  5. §2: trip `loading` + stop `in_progress` (regressed) → **no** execution control exposed.
- Backend rejection guard intact (no backend file touched).
- Full driver‑mobile suite: **9 files / 75 tests pass** (no regression).
- `tsc -p tsconfig.app.json`: **0 new errors** in touched files (23 pre‑existing errors are all in unrelated features — ratchet held).

**B — Reports (backend, read‑only end‑to‑end against DEV)**
- `route:list --path=driver` resolves all six report/wallet routes (class‑not‑found gone).
- Real controller (via container DI) + `Auth::login(driver 396)` + real service + DEV DB:
  `orders` → HTTP **200** `{period,summary,items,meta}`; `goods-movement` → **200**; `shortages` → **200**; `advances` → **200**; `statement` → **200**.
- Period filters resolved server‑side (`resolvePeriod`); scoped to driver 396's company; Error ≠ Empty preserved in the UI.

**C — Wallet (backend, read‑only end‑to‑end against DEV)**
- `wallet` → HTTP **200** `{data}`; computed `trips=3`, `collections.total=0`, `settlement_status=needs_review` for driver 396.
- Driver‑scoped read (own company + own trips); no React lifetime aggregation introduced; Error ≠ zero‑balance preserved in the UI.

**D — Runtime**
- Source/runtime parity confirmed: 2 files copied; `route:list` clean; Vite :5173 serves the updated module; `/api` proxied to the fixed `ecos-dev-app`.
- No route cache cleared (none existed); no unrelated route broken (`route:list` renders the full driver group without error).
- Read‑only probes only; container `/tmp` probe scripts removed afterward.

*Not run (per task): full system certification.*

## 15. DEV runtime status

- **Backend:** `ecos-dev-app` now has both Phase‑6 files; all six Wallet/Reports endpoints return 200. **CURRENT.**
- **Frontend:** Vite :5173 (the DEV SPA surface) serves the edited source via HMR; gating fix live. **CURRENT.**
- Production‑like static bundle (`ecos-nginx` :80/:443) is out of DEV scope (no deploy outside DEV).

## 16. Remaining Driver‑App gaps (out of scope, documented)

- **Schema:** migration `2026_08_28…add_return_receipt_classification_to_reconciliation_lines` not run in DEV (§10). Additive, safe, zero current data impact (0 recon rows). Not applied.
- Pre‑existing, previously‑documented Driver‑App gaps remain unchanged (partial delivered‑qty writer, driver‑liability/waste backend authority, advances/expenses authority). **Not** in this task's scope; nothing regressed.
- 23 pre‑existing `tsc` errors in unrelated features (admin/hr/marketing/orders/stock‑ledger) — untouched baseline.

## 17. Implementation status

```
IMPLEMENTATION STATUS:  COMPLETE
DEV RUNTIME:            CURRENT
FINAL CERTIFICATION:    DEFERRED TO FINAL SYSTEM REVIEW
```

Constraints honoured: no redesign · no new business authority · no Finance work · no commit · no push · no deploy outside DEV · no DEV business‑data mutation · backend guard not weakened · driver isolation not weakened.
