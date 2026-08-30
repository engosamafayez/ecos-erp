# TASK-PROC-PURCHASING-END-TO-END-CYCLE-ARCHITECTURE-AUDIT-001 — REPORT

**Date:** 2026-08-21 · **Type:** architecture / contract audit, read-only
**Classification:** **C. BLOCKED** — 3 code blockers + 1 data prerequisite (§24)
**Changed:** nothing. No code, schema, migration, API, UI, business data. No commit.

---

## 1. The complete lifecycle, as actually implemented

| Step | Entity | Service / Action | API | Frontend | Tables | Ledger | Inventory | Supplier balance |
|---|---|---|---|---|---|---|---|---|
| A. Purchase Material | `PurchaseMaterial` | `CreatePurchaseMaterialAction` | `POST /purchase-materials` | `/purchasing/purchases` | `purchase_materials` | — | — | — |
| B. Supplier selection | `PurchaseMaterialLine` | `SelectLineSupplierAction` | `POST /purchase-materials/{id}/lines/{line}/select-supplier` | drawer → Supplier tab | `purchase_material_lines` | — | — | — |
| C. Invoice creation | `SupplierInvoice` | `SupplierInvoiceController::store` | `POST /supplier-invoices` | `/purchasing/supplier-invoices` | `supplier_invoices`, `supplier_invoice_lines` | — | — | — |
| D. Invoice posting | — | `PostSupplierInvoiceService` | `POST /supplier-invoices/{id}/post` | invoice page | + `finance_supplier_bills` | **yes** | Mode 3 only | **yes** |
| E. Receipt creation | `GoodsReceipt` | `CreateGoodsReceiptAction` | `POST /goods-receipts` | PM drawer → Receiving | `goods_receipts`, `goods_receipt_lines` | — | — | — |
| F. Receipt posting | — | `PostGoodsReceiptAction` | `POST /goods-receipts/{id}/post` | Receiving tab / Center | — | via bridge | **yes** | — |
| G. Inventory | `InventoryItem` | `ReceiveStockAction` | — | — | `inventory_items`, `stock_ledger_entries` | — | **yes** | — |
| H. FIFO | `InventoryReceiptLayer` | `CreateReceiptLayersAction` | — | — | `inventory_receipt_layers` | — | **yes** | — |
| I. Payable | `SupplierBill` | `AccountsPayableService::createDocument/postDocument` | via D | — | `finance_supplier_bills` | **yes** | — | **yes** |
| J. Balance | — | `SupplierLedgerService` | `GET /finance/ap/suppliers/{id}/ledger` | Supplier 360, AP page | `finance_supplier_ledger_entries` | read | — | **source** |
| K. Payment creation | `SupplierPayment` | `AccountsPayableService::createPayment` | `POST /finance/ap/payments` | **NONE** | `finance_supplier_payments` | — | — | — |
| L. Payment posting | — | `approvePayment` → `postPayment` | `PATCH /{uuid}/approve`, `/post` | **NONE** | + ledger entry | **yes** | — | **yes** |
| M. Reconciliation | — | `SupplierLedgerService::balance/statement` | `GET /finance/ap/aging`, `/suppliers/{id}/ledger` | AP page (read) | — | read | — | read |

## 2. Canonical financial sources

| Value | Source of truth | Calculation | Posted by |
|---|---|---|---|
| Purchase Material value | `purchase_material_lines.agreed_price × required_qty` | `PurchaseMaterialReceivingService` | — |
| Invoice value | `supplier_invoice_lines` (`quantity × unit_price` + tax − discount) | `SupplierInvoiceController::syncLines` | — |
| Received value | `goods_receipt_lines.landed_unit_cost × effectiveReceivedQty()` | stamped at posting | `PostGoodsReceiptAction` |
| **Payable** | `finance_supplier_ledger_entries` | `SupplierLedgerService::outstandingPayable()` | `AccountsPayableService` **only** |
| Opening balance | `finance_supplier_ledger_entries` (`opening_payable`) | `SupplierOpeningBalanceService` | same |
| Advance | `finance_supplier_ledger_entries` (`advance`) | `availableAdvance()` — own bucket | same |
| Payment | `finance_supplier_payments` + ledger entry | `postPayment()` | same |
| **Supplier balance** | `finance_supplier_ledger_entries` | `SupplierLedgerService::balance()` | derived, never stored |

