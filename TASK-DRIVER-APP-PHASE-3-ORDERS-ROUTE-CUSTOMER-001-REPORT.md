# TASK-DRIVER-APP-PHASE-3-ORDERS-ROUTE-CUSTOMER-001

## Executive Summary

Phase 3 gives the driver a complete assigned-orders + customer experience: the Orders
workspace now groups by **area** and preserves the **canonical delivery sequence**, the Order
Detail shows full customer/invoice information with proper payment labels, and the §6 customer-
contact privacy rule is now enforced. **Frontend-only, zero backend changes**; every value
traces to an existing canonical driver read, and nothing mutates order/delivery state (§10/§13).

Much of the Order Detail already existed from prior work; this phase adds the workspace
grouping/sequence, fixes two real gaps (phone shown before Start Delivery; raw payment
abbreviations), and wires the stop sequence into the Home Next card.

Backend: **NONE** · RBAC: **UNCHANGED** · Live business data: **UNTOUCHED** ·
Frontend: 7 files (2 new) · Commit/Deploy: **NONE**

**IMPLEMENTATION STATUS: COMPLETE.**
**FINAL CERTIFICATION: DEFERRED TO FINAL SYSTEM REVIEW.**

Date: 2026-08-28 · Branch: `develop`

---

## Architecture Trace

