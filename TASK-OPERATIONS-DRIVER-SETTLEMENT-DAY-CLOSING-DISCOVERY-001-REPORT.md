# TASK-OPERATIONS-DRIVER-SETTLEMENT-DAY-CLOSING-DISCOVERY-001 — REPORT

**Title:** Driver Day Closing / Settlement ("تقفيل اليوم") — Discovery & Architecture
**Date:** 2026-08-24
**Mode:** DISCOVERY ONLY — read-only. No implementation, no migration, no DB writes, no backend/frontend/navigation changes.
**Method:** 4 parallel read-only repository audits + direct reading of the settlement core (model, migration, service, controller, routes, enum, existing UI, navigation).

---

## 1. Executive Summary

**The driver end-of-cycle settlement engine already exists and is canonical — but it is per-TRIP and operator-only, and there is no per-driver / per-day aggregation surface over it.** The "تقفيل اليوم" workspace is therefore, at its core, a **read + reconciliation layer over existing canonical operational data plus reuse of the existing per-trip settlement engine** — NOT a new financial engine.

Confirmed canonical, reusable sources:

| Concern | Canonical source | Status |
|---|---|---|
| Cash settlement / reconciliation | `distribution_trip_settlements` + `SettlementService` + `SettlementController` | **EXISTS** (per-trip, operator-gated) |
| Money ledger (cash/transfer/card) | `distribution_payment_collections` (`payment_type`, `amount`, verify/reject, SoD) | **EXISTS** |
| Payment evidence (order-level) | `payment_proofs` (Commerce) + `UploadPaymentProofAction` + verify/reject | **EXISTS** (parallel, unlinked to the ledger) |
| Returned goods (product + custody) | `distribution_trip_returns` / `TripReturn` | **EXISTS** (weak idempotency; no inventory write-back) |
| Goods remaining with driver | `vehicle_inventory_items.quantity_on_hand` | **PARTIAL** (return leg not posted) |
| Delivery performance source | `distribution_delivery_stops` + `DeliveryStopStatus` (unique per order) | **EXISTS** (no per-driver rollup) |
| Settlement lifecycle | `SettlementStatus` (Draft→Submitted→Reconciled→Finalized, +Disputed) | **EXISTS** |
| Idempotency (per trip) | `unique(trip_id)` + state machine + `isFinal()` guard + trip→Closed | **EXISTS** |

Confirmed GAPS (each is an owner decision, not a blocker):

- **No canonical expense engine** anywhere; the canonical settlement formula has **no expense term**.
- **No driver wallet / driver cash ledger / cross-trip driver balance** — all cash is per-trip.
- **No per-driver / per-day aggregation** ("all drivers requiring settlement") read model.
- **`payment_proofs` has no method/type discriminator and no amount** — a "payment-transfer proof" is schema-identical to any other proof, and proofs are unlinked to the money ledger.
- **Returns never write back to canonical warehouse stock**, and `distribution_trip_returns` **confirm has weak idempotency** (no unique constraint, no confirmed-state guard).

**Governing rule discovered — Directive D8 "Distribution is the Single Cash Authority"** (CTO-approved, stated verbatim in the Fleet and Delivery migrations): Fleet cost/fuel and Delivery-COD records are expense/reporting facts that **"never write to `distribution_payment_collections` or `distribution_trip_settlements`."** Any cash that reduces a settlement MUST live on the Distribution side.

**Final verdict: B — IMPLEMENTABLE WITH OWNER DECISIONS.** (See Part 20.)

---

## 2. Existing Driver Architecture

