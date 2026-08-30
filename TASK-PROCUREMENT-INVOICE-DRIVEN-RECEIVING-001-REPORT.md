# TASK-PROCUREMENT-INVOICE-DRIVEN-RECEIVING-001 — Engineering Report

**Procurement Receiving Center — Supplier-Invoice-Driven Warehouse Receiving**
Date: 2026-08-29 · Branch: `develop` · Status: **BLOCKED — architectural conflict, owner decision required**

---

## Executive Summary — Architectural Conflict & Required Decision

The task asks for a specific inbound flow: **Supplier Invoice = expected quantity → Warehouse
records a *separate* actual received quantity → canonical inventory receipt → partial/full
tracking against the invoice**, with §7 stating *"Do not use the invoice quantity directly as an
inventory movement."*

That flow **conflicts with the CERTIFIED goods-inward architecture** (TASK-PROCUREMENT-INBOUND-…-001,
137 tests, deployed) and cannot be implemented without either creating a **second receiving/inventory
authority** (which §1/§11 of this task and the certified ruling both forbid) or **re-litigating a
certified contract**. The conflict is not cosmetic — it is in both supported modes:

- **Mode 1 (`goods_receipt`, the default — and the mode DEV runs):** the **Goods Receipt** is the
  physical + inventory-posting authority (expected = **Purchase Order** qty, actual = `net_received`,
  partial/full tracked against the **PO**). The **Supplier Invoice is a *financial* document that
  *settles* a receipt** (V-5: `SupplierInvoiceLine.goods_receipt_line_id`), raised *after* goods
  arrive. It posts **no** inventory. So an invoice cannot *drive* receiving here — the receipt
  precedes it.
- **Mode 3 (`supplier_invoice`):** the Supplier Invoice **is** the inbound authority and posts
  inventory using its **own billed line quantities** — there is **no** separate "warehouse actual
  received" step, and the invoice quantity **is** the inventory movement. This is exactly what §7 of
  this task forbids, and it offers no partial-receipt concept (the whole invoice posts at once).

**Crucially, the task's functional REQUIREMENTS already exist canonically** — expected-vs-actual,
partial/full, over-receipt ceiling, canonical inventory, and idempotency are all implemented today in
the **Goods-Receipt-against-Purchase-Order** path (see §7–§11 below). The task's requirements map to a
**Purchase-Order-driven** receiving queue; only the *named driver* ("Supplier Invoice") conflicts with
the architecture.

**No code was changed. No DEV data was mutated.** Removing manual receipt creation (§2) *without* a
canonical replacement driver would leave the warehouse unable to receive at all, so a partial
implementation was deliberately not attempted. The decision below is required first.

### Options for the owner / CTO

- **Option A (recommended): re-scope the driver to the Purchase Order (Mode 1).** Make the Receiving
  Center a work queue of **eligible Purchase Orders awaiting/undergoing receipt**, driving the
  **existing, fully-canonical** `GoodsReceipt` + `PostGoodsReceiptAction` flow (expected = PO qty,
  actual = `net_received`, partial/full against the PO, over-receipt ceiling, canonical inventory,
  idempotency), and remove the from-scratch "New Receipt". This satisfies **every functional
  requirement** of the task with **zero new authority** — it only reframes the UI around the canonical
  procurement document that actually owns expected quantity in Mode 1. The Supplier Invoice remains the
  financial settlement (its certified role).
- **Option B: adopt a new "invoice-first receiving" contract.** Treat the Supplier Invoice as the
  expected-quantity source and add a *separate* warehouse-actual-received step feeding a canonical
  inventory receipt. This is a **new inbound model** that must be reconciled by the owner with:
  goods-inward-mode exclusivity, the V-5 invoice-settles-receipt direction, `InboundPostingGuard`
  idempotency, and §7 (actual ≠ invoice qty). It is a dedicated architectural workstream, not a
  focused UI task.
- **Option C: Mode-3 invoice-posting queue** (show `validated` invoices, "Receive" posts them). Uses
  the canonical Mode-3 authority but **violates §6/§7/§8** (no separate actual qty, invoice qty *is*
  the movement, no partial). Not recommended.

