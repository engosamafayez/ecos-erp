# TASK-OPERATIONS-DRIVER-SINGLE-ACTIVE-TRIP-CLOSURE-CONTRACT-001 — Report
## CTO Addendum: Financial Summary Cards (Driver Closing)

Adds the four cash cards to the Active Driver Closing custody + detail. Built on the existing Driver
Closing work (not a restart). **Verification narrow; FINAL CERTIFICATION DEFERRED.**

---

## 1. Audit — canonical financial authorities (audit-first)

Traced every authority behind the four cards. Evidence in memory `driver_closing_financial_authority_audit`.

| Concept | Canonical authority? |
|---|---|
| **Physical cash** | ✅ `PaymentType::Cash` only (`isPhysicalCash()` true only for Cash). |
| **Electronic** | ✅ `BankTransfer` + `Card` (separate; not physical cash). |
| **Prepaid** | ✅ `PaymentType::AlreadyPaid`. |
| **Settlement cash** | ✅ `SettlementService::financialSummary` — `cash_expected` = physical cash; **no expense/advance term**. |
| **Driver operational EXPENSES** (fuel/toll/gate) | ❌ **None.** No `*Expense*` model/table; Fleet `CostEntry` is vehicle-keyed and ring-fenced from trip cash. |
| **Driver cash ADVANCE** | ❌ **None.** HR `Advance` is a payroll loan (opposite sign); `CustodyItemType::CashFloat` is an integer item count, not a money value. |
| **Net-cash-with-driver formula** | ❌ **None** ("money is strictly per-trip"). |
| **Expected Collection (COD target)** | ❌ **Not wired** — hardcoded `expected_collection_available:false`. |

The codebase already encodes this: `DriverReportsReadService` returns `{available:false, reason:'no_canonical_authority'}`
for driver expenses/advances. The findings were surfaced to the CTO, who issued the decisions in §2.

## 2. CTO decisions implemented

- **Cash Collected** → real (canonical physical cash). **Electronic** → real, kept separate (§9).
- **Expenses** → **"Not available"** (never a fabricated EGP 0.00). No expense authority created (your §10).
- **Net Cash With Driver** → **"Not available"** — deliberately **NOT** set equal to Cash Collected as a fallback.
- **Expected Collection** → an **immutable operational snapshot captured at custody handoff** (not a read-time recompute
  from mutable order state), per your rules 1–8.
- **Follow-up architecture gap recorded** (your §"FOLLOW-UP ARCHITECTURE GAP") — a separate backend/domain task
  requiring CTO approval (spawn_task `task_2e2fd78b`).
- **No Finance journal** posted; **no DEV business-data mutation.**

## 3. The four cards (behaviour + basis)

| Card | Value | Canonical basis |
|---|---|---|
| **A. Expected Collection** | Σ per-stop handoff snapshots, or "Not available" | `distribution_delivery_stops.expected_collection_at_handoff` |
| **B. Cash Collected** | real (may be a canonical EGP 0.00) | `collections.cash` = `PaymentType::Cash` |
| **C. Expenses / Cash Out** | "Not available" | no canonical authority (audit §1) |
| **D. Net Cash With Driver** | "Not available" | cash-out term has no authority (CTO §2) |

Plus **Electronic Collections**, **Total Customer Collections**, and **Collection Difference** (where available).

## 4. Expected Collection — the handoff snapshot (rules 1–8)

**Rule 1–2 (audit / reuse):** No existing canonical collectible-at-handoff field. `DeliveryStop.collected_amount` is
*actual* collected (default 0); `Trip.collection_amount` is *actual* total collected. Nothing snapshots the *expected*
amount — so nothing to reuse (reported before schema work).

**Rule 3–6 (smallest snapshot):** Added nullable `expected_collection_at_handoff` (decimal 12,2) to
`distribution_delivery_stops`, populated at the existing handoff boundary `DeliveryService::generateStops()` from the
canonical order authority at that moment:

```
expected_collection_at_handoff = order.date_paid !== null ? 0.00 : max(0, order.total − order.deposit_amount)
```

Fully-prepaid → 0; deposit → total − deposit; unpaid COD → full total. Driver Closing Expected Collection =
`Σ` snapshots over the ONE active Trip/Custody's stops.

**Rule 7 (immutability):** the snapshot is stored once. Later payment-method change / cash collection / transfer proof /
delivery result **never** rewrite it — they move **Collection Difference** = `(cash + bank + card) − expected` instead.
Test-pinned: mutating the order's total + `date_paid` after handoff leaves the stop snapshot unchanged.