**Single-writer proof.** A codebase-wide sweep shows `SupplierLedgerEntry::create(...)` in exactly two
files — `AccountsPayableService` (bill + payment) and `SupplierOpeningBalanceService` (opening).
Nothing else writes the supplier ledger. `PostGoodsReceiptAction` contains no payable, GRNI,
`SupplierBill` or `SupplierLedger` reference at all.

### FLAGGED — the same figure computed twice (not fixed)

**F-1 · "Outstanding" has two independent implementations.**
- `SupplierLedgerService::outstandingPayable()` (PHP, ledger)
- an inline `selectRaw` in `EloquentSupplierRepository` that the code itself documents as mirroring
  it "EXACTLY", batched for the grid.

Same intent, two implementations, no shared test pinning them together — a drift risk.

**F-2 · Two different numbers both called "outstanding" reach the same screen.**
- `SupplierResource.total_outstanding` / `current_supplier_balance` → **ledger-derived** (correct)
- `SupplierResource.outstanding_balance` → `gr_agg.total_invoiced − gr_agg.total_paid`, built from
  hand-entered goods-receipt scalars over an **INNER JOIN to `purchase_orders`**

Supplier 360 renders its "Outstanding" KPI from `analytics.outstanding_balance`
(`GetSupplierAnalyticsQuery`, PO-joined), while the grid's money columns come from the ledger. For a
Purchase-Material supplier these disagree by construction: the PO-joined figure is **always 0**.

## 3. Invoice relationship

| Related to | How |
|---|---|
| Purchase Material | `supplier_invoices.auto_purchase_id` → `PurchaseMaterial` (nullable, optional) |
| PM Line | **no direct link** |
| Supplier | `supplier_id`, **required** |
| Goods Receipt | header `auto_receipt_id`; **line** `supplier_invoice_lines.goods_receipt_line_id` ← the financially meaningful anchor |
| Purchase Order | **none** — no PO column on the invoice at all |
| Finance ledger | `PostSupplierInvoiceService` → `AccountsPayableService` → `SupplierBill` + `SupplierLedgerEntry` |

**Critical question — can a Purchase Material create/post a Supplier Invoice without a Purchase
Order?**

**Creation: YES.** `StoreSupplierInvoiceRequest` requires only `supplier_id`, `warehouse_id`,
`invoice_date`, `lines[]`. No PO field exists.

**Posting: NO — blocked.** Two independent blockers, §5 and §14.

## 4. Receipt ↔ Invoice

Actual current behaviour, not invented:

| Question | Answer |
|---|---|
| Does receiving create payable? | **No.** `PostGoodsReceiptAction` raises no AP document and writes no supplier ledger entry. |
| Does receiving reference an invoice? | No. `goods_receipts` carries `supplier_invoice_number` and `invoice_total_amount` as **descriptive scalars only** — no FK, no AP effect. |
| Independent? | Yes — separate documents, separate authorities. |
| Invoice required before receipt? | No. |
| Receipt before invoice? | **Yes — this is the Mode 1 design.** The invoice settles a receipt that already exists. |
| Partial receipt against an invoice? | Yes. The ceiling is per receipt line: `invoiceable = effectiveReceivedQty − alreadyInvoiced(posted only)`. |
| Invoice before receipt? | Only under Mode 3 (`goods_inward_mode = supplier_invoice`), where the invoice **is** the inbound. This company is Mode 1 (`goods_inward_mode = goods_receipt`). |

## 5. Payable creation — **ONE path, but unreachable for Purchase Materials**