| Concern | Canonical source | Status |
|---|---|---|
| Assigned orders | `useDriverStops(currentTrip.id)` → driver stop list (one stop = one order) | reused |
| Current trip | `useDriverTrips` (self-resolves the driver's own trip) | reused |
| Delivery sequence | `DeliveryStop.sequence` (assigned by the trip/distribution architecture) | reused |
| Area / zone | `StopOrderSummary.governorate` | reused |
| Customer / address / GPS | `StopOrderSummary` (customer_name, address, area, city, governorate, gps) | reused |
| Invoice | `grand_total`, `deposit_paid`, `remaining_balance`, `payment_method`; lines `line_total` | reused |
| Order status | governed by the Fulfillment / delivery lifecycle — **never mutated here** | preserved |

The driver reads only `/api/driver/*` (fail-closed to the authenticated driver). No dispatcher
planning, no enterprise order admin, no customer master data.

## Canonical Stop/Order Source & Sequence Semantics (§3)

`DeliveryStop.sequence` is the single canonical ordering. The Orders workspace and the Home
Next card both read it directly — **no alternative sequencing authority** is created, nothing
is reordered in React by anything other than that sequence, and no route/distance is computed.
The `DeliveryStopCard` already renders the sequence as a numbered badge; the Home Next card now
shows **"Stop N"**.

## Area / Zone Source & Grouping (§2)

New pure helper `lib/orders-grouping.ts` → `groupStopsByArea(stops)`:
- groups stops by the canonical `governorate`,
- sorts each group by `sequence`,
- orders the groups by their lowest sequence (so the whole list follows the canonical delivery
  order),
- sends null-area stops to a single trailing "Other area" group so **nothing is hidden**.

It is not a route planner (unit-tested: *"does not reorder anything but by the canonical
sequence"*). The workspace renders an area header (with count) above each group's stop cards.

Filters were completed to the canonical statuses: **all / pending / delivered / partial /
failed**, where "pending" folds `in_progress` (out-for-delivery) — both are unresolved work.

## Order Detail Design (§4)

The Order Detail (`driver-stop-detail-page.tsx`) already renders: order number + status,
sequence, customer name, address (with a maps deep-link when GPS exists), delivery notes,
product lines with quantities and line totals, and the payment summary. This phase did not
redesign it — it fixed the two gaps below.

## Invoice / Commercial Summary (§5)

- **Payment method labels — fixed.** The raw value (`cod`, `cash`, `instapay`, `bank_transfer`,
  `card`, `already_paid`, `wallet`) is now mapped to a **full localized label** (e.g. "Cash on
  Delivery", "InstaPay", "Bank Transfer"), with a graceful fallback to the raw value for any
  unmapped method. No unclear abbreviations (§5).
- **Shown (canonical):** Final Total (`grand_total`), Deposit paid, Amount to Collect
  (`remaining_balance`), Payment Method, plus per-line totals.
- **Not shown — canonical read gap (documented, not fabricated):** the stop read exposes no
  separate **Merchandise Subtotal**, **Shipping Fee**, or **Discount**. Per §5 "where canonical"
  and the no-fabrication rule, these are omitted. **Missing read contract:** to show them, the
  driver stop-order read would need `subtotal`, `shipping_total`, `discount_total` added — a
  small extension to the existing canonical order read, deferred rather than invented.

## Customer Information / Security & §6 Privacy

- **§6 enforced (fix).** The customer **phone** action previously rendered whenever a phone
  existed — including **before** Start Delivery. It is now gated on `deliveryStarted =
  stop.status !== 'pending'`: before start, the phone is hidden and a muted "Contact available
  after starting delivery" hint shows instead; after the canonical Start Delivery
  (out-for-delivery), the phone `tel:` action appears. There is no WhatsApp action yet — that
  and the Phone-after-start belong to Phase 4, unchanged here.
- **Address / maps stay visible** before start (the driver needs to know where to go); §6 gates
  only phone/WhatsApp, and §7 asks for the maps affordance where a canonical address exists —
  both satisfied.
- **No CRM leak (§9):** only operational fields are shown (name, address, notes, payment) — no
  customer financial administration, CRM history, other drivers' notes, or unrestricted records.

## Maps (§7)

The Order Detail turns the canonical address into a Google Maps deep-link **only when the
order carries canonical `gps` coordinates**; otherwise the address renders as plain text. No
internal route optimizer, no fabricated lat/lng.

## Home Next-Stop Integration (§8)

The Home Command Center Next card (built last task) now shows the canonical **"Stop N"**
sequence alongside the order number, customer, area and COD. "Open Order" navigates to the
order — it does **not** start delivery from Home (§8).

## Status Authority (§10)

No `Order.status` mutation was added. This phase is read/presentation. The delivery-execution
mutations that already exist on the detail page are Phase 4 work and were not touched (only the
phone-visibility gate around them).

## Performance (§11)

The workspace reuses the driver's already-fetched `useDriverStops(currentTrip.id)` list —
scoped to the authenticated driver's current trip, no Commerce-wide load, no N+1. Grouping is a
single in-memory pass, memoized.

## Security (§12)

Driver sees only their own trip's stops (driver-scoped endpoint). No route-sequence mutation,
no dispatcher planning, no enterprise order admin, no customer master-data write. DriverShell /
Phase-1 isolation untouched.

## Backend Changes

**NONE.**

## Frontend Changes

| File | Change |
|---|---|
| `driver-mobile/lib/orders-grouping.ts` | **NEW** — `groupStopsByArea` (area + canonical sequence) |
| `driver-mobile/lib/orders-grouping.test.ts` | **NEW** — 4 unit tests |
| `driver-mobile/pages/driver-orders-page.tsx` | area-grouped render; `partial` filter; `in_progress` folded into pending |
| `driver-mobile/pages/driver-stop-detail-page.tsx` | §6 phone gate; §5 full payment labels |
| `driver-mobile/pages/driver-home-page.tsx` | §8 "Stop N" on the Next card |
| `i18n/locales/{en,ar}/driver-mobile.json` | `orders.filter.partial`, `orders.unzoned`, `stop.payment.methods.*`, `stop.contactAfterStart`, `home.next.stop` |

## Files Changed

7 (2 new libs/tests, 3 modified pages, 2 i18n). No backend.

## Deferred Verification (§14)

Ran only narrow, targeted checks (no full suites, no certification):

- `orders-grouping.test.ts` — **4 pass** (area grouping, group ordering by sequence, null-area
  trailing + nothing dropped, sequence-only ordering).
- `driver-home-page.test.tsx` — **14 pass** (no regression; Next card + sequence render).
- `driver-loading-page.test.tsx` — **21 pass** (no regression).
- Static: **tsc 23 = baseline (0 in touched files)**, **ESLint 0**, **i18n EN↔AR parity OK**.

**Run at final system review:**
```
cd frontend && npx vitest run src/features/operations/driver-mobile
cd frontend && npx tsc -p tsconfig.app.json --noEmit    # expect 23 baseline
cd frontend && npx eslint src/features/operations/driver-mobile
# Browser (authenticated driver session, read-only): /app/driver/orders shows area groups with
# Stop N; open an order → customer/invoice correct, payment label full, phone hidden while
# pending; Home Next card shows "Stop N". No mutation.
```

## Remaining Phase-3 Gaps

1. **Invoice subtotal / shipping / discount** — not in the canonical driver stop read; needs a
   small read-contract extension (fields listed in §5) before they can be shown without
   fabrication.
2. **Full browser/lifecycle verification** — deferred (§14).
3. Demo data: several DEV stops carry no `gps`, so their maps deep-link is absent (address
   shows as text) — canonical, not a defect.

## Phase-4 Handoff

Phase 4 (Delivery + Payment + POD + Failed Delivery) owns what §6 gates open after Start
Delivery: the Phone/WhatsApp contact actions and the delivery/failed-delivery execution. The
detail page already carries the delivery-execution scaffolding (gated), so Phase 4 extends it
rather than rebuilding. **Not started.**

## Final Status

**IMPLEMENTATION STATUS: COMPLETE.**
**FINAL CERTIFICATION: DEFERRED TO FINAL SYSTEM REVIEW.**

Orders workspace groups by canonical area + sequence; Order Detail shows full customer/invoice
with proper payment labels; §6 contact privacy enforced; maps only from canonical GPS; Home
Next shows the canonical stop; no Order.status mutation; driver-scoped and secure; no Phase-4
execution added; no backend or live-data change.

---

**STOP.** No Phase 4, no commit/push/deploy, no live-data mutation.
