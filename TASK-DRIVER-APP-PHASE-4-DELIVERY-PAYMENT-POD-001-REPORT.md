# TASK-DRIVER-APP-PHASE-4-DELIVERY-PAYMENT-POD-001

## Executive Summary

Phase 4 completes the driver's customer-stop execution flow on top of the **existing canonical
delivery / payment / POD authorities** — no new writer, no new fulfillment engine, no
`Order.status` mutation from React. The big change is **gating (§1)**: before Start Delivery the
stop shows only the Start Delivery control; after the canonical transition to out-for-delivery,
Phone, WhatsApp, the delivery-quantity card, the classified failed-delivery outcomes, the
payment proof and the POD are revealed. Payment proof and POD are now **camera-first**.

Most delivery/POD scaffolding already existed (from prior certified work); this phase wired the
§1/§2 gating, added the WhatsApp action and camera capture, and pinned the gate with tests.

**One requirement is BLOCKED and documented, not worked around:** §7 (payment-method change →
`ReevaluateOrderFulfillmentAction`) has **no canonical driver endpoint**. Per §7/§12 I did not
build a new engine or route; the missing transition is specified in §Payment Method Change.

Backend: **NONE** · RBAC: **UNCHANGED** · Live business data: **UNTOUCHED** ·
Frontend: 6 files (1 new test) · Commit/Deploy: **NONE**

**IMPLEMENTATION STATUS: PARTIAL** (everything expressible through existing canonical actions is
done; §7 blocked on a missing backend transition).
**FINAL CERTIFICATION: DEFERRED.**

Date: 2026-08-28 · Branch: `develop`

---

## Start Delivery Lifecycle (§1)

The Start Delivery button (shown only while `stop.status === 'pending'`) calls the existing
canonical `useStartDelivery(tripId, stopId)` → `POST /api/driver/stops/{id}/start`. The UI
**never writes `Order.status`** — the backend performs the lifecycle transition to
out-for-delivery (`in_progress`). Nothing else is exposed before that transition.

## Communication Gating (§1/§2)

A single derived gate, `deliveryStarted = stop.status !== 'pending'`, controls all
execution/contact affordances:

| Control | Before Start (pending) | After Start (in_progress) |
|---|---|---|
| Start Delivery | **shown** (only control) | hidden |
| Phone (`tel:`) | hidden (a "contact after start" hint shows) | **shown** |
| WhatsApp (`wa.me`) | hidden | **shown** (new) |
| Delivery-quantity card actions | read-only | **editable** |
| Failed-delivery outcomes | hidden | **shown** |
| Payment proof / POD | hidden | **shown** |
| Address / Maps | shown (need to reach the customer) | shown |

Phone uses the canonical `order.phone`; WhatsApp uses the same number, digits only
(`https://wa.me/<digits>`); Maps opens only when the order carries canonical `gps` (no
fabricated coordinates). Pinned by `driver-stop-detail-gating.test.tsx` (before → only Start;
after → phone + WhatsApp + outcomes + proof + POD).

## Full Delivery (§3)

Unchanged canonical path: the per-line delivery card → `useSubmitStopDelivery` →
`POST /api/driver/stops/{id}/deliver` → **`RecordProductDeliveryAction`** (the sole
delivered-quantity writer). "Deliver all" fills every line to its ordered quantity. No second
delivered-quantity authority.

## Partial Delivery & Quantity Semantics (§4)

The card records **actual cumulative delivered totals per line** (the certified
cumulative-absolute semantics, not incremental): the input defaults to the canonical
`delivered_qty`, is bounded `[delivered_qty, ordered_qty]` client-side as a courtesy, and only
**changed** lines are sent (minimal + idempotent). The **backend is authoritative** on
`0 ≤ delivered ≤ eligible` — the client bound never replaces the server guard. The stop is
settled Delivered/Partial by the backend when lines complete; the UI invents no lifecycle.

## Vehicle Custody Integration (§5)

Unchanged and preserved. Delivered quantities reconcile with vehicle custody through the
certified bridge: allocation (`EnsureStopDeliveryAllocationsAction`) →
`RecordProductDeliveryAction` → `order_lines.delivered_qty` → `VehicleInventoryService`
custody movement. **No vehicle inventory is written from React.**

## Payment Summary (§6)

Shows Final Total, Deposit, Amount to Collect, and the **full payment-method label** (Cash on
Delivery / InstaPay / Bank Transfer / …, from Phase 3). **Merchandise Subtotal / Shipping /
Discount are not in the canonical driver stop read** and are omitted rather than fabricated —
the read-contract extension for them is documented in the Phase-3 report and carried forward.

## Payment Method Change & Fulfillment Re-evaluation (§7) — **BLOCKED / documented**

**There is no canonical driver endpoint to change a payment method.** `DriverRuntimeController`
only *reads* `payment_method` (settlement/summary); the live route table has no
`.../payment-method` or reevaluation route under `/api/driver/*`.

