# TASK-PROCUREMENT-RECEIVING-INSPECTION-INVOICE-AMENDMENT-001 — Engineering Report

**Date:** 2026-08-17 · **Branch:** `develop` · **Runtime:** MySQL 8.4.10 / PHP 8.4.24

**OUTCOME: STOP BEFORE IMPLEMENTATION — 3 ARCHITECTURE/CONTRACT GAPS**
**FINAL VERDICT: NOT CERTIFIED**
**Production changes made: NONE.** No code, migration, schema, API, UI or data was modified.

The task carries three explicit STOP conditions. **All three are triggered.** Each is reported below
with the evidence that establishes it, the decisions required, and options with a recommendation.

---

## 0. Why this stopped

The task's terminal step is **Supplier Account reconciliation**, and its rejection path requires
**stock reversal**. Neither has a canonical mechanism:

| # | Gap | Task instruction | Triggered? |
|---|---|---|---|
| **G-A** | No path connects a Purchasing Supplier Invoice to the Finance Supplier Account | PART 17 / PART 20 "Do NOT invent accounting treatment" | **YES** |
| **G-B** | No reversal path for a posted Goods Receipt | PART 11 "If the existing architecture has no safe reversal path: STOP and report the gap instead of directly mutating stock" | **YES** |
| **G-C** | No retroactive FIFO cost correction for a price amendment | PART 20 "If no canonical rule exists for invoice-price amendment after receipt: STOP and report the contract gap" | **YES (conditional)** |

Building the workflow anyway would produce a system whose final step silently does nothing and whose
rejection path either does nothing or mutates stock outside any canonical mechanism. This programme
has repeatedly recorded that shipping a plausible-looking financial leg is worse than an honest gap;
the same judgement applies here.

**The rest of the task is genuinely ready to build** — §1 lists what already exists and needs no
invention. Implementation should be fast once the three rulings land.

---

## 1. Existing architecture reuse — what is ready, with nothing to invent

Inspected before any design, per PART 1. Most of the task's non-financial surface is already served:

| Capability | Canonical owner | Verdict |
|---|---|---|
| Physical receipt of actual quantity | `PostGoodsReceiptAction` + `goods_receipt_lines.net_received_quantity` (`effectiveReceivedQty()`) | **Exists.** Inventory already posts the physical quantity, never the invoiced one — PART 4 already holds |
| Partial receipts | `PurchaseOrderLine.received_qty` accumulated per receipt; over-receipt guard locks the PO line | **Exists.** PART 5/30 already hold; each receipt affects inventory only by its own quantity |
| Inventory + ledger | `ReceiveStockAction` → `stock_ledger_entries` | **Exists, certified.** Must not be duplicated |
| FIFO layers | `CreateReceiptLayersAction` | **Exists, certified** |
| Line-level evidence | **`app/Core/Documents/DocumentService`** — polymorphic `subject_type`/`subject_id`, `company_id`, `document_type`, mime, size, `uploaded_by`, timestamps; `attach()`/`getFor()`/`delete()`/`getDownloadUrl()` | **Exists.** Attach with `subject_type = GoodsReceiptLine` → PART 6/7 satisfied by reuse; `SupplierDocumentController` is a working precedent. No parallel file store needed |
| Existing photo precedent | `goods_receipt_lines.weight_photo_path` (single path) and `inventory_count_line_attachments` (line-level collection) | **Exists.** Confirms line-level evidence is an established ECOS pattern |
| Idempotent posting + concurrency | Receipt row `lockForUpdate` (D-INB-03) + shared canonical inbound lock (C-1), both landed today | **Exists.** PART 29 is already satisfied for receiving |
| Goods-inward authority | `GoodsInwardAuthority` + `companies.goods_inward_mode` | **Exists, certified.** Must remain the sole authority |
| Tenant isolation | `tenant` global scope on `GoodsReceipt`/`SupplierInvoice`; `documents.company_id` | **Exists, certified** |
| Audit | `ConfigAuditService` + domain status/actor stamping | **Exists** |
| Approval pattern | No generic engine; ECOS uses per-domain state machines (`price_approvals`, `marketing_campaign_approvals`, `SupplierReturnStatus`) | **Convention exists** — a receiving/amendment state machine should follow it, not invent a generic engine |

