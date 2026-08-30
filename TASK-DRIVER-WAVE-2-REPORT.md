# TASK-DRIVER-WAVE-2 — REPORT

**Date:** 2026-08-24
**Mode:** Wave 2 (delivery) — discovery first, implement only what is proven safe and contract-reusing.
**Final status:** **IMPLEMENTED (Started Delivery) / BLOCKED — OWNER DECISION REQUIRED (4 delivery enhancements) — BROWSER MUTATION PATH UNVERIFIED**

Wave 2 is the **delivery** experience. Discovery shows the basic surface already exists and is D-01-aligned (order cards, order detail, and delivery-outcome recording). Of the Wave-2 *enhancements*, exactly one — **Started Delivery** — can be delivered by reusing an existing service with no migration and no architecture change; it is implemented. The other four (partial-delivery quantities, delivery-proof upload, payment-transfer upload, failure-reason vocabulary) each require an owner architecture decision and/or an unauthorized migration, so per the STOP conditions they are reported, not worked around. There is **zero legitimate delivery data**, so the live mutation path is not browser-verifiable.

---

## Discovery

Wave-2 scope (from the CORE-LOADING task's "do not implement Wave 2" list): driver order cards, order filters, **Started Delivery**, order details, **partial delivery to customer**, **delivery proof**, **payment-transfer upload**, **failed delivery**. Current state (verified against live code, not just the pre-D-01 audit):

| Wave-2 item | State | Evidence |
|---|---|---|
| Order cards | **Exists, D-01-aligned** | `stops` → `stopSummary` returns the canonical order payload (D-01 fix) |
| Order details | **Exists, D-01-aligned** | `stopDetail` field names (phone/address/gps/ordered_qty) aligned in D-01 |
| Delivery outcome (delivered/failed/partial-status) | **Exists** | `stopAction` → `DeliveryService::recordAction` + `completeStop`; `outcomeFor()` maps action → `DeliveryStopStatus` |
| **Started Delivery** | **Gap → implemented here** | driver had no stop-start endpoint; `DeliveryService::startStop` existed but was dispatcher-only (`logistics.distribution.update`) |
| Order filters (zone/area) | Minor gap | status tabs + search exist; no zone filter (needs zone data — none exists) |
| **Partial delivery quantities** | **BLOCKED** | `stopAction` has no quantity field; `order_lines.delivered_qty` has zero writers; the canonical quantity engine is `RecordProductDeliveryAction` (allocation-based, warehouse-only) |
| **Delivery proof (file)** | **BLOCKED (AD-6)** | neither proof stack accepts an `UploadedFile`; the `/proof` frontend route does not exist; `ProofOfDeliveryForm` orphaned |
| **Payment-transfer upload** | **BLOCKED** | canonical `payment_proofs` unused on the driver path; `distribution_payment_collections` has **no `company_id`**; money surface is frozen (D-01/D-02) |
| **Failure reason vocabulary** | **BLOCKED (AD-10)** | Stack B (driver) has no `FailureReason` enum; `delay` never settles the stop |

**Live delivery data:** `distribution_delivery_stops` 0 · `distribution_delivery_proofs` 0 · `distribution_payment_collections` 0 · `allocation_records` 0 (22 `order_lines` exist, none delivered). The delivery mutation path cannot be exercised without fabricating a driver + trip + stop, which is forbidden.

## Existing contracts reused

- `DeliveryService::startStop` — the canonical per-stop start (sets `DeliveryStopStatus::InProgress` + `attempted_at`, refuses an already-settled stop, **never** writes `Order.status`).
- `DriverRuntimeController::ownedStop()` — the certified D-02 fail-closed ownership resolver (driver-owned AND company-owned).
- `loading.driver.operate` — the existing driver permission (no new permission).
- The D-01-aligned `stopSummary` payload for the response.

## Implementation (Started Delivery)

**Backend** — `POST /api/driver/stops/{stopId}/start` → `DriverRuntimeController::startDelivery`: resolves the stop fail-closed via `ownedStop()`, delegates to `DeliveryService::startStop`, returns the updated stop summary; a `DistributionException` (e.g. already-settled) becomes a 422. No new engine, no `Order.status` write, no migration.

**Frontend** — a "Start Delivery" button on the stop-detail page, shown only when the stop is `pending`; on tap it calls `useStartDelivery` → the endpoint and refreshes the stop (→ `in_progress`), after which the existing outcome buttons record delivered/failed/partial. Strings are in the `driver-mobile` namespace (EN + AR).

## Files changed

| File | Change |
|---|---|
| `…/Distribution/…/Controllers/DriverRuntimeController.php` | `+ startDelivery()` + `DistributionException` import |
| `backend/routes/api.php` | `+ POST /driver/stops/{stopId}/start` (same `loading.driver.operate` group) |
| `…/driver-mobile/pages/driver-stop-detail-page.tsx` | "Start Delivery" button (pending stops) + `useStartDelivery` |
| `…/driver-mobile/services/driver-mobile-service.ts` | `+ startDelivery()` |
| `…/driver-mobile/hooks/use-driver-mobile.ts` | `+ useStartDelivery()` |
| `…/i18n/locales/{en,ar}/driver-mobile.json` | `+ stop.startDelivery / starting / startFailed` |

**No migration. No Wave-1 file touched** (`LoadProductAction`/`VehicleInventoryService`/`DriverLoadingController` unchanged from Wave 1; `VehicleInventoryService` shows no diff).

## Backend changes

One thin method + one route, both delegating to the existing `DeliveryService`. php -l / Pint / **PHPStan No errors**.

## Frontend changes

One button + service call + hook + 3 i18n keys. `tsc` = 23 baseline (none in touched files); ESLint exit 0; i18n parity **92/92**.

## Permissions

Reuses `loading.driver.operate`. No new permission; `operations.preparation.update` (the dispatcher's stop-start gate) is **not** granted to the driver — the driver reaches `startStop` through the fail-closed `/api/driver/*` adapter instead.

## Tests

- **D-02 `DriverRbacTenancySecurityTest`** (regression — the controller I edited): run via `GATE_WAIT=2400 ./scripts/test-gate.sh` after `docker cp` + `route:clear` → **`OK (21 tests, 42 assertions)`** — the certified security baseline is intact after adding `startDelivery`.
- No dedicated `startDelivery` test was added: it is a thin delegation to `DeliveryService::startStop` (which has its own coverage) behind the certified `ownedStop()` ownership, and a fixture (owned trip + pending stop) plus zero live data make an isolated happy-path test high-cost/low-signal — noted as a follow-up.

## Browser verification

**BROWSER MUTATION PATH NOT VERIFIED — NO LEGITIMATE DRIVER DELIVERY DATA.** The stop-detail "Start Delivery" button only renders for a real `pending` stop, and there are 0 stops / 0 trips assigned to a driver, so it cannot be reached with legitimate data (fabrication forbidden). The frontend compiles and lints clean; the button is gated behind a real owned pending stop.

## Data safety

No live business data created or modified; no migration; no automatic repair / group movement / driver or vehicle reassignment. `DeliveryService::startStop` writes only the STOP status, and only for a real owned pending stop (none exist).

## Remaining blockers (owner decisions)

1. **Partial delivery quantities.** Route the driver to the canonical `RecordProductDeliveryAction` (allocation-based) rather than a fourth quantity store; requires writing `order_lines.delivered_qty` (currently zero writers) and depends on allocation existing after loading. **Migration + wiring — owner decision.**
2. **Delivery proof upload (AD-6).** Neither proof stack accepts a file. Adopt one storage pattern (`payment_proofs` uploader / generic `documents` table / `DriverController::storeDocument`) and add the `/proof` route + hook. **Architecture decision + migration.**
3. **Payment-transfer upload.** The driver path must use the canonical `payment_proofs` lifecycle, not `distribution_payment_collections` (which has no `company_id`). The money surface is frozen. **Architecture + tenancy decision + migration.**
4. **Failure reason vocabulary (AD-10).** Give the driver stack a `FailureReason` enum (adopt Stack A's or define a Distribution one), and settle the `delay` outcome. **Owner decision.**

Each of these hits a Wave-2 STOP condition (an unauthorized migration and/or a competing engine/architecture decision), so they are reported here for an owner ruling rather than implemented.

## Wave 1 remains intact — explicit confirmation

No change was made to: the Group = Shipment contract, One Group → One Driver + One Vehicle, the Loading manifest contract, `LoadProductAction`, `VehicleInventoryService`, vehicle-inventory accumulation, Group capacity / overflow / Finalize / idempotency, Zone → Group mapping, Template → Group snapshot, or Driver RBAC/tenancy. The Started-Delivery slice writes only `DeliveryStop.status` (the delivery stack) and never touches loading, the group, or `Order.status`.

---

## Final status

**IMPLEMENTED (Started Delivery) / BLOCKED — OWNER DECISION REQUIRED (partial-delivery quantities, delivery-proof upload, payment-transfer upload, failure-reason vocabulary) — BROWSER MUTATION PATH UNVERIFIED.** Wave 3 was not started.