**Rule 8 (no backfill):** availability requires **every** stop to carry a snapshot; a pre-snapshot (historical) stop
makes the whole figure "Not available" rather than a partial or backfilled sum. Test-pinned (a directly-created stop →
`expected_collection_available:false`, `expected_collection:null`).

## 5. Net Cash & Expenses — honest "Not available" + the gap

Both render "Not available" because no canonical authority exists (§1). The detail page states the reason verbatim:
*"Operational expenses / cash movements are not yet connected to a canonical Driver cash authority."* The future
authority — approved Driver cash movements (Fuel / tolls / expenses / advances) with Requested/Approved/Rejected/Settled
status and the audited advance sign (an advance may be cash **given to** the driver, **increasing** custody) — is
recorded as a CTO-approval-gated follow-up (spawn_task `task_2e2fd78b`), with the net formula
`opening + collected + cash-in − approved cash-out`.

## 6. Presentation & value states (§6, §8)

`DriverCashPositionCards`: the four headline cards in a **2-col grid on mobile → 4-col from `lg`** (values wrap, never
clip), then Electronic / Total / Difference, then the note. The three states are distinct (§8): a **canonical real zero →
`EGP 0.00`**; **contract/data unavailable → "Not available"**; a **read failure** is the page's error state (this section
renders only on a successful read). A zero is never fabricated to complete a card.

## 7. Electronic payments kept separate (§9)

Electronic (bank transfer + card) is its own card, hinted "Not physical cash — kept separate from cash custody", and is
**never** added into Cash Collected. The page distinguishes **Total Customer Collections** (cash + electronic) from
**Cash Collected** (physical cash). Test-pinned: electronic does not inflate Cash Collected.

## 8. Detail breakdown & closing summary (§10, §12)

The detail page keeps the existing **Sales & Collections** breakdown (Cash / Bank / Card / Already-Paid / Actual
Collected / Expected Collection) beneath the new Cash Position summary, so the cash position — Expected Collection, Cash
Collected, Electronic, Expenses, Net Cash, Collection Difference — is understandable before the operator closes the
custody. No separate accounting authority was created for the breakdown.

## 9. One trip / one card set (§11)

The figures belong to the ONE active Trip/Custody, not a calendar day. The board's operational day is anchored to trip
start (`DATE(COALESCE(trip_started_at, dispatched_at, created_at))`), and every cash figure sums the trip's own
collections / stop snapshots — so **crossing midnight does not reset** the cards; the same custody's cards persist until
it is closed. No daily cash/expense snapshots were introduced.

## 10. Files changed

**Backend (verified in isolated testrunner; migration NOT applied to DEV runtime):**

| File | Change |
|---|---|
| `…/Infrastructure/Database/Migrations/2026_08_29_120000_add_expected_collection_at_handoff_to_delivery_stops.php` | **New** — nullable `expected_collection_at_handoff` snapshot column. |
| `…/Domain/Models/DeliveryStop.php` | fillable + `decimal:2` cast for the snapshot. |
| `…/Domain/Services/DeliveryService.php` | `generateStops` snapshots the collectible-at-handoff per stop; `expectedCollectionAtHandoff()` helper. |
| `…/Domain/Services/DriverDaySettlementReadService.php` | `collectionsBreakdown` sums the snapshots → `expected_collection` / `_available` / `collection_difference`. |
| `tests/Feature/Logistics/DriverDaySettlementReadTest.php` | +4 snapshot tests + `seedHandoffTrip` fixture. |

**Frontend:**

| File | Change |
|---|---|
| `…/driver-settlement/components/driver-cash-position-cards.tsx` | **New** — the cash-position section (5 cards + electronic/totals + note; honest §8 states). |
| `…/driver-settlement/pages/driver-settlement-detail-page.tsx` | Renders the section prominently before Sales & Collections. |
| `…/driver-settlement/components/driver-cash-position-cards.test.tsx` | **New** — §8/§13 card tests. |
| `i18n/locales/{en,ar}/logistics.json` | `driverSettlement.cards.*` (EN+AR parity); refreshed `expectedCollectionNote`. |

No settlement/reconciliation authority, desktop table, or existing card was altered.

## 11. Focused verification (§13)

**Backend — testrunner gate: 12 tests / 115 assertions / OK (exit 0).** The 4 new + 8 existing, no regressions:
- `generate_stops_snapshots_the_collectible_amount_at_handoff` — 1000 (unpaid COD) / 0 (prepaid) / 700 (deposit).
- `expected_collection_snapshot_is_immutable_after_handoff` — later order mutation does not rewrite it.
- `expected_collection_reads_the_sum_of_the_handoff_snapshots` — HTTP `collections.expected_collection = 1700`, available.
- `expected_collection_is_unavailable_when_a_stop_predates_the_snapshot` — null stop → available:false, value null.

