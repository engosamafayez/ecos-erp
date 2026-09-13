# OPS-01 CLOSURE 03 — INVENTORY REPORTING / RAW MATERIALS / IN-SHIPPING / SORTATION

## STATUS: **SOURCE COMPLETE WITH REVIEW GAPS**

---

## 0. OWNERSHIP / SAFETY

Workspace `E:\ECOS\ECOS-NEXT-OPS`, branch `feature/operations-v1.1`, HEAD confirmed `c6a764d7` before starting; working tree clean; no peer session claimed this worktree. No new heavy runtime cycle started (the prior isolated migration was already completed and torn down before this task began). `E:\ECOS\ECOS-V1-STAGING` not touched.

---

## 1. RECONCILIATION — CURRENT INVENTORY AUTHORITIES (§3)

- **Raw Material identity**: `Product` rows discriminated by `product_type` ∈ `{finished_good, raw_material, packaging_material}` (`Product::TYPES`) — no separate Raw Material model. `InventoryClass` mirrors this 1:1.
- **Warehouse quantity / reservation**: `Modules\Inventory\InventoryItems\InventoryItem` (`on_hand_qty`, `reserved_qty`), written by the 7 canonical actions already mapped in the OPS-01 architecture report.
- **Available**: canonical formula `on_hand_qty − reserved_qty`, gated by `config('inventory_ledger.canonical_summary')` (currently `false` by default ⇒ `SUM(on_hand) − SUM(reserved)`; `true` ⇒ `SUM(on_hand − reserved)` per-row-clamped). Confirmed used **identically** in `ManufacturingAvailabilityService::evaluate()` and `ProductController@stats`/`EloquentProductRepository::paginate()` — one formula, not report-specific arithmetic.
- **Value**: same `canonical_summary` flag selects FIFO-layer value (`inventory_receipt_layers.remaining_qty * landed_unit_cost`, summed) when on, else `on_hand_qty * products.material_cost` when off (today's default) — **never** `sale_price` or any retail/Woo price.
- **Raw Material reporting page**: real, working — `frontend/src/features/raw-materials/` (`raw-materials-page.tsx` + `raw-material-table.tsx`), backed by `ProductController@index/stats` (`GET /products`, `GET /products/stats`).
- **Driver/Vehicle custody, Warehouse Transfer, Goods Receipt, vehicle returns, damaged stock, adjustments**: unchanged from Closures 01/02 — `VehicleInventoryItem`/`LoadingCustodyService`, `WarehouseTransfer`/`TransferStockAction`, `PostGoodsReceiptAction`, `ReceiveVehicleReturnAction`, `WarehouseLiability`/`WasteInvestigation`.
- **Sortation / فرزة**: **no matching source found anywhere** (backend or frontend, case-insensitive, including the Arabic string) — see §5.
- **Dashboard/report services**: `InventoryDashboardService`/`VarianceAnalyticsService`/`WarehousePerformanceService` — re-verified, see §4.

Nothing here was rebuilt; the three pre-existing dashboard/report services and `ProductController`/`EloquentProductRepository` were reused as-is except for the one frontend wiring change in §2.

---

## 2. WHAT WAS ACTUALLY CHANGED

**`frontend/src/features/inventory/pages/packaging-materials-page.tsx`** was a literal "Coming Soon" placeholder for a real `product_type` (`packaging_material`) that already has real warehouse stock and a fully-built, already-generic reporting stack sitting one file away, unused for this purpose: `RawMaterialsPage`'s own title/subtitle logic, `RawMaterialFilterBar`'s material-type dropdown, `RawMaterialStats`' label, and the CSV export column labels **all already branch on `packaging_material` explicitly** — the only missing piece was a route actually rendering that machinery pre-scoped to it, instead of a static card.

Changed:
- `frontend/src/features/raw-materials/pages/raw-materials-page.tsx` — added one optional prop, `defaultMaterialType?: MaterialType`, used only to seed the existing `materialType` filter state (`useState(defaultMaterialType ?? '')`). Omitting it reproduces the exact previous behavior of the `/raw-materials` route — zero risk to the existing page.
- `frontend/src/features/inventory/pages/packaging-materials-page.tsx` — replaced the placeholder card with `<RawMaterialsPage defaultMaterialType="packaging_material" />`. The type filter stays fully switchable from there (an operator can still choose "All Materials"/"Raw Materials" from the same dropdown) — this is a starting scope, not a lock, matching §17's "only make UI changes necessary" rather than adding new lock/restriction UI that wasn't asked for.
- `backend/tests/Feature/Inventory/RawMaterialReportingBoundaryTest.php` (new) — characterizes the business rule this rests on: `stats()` defaults to raw+packaging material only (finished goods contribute nothing even when present), a single-type filter doesn't leak the other type's stock, and inventory value comes from `material_cost`, never `sale_price`. All three passed by reading the existing, unmodified backend code — these lock in already-correct behavior, they don't fix a defect.

I traced the full frontend→backend chain by hand for this change (`raw-materials-service.ts`'s `material_type` → `product_type` mapping, `ProductController@stats`'s default/explicit type scoping, `EloquentProductRepository::paginate()`'s equivalent) rather than assuming; see §4 for one real nuance this surfaced.

**Everything else in this reconciliation is a finding, not a change** — see §§3–8.

---

## 3. FINISHED PRODUCT AVAILABILITY BOUNDARY (§9) — already correct, nothing built

`ManufacturingAvailabilityService::evaluate()` already exists, is already company-scoped (fails closed on a null `Product->brand->company_id`), and already returns exactly the state-only shape §9 asks for: `status: 'instock'|'outofstock'|'recipe_missing'` plus which raw materials are blocking — **never** a producible-units count. Already wired into `ProductController@show`/`@update` for finished goods. Not modified; not duplicated.

---

## 4. INVENTORY DASHBOARD TENANT SAFETY — RE-VERIFIED (§16)

`InventoryDashboardService`/`VarianceAnalyticsService`/`WarehousePerformanceService`: the `scopeToCompany()` fix from `941089a3` is intact (confirmed by direct grep — all five/four/four query sites still call it). `ProductController@stats` independently confirmed correctly scoped (`TenantOwnershipResolver` + `whereHas('brand', ...)`, fails closed on a null company) — covered by a new regression test in this pass (§2) rather than assumed.

**One real nuance found, not a defect**: `EloquentProductRepository::paginate()` (backing `GET /products`, the general product list/catalog — used by far more pages than just Raw Materials) applies **no default** `product_type` scope of its own — unlike `stats()`, which defaults to `raw_material,packaging_material` when no type filter is given. This is not a company-isolation gap (tenant scoping on `paginate()` is separate and was not found to be broken), and it is very likely **intentional** — this endpoint also serves the general Products catalog, which legitimately needs to list finished goods too. Today's only Raw-Material/Packaging-Material caller (`raw-materials-service.ts`) always sends an explicit `product_type`/`product_types` filter (confirmed by reading it), so no finished good currently leaks into either page. This is correct-by-convention rather than correct-by-construction — flagged as a fragility for OPS-01 Full Review, **not changed here**, since hardening it risks an unreviewed regression to the general Products catalog, a page this task did not investigate.

---

## 5. SORTATION / فرزة (§13–15) — **BLOCKED: genuinely absent, not an alias**

A dedicated background investigation and my own direct greps found **zero matches** for "Sortation" or the literal string "فرزة" anywhere in the repository (backend, frontend, excluding vendor/node_modules). This is not a naming mismatch: `WasteInvestigation` (cycle-count-triggered: requires a `count_session_id`, structurally cannot originate any other way) and `WarehouseLiability` (its approved downstream record) are real, different, pre-existing workflows — not an alias or rebrand of "فرزة."

Per §13's own instruction ("do not invent a second damage/write-off engine") and §14 ("if canonical approval is required, preserve it"), there is nothing existing here to reuse or preserve — building an approval-gated سortation workflow from scratch would itself be inventing a new engine, which is explicitly out of bounds for a reconciliation task, and not something safe to design blind under this task's no-runtime-testing policy for a workflow touching inventory quantities and (per §15) potentially Finance.

**Reported as PARTIAL/BLOCKED for this sub-area, with the evidence above, rather than built.** This needs a product/business decision first: is "فرزة" meant to be the existing `WasteInvestigation`/`WarehouseLiability` flow under an Arabic UI label that was simply never connected, or a genuinely distinct receiving-time rejection/segregation process ECOS doesn't have yet? Carried to OPS-01 Full Review.

---

## 6. IN-SHIPPING (§4–5) — **honestly absent, not fabricated**

No `in_shipping`/`in_transit`/`shipped_qty` reporting concept exists for raw materials, anywhere. The one real, working analog found — `VehicleInventoryItem.quantity_on_hand = loaded − delivered − returned` (Operations\Loading, surfaced at `GET .../assignments/{id}/inventory` and the driver-mobile "On Hand" tile) — tracks a **different domain**: finished sellable goods in a driver's vehicle custody, en route to a customer order. Raw materials in this system either sit `on_hand` in a warehouse, move between warehouses via the atomic (no in-transit window — §7 below) `WarehouseTransfer`, or get consumed into production. No current business process puts raw-material stock into a vehicle-custody-tracked "left the warehouse, not yet arrived, not yet returned" state.

Repurposing `VehicleInventoryItem` for raw materials would conflate two unrelated physical processes — exactly what §5/§11 forbid ("do not invent a separate... calculation", "do not fabricate a new intermediate inventory state" — §20's own principle applied here too). **Reported as not applicable to current raw-material logistics, rather than forced to a fabricated number.** If ECOS later ships raw materials between warehouses/suppliers by vehicle with a real custody window, that would be the moment to build this — not before.

---

## 7. WAREHOUSE TRANSFERS (§10) — already correct, confirmed structurally

`WarehouseTransfer`'s own docblock: "Immutable audit record created once per successful warehouse transfer." Its migration carries one `status` column (`TransferStatus`: `Completed`/`Failed`/`Reversed` only — no in-transit state), and `TransferStockAction` decrements the source and increments the destination `on_hand_qty` inside **one** `DB::transaction`. There is no persisted intermediate state, so reporting cannot show stock as simultaneously fully available at both warehouses — the concern §10 raises is structurally impossible given how transfers are actually implemented. Nothing to fix.

---

## 8. RETURNS / CUSTODY REPORTING (§11–12)

Unchanged from Closure 02's findings, re-confirmed: the `returns-settlement` workspace's gap-card pattern already satisfies "unavailable, not fake zero" for custody/discrepancy aggregates that have no real backend authority; `ReceiveVehicleReturnAction` remains the sole good/damaged/missing-preserving warehouse-receipt authority. Not modified in this pass.

---

## RETURN VALUES

**BASE SHA:** `c6a764d7`
**INVENTORY_REPORTING_SHA:** recorded below once committed.

**RAW MATERIAL AUTHORITY:** `Product` (discriminated by `product_type`) + `InventoryItem`; no separate model. `ProductController@index/stats`, `EloquentProductRepository`.

**QUANTITY SEMANTICS:** On Hand / Reserved from `InventoryItem`; Available = canonical `on_hand − reserved` (config-gated formula, one authority, confirmed identical across `ManufacturingAvailabilityService` and the Products reporting layer); In Shipping — no authority exists for raw materials, reported as not applicable rather than invented (§6).

**VALUE SEMANTICS:** `material_cost` (legacy default) or FIFO layer value (`canonical_summary=true`) — never retail/`sale_price`. Confirmed and regression-tested (§2).

**FINISHED PRODUCT BOUNDARY:** confirmed no physical finished-product warehouse quantity invented; `stats()`'s default scope structurally excludes `finished_good`. `ManufacturingAvailabilityService` is the existing, unduplicated availability authority (state-only: instock/outofstock/recipe_missing).

**IN-SHIPPING:** no canonical physical/custody authority exists for raw materials; not fabricated from Order/Trip/Delivery status or from the unrelated vehicle-custody-for-finished-goods mechanism.

**TRANSFERS:** atomic, single-transaction, no in-transit window — reporting cannot show a double-available state; confirmed structurally, unchanged.

**RETURNS:** Good/Damaged/Missing/Still-With-Driver — unchanged from Closure 02 (`ReceiveVehicleReturnAction`, `returns-settlement` workspace).

**SORTATION / فرزة:** absent; nearest analogs (`WasteInvestigation`/`WarehouseLiability`) are real but different; needs a business decision before any implementation (§5).

**DASHBOARDS:** tenant/company isolation re-verified on all three named services plus `ProductController@stats`; one non-defect fragility noted on `EloquentProductRepository::paginate()`'s lack of a default type-scope (§4) — not changed, flagged for review.

**FAKE-ZERO REMOVAL:** none newly needed in this pass — the raw-material/packaging-material views already surface real numbers or (via the returns-settlement workspace, unchanged) an honest gap card; no code path found fabricating a zero in this reconciliation.

**EXPECTED RETURNS:** unchanged from Closure 02 — honest gap retained, no new aggregate authority discovered that would justify wiring one.

**CARRY-FORWARD TO OPS-01 FULL REVIEW:**
- Pool Loading vs. Dispatch physical issue timing (Closure 02) — cross-module architecture review, not touched here either.
- Dead/legacy Loading assignment API (Closure 02) — `AssignDriverAction`/`DriverAssignmentController::store`/`VehicleAssignmentController::store`.
- Sortation / فرزة — business decision needed on whether it's `WasteInvestigation`/`WarehouseLiability` under a different name, or a genuinely new workflow.
- `EloquentProductRepository::paginate()`'s reliance on caller discipline (not a default scope) for excluding finished goods from materials-only views.
- `consumables`/`semi-finished-materials` frontend pages remain "Coming Soon" — neither corresponds to a real `product_type` value today (only `finished_good`/`raw_material`/`packaging_material` exist); building real reports for them needs a product-taxonomy decision, not a reporting fix.

**STATIC SANITY:**
- `php -l`: pass on the new test file.
- Pint: pass, no changes needed.
- PHPStan (full platform, `--memory-limit=4G`): 1 finding, the same pre-existing `Modules\Purchasing\PurchaseMaterials\...\SelectLineSupplierAction.php` baseline-drift artifact seen in Closure 02 — confirmed unrelated (this diff touches no Purchasing files); not fixed here.
- `git diff --check`: pass.
- Frontend ESLint (`npm run lint`): 0 errors; 11 pre-existing warnings, none in either changed file (`raw-materials-page.tsx`/`packaging-materials-page.tsx`) — confirmed by direct grep of the full output.
- Frontend TypeScript (`tsc -b --noEmit`): 0 errors in either changed file (confirmed by grepping the full error list for both filenames — no match); ~16 pre-existing errors remain in unrelated files (admin/configuration, business-accounts, engineering, hr, iam-admin, logistics/dispatch) — none touched by this diff.
- Frontend i18n audit (`npm run lint:i18n` / `audit:i18n --json`): 0 missing keys, 0 invalid JSON (both gate the pass/fail result and both pass); the script's overall FAIL is from pre-existing, project-wide counts (2,948 hardcoded strings, 751 RTL-unsafe classes, 43 genuine untranslated gaps) that also gate the result but have nothing to do with this diff — confirmed by reading the audit script's own pass condition and by grepping its full output for both changed files (no match). "Orphan keys" (8) is a pre-existing EN/AR key-set-mismatch count, unrelated to code usage and unaffected by this change — confirmed by reading the script's own definition of that metric.
- `npm install` was required first (no `node_modules` existed) — completed successfully (19 min, this host's disk I/O is heavily constrained throughout this whole session; unrelated to this diff).

**DEFERRED TO OPS-01 CONSOLIDATED TESTING:** all tests written across Closures 01–03, the full MySQL/PHPUnit/browser/integration/security pass.

---

## OPS-01 FULL SOURCE RECONCILIATION

| Area | Status |
|---|---|
| A. Tenant-safe Inventory dashboards | COMPLETE |
| B. Warehouse Transfer | COMPLETE |
| C. Physical return route | COMPLETE |
| D. Loading/Custody | COMPLETE |
| E. Driver Returns / Warehouse Receipt | COMPLETE |
| F. Raw Material Inventory Reporting | COMPLETE WITH REVIEW GAP (consumables/semi-finished pages need a taxonomy decision, out of this task's scope) |
| G. Reserved / Available / In-Shipping | Reserved/Available: COMPLETE. In-Shipping: N/A for current raw-material logistics (§6) |
| H. Transfers | COMPLETE |
| I. Sortation / damage-loss reporting | BLOCKED — needs a business decision (§5) |
| J. Returns/custody reporting | COMPLETE |

## OVERALL: **OPS-01 SOURCE IMPLEMENTATION COMPLETE WITH REVIEW GAPS**

## CONFIRM

- Raw materials are the warehouse stock authority — confirmed and regression-tested.
- No finished-product physical quantity invented.
- No fake zero for unavailable data.
- No Pool stock-timing redesign (left exactly as Closure 02 left it).
- No duplicate Inventory engine.
- No Closure 04.
- No OPS-02/03/04.
- No push, no merge, no deploy.
- `E:\ECOS\ECOS-V1-STAGING` untouched.

## STOP

For OPS-01 FULL SOURCE REVIEW.
