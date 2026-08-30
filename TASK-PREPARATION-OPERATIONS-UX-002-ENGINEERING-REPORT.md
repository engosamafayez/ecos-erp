# TASK-PREPARATION-OPERATIONS-UX-002 — Engineering Report

**Status: IMPLEMENTATION COMPLETE EXCEPT FINANCIAL SETTLEMENT CONTRACT** — plus browser smoke pending sign-in.
**Certification: DEFERRED.** Production untouched · DEV data NOT reset/deleted · no destructive git ops.
Date: 2026-08-20 · DEV only.

Audit-first (9-agent workflow, adversarially verified). Everything below reuses existing architecture; **no second preparation/procurement/shipping/payment/finance engine was created.**

## 1. Final UI architecture
Top-level Preparation = 3 destinations, achieved by **relabelling existing sidebar items** (the screens already exist — reused as-is, not rebuilt):
- **تحضير اليوم / Today's Preparation** → existing `WaveWorkspaceLayout`
- **الأرشيف / Archive** → existing `WaveArchivePage`
- **الإعدادات / Settings** → existing `WaveEngineSettingsPage`

Relabels: `common.json` `nav.items.wave-workspace|wave-archive|wave-engine` (en + ar). No route/screen rebuild.

## 2. Daily Preparation workspace
`WORKSPACE_TABS` now has the four tabs: **النشط · المواد المفقودة · الطلبات · قرارات العجز** (`wave-workspace-layout.tsx`). Archive/Settings are NOT tabs. النشط points at the product-demand view (per-product readiness from the prior task); the previously-omitted Raw Materials view remains routable.

## 3. Missing Materials
`WaveDemandController::missingMaterials` now also emits **Warehouse, Expected Incoming, Uncovered Shortage**. Frontend `wave-missing-materials-page.tsx` renders the two new columns. Related Product/Orders unchanged (existing `materialRelatedOrders` drill-down).

## 4. Expected Incoming — **no new field or table**
Derived READ-ONLY from `purchase_order_lines.quantity − received_qty` over **receivable** POs (`PurchaseOrderStatus::canReceive()` = approved | partially_received), company + warehouse scoped, in a new `Modules/Purchasing` query object `ExpectedIncomingQuery` (keeps the procurement query out of Preparation). **Uncovered = max(0, missing_qty − expected_incoming).** Proven (tests D/E) never to touch inventory_items, stock_ledger, GRN, FIFO, or reservations, and never to reduce the real `missing_qty`. Draft/submitted POs contribute 0.

## 5. Shortage Decisions ("قرارات العجز")
New read-only endpoint `GET waves/{waveId}/deficit-decisions` + new page `deficit-decisions-page.tsx`. A candidate = a product that is `material_status = waiting_material` **and** has a blocking material whose shortage is not covered by Expected Incoming (owner §13: a fully PO-covered shortage is NOT a candidate). List reuses the canonical `productRelatedOrders` join and the same `postponed_at` exclusion. Columns: Order, Customer, Product, Required, Prepared, Actual Missing, Expected Incoming, Uncovered, Payment/Order State, Wave, Warehouse, Decision. Decision drawer offers exactly two actions — no Delete/Cancel/Discount.

## 6. Continue Process Despite Shortage ("استمرار العملية رغم العجز")
New action `POST .../product-demand/{productId}/continue-despite-shortage`. It stamps `shortage_decision='continue'` + who/when on `wave_product_demand` (migration adds 3 nullable columns), and **relaxes the P-04 completion guard for decision-backed rows only**. "Not fully fulfilled" is the EXISTING canonical representation `prepared_qty < required_qty` — **no new OrderStatus, the order line and product are never deleted, and the order status/price/total are never touched** (proven by tests H/I/J/O).

## 7. Postponement ("تأجيل الطلب")
**Reused verbatim** — `usePostponeWaveOrder` → `POST waves/{waveId}/orders/{orderId}/postpone` → `WaveMembershipService::postponeOrder`. No new code, no new status.

## 8–11. Cross-module chain (audited; carried as DATA, not auto-propagated)
| Node | Verdict |
|---|---|
| Loading short-load | **EXISTS** — `LoadingTask`/`LoadProductAction` (`status=ShortLoaded`, `quantity_short`). |
| Driver short delivery | **EXISTS** — `AllocationRecord`/`RecordProductDeliveryAction` (`PartialDelivery`, `quantity_remaining`). |
| Customer not-received | **EXISTS** — `DeliveryReturnLine.undeliveredQty()`. |
| **Financial settlement** | **CONTRACT GAP — see below.** |

The `order_lines` fulfillment columns (`prepared_qty/loaded_qty/delivered_qty`) are confirmed **dead (no writer)**; the live per-stage truth lives in the tables above. Auto-projecting the Preparation decision into Loading/Driver/Customer records is a real follow-up (not built here); the shortage already travels as data in those records.

