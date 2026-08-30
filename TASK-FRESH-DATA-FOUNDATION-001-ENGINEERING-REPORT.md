# TASK-FRESH-DATA-FOUNDATION-001 — ENGINEERING REPORT

**Date:** 2026-08-19
**Environment:** DEV (`ecos_dev`) — live Vite frontend (`127.0.0.1:5173`) proxying `/api` → dev nginx (`127.0.0.1:8081`) → `ecos-dev-app` → `ecos_dev`
**Method:** Real application UI + real HTTP application flow. Every screen was opened; data was created through the app's real forms/endpoints; results verified against the backend DB. **No SQL seeding of business data.**
**Certification:** NOT claimed. Statuses below are `UI VERIFIED` / `RUNTIME VERIFIED` / `IMPLEMENTED` / `BLOCKED`. Final acceptance is deferred to the owner.

> **Interaction note:** The in-app Browser pane was not composited in this session, so PNG screenshots could not be captured and mouse clicks were unreliable. All UI interaction was driven through the **real React app** (URL navigation, the components' own click/submit handlers via the accessibility tree, and real form submits → real `/api`). Evidence is therefore captured as **route + rendered DOM text + real API request/response + resulting DB rows** rather than PNGs, per the agreed evidence mode.

---

## 1. Fresh Environment Proof

- Preceding reset (TASK-DEV-DATA-RESET-001) left DEV operationally empty: 118 → 61 non-empty tables, 0 orphans across 723 FK constraints, admin + ECOS Holding 20 org preserved.
- Confirmed live at start of this task via the UI:
  - `/app/categories` → "0 categories — No categories yet"
  - `/app/raw-materials` → Total On Hand 0 / Reserved 0 / Available 0
  - `/app/orders` → "0 orders"; `/app/purchasing/material-requests` → "No material requests"
- Login: real UI flow `POST /api/auth/login` (admin@ecos.local) → 200, `ecos_token` stored, redirected to `/app/dashboard`. Header context: **ECOS Holding 20 / ECOS brand / Main Warehouse / Cairo**.
- Document numbering restarted cleanly (post-reset): first order = **ORD-00001**, first procurement doc = **PM-00001**, wave = **PREP-202608-000001**.

## 2. Master Data Created (via real UI)

| Entity | Created | Route | Evidence |
|---|---|---|---|
| Categories | Beverages (BEV, product), Ingredients (ING, material) | `/app/categories` | `POST /api/categories` 201; DB `category_scope` product/material; **no company_id (global)** |
| Units | Pieces (PCS), Kilogram (KG), Liter (L) | `/app/inventory/master-data/units` | 3 rows |
| Suppliers | Nile Foods Supply (SUP-001), Delta Packaging Co (SUP-002) | `/app/suppliers` | `POST /api/suppliers` 201; tenant-scoped `company_id` = ECOS Holding 20 |
| Customers | Cairo Retail Store (CUST-001) | `/app/customers` | phone-first dedupe flow; brand link via **`customer_brands` pivot** (ECOS Holding); `customers.brand_id` NULL is by-design |
| Raw Materials | Raw Honey (RM-HONEY-01, raw_material, kg), Glass Jar 250ml (PKG-JAR-250, packaging_material, pcs) | `/app/raw-materials` | products table, `product_type` discriminator, unit+category bound |
| Finished Product | Honey Jar 250g (FG-HONEY-250, Beverages, Pieces, price 100) | `/app/products` | **UI create FAILS — DEF-PROD-01** (see §11). Seeded via real `POST /api/products` w/ `unit_id` → 201 |
| Brand | Surviving ECOS Holding (BRD-000001) | — | reused per task |
| Channels | ECOS Main Store, ECOS Wholesale (survived reset) | — | product mapped to ECOS Main Store (`product_channel_mappings`) |
| Recipe / BOM | Honey Jar = Raw Honey 0.25 kg + Glass Jar 1 pc, yield 1 | `/app/inventory/recipes/new` | `bills_of_materials` + 2 `bill_of_material_lines`; FG cost rolled up to **14.50** = 0.25×50 + 1×2 |

Final counts: categories 2, units 3, suppliers 2, customers 1, products 3, BOMs 1.

## 3. Inventory Created (Phase 2)

| Check | Result | Evidence |
|---|---|---|
| Raw-material stock | **UI VERIFIED** | Add Stock wizard: Raw Honey 100 kg @ 50, Glass Jar 500 pcs @ 2. Ledger `adjustment_in` (0→100). |
| UI ↔ backend parity | **UI VERIFIED** | List shows Raw Honey In Stock, On Hand 100 / Reserved 0 / Available 100, Value EGP 5,000; totals match `inventory_items` exactly |
| Available quantity | **UI VERIFIED** | displayed = on_hand − reserved |
| allow_negative_stock | **UI VERIFIED** | row toggle → DB `allow_negative_stock` 0→1→0 (both directions) |
| Recipe-driven cost/availability | **UI VERIFIED** | FG cost 14.50 (BOM rollup); 🟢 "Recipe Available" |
| Finished-good stock | **IMPLEMENTED (by design)** | No manual add-stock for FG — produced via manufacturing/preparation |
| Reserved quantity | see §5 | exercised via order reservation |

## 4. Procurement Validation (Phase 3)

| Step | Result | Evidence |
|---|---|---|
| 1. Material Request | **UI VERIFIED** | PM-00001, `record_type=material_request`, `status=draft`, tenant-scoped |
| 2. Purchase Material | **RUNTIME VERIFIED** | PM-00002, `record_type=purchase` (via real `POST /api/purchase-materials` — UI "New Purchase" source-selector wizard did not advance under headless clicks; code path exists) |
| 3. record_type separation | **UI VERIFIED** | distinct in DB + lists |
| 4. Submit | **UI VERIFIED** | draft → under_review, `submitted_at` set |
| 5. Approve | **UI VERIFIED** | under_review → **waiting_supplier_selection** |
| 6. waiting_supplier_selection | **UI VERIFIED** | DB-confirmed |
| 7. Supplier picker | **UI VERIFIED** | Supplier tab; RBAC note "Warehouse managers cannot see this tab" |
| 8. Select supplier (enterprise Combobox) | **UI VERIFIED** | both suppliers listed; SUP-001 selected; line `supplier_selected_at` set |
| 9. Agreed price populated | **UI VERIFIED** | `agreed_price=48.00` saved. Does NOT auto-derive (no supplier↔product price agreement exists) — manual buyer entry |
| 10. Purchases list ⊉ Material Requests | **UI VERIFIED** | shows PM-00002, excludes PM-00001 |
| 11. Material Requests list ⊉ Purchases | **UI VERIFIED** | shows PM-00001, excludes PM-00002 |
| Lead time | **N/A → PR-02/D8 gap** | `lead_time_days=null`; reported as the existing data-contract gap (not invented) |

## 5. Orders Validation (Phase 4)

**Scenario B (COD) — ORD-00001, created end-to-end via the real 6-step order UI:**

| Check | Result | Evidence |
|---|---|---|
| Order created | **UI VERIFIED** | ORD-00001 (numbering restarted) |
| SKU | **UI VERIFIED** | order line `FG-HONEY-250`, qty 1, unit_price 100 |
| Payment method | **UI VERIFIED** | COD → persisted in `payment_method_manual='cod'` |
| Payment status | **UI VERIFIED** | Unpaid (COD, pre-delivery) |
| GPS / Google Maps URL resolution | **UI VERIFIED** | "Import Location" parsed coords → **`google_maps_lat=30.0444`, `google_maps_lng=31.2357`**, `location_source=google_maps`; exposed in UI ("Copy Coords"). Persisted on the order. |
| Inventory reservation | **UI/RUNTIME VERIFIED** | after warehouse assignment → **`awaiting_stock`**, `reserved_qty=0` (FG has 0 on-hand → FG shortage only; correct ADR-027 §2/§10; no over-reserve) |
| Order status | **UI VERIFIED** | in_progress → awaiting_stock |
| Confirmation gate | **UI VERIFIED** | "Confirm Customer" → Order Confirmation dialog (Phone Call / Confirmed) → `confirmation_result=confirmed`, `customer_confirmed_at` set. Order correctly **stays awaiting_stock** (customer confirmation ≠ stock availability — correct interlock; order-level `confirmed_at` not stamped without stock) |
| Tenant/company scope | **UI VERIFIED** | Company selector shows only ECOS Holding 20; order `company_id` tenant-scoped |
| Warehouse assignment | **RUNTIME VERIFIED** | correctly **Awaiting Warehouse** (empty `warehouse_brand_coverage` = certified "serves no brands"); `POST /api/orders/{id}/override-warehouse` (real endpoint) → 200 |

**Scenarios A (unpaid + proof required), C (paid), D (proof attached): PARTIAL / BLOCKED** — see §13. The order-detail surface exposes customer confirmation but no direct mark-paid / proof-upload control was reachable on the COD order detail (payment appears governed by the confirmation/verify workflow). VerifyPaymentAction was **not** modified.

## 6. Preparation Validation (Phase 5) — PARTIAL

| Check | Result | Evidence |
|---|---|---|
| Wave workspace UI | **UI VERIFIED** | `/app/operations/preparation/wave-workspace` renders (wave picker, Product Demand / Missing Materials / Wave Orders tabs, Wave History, Wave Engine) |
| Wave exists | **RUNTIME VERIFIED** | scheduler-created **PREP-202608-000001** (status `collecting`), tenant-scoped |
| Order → wave eligibility interlock | **RUNTIME VERIFIED** | customer-confirmed awaiting_stock order NOT auto-added; correct — no FG stock to prepare |
| ADR-027 §18 floor / demand / prepared / missing | **BLOCKED** | requires FG manufacturing to produce prepared_qty and drive product-demand/missing-materials with real numbers. FG manufacturing was not configured (see §13). Schema present: `wave_product_demand` (required_qty/prepared_qty/remaining_qty), `wave_missing_materials`. ADR-027 was **not** changed. |

## 7. Logistics Validation (Phase 6) — BLOCKED

| Check | Result | Evidence |
|---|---|---|
| Loading/fulfillment endpoints | **IMPLEMENTED** | `GET /api/loading/sessions` 200, `GET /api/fulfillments` 200 |
| Session → Vehicle → Allocation → Delivery → Reconciliation | **BLOCKED** | requires a prepared+loaded order (upstream preparation/manufacturing chain, blocked) |
| `loaded − delivered − returned = variance` (10/8/2/0 test) | **BLOCKED** | no loaded trip could be produced without the upstream chain |
| GPS on order | **UI VERIFIED (order-level)** | ORD-00001 carries canonical `lat/lng` (30.0444/31.2357). The separate "order has no canonical lat/lng" gap appears **addressed for manually-created orders**. |

## 8. UI Routes Exercised (screens actually opened)

`/app/login`, `/app/dashboard`, `/app/categories`, `/app/inventory/master-data/units`, `/app/suppliers`, `/app/customers`, `/app/raw-materials`, `/app/products`, `/app/inventory/recipes/new`, `/app/purchasing/material-requests`, `/app/purchasing/purchases`, `/app/orders`, `/app/orders/new`, `/app/orders/{id}`, `/app/warehouses`, `/app/operations/preparation/wave-workspace`. (PNG screenshots unavailable — pane not composited; DOM/route/API/DB evidence captured instead.)

## 9. Backend / API Evidence (representative)

- `POST /api/categories` 201 · `POST /api/suppliers` 201 · `POST /api/customers` 201 (phone dedupe) · Add Stock → `adjustment_in` ledger · `POST /api/purchase-materials` 201 (both record types) · `.../submit` + `.../approve` transitions · line `select-supplier` persisted agreed price · `POST /api/orders` → ORD-00001 · `POST /api/orders/{id}/override-warehouse` 200 · customer confirmation persisted.
- `POST /api/products` **500** (`SQLSTATE[23000] Column 'unit_id' cannot be null`) from the UI form; **201** when `unit_id` is included → proves backend correct, frontend payload defective.

## 10. Known Gaps (pre-existing / data-contract)

1. **PR-02/D8** — supplier lead time has no data contract; `lead_time_days` stays N/A. (Confirmed, not invented.)
2. **Warehouse coverage not configured** — `warehouse_brand_coverage` empty + `branch_coverage_areas` empty ⇒ automatic warehouse assignment returns none ("serves no brands", certified). This is missing master-data setup (Phase 1 item 10); no clean create-API/UI path was found for `warehouse_brand_coverage` (not in warehouse Store/Update request; owner is the warehouse per migration TASK-WAREHOUSE-COVERAGE-BRAND-ASSIGNMENT-001).
3. **FG stock only via manufacturing** — no manual add-stock for finished goods; blocks reservation-to-ready and the whole preparation→loading chain without a manufacturing cycle.

## 11. Regression / Defect Findings

- **DEF-PROD-01 (CONFIRMED, High)** — Finished-product create/update UI omits `unit_id`. [`frontend/src/features/products/components/product-form-schema.ts:58`](frontend/src/features/products/components/product-form-schema.ts:58) `toPayload()` returns `category_id` but **no `unit_id`**, though the form collects it (UnitSelect), the Zod schema requires it (line 12), and defaults seed it (line 40). Every UI product create → `POST /api/products` without `unit_id` → **500** (NOT NULL violation). This is an **incomplete fix of the historical "unit_id = NULL" bug** (schema patched, payload mapper not). Raw-material form is unaffected. *Secondary:* backend `StoreProductRequest` does not `required` `unit_id`, so a missing value reaches the DB as a 500 instead of a clean 422.
- **DEF-PROC-01 (Minor)** — Purchases page KPI "Awaiting Supplier" count includes `material_request` records (counts by status via the stats endpoint, ignoring `record_type`) while the list correctly excludes them ([`purchases-page.tsx:240`](frontend/src/features/purchase-materials/pages/purchases-page.tsx:240)). Count/list use different filters.
- **Observation** — material-request approve→waiting_supplier_selection transition does not stamp `approved_at` (`approved_at` NULL though status advanced).
- **Interaction-unverified (needs real click)** — "New Purchase" source-selector wizard did not open under headless synthetic events (`onClick → setWizardOpen(true)` path exists; likely a headless-only limitation, not confirmed as a product defect).

## 12. PASS / FAIL Matrix

| Phase / Item | Status |
|---|---|
| Fresh environment proof | ✅ VERIFIED |
| Categories / Units / Suppliers / Customers / Raw Materials | ✅ UI VERIFIED |
| Finished Product (UI create) | ❌ FAIL — DEF-PROD-01 |
| Finished Product (backend/API) | ✅ RUNTIME VERIFIED |
| Channels / Recipe-BOM | ✅ UI VERIFIED |
| Inventory: raw stock / available / allow-negative / recipe cost | ✅ UI VERIFIED |
| Procurement steps 1,3,4,5,6,7,8,9,10,11 | ✅ UI VERIFIED |
| Procurement step 2 (create purchase) | ✅ RUNTIME VERIFIED (API; UI wizard unverified) |
| Procurement lead time | ⚠️ N/A (PR-02/D8) |
| Orders — Scenario B (COD): SKU, payment method, GPS, reservation, status, confirmation gate, tenant | ✅ UI VERIFIED |
| Orders — Scenarios A / C / D (proof-required / paid / proof-attached) | ⚠️ PARTIAL / BLOCKED |
| Preparation — workspace + wave existence + eligibility interlock | ✅ VERIFIED |
| Preparation — ADR-027 §18 with real prepared/missing numbers | 🚧 BLOCKED (FG manufacturing) |
| Logistics — endpoints present | ✅ IMPLEMENTED |
| Logistics — load/deliver/reconcile (10/8/2/0), replay, tenant | 🚧 BLOCKED (upstream chain) |
| GPS canonical lat/lng on order | ✅ UI VERIFIED (addressed for manual orders) |

## 13. Certification Blockers

1. **DEF-PROD-01** — finished-product creation is broken through the real UI. Master-data foundation cannot be built through the product screen until `toPayload()` forwards `unit_id` (and `StoreProductRequest` should `required` it to return 422 not 500). *Fix intentionally not applied — validation task.*
2. **Warehouse coverage setup missing** — no `warehouse_brand_coverage` / `branch_coverage_areas`; automatic warehouse assignment cannot occur, so orders sit in Awaiting Warehouse. A supported create path (UI or API) for warehouse→brand coverage needs to be identified/exercised.
3. **FG manufacturing not configured** — finished-good stock is producible only through a manufacturing/preparation cycle; without it, orders remain `awaiting_stock` and the Preparation ADR-027 §18 numbers and the entire Logistics load/deliver/reconcile chain cannot be exercised end-to-end.
4. **Payment scenarios A/C/D** — mark-paid / payment-proof-upload controls were not reachable from the COD order detail in this pass; the paid/proof/verify path (incl. the confirmation payment gate) needs a supported UI entry point to validate without touching VerifyPaymentAction.

**No architectural changes, no contract inventions, no ADR-027 / VerifyPaymentAction modifications were made. No commits. Certification remains deferred to owner manual acceptance.**
