# TASK-PROCUREMENT-PO-RECEIVING-CENTER-CLOSURE-001 — Engineering Report

**Procurement PO-Driven Receiving Center — UX (Supplier Filter) + DEV Runtime Closure**
Date: 2026-08-29 · Branch: `develop` · Status: **COMPLETE** · DEV runtime parity: **RESTORED**

---

## Executive Summary

This closure task finished the two remaining operational items on the already-accepted PO-driven
Receiving Center, without touching the certified receiving architecture:

1. **Supplier filter** — the Receiving Center queue now exposes a Supplier control, wired to the
   Purchase Order's **existing server-side `supplier_id` filter**. It reuses the canonical
   `useSupplierOptions` supplier read/select infrastructure and the approved ECOS **Search + Filters**
   responsive toolbar, so no new endpoint, no duplicated supplier data, and no client-side filtering
   authority were introduced.
2. **DEV runtime refresh** — the approved PO-driven Receiving Center source (backend + frontend) is now
   served by the current DEV runtime. The three receiving routes are registered and auth-protected in
   `ecos-dev-app`; the fresh SPA bundle (containing the Supplier filter) is served by `ecos-dev-nginx`.

No certified authority, `goods_inward_mode`, or Supplier Invoice behaviour was changed. No DEV business
data was mutated; no commit/push was made; nothing was deployed outside DEV.

---

## 1. Remaining Scope

Exactly three items, per the task:

- **A. Supplier filter** — add the missing Supplier control to the Receiving Center UI over the
  already-supported canonical backend `supplier_id`.
- **B. DEV runtime refresh** — apply the completed PO-driven Receiving Center to the current DEV runtime.
- **C. Read-only DEV confirmation** — confirm the new queue and mobile presentation are served.

Scope was not broadened. The Supplier Invoice enhancement and the damage/rejected disposition gap were
**not** started (see §12, §16).

## 2. Supplier Filter Implementation

The Supplier control was added to the Receiving Center's filter set (which already had Search, Warehouse,
Date, and — via the Active/History tabs — receiving state). It reuses existing infrastructure end to end:

- **Supplier options:** `useSupplierOptions` (`frontend/src/features/purchase-orders/hooks/use-supplier-options.ts`)
  — the same active-supplier, company-scoped select source (`suppliersService.list({ per_page: 200,
  status: 'active' })` → `ComboboxOption[]`) used by the Goods Receipts and Purchase Order screens. No new
  supplier endpoint, no duplicated master data, no unrestricted history load.
- **Toolbar:** the approved `EntityToolbar` + `FilterPanel` (`@/components/crud`) — Search stays inline;
  Supplier / Warehouse / Date collapse behind a single **Filters** toggle (see §4). The bespoke inline
  filter row was replaced by this canonical pattern.
- **State → query:** selecting a supplier sets `supplierId`, which flows into the queue params as
  `supplier_id` (empty string = "all" → omitted). The server does the filtering.

## 3. Server-Side Filter Trace

The filter is server-side; the client only passes the id:

1. **UI** — `receiving-center-page.tsx`: the Supplier `<select>` sets `supplierId`; `params.supplier_id =
   supplierId || undefined`.
2. **Service** — `receiving-service.ts`: `api.get('/receiving/queue', { params })` forwards `supplier_id`
   as a query parameter (no client-side row filtering anywhere).
3. **Controller** — `ReceivingCenterController::queue()` (unchanged; already present):
   `->when($request->query('supplier_id'), fn ($q, $v) => $q->where('supplier_id', $v))`, applied to both
   the paginated list and the KPI counts, and always after the `company_id` tenancy boundary.

Backend tests assert the narrowing directly (§14).

## 4. Mobile Filter UX

The approved ECOS responsive pattern is used — no second wide toolbar on mobile:

- **Search** is always inline (`SearchInput`, debounced, with its own clear button).
- **Filters** (Supplier, Warehouse, From, To) live behind a single **Filters** toggle (`SlidersHorizontal`),
  opening a `FilterPanel` that lays the controls out in a responsive grid (`1 col` on mobile,
  `2` at `sm`, `3` at `lg`) with a **Clear** action. Selecting a supplier keeps the panel open and the
  choice visible; no horizontal scrolling is required.
- The receiving **state** (Active / History) remains the prominent top-level Tabs rather than being
  duplicated into the filter sheet — a deliberate primary control, visible on mobile.

## 5. DEV Backend Runtime State (before)

