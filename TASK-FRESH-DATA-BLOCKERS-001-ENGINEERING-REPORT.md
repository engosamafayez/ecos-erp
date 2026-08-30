# TASK-FRESH-DATA-BLOCKERS-001 — ENGINEERING REPORT

**Date:** 2026-08-19
**Environment:** DEV (`ecos_dev`) — live Vite frontend (`127.0.0.1:5173`) → dev nginx (`8081`) → `ecos-dev-app`. Backend contract tests run in `ecos-dev-testrunner` via the serialized gate (`ecos_dev_test`).
**Scope:** Remediation of the three blockers from TASK-FRESH-DATA-FOUNDATION-001. Audit-first; fix only where a current contract exists; no contract invention; no ADR changes; no speculative VerifyPaymentAction repair; no SQL seeding of business/master data.
**Baseline preserved:** all previously-verified areas (categories, units, suppliers, customers, raw materials, channels, recipe/BOM, costing, add-stock, allow_negative_stock, procurement lifecycle, record_type separation, supplier combobox, order creation, COD, SKU, tenant scope, Google Maps resolution, reservation, awaiting_stock) were **not** modified.

> **Evidence mode:** Browser pane not composited → no PNG screenshots. UI driven through the real React app (real handlers, real form submit → real `/api`). Evidence = route + rendered DOM + real HTTP req/resp + DB rows.

## FINAL STATUS

| Blocker | Status |
|---|---|
| **01 — DEF-PROD-01 (Product unit_id)** | **IMPLEMENTED + UI VERIFIED** |
| **02 — Warehouse → Brand coverage** | **BLOCKED — CONTRACT DECISION REQUIRED** |
| **03 — Payment status + proof** | **BLOCKED — PAYMENT CONTRACT GAP** |

Certification remains **DEFERRED**.

---

## BLOCKER 01 — DEF-PROD-01 · Product `unit_id` — IMPLEMENTED + UI VERIFIED

**1. Root cause.** The finished-product create/update form collected `unit_id` (UnitSelect Controller), the Zod schema required it (`product-form-schema.ts:12`), and `toFormValues` seeded it (line 40) — but `toPayload()` never copied it into the request body. Every UI create sent no `unit_id` → `products.unit_id` NOT NULL violation → HTTP 500. Incomplete fix of the historical "unit_id = NULL" bug (schema patched, mapper not).

**2. Existing contract (reused, not invented).**
- `ProductPayload.unit_id?: string | null` already exists — [`frontend/src/features/products/types/product.ts:163`](frontend/src/features/products/types/product.ts:163).
- Backend `StoreProductRequest` already requires it: `'unit_id' => ['required','uuid','exists:units,id']` — [`.../Products/Presentation/Http/Requests/StoreProductRequest.php:76`](backend/Modules/Inventory/Products/Presentation/Http/Requests/StoreProductRequest.php:76). (The *running app container* still carries a stale `sometimes|nullable` copy from its 2026-08-10 image — the reason the earlier failure was a 500 not a 422 — but the on-disk contract and the testrunner are current.)
- DB constraint unchanged (NOT NULL preserved). Unit made neither optional nor duplicated; no validation bypassed.

**3. Files changed (frontend only — no backend, no routes, no shared files).**
- [`frontend/src/features/products/components/product-form-schema.ts`](frontend/src/features/products/components/product-form-schema.ts) — `toPayload()` now returns `unit_id: values.unit_id`.
- [`frontend/src/features/products/components/product-form-schema.test.ts`](frontend/src/features/products/components/product-form-schema.test.ts) — new vitest.

**4. Tests.**
- **Frontend (new):** 4 vitest cases — `unit_id` is forwarded (A), the exact chosen unit (not guessed), round-trips through `toFormValues → toPayload`, and the rest of the create contract is intact (D). → **1 file, 4 passed.**
- **Backend (existing, run via gate):** `tests/Feature/Inventory/ProductUnitContractTest.php` → **9 tests, 23 assertions, OK** — covers B (valid unit clears validation), C (missing / null / unknown unit → 422 + no product persisted), D (existing product keeps its unit through unrelated edit; partial update omitting unit accepted).
- `tsc -p tsconfig.app.json`: **0 errors in changed files** (repo-wide L10N error baseline is pre-existing and unrelated). `eslint` on changed files: **clean**. Full `vite build` not run to green — `build` is `tsc -b && vite build` and the pre-existing repo-wide tsc baseline blocks it; that baseline is not introduced by this change.

**5. UI route.** `/app/products` → New Product.