- **Identity/pairing:** `logistics_drivers` (bigint id, no uuid), `logistics_vehicles` (uuid), `logistics_driver_vehicle_assignments` (the driver↔vehicle pairing ledger).
- **Trip = the operational + settlement unit:** `distribution_trips` (`Trip` model, `Modules/Logistics/Distribution`). It carries `company_id`, `preparation_wave_id`, `shipping_company_id`, and **`driver_vehicle_assignment_id`** — deliberately **not** `driver_id`/`vehicle_id` directly (the Drivers module owns the driver via unique indexes). A trip reaches its driver via `Trip → DriverVehicleAssignment → driver`.
- **Driver runtime surface:** `DriverRuntimeController` under `/api/driver/*`, gated by the single driver permission **`loading.driver.operate`**, tenant-fail-closed via `ownedStop()`/`ownedTrip()`. Certified by `DriverRbacTenancySecurityTest`.
- **Frozen driver financial surface (Section 17):** `POST /driver/stops/{stopId}/payment`, `GET /driver/trips/{tripId}/collections`, `GET /driver/trips/{tripId}/settlement`, `POST /driver/trips/{tripId}/settlement/submit` all route to `DriverRuntimeController::frozen()` → **403**. **Settlement is NOT a driver action** — it is operated only from the back office. This directly matches the approved concept ("when an operator opens a driver…").

**Wave 1 / Wave 2 compatibility (Part 5):** All of the following are consumed **read-only** by settlement and MUST NOT be modified — Driver ownership/RBAC/tenancy, Loading flow (`LoadProductAction`/`VehicleInventoryService`), stop lifecycle (`DeliveryStopStatus`), delivery outcome, canonical `FailureReason`, canonical `payment_proofs`, and the driver payment-proof upload path (Wave-2 Phase-1).

---

## 3. Existing Delivery Data

- **`distribution_delivery_stops`** (`DeliveryStop`, migration `2026_07_28_100003`) — **`unique(trip_id, order_id)`**, so one stop = one order. Reached via `Trip::stops()`.
- **Outcome vocabulary:** `DeliveryStopStatus` = `pending | in_progress | delivered | partial | failed | returned | skipped`; `isSettled()` = reached an outcome (delivered/partial/failed/returned/skipped).
- **Partial delivery quantities:** the canonical writer is `RecordProductDeliveryAction` (allocation-based) → `AllocationRecord.quantity_delivered` + `VehicleInventoryItem.quantity_delivered`. **`order_lines.delivered_qty`/`returned_qty` columns exist but have NO writer** — they are read-only everywhere, so any per-line "remaining" computed from them always equals the ordered quantity. **Do not treat `order_lines` fulfillment counters as authoritative.**

---

## 4. Existing Payment-Proof Architecture

- **`payment_proofs`** (`PaymentProof`, Commerce/Orders, migration `2026_08_19_140000`). Columns: `id` uuid, `company_id` uuid, `order_id` uuid, `state` (`uploaded|verified|rejected`), storage (`storage_disk`/`storage_path`/`original_filename`/`mime_type`/`size_bytes`), attribution (`uploaded_by/at`, `verified_by/at`, `rejected_by/at`, `rejection_reason`), supersede chain (`superseded_at`, `replaces_proof_id`). Indexes `(order_id, superseded_at)`, `(company_id, order_id)`.
- **Lifecycle:** `UploadPaymentProofAction` (driver + operator upload → `uploaded`), `VerifyPaymentProofAction`/`RejectPaymentProofAction`. Verify/reject held **only** by `company-admin` + `finance-manager` (`config/permissions.php`); Separation of Duties (`uploaded_by != verified_by`) enforced by identity in the actions.
- **Two hard limitations (verified):** (a) **no `method`/`type`/`channel` column** — a "payment-transfer proof" is schema-identical to any other proof; (b) **no amount column** — verify only flips state; there is no approved-amount output. Order money truth is `orders.deposit_amount` (method-agnostic, written by `RecordOrderPaymentAction`).
- **Driver path (Wave-2 Phase-1):** `POST /driver/stops/{stopId}/payment-proof` → `UploadPaymentProofAction` on the stop's order, always `uploaded`; the driver never verifies. This writes to `payment_proofs` (Commerce), which is **parallel to and unlinked from** the settlement money ledger (`distribution_payment_collections`).

---

## 5. Existing Cash Architecture

