# TASK-PROC-PURCHASING-WORKFLOW-REALIGNMENT-001-PHASE2-ARCHITECTURE-AUDIT-002 — REPORT

**Date:** 2026-08-21
**Status:** AUDIT ONLY — no implementation, no migration, no API change, no business data, no commit.
**Method:** six parallel read-only domain audits + independent verification of the two highest-stakes claims.

**Markers:** `PASS` · `FINDING` · `BLOCKER` · `REQUIRED DECISION` · `RECOMMENDATION`

---

## PART 1 — CURRENT SYSTEM AUDIT

**FINDING — the chain in the brief does not exist. There are two disconnected chains.**

```
Supplier → PurchaseMaterial → PurchaseMaterialLine → [DEAD END]
Supplier → PurchaseOrder → POLine → GoodsReceipt → GRLine → stock ledger + FIFO → SupplierInvoice → AP ledger
```

There is **no column, no relation and no code** linking PurchaseMaterial to PurchaseOrder, to GoodsReceipt, or to the stock ledger.

### SSOT table
| Question | SSOT today | Evidence |
|---|---|---|
| Purchase Material quantity | **`purchase_material_lines.requested_qty`** (NOT NULL). `agreed_qty` is nullable, written only by supplier selection, and **read by nothing but the API resource** | `2026_07_04_300001:26`; `2026_07_04_300002:22`; `SelectLineSupplierAction.php:52`; `PurchaseMaterialLineResource.php:35` |
| Received quantity (per event) | **`goods_receipt_lines.net_received_quantity`** via `effectiveReceivedQty()` = `net ?? received` | `2026_06_25_200001:21` ("the authoritative quantity for stock movements"); `GoodsReceiptLine.php:86-89` |
| Received quantity (cumulative) | **`purchase_order_lines.received_qty`** — sole writer `PostGoodsReceiptAction.php:217-220` | `2026_06_25_100001:19-21` |
| Remaining quantity | **Never stored.** Derived in 3 places, **all on PO lines** | `PurchaseOrderLine.php:62-70`; `ExpectedIncomingQuery.php:54-56`; `PostGoodsReceiptAction.php:176-186` |
| Supplier payable | **`finance_supplier_ledger_entries`** via `SupplierLedgerService` (SUM, never stored) | `SupplierLedgerService.php:17-40`; writers: `AccountsPayableService.php:142-152,259-269`, `SupplierOpeningBalanceService` |
| Inventory posting source | **`ReceiveStockAction`** (sole `stock_ledger_entries` writer) + **`CreateReceiptLayersAction`** (FIFO). Two callers only: GR post, Mode-3 invoice post | `ReceiveStockAction.php:70-84`; `CreateReceiptLayersAction.php:144-156` |
| Goods Receipt ownership | `Modules\Purchasing\GoodsReceipts`; fail-closed tenant scope; **requires an approved PO to exist** | `GoodsReceipt.php:169-196`; `StoreGoodsReceiptRequest.php:29,54`; `CreateGoodsReceiptAction.php:37-57` |
| Purchase Order ownership | `Modules\Purchasing\PurchaseOrders`; **no tenant global scope**; full live CRUD + submit/approve/cancel | `PurchaseOrder.php` (no `booted()`); `routes/api.php:621-627` |

### Bridges — every FK between these aggregates
**LIVE:** PO→Supplier; POLine→PO; GR→PO (`purchase_order_id` NOT NULL — *the load-bearing bridge*); GRLine→POLine (`purchase_order_line_id` NOT NULL — *the quantity bridge*); GR→Warehouse/Company; layers→GRLine (UNIQUE); SILine→GRLine (V-5 anchor); SRLine→GRLine (certified SR-2 precedent); PMLine→Supplier (advisory only).

**DEAD (column exists, no production writer):**
- `supplier_invoices.auto_purchase_id` → `purchase_materials` — **the only PM↔physical bridge in the entire schema, and nothing writes it** (`2026_07_04_400003:27`; absent from `StoreSupplierInvoiceRequest.php:19-47`).
- `supplier_invoices.auto_receipt_id` → `goods_receipts` — written only in tests (`GoodsInwardAuthority.php:17-19` states this verbatim).
- `purchase_orders.company_id` / `warehouse_id` — **never written by any production path** (`CreatePurchaseOrderAction.php:29-38`; `PurchaseOrderDTO` has no such fields).

**ANSWER TO THE CRITICAL QUESTION — `PASS` (proved, not assumed):** PurchaseMaterial is **not** a physical anchor. It is a requisition/approval document whose lifecycle terminates at `Approved`. Its `Purchasing`/`Receiving`/`Completed` states have **zero writers** repo-wide, and its only downstream consumer is a read-only demand figure.

---

## PART 2 — OPTION A AUDIT (minimum schema change set)

**FINDING — the minimum set is 3 changes across 2 tables** (smaller than the 4-across-3 in the Phase-1 stop report).

| # | Table | Column | Current | Proposed | Null | FK | Index | Unique | Backfill | Existing-data impact | Rollback |
|---|---|---|---|---|---|---|---|---|---|---|---|
| 1 | `goods_receipt_lines` | `purchase_material_line_id` **(new)** | — | `CHAR(36)` | **YES** | → `purchase_material_lines.id` **RESTRICT** | plain, **required** | **none** (partial receipts need many rows per PM line) | **NONE, ever** | none (all rows NULL) | drop FK+index+column; safe only while all values NULL |
| 2 | `goods_receipt_lines` | `purchase_order_line_id` | `CHAR(36) NOT NULL` | `CHAR(36) NULL` | YES | unchanged | existing | none | none | none — widening never rewrites values | restore NOT NULL (fails if any NULL) |
| 3 | `goods_receipts` | `purchase_order_id` | `CHAR(36) NOT NULL` | `CHAR(36) NULL` | YES | unchanged | existing | none | none | none | restore NOT NULL |

