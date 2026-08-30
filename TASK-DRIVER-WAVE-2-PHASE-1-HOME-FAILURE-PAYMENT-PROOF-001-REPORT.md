# TASK-DRIVER-WAVE-2-PHASE-1-HOME-FAILURE-PAYMENT-PROOF-001 — REPORT

**Date:** 2026-08-24
**Mode:** Safe completion slice — reuse canonical engines, no new migrations, respect the STOP conditions.
**Final status:** **IMPLEMENTED (all three slices) / VERIFIED (static + live endpoints + certified security regression) / UI RENDER + MUTATION PENDING (no legitimate driver data; driver login cannot be self-provisioned) / 2 OWNER DECISIONS reported (both were STOP conditions, correctly not invented).**

This task delivered exactly three areas — **A. Driver Home (W1-02)**, **B. Failure/Delay vocabulary (W2-04)**, **C. Payment-transfer proof (W2-03)** — each by reusing an existing canonical engine, adding **no migration**, and **stopping** where the existing system does not define behaviour (Part B lifecycle, Part C proof-type marker) rather than inventing it. Wave 3 was not started; the frozen Wave-1 architecture was not touched.

---

## Per-part status

| Part | Status |
|---|---|
| **A — Driver Home** | IMPLEMENTED / VERIFIED (static + Vite transform + read endpoints 401-guarded). Populated-card render PENDING (needs a driver with an assigned shipment — 0 exist). |
| **B — Failure vocabulary** | IMPLEMENTED / VERIFIED (static). Driver now records a **canonical** `FailureReason`. **Lifecycle wiring (terminal/retryable/delay) = OWNER DECISION** — reported, not invented (STOP respected). |
| **C — Payment-transfer proof** | IMPLEMENTED / VERIFIED (static + endpoint 401-guarded). Driver can **upload only**, into canonical `payment_proofs`. **Proof method/type marker = OWNER DECISION** — no migration created (STOP respected). Upload mutation PENDING (needs a real order/stop). |

---

## Part A — Driver Home (W1-02)

The Home is now the operational entry point. It is built **entirely from existing canonical reads** — it does not add a second aggregation service.

- **Header** — driver identity (name + role) from the auth context.
- **Current Shipment** — assigned-order count (`Σ trip.stops_count`, the canonical assigned-order count; one stop = one order).
- **Operations → Loading card** — from the Wave-1 manifest (`useDriverLoading` → `GET /driver/loading`): "*X of Y products loaded*", a "Loaded" badge when `loading_complete`, and a single CTA that reads **Start / Continue / View Loading** → `ROUTES.driverLoading`.
- **Operations → Delivery card** — from the delivery-stop outcome model (`useDriverStops` on the current trip): **Stops / Delivered / Remaining** counts, a "*N failed / partial*" line when any exist, and a **Start / Continue Delivery** CTA → `ROUTES.driverTripStops`.
- **Empty state** — when no shipment is assigned, a single "*No shipment assigned yet*" panel (unchanged from the browser-verified Wave-1 Home). **No empty cards full of zeros** are shown.

**No Wallet / Money / Reports / Earnings** — those are Wave 3 and there is no canonical driver-financial read to source them from.

Reused: `useDriverTrips`, `useDriverLoading`, `useDriverStops`, `ROUTES.driverLoading`, `ROUTES.driverTripStops`. New logic: none beyond composing these reads.

## Part B — Failure / Delay vocabulary (W2-04)

The driver's free-text "reason" field is replaced by the **canonical** `Modules\Logistics\Delivery\Domain\Enums\FailureReason` vocabulary. **No second vocabulary was created.**

- **Backend** — `GET /api/driver/failure-reasons` → `DriverRuntimeController::failureReasons()` returns `FailureReason::catalogue()` **verbatim** (15 canonical reasons with category / retryable / address-correction flags). The enum is the single source of truth.
- **Frontend** — in `delivery-action-form.tsx`, the failure-outcome reason (`refused / not_available / wrong_address / unreachable`) is now a `Select` whose options come **from the backend catalogue at runtime**. Display labels are localized (EN/AR) with `defaultValue: opt.label` falling back to the backend English label — the i18n map never decides *which* reasons exist. A local key-union types the label lookup only (the same idiom as `ResultKey` / `ReservationStatus` already in the codebase).

