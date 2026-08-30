# TASK-PROC-PURCHASING-PHASE2-SUPPLIER-INVOICE-PAYMENT-DECISION-RECORD-001

**Date:** 2026-08-21
**Type:** Decision record — **no implementation**
**Status:** DECISIONS APPROVED — IMPLEMENTATION GATE
**Changed by this task:** nothing. No code, schema, migration, permission, user, business data, or commit.

**Provenance note.** The task brief cites `PHASE2-ARCHITECTURE-AUDIT-002` as the source. The nine
decisions recorded here were actually produced by
**`TASK-PROC-PURCHASING-END-TO-END-CYCLE-ARCHITECTURE-AUDIT-001`** (§20 of that report).
`AUDIT-002` was the earlier receiving-anchor audit that preceded Phase 2 Part 1. Both are cited below
where each piece of evidence originates; the decision content is unchanged.

---

## Purpose and boundary

Enable this lifecycle **without restoring Purchase Order as a hidden source of truth**:

```
Purchase Material → Purchase Material Line → Goods Receipt → Supplier Invoice → Accounts Payable
```

Payment is explicitly **out of reach** in this cycle (Decision 4, §6).

---

# 1–4. The nine decisions: evidence, approved direction, rejected alternatives

## DECISION 1 — Supplier identity · APPROVED

**Source of truth:** `purchase_material_lines.supplier_id` (RD-1) whenever the receipt is
Purchase-Material anchored.

**Evidence.** `InvoiceReceiptAnchorService:123`:

```php
$anchorSupplier = (string) ($receipt->purchaseOrder?->supplier_id ?? '');
if ($anchorSupplier === '' || $anchorSupplier !== (string) $invoice->supplier_id) {
    throw InvoiceAnchorValidationException::supplierMismatch($anchorId);
}
```

The immediately preceding line (115) already grants **company** a fallback
(`purchaseOrder?->company_id ?? warehouse?->company_id`), so the fallback pattern is established in
the same method. A PM-anchored receipt has `purchase_order_id = NULL` (confirmed on GR-00001), so
`purchaseOrder` is null, the anchor supplier is empty, and every resolution throws. `basisFor()`
calls `resolve()` per positive-quantity line and the exception is deliberately uncaught, so the whole
invoice posting rolls back.

The data is already present: `GoodsReceiptLine::purchaseMaterialLine()` exists
(`GoodsReceiptLine.php:117`) and the PM line carries `supplier_id`.

**Approved direction.** Resolve the anchor supplier from the PO when present, otherwise from the
Purchase Material line. Required behaviour, unchanged from the certified contract:

- missing PM-line supplier → **refuse**
- mixed supplier anchors → **refuse**
- mixed receipt anchors → **refuse**
- tenant/company scope → **remains enforced**

**Affected paths (only these):** `InvoiceReceiptAnchorService`, `ApproveSupplierReturnAction`.

**Rejected alternatives.**
- Make Purchase Order mandatory on every receipt again — reverses Phase 2 Part 1 entirely.
- Add a `supplier_id` column to `goods_receipts` — creates a second supplier source of truth, which
  the brief forbids and which would drift from the PM line.
- Infer the supplier from product + date + proximity — the anchor contract exists precisely to
  forbid guessing.

## DECISION 2 — GR-00001 zero-cost receipt · APPROVED

**Treated as historical anomalous business data. Left unchanged.**

**Evidence.** Traced end to end on live data:

```
purchase_material_lines.agreed_price      = NULL
goods_receipt_lines.unit_price            = 0.00
goods_receipt_lines.landed_unit_cost      = 0.0000
inventory_receipt_layers.landed_unit_cost = 0.0000    <- 40 units capitalised at zero
finance_journal_entries                   = 0 rows
finance_posted_event_receipts             = 0 rows
```