- **The money ledger is `distribution_payment_collections`** (`PaymentCollection`, migration `2026_07_28_100008`): `trip_id`, `stop_id`(nullable), `payment_type` (`cash|bank_transfer|card|already_paid`), **`amount`**, `reference_number`, `image_path`, `status` (`recorded|verified|rejected`), `verified_by/at`, `collected_by`. **No `company_id`** (tenancy via `Trip`). Discriminator (`payment_type`) **exists**.
- **Verify/reject** via `SettlementService::verifyPayment/rejectPayment`, gated `logistics.distribution.update`, with **Separation of Duties** by identity (`PaymentCollection::isSelfReviewBy()`, `collected_by != verified_by`) — binds even a system role.
- **Totals are always DERIVED** from the ledger (`SettlementService::calculateTotals()`), never hand-accumulated, so the settlement cannot drift from the rows.
- **`image_path` on a collection is an explicitly untrusted client string** — documented in `SettlementController::recordPayment` as "NOT a payment proof," deliberately NOT reconciled with `payment_proofs` (a redesign STOPPED for an owner decision).

---

## 6. Existing Wallet Architecture

**No driver wallet exists.** The only "wallet" in the platform is the **customer loyalty wallet** (`crm_loyalty_accounts`, `Modules/Crm/Loyalty`, `WalletService`) — unrelated to drivers. There is **no** `driver_wallet`, `driver_balance`, `driver_cash_ledger`, or cross-trip driver cash accumulator anywhere. All driver cash is scoped to a single `TripSettlement`. **Do not assume or create a wallet** (Part 7). If a running driver balance across trips/days is ever required, it is net-new architecture and an owner decision.

---

## 7. Existing Expense Architecture

**No canonical expense engine exists** (glob for `*{Expense,Reimburs,PettyCash,Payout}*` = 0 files). The only "expense" tokens are GL expense *accounts* (chart of accounts) and HR salary *allowances* — neither captures an operational driver expense. Adjacent-but-not-the-thing:

- **`fleet_cost_entries`** (`VehicleCostService`, `CostType` fuel/maintenance/…): vehicle-centric, **no `driver_id`/`trip_id`, no approval lifecycle, no evidence, no API routes**, and **forbidden by Directive D8 from touching trip cash**.
- **`fleet_fuel_transactions`**: has evidence (`photos`) + a reconcile/dispute lifecycle, but fuel-specific and vehicle-centric, also outside trip cash.
- **`distribution_payment_collections`**: the right evidence+review *pattern* but models cash IN, not expense OUT.

**Critical formula consequence:** the canonical settlement has **no expense/deduction line** — a driver cannot record "spent 50 from collected cash on fuel." Adding expenses is a **net-new Distribution-side** capability (see Parts 11 & 15).

---

## 8. Existing Returns Architecture