Payable is created **only** by `PostSupplierInvoiceService` → `AccountsPayableService::createDocument()`
+ `postDocument()`. Idempotency: `SupplierBill.number = 'SI-'.$invoice->id`, checked before insert.
**No second path exists** — Purchase Material, Goods Receipt and Finance posting create none.

That is the good news. The blocker is that this single path cannot run for a PM-anchored receipt.

### BLOCKER B-1 — the invoice anchor resolves its supplier from the Purchase Order only

`InvoiceReceiptAnchorService::resolve()`:

```php
// line 115 — company HAS a fallback
$anchorCompany = (string) ($receipt->purchaseOrder?->company_id ?? $receipt->warehouse?->company_id ?? '');

// line 123 — supplier has NONE
$anchorSupplier = (string) ($receipt->purchaseOrder?->supplier_id ?? '');
if ($anchorSupplier === '' || $anchorSupplier !== (string) $invoice->supplier_id) {
    throw InvoiceAnchorValidationException::supplierMismatch($anchorId);
}
```

A PM-anchored receipt has `purchase_order_id = NULL` (GR-00001 confirmed), so `purchaseOrder` is
null, `$anchorSupplier` is `''`, and **every** attempt throws `supplierMismatch`. `basisFor()` calls
`resolve()` per positive-quantity line, and the exception is deliberately uncaught, so the whole
invoice posting rolls back.

The data needed is already present and already the certified RD-1 source:
`GoodsReceiptLine::purchaseMaterialLine()` exists and `purchase_material_lines.supplier_id` is
populated. The service simply never consults it. **Category B.**

### BLOCKER B-2 — the invoice API exposes no validated anchor field

`StoreSupplierInvoiceRequest` validates `product_id`, `quantity`, `unit_price`, `tax_rate`,
`discount_amount`, `uom_*`, `notes`. **`goods_receipt_line_id` is absent.**

Precisely stated: the field *is* in `SupplierInvoiceLine::$fillable`, and `syncLines()` uses
`$request->input('lines')` (raw) with `array_merge`, so a client that sent the key **would** have it
persisted. But it is unvalidated — no `exists:`, no tenant scoping, no type check — so it is an
accidental passthrough, not a contract. The frontend never sends it (`grep` → 0 matches in
`features/supplier-invoices`). **Category B.**

## 6. Supplier balance — architecturally sound

`Supplier Balance = Σ finance_supplier_ledger_entries.amount`, computed by
`SupplierLedgerService::balance()`. Payable excludes advances; advance is its own bucket.

| Double-count risk | Guard | Evidence |
|---|---|---|
| Opening balance counted twice | `suppliers.opening_balance_amount` is onboarding **input**, never added to the balance | `SupplierResource` exposes it as `opening_balance*`; money columns read `ledger_*` only. The old defect (adding the scalar on top) is documented as removed. |
| Advance folded into payable | `outstandingPayable()` filters `entry_type <> 'advance'` | `SupplierLedgerService:33`, mirrored in the grid SQL |
| Purchase Material counted | PM writes no ledger entry at all | no ledger reference in the PM module |
| Goods Receipt counted | receipt writes no ledger entry | `PostGoodsReceiptAction` — zero AP references |
| Invoice counted twice | `SupplierBill.number = 'SI-{id}'` uniqueness check before insert | `PostSupplierInvoiceService:269` |
| Payment counted twice | status guard + `PostingCoordinator` idempotency on `payment:{uuid}` | `postPayment()` |

**Verified against live data:** `finance_supplier_ledger_entries` = **0 rows** for this supplier, so
the current balance is genuinely 0 — nothing has been double-counted because nothing has posted.

## 7. Payment lifecycle — implemented, and stronger than a screen

`createPayment` (Draft) → `approvePayment` (Approved) → `postPayment` (Posted).

- **Posting:** `Dr AP control / Cr funding account` through `PostingCoordinator`, plus one
  `SupplierLedgerEntry` of type `Payment` with a negative sign.