`config('finance.integration.auto_subscribe')` is **true** — the bridge is live — and
`inventory.stock.received` **is** mapped in `EventPostingCatalog` (line 94). Nothing posted because
the translation drops what it cannot value: *"quantity without a cost cannot be valued here"*.
**GR-00001 therefore accrued no GRNI.**

**Approved direction.** Leave GR-00001 exactly as it is. It must be **explicitly flagged and
excluded** from any claim that the Purchase Material lifecycle represents financially correct
historical costing. Full handling rule in §7.

**Rejected alternatives.**
- Edit the FIFO layer `landed_unit_cost` directly — leaves ledger and journals inconsistent, and is
  a prohibited direct database mutation.
- Adjust the ledger by hand.
- Rewrite historical receipt rows.
- Delete the receipt.
- Invent an accounting reversal mechanism — none exists today; building one is a separate
  architecture decision.
- **Silently accept 0.00 as financially correct** — it is not; 40 units are capitalised at zero.

## DECISION 3 — `goods_receipt_line_id` contract · APPROVED

**Evidence.** `StoreSupplierInvoiceRequest` validates `product_id`, `quantity`, `unit_price`,
`tax_rate`, `discount_amount`, `uom_*`, `notes`. **`goods_receipt_line_id` is absent.**

Precisely: the field *is* in `SupplierInvoiceLine::$fillable`, and
`SupplierInvoiceController::syncLines()` uses `$request->input('lines')` (raw, not `safe()`) with
`array_merge`, so a client that sent the key **would** have it persisted — with **no** `exists`
check, **no** tenant scoping, **no** type check. That is an accidental passthrough, not a contract.
The frontend never sends it (0 matches in `features/supplier-invoices`).

**Approved direction.** Accept `lines.*.goods_receipt_line_id` as an explicit, validated field using
the **same security pattern already proven** for `purchase_material_line_id` in
`StoreGoodsReceiptRequest` — a `Rule::exists(...)->where(...)` constrained to the actor company.

Must reject: non-existent receipt line · receipt line of another company/tenant · invalid anchor ·
mixed incompatible anchors. No raw value may reach line synchronisation unvalidated.

**Why tenant scoping is non-negotiable.** Without it, a tenant can anchor its invoice to another
company receipt line and read that company `landed_unit_cost` through the GRNI and PPV legs — a
cross-tenant cost leak. The receipt request own comment documents that a bare global `exists:` is
exactly how the legacy `purchase_order_line_id` became a cross-tenant edge.

**Rejected alternatives.**
- Global unscoped `exists:` — the documented cross-tenant defect.
- Leave it as an unvalidated passthrough — no contract, no safety, invisible to the UI.

## DECISION 4 — Payment segregation of duties · APPROVED POLICY

**Maker and Approver MUST be different users. `approverCannotBeMaker()` is not to be weakened.**

**Evidence.** `AccountsPayableService::approvePayment()` throws `approverCannotBeMaker()` when
`(int) $payment->created_by === $approverId`. This is an **identity** check, not a permission check —
`grants()` returning true for a system role does **not** bypass it.

Live environment check (read-only):

| Fact | Value |
|---|---|
| Total users | **1** |
| System roles | `Super Admin` |
| Users holding a system role | 1 |
| Users holding `finance.ap.payment.create` via a non-system role | **0** |
| Users holding `finance.ap.payment.approve` via a non-system role | **0** |

Roles carrying the AP payment permissions exist and are seeded (CEO, CFO, COO, CTO, Finance Director,
Financial Controller, Senior Accountant, Finance Manager) but **no user is assigned to any of them**.

**Approved direction.** Payment E2E is **BLOCKED** until a second legitimate financial approver user
exists. Creating or configuring that user is an administrative operation requiring explicit separate
authorization. It will **not** be done as part of an engineering task, and no identity will be
fabricated.

**Rejected alternatives.**
- Weaken or remove `approverCannotBeMaker()`.
- Special-case Super Admin to bypass the identity check.
- Change the rule to make an acceptance scenario pass.
- Auto-create a second user.

## DECISION 5 — Payable source of truth · APPROVED