Four distinct stores (the task's `DeliveryReturn`/`CustodyReturn` anchors are partly stale):

- **A — `distribution_trip_returns`** (`TripReturn`, migration `2026_07_28_100007`): the **canonical "goods returned with driver"** ledger — unified **product + custody** (`kind = product|custody`; it explicitly replaces the older split `DriverDeliveryReturn`/`DriverCustodyReturn`). Columns include `warehouse_confirmed_qty/at/by`, `discrepancy_qty`, `driver_liable`, `disposition`, `photos`. **Create:** `DeliveryService::recordReturn` — driver (`POST /driver/trips/{tripId}/returns`, `loading.driver.operate`, hardcodes `kind=product`) or operator (`POST /trips/{tripId}/returns`, `logistics.distribution.update`, product|custody). **Confirm (receive):** `DeliveryService::confirmReturn` → `PATCH /trips/{tripId}/returns/{returnId}/confirm`, **operator only** (`logistics.distribution.update`) — no driver confirm.
- **B — `delivery_returns` + `delivery_return_lines`** (`DeliveryReturn`, `Modules/Logistics/Delivery`): parallel **customer-refusal** reconciliation, per-order line-level, kept separate by CTO decision; state machine `Initiated→InTransit→Received→Verified/Discrepancy`; operator only (`delivery.return.manage`).
- **C — vehicle inventory** (below).
- **D — vehicle shift reconciliation** (below).

Custody is **not** a separate table — it is the `kind='custody'` rows of A. The driver-facing `/driver/.../custody-returns` endpoint named in the concept **does not exist**.

---

## 9. Existing Inventory / Driver-Goods Architecture

- **`vehicle_inventory_items` + `vehicle_inventory_movements`** (`VehicleInventoryService`, Operations/Loading): the driver-side stock ledger. `quantity_on_hand = loaded − delivered − returned`. **`loaded`** via `LoadProductAction` (Wave-1); **`delivered`** via `RecordProductDeliveryAction` (the sole delivered-qty writer). **Gap:** `VehicleInventoryService::recordReturn()` has **zero callers** — the return leg is never posted here, so `on_hand` effectively = `loaded − delivered`.
- **`vehicle_shift_reconciliations` + `_lines`** (ADR-015 §6.4): end-of-shift variance = loaded − delivered − returned. **Gap:** there is **no `approve`/`close` route or service** — the `Approved` terminal state is set only in a test; even reaching it writes nothing to inventory.
- **Inventory reconciliation on return = MISSING:** confirming a return only stamps `warehouse_confirmed_*` on the return row. There is **no** `stock_ledger_entries`/`stock_movements` write, no warehouse restock, and **no reconciling listener** anywhere in Logistics or Loading. **Do not create a new driver-inventory source** (Part 9) — the read inputs already exist; the missing piece is the write-back, which is an owner decision.

---

## 10. Existing Settlement / Reconciliation Architecture

**Fully realized, canonical, per-trip, operator-gated.**

- **Table `distribution_trip_settlements`** (migration `2026_07_28_100009`), model `TripSettlement`: `cash_collected, bank_transfers_pending, already_paid, total_collected, cash_expected, driver_cash_submitted, discrepancy, status, submitted_at, reconciled_at, finalized_at, finalized_by, notes`. **`unique(trip_id)`** — exactly one per trip. **No `driver_id`, no `company_id`** (tenancy via `Trip`).
- **Service `SettlementService`:** `openSettlement` (requires `allStopsSettled()`, derives totals, `firstOrNew(['trip_id'])`, refuses if `isFinal()`) → `submitDriverCash` (Submitted, derives `discrepancy`) → `reconcile` (Reconciled) / `dispute` (Disputed) → `finalize` (Finalized, sets `finalized_by`, transitions **Trip → Closed**, dispatches `TripSettled`).
- **Controller `SettlementController` + routes** `api.php:1886-1897`, all `permission:logistics.distribution.view/update`: `payments`, `recordPayment`, `verifyPayment`, `rejectPayment`, `show`, `open`, `submit-cash`, `reconcile`, `dispute`, `finalize`, `financial-summary`. Tenant fail-closed via `resolveTrip()` (`company_id` scope) + `companyId()` (403 on null scope).
- **Existing operator UI:** `frontend/src/features/logistics/trips/components/trip-settlement-tab.tsx` (+ `use-trip-settlement.ts`, `trip-settlement-service.ts`) — a **per-trip** settlement tab; every figure is backend-derived (never re-computed in the browser). This is the reuse anchor for the driver-detail view.
- **What is MISSING:** any **per-driver / multi-driver / day-level** aggregation ("all drivers requiring settlement"), and any operator "physically counted / received" field distinct from `driver_cash_submitted` (today `reconcile` only stamps a status).

---

## 11. Delivery Performance Sources

- **Canonical source EXISTS:** `distribution_delivery_stops` (unique per order) + `DeliveryStopStatus`. Assigned = stop count; delivered/partial/failed/returned = stops grouped by `status`; delivery % = delivered ÷ total. Plus `Trip::outstandingStopsCount()`, `Trip::allStopsSettled()`, and `SettlementService::financialSummary()` (`stops_total` + `stops_outstanding` alongside money).
- **No ready-made per-driver rollup exists** — no `groupBy('status')` over `distribution_delivery_stops` anywhere. `DriverTripKpis` is a **frontend-only orphan** (its grid is imported nowhere; no backend endpoint produces it).
- **Avoid the parallel engine:** `Modules/Logistics/Delivery`'s `EloquentDeliveryRepository::statistics()` produces delivered/failed/returned/SLA counts but over a **different `Delivery` model / `DeliveryStatus` enum**, company-wide, not per-trip/driver, not `distribution_delivery_stops`. Reusing it would adopt a second engine over a different SSOT. **Build ONE new read model** that aggregates stop `status` over `Trip::stops()` instead.

---

## 12. Settlement Period Recommendation

The **canonical settlement grain is the Trip** (`unique(trip_id)`), and a Trip maps 1:1 to one driver+vehicle (via `driver_vehicle_assignment_id`) and carries a `preparation_wave_id`. Options and recommendation:

- **Calendar day** — intuitive for "تقفيل اليوم" but has no canonical anchor on the settlement row (would need a date derivation from `trip_started_at`/wave date).
- **Preparation Wave** — a Trip has `preparation_wave_id`, but settlement must **never close or mutate a Preparation Wave** (Part 14); coupling the period to the wave risks that.
- **Trip / driver-assignment cycle** — the canonical settlement unit; safest.

**Recommendation:** keep the settlement grain = **Trip** (unchanged, canonical), and make "تقفيل اليوم" a **per-driver day VIEW that groups a driver's trips by their operating day** (derived read-only from `trip_started_at`/`finalized_at`, or optionally the wave's date), aggregating the per-trip `TripSettlement`s. This is **read-only over Preparation Waves** and introduces no new settlement authority. Whether "day" is a calendar day or the wave day is **Owner Decision OD-6**.