- **Segregation of duties:** `approvePayment` throws `approverCannotBeMaker()` when
  `created_by === approverId`. **Two distinct users are required.**
- **Partial payment:** supported — amount is free-form, not tied to a bill total.
- **Overpayment:** not blocked at payment level; the ledger simply goes negative (a debit balance).
- **Duplicate protection:** status guards (`documentAlreadyPosted`, `documentVoided`,
  `paymentNotApproved`) + coordinator idempotency key `payment:{uuid}`.
- **Reversal:** a `Void` status exists in the enum; no void/reversal action was found in the AP
  service. **Not implemented.**

## 8. Payment ↔ Invoice — **C. Both**

Payment is created at **supplier-account level** (`createPayment(companyId, supplierId, amount, …)` —
no bill reference). Allocation to specific bills is a **separate, optional** step:
`AllocationEngine::allocatePayment()` / `autoAllocatePayment()` writing `PaymentAllocation`
(`payment_id` → `supplier_bill_id`), exposed as `POST /finance/ap/payments/{uuid}/allocate` and
`/auto-allocate` under `permission:finance.allocation.manage`.

The supplier **balance** derives from the ledger and is therefore correct with or without
allocation; allocation drives bill-level settlement and aging, not the balance.

## 9. Purchase Order dependency sweep

| Site | Class |
|---|---|
| `InvoiceReceiptAnchorService:123` — supplier from PO only | **B — blocks the PM flow** |
| `ApproveSupplierReturnAction:252` — supplier from PO only | **B — blocks PM returns** (§10) |
| `UpdateGoodsReceiptRequest:29` — `purchase_order_id` **required** on update | **B** — a PM-anchored receipt cannot be edited through the API |
| `EloquentGoodsReceiptRepository:48` — supplier filter via `whereHas('purchaseOrder')` | **B/C** — PM receipts vanish when filtering receipts by supplier |
| `EloquentSupplierRepository:29` — `gr_agg` INNER JOIN | **C** — supplier grid purchase volume excludes PM receipts |
| `GetSupplierAnalyticsQuery` ×5, `GetProcurementHealthQuery` ×4, `GetSupplierTimelineQuery` ×1, `DemandAnalysisService` ×3 | **C** — analytics exclusion (§19) |
| `InvoiceReceiptAnchorService:115` — company **with** warehouse fallback | **A** — correct legacy compatibility |
| `GoodsReceipt::purchaseOrder()`, `GoodsReceiptResource`, `CreateGoodsReceiptAction` PO branch, `StoreGoodsReceiptRequest` XOR | **A** — legitimate, null-safe |
| `ExpectedIncomingQuery:48` — `purchase_order_lines → purchase_orders` | **D** — PO-native by definition |

## 10. Supplier Returns — same defect, same shape

- Returns **do** originate from receipts: `supplier_return_lines.goods_receipt_line_id` is the
  canonical identity (SR-2), refused when absent.
- Returns **do not require a PO** structurally…
- …but `ApproveSupplierReturnAction:252` resolves the line's supplier as
  `$line->goodsReceipt?->purchaseOrder?->supplier_id ?? ''` and throws when it does not match.
  **A return against a PM-anchored receipt line can never be approved.** Identical root cause to B-1.
- FIFO attribution itself is correct (certified `SupplierReturnValuation`).
- **Supplier balance is not affected by returns today**: `credit_method` (`credit_note|refund|
  replacement`) is stored, but no AP document or ledger entry is raised. A credit note is not posted.
- `goods_inward_mode` governs which document may post stock; it does not gate return availability.

## 11. Inventory / FIFO isolation — **confirmed**

The intended separation holds:

- **Invoice → financial only** under Mode 1: `invoiceMayPost()` returns false, and the log records
  "financial document only"; inventory, FIFO, ledger and cost propagation are all skipped.
- **Receipt → inventory / FIFO only**: no payable, no supplier ledger.
- **Payment → financial settlement only**: no inventory reference.

