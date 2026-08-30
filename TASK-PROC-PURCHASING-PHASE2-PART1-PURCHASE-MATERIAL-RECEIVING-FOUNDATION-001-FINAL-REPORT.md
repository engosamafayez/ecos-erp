# TASK-PROC-PURCHASING-PHASE2-PART1 — PURCHASE MATERIAL RECEIVING FOUNDATION — FINAL REPORT

**Date:** 2026-08-21 · **Phase:** 2 · **Part:** 1 · **No commits.**

---

## 1. EXECUTIVE SUMMARY

The operational chain **Purchase Material → Purchase Material Line → Goods Receipt Line → Inventory** now exists and is proven by automated test. A goods receipt can be raised and posted against a Purchase **without any purchase order**, supplier identity comes from the Purchase line (RD-1), Required/Received/Remaining follow RD-2/RD-3, over-receipt is refused on the Purchase branch, and inventory posts exactly once through the **existing certified path** — no second inventory engine, no fake purchase order, no fake journal.

**Verdict: BLOCKED** (backend foundation COMPLETE; Part 1 cannot be closed).
Browser acceptance is impossible with existing data: the only real Purchase (`PM-00002`) has **no supplier on its line**, and creating that data is business-data manufacturing, which this task forbids. UI was deliberately not built pending that unblock. **Nothing is claimed CERTIFIED.**

One regression was introduced during this Part and **fixed**: it was caught by a control run at HEAD, not by inspection. Details in §12.

---

## 2. INITIAL AUDIT (answers to A1–A7)

| # | Question | Answer (verified) |
|---|---|---|
| 1 | Where is a Goods Receipt created? | `CreateGoodsReceiptAction` (+ `EloquentGoodsReceiptRepository::create`), HTTP via `StoreGoodsReceiptRequest` → `GoodsReceiptController@store` |
| 2 | Where is Supplier determined? | **Nowhere on the receipt** — `goods_receipts` has no `supplier_id`. It was read transitively as `$po->supplier_id` and stamped on the FIFO layer (`CreateReceiptLayersAction:58`) |
| 3 | Where is Received Quantity determined? | `goods_receipt_lines.net_received_quantity` via `effectiveReceivedQty()`; cumulative counter on `purchase_order_lines.received_qty` |
| 4 | Where is over-receipt checked? | `PostGoodsReceiptAction` Guard 4 — against `poLine->quantity`, under `lockForUpdate` |
| 5 | Where is Inventory posted? | `PostGoodsReceiptAction` → `ReceiveStockAction` (stock ledger) + `CreateReceiptLayersAction` (FIFO), gated by `GoodsInwardAuthority` |
| 6 | Where did the path depend on PurchaseOrder? | Schema (2 NOT NULL FKs) + `CreateGoodsReceiptAction` (PO fetch, 3 status guards, company, unit price) + `PostGoodsReceiptAction` (status guards, company, over-receipt, `received_qty`, PO status) + `CreateReceiptLayersAction` (company, supplier) |
| 7 | Minimum change to make the PM Line the anchor? | 1 new nullable FK column + 2 nullability relaxations, then branch the three actions. **No PO deletion, no legacy column removal.** |

**Live data state (read-only):** `goods_receipts` **0**, `goods_receipt_lines` **0**, `purchase_orders` **0**, `purchase_order_lines` **0**, `purchase_materials` **2**, `purchase_material_lines` **2**. Confirms the AUDIT-002 finding that historical risk is nil.

---

## 3. ARCHITECTURE DECISION

Option A as approved. The Purchase Material line is the **operational anchor**; the legacy PurchaseOrder path is left **fully intact and still supported**. A receipt is *either* PO-anchored *or* Purchase-anchored — never both (enforced). No hidden PO is ever created to satisfy the legacy path — the explicit prohibition in item F.

---

## 4. EXACT SCHEMA CHANGES

Migration `2026_08_21_100000_add_purchase_material_anchor_to_goods_receipt_lines.php` — **3 changes, 2 tables**:

