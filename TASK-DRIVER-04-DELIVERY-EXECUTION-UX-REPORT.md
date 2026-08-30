# TASK-DRIVER-04 — DRIVER DELIVERY EXECUTION UX — DISCOVERY & VERDICT REPORT

**Mode:** CONTROLLED IMPLEMENTATION (discovery-first).
**Date:** 2026-08-26
**Verdict:** ⛔ **IMPLEMENTATION BLOCKED — OWNER DECISION REQUIRED** (see §19).
**Code changed this task:** none. Discovery was read-only; two of the task's central pillars (§6 partial, §8 proof) trip explicit STOP conditions, so I stopped after this report per §23.

---

## 1. Task & scope recap

Build the driver's **delivery-execution** experience after Loading: Loaded → Orders/Stops → Order detail → Delivery attempt → Delivered / Failed / Partial → next order, reusing the **existing canonical contract** — no second delivery engine, no invented statuses, no new proof store, inventory-neutral, tenant-safe, EN+AR.

The governing rule (§2): *"If a required business operation does not already exist: STOP and report the gap. Do not invent a backend engine simply to complete the UI."* §8 adds: *"If the current proof contract is unsafe or incomplete: STOP and report the exact issue."*

## 2. Discovery method

Read-only audit via 5 parallel Explore agents + direct verification of every consequential claim by reading the source myself (I did not take the agents' word on the two STOP-critical facts). Evidence is cited as `file:line`. Areas: delivery actions; partial/failed persistence + the `delivered_qty` writer question; proof safety; the driver delivery frontend; tenancy + inventory neutrality.

## 3. Canonical delivery-execution mechanisms that DO exist (and are safe to reuse)

| Capability | Canonical mechanism | Evidence | Status |
|---|---|---|---|
| Start a stop | `POST /driver/stops/{id}/start` → `DeliveryService::startStop` (stop → `in_progress`) | `DriverRuntimeController` startDelivery; route `api.php:3194` group | ✅ safe, exists |
| Record an outcome | `POST /driver/stops/{id}/action` → `DeliveryService::recordAction` + `completeStop` | `DriverRuntimeController.php:293-334` | ✅ safe (status only) |
| Outcome vocabulary | `DeliveryStopStatus` enum: Delivered / Partial / Failed / Returned / Skipped | `DeliveryStopStatus.php:9-15` | ✅ canonical |
| Action → status map | `outcomeFor()`: `completed`/`delivered`→Delivered; `partial`→Partial; `refused`/`not_available`/`wrong_address`/`unreachable`/`failed`→Failed | `DriverRuntimeController.php:569-577` | ✅ canonical |
| Failure reason vocabulary | `GET /driver/failure-reasons` → `FailureReason::catalogue()` (value+label+category+retryable) | `DriverRuntimeController.php:421-424` | ✅ canonical, exposed |
| Customer contact | order payload carries `phone`, `address`, `governorate/city/area`, `gps{lat,lng}` | `DriverRuntimeController.php:678-696` | ✅ safe |
| Payment-transfer proof | `POST /driver/stops/{id}/payment-proof` → validated multipart upload, private disk, server ULID path | `DriverRuntimeController.php:433-448`; `UploadPaymentProofAction` | ✅ **safe** |

**Two delivery stacks exist.** The driver hits **Stack B (Logistics/Distribution)** only. A richer **Stack A (Logistics/Delivery)** — `FailureReason` + `FailureCategory` enums, `DeliveryFailure` table, `DeliveryExecutionService::fail()` (`DeliveryExecutionService.php:266-328`) — is **not wired to the driver path**. The driver reads Stack A's `FailureReason` *catalogue* but writes Stack B (`DeliveryStop` + `DeliveryAction`).

## 4. Primary delivery action & state model (§5)

The stop's canonical state is server-authoritative: `DeliveryStop.status` (`pending → in_progress → delivered|partial|failed|returned|skipped`), surfaced verbatim in `stopSummary` (`DriverRuntimeController.php:623`). The existing UI already reflects this (`isPending`/`isDone` in `driver-stop-detail-page.tsx:61-62`) and renders Start Delivery + outcome buttons only while pending/in-progress. **No change required and none made.**

## 5. Partial delivery (§6) — ⛔ GAP: NO CANONICAL DELIVERED-QUANTITY WRITER

§6 requires showing **Required / Delivered / Remaining** distinctly and recording the *actual delivered quantity* where the contract permits. **The contract does not permit it:**

- `stopAction` validation accepts **no quantity of any kind** — only `action_type, reason, notes, new_delivery_date, corrected_lat, corrected_lng` (`DriverRuntimeController.php:297-304`).
- `distribution_delivery_stops` has **no qty column** (migration `2026_07_28_100003:25-36`); `distribution_delivery_actions` has **no qty column** (`2026_07_28_100004:22-29`).
- `action_type='partial'` sets `DeliveryStop.status = Partial` and nothing else; `Partial::isSettled()` is a terminal label carrying no quantity (`DeliveryStopStatus.php:31-36`).
- `order_lines.delivered_qty` exists (migration `2026_07_14_100001:23`, fillable `OrderLine.php:53`) but **has ZERO writers backend-wide** — verified by whole-backend grep: the only occurrences are the migration, the fillable entry, and **reads** (`OrderResource.php:204`, `DriverRuntimeController.php:709` & `:713`). The `delivered_qty` writes in the Delivery module are on a *different* table (`delivery_return_lines`, a return-declaration input — `DeliveryReturnController.php:61`), not the order line and not on the driver path.
- `DeliveryStopCompleted` (`DeliveryService.php:104`) has **no listener** anywhere, so nothing derives a delivered quantity from the outcome.

**Consequence (silent data loss):** if the driver "delivers 7 of 10", the 7 is captured nowhere. `remaining_qty` in the order payload computes `ordered − delivered_qty(=0) − … = full ordered` **forever** (`DriverRuntimeController.php:711-715`), and vehicle-inventory delivered totals can never reflect it.

**Why I did not build it:** a delivered-quantity input would either (a) post a number the backend silently discards — manufacturing the exact silent-data-loss defect above — or (b) require **inventing a fulfillment/inventory writer**, which §22 forbids ("inventing a new inventory movement") and §14 protects. **STOP per §2 + §22.**

## 6. Failed delivery (§7) — canonical reason model EXISTS and is already used

- The canonical vocabulary is `FailureReason::catalogue()`, exposed read-only to the driver at `GET /driver/failure-reasons` (`DriverRuntimeController.php:421-424`). Its own docstring states the intent: *"The driver UI records one of THESE values … it defines NO second vocabulary."*
- The existing UI already consumes it: `delivery-action-form.tsx:50` uses `useFailureReasons()` and renders the catalogue in a `Select` (`:87-103`); the chosen value is posted via `stopAction.reason`. So **§7 is satisfied for the UI** — the driver picks a canonical reason, not free text.
- **Limitation to note (not a UI blocker):** on the driver (Stack B) path the reason is persisted as a **free-text string** on `DeliveryAction.reason`, **not** as a structured `DeliveryFailure` row with `category`/`is_retryable`. `is_retryable` is surfaced but deliberately not acted on (`DriverRuntimeController.php:414-419`). Bridging the driver failure to Stack A's `DeliveryExecutionService::fail()` is a **backend** change outside §04 scope — flagged for the owner, not done.

## 7. Proof of delivery (§8) — ⛔ UNSAFE CONTRACT

§8 explicitly: audit the proof mechanisms first; if the contract is unsafe/incomplete, **STOP and report the exact issue; do not redesign proof without approval.** Two mechanisms, opposite safety postures:

- **Delivery proof (UNSAFE).** `POST /driver/stops/{id}/proof` validates `signature_path` / `photos.*` as **arbitrary strings** (`max:500`), not files (`DriverRuntimeController.php:339-344`). `DeliveryService::captureProof` stores them **verbatim** — no upload, no disk write, no existence/ownership check (`DeliveryService.php:116-122`); columns are plain string/JSON (`2026_07_28_100005:25-27`). All fields nullable → an **empty POST is accepted as valid proof**. A driver can submit any path string as POD (evidence-forgery / data-integrity risk on their own stops). The same weak contract is also exposed on the dispatcher route (`api.php:1922`).
- **Payment-transfer proof (SAFE).** `POST /driver/stops/{id}/payment-proof` is a validated multipart upload (`file` required, `max:10240`, `mimes:jpg,jpeg,png,pdf` — `:437-439`), stored to the private `local` disk with a server-generated ULID path and real MIME sniff via `UploadPaymentProofAction`.

**Why I did not build/keep the delivery-POD capture:** building a UI on the unsafe contract would present forgeable strings as proof; fixing the contract (validated upload, like the payment-proof engine) is a **backend** change §8 says needs explicit approval. **STOP per §8.**

## 8. Customer contact (§9) — safe, already present

Phone → `tel:` link; address → Google Maps deep-link built **only from the canonical order GPS** when present, else plain text (`driver-stop-detail-page.tsx:80-133`). No new maps/geocoding, no invented coordinates. **§9 satisfied; no change needed.**

## 9. Tenancy & permissions (§15) — fail-closed, verified

`driver()` resolves the `Driver` by `Auth::id()`, 403 if not a driver (`:528-534`). `ownedTrip()` double-fences on **company_id AND driver_id** via `firstOrFail()` (`:537-546`). `ownedStop()` resolves the stop then **re-runs full ownership on the parent trip** — *"never trust the stop id alone"* (`:557-567`) — so every bare-stopId write (start, action, proof, exception, payment-proof) re-asserts company+driver ownership. Route group gated `auth:sanctum` + `permission:loading.driver.operate` (`api.php:3194`), a driver-only permission. **No permission widening needed or made.** Driver A cannot touch Driver B's orders.

## 10. Inventory / custody / reservation neutrality (§14) — verified

`completeStop` writes **only** the `DeliveryStop` row (status/completed_at/attempted_at + notes/gps) and dispatches `DeliveryStopCompleted`, which **has no listeners** (`DeliveryService.php:78-107`). No `VehicleInventoryItem`, `StockLedger`, `StockMovement`, reservation, waste, or `delivered_qty` write occurs on a delivery outcome. Delivery is inventory-neutral today, exactly as §14 requires — and the partial-quantity gap (§5 above) must **not** be closed by wiring an inventory movement.

## 11. Frozen financial settlement & dead payment UI (§20) — consistency finding

- The driver "collect payment" route is wired to a **freeze handler**: `POST /driver/stops/{id}/payment` → `DriverRuntimeController::frozen` (`api.php:3246`), which returns **HTTP 403 "Financial settlement is frozen and not available from the driver runtime."** (`:452-457`).
- Yet the stop-detail page still renders a **"Record Payment"** button + `PaymentCollectionForm` calling `useCollectPayment` → that frozen 403 endpoint (`driver-stop-detail-page.tsx:49,249-258,298-308`), and `delivery-action-form.tsx:29,71-75,119-163` still embeds a **"Collect Payment"** block (payment_type/amount/reference) whose fields `stopAction` **silently strips** (money is frozen server-side, `:289`).
- §20 places Financial Settlement **out of scope**. This is **dead + contradictory UI**. Removing it is the right end-state, but it touches the frozen-money surface — so I flag it for the owner rather than editing it under a STOP-and-report mandate.

## 12. i18n status (§16) — gap in the existing action form

`driver-stop-detail-page.tsx` is largely i18n'd (typed `driver-mobile` keys). But `delivery-action-form.tsx` has **many hardcoded English strings**: "New Delivery Date *", "Collect Payment", "Payment Method", "Amount", "Reference Number", "Transaction reference…", "Notes", "Optional notes…", "Cancel", "Saving…", "Confirm" (`:108-183`). Only the failure-reason label/placeholder are translated. This violates §16 (EN+AR parity). *Most* of these strings belong to the payment block that §20 says should be removed anyway; the residual (date/notes/cancel/confirm) needs EN+AR keys. Deferred with §11 pending the owner decision.

## 13. Current frontend inventory (files that would be touched)

- `pages/driver-stop-detail-page.tsx` — stop detail + action/payment/proof sheets.
- `components/delivery-action-form.tsx` — outcome form (reason picker + dead payment block + hardcoded strings).
- `components/delivery-stop-card.tsx` — list card.
- `components/payment-proof-upload-form.tsx` — SAFE payment-proof upload (keep).
- `components/payment-collection-form.tsx` — frozen-money form (dead).
- `services/driver-mobile-service.ts` — `collectPayment` (→403), `submitDeliveryAction`, proof calls.
- `hooks/use-driver-mobile.ts` — `useSubmitDeliveryAction`, `useCollectPayment`, `useStartDelivery`, `useUploadPaymentProof`, `useFailureReasons`.
- `types/driver-mobile.ts` — `DeliveryStopStatus`, `DeliveryActionType`, `StopOrderLine` (already carries loaded/delivered/returned/remaining, all read-only).

## 14. What IS safely implementable now (no STOP condition)

Stop detail; start delivery; **mark Delivered**; **mark Failed with a canonical `FailureReason`**; customer call + directions; payment-transfer proof via the SAFE engine. **All of these already exist** — the only net-new work on the safe subset is a §16 i18n cleanup and removing the dead §20 payment UI, both of which depend on the owner decision in §19.

## 15. What is BLOCKED (owner decision)

1. **Partial delivered quantity (§6)** — no canonical writer; cannot record Delivered/Remaining truthfully without a backend fulfillment writer (forbidden by §22 to invent here).
2. **Delivery proof (§8)** — the delivery-POD contract is unsafe (arbitrary client strings, empty proof accepted); §8 forbids redesigning it here without approval.

## 16. STOP conditions triggered (§22)

- ✔ "a required business operation does not exist" → partial delivered-quantity writer (§5/§15.1).
- ✔ "proof contract unsafe/incomplete" (§8) → delivery-POD (§7/§15.2).
- Avoided: inventing a new delivery engine/status, inventing an inventory movement, inventing a failure-reason model, widening permissions, changing certified custody/reservation/`allow_negative_stock`. None done.

## 17. Tests (§17)

Not written this task — I stopped before implementing, per §23. The 16 focused frontend tests §17 enumerates are drafted as a plan (orders list / stop detail / delivered / partial / remaining / failed / failure-reason / proof-state / error / empty / no-work / tenancy / no-NaN / EN-AR parity / mobile action) and will be produced against whichever safe subset the owner approves in §19. The certified custody regression (45/45) and driver runtime suites remain green and untouched.

## 18. Browser verification (§18)

Not performed — no code changed, and §18 forbids creating/mutating business data merely to verify. A live active-delivery scenario also requires a trip on-the-road with unsettled stops in the owner's DEV data, which cannot be manufactured under §18/§22. If a safe subset is approved, verification will be **BROWSER PARTIALLY VERIFIED — SAFE DATA CONSTRAINT** unless the owner points to a safe live trip.

## 19. Verdict & owner-decision menu (§23)

**Final state: IMPLEMENTATION BLOCKED — OWNER DECISION REQUIRED.**

The delivery-execution UX §04 describes is *already present* for its safe parts; the two parts §04 uniquely mandates (partial quantity §6, delivery proof §8) are blocked by backend gaps/unsafety that §04 forbids me to close by inventing an engine. Decisions I need before writing any code:

- **A. Partial quantity:** (i) leave partial as a status-only outcome and **hide any quantity input** (truthful, no data loss, but no Delivered/Remaining capture); or (ii) approve a **separate backend task** to add a canonical delivered-quantity writer (`order_lines.delivered_qty` + reconciliation) — out of §04 scope; or (iii) drop partial from the driver UI for now.
- **B. Delivery proof:** (i) **do not expose** delivery POD until the contract is fixed; or (ii) approve a **separate backend task** to make delivery POD a validated upload mirroring the SAFE payment-proof engine; or (iii) reuse the existing SAFE payment-proof upload as the only driver proof surface for now.
- **C. Safe polish now?** May I, within §04, (i) remove the dead/frozen payment-collection UI (§11) and (ii) finish EN+AR i18n on the action form (§12), on the safe subset (delivered / failed-with-reason / contact / start)? This touches the frozen-money surface only to *remove* dead calls; no financial behavior is added.

**No further code will be written until these are decided (§23: "STOP after the report").**

---

## 20. Owner decisions received (2026-08-26) & execution

- **A (partial qty):** create a **separate backend task** for a canonical `delivered_qty` writer — audit first; STOP if it needs a new status/movement/proof/engine; then return to DRIVER-04 to wire the UI. Do **not** ship a fake/status-only partial UI meanwhile.
- **B (proof):** create a **separate backend task** `TASK-DELIVERY-POD-SECURE-UPLOAD-001` for a secure validated multipart upload (own report `TASK-DELIVERY-POD-SECURE-UPLOAD-001-REPORT.md`); do **not** reuse `PaymentProof` as the POD record; do **not** expose the unsafe POD endpoint to the driver UI; STOP after the backend task.
- **C (safe polish):** **approved** — remove the dead/frozen "Record Payment" UI + the stripped payment block; finish EN+AR i18n on the action form; keep delivered / failed-with-reason / contact / start intact; add no financial behavior.

### Part C — DONE (UI + i18n only), verified

**Removed (dead / frozen money surface, §20):**
- `driver-stop-detail-page.tsx`: the "Record Payment" button + its bottom-sheet (`useCollectPayment` → `POST /driver/stops/{id}/payment`, which returns 403 `frozen`); the `paymentMutation`; the `'payment'` sheet mode; the "Collected: {0}" line in the recorded-action summary.
- `delivery-action-form.tsx`: the embedded "Collect Payment" block (payment_type / amount / reference — fields `stopAction` silently strips) and its state.
- `components/payment-collection-form.tsx` — **file deleted** (only consumer was the removed button).
- `components/bank-transfer-form.tsx` — **file deleted** (orphaned frozen-money form, no importers).
- `delivery-stop-card.tsx`: the footer `collected_amount` chip (always 0 under the freeze) + now-unused `useFormatter`.
- `services/driver-mobile-service.ts`: `collectPayment()` writer + `CollectPaymentPayload` (kept read-only `fetchTripCollections`).
- `hooks/use-driver-mobile.ts`: `useCollectPayment` (kept `useTripCollections`).
- `types/driver-mobile.ts`: dead English label maps `ACTION_TYPE_LABELS`, `PAYMENT_TYPE_LABELS`.
- `DeliveryActionPayload`: dropped the 5 dead payment fields (`payment_type`, `payment_amount`, `reference_number`, `image_path`, `payment_notes`) — now exactly what `stopAction` accepts.

**De-exposed (B#14, unsafe POD):** removed the "Proof of Delivery" button from `driver-stop-detail-page.tsx` (it navigated to a `/proof` path that was not even a registered route). The unsafe `submitProofOfDelivery` service fn + orphan `proof-of-delivery-form.tsx` remain unmounted, to be replaced by `TASK-DELIVERY-POD-SECURE-UPLOAD-001`.

**Removed (fake status-only partial, decision A):** dropped `partial` from the stop-detail `ACTION_BUTTONS`. It returns once the backend `delivered_qty` writer exists.

**i18n keys — EN + AR (parity verified):**
- **Added** `actionForm.{newDate, notes, notesPlaceholder, cancel, confirm, saving}` (EN + AR) and switched `delivery-action-form.tsx` to `t()` for every string (header now `t($.actions[type])`).
- **Removed** `stop.recordPayment`, `stop.proofOfDelivery`, `stop.collected` (EN + AR — no remaining references).
- Parity: EN 244 / AR 248 keys; `only-in-EN = []`. The 4 extra AR keys are `loadingScreen.pendingConfirmations_{zero,two,few,many}` — Arabic CLDR plural categories for a **pre-existing** pluralized key (not touched here), which is correct i18n.

**Verification:** `tsc -p tsconfig.app.json` → 0 errors in driver-mobile (pre-existing baseline errors elsewhere untouched, per ratchet). `eslint` → 0 (pruned 3 now-stale suppression entries for the deleted/i18n'd files; `use-driver-mobile` count 22→20; 328 total entries, no unrelated entries touched). `vitest` → **29 passed** (26 existing + 3 new in `delivery-action-form.test.tsx`: no-money-collection payload, canonical failure-reason picker + submit-guard, localized labels). No backend changes. No business data mutated.

**Next:** backend task **A** (`delivered_qty` writer — audit-first) then **B** (`TASK-DELIVERY-POD-SECURE-UPLOAD-001` — audit-first), each per the owner's constraints; both STOP-and-report if implementation would require a forbidden change.