### Is `purchase_material_line_id` actually necessary? — **YES, and it is not derivable**
- **Via the invoice route (`auto_purchase_id` + `goods_receipt_line_id`): impossible.** The column is never written, is header-level (resolves the Purchase, never the *line*), and would make receiving depend on an invoice existing — inverting physical reality.
- **Via product matching: non-deterministic on three independent axes** — (a) no `unique(purchase_material_id, product_id)` on `purchase_material_lines` (only two plain indexes at `2026_07_04_300001:32-33`), and same-product-different-supplier lines are the *designed* split-sourcing shape; (b) multiple open PMs per supplier+product are normal (`GetSupplierProductDemandQuery.php:214-221` assumes it); (c) `unit_label` is a free string vs a real UoM snapshot on GR lines.
- **Precedent:** the codebase has twice chosen a stated nullable FK over inference (`supplier_return_lines.goods_receipt_line_id`, `supplier_invoice_lines.goods_receipt_line_id` — "*This column makes it a lookup instead of a guess*", `2026_08_17_120000:9-35`).

### Rejected from the minimum set (do NOT add)
- **`goods_receipts.purchase_material_id`** — the line link is strictly stronger (one join yields the header) and a header column can disagree with it. Decisive: a PM has **per-line suppliers** while a GR header is single-supplier by construction, so a header PM link would look strong while stating something weaker than the truth.
- **`purchase_material_lines.received_qty`** — see Part 3.

**`REQUIRED DECISION`** — the FK must be **`restrictOnDelete`, not `nullOnDelete`**: `Approved → Hold → Edit` is reachable and `EloquentPurchaseMaterialRepository.php:111-120` **hard-deletes and recreates** PM lines (no SoftDeletes). With `nullOnDelete` a posted receipt is silently orphaned; with RESTRICT it is a loud, correct failure.

---

## PART 3 — OPTION A BUSINESS FLOW

| Quantity | Source |
|---|---|
| **Required** | `purchase_material_lines.requested_qty`, or `COALESCE(agreed_qty, requested_qty)` — **`REQUIRED DECISION`**, must be one expression used everywhere |
| **Received** | `SUM(COALESCE(grl.net_received_quantity, grl.received_quantity))` where `grl.purchase_material_line_id = L`, joined to `goods_receipts` with `status='posted' AND deleted_at IS NULL` |
| **Remaining** | `GREATEST(0, Required − Received)` |

`COALESCE(net, received)` is mandatory (`net` is nullable for legacy rows) and matches five existing production queries. `deleted_at IS NULL` is mandatory because `GoodsReceipt` soft-deletes while `GoodsReceiptLine` does not.

### Partial-receipt walk (100 → 40 → 30) — `PASS`
- **t0:** 1 PM line (`requested_qty=100`), 0 GR lines → Received 0, Remaining **100**.
- **t1 (receive 40, posted):** +1 `goods_receipts` (`status=posted`, `purchase_order_id=NULL`), +1 `goods_receipt_lines` (`purchase_material_line_id=L`, `net=40`), +1 stock ledger row, +1 FIFO layer. PO tables untouched → Received **40**, Remaining **60**.
- **t2 (receive 30, posted):** +1 GR, +1 GR line (`L`, `net=30`); **GRL-1 unmodified**. Two rows now share `purchase_material_line_id` — exactly why no unique index may exist on it → Received **70**, Remaining **30**.

### Can Required/Received/Remaining diverge?
**Arithmetically no** — Remaining is a pure read-time function of the other two, with no stored copy. **Semantically yes, at five points** — `FINDING`:
- **D1** Required can change under a live receipt (`Approved→Hold→Edit` hard-deletes lines) → mitigated by RESTRICT, but must be a domain rule, not a 500.
- **D2 `BLOCKER`** — over-receipt is **unguarded** on the PM branch (see Part 4).
- **D3** Draft-receipt window: two operators can each draft the full Remaining (same as the PO path today; not a regression).
- **D4** `UpdateGoodsReceiptAction` deletes+recreates GR lines — must re-persist the new column or an edit silently drops the anchor.
- **D5** Required-column ambiguity if screens disagree on `requested_qty` vs `agreed_qty`.

### Completion
`PurchaseMaterialStatus::Purchasing|Receiving|Completed` exist and `nextWorkflowState()` already chains `Approved → Purchasing → Receiving → Completed`. They are **unreachable but already read** by `GetPurchaseMaterialStatsAction` — activating them turns on existing KPI arithmetic rather than requiring new code. `canHold()`/`canCancel()` already exclude all three, so a receiving Purchase cannot be held or cancelled.
**`REQUIRED DECISION`** — `nextWorkflowState()` is strictly sequential, so a single fully-satisfying receipt needs three hops or a direct assignment.

---

## PART 4 — OPTION A SIDE EFFECTS