**PART 22 — permissions: NOT a blocker.** The model can express the three roles today:
`purchasing.receiving.create` (operator), `purchasing.purchases.review` / `purchasing.materials.review`
(reviewer), and a financial authority role. Adding rows for receiving-review and amendment-approval is
ordinary seeder work (`RbacSeeder` is idempotent), not an architecture change.

---

## 2. G-A — No Supplier Invoice → Supplier Account path (blocks PARTS 12–17, 26)

### The evidence

1. `SupplierLedgerEntry` rows — the supplier account — are written by **exactly two** call sites, both
   inside `AccountsPayableService`: `postDocument()` (:142) and `postPayment()` (:259).
2. `SupplierBill` (the AP document those entries come from) is referenced **only within
   `Modules/Finance`** — its own controller and `AllocationEngine`. **No file in `Modules/Purchasing`
   references `SupplierBill` at all.**
3. `PostSupplierInvoiceService` emits **no event** — verified by grep for `event(`/`dispatch(`/`Event::`.
4. **No file in `Modules/Finance` references `SupplierInvoice`.**

So a Purchasing Supplier Invoice can be created, validated, posted and can move inventory **without
ever producing a payable, a ledger entry, or any change to the supplier balance or statement.**

### Why this blocks the task rather than being a small addition

PART 15 requires an approved amendment to update "supplier payable amount, supplier account balance,
supplier statement, invoice totals". There is nothing to update: the *original* invoice never created
a payable. PART 17 requires the Supplier Account to change "only through the canonical financial
invoice workflow" — that workflow (`createDocument` → `postDocument`) exists but is fed exclusively by
Finance's own `SupplierBillController`, never by Purchasing.

Worse, **three disconnected representations of supplier financial truth currently coexist**:

| # | Representation | Maintains a balance? | Connected to the others? |
|---|---|---|---|
| 1 | `goods_receipts.invoice_total_amount` / `paid_amount` / `payment_status` | No | **No** — read only by three Purchasing analytics queries (`GetProcurementHealthQuery`, `GetSupplierAnalyticsQuery`, `GetSupplierSummaryStatsQuery`) |
| 2 | `Modules\Purchasing\SupplierInvoices\SupplierInvoice` (+ its own status machine, `credit_amount`, `debit_note_number`) | No | **No** |
| 3 | `Modules\Finance\Payables\SupplierBill` → `SupplierLedgerEntry` | **Yes** — the only real supplier account | **No** |

This is consistent with the certified SR-3 ruling ("no financial effect in V1" for supplier returns)
and with the historical **G-8** finding ("no Accounts Payable module was found") — an AP module now
exists, but the Purchasing→Finance bridge still does not.

### Decision required

**D-1: Which representation is the canonical supplier financial truth, and what event creates the payable?**

| # | Option | Consequence |
|---|---|---|
| **1a** | `SupplierInvoice` posting creates a `SupplierBill` via `AccountsPayableService::createDocument()` + `postDocument()` | Correct long-term shape; makes #3 canonical and #1/#2 derived. Requires ruling the document type, GL accounts and posting trigger. **Recommended** |
| 1b | Goods Receipt posting creates the payable | Contradicts PART 17 ("Do NOT directly mutate Supplier Account from Goods Receipt") |
| 1c | Keep them disconnected; the amendment adjusts only the Purchasing invoice | PARTS 15/17/26 become unimplementable as written; "Supplier Account reconciliation" would be a label with no behaviour |