| # | Table | Column | Current | New | Null | FK | Index | Backfill |
|---|---|---|---|---|---|---|---|---|
| 1 | `goods_receipt_lines` | `purchase_material_line_id` **(new)** | — | `CHAR(36)` | YES | → `purchase_material_lines.id` **RESTRICT** | `grl_purchase_material_line_idx` | **NONE** |
| 2 | `goods_receipt_lines` | `purchase_order_line_id` | `NOT NULL` | `NULL` | YES | unchanged | existing | none |
| 3 | `goods_receipts` | `purchase_order_id` | `NOT NULL` | `NULL` | YES | unchanged | existing | none |

- **Why required:** attribution is not derivable — `purchase_material_lines` has no unique `(purchase_material_id, product_id)` (same product on two lines with different suppliers is the designed split-sourcing shape), and multiple open Purchases per supplier+product are normal. A stated FK is a lookup, not a guess — the same resolution already used by `supplier_invoice_lines.goods_receipt_line_id` and `supplier_return_lines.goods_receipt_line_id`.
- **Why RESTRICT, not nullOnDelete:** editing a held Purchase **hard-deletes and recreates** its lines; under `nullOnDelete` that would silently orphan a *posted* receipt (stock in the warehouse, attribution gone). RESTRICT makes it a loud, correct failure.
- **Existing compatibility:** additive + widening only. Every existing row keeps its PO anchor and reads NULL in the new column, i.e. "legacy, PO-anchored receipt". **No backfill written** — none is deterministic, and inventing one is the guess this design refuses.
- Applied to the test DB and verified: column present, both PO columns nullable.

---

## 5. BACKEND CHANGES

**New (3):** `PurchaseMaterialReceivingService` (quantity SSOT) · `PurchaseMaterialReceivingException` (typed refusals) · the migration.

**Modified (8):**
- `CreateGoodsReceiptAction` — anchor detection; PM branch skips PO fetch/guards, resolves company from the Purchase (warehouse fallback), takes unit price from `agreed_price`, and validates supplier identity + product match + single-supplier + no-mixed-anchors.
- `PostGoodsReceiptAction` — PO guards run **only when a PO exists**; company resolved from the receipt's own stamped ownership first; **new PM-branch over-receipt ceiling**; PO `received_qty` increment and PO status advance skipped for PM lines.
- `CreateReceiptLayersAction` — null-safe `$po`; **FIFO layer supplier resolved from the Purchase line** when there is no PO.
- `GoodsReceiptDTO` / `GoodsReceiptLineDTO` — nullable anchors; the PM anchor is an **optional trailing** parameter (see §12).
- `GoodsReceiptLine` — fillable + `purchaseMaterialLine()` relation.
- `StoreGoodsReceiptRequest` — XOR anchor rules + **tenant-scoped** `Rule::exists` on the new FK.
- `PurchaseMaterialLineResource` — exposes `required_qty` / `received_qty` / `remaining_qty`, batched per Purchase to avoid an N+1 in the purchases list.

---

## 6. UI CHANGES

**None — deliberately.** Rationale: browser acceptance is blocked by missing business data (§13), so any receiving screen shipped now would be unverifiable surface. The API contract it needs is in place (`required_qty`/`received_qty`/`remaining_qty` on every Purchase line). **No frontend file was touched in this Part**, so the frontend gates are unchanged from their last green state. The approved label (`Confirm Receipt`, never "Post") is recorded for the UI step and was already applied to the Receiving Center in Phase 1.

---

## 7. SUPPLIER SSOT (RD-1)

Supplier for a Purchase-anchored receipt is **`purchase_material_lines.supplier_id`** — never the PurchaseOrder. Enforced at creation:
- line with **no supplier** → refused (`supplierMissing`), because there is no identity to attribute the stock to;
- lines with **two suppliers** on one receipt → refused (`supplierMismatch`);
- the resolved supplier is what lands on the **FIFO layer**, which is what supplier returns and supplier cost analytics attribute through (asserted in test).

**Confirmed from schema, not assumed:** `purchase_material_lines.supplier_id` is `nullable` and has **no real FK** — the original migration used `uuid()` instead of `foreignUuid()`, so `->constrained('suppliers')` silently created nothing. Recorded as a finding (§16); not repaired here, as that is outside Part 1.

---