Per §7 ("Do NOT create a new fulfillment engine", "Do NOT directly change fulfillment/reservation
behavior in the Driver UI") and §12 ("document the missing transition"), I did **not** build it.

**Missing canonical transition (for a later backend task):**
```
PATCH /api/driver/stops/{stopId}/payment-method   (or /orders/{id}/payment-method)
  auth   : auth:sanctum + permission:loading.driver.operate, fail-closed to the driver's stop
  body   : { payment_method: 'cod' | 'instapay' | 'bank_transfer' | 'card' | ... }
  effect : update the order's payment method through the canonical order service AND, where the
           policy requires (e.g. InstaPay → COD), invoke ReevaluateOrderFulfillmentAction so
           reservation/fulfillment is re-evaluated. NO new engine — reuse the existing action.
  guard  : proof-required transitions must not finalize without a captured payment proof.
```
Until this exists, the driver UI exposes payment-method as **read-only** (the summary shows the
current method); it does not offer a change control, so it cannot bypass the fulfillment guard.

## Electronic Payment Proof (§8/§9)

The payment-proof capture is now **camera-first** (`capture="environment"` on the receipt
input) and uploads through the **existing certified secure endpoint** (`useUploadPaymentProof`
→ `POST /api/driver/stops/{id}/payment-proof`): server-generated private path, MIME/size
validation, tenant/stop authorization. No raw filesystem paths are exposed; the form sends a
real `File` only. The form already rejects an empty submission (no placeholder proof).

## POD (§10)

Reuses the certified secure POD uploader (`DeliveryProofUploadForm` → `useUploadDeliveryProof`
→ `POST /api/driver/stops/{id}/delivery-proof`). Supports signature + photos + notes; the
**photos input is now camera-first** (`capture="environment"`). No second upload authority; the
form requires at least one piece of evidence.

## Failed Delivery (§11/§12)

**Classified** outcomes (not one generic "Failed"): Refused, Not Available, Postpone/Delay,
Wrong Address, Unreachable — each opens `DeliveryActionForm`, which requires a **reason** (from
the backend catalogue `GET /driver/failure-reasons`) plus optional notes, and submits through
`useSubmitDeliveryAction` → `POST /api/driver/stops/{id}/action` (recordAction / completeStop).
These are revealed **only after Start Delivery**. No `Order.status` is written from the UI; the
backend's stop lifecycle owns the outcome.

## Delivery Completion (§13)

Completion is the backend's decision: the UI records quantities / outcomes / proof through the
canonical actions and reads back the settled status. It never presents "Delivered" optimistically
— the status badge reflects the canonical `stop.status`.

## Idempotency (§14)

Preserved (backend unchanged): the delivery writer is cumulative-absolute and the UI sends only
**changed** lines, so a repeat with the same totals is a no-op; the custody transfer is
idempotency-keyed on the stock ledger (`vehicle_custody_transfer` / `loading_task_id`); proof
and POD go through the certified secure endpoints. No duplicate quantity, custody move,
collection, POD linkage or lifecycle event results from a retry.

## Security (§15)

All controls act on the driver's **own** current stop via `/api/driver/*` (fail-closed
ownership on the server). No cross-driver mutation, no dispatcher/enterprise surface, no
customer master-data write. Contact details are additionally time-gated to after Start Delivery.

## Files Changed

| File | Change |
|---|---|
| `pages/driver-stop-detail-page.tsx` | §1/§2 gating (Start-only before; phone/WhatsApp/outcomes/proof/POD after); `canDeliver` → in_progress; WhatsApp action; removed dead `isPending` |
| `components/payment-proof-upload-form.tsx` | camera-first (`capture="environment"`) receipt input |
| `components/delivery-proof-upload-form.tsx` | camera-first POD photos input |
| `pages/driver-stop-detail-gating.test.tsx` | **NEW** — 2 gating tests (§1/§2) |
| `i18n/locales/{en,ar}/driver-mobile.json` | `stop.whatsapp` |

**Backend: NONE.** No new hook or endpoint was added; the flow reuses the existing canonical
actions (`useStartDelivery`, `useSubmitStopDelivery`, `useSubmitDeliveryAction`,
`useUploadPaymentProof`, `useUploadDeliveryProof`).

## Deferred Verification (§17)

Narrow, targeted only:
- `driver-stop-detail-gating.test.tsx` — **2 pass** (before/after Start Delivery reveal).
- `delivery-action-form.test.tsx` — **passes** (unchanged).
- `driver-loading-page.test.tsx` — **21 pass** (no cross-file regression).
- Static: **tsc 23 = baseline (0 in touched files)**, **ESLint 0**, **i18n EN↔AR parity OK**.

**Run at final system review:**
```
cd frontend && npx vitest run src/features/operations/driver-mobile
cd frontend && npx tsc -p tsconfig.app.json --noEmit    # expect 23 baseline
cd frontend && npx eslint src/features/operations/driver-mobile
# Browser (authenticated driver, isolated fixture — NOT demo data): open a pending stop → only
# Start Delivery; tap it → phone/WhatsApp/outcomes/proof/POD appear; record a partial; capture
# payment proof + POD (camera); record a failed outcome with a reason.
```

## Remaining Gaps / Phase-5 Handoff

1. **§7 payment-method change** — blocked on the missing driver endpoint + `ReevaluateOrderFulfillmentAction`
   wiring (spec above). Backend task.
2. **Invoice subtotal / shipping / discount** — canonical read-contract extension (from Phase 3).
3. **Full browser/lifecycle verification** — deferred.
4. **Phase 5 (Returns + Vehicle Reconciliation)** — not started. The failed/partial outcomes
   this phase records feed the return/reconciliation flow Phase 5 will build; custody on-hand
   after delivery is already surfaced (Home). Out of scope here (§16).

## Final Status

**IMPLEMENTATION STATUS: PARTIAL** — Start Delivery + gating, communication (phone/WhatsApp/maps),
full & partial delivery, camera-first payment proof, POD, and classified failed delivery are
implemented on the canonical authorities with no `Order.status` mutation. **§7 payment-method
change is BLOCKED** on a missing canonical driver transition and is documented, not worked around.

**FINAL CERTIFICATION: DEFERRED.**

---

**STOP.** No Phase 5, no commit/push/deploy, no demo-data mutation.