---

## 13. Settlement Formula

**The canonical equation already exists and is NOT "Cash + Transfers − Expenses."** Per `SettlementService::calculateTotals()` + `TripSettlement::calculateDiscrepancy()`:

```
cash_collected          = Σ collections WHERE payment_type = cash        AND status ≠ rejected
bank_transfers_pending  = Σ collections WHERE payment_type IN (bank_transfer, card) AND status ≠ rejected
already_paid            = Σ collections WHERE payment_type = already_paid AND status ≠ rejected
total_collected         = cash_collected + bank_transfers_pending + already_paid
cash_expected           = cash_collected            ← ONLY physical cash is reconciled
discrepancy             = driver_cash_submitted − cash_expected     (null until the driver hand-back)
is_balanced             = |discrepancy| < 0.01
```

Key facts: only **physical cash** is reconciled against the driver's hand-back; transfers/card/already-paid are tracked but not part of the cash discrepancy; **there is no expenses, refunds, or returns term** in the canonical formula. Two refinements are owner decisions: (a) `bank_transfers_pending` currently sums **non-rejected** (recorded + verified), not **verified-only** — an "approved transfer" figure would filter `status = verified` (the ledger supports it; no method exposes it yet) — **OD-3**; (b) introducing expenses would require a **new `net_expected = cash_expected − approved_expenses`** and a schema/formula change — **OD-2**.

---

## 14. Idempotency / Double Settlement

**Per-trip settlement idempotency is STRONG and canonical:**
- `unique(trip_id)` on `distribution_trip_settlements` (DB-enforced one-per-trip) + `firstOrNew(['trip_id'])` → idempotent open.
- `SettlementStatus` state machine (`canTransitionTo`) + `assertMutable`/`isFinal()` → a Finalized settlement cannot be mutated; transitions cannot be skipped or repeated out of order.
- `finalize` → Trip **Closed** → the trip cannot be re-settled.
- Payment collections: Separation of Duties (`collected_by != verified_by`) prevents self-verify; rejected rows excluded from totals.

**Gaps (owner decisions):**
- **Returns confirm (`distribution_trip_returns`) idempotency is WEAK** — no status field, no `isConfirmed()` guard before overwrite, no lock, **no unique constraint** on `(trip_id, order_id, product_id, kind)`. A driver can POST the same product-return twice → two rows; a confirmed return can be silently re-confirmed. **Harden before a settlement flow relies on it** — **OD-4**.
- **Day-level double-settle:** if a *new* per-driver/day settlement record is ever introduced, it needs its own idempotency (`unique(driver_id, period)`); a pure read/rollup over per-trip settlements inherits the strong per-trip guards and needs none — **OD-1** (prefer the read/rollup).