`ecos-dev-app` (PHP-FPM) did **not** have the PO-driven Receiving Center: `ReceivingCenterController`,
`ReceiveAgainstPurchaseOrderAction`, and `ReceiveAgainstPurchaseOrderRequest` were absent, and
`routes/api.php` contained no `receiving/*` routes. Routes were **uncached** (`bootstrap/cache/` held only
`packages.php` + `services.php`; no `routes-*.php`). This matched the prior task's §23 STOP (never deployed).

## 6. DEV Frontend Runtime State (before)

There is no Vite `:5173` dev server; the DEV SPA is a **static bundle** served by `ecos-dev-nginx` from
`/var/www/html/public/app/` (`base: '/app/'`). The served bundle predated the PO-driven Receiving Center
work (and the supplier filter), so it was stale.

## 7. Runtime Refresh Performed

Minimum necessary, DEV only:

- **Backend (`ecos-dev-app`):** `docker cp` of the three receiving source files
  (`ReceivingCenterController`, `ReceiveAgainstPurchaseOrderAction`, `ReceiveAgainstPurchaseOrderRequest`)
  into their module paths, and an **additive** edit of the container's own `routes/api.php` — only the
  `ReceivingCenterController` import and the three `receiving/*` routes were inserted (see §8). `php -l`
  clean on all four files.
- **Frontend (`ecos-dev-nginx`):** `npx vite build` (esbuild/rollup — no typecheck, the established DEV
  method) → `../backend/public/app`, then `docker cp backend/public/app/. ecos-dev-nginx:/var/www/html/public/app`.
  nginx's `index.html` now references the new entry chunk and serves the fresh `receiving-center-*` chunk.

## 8. Route / Cache Safety

The previous route-cache/source-drift incident was explicitly avoided:

- The host working-tree `routes/api.php` carries **large uncommitted cross-workstream drift**
  (`GoodsInwardModeController`, `PaymentProofController`, `WaveEngineConfigurationController`, …). It was
  **not** copied into DEV. Instead the container's own `api.php` was pulled out, and only the receiving
  import + three routes were inserted at stable committed anchors (after `goods-receipts/{goodsReceipt}/post`,
  inside the same authenticated purchasing group) — nothing else added or removed.
- Routes were already **uncached**, so no `route:clear` / `route:cache` was needed and none was run; the
  edit takes effect on the next request with no rebuild step to trigger a cascade.
- **Pre-existing, unrelated condition (documented, not introduced):** `php artisan route:list` in
  `ecos-dev-app` fails because the container's `api.php` (from another workstream) references
  `Modules\Logistics\Distribution\...\DriverReportsController`, whose class is absent. Because
  `Controller::class` is a compile-time string, this does **not** affect request serving — every receiving
  route returns HTTP 401 (registered) rather than 404 (see §9). This drift is not part of this task and was
  left untouched.

## 9. Read-Only DEV Confirmation

HTTP-level, through `ecos-dev-nginx` (`127.0.0.1:8081`), no authentication, no mutation:

- `GET /api/receiving/queue?scope=active` → **401** (registered + auth-protected)
- `GET /api/receiving/purchase-orders/{id}` → **401**
- `POST /api/receiving/purchase-orders/{id}/receive` → **401**
- `GET /api/goods-receipts` (baseline) → **401**;  `GET /api/receiving/does-not-exist` → **404**
  (proves the 401s are real registered routes, not a catch-all).
- `GET /app/` → **200**; entry chunk and `receiving-center-*.js` → **200**; the served receiving chunk
  contains the new keys verbatim (`filters:{…,supplier:'Supplier',allSuppliers:'All suppliers',
  warehouse:'Warehouse',…}`).
- SPA boot (unauthenticated) in the browser: shell renders, **no console errors** → the new bundle is
  healthy (no stale-hash blank screen).

**Limitation (per §9):** a mutation-capable Warehouse user was not available and, per the task, no
credentials were created and no RBAC was altered — so the authenticated in-page walkthrough (rendered
supplier dropdown, live queue rows) was not performed against DEV. The rendered control and its
`supplier_id` wiring are proven by the component tests instead (§14).

## 10. New Receipt Confirmation

The Receiving Center still exposes **no** "New Receipt" / from-scratch Goods Receipt creation. The page has
no such control (asserted by the component test), and the underlying Goods Receipt model/actions and the
`goods-receipts/new` route remain in the system (unlinked from the Receiving Center), per the prior task's §3.
No canonical/historical Goods Receipt infrastructure was deleted.

## 11. PO / Goods Receipt Authority Preservation