---

## 1. Existing Receiving Architecture

The Receiving Center (`frontend/src/features/receiving-center/pages/receiving-center-page.tsx`) is a
**Goods Receipt** list, not an invoice queue. It:
- lists `GoodsReceipt` rows via `useGoodsReceiptsQuery` (`GET /api/…/goods-receipts`), with KPIs
  (total / draft / posted) and a status filter (all/draft/posted);
- offers **"New Receipt"** and a "Receive Goods" shortcut, both routing to `goodsReceiptsNew` — the
  manual from-scratch creation form;
- row actions: view, and for `draft` receipts edit / **post** / delete.

Backend: `GoodsReceiptController` (`index/show/store/update/destroy/post`). A receipt is created by
`CreateGoodsReceiptAction` from a `StoreGoodsReceiptRequest` (header + lines), and posted by
`PostGoodsReceiptAction` (which also triggers channel stock-sync). A `GoodsReceipt` **belongs to a
`PurchaseOrder`** (`purchase_order_id`) and carries its own invoice metadata
(`supplier_invoice_number`, `supplier_invoice_date`, `invoice_total_amount`, `payment_status`, …).

## 2. Supplier Invoice Authority

`companies.goods_inward_mode ∈ {goods_receipt (default), supplier_invoice}` — the certified
`GoodsInwardAuthority` (`Modules/Inventory/InventoryItems/Domain/Services`) declares **exactly one
document type posts inventory per company**. `invoiceMayPost()` is true only in `supplier_invoice`
mode. **DEV's only company ("ECOS Holding 20") runs `goods_receipt` mode**, so its Supplier Invoices
post no inventory. `SupplierInvoiceStatus`: Draft → Validated → (AutoProcessing) → Posted / Failed /
Cancelled; `canPost()` = **Validated**. `PostSupplierInvoiceService` converges on the canonical inbound
actions and, in Mode 1, does financial settlement only (GRNI / PPV / VAT via `AccountsPayableService`).

## 3. Expected Quantity Source

In Mode 1 the expected quantity is the **Purchase Order line** (`purchase_order_lines.quantity`), which
`goods_receipt_lines.ordered_quantity` snapshots. The **Supplier Invoice does not own expected receipt
quantity** — its line quantity is a *billed* quantity used for financial valuation (and, in Mode 3
only, for the inventory movement). This is the crux: the document the task names as the expected-qty
source (the invoice) is not the canonical expected-qty owner for physical receiving in Mode 1.

## 4. Existing Manual Receipt Root Cause

Manual creation is the `store` endpoint (`CreateGoodsReceiptAction`) reached from the `goodsReceiptsNew`
form via the Receiving Center's "New Receipt" / "Receive Goods" buttons. It lets a warehouse user
assemble a receipt (choosing PO + lines) from scratch. That is the flow §2 asks to remove — but it can
only be removed once an automatic, canonical driver replaces it (see the decision above).

## 5. Automatic Receiving Eligibility

The canonical "ready for physical receipt" signal in Mode 1 is a **Purchase Order with an outstanding
quantity** (`received_qty < quantity` on its lines), not a Supplier Invoice state. A Supplier Invoice's
receivable-equivalent state would be `Validated` (`canPost()`), but in Mode 1 that drives *financial
settlement*, not physical receipt; Draft is correctly never receivable. No canonical "invoice awaiting
physical receipt" state exists, because physical receipt is the receipt's job, not the invoice's.

## 6. Invoice-to-Receiving Relationship