Double-posting is additionally guarded by `InboundPostingGuard` (shared reference) and a shared row
lock, so a receipt and a linked invoice contend on one mutex.

## 12. Full-cycle numeric scenario (design only — nothing executed)

Using the real PM-00002 line and the rules found in code. **This exposes a prerequisite:**

| Step | Value | Rule |
|---|---|---|
| Purchase Material | 100 units, `agreed_price` **must be set** (currently NULL) | RD-2 `COALESCE(agreed_qty, requested_qty)` |
| Invoice | 100 units × agreed price | free-form; tax only if a tax code is configured |
| Receipt | 40 units at `landed_unit_cost` | RD-3 |
| Receipt valuation | `40 × landed_unit_cost` | `InvoiceReceiptAnchorService::receiptValuation()` |
| Invoice line (settling) | ≤ 40 units — the ceiling is `received − alreadyInvoiced` | SR-2 applied to AP |
| GRNI cleared | receipt valuation | `postSupplierPayable()` |
| PPV | `invoiceValue − receiptValue` | posted only when \|variance\| > 0.0001 |
| Payable raised | invoice net + VAT | `AccountsPayableService` |
| Payment | any partial amount | account-level |
| Final balance | `opening + bills − payments` | `SupplierLedgerService::balance()` |

**Worked example** — agreed price 25.00, receive 40, invoice those 40 at 25.00, pay 500:

```
Receipt      : 40 × 25.00 = 1,000.00 into Inventory + FIFO layer
GRNI accrued : Cr GRNI 1,000.00        (Dr Inventory 1,000.00)
Invoice      : 40 × 25.00 = 1,000.00
  Dr GRNI 1,000.00 / Cr Trade Payables 1,000.00 ; PPV = 0.00
Payable      : 1,000.00
Payment 500  : Dr AP 500 / Cr Cash 500 ; ledger −500
Final balance: 1,000 − 500 = 500.00 ; Remaining to receive = 60
```

An invoice for all 100 units **cannot post** — the anchor ceiling refuses quantity above the 40
received. Invoicing must follow receiving, line by line.

## 13. Real-data feasibility

| Requirement | Status |
|---|---|
| Purchase Material with supplier | ✅ PM-00002 |
| Posted receipt to anchor | ✅ GR-00001, 40 units |
| Supplier | ✅ 398830 |
| Finance account roles | ✅ 44 roles provisioned for this company |
| Posting rules | ✅ 44 rules |
| **Agreed price on the PM line** | ❌ **NULL — see D-1** |
| **Receipt valuation** | ❌ **0.00 — see D-1** |
| Supplier ledger | empty (0 rows) — clean slate, not an error |
| Second user for payment approval | ❓ unverified — required by segregation of duties |

### D-1 — the existing receipt is valued at zero (data prerequisite, not a code defect)

Traced end to end on live data:

```
purchase_material_lines.agreed_price  = NULL
goods_receipt_lines.unit_price        = 0.00
goods_receipt_lines.landed_unit_cost  = 0.0000
inventory_receipt_layers.landed_unit_cost = 0.0000   ← 40 units capitalised at zero
finance_journal_entries               = 0 rows
finance_posted_event_receipts         = 0 rows
```

`config('finance.integration.auto_subscribe')` is **true** — the bridge is live. The event
`inventory.stock.received` **is** mapped in `EventPostingCatalog` (line 94). Nothing posted because
the translation drops an event it cannot value: *"quantity without a cost cannot be valued here"*.

**Consequence:** GR-00001 accrued **no GRNI**. If an invoice were posted against it today, it would
clear a GRNI that was never raised and book the entire invoice value as Purchase Price Variance.

**Legitimate operator action required before the real cycle** — no fabrication needed: set
`agreed_price` on the Purchase Material line through the existing **Supplier tab → Confirm Supplier**
(which already accepts `agreed_price`), then receive. The remaining 60 units are available for a
correctly-valued receipt. GR-00001's 40 units at zero cost cannot be revalued without a reversal
mechanism, which does not exist.