**D-2: How does an approved amendment adjust an already-posted payable?** Reversal-and-repost, a
credit-note document, or a delta ledger entry? `SupplierDocumentType` and `SupplierLedgerEntryType`
already exist and should be inspected against this decision — but choosing between them is an
accounting ruling, and PART 20 explicitly forbids me from inventing it.

---

## 3. G-B — No reversal path for a posted Goods Receipt (blocks PART 11)

### The evidence

- `GoodsReceiptStatus` has exactly **two** states: `Draft`, `Posted`. There is no reversed, cancelled,
  rejected or void state.
- **No** `ReverseGoodsReceiptAction`, `UnpostGoodsReceiptAction` or `CancelGoodsReceiptAction` exists
  anywhere in `backend/Modules`.
- The only inventory-out path in Purchasing is `AdjustmentOutAction`, used by
  `ApproveSupplierReturnAction` — a **different business document** with its own certified FIFO
  scope, supplier-anchor and ceiling semantics (SR-1/SR-2). Reusing it to reverse a receipt would
  apply supplier-return valuation rules to something that is not a supplier return.

### Why it blocks

The task's own sequence (PART 2/4/10) posts inventory at physical receipt and reviews afterwards, and
PART 10 forbids the approval from posting again. A **rejection** therefore arrives when stock is
already on hand. PART 11 requires that rejection preserve evidence and, if reversal is needed, reuse
an existing canonical workflow — and states plainly: *"If the existing architecture has no safe
reversal path: STOP and report the gap instead of directly mutating stock."* There is none.

### Decision required

**D-3: What does rejecting a receiving inspection do to stock already posted?**

| # | Option | Consequence |
|---|---|---|
| **3a** | Rejection is a **control flag only** — stock stays, the document is marked rejected with reason/actor/timestamp, and any physical correction goes through the existing Supplier Return or Inventory Adjustment document | No new reversal mechanism; evidence preserved; **fully implementable today**. **Recommended** |
| 3b | Add a canonical `ReverseGoodsReceiptAction` (FIFO-aware, idempotent, locked) | Correct if rejection must truly un-receive, but it is a new inventory mutation path and needs its own FIFO/costing ruling — a task of its own |
| 3c | Reuse `AdjustmentOutAction` directly from the receipt path | **Not recommended** — silently applies supplier-return semantics to a non-return document |

Under **3a** the entire receiving-review workflow becomes implementable immediately.

---

## 4. G-C — No costing rule for a price amendment after receipt (conditional, PART 20)

**Quantity-only amendments need nothing.** In the task's own example (invoice 100 KG, received 80 KG)
inventory and the FIFO layer already record 80 KG at the received unit cost, so the amendment is
purely financial and touches no layer. PART 20 is satisfied for this case.

**Price amendments have no canonical treatment.** A grep across `Modules/Inventory` for retroactive
layer-cost correction (`landed_unit_cost` update, `recalculateLayer`, `adjustLayerCost`, …) returns
**nothing** — FIFO layers are written once at receipt and never cost-corrected. So if an amendment
changes unit price while quantity is unchanged, there is no rule for whether existing layers,
`product.average_cost` and already-consumed cost are restated or left alone.

**D-4: Does an approved price amendment restate historical cost, or apply prospectively only?**
Recommended: **prospective only in V1** (layers immutable once consumed), with the price difference
handled as a purely financial adjustment. That is the smallest ruling that unblocks the feature and it
preserves the certified FIFO contract untouched. It must be an explicit ruling, not my assumption.

---

## 5–17. Deliverables not implemented, and why