| Area | Verdict |
|---|---|
| Existing PurchaseOrders | **PASS** — no DDL, semantics unchanged, PO-anchored receipts keep incrementing `received_qty` |
| **Existing GoodsReceipts** | **PASS** — every historical row remains valid **unchanged**: nullability widening never rewrites values, new column is nullable with no backfill, all PO-path queries keep matching |
| Supplier balances | **PASS** — fully insulated; the AP ledger reads no GR/PO column |
| Inventory | **PASS** — `ReceiveStockAction` receives no PO field; the certified path is reusable as-is |
| FIFO layers | **BLOCKER (code)** — `CreateReceiptLayersAction.php:41,57-58` dereferences `$po` with **no null-safe operator** → fatal; layer `supplier_id` must come from the PM line |
| **Supplier invoices** | **BLOCKER (certified Finance)** — `InvoiceReceiptAnchorService.php:123-127` resolves supplier as `receipt->purchaseOrder?->supplier_id` and throws `supplierMismatch` when empty → **no PM-anchored receipt could ever be invoiced** |
| **Returns** | **BLOCKER (certified)** — `ApproveSupplierReturnAction.php:252-258` uses the identical pattern → **no PM-anchored receipt could ever be returned**. Ceiling/FIFO scope themselves are unaffected (keyed on `goods_receipt_line_id`) |
| Partial receipts | **PASS** — structurally supported |
| **Duplicate receipts** | **BLOCKER** — the over-receipt guard (`PostGoodsReceiptAction.php:170-187`) locks a **PO line** and compares to `poLine->quantity`; on the PM branch it is skipped entirely. Document-level duplicate defences survive (status guard, ledger guard, `lockForUpdate`, layer UNIQUE index) |
| Cancellation / reversal | **PASS** — unchanged; no reversal path exists today and Option A creates none |
| Tenant isolation | **FINDING** — both ends are scoped, but `goods_receipts.company_id` currently prefers `$po->company_id`; on the PM path the warehouse fallback becomes the sole source and must be proven to always yield a company (else RC-6 makes the receipt invisible) |
| **Analytics / supplier reporting** | **BLOCKER (silent)** — **13 INNER `join('purchase_orders as po')` sites** (independently verified) across `GetSupplierAnalyticsQuery`, `GetProcurementHealthQuery`, `GetSupplierPriceHistoryQuery`, `GetSupplierProductDemandQuery`, `GetSupplierTimelineQuery`, `EloquentSupplierRepository`, `DemandAnalysisService`. An INNER JOIN on a NULL FK **drops the row silently** — every PM-sourced receipt would vanish from supplier analytics with no error |

---

## PART 5 — OPTION B AUDIT (hidden PM → PO bridge)

1. **Where it lives:** a new action invoked on PM approval, creating PO(s) + lines.
2. **One PM → one PO?** **No.** `purchase_orders.supplier_id` is a single **NOT NULL** header column; `PurchaseOrderLine` has no supplier column; PM suppliers are **per line**.
3. **One PM → multiple POs?** **Yes — mandatory**, one per distinct line supplier. PM lines with `supplier_id IS NULL` (allowed; approval never checks lines) have **no valid PO destination**.
4. **One PO containing multiple PMs?** Not without a further link table; nothing supports it.
5. **PM line → PO line:** product 1:1; `quantity` ← `agreed_qty ?? requested_qty` (**the PO `quantity` becomes the enforceable ceiling** while the PM screen shows `requested_qty`); `unit_price` ← `agreed_price` (nullable; decimal 15,4 → 15,2 precision loss); `lead_time_days`/`unit_label` **dropped**.
6. **Receipt quantities back to PM lines:** **no join key exists**. Requires `purchase_order_lines.purchase_material_line_id` or a link table → **Option B is NOT migration-free**.
7. **Partial receipts:** PO status advances; **PM status never moves** (no writers).
8. **Returns:** anchored on GR lines, PM-unaware; already never decrement `received_qty`.
9. **Cancellation:** an approved PO **cannot be edited, cancelled or deleted** by any existing path; PM cancel excludes `Approved`. The two lifecycles are structurally disjoint — a PM cancelled from `on_hold` leaves a fully receivable PO.
10. **Invoice matching:** 100% PO-driven (`InvoiceReceiptAnchorService` resolves company+supplier from the PO). The PM contributes nothing.

**Additional `FINDING`s:** the bridge must manufacture an `approved` PO directly, bypassing the certified draft→submitted→approved chain; `CreatePurchaseOrderAction` never writes `company_id`/`warehouse_id`, so reusing it yields NULL-company POs; `PurchaseOrder` has **no tenant global scope** and its repository filter is **fail-open**.

---

## PART 6 — OPTION B SSOT TEST

**`BLOCKER` — Option B creates two competing sources on all five axes.**

| Truth | PurchaseMaterial | PurchaseOrder | Winner |
|---|---|---|---|
| Ordered qty | `requested_qty` + `agreed_qty` | `quantity` (**enforced ceiling**) | **PO** |
| Received qty | *nothing* | `received_qty` | **PO (sole holder)** |
| Remaining | *nothing* | derived from PO line | **PO** |
| Supplier commitment | per-line, **mutable while `approved`** | header, **frozen** after approval | **PO (and they drift by design)** |
| Completion status | dead states, never written | advances automatically on receipt | **PO (only live machine)** |

**Concrete divergences:** (D1) editing a held PM hard-deletes/recreates lines with new UUIDs, orphaning the link; (D2) `select-supplier` is permitted while `approved`, so the PM's supplier drifts from the frozen PO → invoices from the new supplier are rejected `supplierMismatch` forever; (D3) a fully received PO leaves the PM at `Approved` permanently; (D4) `/api/purchase-orders` + submit/approve/cancel are **fully live**, so a PO can be created and received with **no PM at all**; (D5) the Goods Receipt form **requires the operator to pick a PO by number** — the PO is surfaced in the very screen the bridge exists to hide (plus a stale `Purchase Orders` entry in the legacy `navigation.ts`, the command palette, and a live Supplier-360 PO tab).

**Conclusion:** under Option B the **PurchaseOrder is the system of record**, and "Purchase Materials" is a lossy facade — 1→N, needing a link column that doesn't exist, over a PO that is immutable from birth. **This directly contradicts approved decision 3.**

---

## PART 7 — DOUBLE-COUNTING AUDIT