## 8. QUANTITY RULES (RD-2 / RD-3)

One definition, in `PurchaseMaterialReceivingService`, used by the guard and the API alike:
- **Required** = `COALESCE(agreed_qty, requested_qty)`
- **Received Gross** = Σ `COALESCE(net_received_quantity, received_quantity)` over **posted, non-deleted** receipts anchored to that Purchase line — **gross of returns** (returns remain a separate outbound document; receipt history is never rewritten)
- **Remaining** = `max(0, Required − Received Gross)`

**Derived, not a stored counter** — deliberately unlike `purchase_order_lines.received_qty`, which has no reversal path and already drifts because approved returns never decrement it.

---

## 9. OVER-RECEIPT RULE

A new ceiling on the Purchase branch inside the posting transaction, under `lockForUpdate` on the Purchase line, comparing `Received Gross + this receipt` against **Required**. No tolerance rule was invented — none exists in the codebase, so the ceiling is exact. The legacy PO ceiling is untouched. Both are proven by test (including that the ceiling correctly follows `agreed_qty` when present).

---

## 10. INVENTORY INTEGRATION

The Purchase branch reuses the certified path **unchanged**: `ReceiveStockAction` (sole stock-ledger writer) and `CreateReceiptLayersAction` (FIFO + landed cost), still gated by `GoodsInwardAuthority`. Nothing was rewritten in the stock ledger, FIFO, receipt layers or costing, and **no fake PurchaseOrder is created** to make the legacy path work. Verified: on-hand rises by exactly the received quantity, exactly one `purchase_receipt` ledger entry per posted receipt, and a re-post is refused without moving inventory.

---

## 11. TESTS

`tests/Feature/Purchasing/PurchaseMaterialReceivingFoundationTest.php` — **15 tests, all green**, covering the required matrix: PM line anchors a receipt (no PO invented) · supplier from the PM line (asserted on the FIFO layer) · missing supplier refused · mixed suppliers refused · Required = `agreed_qty` when present · Required = `requested_qty` when null · Received Gross accumulates 40 → 70 with Remaining 60 → 30 · draft receipts don't count · over-receipt refused (both ceilings) · inventory posted once with no duplicate ledger entry and re-post refused · company ownership · warehouse scope · mixed anchors refused · **no PurchaseOrder row touched**.

**Combined verification run: 17/17, 36 assertions OK** (the 15 above + the 2 regression tests from §12).

---

## 12. REGRESSION RESULTS — classified honestly

A **control run at HEAD** (my files reverted in-container, same schema, same filter) was used to separate causes.

| Run | Result |
|---|---|
| Control (HEAD code) | 103 tests — **25 failing** (14 errors + 11 failures) |
| With Part-1 changes | 103 tests — **15 failing** (9 errors + 6 failures) |

- **Caused by this Part: 2 — and now FIXED.** `GoodsReceiptTest::test_uom_snapshot_captured_on_line_creation` and `test_uom_snapshot_immutable_after_product_unit_change`. **Root cause:** I added `purchase_material_line_id` as a *required* constructor parameter to `GoodsReceiptLineDTO`; every existing caller constructs it with **named arguments**, so they threw `ArgumentCountError`. **Fix:** the parameter is now the **last, optional** parameter, keeping the legacy contract byte-compatible. Both tests re-run green.
- **Pre-existing: 13** — all `Inbound*` (`InboundCrossDocumentConcurrencyTest`, `InboundOwnershipContractTest`, `InboundOwnershipHttpTest`). Every one also fails at HEAD. Their causes are environment/fixture conditions unrelated to receipt anchoring (missing `packaging_materials` account-role mapping; products with no `product_type` classification).
- **Not claimed:** the control failed 10 tests that did not recur in my run. These suites are visibly order/state-dependent, so I make **no claim** to have fixed them.
- **No fixture was edited to mask a production defect.** The one production defect found (the DTO signature) was fixed in production code.

**Static gates:** `php -l` clean · **Pint PASS** (5 files auto-formatted, pulled back to host) · **PHPStan L0 — [OK] No errors** on all 12 Part-1 files. **Frontend:** no frontend file changed in this Part, so tsc/ESLint/Vite/i18n are unchanged from their last green state (not re-run, and not claimed).