## 14. Browser path

| Step | Screen | Action | API | Permission | Status |
|---|---|---|---|---|---|
| Purchase Material | `/purchasing/purchases` | wizard / row | `POST /purchase-materials` | `purchasing.materials.create` | ✅ |
| Supplier selection | PM drawer → Supplier | Confirm Supplier | `…/select-supplier` | `purchasing.materials.select_supplier` | ✅ |
| Invoice create | `/purchasing/supplier-invoices` | editor | `POST /supplier-invoices` | `purchasing.supplier_invoices.create` | ⚠️ no anchor field (B-2) |
| Invoice post | same | Validate → Post | `…/post` | `purchasing.supplier_invoices.post` | ⚠️ will fail (B-1) |
| Receipt | PM drawer → Receiving | Confirm Receipt | `POST /goods-receipts` + `/post` | `purchasing.goods_receipts.create/update` | ✅ |
| Supplier balance | Supplier 360 · `/accounts-payable` | read | `GET /finance/ap/...` | `finance.ap.view` | ✅ read-only |
| **Payment** | — | — | `POST /finance/ap/payments` exists | `finance.ap.payment.create` | ❌ **BLOCKER B-3** |

### BLOCKER B-3 — supplier payment has no UI

`features/finance/services/finance-ap-service.ts` contains **only `GET`** calls (aging, bills,
payments, supplierLedger, controlReconciliation). `accounts-payable-page.tsx` renders three
read-only tabs. A repo-wide search finds **no** frontend call to `POST /finance/ap/payments`,
`/approve`, `/post`, or `/allocate`. The backend is complete; the operator cannot reach it.

## 15. Ledger evidence to capture at the real acceptance

| Posting | Expected movement | Accounts | Supplier | Amount | Reference |
|---|---|---|---|---|---|
| Receipt | `Dr Inventory / Cr GRNI` | `inventory` role / `grni` (2120) | via PM line | `qty × landed_unit_cost` | `inventory.goods_receipt`, event id |
| Invoice | `Dr GRNI` (+`Dr/Cr PPV`, `Dr Input VAT`) `/ Cr Trade Payables` | `grni`, `purchase_price_variance`, AP control | invoice supplier | receipt valuation + variance + tax | `SupplierBill.number = SI-{invoice_id}` |
| Payment | `Dr AP control / Cr funding account` | AP control / bank or cash | payment supplier | payment amount | `payment:{uuid}`, `SupplierPayment.number` |

Plus one `finance_supplier_ledger_entries` row per invoice (positive) and per payment (negative).

## 16. Side-effect matrix

**Expected to change:** `purchase_material_lines` (agreed_price), `supplier_invoices` +
`supplier_invoice_lines`, `goods_receipts` + `goods_receipt_lines`, `inventory_items`,
`stock_ledger_entries`, `inventory_receipt_layers`, `finance_supplier_bills`,
`finance_supplier_payments`, `finance_supplier_ledger_entries`, `finance_journal_entries`,
`finance_posted_event_receipts`.

**Expected unchanged:** `purchase_orders`, `purchase_order_lines`, Preparation, Distribution,
unrelated Orders, unrelated inventory, unrelated suppliers.

**Documented expected side effect:** receiving raises `InventoryStockReceived`, which
`OrderServiceProvider:77` routes to `RetryReservationOnStockAvailableListener::handleStockReceived`.
Orders awaiting stock may reserve, adding `reservation` rows to `stock_ledger_entries` for **other
products**. Observed during the GR-00001 acceptance and confirmed by design.

## 17. Duplication risks