- **Opening balance + ledger opening balance:** `PASS`. `suppliers.opening_balance_amount` is read **only** for display/validation; all money columns are ledger-derived. The former double-count (`current_supplier_balance` adding the scalar on top of the ledger) was removed in Phase 1.
- **Supplier payable, GR + invoice:** `PASS` **by design** — a GR raises a **GRNI credit (GL only)**, never AP and never the supplier subledger; the invoice **debits GRNI** at the receipt's stamped valuation and credits AP, relieving the accrual exactly once. Over-invoicing is blocked by `invoiceable() = received − alreadyInvoiced`; per-invoice AP idempotency by `number = 'SI-'.$invoice->id`.
- **Purchase counted twice (PM + PO) — `FINDING`:** under **Option B**, bridge-created POs feed `active_pos_count` and the GR→PO supplier aggregates, inflating purchase *volume* metrics with phantom orders. Under **Option A**, PM-sourced receipts are *dropped* from those same aggregates (Part 4). Both distort supplier volume reporting in opposite directions; **neither distorts the payable**.
- **Inventory double-post:** prevented by `GoodsInwardAuthority` mutual exclusion (exactly one of GR/invoice may post per company). Residual, **pre-existing, affecting both options**: the mode is **not stamped on the posted document**, so flipping it between two postings re-opens double-posting; and GR resolves company from the PO while the invoice resolves from itself/warehouse, so a mismatch can authorise both.

---

## PART 8 — GOODS RECEIPT CONTRACT

**What it fundamentally requires:** warehouse, product, net qty, unit cost (snapshotted on the GR line at creation), landed-cost header, company, supplier.

**Every PO touch, classified:**
- `BUSINESS-ESSENTIAL` (2 only): `company_id` (`PostGoodsReceiptAction.php:104` — **already has a warehouse fallback in the same expression**) and `supplier_id` (`CreateReceiptLayersAction.php:58` — stamps the FIFO layer and `products.last_supplier_id`).
- `LEGACY IMPLEMENTATION COUPLING`: the three PO-status gates; the over-receipt ceiling; the `received_qty` increment; the PO status rollup; the eager-load.

**Is the PO needed per concern?** Stock ledger write — **no**. FIFO layer — only for `supplier_id`. Landed cost — **no**. Idempotency — **no** (keyed on the receipt row / ledger reference). Over-receipt guard — **yes, entirely**. PO bookkeeping — yes by definition.

**Could a different ordered-quantity source drive it?** **Yes.** Ordered quantity is read **exactly once**, for the over-receipt guard, and never reaches `StockOperationDTO`, the FIFO layer, or the Finance bridge. An alternative source that also supplies supplier + company would produce **byte-identical** ledger rows, layers, landed costs and GL postings.

**Verdict:** `FINDING` — **the PO dependency is legacy implementation coupling, not business-essential**, with two exceptions (company — already fallback-capable; supplier — must be re-homed). **Option A can reuse the certified path**; **Option B merely preserves the coupling.**

---

## PART 9 — RETURNS

- A return line references **`product_id` + `goods_receipt_line_id` only** — no PO line reference. Header `purchase_order_id`/`goods_receipt_id` are nullable and **never read by posting logic**.
- **The receipt-line anchor remains the SSOT for FIFO scope AND ceiling** (`PASS`, certified contract intact): `returnable = effectiveReceivedQty − alreadyReturned`; client-supplied `original_received_qty` is explicitly not trusted; the anchor is required and never guessed.
- Returns write inventory (FIFO consumption scoped to the receipt line + stock ledger) and **post nothing to Finance** (SR-3: no AP mutation; `credit_amount`/`debit_note_number` are unvalidated free data).
- **`FINDING` (pre-existing):** returns **never decrement `purchase_order_lines.received_qty`** — a "fully received" PO can coexist with returned stock.
- **`BLOCKER` — NEW, undocumented, cross-module:** the FIFO anchor is enforced by a **unique index** `irl_goods_receipt_line_unique` on `inventory_receipt_layers.goods_receipt_line_id`, and **only** the Goods-Receipt path writes a non-null value — the other seven layer-creating paths (including Mode-3 invoice posting) write NULL explicitly. Because `ApproveSupplierReturnAction` scopes FIFO consumption to `goods_receipt_line_id`, **setting `goods_inward_mode = supplier_invoice` (Mode 3) silently disables Supplier Returns for that company**: a return against invoice-sourced stock fails with a raw insufficient-stock error rather than a domain message. **Nothing in either module documents this interaction.** It is pre-existing, unrelated to the A/B choice, and affects any company switched to Mode 3.
- **A vs B:** anchor, ceiling and FIFO scope survive **both**. Under **A**, the **supplier guard breaks** (fails closed) unless supplier resolution is re-homed to the PM line. Under **B**, returns are untouched.

---

## PART 10 — INVOICES

- **No purchase-order reference exists anywhere on a supplier invoice** (header or line); the PO is reachable only transitively via the anchor.
- Link columns: `supplier_id`/`warehouse_id`/`company_id` **LIVE**; `auto_purchase_id` and `auto_receipt_id` **never written in production**; `goods_receipt_line_id` (line) is **mass-assignable but unvalidated**, **absent from the create rules**, and **not exposed by the resource** — and `syncLines()` deletes-and-recreates lines, so **any edit silently destroys it**.
- Valuation needs a **goods receipt LINE** only; guards resolve company then **supplier via the PO**, failing closed.
- **`BLOCKER` (pre-existing, undocumented):** in the **default** mode, a missing anchor **throws and rolls back the entire posting** (deliberately uncaught) — so an invoice created through the UI **cannot be posted at all**.
- **A vs B:** under **A-additive** (PO columns kept NOT NULL) invoice behaviour is **completely transparent**; under **A-substitutive** the supplier guard fails closed. Under **B**, nothing in invoices changes.

---

## PART 11 — HISTORICAL DATA

