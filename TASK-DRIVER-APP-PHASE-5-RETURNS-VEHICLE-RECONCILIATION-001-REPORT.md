# TASK-DRIVER-APP-PHASE-5-RETURNS-VEHICLE-RECONCILIATION-001

## Executive Summary

Phase 5 completes the **driver-facing** return-to-warehouse experience on top of the existing
canonical authorities, while preserving Warehouse authority for physical receipt. Two things
changed on the driver side:

1. **The driver now sees the return reconciliation** — Expected Return = Loaded − Delivered
   (the canonical ADR-015 §6.4 identity, never a counter), plus received-back, still-on-vehicle,
   and a per-product status — on the Vehicle Inventory page, derived purely from the canonical
   `GET /driver/vehicle-inventory` read.
2. **A §3/§13 security defect was removed** — the driver Returns page carried a *"Confirm
   Receipt"* button that posted `POST /driver/returns/{id}/confirm` (a warehouse-receipt write).
   That route **does not exist under `/driver/`** (the only return-confirm is the dispatcher's
   `PATCH /trips/{id}/returns/{returnId}/confirm`, `logistics.distribution.update`), so it was
   both a dead 404 for a driver token and, conceptually, the driver recording warehouse receipt.
   It is gone; the warehouse confirmation is now shown **read-only**.

The heavy lifting already existed and was **preserved untouched**: the warehouse receipt
authority `ReceiveVehicleReturnAction` (accepted → canonical `AdjustmentIn`, damaged kept out of
good stock, shortage = visible variance → shift `Disputed`, custody `reconcileReturn` absolute
+ idempotent), operator-gated by `loading.session.operate`. The driver's only canonical return
WRITE is the **declaration** (`addReturn` → `TripReturn`, no inventory effect) — kept as-is.

**Frontend only. Backend: NONE. RBAC: UNCHANGED. Live/DEV business data: UNTOUCHED. No
commit/push/deploy.**

**IMPLEMENTATION STATUS: PARTIAL** — the driver-facing return experience + reconciliation
visibility + the §13 correction are COMPLETE; the warehouse-side reconciliation is pre-built and
preserved; two cross-cutting records (damage **disposition** and driver-attributed **liability**)
remain **documented owner-decision deferrals**, not driver-app scope to invent (§7/§8/§12).
**FINAL CERTIFICATION: DEFERRED.**

Date: 2026-08-28 · Branch: `develop`

---

## Return Architecture Trace (verified against the working tree)

The approved model maps 1:1 onto existing canonical components. "Driver declares → Warehouse
counts/receives → accepted/damage/shortage → custody reconciliation":

| Stage | Canonical component | Authority | Status |
|---|---|---|---|
| Expected Return = Loaded − Delivered | `VehicleShiftReconciliationService::open()` → `quantity_returned_expected = max(0, loaded−delivered)`; also derivable from `vehicle_inventory_items` | read | preserved; surfaced to driver |
| Driver **declares** a return | `POST /driver/trips/{id}/returns` → `DriverRuntimeController::addReturn` → `DeliveryService::recordReturn` → `TripReturn` (no inventory effect) | `loading.driver.operate` | preserved (only driver return WRITE) |
| Warehouse **receives/counts** | `POST /api/loading/sessions/{s}/assignments/{a}/reconciliation/lines/{l}/receive` → `ReceiveVehicleReturnAction` | **`loading.session.operate`** (operator) | preserved, NOT driver-reachable |
| Accepted good → warehouse stock | `AdjustmentInAction` (+FIFO layer, `reference_type='vehicle_return'`) | Inventory | preserved |
| Damaged | condition-gate — **never restocked**; disposition record deferred | Warehouse | preserved |
| Shortage | `variance = loaded−delivered−returned`; shift → `Disputed` (visible, no auto-charge) | reconciliation | preserved |
| Vehicle custody reconcile | `VehicleInventoryService::reconcileReturn` (absolute SET, idempotent, `warehouse_receipt_at`) | Loading custody | preserved |

The dead `VehicleInventoryService::recordReturn()` (`+=`, zero callers) is **not** used — the live
path is the absolute `reconcileReturn`, called only by `ReceiveVehicleReturnAction`.

## Expected-Return Semantics (§1)

