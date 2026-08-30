# TASK-DRIVER-OWNER-DECISION-AUDIT-002 — Driver OS Wave 1 + Wave 2 Owner Decision Audit

**Date:** 2026-08-24
**Type:** READ-ONLY owner-decision audit. No implementation.

## 1. Executive Summary

Driver OS Wave 1 (Group-as-Shipment loading) is implemented and focused-verified; Wave 2 (delivery) has one shipped slice (Started Delivery) and four open items. This audit consolidates every remaining decision. The headline findings:

- **No new migration is strictly required for Wave 2** as originally feared. `order_lines.delivered_qty` **already exists**; the canonical delivered-quantity engine (`RecordProductDeliveryAction` → `allocation_records`) **already supports `actorType='driver'`**; `payment_proofs` (canonical, tenant-safe) **already exists** and is order-attached; `FailureReason` (15 cases) **already exists** in Stack A; and three real file-upload patterns exist to reuse. Most blockers are **wiring + authorization + one architecture selection (delivery-proof storage)**, not new engines or stores.
- **The real cross-cutting risk is duplicate sources of truth**, not missing ones: delivered-qty (`allocation_records.quantity_delivered` canonical vs `order_lines.delivered_qty` unwired), payment proof (`payment_proofs` canonical vs `distribution_payment_collections` tenancy-less vs `delivery_cod_records`), and delivery proof (`distribution_delivery_proofs` vs `delivery_pods`, neither file-capable). The decisions below all resolve to **"elect the canonical, do not add a competitor."**
- **A genuine flow dependency**: driver partial-delivery quantities require **allocation** to have run after loading (`loading_complete → allocating → allocated`), which is an operator/Stack-A step. Delivery-with-quantities cannot precede it.

## 2. Frozen Contract (not reopened)

Group = Shipment; one Group → one Driver + one Vehicle; Trip is internal only; ownership Order→Zone→Group→Shipment→Driver/Vehicle→Loading→Delivery; Group-grain loading via `LoadProductAction` + `VehicleInventoryService` (NULL pool provenance allowed; actual loaded enters inventory; over-load rejected; pool-based loading keeps provenance); Finalize / Zone→Group / Template→Group unchanged; Template Recommended Drivers are suggestions only; Browser-Verification-Pending is not a dev blocker. All treated as decided.

## 3. Wave 1 Audit

Implemented and verified (7/7 focused tests, D-02 21/21): the loading flow is architecturally complete (see §6). Open items are **W1-01 permission confirmation**, **W1-02 Driver Home final contract**, **W1-03 browser certification procedure** — none reopen the frozen contract.

## 4. Wave 2 Audit

Delivery: order cards + order detail exist and are D-01-aligned; delivery-outcome recording (`stopAction`) exists; **Started Delivery** shipped. Open: **W2-01 partial-delivery quantities**, **W2-02 delivery proof**, **W2-03 payment-transfer proof**, **W2-04 failure/delay vocabulary** (see §7–§10).

## 5. Driver Home Contract (W1-02)

**Finding:** all 12 driver pages exist **and are mounted** (loading, stop-list, stop-detail, map, collections, exceptions, returns, settlement, custody, timeline, trip-dashboard, home). The gap is that the Home does not **surface** them, and there is **no canonical driver-financial source** — the only money models are `Trip`/`TripSettlement`/`SettlementService`, which are the **frozen** (403) surface, and the audit's AD-7 established "no driver-keyed money store exists."

**Recommended FINAL Driver Home contract (Wave-1/2 scope):**
| Section | Source | Status |
|---|---|---|
| Driver identity | `useAuthStore` (auth context) | EXISTING (rendered) |
| Current shipment card (Group: assigned-order count) | `Σ stops_count` (canonical) | EXISTING (rendered) — extend into a card that links to Loading + Delivery |
| Operational entry: **Loading** | Wave-1 `/api/driver/loading` | EXISTING (Start Loading CTA) |
| Operational entry: **Delivery / Stops** | `DriverRuntimeController::stops` | EXISTING page, **not linked from Home** → mount an entry card (implementation only, no new architecture) |
| Delivery progress summary (delivered / remaining stops) | derive from stop statuses | NEEDS IMPLEMENTATION (read exists; no aggregate endpoint) |
| **Wallet / financial** | — | **NOT AVAILABLE** — frozen money surface, no canonical driver ledger (AD-7). **Do not invent.** Wave 3. |
| **Reports / metrics** | — | **NOT AVAILABLE** — no metric derives from delivery execution (audit §12). Wave 3. |

