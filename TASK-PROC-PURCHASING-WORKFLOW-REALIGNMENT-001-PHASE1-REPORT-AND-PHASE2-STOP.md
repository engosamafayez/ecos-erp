# TASK-PROC-PURCHASING-WORKFLOW-REALIGNMENT-001 — Phase 1 Report + Phase 2 STOP

**Date:** 2026-08-20
**Phase 1 (safe alignment):** IMPLEMENTED + RUNTIME VERIFIED.
**Phase 2 (Purchase → Invoice → Receipt → Completion core):** **STOPPED before any code** — the approved line-anchor model cannot be built on the existing schema. Details in §B, per implementation rule 10.
**No commits. No migration created.**

---

# A. PHASE 1 — SAFE ALIGNMENT (delivered)

## A1. Files created / modified

**Frontend (12)**
| File | Change |
|---|---|
| `features/purchase-materials/pages/purchases-page.tsx` | Deleted `SourceSelectorDialog` + `SOURCE_OPTIONS`; **New Purchase now opens the one operational purchase directly** (`sourceType="direct"`); dropped now-unused imports |
| `config/module-navigation.ts` | **Material Requests nav leaf withdrawn** (route/page/record_type untouched) |
| `features/procurement/pages/procurement-hub-page.tsx` | Work-queue card repointed MR → **Purchases in review**; removed the "New Material Request" quick action |
| `features/suppliers/components/supplier-form.tsx` | **Opening-Balance inputs removed** → hint pointing at the Finance operation |
| `features/suppliers/components/supplier-wizard.tsx` | Same removal + step-2 field list cleaned |
| `features/suppliers/components/supplier-form-schema.ts` | `opening_balance_*` removed from schema/defaults/payload — CRUD never writes them |
| `features/suppliers/components/supplier-360-drawer.tsx` | **New Invoices tab** (reuses `?supplier_id`); performance "No data" empty state; null-safe score rendering |
| `features/suppliers/components/procurement-health-badge.tsx` | Accepts `'no_data'` → renders a dash |
| `features/suppliers/types/supplier-analytics.ts` | Health components/score/trend now nullable + `has_history` |
| i18n `en|ar`: `procurement.json`, `suppliers.json`, `receiving-center.json`, `supplier-invoices.json` | New keys + **Post relabeling** (both locales, parity kept) |

**Backend (4 — existing contracts only, no new engine, no migration)**
| File | Change |
|---|---|
| `Suppliers/Infrastructure/Repositories/EloquentSupplierRepository.php` | Added a **ledger-derived** aggregate (batched, mirrors `SupplierLedgerService`) for outstanding payable / available advance / posted opening payable; guarded by `Schema::hasTable` |
| `Suppliers/Presentation/Http/Resources/SupplierResource.php` | Money columns now **ledger-derived**; **removed the opening-balance double-count** (`current_supplier_balance` used to add the `suppliers.opening_balance_amount` scalar on top of the ledger); `available_advance` exposed as its **own** column |
| `Suppliers/Application/Queries/GetProcurementHealthQuery.php` | **Fabricated midpoints removed** (50/50/75/30/100/50 → `null`); weighted score computed only over components with data and re-normalised; `has_history` added; hard-coded `trend:'stable'` → `null` |
| `GoodsReceipts/Presentation/Http/Controllers/GoodsReceiptController.php` | Forwards `supplier_id` (the repo already filtered it) — Supplier 360's GR tab is now supplier-scoped |

**Test modified (1):** `tests/Feature/Purchasing/SupplierTenantIsolationTest.php` — see A3.