| Risk | Guard | Evidence | Blocks E2E? |
|---|---|---|---|
| Opening balance twice | scalar is input only; money columns read the ledger | `SupplierResource`, `EloquentSupplierRepository` | No |
| Purchase Material counted | PM writes no ledger entry | no AP reference in module | No |
| Invoice twice | `SupplierBill.number` uniqueness `SI-{id}` + status re-check under lock | `PostSupplierInvoiceService:269, 99-109` | No |
| Receipt twice | posted-status guard + `InboundPostingGuard` + shared row lock | verified live: 1 `purchase_receipt` row | No |
| Invoice + receipt both posting stock | `GoodsInwardAuthority` mode + shared ledger reference | `invoiceMayPost()` | No |
| Payment twice | status guards + coordinator idempotency `payment:{uuid}` | `postPayment()` | No |
| Advance folded into payable | `entry_type <> 'advance'` filter | `SupplierLedgerService:33` | No |
| Returns double-credit | no AP effect at all today | §10 | No — but returns are financially inert |
| **Same figure computed twice** | none | F-1, F-2 | No — reporting drift, not double-posting |

## 18. Reversal / failure behaviour

| Event | Actual behaviour |
|---|---|
| Invoice fails after draft | status → `Failed`, `posting_error` + `posting_log` stored, whole transaction rolled back |
| Receipt fails | transaction rollback; status stays `draft` |
| Payment fails | rollback inside `DB::transaction`; status unchanged |
| Duplicate submit (invoice) | second post re-reads status **under the lock** and throws |
| Invoice posted twice | refused by `canPost()` + `SupplierBill.number` uniqueness |
| Payment posted twice | `documentAlreadyPosted` |
| Receipt posted twice | refused; verified live — the UI also disables the action |
| **Reversal of a posted document** | **none implemented.** `PaymentStatus::Void` exists as an enum value with no action. A posted receipt cannot be un-posted; a zero-valued FIFO layer cannot be revalued. |

## 19. Go-live analytics

**15 join sites**; the ones that silently exclude PM receipts:

| Report | Sites | Effect |
|---|---|---|
| `GetSupplierAnalyticsQuery` | 5 | Supplier 360 KPIs — spend, outstanding, price history exclude PM receipts |
| `GetProcurementHealthQuery` | 4 | procurement health metrics understated |
| `DemandAnalysisService` | 3 | demand/consumption analysis misses PM inbound |
| `GetSupplierTimelineQuery` | 1 | PM receipts absent from the supplier timeline |
| `EloquentSupplierRepository:29` | 1 | supplier grid `total_invoiced` / `total_paid` / `last_purchase_date` |
| `ExpectedIncomingQuery:48` | 1 | **D** — PO-native, correct as-is |

**Classification: GO-LIVE FOLLOW-UP, not a blocker for E2E.** None of these participates in
posting; they misreport rather than miscompute the ledger. The E2E cycle can be proven with the
ledger and Supplier 360's ledger-derived columns. They **must** be fixed before Purchasing is
declared production-ready — GR-00001 is already invisible to all of them.

## 20. Required business decisions

1. **Anchor supplier fallback (B-1)** — approve resolving the anchor's supplier from
   `purchase_material_lines.supplier_id` when there is no PO, exactly as company already falls back
   to warehouse? (This is the RD-1 source; no new authority.)
2. **Invoice anchor as a first-class contract (B-2)** — add validated `lines.*.goods_receipt_line_id`
   (tenant-scoped `exists`, as `purchase_material_line_id` already is on receipts)?
3. **Payment UI scope (B-3)** — minimum create + approve + post, or also allocate?
4. **Segregation of duties** — who is the second user approving the payment? Required by code.
5. **Invoice/receipt quantity** — confirm invoicing is capped at received quantity (current
   behaviour), i.e. no invoice-ahead-of-receipt in Mode 1.
6. **Payment allocation** — leave unallocated (balance still correct) or auto-allocate?
7. **Returns (§10)** — accept that returns are financially inert, or is a credit note required
   before go-live?
8. **GR-00001's zero valuation (D-1)** — accept the 40 zero-cost units as historical, or is a
   correction mechanism required? No reversal exists today.
9. **PO-derived analytics (§19)** — confirm as go-live follow-up.

## 21. Implementation plan — smallest steps