**6. UI evidence (Scenario 1).** Created **Honey Jar 500g** through the real form (Beverages / Kilogram / ECOS Main Store / price 150). Product appears in the Products list with correct Brand (ECOS Holding · BRD-000001), Channel (ECOS Main Store), SKU (FG-HONEY-500), and "Recipe Missing" (recipe still attachable). Dialog closed on success.

**7. HTTP evidence.** Captured request body: `unit_id = 01a0180d-d04a-…` (Kilogram) present → **`POST /api/products` → 201 Created**, `"Product created successfully."` (previously 500).

**8. DB evidence.** `products` row `FG-HONEY-500`: `unit → Kilogram` (unit_id persisted), category Beverages, brand ECOS Holding, `regular_price 150.00`; `product_channel_mappings` → ECOS Main Store.

**9. PASS.**

**10. Remaining gap.** The **running app container** carries the stale `StoreProductRequest` (`sometimes|nullable`), so at the raw-HTTP layer a *missing* unit would still 500 there rather than 422. The correct on-disk contract simply needs to reach the running image (rebuild/redeploy, or `docker cp`) — a deployment step, not a code gap. From the real UI, missing unit is already rejected client-side by Zod, and the fix makes the happy path succeed.

---

## BLOCKER 02 — Warehouse → Brand coverage — BLOCKED — CONTRACT DECISION REQUIRED

**1. Root cause.** `warehouse_brand_coverage` is empty and there is **no supported way to populate it**: the table has a Domain model, a migration, and one *reader*, but **no controller, route, request, service, UI, or seeder** — no write/configuration surface of any kind.

**2. Existing contract (what IS defined).**
- Data model + semantics: [`WarehouseBrandCoverage`](backend/Modules/MasterData/Warehouses/Domain/Models/WarehouseBrandCoverage.php) — `fillable = [company_id, warehouse_id, brand_id, is_active]`; migration `TASK-WAREHOUSE-COVERAGE-BRAND-ASSIGNMENT-001`; rule **"NO ROWS = SERVES NO BRANDS; owner is the WAREHOUSE"**.
- Sole consumer: [`BranchAssignmentEngine`](backend/Modules/Operations/Preparation/Application/Services/BranchAssignmentEngine.php) `filterByBrandCoverage()` — with an empty table every candidate is filtered out → "No Warehouse Serves Order Brands", so the order stays Awaiting Warehouse (exactly the observed behavior).
- The full assignment chain is: `branch_coverage_areas` (governorate→branch) **AND** `warehouse_brand_coverage` (warehouse→brand), then `BranchWarehouseResolver` (branch→warehouse, auto via `default_warehouse_id` or first active company warehouse).

**3. Determination against the task decision tree.**
- The **geographic** half, `branch_coverage_areas`, HAS a supported write API (`GET/POST/PUT/DELETE /api/branches/{branch}/coverage`) — configurable (case 6).
- The **brand** half, `warehouse_brand_coverage` — the exact relation the task's target config needs ("Main Warehouse → ECOS Holding brand") — has **no defined write/configuration contract**. Populating it would require authoring, from scratch, an API shape + authorization policy + request validation + a UI surface. That is not "wiring a UI to an existing endpoint" (case 7); it meets the explicit **STOP condition** ("warehouse-brand coverage has no defined contract") and would require inventing the configuration contract.

**4. Files changed.** None (correctly stopped).

**5–8. Evidence.** `SELECT * FROM warehouse_brand_coverage` → 0 rows. `artisan route:list | grep warehouse | grep coverage` → none. No frontend reference to `warehouse_brand_coverage` (the admin "delivery coverage" workspace manages a *different* table — brand→governorate delivery geography). No seeder. Order ORD-00001 remains Awaiting Warehouse for this reason.

**9. BLOCKED.**

**10. Exact owner decision required (recommended pattern in brackets).**
1. **Approve a write/configuration surface** for `warehouse_brand_coverage`. [Recommended: mirror the already-approved `branch_coverage_areas` REST pattern — `GET/POST/DELETE /api/warehouses/{warehouse}/brand-coverage` + a `WarehouseBrandCoverageController` + request.]
2. **Validation:** `brand_id` exists and belongs to the warehouse's company; unique `(warehouse_id, brand_id)`; `is_active` default. [Confirm.]
3. **Authorization:** which permission may configure coverage (e.g. `warehouses.manage` / a coverage-specific permission). [Confirm — no permission exists for this today.]
4. **UI placement:** a "Brand Coverage" tab on the Warehouse detail using enterprise components. [Confirm.]
5. **Auto-seed policy:** should coverage be auto-created when a warehouse is created for a single-brand company, or always explicit? [Confirm — affects whether fresh environments start assignable.]