## A2. Section-by-section status
| § | Item | Status |
|---|---|---|
| 2 | "From Material Request" removed from purchase entry | **IMPLEMENTED** |
| 3 | Redundant source choices removed (single New Purchase) | **IMPLEMENTED** |
| 9 | Ambiguous "Post" replaced: GR → **Confirm Receipt**, Invoice → **Post Invoice** | **IMPLEMENTED** (16 label changes, en+ar) |
| 13 | Supplier 360 Invoices tab | **IMPLEMENTED** |
| 14 | Supplier edit persistence | **ROOT-CAUSED + FIXED** — profile fields already persisted; the only silent drop was Opening Balance (validated, then dropped by `SupplierDTO`, producing a success toast and no change). Inputs removed; opening balance is a Finance posting. |
| 15 | Performance realism | **IMPLEMENTED + RUNTIME VERIFIED** |
| 16 | List balances from the real ledger; Advance ≠ Payable | **IMPLEMENTED** |
| 17 | Opening Balance contract untouched | **VERIFIED** (9/9 opening-balance tests still green) |
| 19 | Procurement sidebar | **IMPLEMENTED** (audited first: 2 deep-links found and repointed; route/page kept) |
| 21 | No data loss | **HONORED** — nothing deleted; MR route, page, `record_type`, and all rows intact |

## A3. Tests
**Run through the serialized gate.**
- `SupplierAnalyticsTest` + `SupplierOpeningBalanceTest` + `SupplierTenantIsolationTest` → **29/29 OK, 73 assertions** (health nullability, opening-balance non-regression, tenant isolation).
- `PurchaseMaterialRecordTypeFilterTest` (incl. the KPI-leak regression), `PurchaseMaterialApprovalWorkflowTest`, `PurchaseMaterialTenantIsolationTest`, `InboundOwnershipContractTest` → **25/26**; the 1 error is **pre-existing**, proven by a **control run at HEAD** (same `packaging_materials` account-role mapping error with the HEAD version of my file) — not attributable to this task.

**Disclosed test change:** attaching `purchasing.suppliers.view` to supplier reads (shipped in the previous task) made 3 tenant tests 403. Those tests exist to prove *tenant scoping*, and their docblock still described the old ungated route. I granted their operator roles **only** `purchasing.suppliers.view` (nothing else) so they again measure the scope, and corrected the docblock. The security gate itself was kept.

## A4. Static verification
php -l clean · **Pint PASS** · **PHPStan L0 [OK] No errors** · **tsc 23 = baseline, 0 new** · ESLint 0 on all changed files · **vite build ✓** · en/ar i18n parity kept.

## A5. Not done in Phase 1
Receiving-queue UI, Ordered/Invoiced/Received/Remaining grid, partial-receipt display — these belong to the Phase 2 core and depend on §B.

---

# B. PHASE 2 — STOP + REPORT (schema blocker)

Owner decision 3 requires: **"Receipt quantities must be attributable to Purchase lines."** With `PurchaseMaterial` as the Purchase (decision 1), and no revival of the legacy `PurchaseOrder` as the workflow anchor (decision 3), this is **not expressible on the current schema**.

## B1. Exact missing capability
There is **no link, at any level, between a Goods Receipt and a Purchase (`PurchaseMaterial`)**:
- `goods_receipt_lines` columns: `goods_receipt_id, purchase_order_line_id, product_id, ordered_quantity, gross/net/received_quantity, …` — **no `purchase_material_line_id`**.
- `purchase_material_lines` columns: `purchase_material_id, product_id, requested_qty, agreed_qty, agreed_price, …` — **no link to a GR line or PO line**.

## B2. Why existing columns/relations cannot support it
1. **A Goods Receipt structurally requires a legacy PurchaseOrder — NOT NULL, no default:**
   - `goods_receipts.purchase_order_id` → `foreignUuid('purchase_order_id')->constrained('purchase_orders')` (migration `2026_06_23_140001:20`) — **not nullable**.
   - `goods_receipt_lines.purchase_order_line_id` → `foreignUuid(...)->constrained('purchase_order_lines')` (migration `2026_06_23_140002:20`) — **not nullable**.
   So a receipt cannot even be created against a Purchase; a `PurchaseOrder` + `PurchaseOrderLine` must exist first.
