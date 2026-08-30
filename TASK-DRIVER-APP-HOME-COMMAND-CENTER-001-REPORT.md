# TASK-DRIVER-APP-HOME-COMMAND-CENTER-001

## 1. Executive Summary

Driver Home is now a **contextual Daily Command Center**: it answers "what state am I in",
"what do I do next", and "what's unresolved" — and it changes with the operational day rather
than showing the same cards throughout.

The work is **frontend-only with zero backend changes** — every new number traces to a
canonical driver read (trips / loading manifest / delivery stops / vehicle inventory / trip
settlement). The Phase-2 pending-loading precedence and the single primary-action resolver are
**preserved unchanged**. All contextual/derivation logic lives in **one** new pure module so
cards cannot disagree, and it drives no backend transition (§2 "no shadow lifecycle").

Backend: **NONE** · RBAC: **UNCHANGED** · Live business data: **UNTOUCHED** ·
Frontend: 6 files (2 new) · Commit/Deploy: **NONE**

**IMPLEMENTATION STATUS: COMPLETE.**
**VERIFICATION / FINAL CERTIFICATION: DEFERRED TO FINAL SYSTEM REVIEW** (§37).

Date: 2026-08-28 · Branch: `develop`

---

## 2. Before State

Home already had: an identity header, a Current Work card, one primary action (from the
`deriveState` resolver), an end-of-day Day Summary, an Orders card, and a Vehicle Stock card —
plus skeleton/error/empty states. It was a good base but **static**: the same Orders and
Vehicle cards rendered in every state, there was no journey spine, no next-order, no
collections, and no contextual "needs attention".

---

## 3. Home Architecture Audit (§4)

| Value | Source | Kind |
|---|---|---|
| driver name | `useAuthStore` (`/api/auth/me`) | canonical |
| vehicle plate / trip number / status | `useDriverTrips` → `DriverRuntimeController::tripSummary` | canonical |
| loading items / workflow_state / loading_complete | `useDriverLoading` → `DriverLoadingController::manifest` | canonical |
| delivery stops (status, sequence, collected_amount, order) | `useDriverStops` → stop list | canonical |
| vehicle custody (loaded/delivered/returned/on_hand/products) | `useVehicleInventory` → `VehicleInventorySummary` | canonical |
| trip settlement (cash_expected/total_collected/discrepancy/status) | `useTripSettlement` → `TripSettlement` | canonical, **trip-scoped** |
| **next action** | existing `deriveState(trip, manifest, remaining)` | the ONE resolver — unchanged |
| cross-trip wallet / expected-return-per-product / route sequence | — | **unavailable (later phases)** — omitted, not fabricated |

**D — a central next-action resolver already existed** (`deriveState`). I reused it verbatim
and did **not** duplicate action logic. **E — every card I added maps to an existing canonical
read;** no new domain authority was introduced.

---

## 4. Canonical Data Sources & 5. Home State Model

The Home "phase" is read straight from the existing resolver's `workKey` (which maps 1:1 to the
canonical trip status + loading state): `loading` → `readyForDelivery` → `inDelivery` →
`settlementPending` → `completed` (plus `blocked` / `none`). No second lifecycle exists; a new
`statusRank()` only *orders* the canonical `TripStatus` for presentation thresholds, and drives
nothing.

Contextual section visibility (§3/§12/§27):

| Section | Shown when |
|---|---|
| Current Work + Primary Action | always (a trip exists) |
| **Daily Journey** | always (a trip exists) — the read-only spine |
| Orders Summary | `inDelivery` / `settlementPending` / `completed` (deliveries relevant) |
| Next Order | `inDelivery` **and** a canonical next stop exists |
| Vehicle Warehouse | always |
| Collections | delivering/closing/completed **and** settlement read has data |
| Needs Attention | only when a real issue exists |
| Day Summary / Closing | `completed` / `settlementPending` |

---

## 6. Next Action Resolver

**Unchanged.** `deriveState` remains the single authority for the one primary action. The new
module (`home-command-center.ts`) contains **only presentation derivations** (journey, order
metrics, custody snapshot, collections, next stop, attention) — it never resolves the primary
action, so there is exactly one place for that decision.

## 7. Phase-2 Loading Precedence Preservation

**Preserved and re-verified.** `deriveState` still checks `pendingConfirmations > 0` before the
`loading_complete` flag. The two Phase-2 tests — *"loading complete flag set BUT an item still
awaits → confirm received"* and *"loading complete + all confirmed → start trip"* — **pass
unchanged** in this task's run (§27). The 1-of-2 fix is not regressed.