Expected Return is computed **only** as `max(0, loaded − delivered)` per product, from the
canonical custody quantities — never from an arbitrary frontend counter. It is clamped at zero
so a delivered ≥ loaded row can never net negative, and the trip total sums the **per-product**
identities (so one over-delivered line cannot mask another's expected return). Encoded in the
pure, unit-tested helper `lib/returns-reconciliation.ts` (`buildProductReconciliation`,
`buildReturnTotals`) — mirroring the backend's `quantity_returned_expected`.

## Driver Return Experience (§2)

- **Vehicle Inventory page** is now a **reconciliation view**: per product it shows Loaded,
  Delivered, **Expected**, Returned (received back), current On-hand, a status badge, and a
  visible shortage line; a banner totals Expected return / Received back / Still on vehicle. All
  read-only, all from the canonical driver read.
- **Returns page** lets the driver **declare** returns (canonical `addReturn`) and shows the
  warehouse's confirmation read-only. The driver performs only the return action that already
  exists canonically (§2).

## Warehouse Receipt Authority (§3)

The driver is **not** the authority for actual receipt. Receipt lives only under the operator
`loading` prefix (`ReceiveVehicleReturnAction`, `loading.session.operate`) — a driver token
cannot reach it. The driver UI now offers **no** receipt/confirm control and cannot increase
warehouse stock. The Returns page carries an explicit note to this effect.

## Product Reconciliation (§4)

Per product the driver sees, from canonical custody:

| Column | Driver-visible? | Source |
|---|---|---|
| Loaded | ✅ | `quantity_loaded` |
| Delivered | ✅ | `quantity_delivered` |
| Expected Return | ✅ | `max(0, loaded − delivered)` (derived) |
| Actual Received (total) | ✅ | `quantity_returned` (warehouse-reconciled custody) |
| Remaining / Variance | ✅ | `quantity_on_hand` (residual = shortage after receipt) |
| Accepted vs **Damage** split | ⚠️ warehouse-side | `vehicle_shift_reconciliation_lines.quantity_accepted/quantity_damaged` |
| Formal **Shortage** classification | ⚠️ warehouse-side | reconciliation `variance` + `Disputed` |

The accepted-vs-damaged split and the formal shortage classification are the **Warehouse's** to
record; they are **not fabricated** on the driver side. Surfacing them to the driver would need a
small read-contract extension (a driver read over `vehicle_shift_reconciliation_lines`), listed
under Remaining Gaps — deferred, not invented.

## Full Return (§5)

When Expected = Actual good return, variance is zero and accepted units re-enter warehouse stock
through the canonical `AdjustmentInAction` (operator side). The driver sees the product settle to
**Reconciled** (on-hand → 0). No driver stock write.

## Partial Return / Shortage (§6)

Expected is **never silently changed**. When received < expected, the residual stands as the
visible discrepancy: the product shows **Partial return** with a red "Shortage: N unit(s) not yet
returned" line, and `expectedReturn` remains the original figure. Pinned by test: expected stays
8 when received is 7; `hasDiscrepancy` is true.

## Damage — WasteInvestigation (§7) — documented deferral

`WasteInvestigation` exists but is **structurally unusable** for returns: its `count_session_id`
+ `count_line_id` are NOT-NULL FK-coupled to inventory count sessions (a return has neither), and
its stock effect is an `AdjustmentOut` write-off of already-on-hand stock (the opposite of
quarantine). The return flow already keeps damaged stock **out of good inventory** via the
condition-gate in `ReceiveVehicleReturnAction` (accepted is restocked; damaged is intentionally
not). The damage **disposition record** is a pre-existing owner-decision deferral (documented in
that action's docblock), pending schema relaxation. Per §7 ("do not create another damage
system") nothing new was built. The driver only **declares** damage type/qty/reason via the
existing return declaration.

## Liability — WarehouseLiability (§8) — documented deferral

`WarehouseLiability` exists but has **no driver/trip/vehicle attribution column and no create
action**; its status enum is Pending/Approved/Rejected. The **driver holds zero
`inventory.liabilities` permission** — they can neither open nor close a liability (maker-checker
intact). Per requirement, a shortage does **not** auto-become a charge: it is captured
non-punitively as the reconciliation `variance` and holds the shift `Disputed`. A formal
driver-attributed liability is a documented owner decision — not built (§8, §12: "do not create a
second driver liability authority").

## Vehicle Custody Reconciliation (§9)

Custody is reconciled to the canonical warehouse-received quantity by `reconcileReturn` (absolute
SET, movement appended, idempotent) — inside `ReceiveVehicleReturnAction`'s single transaction.
Custody is **never zeroed because the driver pressed "Return"** — the driver's declaration has no
custody effect; on-hand only moves when the warehouse records receipt. The driver's Vehicle
Inventory view reflects that canonical result.

## Driver Home Integration (§10)

Already correct and left untouched: `home-command-center.ts` marks the **return leg** `done`
only when canonical `custody.onHand === 0`, surfaces `expectedReturn = onHand` as a "needs
attention" item, and gates **closing** on trip/settlement status — it never infers completion
merely because delivery work ended. Phase 5 adds no shadow lifecycle here.

## Operations Day Settlement Integration (§12)

No settlement authority was created. The canonical per-trip `SettlementService` is operator-only
and driver settlement routes are deliberately frozen (403); the day-settlement workspace is a
read-only rollup. Phase 5 consumes canonical custody/return state and does not duplicate any of
it.

## Permanent Traceability (§11)

Preserved by the canonical models: `TripReturn` (trip/order/product/declared qty, `reported_by`,
`warehouse_confirmed_by/at`, discrepancy), `vehicle_shift_reconciliation_lines`
(loaded/delivered/expected/actual/accepted/damaged/`damage_reason`/`warehouse_receipt_at`/
variance, responsible operator), and append-only `vehicle_inventory_movements`. Nothing is
overwritten on the driver side.

## Security (§13)

The driver **cannot**: record warehouse receipt (control removed; no driver route), approve their
own shortage or damage investigation (no permission), modify accepted warehouse quantity, or
close a liability. All driver reads/writes remain self-scoped on `/api/driver/*`
(`loading.driver.operate` + per-request ownership). Pinned by `driver-returns-page.test.tsx`:
the returns screen exposes only Back + Add — no confirm/receipt control — for both unconfirmed
and warehouse-confirmed returns.

## Out of Scope (§14)

No wallet, advances, deductions, salary, Finance entries, or Driver Reports — untouched (Phase 6).

## Files Changed (frontend only)

| File | Change |
|---|---|
| `lib/returns-reconciliation.ts` | **NEW** — pure `buildProductReconciliation` / `buildReturnTotals` / `hasOutstandingReturns` (§1/§4/§6) |
| `lib/returns-reconciliation.test.ts` | **NEW** — 11 unit tests (Expected Return identity, status, §6 discrepancy, totals) |
| `pages/driver-vehicle-inventory-page.tsx` | reconciliation view: Expected Return + status badge + totals banner + warehouse-authority note |
| `pages/driver-returns-page.tsx` | **§13 fix** — removed "Confirm Receipt"; warehouse status read-only; declare intro; full i18n |
| `pages/driver-returns-page.test.tsx` | **NEW** — 2 tests pinning no driver receipt control |
| `components/return-form.tsx` | i18n (was hardcoded English) |
| `hooks/use-driver-mobile.ts` | removed `useConfirmReturn` (dead/violating) |
| `services/driver-mobile-service.ts` | removed `confirmReturn` (posted a non-existent driver route) |
| `i18n/locales/{en,ar}/driver-mobile.json` | `returns.*`, `returnForm.*`, `vehicleInventory.recon.*`, `vehicleInventory.perProduct.expectedReturn` (EN↔AR parity, AR plural forms) |
| `eslint-suppressions.json` | pruned 2 now-clean entries (return-form, driver-returns-page); use-driver-mobile count 20→18 |

**Backend: NONE. No new hook/endpoint** — the experience reuses `useTripReturns`, `useAddReturn`,
`useVehicleInventory`.

## Deferred Verification (§15)

Narrow, targeted only:
- `returns-reconciliation.test.ts` — **11 pass**; `driver-returns-page.test.tsx` — **2 pass**.
- Full driver-mobile suite — **70 pass** (8 files, no regression).
- Static: **tsc 23 = baseline (0 in touched files)**, **ESLint 0**, **i18n EN↔AR parity clean**.

**Run at final system review:**
```
cd frontend && npx vitest run src/features/operations/driver-mobile
cd frontend && npx tsc -p tsconfig.app.json --noEmit   # expect 23 baseline
cd frontend && npx eslint src/features/operations/driver-mobile
# Browser (authenticated driver, isolated fixture — NOT demo data): open Vehicle Inventory →
# per product shows Expected = Loaded − Delivered + status; after a warehouse receipt, Returned
# rises and status → Reconciled (or Partial + shortage if short). Returns page: declare a return;
# confirm there is NO "Confirm Receipt" control; the warehouse confirmation shows read-only.
```
Full browser/lifecycle certification is **deferred** (§15).

## Remaining Gaps / Phase-6 Handoff

1. **Damage disposition record (§7)** — `WasteInvestigation` schema relaxation (nullable
   count-session FK / return-sourced waste) — owner decision, backend.
2. **Driver-attributed liability (§8)** — `WarehouseLiability` needs driver/trip/vehicle
   attribution + a create action — owner decision, backend.
3. **Driver read of accepted-vs-damaged split + formal shortage** — a small self-scoped driver
   read over `vehicle_shift_reconciliation_lines` would let the driver see the warehouse's
   accepted/damaged/variance classification (today the driver sees the reconciled total + residual
   variance). Read-contract extension, deferred.
4. **Returns declaration UX** — the declare form still asks for a manual product ID; a picker over
   the vehicle inventory would be a natural enhancement (kept the canonical mechanism, not rebuilt).
5. **Full browser/lifecycle certification** — deferred (§15).
6. **Phase 6 (Wallet + Settlement)** — not started; out of scope (§14).

## Final Status

**IMPLEMENTATION STATUS: PARTIAL** — driver-facing return reconciliation visibility (Expected
Return, per-product status, §6 shortage) and the §3/§13 security correction are COMPLETE on the
canonical authorities with zero backend change and no `Order.status` / warehouse-stock write from
the driver. The warehouse-side reconciliation is pre-built and preserved. Damage-disposition and
driver-attributed-liability records remain documented owner-decision deferrals (not driver-app
scope to invent).

**FINAL CERTIFICATION: DEFERRED.**

---

**STOP.** No Phase 6, no commit/push/deploy, no DEV business-data mutation.