2. **The certified inventory path is PO-bound.** `PostGoodsReceiptAction` — which decisions 6/§11 require reusing — loads `$receipt->purchaseOrder` (:76) and, per line, does `PurchaseOrderLine::findOrFail($line->purchase_order_line_id)` (:172-174), updates `received_qty` (:177-219) and advances PO status. Receiving without a PO would bypass the very path the owner mandated.
3. **No PM→PO bridge exists.** Repo-wide search: `PurchaseOrders` module has **zero** references to `PurchaseMaterial`, `purchase_orders` migrations have **no** `purchase_material` column, and `PurchaseMaterials` contains **no** PO-creating action.
4. **The invoice hub is inert.** `SupplierInvoice.auto_purchase_id` and `SupplierInvoiceLine.goods_receipt_line_id` exist but are **absent from the create contract and never written by any production path**, so even the indirect chain Purchase → Invoice → GR line carries no data today.

**Note on `goods_inward_mode`:** the certified contract is **not** the blocker here — it governs *which single document posts stock per company* (GR vs invoice), which the line-anchor model respects. The blocker is purely the missing FK/nullability above. No conflict with the certified decision was found.

## B3. Proposed migration (NOT created — awaiting approval)
Minimum to satisfy decision 3 while keeping `PostGoodsReceiptAction` as the only inventory path:
```
1) ALTER goods_receipt_lines
     ADD COLUMN purchase_material_line_id CHAR(36) NULL
       (FK → purchase_material_lines.id, restrictOnDelete) + index
2) ALTER goods_receipts
     ADD COLUMN purchase_material_id CHAR(36) NULL
       (FK → purchase_materials.id, restrictOnDelete) + index
3) ALTER goods_receipts   MODIFY purchase_order_id      NULL  (was NOT NULL)
   ALTER goods_receipt_lines MODIFY purchase_order_line_id NULL  (was NOT NULL)
```
Plus backend work (no second engine): make `PostGoodsReceiptAction` update **either** the PO line **or** the Purchase line; a receiving work-queue read endpoint over purchases with `remaining > 0`; a completion action advancing `Approved → Receiving → Completed` via the existing `nextWorkflowState()` when received = ordered.

## B4. Impact
- **Additive + nullable** → existing PO-anchored receipts and all historic rows keep working unchanged; both `purchase_order_id` paths stay valid.
- Relaxing two NOT NULLs weakens a DB-level guarantee; it must be replaced by an application invariant: *a receipt line references exactly one anchor (PO line XOR Purchase line)*.
- Touches the certified inbound path (`PostGoodsReceiptAction`) — the inbound-ownership, concurrency and GR suites must all be re-run.
- No accounting change; `goods_inward_mode`, the Opening Balance contract, and the AP ledger are untouched.

## B5. Rollback
`down()` drops the two FKs/columns and restores both NOT NULLs. Safe **only while** no row uses a Purchase anchor (all `purchase_material_line_id IS NULL` and `purchase_order_line_id IS NOT NULL`); once operators receive against Purchases, rollback would orphan those receipts — so rollback is a pre-adoption escape hatch, not a general reversal.

## B6. Decision requested
Approve **one**:
- **(i)** the §B3 migration + backend work (delivers decision 3 exactly as approved); or
- **(ii)** a hidden **PM→PO bridge** — auto-create a PO on purchase approval so receiving keeps its required anchor (**no migration**, reuses the certified path end-to-end, but the legacy PO remains the physical receiving anchor behind the scenes); or
- **(iii)** defer the Phase 2 core.

I have implemented nothing for Phase 2 and created no migration.

---

## C. Acceptance status (§12 / §22)
- A (purchase creation), G (supplier edit), H (performance realism), I (post semantics), J (regression) — **covered by Phase 1** (runtime-verified where testable).
- B (invoice linkage), C/D/E (receiving, partial, completion), F (full supplier history of the chain) — **BLOCKED on §B**.
- **Browser acceptance: BLOCKED** (no authenticated runtime available). **No certification claimed.**