---

## 13. BROWSER ACCEPTANCE — **BLOCKED**

Not executed. **Not claimed.**

**Reason (data, not code):** the only real Purchase is `PM-00002` (`record_type=purchase`, `status=approved`), and its single line has **`supplier_id = NULL`** (and `agreed_qty = NULL`). Under RD-1 the Purchase line is the supplier SSOT, so that line is correctly refused for receiving. The other Purchase Material, `PM-00001`, is a `material_request`, not a purchase.

Per the task's data-safety rule I did **not** create or mutate any business data to manufacture a scenario.

**Exact unblock (one normal business action, yours to take):** select a supplier on `PM-00002`'s line through Procurement Review (`POST /purchase-materials/{id}/lines/{line}/select-supplier`). That single action makes the Purchase receivable, after which the UI step and the full browser walkthrough can be completed.

---

## 14. DATA SIDE EFFECTS

**None in any live database.** No business data was created, modified or deleted; no raw SQL business mutation; no inventory adjustment; no reset. The only live-schema effect is the additive migration, applied to the **test** database for the suite. All test data lives inside `RefreshDatabase` transactions.

---

## 15. AUTHORIZATION

**No permission was created.** The Purchase branch reuses the existing goods-receipt permissions on the existing routes (`purchasing.goods_receipts.create` / the post route). No authorization vocabulary conflict was encountered, and no gate was bypassed. The new FK's request rule is **tenant-scoped**, deliberately not repeating the scope-blind `exists:` that made the legacy `purchase_order_line_id` FK a cross-tenant edge.

---

## 16. KNOWN FINDINGS (recorded, not actioned — outside Part 1)

1. **FOLLOW-UP — the 13 analytics `INNER JOIN purchase_orders` sites.** A Purchase-anchored receipt (NULL `purchase_order_id`) is **silently dropped** from supplier analytics, procurement health, price history, demand and the supplier timeline. Not fixed here because no supplier analytic is part of the Part-1 receiving foundation, and a blanket refactor was explicitly out of scope. **This must be closed before Purchase receipts are used in anger.**
2. `purchase_material_lines.supplier_id` has **no database FK** (migration used `uuid()` not `foreignUuid()`), so nothing at DB level prevents a cross-tenant supplier id.
3. Pre-existing `Inbound*` failures (§12) — account-role mapping and product-classification fixture gaps.
4. Editing a held Purchase hard-deletes and recreates its lines; with RESTRICT this now fails loudly once a receipt exists. It should become a domain rule rather than surfacing as a 500 (Part 2 candidate).
5. Carried from AUDIT-002, unchanged: a UI-created supplier invoice cannot post in default mode; `goods_inward_mode = supplier_invoice` silently disables Supplier Returns.

---

## 17. DEFERRED ITEMS (explicitly NOT started)

Purchase completion lifecycle (`Purchasing/Receiving/Completed` transitions) · receiving work-queue endpoint · receiving UI · Supplier Returns against Purchase receipts · Supplier Invoice anchoring to Purchases · analytics migration · **Phase 2 Part 2** · anything in Preparation, Distribution, Zones, Loading, Logistics or POS (untouched).

---

## 18. ROLLBACK

`php artisan migrate:rollback` on this migration drops the FK, index and column and restores both NOT NULLs. **Safe while no Purchase-anchored receipt exists** (currently true: zero receipts in every database). Once operators receive against Purchases, rollback would strand those receipts and is no longer a clean reversal — it is a pre-adoption escape hatch. Code rollback is a plain revert of 11 files; the legacy PO path is untouched and would continue working either way.

---

## 19. FINAL VERDICT

**BLOCKED** — backend foundation **COMPLETE and RUNTIME VERIFIED** (17/17), but Part 1 cannot be closed:
1. **Browser acceptance BLOCKED** on business data I am not permitted to create (§13).
2. **UI not built**, deliberately, pending that unblock (§6).

**NOT CERTIFIED**, and no part of this report claims certification.

**Stopping here** per the STOP rule. Awaiting your review and the supplier-selection unblock before the UI step and browser acceptance.
