# ADR-043: BOM/Recipe Change & Warehouse Reassignment — Reservation Lifecycle

**Status:** Approved
**Version:** v1.0
**Date:** 2026-08-19
**Author:** Engineering Architecture Review
**Inputs:** TASK-ORDERS-INVENTORY-MANUAL-REMEDIATION-001 (owner Decisions A1 + Multi-Warehouse)
**Related:** ADR-027 (Reservation Ownership Policy), ADR-015 (Enterprise Fulfillment), ADR-026 (Transfer Events)

---

## Context

Two reservation-lifecycle change events were previously undefined and repeatedly
recorded as *"CONTRACT GAP — OWNER DECISION REQUIRED"* across verification reports:

1. **BOM/recipe change while a reservation is active.** ADR-027 §16/§17 defines how
   a reservation *derives* raw-material requirements from the active BOM in
   steady state, but not what happens to a *live* reservation when the BOM
   subsequently *changes*. The BOM write paths (`SetBomStatusAction`,
   `UpdateBomAction`, `BomController`) currently touch reservations not at all.

2. **Warehouse reassignment of an already-reserved order.**
   `WarehouseAssignmentEngine::override()` rewrites `assigned_warehouse_id` with
   no release/re-reserve orchestration. Safe today (single-warehouse) but unsafe
   once multi-warehouse activates.

This ADR ratifies the owner decisions for both. **It is the contract of record;
implementation is tracked separately and is NOT part of the task that raised it.**

## Decision A1 — BOM/Recipe Change vs Active Reservation → **Option A**

When a product's Recipe/BOM changes and that product has an **active reservation**,
the system MUST apply **release semantics** to the existing reservation:

1. The existing reservation is **released** — never left stale, and never stacked
   beneath a second reservation for the same demand.
2. The reservation is **recomputed** against the newly-active BOM (the ADR-027
   §17 derivation), producing a single fresh reservation.
3. The whole release-then-recompute is **atomic** — no window in which the demand
   is doubly-reserved or unreserved-but-consumed.
4. Recalculation MUST NOT produce a **negative or duplicate** reservation.
5. After the change, the **ledger and reservation state are consistent** — the
   ADR-027 invariants (one reservation per demand; reservation warehouse recorded
   on the reservation itself) continue to hold.

**Not in scope of Option A / explicitly NOT invented here:** over-delivery,
refusal/damage, or variance-resolution semantics; those remain governed by their
own contracts. Option A governs only the release-then-recompute on a BOM change.

**Ownership:** Manufacturing owns the BOM change event; the reservation
release/recompute is executed through the existing Orders reservation actions
(`ReleaseOrderInventoryAction` then the ADR-027 §17 reservation chain) — no new
reservation authority is introduced. Release MUST use the warehouse recorded by
the reservation itself (the ADR-027 / `ReleaseOrderInventoryAction` rule), never a
mutable `assigned_warehouse_id`.

## Decision — Warehouse Reassignment Reservation Lifecycle (multi-warehouse)

The platform currently operates on a **single warehouse**; no multi-warehouse
orchestration is to be built prematurely, and the existing single-warehouse
behaviour is preserved. When multi-warehouse is activated, reassigning an order
to a new warehouse MUST follow this sequence:

1. **Release** the existing reservation from the **old** warehouse.
2. **Reassign** the order to the **new** warehouse.
3. Start a **fresh reservation attempt** against the **new** warehouse.
4. The old reservation MUST NOT remain active.
5. The order MUST NEVER simultaneously reserve the same demand in both warehouses.
6. The reservation **record is NOT moved** between warehouses — the old one is
   released and a new one is created.

The single correct implementation seam is `WarehouseAssignmentEngine::override()`,
which must (inside its transaction) release against the old warehouse and reset
`reservation_status`/`inventory_reserved_at` before the `assigned_warehouse_id`
rewrite, so the existing `WarehouseAssigned` → H3 path
(`ExecuteReservationOnWarehouseAssigned`, which requires `Pending`) re-executes
reservation in the new warehouse.

## Consequences

- A BOM change on a reserved product becomes a deterministic, atomic
  release-then-recompute rather than an undefined/no-op event.
- Multi-warehouse reassignment has a ratified, safe lifecycle to implement against
  when it is activated; until then, single-warehouse behaviour is unchanged and
  the certified `ReleaseOrderInventoryAction` warehouse-source fix remains intact.
- No new financial or inventory contract is introduced; both decisions reuse
  existing reservation actions and invariants.

## Status of Implementation

**Contract ratified; implementation pending and out of scope for
TASK-ORDERS-INVENTORY-MANUAL-REMEDIATION-001** (which implemented only the SKU and
tenant-isolation decisions). This ADR satisfies the "document the decision in the
appropriate contract/ADR before certification" requirement.