**`AccountsPayableService` is the sole authority for payable creation and posting.**

**Evidence.** `createDocument()` + `postDocument()` are invoked from exactly one place:
`PostSupplierInvoiceService`. Duplicate protection is `SupplierBill.number = 'SI-' . invoice id`,
checked before insert (`PostSupplierInvoiceService:269`). A sweep of `PostGoodsReceiptAction` finds
**zero** references to payable, GRNI, `SupplierBill`, `SupplierLedger`, or `AccountsPayable`.

**Approved direction.** The certified flow stays
`Supplier Invoice → AccountsPayableService → Payable`. No second payable path from Goods Receipt,
Inventory, GRNI, or Purchase Material receiving.

**Rejected alternatives.**
- Let receipt posting create a `SupplierBill` directly — produces two independent payable paths for
  the same delivery.
- Derive payable from the `goods_receipts.invoice_total_amount` / `paid_amount` scalars — these are
  descriptive only and carry no FK or AP effect.

## DECISION 6 — Supplier ledger writers · APPROVED

**Exactly two legitimate writers:** `AccountsPayableService` (bill + payment) and
`SupplierOpeningBalanceService` (opening balance).

**Evidence.** Codebase-wide sweep of `SupplierLedgerEntry::create(` returns hits in only those two
files. `SupplierLedgerService` performs reads only.

**Approved direction.** No third direct writer. If one is ever required → **STOP** and raise a
separate architecture decision. Where the repository already has a writer-guard pattern (the
write-route authorization guard), follow it. **Do not build a new guard architecture for this task.**

**Rejected alternatives.**
- Allow a module to adjust the balance directly.
- Invent a bespoke guard mechanism here.

## DECISION 7 — Supplier balance source · APPROVED

**`SupplierLedgerService` is canonical for outstanding payable.**

**Evidence.** Two different numbers both surface as "outstanding":

- `SupplierResource.total_outstanding` / `current_supplier_balance` → **ledger-derived** (correct).
- `SupplierResource.outstanding_balance` → `gr_agg.total_invoiced − gr_agg.total_paid`, built from
  hand-entered goods-receipt scalars over an **INNER JOIN to `purchase_orders`**
  (`EloquentSupplierRepository:29`).

Supplier 360 renders its Outstanding KPI from `analytics.outstanding_balance`
(`GetSupplierAnalyticsQuery`, PO-joined) — which is **always 0 by construction** for a Purchase
Material supplier. Separately, `outstandingPayable()` has two implementations: the PHP service and an
inline `selectRaw` in `EloquentSupplierRepository` documented as mirroring it "EXACTLY", with no
shared test pinning them together.

**Approved direction.** Outstanding Payable = `SupplierLedgerService`. Operational/volume analytics
are separate semantics and **must not be labelled "outstanding balance"** when they are not the
supplier ledger balance. No payable-balance surface may independently recalculate the authoritative
figure.

**Boundary:** the Supplier 360 / analytics divergence is a **separate follow-up**. It is **not** to
be mixed into the minimum E2E implementation unless the invoice workflow directly requires it — it
does not.

**Rejected alternatives.**
- Keep two competing "outstanding" figures on the same screen indefinitely.
- Fix the whole analytics layer inside this E2E scope.

## DECISION 8 — Supplier Returns · APPROVED DIRECTION

**Evidence.** `ApproveSupplierReturnAction:252`:

```php
$lineSupplierId = (string) ($line->goodsReceipt?->purchaseOrder?->supplier_id ?? '');
```

Identical defect to Decision 1. The return itself is already anchored on `goods_receipt_line_id`
(SR-2) and needs no PO structurally.

**Approved direction.** Returns resolve supplier from `purchase_material_lines.supplier_id` for
PM-anchored receipts, same fix shape as Decision 1. Affected path: `ApproveSupplierReturnAction`.