The canonical link runs **invoice → receipt** (settlement), not receipt-from-invoice:
`SupplierInvoiceLine.goods_receipt_line_id` (the V-5 anchor — "the receipt line whose physical
valuation this line settles… null only while the invoice is a draft raised before the goods arrived");
`SupplierInvoice.auto_receipt_id` (FK to a receipt). In DEV, **0 of 2** validated invoice lines are
anchored — i.e. the invoices are not tied to any physical receipt. There is **no** existing
"invoice spawns receiving work" relationship to reuse; building one is Option B.

## 7. Partial Receipt

Already canonical — **against the Purchase Order**. `PostGoodsReceiptAction` advances
`purchase_order_lines.received_qty` cumulatively; a PO line with `received_qty < quantity` remains
receivable by a later receipt. This is exactly the task's partial-receipt requirement, sourced from the
PO, not the invoice.

## 8. Full Receipt

Also canonical: when cumulative `received_qty` reaches the ordered quantity the PO line is fully
received and leaves the outstanding set. Mode 3 invoice posting has no partial/full concept — it posts
the whole invoice once.

## 9. Actual Quantity Semantics

Canonical actual quantity = `goods_receipt_lines.net_received_quantity`
(`effectiveReceivedQty()` = `net ?? received`), validated `min:0.0001`, `lte:gross_received_quantity`.
Inventory increases by this actual quantity via `PostGoodsReceiptAction`. In Mode 3, by contrast, the
**invoice's billed quantity** is the movement — which §7 explicitly forbids. So the task's actual-qty
rule is satisfied only by the receipt path, never by invoice posting.

## 10. Inventory Movement Authority + Over-Receipt

Canonical inbound = `ReceiveStockAction` (+ `CreateReceiptLayersAction` for FIFO layers), the single
path both `PostGoodsReceiptAction` and `PostSupplierInvoiceService` converge on. **Over-receipt is
already prohibited**: `PostGoodsReceiptAction` Guard 4 (under a row lock) throws `OverReceiptException`
/ `PurchaseMaterialReceivingException::overReceipt` when `received_qty + net > ordered`. No silent
over-receipt exists; no tolerance policy is configured. React never mutates stock.

## 11. Idempotency

`InboundPostingGuard` keys inventory posting on a shared ledger reference so a receipt and its linked
invoice cannot both post the same delivery; `PostGoodsReceiptAction` re-checks status under a lock
(Guard) so concurrent posts of the same receipt cannot double-post; `PostSupplierInvoiceService`
re-reads `canPost()` under the same lock. Idempotency is robust and must be preserved by any new flow —
another reason Option B needs careful design rather than a UI change.

## 12. Invoice Edit / Cancellation Behavior

`SupplierInvoiceStatus.canCancel()` = Draft/Validated/Failed (not Posted). Because in Mode 1 the invoice
never posts inventory, editing/cancelling it before posting touches no stock; a posted invoice is
immutable. There is **no** canonical path by which an invoice edit rewrites *physical receipt* history,
precisely because the invoice does not own physical receipt — which is why the task's §12 concern only
arises under the (non-existent) invoice-drives-receipt model of Option B. That reconciliation contract
(what happens when an edited invoice expected-qty < already-received) does not exist and would have to be
defined under Option B.

## 13. Damage / Rejected Goods

**Not canonically supported.** `goods_receipt_lines` carries `ordered / received / gross_received /
net_received / variance` but **no `accepted` / `rejected` / `damaged` fields**, and there is no receipt
disposition action. Surfacing accepted/rejected/damaged (§14) would require new schema + an action, and
must not duplicate the WasteInvestigation / inventory-disposition authorities. **Documented gap**, not
fabricated.

## 14. RBAC

Warehouse receipt authority (goods-receipt create/post permission) is distinct from invoice
create/approve and supplier-master editing. `GoodsReceipt` and `SupplierInvoice` both carry the certified
`tenant` global scope (foreign row → 404). Any new flow must keep receipt authority separate from
invoice-edit authority (§18) — no permission was changed.

## 15. Desktop UX

Unchanged (no implementation). Under Option A the desktop table would list **receivable Purchase
Orders** (Supplier / PO ref / expected / received / remaining / status + Receive), replacing the
receipt-CRUD list and removing "New Receipt".

## 16. Mobile UX

Unchanged (no implementation). Under Option A, the same responsive pattern used elsewhere in this
codebase applies — `UniversalDataGrid`'s `renderMobileCard` gives desktop table / mobile card for free;
a card would show Supplier / reference / expected / received / remaining / status with a primary
**Receive** action. This is a safe, non-authority presentation layer once the driver decision is made.

## 17. Backend Changes

**None.** Implementation is blocked pending the driver decision; changing the inbound driver touches
certified contracts and must be owner-approved.

## 18. Frontend Changes

**None**, for the same reason. (Removing manual creation without a canonical replacement would break
receiving entirely.)

## 19. Files Changed

**None.** This deliverable is a read-only audit + architectural decision request.

## 20. Focused Verification

Read-only audit evidence (no DEV mutation):
- `goods_inward_mode` — DEV company = `goods_receipt` (Mode 1) ⇒ Supplier Invoice posts no inventory.
- `GoodsInwardAuthority` / `PostSupplierInvoiceService` — invoice converges on canonical inbound;
  Mode 1 = financial settlement only; Mode 3 = posts billed qty (the §7-forbidden behavior).
- `GoodsReceiptLine` / `PostGoodsReceiptAction` — expected(`ordered`)/actual(`net_received`),
  cumulative `received_qty` vs PO, `OverReceiptException` ceiling, locked idempotency — the task's
  functional requirements, already canonical, PO-driven.
- V-5 anchor (`SupplierInvoiceLine.goods_receipt_line_id`) direction = invoice-settles-receipt; DEV
  0/2 invoice lines anchored.
- Damage/rejected fields absent on `goods_receipt_lines`.

No implementation ⇒ no functional test suite added (nothing to verify); the certified inbound suite
(137 tests) remains the authority for the existing flow.

## 21. Deferred Gaps

- The **driver decision** (Options A/B/C) — the blocker.
- **Damage/rejected/accepted** disposition — no canonical fields/action (§13).
- **Invoice-edit-vs-received reconciliation** contract — only needed under Option B (§12).
- The certified **D-INB-04/05** landed-cost differences between invoice-billed and receipt-net remain
  open and would interact with any invoice-first model.

## 22. Implementation Status

The requested invoice-driven, separate-actual-quantity, partial/full receiving flow cannot be built as
specified without contradicting the certified `goods_inward_mode` architecture (one inventory authority
per company; Mode 1 invoice = financial settlement; Mode 3 invoice-qty = movement, which §7 forbids) or
creating a second receiving authority (forbidden). The task's functional requirements are already met by
the canonical **Purchase-Order-driven Goods Receipt** flow. A driver decision (Option A recommended) is
required before any code is written.

---

IMPLEMENTATION STATUS:
BLOCKED

FINAL CERTIFICATION:
DEFERRED TO FINAL SYSTEM REVIEW

---

# Supplier Invoice Upstream Contract Audit

**Added 2026-08-29 following the CTO Scope Clarification** ("Supplier Invoice = canonical commercial
source"). This section audits the *existing* Supplier Invoice implementation against the clarified
upstream contract, reusing canonical facts and **fabricating nothing**. DEV company **"ECOS Holding 20"
runs `goods_inward_mode = goods_receipt` (Mode 1)** — verified live — so in DEV the Supplier Invoice is the
*downstream* financial/commercial document and never posts inventory itself.

## A. What the approved Supplier Invoice already exposes

Source: `SupplierInvoice` / `SupplierInvoiceLine` models + migrations, `SupplierInvoiceController`,
`StoreSupplierInvoiceRequest`, `SupplierInvoiceStatus`.

**Header** — Company (`company_id`) ✅ · Receiving Warehouse (`warehouse_id`) ✅ · Supplier (`supplier_id`) ✅ ·
Invoice Date (`invoice_date`) ✅ · Supplier Invoice Number (`supplier_invoice_ref` = supplier's own +
`invoice_number` = system) ✅ · Currency/FX ✅.

**Lines** — Product/Material (`product_id`) ✅ · Quantity (`quantity`) ✅ · Unit Price (`unit_price`) ✅ ·
Line Total (`line_total`, tax-inclusive) ✅ · tax/discount/UOM-snapshot/landed-cost ✅.

**Totals** — Items Total (`subtotal`, net) ✅ · Transport Expense (`freight_amount`) ✅ · Additional
Expenses (`additional_costs`) ✅ · Invoice Total (`grand_total`) ✅. `recalculateTotals()` keeps
`subtotal + tax + freight + additional − discount = grand_total`.

**Payment** — Payment Method (`payment_method`) ✅ · Due Date (`due_date`) ✅ · terms/terms_days ✅.

**Status / approval** — `SupplierInvoiceStatus` = Draft → Validated → (AutoProcessing) → Posted / Failed /
Cancelled. **`Validated` is the approval/eligibility state** (`canPost()` returns true only for Validated;
set by `validate()`; guarded by `permission:purchasing.supplier_invoices.validate`).

**Line-calculation contract already present:** `syncLines()` computes `subtotal = qty × unit_price`,
`tax = subtotal × rate`, `line_total = subtotal + tax − discount` at decimal:4 — the canonical money/qty
precision the clarification asks future work to follow. (It computes Line Total from Qty × Price; it does
**not** yet derive Unit Price from an edited Line Total — a UX concern for the next task, not a data gap.)

## B. Which clarified-contract fields are MISSING upstream

| Clarified field / concept | Status | Blocks receiving? |
|---|---|---|
| Invoice **Attachment** | ❌ absent (no column/media relation) | No — presentation only |
| **Paid Amount** | ❌ absent | No for physical receipt; blocks Payment-status dimension |
| **Remaining Balance** (derived = Total − Paid) | ❌ absent (no `paid_amount` to derive from) | No for physical receipt |
| Per-line **cumulative received qty / remaining** | ❌ absent (line has `quantity` = expected only; no `received_qty`) | **YES — blocks invoice-driven partial/full receipt** |
| **Receiving Status** dimension (Awaiting/Partial/Full) | ❌ absent (only the single invoice `status` exists) | **YES** |
| **Payment Status** dimension (Unpaid/Partial/Paid) | ❌ absent | No for physical receipt |
| "**Receive actual qty against invoice → inventory**" authority | ❌ absent (routes are only `validate`/`post`/`cancel`; no `receive`) | **YES — blocks the write path** |

The three ❌-**YES** rows are the hard blockers for the requested *invoice-driven* receiving write path.

## C. Current invoice approval state used for receiving eligibility

`Validated` (`canPost()`), set by `POST /supplier-invoices/{id}/validate`. This is the natural "approved /
eligible" gate a Receiving queue would read.

## D. Expected-quantity source

The Supplier Invoice **line `quantity`** is the commercial/expected quantity (billed qty). There is no
separate actual-received quantity anywhere on the invoice or its lines.

## E. Current Invoice → Receipt relationship (and its direction)

The existing link is **receipt-first settlement, the reverse of "receive against invoice"**:
- `SupplierInvoiceLine.goods_receipt_line_id` (V-5 anchor) = "this invoice line **settles** an existing
  Goods Receipt line." `InvoiceReceiptAnchorService` enforces company/supplier/product/qty agreement and a
  ceiling of *received − already-invoiced* — i.e. you may only invoice what was **already physically
  received**.
- `SupplierInvoice.auto_receipt_id` / `auto_purchase_id` are "populated **after** posting"
  (`auto_purchase_id` → **PurchaseMaterial**, not PurchaseOrder). Before posting there is **no PO/receipt
  reference** to receive against.
- There is **no `purchase_order_id`** on the invoice, so the certified PO-driven receipt authority
  (`PostGoodsReceiptAction` / `ReceiveAgainstPurchaseOrderAction`) cannot be reached *from* an invoice.

## F. Current payment / balance fields

`payment_method`, `payment_terms`, `payment_terms_days`, `due_date` only. **No `paid_amount`, no
`remaining_balance`, no payment-status.** The financial payable/balance is established downstream by
`AccountsPayableService` (a `SupplierBill`) at `post()` time — the AP authority, not the invoice row.

## G. Inventory-boundary confirmations (REQUIRED)

- ✅ **Supplier Invoice APPROVAL does NOT mutate stock.** `validate()` (Draft→Validated) only writes
  `status`. `PostSupplierInvoiceService` runs solely on `post()` — a later, separate step.
- ✅ **Actual Warehouse receipt remains the Inventory authority (Mode 1 / DEV).** In `goods_receipt` mode
  the invoice's G-1 guard (`GoodsInwardAuthority::invoiceMayPost() == false`) **skips all inventory
  posting** — "financial document only." Inventory moves only through the canonical
  `ReceiveStockAction` + `CreateReceiptLayersAction`, driven by the **Goods Receipt**.
- ⚠️ **Mode-3 caveat (not DEV):** where a company runs `supplier_invoice` mode, `post()` posts inventory
  from the invoice's **billed** `quantity` (no separate actual-received step, no partial receipt). This is
  the exact "invoice qty = movement" the clarification's §7 forbids; it is dormant in DEV (Mode 1) but is
  the structural reason the invoice cannot today be a *separate-actual-quantity* receiving source.

## H. Exact upstream gaps the NEXT Supplier Invoice task must deliver (before invoice-driven receiving is safe)

1. **Per-line receiving tracking** — a cumulative `received_qty` (or a canonical receipt-linkage that
   accumulates) on the Supplier Invoice line, so Expected / Previously-Received / Remaining can be
   maintained *against the invoice* across partial receipts.
2. **A Receiving-Status dimension** distinct from invoice `status` (Awaiting → Partially Received → Fully
   Received), using canonical vocabulary.
3. **A canonical "receive actual accepted qty against an approved Supplier Invoice → Goods Receipt →
   Inventory" authority** that posts the **actual** quantity (never the billed qty), reusing
   `PostGoodsReceiptAction` semantics (over-receipt ceiling, idempotency) — the one piece that lets the
   invoice be an upstream source without becoming a second inventory authority.
4. **Payment contract** — `paid_amount` + derived `remaining_balance` + a Payment-Status dimension
   (Unpaid/Partial/Paid), kept separate from Receiving Status (rule 9).
5. **Attachment** field.
6. **Line-total ⇄ unit-price** two-way derivation (UX) on the invoice editor.

None of the above were fabricated in the Receiving Center (rule: "must not become a shadow Supplier Invoice
editor"). No Finance journal/GL/AP/VAT/COGS posting was added (Finance boundary preserved).

## I. Disposition for the current Receiving task

- **Invoice-driven receiving WRITE path** (receive actual qty against an approved invoice, posting
  inventory, with partial/full tracked on the invoice) is **BLOCKED on the upstream contract** in §H —
  specifically the three ❌-**YES** gaps. Per the clarification's MISSING-UPSTREAM-DATA rule these were
  documented, **not fabricated**, and recorded for the next task.
- **Safe, certified realization preserved:** in Mode 1 the accepted **PO-driven Receiving Center**
  (this workstream's Option-A implementation + Closure-001) already delivers *eligible upstream document →
  Receiving Center → actual accepted qty → canonical Goods Receipt → Inventory*, with partial/full,
  over-receipt ceiling, idempotency, and **no manual "New Receipt"** — and the Supplier Invoice settles it
  downstream (V-5). This work is retained (not discarded, not restarted).
- **Recommended sequencing:** land the §H upstream contract in the dedicated **next Supplier Invoice task**
  first; the Receiving Center change to consume an *approved invoice* as the source is then small (swap the
  queue source, reuse the same actual-qty receipt mechanics) and safe.

No code was changed, nothing was committed/pushed/deployed, and **no DEV business data was mutated** for
this audit (read-only inspection + one read-only `goods_inward_mode` query).

---

AUDIT STATUS (2026-08-29 clarification):
COMPLETE — invoice-driven receiving WRITE path BLOCKED on the documented upstream Supplier Invoice contract (§H); PO-driven receiving retained as the safe Mode-1 realization; inventory boundaries confirmed.

FINAL CERTIFICATION:
DEFERRED TO FINAL SYSTEM REVIEW