**Scope ruling:** Home identity + shipment summary + Loading/Delivery entry cards + a delivery-progress summary are **Wave 1/2 (implementation, no new architecture)**. Wallet and reports are **Wave 3** and require an architecture decision (AD-7/AD-8) — do not let them expand D-01.

## 6. Loading Contract (W1-04) — resolved, freeze

Canonical **read** = `DistributionAggregationService::productAggregation` (Required) + `GroupPreparationService::preparedByProduct` (Prepared) + `loading_tasks` (Loaded). Canonical **write** = `LoadProductAction` → `VehicleInventoryService`. **Ownership** = D-02 fail-closed (driver-owned AND company-owned, via `currentTrip`/`ownedGroup`). **Quantities:** planned = live Required; loaded = actual; shortfall = `quantity_short`; **over-load rejected (422)**; **idempotent** absolute-set on `(vehicle_assignment, product)`; completion is **assignment-scoped**. **Nothing architecturally unresolved.**

## 7. Delivery Contract — Partial Delivery (W2-01)

- **Canonical action:** `RecordProductDeliveryAction::execute(AllocationRecord $record, float $quantityDelivered, string $actorId, string $actorType='driver')` — lockForUpdate, **over-delivery fails closed**, **idempotent** absolute-set; **already accepts `actorType='driver'`**.
- **Dependency:** it operates on an **`AllocationRecord`** (`quantity_requested/allocated/loaded/delivered/remaining`, `is_partial`, `partial_reason`), which is produced by **allocation** (`loading_complete → allocating → allocated`). Delivery-with-quantities therefore **requires allocation to have run** — an operator/Stack-A step upstream of the driver.
- **`delivered_qty`:** `order_lines.delivered_qty` **exists** (migration 2026‑07‑14) but has **zero writers**; the canonical delivered authority is **`allocation_records.quantity_delivered`** (owner decision D-A Option 2, per the action's own docblock). **No migration required.** `order_lines.delivered_qty` is a legacy/unwired column — either leave it or reconcile it later; it is **not** the driver's source of truth.
- **Effect on entities:** stop (Stack B `DeliveryStop.status` → Partial/Delivered) is the **outcome**; the **quantity** lives on `allocation_records` (Stack A). Remaining = allocated − delivered (allocation-derived). Repeated cycles / full-after-partial: the absolute-set handles it (no double count); over-delivery refused.
- **Driver visibility:** allocated / delivered / remaining come from `allocation_records`; ordered comes from `order_lines`.
- **Recommendation:** a thin driver endpoint that lists the stop's order `allocation_records` and delegates to `RecordProductDeliveryAction` (fail-closed ownership, `loading.driver.operate`). **No migration, no new engine.** Gate on: allocation must exist. This bridges the two delivery stacks (Stack B outcome ↔ Stack A quantity) — the "unbridged stacks" (audit §17) — which is the one architecture point to confirm.

## 8. Proof Architecture — Delivery Proof (W2-02)

**Existing canonical upload patterns (reuse, do not invent):**
1. `app/Core/Documents/` — generic polymorphic `documents` table + `DocumentService`.
2. `DriverController::storeDocument` — **proven driver-side** validated upload (`file` required, `mimes:jpg,jpeg,png,pdf`, size cap) to a disk.
3. `UploadPaymentProofAction` — real `UploadedFile`, private disk, MIME sniff, `size_bytes`, supersede chain, tenant-scoped.

**Existing proof stores:** `distribution_delivery_proofs` (Stack B, attached to the **stop**, client-supplied path string — no file) and `delivery_pods` + `delivery_pod_artifacts` (Stack A, attached to the **attempt**). **Neither accepts a file today.**

**Recommendation:** attach delivery proof to the **stop** (Stack B, the driver's grain), and give it a **real file** by reusing an existing uploader — preferably the `documents`/`DocumentService` pattern (no new table) or the `DriverController::storeDocument` pattern. Tenant isolation + private disk come from the reused pattern. **The one open architecture choice:** reuse `documents` (no migration) vs. add file-metadata columns to `distribution_delivery_proofs` (a migration). Route + permission: a driver `POST …/stops/{stopId}/proof` under `loading.driver.operate`. **Do not** create a fourth proof store.

## 9. Payment Proof Architecture (W2-03)

- **Canonical:** `payment_proofs` — `company_id` FK + `order_id` FK, `state` (uploaded|verified|rejected), `uploaded_by/verified_by/rejected_by`, supersede chain (`replaces_proof_id`, `superseded_at`), indexed by `(order_id, superseded_at)` and `(company_id, order_id)`. Written by **`UploadPaymentProofAction`** (Modules/Commerce/Orders). Attached to the **Order** (not a status).
- **Non-canonical competitors (do not use):** `distribution_payment_collections` (**no `company_id`** — fails tenancy) and `delivery_cod_records`.
- **Recommendation:** the driver **reuses the canonical `payment_proofs` mechanism** — a thin driver endpoint that resolves the Order from the stop and delegates to `UploadPaymentProofAction`. **No new store, no money-surface change.** Payment-transfer proof belongs to the **Order**. Open decisions: (a) **authorization** — may a driver upload (not verify) an order's payment proof? (maker-checker: upload only, never verify — consistent with D-02); (b) whether a dedicated `method`/`transfer` marker is needed on `payment_proofs` (a small nullable column = a migration) or an existing field suffices.

## 10. Failure / Delay Contract (W2-04)

- **Canonical vocabulary exists:** Stack A `FailureReason` (15 cases): `customer_unavailable, customer_refused, customer_rescheduled, no_answer` (customer); `address_not_found, address_inaccessible, wrong_area` (address); `product_damaged, wrong_item, item_missing` (product); `cannot_pay, amount_disputed` (payment); `vehicle_breakdown, time_exhausted, weather` (operational). **No `other`.** Stack B (driver) has only free-text `exception_type`.
- **Recommendation:** **adopt Stack A's `FailureReason`** for the driver stack (validate the driver's reason against `FailureReason::values()`) — **no new enum, no new engine; a validation change, not a migration.** Optionally add an `other` case (a one-line enum edit — code, not migration) if the owner wants a catch-all.
- **Terminal vs retryable (owner ruling):** terminal → `customer_refused`, `address_not_found`, `product_damaged`, `cannot_pay` (settle the stop Failed); retryable → `customer_unavailable`, `no_answer`, `customer_rescheduled` (re-attempt / reschedule).
- **Delay:** map the driver's `delay` to `customer_rescheduled` with a `new_delivery_date`; today Stack B's `delay` returns `null` from `outcomeFor()` so the stop **never settles** — the owner must decide whether a rescheduled stop **stays with the same driver** (re-attempt) or **returns to operations** (re-planning). **Order lifecycle:** failed/partial/delayed delivery must NOT be auto-written to `Order.status` by the driver (the frozen guard) — it stays on `DeliveryStop.status`; any `Order.status` transition remains the Fulfillment engine's, downstream.

## 11. RBAC / Tenancy Audit (W1-01)

- `loading.driver.operate` is the driver permission (D-02); it gates the entire `/api/driver/*` group and is **already scoped to the driver's own shipment** by the fail-closed resolvers (`driver()`, `ownedTrip`, `ownedStop`, `currentTrip`, `ownedGroup`). The Wave-1 loading and Wave-2 Started-Delivery endpoints reuse it.
- The operator loading entry `openGroupLoading` uses `operations.preparation.update` — **correctly NOT granted to drivers**; the driver reaches the same domain via the fail-closed adapter.
- **Recommendation:** keep **`loading.driver.operate` as the single driver-operations permission** across loading + delivery + (future) proof/payment endpoints, always enforced against the driver's own Group/Shipment. **No broader permission** (`operations.preparation.update`, `logistics.distribution.update`) should ever be granted to drivers. This is already the state — **confirm, do not change.**

## 12. Cross-Task Conflict Audit

- **A. Competing engines:** none needed. Loading = `LoadProductAction`; delivery-quantity = `RecordProductDeliveryAction`; proof = an existing uploader; payment = `UploadPaymentProofAction`; failure = `FailureReason`. The only structural tension is the **two delivery stacks** (Stack B stop-outcome ↔ Stack A allocation-quantity), bridged by W2-01, not duplicated.
- **B. Duplicate sources of truth (elect canonical, do not add):** delivered-qty → `allocation_records.quantity_delivered` (not `order_lines.delivered_qty`); payment proof → `payment_proofs` (not `distribution_payment_collections`/`delivery_cod_records`); delivery proof → one file-capable store (not both `distribution_delivery_proofs` and `delivery_pods`).
- **C. Conflicting status machines:** the driver writes only `DeliveryStop.status`; `Order.status` stays with the Fulfillment engine (frozen guard). Keep this boundary.
- **D. Conflicting ownership:** none — everything hangs off the D-02 driver→shipment chain.
- **E. Migration that creates a duplicate store:** avoid — `delivered_qty` exists, `payment_proofs` exists, `FailureReason` exists. The only *possibly* new migration is delivery-proof file metadata **iff** `documents` is not reused.
- **F. Reuse:** `RecordProductDeliveryAction`, `UploadPaymentProofAction`, `DocumentService`/`DriverController::storeDocument`, `FailureReason`, `loading.driver.operate`, the D-02 ownership resolvers.
- **G. Ordering dependency:** allocation (post-loading) MUST run before driver partial-delivery quantities.

## 13. Dependency Graph

```
D-01 Driver Home (identity + shipment count + empty state) ........... EXISTING
  ↓
Wave 1 Group Loading (manifest → actual loaded → inventory) .......... EXISTING (verified)
  ↓  [operator/Stack-A: allocation  loading_complete→allocating→allocated] ... EXISTING (upstream dependency)
  ↓
Wave 2 Delivery
  ├── Started Delivery ............................................... EXISTING (shipped)
  ├── Order cards / Order detail ..................................... EXISTING (D-01-aligned)
  ├── Partial Delivery (RecordProductDeliveryAction) ................. NEEDS IMPLEMENTATION (no migration) — BLOCKED ON allocation dependency
  ├── Delivery Proof ................................................. NEEDS ARCHITECTURE DECISION (reuse documents = no migration; else migration)
  ├── Payment Transfer Proof ........................................ NEEDS IMPLEMENTATION (reuse payment_proofs) + AUTHORIZATION DECISION
  └── Failure / Delay vocabulary ..................................... NEEDS OWNER DECISION (adopt FailureReason) — no migration
  ↓
Wave 3 (Wallet, Reports, Commission, Custody, Closing) ............... NOT REQUIRED yet (no canonical source; AD-7/AD-8)
```

Home "wallet/reports" cards: **NOT REQUIRED** (Wave 3 / no canonical source). Home "Delivery entry card + progress summary": **NEEDS IMPLEMENTATION** (no new architecture).

## 14. Decision Matrix

| ID | Decision | Current State | Canonical Reuse | Migration? | Architecture Change? | Implementation Needed? | Dependency | Recommendation |
|----|----------|---------------|-----------------|------------|----------------------|-------------------------|------------|----------------|
| W1-01 | Driver loading/ops permission | `loading.driver.operate`, own-shipment scoped | `loading.driver.operate` + D-02 resolvers | No | No | No (confirm) | — | Keep as the single driver permission; never grant `operations.preparation.update`/`logistics.distribution.update` |
| W1-02 | Driver Home final contract | Identity + count + Start Loading | existing pages + read models | No | No (Wave1/2 parts); Yes (wallet/reports = Wave 3) | Yes (Delivery entry card + progress summary) | delivery flow | Add Loading/Delivery entry cards + delivery-progress; wallet/reports deferred to Wave 3 (no canonical source) |
| W1-03 | Live browser certification | mutation paths unverified (0 data) | existing tests | No | No | No | legitimate driver+vehicle+group+orders(+allocation) | Not a dev blocker; certify via a UAT script once legitimate data exists |
| W1-04 | Loading flow | Implemented, 7/7 + D-02 21/21 | `LoadProductAction`/`VehicleInventoryService` | No | No | No | — | Freeze |
| W2-01 | Partial delivery quantities | engine exists; unwired to driver | `RecordProductDeliveryAction` (`actorType='driver'`), `allocation_records` | No | Bridge Stack B↔A (confirm) | Yes (thin driver endpoint) | **allocation must run first** | Route driver to `RecordProductDeliveryAction`; `allocation_records.quantity_delivered` is canonical (not `order_lines.delivered_qty`) |
| W2-02 | Delivery proof storage | 2 stores, neither file-capable | `documents`/`DocumentService` or `DriverController::storeDocument` | No (if `documents` reused) / Yes (if adding columns) | Yes — pick one store | Yes | — | Attach to stop; reuse `documents` uploader (no new table); private disk + tenant scope |
| W2-03 | Payment-transfer proof | canonical unused on driver path | `payment_proofs` + `UploadPaymentProofAction` | No (unless a `method` marker wanted) | No | Yes (thin driver endpoint) | order resolvable from stop | Reuse `payment_proofs` (order-attached); driver may upload, never verify; ignore `distribution_payment_collections` |
| W2-04 | Failure / delay vocabulary | free-text; `delay` never settles | Stack A `FailureReason` (15) | No | No (validation only) | Yes (adopt enum + settle delay) | — | Adopt `FailureReason`; map `delay`→`customer_rescheduled`+date; owner rules terminal-vs-retryable + stay-with-driver-vs-return-to-ops |

## 15. Recommended Implementation Order

1. **Freeze (complete):** Driver Home base (D-01), Wave-1 Loading, Started Delivery.
2. **Immediately after owner approval, no migration:**
   - **W2-04** Failure/Delay (adopt `FailureReason` + settle `delay`) — self-contained, unblocks honest failure reporting.
   - **W1-02** Home Delivery entry card + delivery-progress summary — pure frontend/read wiring.
   - **W2-03** Payment-transfer proof (reuse `payment_proofs`/`UploadPaymentProofAction`) — pending the upload-authorization ruling.
3. **After the allocation dependency is confirmed present in the operator flow:**
   - **W2-01** Partial delivery (route to `RecordProductDeliveryAction`). Do **not** start before allocation produces `allocation_records`, or the driver has nothing to deliver against.
4. **After the one architecture decision (delivery-proof store):**
   - **W2-02** Delivery proof (reuse `documents` = no migration; else a migration → re-authorize).
5. **Must NOT start yet:** Wave 3 (Wallet, Reports, Commission, Custody, Closing) — no canonical driver-money/metric source (AD-7/AD-8); and any change to the frozen Group/Loading/RBAC contract.

Ordering prevents conflict: W2-04 and W2-03 are independent; W2-01 must follow allocation; W2-02 is gated by one architecture pick; nothing here duplicates a store or engine.

## 16. STOP Conditions Encountered

- **Delivery-proof file storage (W2-02)** — an architecture selection is required (reuse `documents` vs. migrate `distribution_delivery_proofs`). **Reported, not decided.**
- **Payment-upload authorization (W2-03)** — whether a driver may upload an order payment proof, and whether a `method` marker column is needed, is an owner/money-surface decision. **Reported, not decided.**
- **Allocation dependency (W2-01)** — driver partial-delivery requires the upstream allocation step; if allocation is not run in the live operator flow, W2-01 is **blocked** until it is. **Reported.**
- No new ownership model, no new status engine, no second source of truth, and no incompatible change to a certified engine is required by any recommendation above.

## 17. Verification Status

Read-only. Inspected: routes, `DriverRuntimeController`/`DriverLoadingController`, `LoadProductAction`/`RecordProductDeliveryAction`/`VehicleInventoryService`, `GroupPreparationService`/`DistributionAggregationService`, `payment_proofs`/`UploadPaymentProofAction`, `documents`/`DocumentService`/`DriverController::storeDocument`, `FailureReason`, `order_lines` schema, and live counts (0 drivers/vehicles/stops/allocations). Existing tests noted (not re-run): `GroupGrainDriverLoadingTest` 7/7, `DriverRbacTenancySecurityTest` 21/21, `GroupTripLoadingIntegrationTest`. No suite was modified or re-run for this audit.

## 18. Data Safety

No database writes, no migration, no seed data, no fabricated drivers/vehicles/groups/trips/stops. Only `SELECT`/schema reads and source inspection were performed.

## 19. Owner Decisions Required

1. **W2-01** Confirm bridging Stack B stop-outcome ↔ Stack A `allocation_records` for driver partial delivery, and confirm the operator allocation step runs before delivery. (No migration.)
2. **W2-02** Choose the delivery-proof store: reuse `documents` (no migration) **or** migrate `distribution_delivery_proofs` to hold a real file.
3. **W2-03** Approve driver **upload-only** access to the canonical `payment_proofs` for an order; decide whether a `method`/`transfer` marker column is needed.
4. **W2-04** Adopt Stack A `FailureReason` for the driver stack; rule terminal-vs-retryable and whether a delayed stop stays with the driver or returns to operations; whether to add an `other` case.
5. **W1-02** Approve the Home final contract (Loading/Delivery entry cards + delivery-progress summary now; wallet/reports = Wave 3, no canonical source).
6. **W1-01 / W1-04** Confirm `loading.driver.operate` as the single driver permission and freeze the loading flow. (No change.)

---

**NO IMPLEMENTATION WAS PERFORMED.
NO BACKEND OR FRONTEND FILES WERE MODIFIED.
NO MIGRATION WAS CREATED OR EXECUTED.
NO DATABASE DATA WAS MUTATED.
WAVE 3 WAS NOT STARTED.**