### 11. FULFILLMENT SHORTAGE FINANCIAL SETTLEMENT — CONTRACT GAP (scoped OUT, per §22/§33-D)
**No canonical contract settles an order's amount by delivered-vs-ordered quantity.** Evidence: Orders never generate an AR customer invoice; `delivery_confirmation` posts no GL by default; no posting rule maps a delivery/short/return event to a customer-receivable reduction; order totals are never recomputed from delivered qty (grep of `delivered_qty` in any invoice/settlement/amount context = none). Building it needs a **new BusinessEventType + posting rule** — a new accounting rule, which the contract forbids Preparation from inventing. **STOPPED and reported.** Preparation records only "not fully fulfilled"; it never mutates invoice totals.

## 12. Archive & 13. Settings
Re-parented by relabel only; existing `WaveArchivePage` / `WaveEngineSettingsPage` reused unchanged.

## 14. Product readiness & 15. Material arrival
Delivered in the prior task and unchanged: `material_status` per product; `RefreshDemandOnStockReceivedListener` re-projects on `InventoryStockReceived`. Verified still green (P-03 + readiness suites).

## 16. Tenant isolation
`ExpectedIncomingQuery` is company + warehouse scoped; deficit-decisions inherits the wave's company via `findWave`. Test Q proves another company's PO does not leak in.

## 17. Tests — exact results (serialized gate, `ecos-dev-testrunner`)
- **`DeficitDecisionsAndExpectedIncomingTest` — OK (7 tests, 27 assertions):** C (missing 15/expected 10/uncovered 5), D+E (never mutates inventory/ledger), F (covered → not candidate), G (uncovered → candidate), H/I/J/O (continue preserves line/order/total, records not-fully-fulfilled, allows completion), Q (tenant isolation), draft-PO-excluded.
- **`PartialPreparationCompletionGuardTest` + `MaterialAvailabilityContractTest` (P-03) — OK (16 tests, 47 assertions):** the P-04 guard still blocks undecided rows; P-03 material-availability contract intact.
- A/B (readiness) covered green by `ProductReadinessContractTest` (prior task).
- **Static:** `php -l` OK · Pint fixed/clean · PHPStan **L0 OK** · frontend `tsc` 23 = baseline (0 new) · ESLint 0 · `vite build` exit 0.
- Cross-module K/L/M/N (Loading/Driver/Customer) and O-finance: not implemented here (existing carriers verified by the audit; auto-propagation + settlement are follow-ups/gap).

## 18. Browser smoke — **BLOCKED (no auth)**
Scenarios 1–9 not executed: the DEV browser pane has no authenticated session and entering credentials is outside my permitted actions. Instead verified via real application paths: `ExpectedIncomingQuery`, `missingMaterials` (real wave: missing=1/expected=0/uncovered=1), and `deficitDecisions` (real wave → `[]`, correct: the credit-covered product is READY) all run on live DEV data; routes registered. Visual/UI confirmation needs a signed-in session.

## 19. Static verification — all green (see §17).

## 20. Remaining contract gaps / follow-ups
1. **FULFILLMENT SHORTAGE FINANCIAL SETTLEMENT — CONTRACT GAP** (Finance-owner escalation; new accounting rule required).
2. Auto-projection of the Continue decision into Loading/Driver/Customer records (data carriers exist; wiring is new).
3. `delivered ≤ loaded` integrity guard on `RecordProductDeliveryAction` (binds to allocated, not loaded).
4. Orphaned nested `WaveSettingsPage` (reachable-by-URL, two dead actions) — product decision to remove/repair.
5. Cross-wave shortage board (current endpoints are single-wave).

## Files changed
**Backend:** `Modules/Purchasing/PurchaseOrders/Application/Queries/ExpectedIncomingQuery.php` (new); `Modules/Operations/DemandAnalysis/Presentation/Http/Controllers/WaveDemandController.php`; `.../Domain/Models/WaveProductDemand.php`; `.../Infrastructure/Database/Migrations/2026_08_20_120000_add_shortage_decision_to_wave_product_demand.php` (new); `routes/api.php`.
**Backend tests:** `tests/Feature/Operations/DemandEngine/DeficitDecisionsAndExpectedIncomingTest.php` (new).
**Frontend:** `pages/deficit-decisions-page.tsx` (new); `pages/wave-missing-materials-page.tsx`; `components/wave-workspace-layout.tsx`; `hooks/use-preparation.ts`; `services/preparation-service.ts`; `types/preparation.ts`; `router/router.ts`; `router/routes.ts`; `i18n/{en,ar}/operations.json`; `i18n/{en,ar}/common.json`.

**Concurrency:** no other session was modifying these files; `routes/api.php` and i18n edited surgically; `route:clear` run before feature tests; no `git checkout/reset/restore`.
