# TASK-PROCUREMENT-MANUAL-REMEDIATION-001 — Engineering Report

**Status:** IMPLEMENTATION COMPLETE / RUNTIME VERIFIED (backend + browser smoke A/B/C passed)
**Date:** 2026-08-19
**Scope:** Procurement operational UI/workflow and its integration with Inventory/Preparation. Finance D-A1/D-A2, AccountsPayableService, EventPostingCatalog, V-3/V-5, Logistics, Preparation implementation and Orders lifecycle were treated as **frozen** and not touched.

---

## 1. Method

The manual finding was *"the UI did not change, the same problems are still there."* That is a behavioural claim, so the work began with an actual audit of the **current** workspace (not the reports about it), across three parallel tracks:

1. **Frontend audit** — every routed procurement page, the UI infrastructure it uses, the endpoints it calls, and its company/channel scoping.
2. **Backend audit** — routes, tenant isolation on the five list endpoints, the demand→procurement path, supplier selection, and the Goods-Receipt→Inventory chain.
3. **Prior-contract extraction** — what is already approved/certified (so nothing is redesigned) and what is an explicitly documented gap.

The audit reclassified the six findings into **three real, in-scope defects** (fixed here), **two areas governed by certified contracts** (verified, not touched), and **three genuine gaps with no approved contract** (documented, deliberately *not* invented — per the task's own instruction for PR-02/PR-06).

---

## 2. Current-state audit (what actually ships today)

### Routing / navigation — already migrated
- The live module rail (`src/config/module-navigation.ts`) already points at the new `/purchasing/*` workspace (Hub, Suppliers, Material Requests, Purchases, Supplier Invoices, Receiving Center, Supplier Returns).
- Legacy routes redirect: `/purchase-orders/*` → `/purchasing/purchases`; `/goods-receipts` (list) → `/purchasing/receiving`.
- `src/config/navigation.ts` (which still names "Purchase Orders"/"Goods Receipts") is **dead config — imported nowhere**; it does not affect the rendered UI. So the "UI didn't change" complaint is about **behaviour/content**, not navigation.

### The two behavioural defects a manual tester would actually hit
1. **Material Requests and Purchases showed the *same* list.** They are one table split by `record_type`, but the backend **ignored `record_type` on both read and write** — the create action dropped the field (every row persisted as the column default `material_request`) and the list query never filtered on it. The frontend was sending `record_type` correctly the whole time; the backend threw it away. Net effect: the "Purchases" screen could never show what it created, and both screens were indistinguishable.
2. **Supplier selection was a raw UUID text box.** Known suppliers for the item were already fetched and displayed (`alternative_suppliers` from the procurement panel), but as read-only text — the buyer had to hand-copy a UUID into a plain input. The single biggest UX defect in the module.

### The isolation defect
3. **`PurchaseMaterial` had no tenant isolation.** It was the only inbound aggregate (vs. Supplier / GoodsReceipt / SupplierInvoice) **without** a tenant global scope. The list filtered by company only when the client happened to pass `?company_id=`, and the create path took ownership straight from the (nullable, spoofable) client payload. Any `purchasing.materials.view` holder could read every company's requests — a direct violation of the COMPANY ISOLATION contract ("No frontend-only filtering. Company A ≠ Company B").

---

## 3. Findings → resolution

| # | Finding | Verdict | Action |
|---|---------|---------|--------|
| PR-01 | Demand → Procurement | **Documented gap** | The Operations wave engine (`WaveMissingMaterial`, `MissingMaterialsUpdated`) is **not consumed** by Purchasing; the only demand signal is the per-product `DemandAnalysisService`. No end-to-end demand→procurement conversion contract is certified (gated on Product Decision **D8**). Not invented. See §6. |
| PR-02 | Supplier selection | **Partly fixed / partly gap** | The *ranking/suggestion algorithm* is undefined (D8) — not invented. The *UX defect* (suggestions not selectable, raw-UUID entry) **is fixed**: suggestions are now click-to-apply and a real supplier picker replaces the UUID box. |
| PR-03 | Purchase Materials workflow | **Fixed (2 defects)** | (a) `record_type` is now persisted on create and honoured as a list filter, so Material Requests and Purchases are distinct workspaces again. (b) **The `under_review → waiting_supplier_selection` transition was never wired** — no code path wrote that state (the enum's `nextWorkflowState()` was defined but unused), so supplier selection was unreachable and Approve failed on an `under_review` request even though the drawer offers the button and Reject already accepts both states. Approve now advances one workflow step at a time via `nextWorkflowState()`. (`CAST … AS BIGINT`→`UNSIGNED` MySQL bug was already fixed in the working tree; left as-is.) |
| PR-04 | Goods Receipt → Inventory | **Certified — verified, untouched** | `PostGoodsReceiptAction`→`ReceiveStockAction`→`CreateReceiptLayersAction` writes the ledger with correct warehouse/material/qty/landed-cost/FIFO under one locked transaction, gated by `GoodsInwardAuthority`. Confirmed healthy; not modified. |
| PR-05 | Supplier Invoice UI→backend | **Certified path — verified** | The UI calls the correct `/supplier-invoices` + `/validate` `/post` `/cancel` endpoints. Financial posting (GRNI/PPV/AP) is a **Finance-owned, V-3-blocked** integration — out of scope, untouched. |
| PR-06 | Supplier Returns | **Certified — verified, untouched** | Return valuation (FIFO, receipt-anchored) is certified & deployed; no payable effect in V1 by decision (SR-3). The `supplier_returns.company_id` create-path gap remains open and is owned by the certified-return follow-up — not reopened here. |
| ISO | Company isolation | **Fixed (PurchaseMaterials)** | Fail-closed tenant global scope + server-derived ownership on create. The **cross-tenant inbound *posting*** gap (B-2/D-INB-01, GoodsReceipt) is a separate owned task and was not touched. |

---

## 4. Changes implemented

### Backend (verified via feature tests)

**A. PurchaseMaterial tenant isolation (fail-closed).**
- `…/PurchaseMaterials/Domain/Models/PurchaseMaterial.php` — added the certified `tenant` global scope (identical to Supplier/GoodsReceipt/SupplierInvoice): console/queue/seeder actors unfiltered, `is_system` unrestricted, a **null company closes the query** (`1 = 0`), otherwise `where company_id`.
- `…/Application/Actions/CreatePurchaseMaterialAction.php` — `company_id` is now **resolved server-side from the initiating (tenant-scoped) warehouse**, falling back to the actor's company, never trusted from the payload. Keeps write and read in agreement (RC-6 doctrine).
- `…/Presentation/Http/Requests/StorePurchaseMaterialRequest.php` — a restricted actor may not raise a request against another company's warehouse (`owns(warehouse.company_id)`), closing the write-side spoof edge.
- `…/Infrastructure/Repositories/EloquentPurchaseMaterialRepository.php` — `nextRequestNumber()` now lifts the tenant scope (`withoutGlobalScope('tenant')`); numbering is **global** across tenants (unique index on `request_number` alone) and must not restart per company.
- `…/Infrastructure/Database/Migrations/2026_08_19_100000_backfill_company_id_on_purchase_materials.php` — backfills `company_id` from the warehouse for pre-existing null rows so the new fail-closed scope hides nothing legitimately created (mirrors the GoodsReceipt B-2 backfill precedent).

**B. record_type honoured end-to-end.**
- `CreatePurchaseMaterialAction.php` — persists `record_type` and `source_type` from the DTO.
- `PurchaseMaterialController@index` — passes `record_type` into the filter set.
- `EloquentPurchaseMaterialRepository::paginate` — filters by `record_type` when provided.

**D. Approval workflow reaches supplier selection.**
- `ApprovePurchaseMaterialAction.php` — Approve now advances one step along the enum's own `nextWorkflowState()`: `under_review → waiting_supplier_selection`, then `waiting_supplier_selection → approved`. The intermediate hop does not stamp `approved_at`; only the terminal hop does. Guarded on the two in-review states (mirroring `RejectAction`). Nothing new is invented — this wires an existing, half-implemented transition that the drawer UI already exposes. Found while seeding the browser smoke (the target state was otherwise unreachable through the app).

### Frontend (type-check + lint + bundle clean)

**C. Supplier selection UX (PR-02).** `…/purchase-materials/components/purchase-material-drawer.tsx`:
- Known-supplier suggestions (`alternative_suppliers`) are now **clickable** — selecting one fills the supplier and pre-populates its last-known price and lead time.
- The raw-UUID `<input>` is replaced with the enterprise `Combobox` (`@/components/crud`, the ECOS overlay standard already used by the Supplier Invoice editor), fed by the tenant-scoped `useSupplierOptions()` list. No new component or ranking logic introduced.
- New i18n keys added to `en`/`ar` `purchase-materials.json` (`supplier`, `supplierPickerPlaceholder`, `supplierSearchPlaceholder`, `supplierEmpty`; `refKnownSuppliers` reworded to "click to select").

No frontend change was needed for `record_type` — the pages already send it; the backend now respects it.

---

## 5. Verification

### Backend — runtime verified
Run through the mandatory test gate inside `ecos-dev-testrunner` (MySQL `ecos_dev_test`):

```
scripts/test-gate.sh \
  tests/Feature/Purchasing/PurchaseMaterialTenantIsolationTest.php \
  tests/Feature/Purchasing/PurchaseMaterialRecordTypeFilterTest.php \
  tests/Feature/Purchasing/PurchaseMaterialApprovalWorkflowTest.php \
  tests/Feature/Purchasing/PurchaseMaterialNumberGenerationTest.php
→ OK (20 tests, 51 assertions)
```

New tests:
- `PurchaseMaterialTenantIsolationTest` (7): own-company visible / foreign not; cross-company show → 404; companyless non-privileged sees nothing (fail-closed); `is_system` retains cross-company visibility; create stamps company from the warehouse ignoring a spoofed payload; restricted actor cannot raise against a foreign warehouse (422); unauthenticated/console execution stays unscoped.
- `PurchaseMaterialRecordTypeFilterTest` (2): create persists the requested `record_type`; the list is filtered by `record_type` (purchase vs material_request no longer overlap).
- `PurchaseMaterialApprovalWorkflowTest` (1): Approve advances `under_review → waiting_supplier_selection → approved`, stamping `approved_at` only on the terminal hop.
- Existing `PurchaseMaterialNumberGenerationTest` re-run green — global numbering survives the new scope.

Deployed to `ecos-dev-app` and `php artisan migrate --force` applied the backfill migration cleanly (155 ms). `php -l` clean on all changed files.

### Frontend — static verification
- `npx tsc -p tsconfig.app.json --noEmit`: **zero** errors in the changed files. (The repo carries a large pre-existing type-debt baseline — EPIC-L10N-001 — in unrelated modules; no new debt was added — ratchet respected.)
- `npx eslint` on the drawer: clean.
- `vite build`: bundle graph compiles.

### UI (browser) — performed, PASS
Seeded one minimal Purchase Material through the real application path (see §9) and ran the smoke against the live app (host Vite dev server on `:5173` serving the changed source, proxying `/api` → dev backend on `:8081`). Two restricted single-company users were used so tenant scope is genuinely exercised (the super-admin bypasses it by design).

**A — record_type respected (PASS).** As Buyer A (Company A): the **Purchases** surface listed only `PM-00001` (a `purchase`, status *Awaiting Supplier*); the **Material Requests** surface listed only `PM-00002` (a `material_request`, *Draft*). Neither record leaked into the other list. Confirmed at the API too (`?record_type=purchase` → PM-00001 only; `?record_type=material_request` → PM-00002 only).

**B — supplier picker (PASS, one sub-item is a data gap).** Opened PM-00001 → Supplier tab:
- Supplier field is the enterprise **Combobox** (placeholder "Select a supplier…"), **no raw UUID input present** — PASS.
- Known-supplier suggestion rendered under "KNOWN SUPPLIERS — CLICK TO SELECT": *Smoke Supplier Co · 12.50* — PASS.
- Clicking the suggestion applied the supplier (Combobox showed "SUP-5963 – Smoke Supplier Co") and populated **Agreed Price = 12.5** — PASS. Confirm persisted end-to-end via `POST …/lines/{id}/select-supplier` (line `supplier_id` + `agreed_price=12.5` verified via API) — PASS.
- **Lead time populated: N/A — data gap.** The suggestion source (`DemandAnalysisService::alternativeSuppliers`) hard-codes `lead_time_days = null` ("requires supplier lead-time table"), so there is no lead time to apply. The frontend applies lead time when the suggestion carries it (identical code path to price); the backend provides none. Classified as a **backend/data-contract gap**, out of scope (PR-02 supplier enrichment is undefined / D8-gated) and **not patched**.

**C — tenant isolation (PASS).** Logged in as Buyer B (Company B / AxieFood): the Purchases list showed the empty state ("No purchases yet") and `PM-00001` was **not present**. Corroborated at the API: Buyer B's list returns 0 items and a direct `GET /purchase-materials/{A's id}` returns **404**. Scoping was not weakened to make the smoke pass.

---

## 6. Remaining gaps (documented, deliberately not built)

1. **PR-01 — no demand→procurement bridge.** The Operations wave "missing material" engine is unconsumed by Purchasing; there is no automated shortage→request feed, and no certified conversion contract (gated on Product Decision **D8**). A Demand/Suggested-Purchases surface is a product decision, not a code fix.
2. **PR-02 — no supplier-suggestion contract.** There is no supplier↔product↔company catalog and no approved ranking rule; the existing suggestion is goods-receipt history only (and not company-scoped). The UX is now usable, but a *ranking/auto-suggest* algorithm must not be invented ahead of D8. **Sub-item:** the suggestion source returns `lead_time_days = null` unconditionally (no supplier lead-time table), so the picker cannot pre-fill lead time from a suggestion (price is available and works). Building a supplier lead-time source is the same D8-gated enrichment; not done here.
3. **PR-03 — PurchaseMaterials never emits a Purchase Order.** The modern request flow and the legacy PO→GR→inventory chain remain two disconnected halves; goods still reach inventory only through a legacy PO. Bridging them is an architecture decision, explicitly out of scope for a remediation task.
4. **Receiving Center KPI counts** are computed from the current page only (misleading past page 1). A correct fix needs a `goods-receipts/stats` endpoint (new additive backend) — noted, not built here.
5. **Supplier Returns list is fail-open** on a null `user.company_id` and scopes via the warehouse's company rather than the return's own `company_id`. This is tied to the **open, owned** `supplier_returns.company_id` create-path gap from the certified return task; not reopened here.
6. **Command palette** still offers a "Purchase Orders" entry that redirects to `/purchasing/purchases` — functional but stale label; minor cleanup.

---

## 7. Frozen areas confirmed untouched

Finance D-A1/D-A2, `AccountsPayableService`, `EventPostingCatalog`, `AccountRoleResolver`/roles, V-3 provisioning, V-5 anchor, `GoodsInwardAuthority`/`GoodsInwardMode`, the certified inbound chain (`ReceiveStockAction`/`CreateReceiptLayersAction`/`InboundPostingGuard`/`PostGoodsReceiptAction`), the certified Supplier-Return changeset, Logistics, Preparation implementation, and Orders lifecycle — none modified.

## 8. Files changed

Backend (7):
- `Modules/Purchasing/PurchaseMaterials/Domain/Models/PurchaseMaterial.php`
- `Modules/Purchasing/PurchaseMaterials/Application/Actions/CreatePurchaseMaterialAction.php`
- `Modules/Purchasing/PurchaseMaterials/Application/Actions/ApprovePurchaseMaterialAction.php`
- `Modules/Purchasing/PurchaseMaterials/Presentation/Http/Requests/StorePurchaseMaterialRequest.php`
- `Modules/Purchasing/PurchaseMaterials/Presentation/Http/Controllers/PurchaseMaterialController.php`
- `Modules/Purchasing/PurchaseMaterials/Infrastructure/Repositories/EloquentPurchaseMaterialRepository.php`
- `Modules/Purchasing/PurchaseMaterials/Infrastructure/Database/Migrations/2026_08_19_100000_backfill_company_id_on_purchase_materials.php` (new)

Tests (3, new):
- `tests/Feature/Purchasing/PurchaseMaterialTenantIsolationTest.php`
- `tests/Feature/Purchasing/PurchaseMaterialRecordTypeFilterTest.php`
- `tests/Feature/Purchasing/PurchaseMaterialApprovalWorkflowTest.php`

Frontend (3):
- `src/features/purchase-materials/components/purchase-material-drawer.tsx`
- `src/i18n/locales/en/purchase-materials.json`
- `src/i18n/locales/ar/purchase-materials.json`

## 9. Browser-smoke seed data (dev `ecos_dev` only — NOT production)

Created for the smoke, in the existing `ECOS Holding 20` (Company A) and `AxieFood` (Company B) tenants, reusing an existing warehouse (`Smoke WH A`) and product (`عسل الصال`):
- Users: `smoke.a@ecos.local` (Company A, roles: `purchasing.materials.view/create/submit/approve/select_supplier`) and `smoke.b@ecos.local` (Company B, `purchasing.materials.view`) — password `Smoke@123456` for both. Roles `smoke-buyer-a`, `smoke-buyer-b`.
- `Smoke Supplier Co` (Company A) + one approved Purchase Order + one posted Goods Receipt + PO/GR lines at `unit_price 12.50` — the goods-receipt history that makes the per-item supplier suggestion appear.
- `PM-00001` (record_type `purchase`, advanced to `waiting_supplier_selection`, supplier selected) and `PM-00002` (record_type `material_request`).

The Purchase Materials themselves were created through the **real HTTP API** (create → submit → approve), not direct inserts; only the supporting master/history rows were seeded (via factories in the test-runner and explicit inserts for the GR/PO lines, because `ecos-dev-app` runs without `fakerphp`). **Retained** (not removed) so the change can be re-verified; it is inert dev data and can be deleted by removing the two `smoke.*` users, the `Smoke *` supplier/warehouse-scoped rows, and `PM-00001/PM-00002`. No production stack (`ecos-app` / `ecos_erp`) was touched.

**STATUS: IMPLEMENTATION COMPLETE / RUNTIME VERIFIED (backend feature tests + browser smoke A/B/C). Frontend UI verified in-browser. Never CERTIFIED — certification deferred to global Go-Live.**