**Frontend — Vitest 24/24 (driver-settlement folder):**
- Cash Collected is physical cash only; electronic shown separately and does not inflate it.
- Expenses + Net Cash are "Not available"; Net Cash does NOT fall back to Cash Collected.
- Expected Collection shows the snapshot when available, "Not available" otherwise.
- Canonical real zero (`EGP 0.00`) is distinct from "Not available".

**Static:** ESLint clean; **0 tsc errors** in touched files; **i18n EN↔AR parity — 0 missing keys**.

Verification items assuming an expense authority (approved-expense affects the card, etc.) are satisfied by their honest
inverse — the Expenses card is "Not available", never a fabricated number. Midnight-non-reset is covered by the
trip-start-anchored operational day (§9), asserted structurally rather than by a clock test.

## 12. Remaining gaps / pending steps

- **DEV schema deploy (pending):** the migration + updated Distribution source are verified in the testrunner but **not
  applied to the `ecos-dev-app` runtime** (schema change; not authorized here). Until deployed + a new trip's stops are
  generated, DEV's existing custodies show Expected Collection "Not available" (historical, no snapshot — correct, rule 8).
- **Expenses / Net Cash** remain "Not available" pending the CTO-approval-gated cash-movement authority (`task_2e2fd78b`).
- **Live operator browser pass** deferred (the only live DEV session is a driver → 403 on the operator endpoint).

## 13. Implementation status

**FINANCIAL ADDENDUM (this document): COMPLETE (in-scope), accepted by CTO.** The four cards render with honest,
canonical-or-"Not available" values; Expected Collection is an immutable handoff snapshot; Expenses & Net Cash are honest
"Not available"; electronic is separate; real zero is distinct from unavailable; the desktop table and settlement
authorities are unchanged.

**CORE CONTRACT ("ONE active operational Trip/Custody per Driver"): NOT IMPLEMENTED → task PARTIAL (§14).**

**FINAL CERTIFICATION: DEFERRED TO FINAL SYSTEM REVIEW.**

---

## 14. CTO Review Follow-Up — CORE single-active-custody contract audit

Read-only audit (no code changed, no DEV mutation). **The "one active custody per Driver" contract was NOT implemented
by any work under this task** — the work delivered was the read / mobile-UX / parity / financial-cards layers, none of
which touch a single-active write boundary or identity.

- **Active identity** = `DISTINCT (driver_vehicle_assignment_id, DATE(COALESCE(trip_started_at, dispatched_at, created_at)))`
  over trips `status != Closed` (`DriverDaySettlementReadService::activeBoard`, lines 141–174; `DAY_EXPR` line 93). It is a
  per-**(assignment, operational-day)** rollup — NOT per driver, NOT per custody, NOT per trip.
- **Membership event** = merely a trip with a non-null assignment and `status != Closed`. **`loading` (Draft/Planning)
  trips DO appear in Active** — there is no dispatch/handoff gate.
- **Write boundary** = NONE. `TripService::create` is an unconditional `Trip::create`; the trips table has no unique
  constraint on `driver_vehicle_assignment_id`/open-status (only `uuid`, `(company_id, trip_number)`).
- **DEV OSAMA×3** = ONE driver (396, "OSAMA FAYEZ AHEMD"), ONE assignment (209), THREE trips (TRP-001/002/003) all
  `status='loading'`, never started/dispatched, created 2026-08-21 / 08-23 / 08-25. Because they are unstarted,
  `op_day` = `DATE(created_at)`, giving three distinct `(209, day)` keys → three Active rows. Under the CURRENT
  (unenforced) model these are valid rows; under the DESIRED contract they are an invariant violation. **Not mutated.**

**Correction to §9 of this report:** the "one open trip = one Active record / survives midnight" claim holds only for a
*single started* trip; it is NOT a single-active-**per-driver** guarantee, which does not exist.

**SOURCE IMPLEMENTATION STATUS** — single-active contract: **NOT IMPLEMENTED**. Financial addendum + Expected-Collection
snapshot: **IMPLEMENTED in source, verified in testrunner (12 tests / 115 assertions OK)**.

**DEV SCHEMA/RUNTIME STATUS** — `expected_collection_at_handoff` migration is **NOT applied to DEV** (source + testrunner
only). The DriverDaySettlement read/controller/routes are at parity (earlier task). No single-active enforcement exists in
DEV (proven by OSAMA×3).

**OVERALL TASK STATUS: PARTIAL** — core single-active-custody contract awaiting CTO decision; do not implement without it.

---

**STOP.** No commit. No push. No deploy. No DEV business-data mutation.