Once (1)–(5) are decided, implementation is small and mechanical; it is held only because the contract is undefined.

---

## BLOCKER 03 — Payment status + payment proof — BLOCKED — PAYMENT CONTRACT GAP

**Phase A (audit only) — findings.** The payment contract is **incomplete and internally contradictory**:

1. **Two conflicting representations of "paid".**
   - `orders.payment_status` is a **nullable free-form string** (migration `2026_07_11_..._add_payment_status_to_orders_table`) with **no enum, no default, and no writer anywhere in the codebase** — it is orphaned.
   - The actual paid/partial/unpaid determination is **derived from `deposit_amount` vs `total`** — [`EloquentOrderRepository.php:161-174`](backend/Modules/Commerce/Orders/Infrastructure/Repositories/EloquentOrderRepository.php:161) (`paid = deposit_amount >= total`). These two notions are unreconciled.
2. **No "Mark Paid" action/endpoint.** Order payment routes are only `POST /api/orders/{order}/verify-payment` and a `GET .../filter/payment-methods`. Payment is captured implicitly as `deposit_amount` on order **create/edit** (the "Deposit Received" control on the manual order form); there is no post-hoc mark-paid transition.
3. **No payment-proof upload contract.** `orders.payment_proof_path` is a bare string; `verify-payment` accepts a *pre-existing* path (`orders-service.ts:144`), and the order detail only **displays** the proof link — there is no upload endpoint or storage contract feeding the path at the order-payment layer.
4. **`VerifyPaymentAction` carries the flagged defects.** [`VerifyPaymentAction.php`](backend/Modules/Commerce/Orders/Application/Actions/VerifyPaymentAction.php): it does a **direct `$order->update(['status' => …])`** (the forbidden status write that must go through the approved OrderStatusGuard / FulfillmentEngine), it only runs from `awaiting_payment`, it sets `payment_proof_path` but writes **neither `payment_status` nor `deposit_amount`**.
5. **COD "mark paid" has no path.** A COD order is not in `awaiting_payment`, so `verify-payment` 422s; and marking paid means setting `deposit_amount >= total`, for which no action exists.

**Phase B.** Because the contract is incomplete/contradictory, per the task I **STOP** — no implementation, and **VerifyPaymentAction was not touched**.

**Files changed.** None (correctly stopped).

**BLOCKED — PAYMENT CONTRACT GAP.**

**Exact owner decisions required.**
1. **Source of truth for "paid":** the orphaned `payment_status` column, or the derived `deposit_amount >= total`? (Then either retire/backfill the column or define who writes it.)
2. **Is there a dedicated "Mark Paid" action,** or is payment only recorded via `deposit_amount` on order edit? If a Mark-Paid action is wanted: define its endpoint, what amount it sets, its authorization, and that any status change **routes through the approved status guard** (not a direct write).
3. **Payment-proof upload:** define the upload endpoint + storage (media service?) that produces `payment_proof_path`, plus authorization.
4. **VerifyPaymentAction:** confirm its direct status write must be re-routed through the guard (semantic change to a flagged action — deferred pending this decision, not repaired speculatively).
5. **COD paid path:** define how a COD order becomes paid (on delivery / COD collection / deposit).

---

## VALIDATION-AFTER-CHANGES — SCENARIO RESULTS

| Scenario | Result |
|---|---|
| **1 — Product** (create FG through UI) | **PASS** — 201, `unit_id` persisted (Kilogram), product visible, SKU/brand/channel correct |
| **2 — Warehouse Brand Coverage** (Main Warehouse → ECOS Holding brand) | **BLOCKED** — no supported configuration path (Blocker 02) |
| **3 — Order → Preparation prerequisite** | **BLOCKED** — depends on (2) for warehouse assignment; FG manufacturing prerequisite also still contract-blocked. Not claimed. |
| **4 — Payment** (Mark Paid / upload proof) | **BLOCKED** — payment contract gap (Blocker 03); not attempted, VerifyPaymentAction untouched |

## STOP-CONDITION COMPLIANCE
No new business rule invented · no ADR changed · VerifyPaymentAction not modified · no route/`api.php` change · no `git checkout/reset/restore` on any file · no SQL seeding of business/master data · UI verification is from real screen interaction, not code inspection · no certification claimed · gate used serialized (occupancy checked before run). Change set: **2 frontend files** (1 modified, 1 new test). No commits.