---

## 15. Required Future Data Model

**Preferred (minimal, no new settlement authority):** a **pure read/aggregation model** — no new tables. The "day closing" is a query that groups a driver's `TripSettlement`s (+ payment collections, trip returns, vehicle inventory, delivery-stop status) by day. This is the **recommended v1** and keeps Distribution as the single cash authority.

**Only if the owner requires capabilities the canonical model lacks**, the minimum net-new tables (Distribution-owned, company-scoped, DO NOT create in this task):
- **`distribution_driver_expenses`** (or similar): `trip_id`/`driver_id`, `amount`, `category`, evidence via the existing `payment_proofs` mechanism, an approval lifecycle mirroring `SettlementStatus`/the proof review pattern (record → verify/reject by a *different* actor). Required only if expenses are in scope (**OD-2**).
- **An "operator counted cash" field** on `distribution_trip_settlements` (distinct from `driver_cash_submitted`) — only if the business needs to record what the operator physically received separately from what the driver declared (**OD-5**).
- **A `payment_proofs` discriminator** (`method`/`type`) and/or an `approved_amount` — only if order-level transfer proofs must feed settlement (**OD-3**); today the money ledger (`distribution_payment_collections`) already carries `payment_type` + `amount`, so this may be unnecessary.
- **A returns idempotency constraint** (`unique(trip_id, order_id, product_id, kind)` + a confirmed-state guard) on `distribution_trip_returns` (**OD-4**).

Each of the above is a **migration** and therefore explicitly **out of scope** for this discovery (Part 18).

---

## 16. Required Future API / Service Layer

- **New (aggregation only):** an operator read endpoint `GET /logistics/settlement/day-closing?date=…` (permission `logistics.distribution.view`) returning **all drivers with trips requiring settlement** for the period, each row summarizing counts + money + settlement status — built on a new `DriverDayClosingReadService` that aggregates existing sources (no new engine). A per-driver detail read that composes the driver's trips.
- **Reuse verbatim (no change):** `SettlementController` (open/submit-cash/reconcile/dispute/finalize/verify/reject) and `SettlementService`; `DeliveryController::confirmReturn`; `financial-summary`; the delivery-stop reads.
- **Performance:** one new read model that `groupBy('status')` over `Trip::stops()` for delivered/failed/partial/returned/% — reused by both the main list and the detail.

---

## 17. UI / UX Proposal

- **Main workspace ("تقفيل اليوم"):** an operator table of **all drivers requiring settlement** for the selected day — Driver, Vehicle, Trip(s), Orders received/delivered, delivery %, failed/partial/returned, remaining-with-driver, cash collected, transfers, expected settlement, driver-submitted, difference, settlement status. Status chips reuse the canonical `SettlementStatus` (Draft/Submitted/Reconciled/Disputed/Finalized) — **do not invent Open/Under-Review/Reconciled/Settled**; they already map to the canonical enum. Empty state when no driver needs settlement.
- **Driver detail:** compose the **existing `TripSettlementTab`** per trip (money + open/submit-cash/reconcile/dispute/finalize), plus panels for Payment Proofs (canonical `payment_proofs` view/verify — Finance/company-admin gated), Payment Collections verify/reject (SoD), Returns/custody (`distribution_trip_returns`, operator confirm), Goods-remaining (`vehicle_inventory_items`), and Delivery performance (new read model). All figures **backend-derived**; never re-compute money in the browser.
- **RTL/i18n:** reuse the `logistics` namespace `trips.settlement.*` keys already used by `TripSettlementTab`; add a new `operations`/`settlement` section for the day-closing list. EN/AR parity mandatory.

---

## 18. Navigation Placement

Confirmed placement under the **Operations** module (`module-navigation.ts`, `id: 'operations'`), **after Loading**:

```
Operations
├── Distribution Planning   (logistics-distribution-plan → /logistics/distribution)
├── Loading (Drivers)       (loading-drivers → /operations/loading)
├── تقفيل اليوم             ← NEW (Driver Day Closing / Settlement)  [not added in this task]
└── …
```