**STOP condition respected — lifecycle NOT invented.** The stop lifecycle (`DeliveryStopStatus`) is still driven **only** by `action_type` via the existing `outcomeFor()`. `FailureReason::isRetryable()` / `category()` exist but **nothing in the driver stop lifecycle consumes them**, and I did not wire them in.

> **OWNER DECISION REQUIRED (B):** Should a non-retryable reason force a terminal `failed` outcome, and a retryable one permit a re-attempt or `delay`? Today the reason is descriptive; `action_type` alone settles the stop. Wiring `isRetryable → terminal/retry` is a lifecycle change and was left to the owner.

## Part C — Payment-transfer proof (W2-03)

The driver can **upload a payment-transfer proof file** into the **canonical `payment_proofs`** store via the canonical `UploadPaymentProofAction`. `distribution_payment_collections` was **not** used.

- **Backend** — `POST /api/driver/stops/{stopId}/payment-proof` → `DriverRuntimeController::uploadPaymentProof()`: resolves the stop **fail-closed** via `ownedStop()`, resolves the linked order, validates the file (`jpg/jpeg/png/pdf`, ≤10 MB), and calls `app(UploadPaymentProofAction::class)->execute($order, $file)`. The proof is created with state `uploaded`.
- **Frontend** — an "**Upload payment proof**" button + sheet on the stop-detail page (`PaymentProofUploadForm`): a single file input → multipart POST. The copy states plainly that verification is done by the office.

**Driver cannot change financial state — enforced, not just labelled:**
- The endpoint only ever creates an `uploaded` proof; it never verifies, approves, settles, or modifies any financial record.
- The verify / reject routes (`payment-proofs/{proof}/verify|reject`) are gated on `sales.orders.proof_verify` / `proof_reject` — **operator** permissions. The driver holds `loading.driver.operate`, which does **not** grant them. There is no verify/settle control anywhere on the driver surface.

**STOP condition respected — no migration created.** I verified `payment_proofs` has **no method/type column**. The driver-uploaded proof is therefore a generic `payment_proofs` row (state `uploaded`, `order_id` FK) with **no** "payment-transfer" discriminator. I did **not** add a column.

> **OWNER DECISION REQUIRED (C):** If the business must distinguish a *payment-transfer* proof from other proof types (e.g. a `method`/`type` column on `payment_proofs`), that is a migration and needs authorization. It was not created.

---

## Files changed

| File | Change |
|---|---|
| `…/Distribution/…/Controllers/DriverRuntimeController.php` | `+ failureReasons()` (Part B), `+ uploadPaymentProof()` (Part C); imports `FailureReason`, `UploadPaymentProofAction` |
| `backend/routes/api.php` | `+ GET /driver/failure-reasons`, `+ POST /driver/stops/{stopId}/payment-proof` (same `auth:sanctum` + `permission:loading.driver.operate` group) |
| `…/driver-mobile/pages/driver-home-page.tsx` | Home reworked: Current-Shipment + Loading card + Delivery card + empty state (Part A) |
| `…/driver-mobile/pages/driver-stop-detail-page.tsx` | "Upload payment proof" button + sheet (Part C) |
| `…/driver-mobile/components/delivery-action-form.tsx` | reason free-text → canonical `FailureReason` dropdown (Part B) |
| `…/driver-mobile/components/payment-proof-upload-form.tsx` | **new** — driver upload-only proof form (Part C) |
| `…/driver-mobile/services/driver-mobile-service.ts` | `+ fetchFailureReasons()`, `+ uploadPaymentProof()` |
| `…/driver-mobile/hooks/use-driver-mobile.ts` | `+ useFailureReasons()`, `+ useUploadPaymentProof()` |
| `…/driver-mobile/types/driver-mobile.ts` | `+ FailureReasonOption`, `+ DriverPaymentProofResult` |
| `…/i18n/locales/{en,ar}/driver-mobile.json` | `+ home.cards.*`, `home.shipmentLabel`, `stop.failureReason.*`, `stop.paymentProof.*`, `failureReasons.*` (15) — EN/AR parity |
| `frontend/eslint-suppressions.json` | `delivery-action-form.tsx` hardcoded-string count ratcheted **11 → 9** (localised 2 strings; ratchet, not a cliff) |

**No migration. No Wave-1 file touched** (`LoadProductAction`, `VehicleInventoryService`, `DriverLoadingController` unchanged).

---

## Verification