**`PASS` — and decisively so.** Verified by direct `COUNT(*)` on both stacks (`ecos_dev` and `ecos_erp`), soft-deletes checked:

| Table | ecos_dev | ecos_erp |
|---|---|---|
| `goods_receipts` / `goods_receipt_lines` | **0 / 0** | **0 / 0** |
| `purchase_orders` / `purchase_order_lines` | **0 / 0** | **0 / 0** |
| `purchase_materials` / lines | 2 / 2 | 0 / 0 |

**Classification:** buckets **A, B and C are all empty — there is no historical goods receipt anywhere.**
**Structural rule (governs any future customer DB):** **100% bucket B — legacy, PO-anchored, column stays NULL.** A deterministic backfill is **impossible**: no key chain exists, and the heuristic join fails on all four candidate keys (no `supplier_id` on the GR header; nullable supplier on the PM line; *requesting* vs *receiving* warehouse are different fields; product cardinality is non-unique).
**No mapping was fabricated and none should be written.** Correct posture: nullable column, **no backfill statement**, legacy rows stay NULL, and every consumer must read NULL as "legacy PO-anchored receipt".

---

## PART 12 — MULTI-TENANCY

| Model | `company_id` | Global scope | Enforcement |
|---|---|---|---|
| Supplier / PurchaseMaterial / GoodsReceipt | Yes | **Yes — fail-closed** | Model-level |
| **PurchaseOrder** | Yes | **NO** | Controller/repo only, **fail-open**, `findById` unscoped |
| PMLine / POLine / GRLine | No | No | Inherited via parent FK |

**`BLOCKER` (pre-existing):** the **existing** `goods_receipt_lines.purchase_order_line_id` FK has **zero cross-tenant protection at any layer** — `exists:purchase_order_lines,id` is a scope-blind global lookup, the create action never checks the submitted PO lines belong to the receipt's PO, and `PostGoodsReceiptAction` will **increment `received_qty` on another company's PO line**.

- **Option A** would add a **second unguarded edge** of the same class (aggravated because the *parent* `PurchaseMaterial` is scoped, so the child link looks protected). **Mitigation is mandatory:** a tenant-scoped `Rule::exists(...)` **plus** an in-action same-company assertion (FormRequests are bypassed by non-HTTP callers).
- **Option B is worse:** reusing `CreatePurchaseOrderAction` produces **NULL-company POs**, which the **fail-open** repository filter then exposes to **every** company; and the receipt's company silently reassigns to the receiving warehouse's company.
- **`FINDING`:** `purchase_material_lines.supplier_id` has **no database FK at all** — the migration used `uuid()` instead of `foreignUuid()`, so `->constrained('suppliers')` silently created nothing. This matters because Option A wants to promote that column to the supplier identity of record.

---

## PART 13 — IDEMPOTENCY

**Duplicate GR posting is prevented by four layers** (status guard → cross-document ledger guard → in-transaction `lockForUpdate` re-read + guard re-assert → shared row lock with the invoice path). There is **no posting-receipt table and no idempotency key**; the effective key is `(stock_ledger_entries.reference_type, reference_id, movement_type)`.

