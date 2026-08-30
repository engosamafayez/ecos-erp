# TASK-ORDERS-PREPARATION-PAYMENT-FINAL-FIX-001 — Engineering Report

> ## STAGE 2 UPDATE (post owner-decisions)
>
> Owner decisions received: **D3, D5, D6 approved as recommended. D1 replaced by a new
> owner contract ("Fulfillment Truth vs Physical Preparation Truth") with an explicit
> instruction to re-draft it and WAIT for confirmation before any ADR/schema/code change.**
>
> **Implemented this stage: D3, D4, D5, D6 + the two live security defects.**
> **NOT implemented: D1 (awaiting confirmation of the draft), D2 (deferred with D1).**
> **Preparation OS was NOT touched — no file under `Modules/Operations/DemandAnalysis` or
> `Modules/Operations/Preparation` was modified.**
>
> ### Files changed
> **Backend (10):** `StockOperationDTO.php` (+`permit_negative_commitment`), `ReserveStockAction.php`,
> `ReleaseStockAction.php`, `ReserveOrderInventoryAction.php`, `UpdateOrderAction.php`,
> `UpdateOrderRequest.php`, `OrderResource.php`, `PaymentProofResource.php`,
> `EloquentOrderRepository.php`, `ConfirmOrderWorkflow.php`.
> **Backend tests (1 new):** `tests/Feature/Orders/OrderEditReservationAndPaymentGuardsTest.php`.
> **Frontend (9):** `types/order.ts`, `order-detail-page.tsx`, `order-detail-drawer.tsx`,
> `payment-proof-section.tsx`, `payment-proof-service.ts`, `orders-service.ts`, `use-orders.ts`,
> `en/orders.json`, `ar/orders.json`.
>
> ### What each decision produced
> - **D3 — reserve made symmetric.** The made-to-order branch now writes the full commitment to
>   `inventory_items`, permitted by the recipe-executability decision via a new explicit
>   `permit_negative_commitment` flag (the FG's own `allow_negative_stock` is false, so the
>   inventory layer would otherwise reject it). `ReleaseStockAction` now treats a missing
>   inventory row as a **no-op instead of a 422**, so orders committed before the fix stay
>   editable. This is the same fix the sibling `allow_negative` branch already had.
> - **D4 — warehouse resolved.** `OrderResource` now emits a nested
>   `assigned_warehouse {id,name,code}`, eager-loaded on both list and detail. All four UI
>   sites now read it instead of `line.warehouse_name` (a column with no writer, null on every row).
> - **D5 — confirm gate re-pointed.** `ConfirmOrderWorkflow` now accepts a **verified,
>   non-superseded** `payment_proofs` row; the legacy `payment_proof_path` still works for
>   pre-lifecycle and WooCommerce orders. An *uploaded* (unreviewed) proof deliberately does
>   NOT clear a REQUIRED gate.
> - **D6 — `deposit_amount` back door closed.** Removed from `UpdateOrderRequest` and
>   `SOFT_FIELDS`; `recordPayment` service + `useRecordOrderPayment` hook added so the
>   guarded, audited `POST /orders/{id}/record-payment` is reachable.
> - **Gate bypass closed.** `payment_method_manual` on update now uses the same five-value
>   catalogue as create (was any string ≤100 chars, which silently defaulted an unknown
>   method to the non-blocking `'none'` and stripped a REQUIRED proof gate).
> - **`/api/api` 404 fixed.** `PaymentProofResource.download_url` is now API-root-relative.
> - **Payment UI.** `PaymentProofSection` (upload → **inline preview** → verify →
>   reject-with-reason → replace → history, plus uploaded/verified/rejected timestamps) is now
>   rendered in the **drawer** the list's "Verify Payment" action opens — previously a dead end
>   showing only a legacy link. Drawer paid-state now derives from `payment_state`, not from
>   `date_paid` (a WooCommerce-import-only field that made a paid manual order render "Paid"
>   and "Awaiting Verification" side by side).
>
> ### Verification results
> | Gate | Result |
> |---|---|
> | Affected + regression suites (PaymentProofLifecycle, ConfirmationGate, PaymentState, InventoryReservation, PreparationFulfillability, RecipeToOrderE2E, AvailabilityLifecycle) | **OK — 99 tests, 263 assertions** |
> | New regression suite (Part 2 scenarios A–D + payment guards) | **OK — 11 tests, 27 assertions** |
> | `php -l` / Pint / PHPStan L0 | **pass** (Pint: 9/10; `OrderResource.php` fails identically at HEAD — pre-existing, untouched) |
> | Frontend `tsc -p tsconfig.app.json` | **23 errors — identical to baseline, none in changed files** |
> | ESLint (changed files) | **0 problems** |
> | `vite build` | **exit 0** |
> | **Runtime — real ORD-00008** | Edit **succeeded** (was HTTP 422). Reservation survived (`reserved`). FG inventory row now exists: `on_hand=0, reserved=1, available=-1` — the physical truth that 1 unit must be prepared. `assigned_warehouse` resolves to **"Main Warehouse" (WH-MAIN)**. |
>
> ### Status per item
> | Item | Status |
> |---|---|
> | D3 reserve/release symmetry | **RUNTIME VERIFIED** (real ORD-00008 + 11 tests) |
> | D4 warehouse name | **RUNTIME VERIFIED** (API render) — UI **IMPLEMENTED**, not browser-verified |
> | D5 confirm gate | **IMPLEMENTED + tested** (unit-level via the workflow's own predicate) |
> | D6 deposit_amount + record-payment | **IMPLEMENTED + tested** |
> | Payment method catalogue / `/api/api` | **IMPLEMENTED + tested** |
> | Payment UI (drawer wiring, preview, metadata) | **IMPLEMENTED** — **not BROWSER VERIFIED** |
> | D1 physical-shortage contract | **BLOCKED — awaiting owner confirmation** of `TASK-D1-PREPARATION-PHYSICAL-SHORTAGE-CONTRACT-DRAFT.md` |
> | D2 dead shortage engines | **DEFERRED** with D1 (they interact — see the draft's §5 warning) |
> | Browser scenarios 1–7 | **BLOCKED** — no authenticated session available |
> | Certification | **NOT CLAIMED** |
>
> ### Carried-forward gaps (unchanged from the audit, not addressed this stage)
> `wave_manufacturing_demand` has no writer · `shortage_detected` dead flag · stale demand
> projection with no reservation-change invalidation · third broken shortage reader ·
> vacuous `CompleteWaveAction` guard · §17 RM reservation leaks on cancel · swallowed
> re-reserve `catch (Throwable)` in `UpdateOrderAction:194-212` · `date_paid`/`payment_status`
> still rendered elsewhere as payment authorities.

---

**Stage 1 below: AUDIT (no code changed).** All three tracks triggered the STOP conditions defined in the task.
**Date:** 2026-08-20 · **Environment:** DEV only · **Production untouched · No DB reset · No destructive git ops.**
**Method:** 3 parallel deep audits + 6 adversarial verifications (correctness + completeness lenses) + synthesis. Verifier corrections preferred over the original audits throughout.

---

## ⚠️ FIRST — a consequence of the previously-approved contract change

The ORD-00008 edit failure is a **direct downstream effect of TASK-ORDER-PREPARATION-FULFILLABILITY-CONTRACT-001** (which remains approved and is NOT being reopened).

`ReserveOrderInventoryAction.php:212-233` (recipe-executable branch) writes `$line->update(['reserved_qty' => $requested])` **outside** the `if ($available > 0.0)` guard that protects the *inventory* write. So the order line records a commitment that `inventory_items` never recorded.

Before the contract change that branch was unreachable for `can_manufacture = false` products. **Removing the `can_manufacture` gate made it reachable**, so ORD-00008 (FG with zero inventory rows) now gets `reserved_qty = 1` with no inventory row — and release-on-edit then throws.

Critically, **this exact bug was already found and fixed in the sibling `allow_negative` branch** at `:245-269`, whose comment reads: *"With on_hand = 0 the guard `$available > 0.0` was false, so NOTHING was written to inventory_items… precisely the reported symptom."* The manufacturing branch never received the same treatment. The fix is to apply the already-approved template — see **D3**.

---

## TRACK 1 — PREPARATION / MISSING MATERIALS

| | |
|---|---|
| **Finding** | Wave PREP-202608-000002 → Missing Materials shows *"No material shortages — all requirements are met!"* while RM "تجربه" (ECOS-RM-000001) has `on_hand = 0`. |
| **Root cause** | **The calculation is CORRECT per the approved contract. There is no lost quantity.** `MaterialDemandCalculator.php:244-248` genuinely computes `available=0, missing=1, coverage=0`; `:257-260` then deliberately overrides to `missing=0, coverage=100` **because `allow_negative_stock = TRUE`** — verbatim ADR-027 §18.4 and verbatim your own rule for this task. Everything downstream is a faithful consequence. |
| **Proof** | Differential execution: HEAD writes `(0,1,0)`; working tree **without** the override writes `(0,1,0)`; only the working tree **with** `:257` writes the `(0,0,100)` triple the DB actually holds. |
| **The real gap** | **Presentation.** The operator sees a green **"Sufficient"** badge, emerald `0`, Missing as `—`, and the row counted in the **"ok"** tab (`wave-raw-materials-page.tsx:59-67, :83, :96-98, :168`). A material on open credit is visually indistinguishable from one physically on the shelf. |
| **Classification** | **GAP (UI semantics) — BLOCKED on D1.** Not a calculation defect. |

**Adjacent structural defects found (none presentational):**
1. `wave_manufacturing_demand` **has no writer at all** — `DemandReadRepository::upsertManufacturingDemand()` (:232) has zero callers; `DemandProjectionBuilder::buildFull()` has no manufacturing layer. `GET waves/{id}/manufacturing-demand` returns `[]` permanently. This is exactly where ADR-027 §19 made-to-order would record *"1 unit of ECOS-FG-000001 must be produced."*
2. `preparation_waves.shortage_detected` is a **dead flag** — its only writer, `AnalyzeMaterialsAction`, **cannot run**: it joins `bill_of_materials` (singular; the real table is `bills_of_materials`) and selects columns that do not exist. It drives the operator-facing "Shortage Detected" badge. *Even deleting the override would not light that badge.*
3. **The projection is stale with no invalidation on reservation change** — `wave_material_demand.reserved_qty=1 @ 23:33:02`, live `inventory_items.reserved_qty=2 @ 23:35:22`. No listener fires when a non-member order reserves.
4. A **third** shortage reader is double-broken — `PreparationWaveController.php:521-559` reads field names the writer never persists; table is 0 rows.
5. `expected_today` / `in_transit_qty` are hardcoded `0.0` yet shown as default-visible columns. `CompleteWaveAction.php:44` gates on `waveItems()` which is **empty platform-wide → vacuous guard; a wave can complete with nothing prepared.**

**Canonical contract:** `MaterialDemandCalculator` → `wave_material_demand` is the SSOT; `wave_missing_materials` is a strictly derived projection of `missing_qty > 0`. **Correction:** it is *not* the single authority today — `shortage_detected` and `preparation_material_requirements` are two further, dead authorities. **Retire them onto the Demand Engine; do not build a second engine.**

---

## TRACK 2 — ORDER WAREHOUSE / EDIT ERROR

| | |
|---|---|
| **Finding** | Editing ORD-00008 (customer/address only) → **HTTP 422 "No inventory record found for the given warehouse and product"**. Inventory tab shows **Assigned Warehouse = —**. |
| **Root cause (A) — the 422** | `OrderStatus::isLocked()` is FALSE for `AwaitingPayment` (`OrderStatus.php:77-80`) → edit takes the structural branch (`UpdateOrderAction.php:128`) → `hasActiveReservation` true → `releaseInventory->execute()` → `ReleaseOrderInventoryAction:78` keys on `reserved_qty=1` → `ReleaseStockAction.php:48-56` finds no `inventory_items` row → throws. **Reserve wrote a commitment only to the order; release tries to hand back what inventory never took.** |
| **Root cause (B) — the "—"** | `order_lines.warehouse_name` **has no writer anywhere** (runtime-verified: 8 rows, 0 non-null). `OrderResource:258` exposes only the bare UUID; `EloquentOrderRepository::WITH_DETAIL:18` does not eager-load `assignedWarehouse`. Runtime-confirmed by me: the resource returns `assigned_warehouse_id` only — no name, no nested object (warehouse is "Main Warehouse" / WH-MAIN). |
| **Classification** | **(A) REAL BACKEND DEFECT — BLOCKED on D3. (B) REAL API/UI GAP — determinate fix, D4.** |

**Warehouse ownership is NOT ambiguous — that STOP question is closed.** `orders.assigned_warehouse_id` is the SSOT (relation `Order::assignedWarehouse()`, `Order.php:332-335`). The apparent second candidate, `ReleaseOrderInventoryAction::reservationWarehouseFor()` (`:155-166`), is a documented *release-time-only* authority that reads the reservation ledger and falls back to `assigned_warehouse_id` — verified inert here. `preparation_waves.warehouse_id` is never read as the order's.

**What IS undefined:** the meaning of `order_lines.reserved_qty` — reserve writes it as *customer commitment*, release reads it as *units inventory holds*. → **D3**.

**Three further defects in the same path:**
- **The §17 raw-material reservation leaks permanently.** `ReleaseOrderInventoryAction` iterates only `$order->lines` (FG), never the RM commitments written by `ReconcileOrderMaterialReservationsAction`. This is precisely why RM "تجربه" sits at `on_hand=0, reserved=2`. Edit masks it (reconcile is idempotent); **cancel leaks forever.**
- `order_lines.reserved_qty` has **no zeroing path** on release.
- `UpdateOrderAction.php:194-212` catches `Throwable`, logs a warning, sets `Pending`, and returns **HTTP 200** — once the 422 is fixed the failure moves here and **silently drops the reservation while reporting success.**

**Dead code — do not "fix" there:** `toEditPayload` (`order-form-schema.ts:199`) and `useUpdateOrder` (`use-orders.ts:134`) have **zero callers**. The live path is `toManualPayload` → `useUpdateManualOrder` → `PUT /orders/{id}`.

---

## TRACK 3 — PAYMENT UI / WORKFLOW

| | |
|---|---|
| **Finding** | ORD-00008 (`awaiting_payment`, `instapay`) cannot be moved forward. The list's "Verify Payment" action opens a drawer whose Payment tab has no verify capability. |
| **Root cause** | Not merely "the proof UI is invisible" — **every clearance path for an existing `awaiting_payment` order is dead or ineffective.** |
| **Classification** | **BLOCKED on D5/D6.** |

1. **Two disjoint proof systems.** `ConfirmOrderWorkflow.php:113-114` gates on `orders.payment_proof_path`; `UploadPaymentProofAction`/`VerifyPaymentProofAction` **never write that column**. Live confirmation: `payment_proofs` has 4 rows (one verified, one rejected→superseded→uploaded), yet **all** those orders still have `payment_proof_path = NULL` and remain `awaiting_stock`/`awaiting_payment`.
2. **The drawer is a dead end** — renders only the legacy `MediaViewer` path (`order-detail-drawer.tsx:937-953`), which cannot resolve new proofs (private `local` disk). `orders-page.tsx:722` wires "Verify Payment" to merely opening that drawer.
3. **The View button 404s** — `PaymentProofResource.php:28` emits an `/api`-prefixed URL into an axios instance already based at `/api` → `/api/api/...`.
4. **The edit-form escape hatch silently discards data.** `payment_proof_path` is absent from `UpdateOrderRequest` and from both `SOFT_FIELDS`/`STRUCTURAL_FIELDS`, yet the edit form still renders the uploader and still sends the field → operator uploads, sees it attached, saves, gets **HTTP 200, value discarded.**
5. **Both canonical clearance endpoints are unreachable from the UI** — `POST /orders/{id}/verify-payment` (`use-orders.ts:259`, zero callers; `order-payment-cell.tsx:65` destructures the prop and discards it) and `POST /orders/{id}/record-payment` (zero callers).
6. **The only reachable clearance is an unaudited back door** — `deposit_amount` IS mass-assignable via `PUT /orders/{order}` (`UpdateOrderRequest:64`, `SOFT_FIELDS:38`), bypassing `RecordOrderPaymentAction`'s overpayment guard, idempotency check, and `payment_recorded` audit event. → **D6**.
7. **A live gate bypass** — `UpdateOrderRequest:59` is `['nullable','string','max:100']` with **no `Rule::in`**, while create constrains to 5 values. `ConfirmOrderWorkflow:136` resolves an unknown method to `'none'`, so `PUT` with `payment_method_manual: "instapayy"` **bypasses the proof gate entirely**.
8. **A third payment authority is rendered and wrong** — `date_paid` is written **only** by the WooCommerce importer, yet drives paid-state in the drawer and on the detail page directly above `payment_state`. A fully-paid manual order can show "Paid" and "Awaiting Verification" side by side.
9. `orders.payment_status` is an **orphaned column** (no enum, not in `$fillable`, not in `OrderResource`; sole writer is a test) — yet it is rendered at `related-orders-panel.tsx:159` and snapshotted by `WaveMembershipService:102-110`, so **every order admitted to PREP-202608-000002 was snapshotted as unpaid**.

**PAYMENT-METHOD UPDATE CONTRACT — ANSWERED, NOT BLOCKED.** It exists: **`PUT /api/orders/{order}`** → `UpdateOrderRequest:59` + `UpdateOrderAction::SOFT_FIELDS`, permission `sales.orders.update`. **Do not invent an endpoint.**

**Canonical contracts:** proof evidence = `payment_proofs` + `PaymentProofState` (do not extend the legacy column); payment truth = `PaymentState::fromAmounts()` surfaced as `OrderResource.payment_state`; advancement only via `ConfirmOrderWorkflow` / `VerifyPaymentAction` / `RecordOrderPaymentAction`.

---

## STATUS CLASSIFICATION

| Item | Status |
|---|---|
| Track 1 root cause (calculation correct; presentation gap) | **RUNTIME VERIFIED** (differential execution + live DB) |
| Track 1 fix | **BLOCKED — D1**, plus **D2** for the dead engines |
| Track 2 (A) 422 root cause | **RUNTIME VERIFIED** (live data + elimination proof) |
| Track 2 (A) fix | **BLOCKED — D3** |
| Track 2 (B) warehouse name gap | **RUNTIME VERIFIED** by me (OrderResource render) — fix determinate, **D4** |
| Track 3 root causes | **RUNTIME VERIFIED** (live `payment_proofs` rows + dead-caller analysis) |
| Track 3 fix | **BLOCKED — D5, D6** |
| Any code change | **NONE — nothing implemented this stage** |
| Browser scenarios 1–7 | **BLOCKED** — browser pane has no auth session (`/app/login`, empty localStorage); I cannot enter credentials |
| Certification | **NOT CLAIMED** |

---

## OWNER DECISIONS REQUIRED

| # | Decision | Recommended default |
|---|---|---|
| **D1** | How must an `allow_negative_stock` material *read* to the operator? ADR-027 §18.4 says only what it must NOT be. | **(c) copy + badge only, no schema change** — ship a "Drawable on credit" badge and honest empty-state text now; keep `coverage_pct` overridden (the wave projection reads it) until a schema change is separately approved. |
| **D2** | The two dead shortage engines (`AnalyzeMaterialsAction` + `preparation_material_requirements`). | **Retire them onto the Demand Engine**; point `shortage_detected` at `wave_material_demand`; add the missing manufacturing-demand layer. Do not repair a second engine. |
| **D3** | Meaning of `order_lines.reserved_qty` — customer commitment vs units inventory holds. | **Make reserve symmetric**, using the already-approved template at `ReserveOrderInventoryAction.php:245-269` (the identical fix the `allow_negative` branch already received), paired with `ReleaseStockAction` tolerance. Reject the field-split for go-live (migration + every reader). |
| **D4** | How the UI gets the warehouse name. | **Nested `assigned_warehouse {id,name,code}` on `OrderResource` + eager-load in `WITH_DETAIL`; drop the dead `order_lines.warehouse_name` column.** |
| **D5** | Which proof system does `ConfirmOrderWorkflow` gate on? | **(b) re-point the gate at `payment_proofs`** (verified, non-superseded). (a) re-blesses a legacy column and leaks proofs to a public URL; (c) ships a screen where "Verify" visibly succeeds and nothing happens. |
| **D6** | May `deposit_amount` stay mass-assignable via `PUT /orders/{order}`? | **Remove it** — it is currently the only reachable way to clear the confirm gate and it bypasses the overpayment guard, idempotency, and the audit event. Requires shipping the record-payment UI in the same change. |

**Non-blocking, ship in the same release:** `Rule::in` on `payment_method_manual` (live gate bypass); the `/api/api` double prefix; the swallowed re-reserve `catch (Throwable)`; the permanently-leaking §17 RM reservation on cancel; the vacuous `CompleteWaveAction` guard; retiring `date_paid` and `payment_status` as rendered authorities.

**Shipping-state warning:** the entire ADR-027 §18 rewrite in `MaterialDemandCalculator.php` is **uncommitted working-tree only** (266 insertions / 12 deletions vs HEAD) and was authored by another session (~23:47 on 2026-08-19). Any release plan must account for it.

---

## REMAINING GAPS
1. **Browser verification not executed** — no authenticated session available; all 7 scenarios are **BLOCKED**, not failed.
2. Nothing implemented pending D1–D6.
3. `MaterialDemandCalculator` §18 work is uncommitted and not mine — coordinate before touching that file.