**Backend**
- `php -l` clean; **Pint** passed; **PHPStan** exit 0 on the modified controller.
- Routes registered (testrunner **and** app container): `GET api/driver/failure-reasons`, `POST api/driver/stops/{stopId}/payment-proof` — both on `DriverRuntimeController`.
- Dependencies present in the app container: `FailureReason` (+`catalogue()`), `UploadPaymentProofAction`.
- **Live auth guard (via the user's own Vite proxy at `localhost:5173`):** `GET /api/driver/failure-reasons` → **401**, `POST /api/driver/stops/{id}/payment-proof` → **401** (route wired, middleware intact, no fatal). Same 401 via dev nginx `:8081`.
- **Certified security regression** — `DriverRbacTenancySecurityTest` through the isolation gate after `docker cp` + `route:clear`: **`OK (21 tests, 42 assertions)`**. The certified RBAC/tenancy baseline for the controller I edited is intact.

**Frontend**
- `tsc -p tsconfig.app.json`: **23 baseline errors, 0 in driver-mobile** (my files are type-clean; baseline unchanged).
- **ESLint** on all changed files: **0 real errors** (exit 0 with `--pass-on-unpruned-suppressions`); the only signal was a now-stale suppression count, ratcheted to the true value (11→9).
- **i18n parity**: EN 130 keys = AR 130 keys, **no EN-only / AR-only** — full parity.
- **Vite dev server (`localhost:5173`)**: root `/app/` → 200; all four changed TSX modules transform → **200** (no syntax/transform error — the app is not blank).

## Operational note — a route break I introduced and fixed

Copying my updated `api.php` into `ecos-dev-app` surfaced a **pre-existing** inconsistency: the app container had never received Wave-1's `DriverLoadingController`, so my api.php (which references it) made `route:list` throw *"Class …DriverLoadingController does not exist"* — i.e. the whole API route collection failed to build in that container. I restored it by deploying that one missing Wave-1 controller to `ecos-dev-app`; `route:list` then built the full driver table cleanly. The app container is now consistent with the host and healthy. (The testrunner already had it, hence the passing test.)

## Browser verification — PENDING, stated plainly

**The UI render and all mutation paths are NOT browser-verified.** Reason: there is **zero legitimate driver/shipment data** (0 trips, 0 stops, 0 orders assigned to a driver), and reaching `/driver/*` requires an authenticated driver session which I cannot self-provision (entering credentials is prohibited; fabricating a driver + assignment is forbidden).

- The **Loading/Delivery cards** (Part A) render only with an assigned shipment → not reachable with legitimate data.
- The **failure dropdown** (Part B) and **proof upload** (Part C) mutations require a real owned stop/order → not reachable with legitimate data.
- The **empty state** (Part A) is structurally unchanged from the Wave-1 Home that was browser-verified previously.

What *is* proven live (no fabrication): both new endpoints answer **401** through the user's proxy (route + guard intact), and every changed module transforms cleanly in Vite. **I am not claiming UI mutation verification.**

## Data safety

No live business data was created or modified. No migration. No automatic repair / group movement / driver or vehicle reassignment. The driver endpoints only **read** (`failure-reasons`) or **create an `uploaded` proof** (`payment-proof`) for a real owned stop (none exist), and never write `Order.status` or any verified financial record.

## Frozen contract — explicit confirmation

Untouched: Group = Shipment; Group not split into Trips; one Group → one Driver + one Vehicle; Template assigns no drivers; Zone → Group; Loading via `LoadProductAction` + `VehicleInventoryService` with actual-loaded → inventory, over-load refused, accumulation intact; Driver RBAC/tenancy; `loading.driver.operate` as the driver permission. No second Loading/Delivery engine and no competing source of truth were introduced. Wave 3 not started; Template Driver Recommendations not implemented.

---

## Final status

**IMPLEMENTED (Driver Home, canonical Failure vocabulary, canonical Payment-transfer proof) / VERIFIED (php-l · Pint · PHPStan 0 · routes · 401 auth-guard via the user's proxy · tsc 23-baseline/0-mine · ESLint 0 · i18n 130/130 · Vite 200 · DriverRbacTenancySecurityTest 21/21) / UI RENDER + MUTATION PENDING (no legitimate driver data; driver login not self-provisionable) / 2 OWNER DECISIONS reported — Part B lifecycle semantics and Part C proof method column, both STOP conditions correctly not invented.**