- **Stock ledger:** protected by **guards and locks, not a constraint** — `(reference_type, reference_id)` is a **plain index, not unique**.
- **FIFO layers:** **`PASS`** — a nullable **UNIQUE** index `irl_goods_receipt_line_unique` on `goods_receipt_line_id` (verified independently; one auditor's contrary claim cited a superseded migration). GR-sourced layers cannot duplicate; invoice-sourced layers carry NULL and are a documented deliberate gap.
- **`goods_receipt_lines`:** no uniqueness — correct, since one PO line legitimately appears on many receipts.
- **Under A:** all document-scoped defences survive unchanged (none is keyed on the PO). **But the over-receipt ceiling is lost on the PM branch** — `BLOCKER`, must be reimplemented against Required under a `lockForUpdate` on the PM line.
- **Under B:** all defences survive as-is; the bridge adds a new duplicate risk of its own (re-approval creating a second PO) that must be made idempotent.
- **Pre-existing, both options:** mode not stamped on the document; no inventory reversal path for a posted GR.

---

## PART 14 — PERFORMANCE

**`FINDING` — this is the one dimension where Option B genuinely wins, and the win is real.**

| Query | Option A | Option B |
|---|---|---|
| Ordered/Received/Remaining **per PM line** | `purchase_material_lines` → `goods_receipt_lines` → `goods_receipts` — **2 joins + `SUM`/`GROUP BY`** | **1 join, no aggregation** if it reads the maintained counter `purchase_order_lines.received_qty`; **4 joins + `SUM`** for the receipt-level drill-down |
| **Receiving work queue** | `GROUP BY … HAVING SUM(required) > SUM(received)` — **`HAVING` cannot use an index**; every candidate must be materialised before filtering, and pagination sits on top of that | `WHERE pol.quantity > pol.received_qty` — **index-friendly, `WHERE`-filterable, paginates cleanly**. This is byte-for-byte the shape already shipped in `ExpectedIncomingQuery.php:47-56` |
| Extra reconciliation queries | none | **yes, three classes** — PM→N POs roll-up; drift reconciliation between two independently mutable documents; orphan detection when the bridge half-fails |
| New index needed | `goods_receipt_lines.purchase_material_line_id` (+ a composite on `goods_receipts(status, deleted_at)`) | `purchase_materials.purchase_order_id` and `purchase_material_lines.purchase_order_line_id` |

**Honest reading:** B's cheap path exists *because* it reads a denormalised running counter that the PO already maintains — the very stored-counter pattern Part 2.4 rejects for the PM side (no reversal path; already diverges under returns). So B trades **query cost** for **a second mutable source of truth**. A pays a grouped aggregate per queue render — batchable via `withSum`, exactly as `EloquentPurchaseMaterialRepository.php:24-25` already does on this table — and can never diverge.

Performance is **not** the deciding factor, but the earlier claim that "B is strictly more expensive" was **wrong** and is corrected here: for the worklist query B is cheaper; for per-line drill-down and for total system complexity A is cheaper.

---

## PART 15 — API / UI / TEST IMPACT

### Option A
**REQUIRED — backend:** `PostGoodsReceiptAction` (null-safe PO, PM-branch over-receipt guard, PM status advance); `CreateReceiptLayersAction` (null-safe `$po`, supplier from PM line); `CreateGoodsReceiptAction` / `UpdateGoodsReceiptAction` (accept + persist + re-persist the PM anchor, company without PO); `StoreGoodsReceiptRequest` / `UpdateGoodsReceiptRequest` (PM anchor rules, tenant-scoped `exists`, XOR invariant); `GoodsReceiptDTO` / `GoodsReceiptLineDTO` (nullable PO fields); `GoodsReceiptResource`; `EloquentGoodsReceiptRepository` (supplier filter/search no longer PO-only); **`InvoiceReceiptAnchorService`** and **`ApproveSupplierReturnAction`** (supplier fallback to the PM line) — *both certified*; **the 13 INNER `join('purchase_orders as po')` analytics sites** (LEFT JOIN + PM fallback).
**REQUIRED — API:** `POST /goods-receipts` (accept a PM anchor); **new** read endpoint for the receiving work queue (none exists today).
**REQUIRED — frontend:** Receiving Center (registry → work queue), goods-receipt create form + header fields (PO picker → Purchase picker), purchases page/drawer (Ordered/Received/Remaining columns).
**REQUIRED — tests:** `GoodsReceiptTest`, `GoodsReceiptConcurrencyTest`, `InboundOwnershipContractTest`, `InboundOwnershipHttpTest`, `InboundCrossDocumentConcurrencyTest`, `InvoiceReceiptAnchorTest`, `SupplierInvoiceFinancialPostingTest`, `SupplierReturnValuationTest`, `PurchaseMaterial*` suites, `SupplierAnalyticsTest`.
**OPTIONAL:** header `purchase_material_id`; retiring the legacy PO UI; a `CHECK` constraint for the XOR.

### Option B
**REQUIRED — backend:** new bridge action + link column/table; `CreatePurchaseOrderAction`/DTO (write `company_id`/`warehouse_id`); PM immutability guards (block `UpdatePurchaseMaterialAction`, `SelectLineSupplierAction`, and the Hold-from-Approved loophole once a PO exists); a PO→PM status/`purchased_value` propagation path; revoke `purchasing.purchase_orders.*` write routes; `PurchaseOrder` tenant scope + fail-closed repository.
**REQUIRED — frontend:** purchases page/drawer read-through of PO quantities; **the GR form must stop asking for a PO number** (otherwise the "hidden" PO is visible); remove the stale `navigation.ts` PO entry, command-palette entry and Supplier-360 PO tab.
**REQUIRED — tests:** the same GR/Inbound suites **plus** new bridge cardinality/idempotency/divergence suites.
**OPTIONAL:** none — every item above is load-bearing for the facade to hold.

`FINDING` — Option B's required list is **longer**, and five of its items exist solely to *prevent the user from noticing the PurchaseOrder*. Its genuine offsets: **no new API route**, and the whole GR/Inventory/Finance/Returns test surface stays valid (Part 18).

### The Receiving Center today — `FINDING`
It is a **registry of receipts that already exist, not a to-receive worklist**. Its only data source is `GET /api/goods-receipts`; every column is a property of an existing receipt; its filters are receipt-lifecycle filters (`draft`/`posted`); its KPIs are computed from the **loaded page**, not from outstanding work; and "New Receipt" opens a **blank form** where the operator must already know which PO to pick. Its i18n file even carries an aspirational six-state inspection workflow (`expected`/`arrived`/`in_inspection`/…) that **exists in no enum and no component**.

### Minimal work-queue endpoint (both options need exactly one)
```
GET /api/receiving/work-queue   [permission:purchasing.goods_receipts.view]
  ?warehouse_id&supplier_id&search&overdue&page&per_page&sort_by&sort_dir
→ items[]: { source_type: 'purchase_material'|'purchase_order', source_id, source_number,
             supplier{}, warehouse{}, expected_date, is_overdue,
             open_lines_count, ordered_qty, received_qty, remaining_qty, last_receipt_date }
```
One aggregated read endpoint. **No new write route**, no change to `POST /goods-receipts/{id}/post`, and no schema beyond the anchor column each option already needs. Drill-down can reuse the existing create form with the source prefilled.

---

## PART 16 — MIGRATION RISK

### Option A
- **Capability:** attribute a receipt line to a Purchase line.
- **Why required:** proven non-derivable (Part 2).
- **Schema impact:** 1 new nullable FK column + 2 nullability widenings.
- **Data impact:** **zero** — no backfill, no value rewrite; **and both tables are empty in every environment inspected**.
- **Rollback:** drop column/FK/index; restore NOT NULLs. Clean **while** no PM-anchored rows exist; destructive afterwards (pre-adoption escape hatch only).
- **Deployment risk:** MySQL 8.4 executes the nullability change as `ALGORITHM=COPY` — a lock window proportional to table size, which is currently **0 rows**. **This is the lowest-risk window this change will ever have.**

### Option B (no *schema* migration ≠ no risk)
- **Capability:** keep receipts PO-anchored while presenting PurchaseMaterials.
- **Schema impact:** still needs a link column (so the "migration-free" claim is false).
- **Data impact:** **permanent and ongoing** — every approved Purchase manufactures 1..N synthetic `purchase_orders` rows that appear in supplier analytics, health scores and demand as genuine orders. Unlike a schema change, this cannot be rolled back — the rows are business data.
- **Rollback:** none meaningful.
- **Deployment risk:** low at deploy, **high in operation** (five divergence paths, immutable POs, live PO API).

---

## PART 17 — DECISION MATRIX

| # | Dimension | Option A | Option B |
|---|---|---|---|
| 1 | Purchase Material as SSOT | **LOW** — PM lines hold Required; Received derives from receipts attributed to them | **HIGH** — PO holds every actionable quantity; PM is a facade |
| 2 | Receipt attribution | **LOW** — explicit stated FK, a lookup not a guess | **MEDIUM** — indirect via PO line; needs its own link column |
| 3 | Partial receipt correctness | **LOW** — many GR lines per PM line, derived sum | **MEDIUM** — correct on the PO, must be reconciled back across N POs |
| 4 | Supplier balance safety | **LOW** — AP ledger untouched | **LOW** — AP ledger untouched (but volume metrics inflated by phantom POs) |
| 5 | Invoice reconciliation | **HIGH** — supplier guard fails closed until re-homed (certified service) | **LOW** — unchanged |
| 6 | Returns | **HIGH** — identical supplier guard fails closed until re-homed | **LOW** — unchanged |
| 7 | Historical data | **LOW** — zero rows exist; no backfill needed or possible | **LOW** — none touched |
| 8 | Tenant isolation | **MEDIUM** — adds a second unguarded FK edge; mitigable with scoped `exists` + in-action assertion | **HIGH** — NULL-company POs + fail-open filter + silent company reassignment |
| 9 | Idempotency | **HIGH** — over-receipt ceiling lost on the PM branch until reimplemented | **LOW** — ceiling intact; new bridge-duplication risk to close |
| 10 | Complexity | **MEDIUM** — ~8 backend touch points, enumerable | **HIGH** — bridge + 5 suppression mechanisms + propagation |
| 11 | Performance | **MEDIUM** — 2 joins for drill-down, but the work queue needs `GROUP BY … HAVING` (not index-filterable) | **LOW for the work queue** (index-friendly `WHERE`, reusing the shipped `ExpectedIncomingQuery` shape); **MEDIUM overall** once the three reconciliation classes are counted |
| 12 | Migration risk | **LOW** — additive, zero rows, best window available | **MEDIUM** — still needs a link column; permanent synthetic business data |
| 13 | Legacy coupling | **LOW** — removes it | **HIGH** — entrenches it permanently |
| 14 | Future extensibility | **LOW** — PM becomes a real anchor; dead statuses/KPIs activate | **HIGH** — every future feature must round-trip the PO |
| 15 | Auditability | **LOW** — one document chain, explicit anchor | **HIGH** — two parallel documents that can disagree, with no reconciliation |

---

## PART 18 — RECOMMENDATION

### `RECOMMENDATION`: **OPTION A**, conditional on the four decisions in Part 19 being approved.

**Why it preserves Purchase Materials as the real workflow rather than resurrecting Purchase Orders:** Option A makes `purchase_material_lines` the thing a receipt *points at*, so Required lives on the Purchase, Received derives from receipts attributed to the Purchase, Remaining is a pure function of the two, and Completion is computed from physical events on the Purchase's own already-defined (and already-read) status enum. Nothing needs to consult a PurchaseOrder to answer any question the business asks. Option B does the opposite: it keeps every enforceable quantity on the PO, requires a link column *anyway*, needs five separate mechanisms whose only purpose is to stop the user noticing the PO, and still leaves the PO reachable through a live API and visibly required in the receiving form. Under B, "Purchase Materials" would be a label; the PurchaseOrder would remain the system of record — **precisely what approved decision 3 prohibits**.

**Three findings make A materially safer than the Phase-1 report assumed:**
1. **There is no historical data** — 0 goods receipts and 0 purchase orders in every environment inspected. The migration's data risk is not "low", it is **nil**, and this window will not reopen.
2. **The PO dependency in the certified inventory path is coupling, not business logic** — ordered quantity never reaches the ledger, the FIFO layer, the landed cost or the GL. Only *supplier* and *company* are load-bearing, and company already has a fallback.
3. **Option B is not migration-free either**, which removes its single strongest advantage.

**Honest statement of A's cost:** three `BLOCKER`s must be closed *before* it can work — the supplier guard shared by invoices and returns, the missing over-receipt ceiling, and the 13 silent INNER JOINs. All three are enumerable, testable, and none requires inventing accounting.

**Option B's genuine advantages, stated fairly** (they are real, and they are the reason this had to be audited rather than assumed):
- It requires **no new API route** for receiving — `POST /goods-receipts` and `/post` keep working untouched.
- It leaves the **entire GR / Inventory / Finance / Returns test surface unaffected** (`GoodsReceiptTest`, the three Inbound suites, `InvoiceReceiptAnchorTest`, `SupplierReturnValuationTest`, `SupplierInvoiceFinancialPostingTest` …), because the shape of a receipt never changes.
- `InvoiceReceiptAnchorService` and `ApproveSupplierReturnAction` — both certified — are **not touched at all**, so RD-1 disappears.
- Its **work-queue query is cheaper and index-friendly** (Part 14).

**Why these do not change the recommendation:** every one of them is a *transitional* saving, purchased by making the PurchaseOrder permanently the system of record — the exact outcome approved decision 3 prohibits. They are also partly illusory: B still needs a migration, still needs the receipt form to stop asking for a PO number (touching the same frontend files A touches), and adds five suppression mechanisms whose only purpose is to keep the user from noticing the PO. A's costs are one-time and bounded; B's costs are permanent and compounding.

**Not recommending DEFER**, because deferral does not make the decision cheaper — it only risks losing the zero-data migration window.

---

## PART 19 — PHASE 2 IMPLEMENTATION PLAN (proposed; not started)

**Four `REQUIRED DECISION`s before any code:**
- **RD-1** Re-home supplier identity for PM-anchored receipts to `purchase_material_lines.supplier_id` — this **modifies two certified services** (`InvoiceReceiptAnchorService`, `ApproveSupplierReturnAction`). It also requires making that column **mandatory at the receiving boundary** and **repairing its missing FK**.
- **RD-2** `Required = requested_qty` or `COALESCE(agreed_qty, requested_qty)` — one expression, everywhere.
- **RD-3** Is "Received" gross-of-returns (consistent with today's PO counter) or net-of-returns? If net, it is a *second* number, not a redefinition.
- **RD-4** Completion hops: three sequential `nextWorkflowState()` calls, or a direct assignment when one receipt fully satisfies the Purchase.

| Phase | Content | Independently browser-verifiable outcome |
|---|---|---|
| **2A** | Migration (3 changes) + XOR invariant + tenant-scoped validation | Existing PO receiving still works end-to-end, unchanged |
| **2B** | Null-safety + supplier/company re-homing (RD-1) + PM-branch over-receipt guard | A PM-anchored receipt posts inventory; over-receipt is refused |
| **2C** | Derived Required/Received/Remaining + receiving work-queue endpoint | Purchase screen shows correct quantities; queue lists what needs receiving |
| **2D** | Completion transitions (RD-4) + activate the existing KPI counters | Partial receipt keeps the Purchase open; final receipt completes it and clears the queue |
| **2E** | The 13 analytics joins → LEFT + PM fallback | Supplier analytics/health include PM-sourced receipts |
| **2F** | Invoice + return paths against PM-anchored receipts | An invoice posts and a return approves against a PM receipt |
| **2G** | UI: Receiving Center work queue, receipt form Purchase picker, purchase drawer columns | The full Section-20 walkthrough |

---

## PART 20 — BROWSER VERIFICATION PLAN (designed, not executed)

1. Create Purchase Material → 2. Add lines (product, qty, supplier) → 3. Confirm/approve → 4. Receiving Center lists it → 5. Open it; see Product / Ordered / Invoiced / Previously Received / Current / Remaining → 6. Receive **partial** (40 of 100) → 7. Verify Received = 40, Remaining = 60, Purchase still open → 8. Verify inventory rose by **exactly 40** (stock ledger + FIFO layer) → 9. Verify supplier balance **unchanged** by receiving → 10. Receive the remaining 60 → 11. Verify Completion; Purchase leaves the active queue → 12. Verify it remains in Supplier 360 history (Purchases / Invoices / Goods Receipts / Financial) → 13. Attempt over-receipt → refused → 14. Post an invoice against the receipt; verify payable increases once → 15. Approve a return against the receipt; verify inventory decreases and the ceiling is enforced → 16. Reload every screen; verify persistence → 17. Switch company; verify none of it is visible.

**No business data is to be manufactured now.**

---

## PART 21 — STOP CONDITIONS

| Condition | Triggered? |
|---|---|
| Undocumented contract in the current architecture | **YES — `BLOCKER`.** (a) The V-5 invoice anchor is **unwritable via the API and unreadable from the resource**, and default-mode posting **throws and rolls back** without it → invoices created in the UI cannot be posted. (b) `auto_purchase_id`/`auto_receipt_id` are dead columns. (c) `purchase_orders.company_id`/`warehouse_id` are never written. (d) `purchase_material_lines.supplier_id` has **no FK** despite `->constrained()`. (e) **`goods_inward_mode = supplier_invoice` silently disables Supplier Returns** (Mode-3 layers carry a NULL FIFO anchor; returns scope consumption by it) — documented in neither module. |
| A or B requires a third architecture | **NO.** |
| Historical data cannot be safely classified | **NO** — the population is empty; the structural rule is 100% bucket B, no backfill. |
| Supplier balance can be double-counted | **NO** for the payable (`PASS`). **YES for supplier *volume* metrics** under both options, in opposite directions. |
| Inventory posting cannot be safely attributed | **NO** — but only if the PM-branch over-receipt guard is implemented (`BLOCKER` if skipped). |
| Invoice reconciliation cannot be preserved | **CONDITIONAL** — preserved under B and under A-additive; **broken under A-substitutive until RD-1**. |
| Tenant isolation cannot be guaranteed | **YES — `BLOCKER` (pre-existing).** The existing GR→PO-line FK has zero cross-tenant protection at any layer, and `PostGoodsReceiptAction` will increment a foreign company's PO line. Option A must not replicate this; Option B worsens it. |
| Either option requires changing certified Finance behavior | **YES for Option A — `REQUIRED DECISION` (RD-1):** the supplier resolution inside `InvoiceReceiptAnchorService` (certified V-5) and `ApproveSupplierReturnAction` (certified SR-1) must change. **No** for Option B. |
| Either option requires modifying unrelated modules | **NO** — all changes are within Purchasing + its Finance/Inventory seams. Preparation, Distribution, Zones, Loading, Logistics, Orders are untouched. |

---

## PART 22 — SUMMARY

**RECOMMENDATION: Option A**, conditional on RD-1…RD-4 and on closing the three blockers (supplier guard, over-receipt ceiling, 13 analytics joins). It is the only option under which PurchaseMaterial genuinely becomes the system of record; Option B would make PurchaseOrder the system of record under a PurchaseMaterial label, needs a migration anyway, and is worse on tenancy, auditability, complexity and extensibility.

**The decisive, non-obvious facts:** there is **no historical data at all** (so the schema window is free and will not reopen); the PO dependency in the certified inventory path is **coupling, not business logic**; and Option B's "no migration" advantage **does not exist**.

**No implementation. No migration. No API change. No business-data change. No commit.**