Nav labels are typed i18n keys (a missing key is a tsc error). **Not added in this discovery** (Part 16), and note `TASK-LOGISTICS-NAVIGATION-ARCHITECTURE-CLEANUP-002` is concurrently editing nav — the nav step must be a separate, coordinated implementation.

---

## 19. Dependency Matrix

| Settlement capability | Canonical dependency | Reuse / build |
|---|---|---|
| Cash settlement lifecycle | `SettlementService` / `SettlementController` / `TripSettlement` | **Reuse** |
| Money ledger + verify/reject | `distribution_payment_collections` / `PaymentCollection` | **Reuse** |
| Payment evidence (order) | `payment_proofs` / Upload+Verify+Reject actions | **Reuse** (Finance/company-admin gated) |
| Returned goods (product+custody) | `distribution_trip_returns` / `DeliveryService::recordReturn/confirmReturn` | **Reuse** (+harden idempotency — OD-4) |
| Goods remaining | `vehicle_inventory_items.quantity_on_hand` | **Reuse** (return leg gap — OD-4) |
| Delivery performance | `distribution_delivery_stops` / `DeliveryStopStatus` | **Build** one read model (no 2nd engine) |
| All-drivers day list | — | **Build** (aggregation read; no engine) |
| Expenses | — (none exists) | **Owner decision** (OD-2) |
| Driver wallet / cross-trip balance | — (none exists) | **Owner decision** (not recommended for v1) |
| Tenancy | `Trip.company_id` (indirect) | **Reuse** |

---

## 20. Conflict Analysis

This task was **read-only** — no files changed — so no direct conflict. Forward-looking notes for the concurrent tasks (Part 15):

- **TASK-DISTRIBUTION-DAILY-GROUP-WAVE-LIFECYCLE-002** — wave/group lifecycle. Settlement `finalize` closes a **Trip** (not a wave); day-closing must remain **read-only over Preparation Waves** (Part 14). No overlap if that rule holds.
- **TASK-DRIVER-WAVE-2-PHASE-1 / WAVE-2** — the driver `payment_proofs` upload is **parallel** to the settlement money ledger; settlement consumes `distribution_payment_collections`, not the driver's order-level proof. Coordinate OD-3.
- **TASK-DISTRIBUTION-ZONES/MAP-UX-003/004** — unrelated (zones/map).
- **TASK-LOGISTICS-TEMPLATE-DRIVER-RECOMMENDATIONS-…-001** — unrelated (template driver suggestions).
- **TASK-LOGISTICS-NAVIGATION-ARCHITECTURE-CLEANUP-002** — the settlement nav item must be added by a coordinated, later step to avoid a nav collision (Part 16/18).

No task conflict **blocks** implementation.

---

## 21. Migration Requirements

**None created (Part 18 respected).** If the owner approves capabilities beyond a pure read/rollup, the future migrations would be: `distribution_driver_expenses` (OD-2), a returns idempotency constraint on `distribution_trip_returns` (OD-4), an optional operator-counted-cash column on `distribution_trip_settlements` (OD-5), and an optional `payment_proofs` method/amount discriminator (OD-3). Each requires explicit authorization; **the recommended v1 (read/aggregation only) needs zero migrations.**

---

## 22. Owner Decisions