**Explicit boundary — financial effects are NOT in this E2E.** The audit finding stands: returns
store `credit_method` (`credit_note|refund|replacement`) but the flow creates **no supplier ledger
credit and no accounting effect**. Returns are financially inert today. That is a **separate GO-LIVE
financial decision** and must not be silently solved here.

**Rejected alternatives.**
- Add credit-note posting to returns as part of this work.
- Leave returns permanently unusable for PM-anchored receipts.

## DECISION 9 — Analytics · APPROVED

**GO-LIVE FOLLOW-UP. Not an E2E posting blocker. Not to be declared correct for PM receipts.**

**Evidence.** 15 `INNER JOIN purchase_orders` sites. Those that exclude PM receipts:

| Query | Sites | Effect |
|---|---|---|
| `GetSupplierAnalyticsQuery` | 5 | Supplier 360 KPIs — spend, outstanding, price history |
| `GetProcurementHealthQuery` | 4 | procurement health understated |
| `DemandAnalysisService` | 3 | demand/consumption misses PM inbound |
| `GetSupplierTimelineQuery` | 1 | PM receipts absent from the timeline |
| `EloquentSupplierRepository:29` | 1 | supplier grid volume columns |
| `ExpectedIncomingQuery:48` | 1 | **PO-native — not affected, not to be redesigned** |

**Approved direction.** Follow-up before go-live. None participates in posting; they misreport
rather than miscompute the ledger, so the E2E cycle can be proven from the ledger and the
ledger-derived columns. **GR-00001 is already invisible to all of them.**

**Rejected alternatives.**
- Treat as an E2E blocker.
- Ignore them and declare Purchasing production-ready.
- Fix all analytics inside the minimum invoice path.

---

# 5. Implementation sequence

| Phase | Scope | Files (expected) | Data impact | Focused tests |
|---|---|---|---|---|
| **A** — Supplier identity | D1 supplier fallback | `InvoiceReceiptAnchorService`, `ApproveSupplierReturnAction` *(only where D1 directly requires)* | none | anchor resolves via PM line; still refuses wrong supplier + cross-tenant |
| **B** — Receipt-line anchor | D3 contract | `StoreSupplierInvoiceRequest` (+ line sync path) | none | anchor accepted; foreign anchor rejected; unvalidated passthrough closed |
| **C** — Invoice E2E readiness | invoice references a PM-originated receipt; AP remains sole payable writer; duplicate protection intact | `PostSupplierInvoiceService` (verify only) | posting creates AP documents — **requires separate authorization** | payable creation, duplicate protection, tenant isolation |
| **D** — Payment | — | — | — | **BLOCKED** — see §6 |

**Minimum scope.** Only what establishes
`Purchase Material → PM Line → Goods Receipt → Supplier Invoice → Accounts Payable`.

**Explicitly not started:** Payment UI redesign · Supplier Analytics cleanup · Supplier Returns
financial redesign · Procurement Health redesign · Expected Incoming redesign · Purchase Order
removal · Finance architecture redesign.

**No migration is required for Phases A–C.** Every column already exists:
`supplier_invoice_lines.goods_receipt_line_id`, `goods_receipt_lines.purchase_material_line_id`,
`purchase_material_lines.supplier_id`, and the full `finance_supplier_*` set.

---

# 6. Payment blocker

**Phase D is BLOCKED. Two independent causes:**

1. **No second user.** The environment holds exactly **1 user** (`Super Admin`).
   `approverCannotBeMaker()` is an identity check that a system role does not bypass. One user
   therefore cannot both create and approve a payment — the block is absolute, not a permissions
   configuration issue.
2. **No payment UI.** `features/finance/services/finance-ap-service.ts` contains **only `GET`**
   calls; `accounts-payable-page.tsx` is three read-only tabs. No frontend call exists to
   `POST /finance/ap/payments`, `/approve`, `/post`, or `/allocate`. The backend is complete and
   unreachable.

**No bypass is to be implemented.** Creating the second financial approver is an administrative act
requiring explicit separate authorization.

---