## 8. Driver / Trip / Vehicle Header

Kept the compact Phase-1 header (avatar + name + role; vehicle plate + trip number). Not
enlarged — mobile-first (§7/§28).

## 9. Current Work & 10. Primary Action

Kept the Current Work card and the single `h-14` primary CTA from the resolver. One action
only; a completed state still renders no button (Phase-1/2 behaviour preserved).

## 11. Daily Journey (NEW)

A read-only spine of six stages — Loading → Custody accepted → Trip started → Deliveries →
Return → Closing — each shown done / active / upcoming from canonical status + counts, with a
`delivered / total` detail on Deliveries. It **mutates nothing** and invents no completion:
`buildJourney()` derives purely from the trip status, the loading manifest (pending
confirmations), stop metrics, custody, and settlement. Later-phase stages show a neutral state
until their canonical data exists (§12).

## 12. Orders Summary (NEW detail)

Shown once deliveries are relevant: **Received** (total), **Delivered**, **Partial**,
**Remaining (pending)**, **Failed**, and a **delivery rate**. All from `DeliveryStop.status`.

## 13. Delivery Rate Semantics (§14/§15)

- **Received** = orders handed to the driver = number of delivery stops (§14).
- **Delivered** = stops with `status === 'delivered'` only.
- **delivery_rate = delivered / received**, as a whole percent. **Partial is NOT counted as
  delivered** (§15). Returns **null** (card hidden) when received is 0, so no false "0%".

## 14. Next Stop / Phase-3 Boundary (§16/§33)

The Next Order card shows the **lowest-`sequence` unresolved stop** (`nextStop()`), with order
number, customer name, governorate, and remaining COD — all canonical. It is **ordering only**:
no map, no distance, no zone sequencing, no phone/WhatsApp, no Start Delivery. "Open Order"
navigates to the existing Orders list. Route planning stays in Phase 3.

## 15. Vehicle Warehouse Summary (§17/§18)

From the **Vehicle Inventory authority** (`VehicleInventorySummary`): Delivered, On-hand
(= expected return), Loaded. It is a driver custody view — no stock adjustment, no ledger, no
warehouse switching, no other driver's inventory. Custody is never computed from order totals.

## 16. Collection Summary (§19/§20)

**Trip-scoped, read-only**, from `TripSettlement`: Expected vs Collected, and a difference line
when non-zero. **No wallet, no cross-trip aggregation, no accounting.** The card is **omitted
entirely** when the settlement read has no data (`hasData === false`) rather than showing a
fabricated 0. The settlement read is fetched **only once the trip has departed** (§32).

## 17. Attention / Exceptions (§21/§22)

A contextual "Needs Attention" card rendered **only when a real canonical issue exists**:
pending loading confirmations, remaining orders, failed orders, expected return, or a
settlement cash difference (read-only). Nothing is fabricated; an empty issue set renders
nothing. It is a distinct **amber business-alert** card, visually and semantically separate
from the **system read-error** state above it (§22).

## 18. Error / Empty / Loading States (§22/§26)

Preserved and distinct: skeleton while trips load; a dedicated **error** state with retry when
the read fails (never a business 0); a calm **no-trip empty** state ("no active trip"). A read
failure does not render 0s as real values.

## 19. Returning Driver Experience & 20. Day Completed (§24/§25)

The Day Summary (orders received/delivered/failed/pending, vehicle stock to-return, settlement
status) renders for `settlement_pending` (closing) and `completed`. "Day complete" is shown
only from canonical closure truth (the trip status), never inferred merely because driving
ended. No Returns or Wallet workflow was implemented — presentation/read only (§24).

## 21. No-Trip Experience (§26)

When there is no current trip, a calm empty state renders — **not** 0-orders / 0-stock /
0-collections as if a day were active.

## 22. Security (§31)

All reads are the existing driver-scoped `/api/driver/*` endpoints (trips, loading, stops,
vehicle inventory, trip settlement) — each fail-closed to the authenticated driver on the
server. No new endpoint, no cross-driver data, no operator/enterprise data, no weakening of
authorization. The DriverShell isolation (Phase 1) is untouched.

## 23. Performance / API Read Pattern (§32)

Home issues at most **5 canonical reads**, all fired in parallel by their hooks (no waterfall):
`trips`, `loading`, `stops` (gated on a current trip id), `vehicle inventory`, and
`settlement` — the last **gated to fire only once the trip has departed** (`rank >= dispatched`)
so loading-phase Home makes no needless settlement call. `stopRows` is memoized to keep the
derivations stable across renders (an ESLint warning fixed during implementation).