- **OD-1 — Day-closing grain:** pure **read/rollup** over per-trip `TripSettlement`s (recommended, no new table, inherits strong idempotency) **vs** a new per-driver/day settlement record (new table + `unique(driver_id, period)`).
- **OD-2 — Expenses in scope?** No canonical expense engine exists and the formula has no expense term. If in scope → new Distribution-side `distribution_driver_expenses` + `net_expected` formula change. If not → v1 excludes expenses.
- **OD-3 — Transfer approval source & proof discriminator:** consume `distribution_payment_collections` (`payment_type` + `amount`, verified-only) as the settlement transfer figure (recommended). Decide whether the driver's order-level `payment_proofs` must also feed settlement — if so, `payment_proofs` needs a method/type discriminator and/or approved amount (both currently MISSING).
- **OD-4 — Returns hardening:** add a confirmed-state guard + `unique(trip_id, order_id, product_id, kind)` to `distribution_trip_returns`, and decide the **inventory write-back** on confirm (today a confirmed return never restocks canonical stock; `VehicleInventoryService::recordReturn` is dead; shift-reconciliation has no approve/close).
- **OD-5 — Operator-counted cash:** is a field distinct from `driver_cash_submitted` needed (what the operator physically received), or is `reconcile` status sufficient?
- **OD-6 — "Day" definition:** calendar day vs Preparation-Wave day (both derivable read-only; must not mutate waves).
- **OD-7 — `order_lines` fulfillment counters:** they are unwritten; confirm the settlement uses `AllocationRecord`/`VehicleInventoryItem`/`distribution_trip_returns` as the delivered/returned authority (recommended) and does not rely on `order_lines.delivered_qty`.

---

## 23. Browser Verification

**READ-ONLY, no mutations.** Backend routing was verified without touching data: the operator settlement surface is registered and gated (`api.php:1886-1897`, `permission:logistics.distribution.view/update`), the returns confirm route is operator-gated (`api.php:1884`), and the driver financial surface is **frozen → 403** (`api.php:3176-3180`). The existing operator per-trip settlement UI is present (`trip-settlement-tab.tsx`). No live driver/trip/settlement data was created, settled, uploaded, approved, or mutated. No screenshots of live financial data are included (none should be produced for a discovery). Full interactive browser verification of a not-yet-built page is **not applicable** — the page does not exist and must not be created (Part 17/Final Rule).

---

## 24. Data Safety

No database writes. No migration. No settlement opened/submitted/reconciled/finalized. No payment recorded/verified/rejected. No proof uploaded/verified. No return recorded/confirmed. No navigation, backend, or frontend change. The four discovery agents and all direct reads were strictly read-only. Distribution remains the single cash authority; nothing in this task altered any financial or operational record.

---

## 25. Final Recommendation

Build "تقفيل اليوم" as an **operator-side read + reconciliation workspace over the existing canonical per-trip settlement engine** — a new **all-drivers day list** and a **per-driver detail** that composes the existing `SettlementController`/`SettlementService`, the `distribution_payment_collections` money ledger, `payment_proofs`, `distribution_trip_returns`, `vehicle_inventory_items`, and a single new delivery-performance read model over `distribution_delivery_stops`. Reuse the canonical `SettlementStatus` lifecycle and the existing `TripSettlementTab`. Introduce **no new financial engine, no wallet, no competing payment-proof source, and (for v1) no new tables**. Resolve OD-1…OD-7 before implementation — chiefly the day-closing grain (read/rollup vs new record), whether expenses are in scope, the transfer-approval source, and returns idempotency/inventory write-back.

---

## FINAL VERDICT

**B — IMPLEMENTABLE WITH OWNER DECISIONS.**

The core canonical sources all exist and can be reused without introducing competing engines: the per-trip cash **settlement engine** (`SettlementService`/`TripSettlement`, with a real lifecycle, a derived formula, and strong per-trip idempotency), the **money ledger** (`distribution_payment_collections`), the **returns ledger** (`distribution_trip_returns`), the **payment-proof** system (`payment_proofs`), the **goods-remaining** ledger (`vehicle_inventory_items`), and the **delivery-outcome SSOT** (`distribution_delivery_stops`). The workspace is primarily an operator **aggregation/read layer + reuse**, not a new engine — so this is **not** verdict C. It is read-only-safe against every concurrent task — so **not** D.

It is **not** verdict A because several settlement decisions must be approved first: **no canonical expense engine** and no expense term in the formula (OD-2); the **payment-proof↔ledger** parallelism and missing proof discriminator/amount (OD-3); **weak returns idempotency and no inventory write-back on return** (OD-4); no **operator-counted-cash** field (OD-5); the **day grain** (OD-1/OD-6); and the unwritten `order_lines` counters (OD-7). None is a blocker; each is an owner decision that shapes scope before UI implementation.