| Report section | Status |
|---|---|
| 2. Receiving lifecycle (RECEIVED → UNDER_REVIEW → APPROVED/REJECTED) | **Not implemented** — depends on D-3 (rejection semantics) |
| 3. Partial receipt | **Already works today** — no change needed (§1) |
| 4. Receiving line evidence | **Not implemented** — ready to build on `DocumentService`, but serves a review workflow blocked by D-3 |
| 5. Photo storage | **Design settled, not built** — `app/Core/Documents` polymorphic store, `subject_type = GoodsReceiptLine`, company-scoped; no parallel store required |
| 6/7. Review + approval/rejection | **Not implemented** — blocked by D-3 |
| 8. Invoice discrepancy detection | **Not implemented** — computable today (`net_received_quantity` vs invoice line quantity), but only meaningful once D-1 defines what an amendment adjusts |
| 9/10/11. Amendment + approval/rejection | **Not implemented** — blocked by D-1/D-2 |
| 12. Supplier Account reconciliation | **BLOCKED** — G-A |
| 13. Inventory integration | **Unchanged and correct today**; PART 10's "approval must not post again" already holds because approval would be a control layer |
| 14. FIFO integration | **Unchanged**; price-amendment case blocked by G-C |
| 15. Tenant isolation | **Unchanged and certified**; `documents.company_id` extends it to evidence |
| 16. Permissions | **Not a blocker** — model can express the separation (§1) |
| 17. Audit trail | **Not implemented** — `ConfigAuditService` and domain stamping are available |
| 18. API | **Not implemented** — no endpoints added |
| 19/20. UI / Mobile | **Not implemented** — no frontend changes |
| 21/22. Backend / frontend tests | **Not written** — nothing to test; no production change was made |
| 23. Browser E2E | **Not performed.** No authenticated session is available and I did not enter credentials — the same constraint recorded for the Goods Inward UI task |
| 24. Regression | **Not re-run** — zero production changes, so nothing could regress. The suites verified earlier today remain the current state: Supplier Return 20/20, Inbound Ownership 15/15, Cross-Document 11/11, Goods Receipt Concurrency 8/8, Goods Inward Config 12/12 |
| 25. Static quality | **Not run** — no changed files |
| 26. Deployment parity | **Nothing deployed.** HOST/RUNNER/APP unchanged from the previous task's verified parity |

---

## 27. Known contract gaps — summary

| ID | Gap | Blocks | Decision |
|---|---|---|---|
| **G-A** | Purchasing Supplier Invoice never reaches the Finance Supplier Account; three disconnected representations of supplier financial truth | PARTS 12–17, 26 | **D-1**, **D-2** |
| **G-B** | No reversal path for a posted Goods Receipt; only Draft/Posted states exist | PART 11, and therefore the whole review lifecycle | **D-3** |
| **G-C** | No retroactive FIFO cost correction; price amendments have no costing rule (quantity amendments are unaffected) | PART 20 | **D-4** |

**Pre-existing, unrelated, recorded only:** D-3 from the previous task (a linked Mode 3 receipt never
advances `received_qty` once its invoice posts first) — relevant context for any discrepancy
calculation built later, since a refused receipt records no received quantity at all.

---

## 28. Final certification

**NOT CERTIFIED.**

Certification requires, among others, "Approved amendment updates Supplier Account correctly" and
"Rejection works". Neither can be proven, because neither has a canonical mechanism to be proven
against. No gate is marked PASS and nothing is claimed.

**REAL E2E = NOT PERFORMED** (no authenticated session; credentials not entered).

### What unblocks this fastest

**D-3 alone** (rejection is a control flag, option 3a) unblocks the entire non-financial half —
receiving lifecycle, line-level photo evidence on `DocumentService`, review UI, approve/reject with
mandatory reason, discrepancy detection, and the amendment *proposal* record — with no new inventory
mechanism and no accounting invention. That is a substantial, independently valuable slice.

**D-1 + D-2** are then required for the financial half (amendment approval → final invoice → Supplier
Account). **D-4** is required only if price amendments are in scope.

I have deliberately made no production changes pending these rulings, exactly as PARTS 11 and 20
direct. No Procurement work was started, and no certified contract was reopened or modified.