## 24. Backend Changes

**NONE.** Every value came from an existing canonical driver read.

## 25. Frontend Changes

- `lib/home-command-center.ts` — **NEW.** Pure canonical derivations (journey, order metrics,
  custody snapshot, collections, next stop, attention, statusRank). No hooks, no fabrication.
- `pages/driver-home-page.tsx` — contextual render; gated settlement read; reuses `deriveState`
  unchanged for the primary action.
- i18n EN/AR — new `home.{journey,orders,next,custody,collections,attention}` keys with Arabic
  plural forms; base-key parity verified.

## 26. Files Changed

| File | Change |
|---|---|
| `driver-mobile/lib/home-command-center.ts` | **NEW** — derivations |
| `driver-mobile/lib/home-command-center.test.ts` | **NEW** — 14 unit tests |
| `driver-mobile/pages/driver-home-page.tsx` | contextual command-center render |
| `driver-mobile/pages/driver-home-page.test.tsx` | mocks for new hooks + 3 contextual tests |
| `i18n/locales/en/driver-mobile.json` | command-center keys |
| `i18n/locales/ar/driver-mobile.json` | command-center keys (Arabic plurals) |

## 27. Tests Added/Updated but NOT broadly executed (§37)

Under the verification freeze I ran only **narrowly targeted** unit/component tests for the new
logic, not full suites:

- `home-command-center.test.ts` — **14 pass** (metrics incl. partial-not-delivered and null
  rate; next-stop ordering; custody mapping; collections omit-vs-compute; journey states incl.
  the Phase-2 "awaiting keeps loading active"; attention shows only real issues; statusRank).
- `driver-home-page.test.tsx` — **14 pass** (11 existing incl. both Phase-2 precedence cases,
  + 3 new: journey always shown; orders hidden during loading; orders + next-order shown on the
  road).
- `driver-loading-page.test.tsx` — **21 pass** (unchanged; confirms no cross-file regression).
- Static: **tsc 23 = baseline (0 in touched files)**, **ESLint 0**, **i18n EN↔AR parity OK**.

No existing test was weakened or deleted.

## 28. Deferred Verification Plan (run at final system review)

```
cd frontend && npx vitest run src/features/operations/driver-mobile   # full driver suite
cd frontend && npx tsc -p tsconfig.app.json --noEmit                  # expect 23 baseline
cd frontend && npx eslint src/features/operations/driver-mobile
# Browser (authenticated driver session): open /app/driver/home in each lifecycle state and
# confirm the journey, orders, next-order, vehicle, collections, and attention cards appear
# only in their intended phase, with canonical numbers. Read-only; no mutation.
```

## 29. Demo Data Protection & 30. Live Data Protection

No DEV business data was created or modified. The Home adapts to whatever canonical state the
demo data is in; it does not repair or mutate it. The pre-existing DEV stock/settlement demo
inconsistencies are surfaced as read-only signals where the contract exposes them, never fixed
here (§23/§41). No inventory, reservation, loading, custody, order, trip, return, collection,
settlement, or RBAC write occurred.

## 31. Remaining Gaps

1. **Cross-trip Driver Wallet** — no canonical cross-trip financial read exists; the Home shows
   only trip-scoped collections. Documented for **Phase 6**.
2. **Per-product expected-return** — the vehicle summary gives aggregate on-hand (expected
   return); a per-product return plan is **Phase 5**.
3. **Full browser/lifecycle verification** — deferred to final system review (§37).

## 32. Phase 3 Handoff

Next up is **Phase 3 — Orders + Route + Customer**, which will own the Next-Order card's
deeper behaviour (route sequencing, customer contact, maps). The Home's Next-Order card is
deliberately ordering-only so Phase 3 can extend it without rework. **Not started.**

## 33. Implementation Status

**IMPLEMENTATION STATUS: COMPLETE.**
**VERIFICATION / FINAL CERTIFICATION: DEFERRED TO FINAL SYSTEM REVIEW.**

Static review (§38) confirms: one centralized next-action resolver (unchanged); Phase-2 loading
precedence intact; no shadow lifecycle (canonical status only); order metrics from
`DeliveryStop`; custody from the Vehicle Inventory authority; collections canonical-or-omitted;
error/empty/loading states distinct from business alerts; no Enterprise leak; and no Phase 3–6
implementation crept in.

---

**STOP.** Audit → implementation → static review → report → context update → notification. No
Phase 3, no Finance, no full certification, no live-data mutation, no commit.