# 7. Zero-cost historical receipt handling

**GR-00001 — 40 units of PKG-JAR-250 at `landed_unit_cost = 0.0000` — remains untouched.**

Standing rules:

- It is **not** financially correct data and must never be described as such.
- Any statement about Purchase Material lifecycle costing must **explicitly exclude/flag** it.
- It accrued **no GRNI**; an invoice posted against it today would clear an accrual that was never
  raised and book 100% of the invoice value as Purchase Price Variance.
- Future correction, if authorized, must be a **separately authorized Supplier Return + re-receipt /
  accounting workflow** — never a direct data edit. That path is itself currently blocked by the
  Decision 8 defect.

**Critical business-data rule — currently ACTIVE.**

`purchase_material_lines.agreed_price` for PM-00002 is **NULL right now**. Therefore:

> The remaining 60 units **must not be received** until the PM line carries a valid `agreed_price`.
> If it is missing at that moment → **STOP** (STOP condition 8).

Setting `agreed_price` is a legitimate operator action through the existing **Supplier tab → Confirm
Supplier** control, which already accepts it. It is **not** part of any engineering phase here.

---

# 8. Analytics follow-up

Recorded as **GO-LIVE FOLLOW-UP**, tracked, not fixed here. Affected: `GetSupplierAnalyticsQuery`,
`GetProcurementHealthQuery`, `DemandAnalysisService`, `GetSupplierTimelineQuery`,
`EloquentSupplierRepository`. `ExpectedIncomingQuery` is PO-native and stays as it is.

These surfaces **must not be declared correct for Purchase Material receipts** while the INNER JOINs
remain.

---

# 9. Supplier Return boundary

**In scope (Phase A, only where Decision 1 directly requires):** supplier resolution from the PM line
in `ApproveSupplierReturnAction`.

**Out of scope:** every financial effect of returns. `credit_method` remains stored without a ledger
credit or accounting entry. Returns stay financially inert until a separate GO-LIVE financial
decision. This must not be solved incidentally.

---

# 10. Testing boundary

- Extremely narrow. **No full ERP regression** after each change.
- Per phase, run only tests covering: supplier anchor · receipt-line anchor · tenant isolation ·
  payable creation · duplicate protection · the relevant existing contract.
- **Reuse existing suites** — `InvoiceReceiptAnchorTest`, `SupplierInvoiceFinancialPostingTest`,
  and the 46-test receiving gate (`PurchaseMaterialReceivingFoundationTest` 15,
  `GoodsReceiptTest` 23, `PurchaseMaterialSupplierSelectionTest` 8) as the regression guard.
- No duplicate suites. **No fabricated business data.**

---

# 11. Business-data safety boundary

**Must not be created:** fake suppliers · fake Purchase Materials · fake Purchase Orders · fake
receipts · fake payments · fake financial identities · users.

**Permitted:** inspecting existing real data (read-only).

**Any irreversible business posting — including posting a Supplier Invoice, which creates AP
documents and supplier ledger entries — requires separate explicit authorization.** Phase C
verification stops at the boundary of such a posting unless authorized.

---

# 12. STOP conditions

Stop immediately and report the exact blocker if:

1. a migration is required
2. a schema change is required
3. a new permission is required
4. a second source of truth is required
5. Purchase Order must become mandatory again
6. a financial identity must be fabricated
7. a historical receipt must be mutated to continue
8. the remaining PM quantity has no valid `agreed_price` — **currently ACTIVE: it is NULL**
9. an existing certified inventory/ledger contract must be changed

**A blocker is never to be resolved by weakening a business control.**

---

## Gate status

| Phase | Status |
|---|---|
| A — Supplier identity | ready to implement on approval |
| B — Receipt-line anchor | ready to implement on approval |
| C — Invoice E2E readiness | implementable; the **posting** step needs separate authorization |
| D — Payment | **BLOCKED** — second approver + payment UI |

**Nothing has been implemented. Awaiting explicit approval to begin Phase A.**
