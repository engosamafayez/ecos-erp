# TASK-DRIVER-DELIVERY-UI-WIRING-001 — Driver Delivery UI Wiring

**Date:** 2026-08-26
**Scope:** frontend only — wire the existing stop-detail read model + `POST /driver/stops/{stopId}/deliver` into the existing Driver Stop Detail screen. No backend, no schema, no lifecycle logic in the frontend.
**Checks:** `tsc -p tsconfig.app.json` 0 driver-mobile errors · ESLint 0 · i18n EN/AR parity clean · driver-mobile vitest 29/29. No browser E2E, no live-data mutation, **not** Browser Certified.

---

## DONE

- **Per-line Required / Delivered / Remaining** on the stop detail: each item now shows `Required` (ordered), `Delivered`, and `Remaining` (from the canonical read model — `ordered_qty` / `delivered_qty` / server-computed `remaining_qty`). A fully-delivered line shows a green **Complete** badge; a partially-delivered line shows an amber **Partial** badge, so delivered vs. remaining is clearly distinguished and a partial stays visibly incomplete.
- **Delivery action** wired to the existing endpoint: a **Record delivery** button posts to `POST /driver/stops/{stopId}/deliver` via a new `submitStopDelivery` service call + `useSubmitStopDelivery` hook. No second writer, no new endpoint.
- **Cumulative absolute quantity:** each line's input is the *total* delivered so far; the payload sends that cumulative value, never a delta. A **Deliver all** shortcut sets every line to its required quantity (the common full-delivery case). Only changed lines are sent (idempotent, minimal).
- **Full / partial / repeated** all supported: full (set to Required → backend settles Delivered), partial (set below Required → stop stays open), and repeated cumulative updates (raise the total again later) all flow through the same card.
- **UI guards (client-side, backend still authoritative):** `Remaining = Required − Delivered` is shown; the input `max` is the required quantity and `min` is the amount already delivered; the Record button is disabled when nothing changed or any total is out of range, with an inline hint. The backend remains the hard authority (over-delivery / insufficient-custody / reduce all 422 and surface below).
- **Backend-authoritative stop state (no invented lifecycle, no optimistic UI):** on success the mutation **invalidates and re-reads** the canonical stop detail (`K.stopDetail(tripId, stopId)`) plus the stop list and trips. Delivered / Remaining and the stop status badge come straight from the refreshed backend response — the stop shows **Delivered** only because the backend settled it (all lines complete). Nothing is marked delivered before a successful response.
- **Error handling:** backend validation errors are shown inline under the delivery card (`deliverMutation.error.message`), plus a client-side range hint; a success toast confirms recording. No optimistic quantity is applied.
- **Ownership / security:** reuses the existing driver-owned `/driver/*` endpoints (fail-closed `ownedStop`, `loading.driver.operate`). The frontend touches no allocation logic, no warehouse inventory, and creates no writer.
- **Reused the existing visual language:** the same rounded-border cards, badges, `Input`, `Button`, and mobile layout — the delivery view replaced the old plain "Products" list in place; no redesign.
- **Removed the misleading `completed` status button:** the delivered path is now the canonical quantity card, so marking "Delivered" as a status (which bypassed quantity capture) was removed; the remaining status buttons (Refused / Not available / Delay / Wrong address / Unreachable) are relabeled under **Report an issue** for genuine non-delivery outcomes.
- **Types + i18n:** added `order_line_id` to `StopOrderLine` (the backend already emits it); added `stop.reportOutcome` + `stop.delivery.*` keys in EN **and** AR (parity verified). No hardcoded UI strings.

## NOT DONE

- **Browser E2E / visual confirmation in a running app** — deliberately not performed (per the task's verification rule: static/type/build checks only, no live-data mutation, no Browser Certified claim). The wiring is verified by types, lint, i18n parity, and the frontend test suite.
- **Automated component test for the new delivery card** — not added (the existing driver-mobile suite still passes 29/29; the task asked for static/build checks, not new tests). Can be added on request.

## NO LONGER NEEDED

- The **`completed` delivery status button** — superseded by the canonical delivered-quantity card (a status-only "Delivered" would have bypassed `delivered_qty`).
- The **old plain Products card** (`ordered_qty × name | line_total`) — replaced in place by the Required/Delivered/Remaining + delivery-input card.
- Any **frontend delta/increment logic** — the endpoint is cumulative-absolute, so the UI never computes or sends increments.

## NEXT

- **Frontend-complete for the happy path.** Optional polish (not required): proactively reflect the trip's on-the-road state (today the backend returns a clear 422 if a delivery is attempted before dispatch, shown inline); add a focused component test for the delivery card; and wire secure delivery **proof-of-delivery** capture once `TASK-DELIVERY-POD-SECURE-UPLOAD-001`'s driver UI is enabled (separate task).
- The **shortage split** (one product's custody short of several lines' demand) remains an owner decision if it arises for driver trips (documented in the bridge report); the UI degrades safely — the backend refuses beyond on-hand with a clear message.

## Files changed (frontend only; uncommitted)

- `frontend/src/features/operations/driver-mobile/types/driver-mobile.ts` — `order_line_id` on `StopOrderLine`.
- `frontend/src/features/operations/driver-mobile/services/driver-mobile-service.ts` — `StopDeliveryLine` + `submitStopDelivery`.
- `frontend/src/features/operations/driver-mobile/hooks/use-driver-mobile.ts` — `useSubmitStopDelivery` (invalidates the canonical stop detail).
- `frontend/src/features/operations/driver-mobile/pages/driver-stop-detail-page.tsx` — Required/Delivered/Remaining + cumulative delivery card; removed `completed` button; `reportOutcome` label.
- `frontend/src/i18n/locales/{en,ar}/driver-mobile.json` — `stop.reportOutcome` + `stop.delivery.*`.

No backend, migration, schema, permission, route, or allocation/custody change. Warehouse→Vehicle transfer, `ShipStockAction`, `RecordProductDeliveryAction`, `AllocationRecord`, `DeliveryService`, Loading-Complete gate, and Day Settlement are untouched.

---

## Is the Driver Delivery flow now frontend-complete?

**Yes — frontend-complete for full and partial delivery.** The driver can now, from the existing stop-detail screen, see Required/Delivered/Remaining per line and record full or partial delivery (cumulative) through the canonical writer, with the stop settled Delivered only when the backend confirms every line is complete. **No backend capability remains missing** for this flow — the endpoint, allocation bridge, custody movement, and `delivered_qty` projection all pre-existed and are reused as-is. The only outstanding item is optional (browser verification / a component test / secure POD capture), none of which blocks the delivery flow.