| # | Step | Scope | Files | Data impact | Tests | Browser |
|---|---|---|---|---|---|---|
| 1 | **B-1** supplier fallback in the anchor | backend, 1 line | `InvoiceReceiptAnchorService.php` (+ eager-load `goodsReceipt.purchaseMaterialLine`) | none | anchor resolves for PM line; still refuses cross-supplier + cross-tenant | invoice posts against GR-00001 |
| 2 | **B-2** validated anchor field | backend, request only | `StoreSupplierInvoiceRequest.php` (tenant-scoped `exists`) | none | anchor accepted; foreign anchor 422 | — |
| 3 | **B-2-UI** anchor picker | frontend | `supplier-invoice-editor.tsx`, invoice types | none | payload carries the anchor | invoice created from a receipt |
| 4 | **B-3** payment UI | frontend only — API exists | `finance-ap-service.ts`, `use-finance-ap.ts`, `accounts-payable-page.tsx` | none | create → approve → post wiring | payment posted |
| 5 | **§10** return supplier fallback | backend, 1 line | `ApproveSupplierReturnAction.php` | none | return approves against a PM receipt | optional |
| 6 | **§19** analytics | backend | 14 sites → `LEFT JOIN` + PM-line supplier fallback | none | PM receipts appear | go-live follow-up |

Steps 1–4 are the E2E critical path. **No migration is required for any of them** — every column
already exists. Steps 1, 2 and 5 are one-line fallbacks of a shape already present in the same files.

## 22. Testing strategy

Narrow, no full regression:

- **Step 1:** extend `InvoiceReceiptAnchorTest` (exists) — anchor resolves via PM line; still refuses
  wrong supplier and cross-tenant.
- **Step 2:** one request-validation test — anchor accepted; foreign anchor rejected 422.
- **Step 4:** one focused frontend test on the payment form, mirroring the receiving-tab test.
- **Regression guard:** the existing 46-test receiving gate
  (`PurchaseMaterialReceivingFoundationTest`, `GoodsReceiptTest`,
  `PurchaseMaterialSupplierSelectionTest`) plus `SupplierInvoiceFinancialPostingTest` and
  `InvoiceReceiptAnchorTest`.
- **E2E:** ONE real scenario on PM-00002's remaining 60 units — not a collection of fabricated cases.

## 23. Migration policy

**No migration was created, and none is required.** Every column the cycle needs already exists:
`supplier_invoice_lines.goods_receipt_line_id`, `goods_receipt_lines.purchase_material_line_id`,
`purchase_material_lines.supplier_id`, and the full `finance_supplier_*` set. All four blockers are
logic- or contract-level.

## 24. Final classification

# C. BLOCKED

Three code blockers plus one data prerequisite genuinely prevent the cycle:

| ID | Blocker | Fix size |
|---|---|---|
| **B-1** | `InvoiceReceiptAnchorService:123` resolves the anchor supplier from the PO only, so a PM-anchored receipt can never be invoiced | 1 line + eager-load |
| **B-2** | The invoice API has no validated `goods_receipt_line_id`; the anchor is only an unvalidated passthrough and the UI never sends it | request rule + editor field |
| **B-3** | No supplier-payment UI — `finance-ap-service.ts` is GET-only; the backend is complete and unreachable | frontend only |
| **D-1** | PM-00002 has no `agreed_price`, so GR-00001 is valued at 0.00 and accrued no GRNI; an invoice would book 100% as PPV | operator sets agreed price, then receives |

**Everything else is sound.** One payable authority and one ledger writer; ledger-derived balance with
opening balance and advance correctly separated; complete inventory/FIFO isolation; genuine
idempotency at every posting boundary; a fully implemented payment lifecycle with segregation of
duties. No duplicate balance, no duplicate payable, no duplicate inventory, no duplicate FIFO, and
supplier attribution correct wherever it is read from the PM line.

The gap is uniformly narrow and one-shaped: **three places still ask a Purchase Order who the
supplier is, when the Purchase Material line already knows.**