Unchanged: `PurchaseOrderStatus::canReceive()`, `CreateGoodsReceiptAction`, `PostGoodsReceiptAction`,
`OverReceiptException`, PO `received_qty` semantics, Goods Receipt idempotency, `goods_inward_mode`, the
Supplier Invoice authority, and the inventory authority. No new receiving logic was written — the closure is
a UI filter plus a runtime refresh.

## 12. Deferred Damage / Rejected Gap

Not implemented, by instruction. `goods_receipt_lines` still has no accepted/rejected/damaged disposition
contract; this remains a **DEFERRED PROCUREMENT / INVENTORY ARCHITECTURE GAP** and is not a blocker for the
normal PO-driven Receiving Center.

## 13. Files Changed

**Frontend (source):**
- `frontend/src/features/receiving-center/pages/receiving-center-page.tsx` — Supplier filter + `EntityToolbar`/`FilterPanel` responsive toolbar (replaces the bespoke inline filter row).
- `frontend/src/features/receiving-center/pages/receiving-center-page.test.tsx` — mocks `useSupplierOptions`; adds three supplier-filter tests (kept the four existing).
- `frontend/src/i18n/locales/en/receiving-center.json` · `…/ar/receiving-center.json` — `page.filters.supplier`, `allSuppliers`, `warehouse` (EN/AR parity).

**Backend (tests only):**
- `backend/tests/Feature/Purchasing/PoDrivenReceivingTest.php` — two new tests: server-side supplier
  narrowing, and company-scoped tenancy isolation. (No backend source changed — the `supplier_id` filter
  already existed in `ReceivingCenterController`.)

**DEV runtime (no source change; deploy only):** receiving source + additive `api.php` routes into
`ecos-dev-app`; rebuilt SPA bundle into `ecos-dev-nginx`.

## 14. Focused Verification

- **Frontend typecheck:** `tsc -p tsconfig.app.json` — **0 errors in the receiving-center feature** (the
  unrelated pre-existing project baseline is untouched).
- **ESLint (touched files):** **0** problems.
- **Frontend tests:** `receiving-center-page.test.tsx` — **7/7 passed** (4 existing + 3 new):
  the advanced filters stay behind the Filters toggle and reveal the Supplier control; selecting a supplier
  sends the canonical `supplier_id`; Clear restores the unfiltered queue.
- **i18n parity:** receiving-center EN **94** = AR **94**, no missing/extra keys; the three new keys present in both.
- **Backend gate (isolated test schema, `RefreshDatabase`):** **OK — 9 tests / 49 assertions**
  (`scripts/test-gate.sh tests/Feature/Purchasing/PoDrivenReceivingTest.php`, run inside
  `ecos-dev-testrunner` under the pinned-DB lock): the 7 existing PO-driven receiving tests plus **2 new** —
  the supplier filter narrows the queue **server-side** (filtering by supplier A returns only A's PO, by B
  only B's, cleared returns both), and the queue is **company-scoped** (another company's PO never appears,
  and a foreign `supplier_id` returns zero rows — no cross-tenant supplier/PO leakage).
- **No DEV business data mutated;** all backend assertions run on the isolated test schema. No full
  Procurement regression / module certification was run, per the task.

## 15. Remaining Procurement Gaps

- **Damage / rejected / accepted disposition** — deferred architecture gap (§12).
- The manual `goods-receipts/new` create page remains routed but unlinked from the Receiving Center (§10),
  by the prior task's decision.
- Pre-existing, unrelated DEV `route:list` drift (`DriverReportsController`) noted in §8 — not this task's.

## 16. Readiness for Supplier Invoice Task

The approved boundary is intact and DEV now reflects it: **PO / Goods Receipt = physical receiving +
inventory authority; Supplier Invoice = commercial / financial document.** Receiving creates no Supplier
Invoice (asserted). The Supplier Invoice enhancement is the next Procurement workstream and was **not**
started.

## 17. Implementation Status

Supplier filter shipped over the existing server-side `supplier_id` using canonical supplier + toolbar
infrastructure; DEV runtime refreshed (backend routes registered/auth-protected, frontend bundle served
with the filter); certified receiving/authority/`goods_inward_mode`/Supplier-Invoice contracts untouched;
damage/rejected disposition deferred; no commit/push/deploy-outside-DEV/business-data mutation.

---

IMPLEMENTATION STATUS:
COMPLETE

DEV RUNTIME PARITY:
RESTORED

FINAL CERTIFICATION:
DEFERRED TO FINAL SYSTEM REVIEW
