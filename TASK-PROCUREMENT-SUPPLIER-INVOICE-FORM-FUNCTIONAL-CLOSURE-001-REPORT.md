# TASK-PROCUREMENT-SUPPLIER-INVOICE-FORM-FUNCTIONAL-CLOSURE-001 — Engineering Report

**Supplier Invoice — Create Form Functional Closure**
Date: 2026-08-29 · Branch: `develop` · Status: **PARTIAL** (4 of 5 gaps COMPLETE; payment entry BLOCKED on upstream AP authority)

---

## 1. Existing Form Audit

The create/edit editor (from the prior UX-closure) had: General Information / Items / Invoice Totals /
Payment Summary / Actions sections. Five review gaps remained: attachment required save-first; a single
ambiguous item selector; product search that only client-filtered a preloaded page; VAT defaulting to
15%; and no clear payment entry. Two backend audits (parallel) established the canonical facts below;
the work is frontend-focused (+ one additive resource read-field).

## 2. Attachment Save-First Root Cause

The canonical `App\Core\Documents\DocumentService` keys a document on an existing `subject_id`, so the
prior editor could only attach in **edit** mode and showed a "save first" hint on create.

## 3. Create-Time Attachment Design

The file is now **staged in form state** on the create form and uploaded automatically **after** the
invoice is created: `handleSave()` → `createSupplierInvoice` → obtains the new id → `uploadDocument(newId, file)`
through the existing `SupplierInvoiceDocumentController` (private disk, tenant-gated). No temporary/public
store; no raw filesystem paths. **Failure semantics (§3):** if the invoice is created but the upload
fails, the editor does **not** report total failure — it shows *"Invoice created. Attachment upload
failed — Retry"* and a **Retry** that re-uploads to the already-created invoice id. `createdId` gates
`handleSave` so Retry **never creates a second invoice** (proven by test). Verified: create→upload once;
fail→retry uploads twice while create stays at one.

## 4. Product vs Raw Material Domain Audit

**Raw Material is a `Product` with `product_type='raw_material'`, not a separate model** (one `products`
table; `Product::TYPES = finished_good | raw_material | packaging_material`; `InventoryClass::fromProductType`
recognizes it; BOM `raw_material_id` even FKs `products`). `SupplierInvoiceLine.product_id` → `products`
with **no type restriction**, so **one invoice can carry both Product and Raw-Material lines** using the
same line shape (§6 = YES, mixed allowed). Product-vs-raw-material is purely a type filter, never a
different line structure — so no duplicate master data and no mixed-line contract were invented.

## 5. Product Selection

Explicit **[ + Add Product ]** button adds a `entity_type: 'product'` line whose selector searches
`product_type=finished_good`. Each line shows a Product badge.

## 6. Raw Material Selection

Explicit **[ + Add Raw Material ]** button adds a `entity_type: 'raw_material'` line searching
`product_type=raw_material`. The ambiguous single "Add Item" is gone (§4). Each line represents exactly
one entity (§5); on **edit**, the line's entity type is restored from the invoice line's `product_type`
(a small additive resource read-field, §23).

## 7. Product Search Root Cause / Fix

**Root cause:** the editor called `useProductsQuery({ per_page: 200 })` (no `search`/`product_type`) and
`EcosCombobox` filtered that preloaded page **client-side only** (no async prop) — anything beyond the
first ≤100 rows (backend caps `per_page` at 100) was unreachable. **Fix:** a new feature-local
`ProductLineSelect` drives the typed term (debounced 250 ms) into the canonical searchable endpoint
`GET /products?search=&product_type=finished_good&status=active` and feeds results to the combobox with
client-filtering **off**. `EcosCombobox` gained an **additive, opt-in** `onSearchChange` +
`filterClientSide` (default true → existing callers unchanged). Verified: typing sends `search` to the
server with `product_type=finished_good`.

## 8. Raw Material Search Root Cause / Fix

Same `ProductLineSelect`, `product_type=raw_material` — a Raw-Material search never returns finished
goods and vice-versa. Verified.

## 9. Tax/VAT Current Policy

ECOS tax/VAT policy is **not activated**. Recorded: **Tax/VAT architecture = DEFERRED**. The future tax
engine was not built.

## 10. VAT Default Fix

The line default VAT is now **0%** (`EMPTY_LINE.tax_rate='0'` in the shared calc module → both initial
state and every Add-Product / Add-Raw-Material line). The backend `syncLines()` computes tax from the
**submitted** rate (`tax_rate ?? 0`), so 0 persists as 0 — no React-only-vs-backend mismatch (§11).
Verified by test; the two-way line math is unchanged.

## 11. Historical Tax Safety

No migration; the 0% default is prospective. Existing saved invoices keep their historical tax values
untouched (§12).

## 12. Payment Status UX

Because payment recording is not safely available at invoice-create (§13), the form does **not** present
a fake editable Unpaid/Partially-Paid/Fully-Paid control that writes nothing. It keeps the **derived,
read-only** Payment Summary (canonical status from allocations) and the commercial Payment-Method /
Due-Date fields, with a note that actual payments are recorded in Accounts Payable.

## 13. Supplier Payment Write Authority Audit

The canonical payment **writer chain exists** — `AccountsPayableService::createPayment → approvePayment
→ postPayment` + `AllocationEngine::allocatePayment` — but it is **not reachable from invoice-create**.
Recording "Paid > 0" requires **all** of: (a) a *posted* invoice + `SupplierBill` (the `SI-<id>` bill is
created only at invoice POST — nothing exists at draft-create to allocate to); (b) a funding GL account;
(c) maker≠checker approval; (d) an open fiscal period; (e) GL postings (`postPayment` always writes a
journal). There is **no drop-in "pay supplier invoice" action** — the only orchestration lives under
`finance/ap/payments/*`, split into five separately-permissioned Finance steps keyed on supplier + posted
bill. Per §21/§22 this is a **STOP condition**: no writer was fabricated, and no editable `paid_amount`
shadow field was added (the exact `goods_receipts.paid_amount` anti-pattern the AP subledger replaced).

## 14. Unpaid Behavior

Derived: no allocations → Paid = 0, Remaining = Invoice Total, status **Unpaid** (existing
`SupplierInvoicePaymentSummary`). Read-only.

## 15. Partial Payment Behavior

Derived: `0 < allocated < total` → **Partially Paid**, Remaining = Total − Paid (canonical
`SupplierBill::allocatedAmount()`/`outstanding()`). Read-only. Recording the partial payment itself is
the deferred AP flow.

## 16. Fully Paid Behavior

Derived: `allocated ≥ total` → **Paid**, Remaining = 0. Read-only.

## 17. Payment Method

The existing commercial `payment_method` (canonical vocabulary: bank_transfer / cheque / cash) stays
editable, with helper text that **selecting a method does not record a payment**. No new payment-method
enum was created (§17).

## 18. Paid / Remaining Derivation

Unchanged and canonical: Paid = sum of `PaymentAllocation`; Remaining = `SupplierBill::outstanding()`.
The form **reads** these; it never stores or edits them.

## 19. Inventory Boundary

Unchanged and preserved: Supplier Invoice creation/approval creates **no** Goods Receipt and moves **no**
inventory (covered by the commercial-contract gate). The receiving-boundary note remains on the form.

## 20. Backend Changes

- **MODIFIED** `SupplierInvoiceResource` — one additive read-field `product_type` per line (edit-mode
  entity typing). No behavior/authority/schema change. Re-verified: commercial gate **OK 12/55**.
- No other backend change: `/products` search, `syncLines` VAT handling, and the attachment controller
  already existed. **No payment writer added** (BLOCKED, §13).

## 21. Frontend Changes

- **NEW** `product-line-select.tsx` — debounced, type-filtered, tenant/active-scoped server search.
- **REWRITTEN** `invoice-line-editor.tsx` — Add Product / Add Raw Material, per-line entity type + badge,
  `ProductLineSelect` (no preloaded catalogue).
- **REWRITTEN** `supplier-invoice-editor.tsx` — create-time attachment staging + post-create upload +
  partial-success Retry; wires the new line editor; payment stays read-only/derived.
- **MODIFIED** `invoice-line-calc.ts` — VAT 0% default; `LineEntityType` + `entity_type`; `emptyLine()`.
- **MODIFIED (shared DS, additive)** `ui/ecos-combobox.tsx` — opt-in `onSearchChange` + `filterClientSide`.
- **MODIFIED** `types/supplier-invoice.ts` — `product_type` on the line.
- **MODIFIED** i18n `en/ar` — Add Product/Raw Material, search placeholders, attachment staging/retry
  (EN 219 = AR 219).

## 22. Schema Changes

**None.**

## 23. Files Changed

Backend: `Modules/Purchasing/SupplierInvoices/Presentation/Http/Resources/SupplierInvoiceResource.php`.
Frontend: `features/supplier-invoices/components/{invoice-line-calc.ts, invoice-line-editor.tsx,
product-line-select.tsx, supplier-invoice-editor.tsx}` (+ tests `invoice-line-editor.test.tsx`,
`product-line-select.test.tsx`, `supplier-invoice-editor.test.tsx`), `features/supplier-invoices/types/supplier-invoice.ts`,
`components/ui/ecos-combobox.tsx`, `i18n/locales/{en,ar}/supplier-invoices.json`.

## 24. Focused Verification

- **Frontend:** tsc feature-clean · ESLint 0 (incl. `ecos-combobox`) · **Vitest 13/13** (3 files):
  - Attachment — staged control renders on create; **Create → uploadDocument(newId)**; **failed upload →
    Retry uploads again with the same id, create called exactly once (no duplicate)**.
  - Item type — Add Product → product line; Add Raw Material → raw-material line; one entity per line.
  - Search — Product line queries `product_type=finished_good` + `status=active`; Raw-Material line
    queries `product_type=raw_material`; typed term goes to the **server** (debounced); client-filter off.
  - VAT — `EMPTY_LINE`/`emptyLine()` default 0%.
  - i18n EN/AR parity 219 = 219.
- **Backend:** commercial-contract gate re-run after the additive resource field — **OK, 12 tests / 55
  assertions** (approval still creates no Goods Receipt / moves no inventory; Paid/Remaining derived from
  canonical allocations; VAT computed from the submitted rate).
- **No DEV business data mutated; nothing committed/pushed/deployed.**

## 25. Remaining Gaps

- **Payment entry (BLOCKED, §13):** recording an actual supplier payment at invoice-create needs the
  Finance/AP flow (funding account, maker≠checker approval, open period, GL posting) against a *posted*
  bill. Requires a CTO/Finance decision + a canonical "pay supplier invoice" use-case (or an in-form AP
  integration). Until then the form only **reads** derived payment state.
- **Tax/VAT architecture = DEFERRED** (0% default is the interim policy).
- Sibling `SupplierDocumentController::download()` return-type bug — still recorded as a separate
  follow-up (not touched).

## 26. Implementation Status

Four of the five gaps are closed — create-time attachment (with safe partial-success retry), explicit
Add Product / Add Raw Material with correct single-entity lines, working server-side type/tenant-scoped
search, and VAT defaulting to 0% (backend-honored) — reusing canonical authorities and endpoints, with
no schema change and no new payment/receiving/inventory authority. The fifth (operator payment entry)
is honestly **BLOCKED** on an upstream AP payment-write integration.

---

## 27. Follow-up — AP Payment Integration (AP-PAYMENT-INTEGRATION-001)

The BLOCKED payment gap (§13, §25) was carried into
`TASK-PROCUREMENT-SUPPLIER-INVOICE-AP-PAYMENT-INTEGRATION-001` (see that report). Outcome:

- **Read integration ADDED (COMPLETE):** the invoice detail now shows Total / Paid / Remaining /
  Status / **Due** / **payment history**, all derived from the canonical AP authority
  (`SupplierBill` → immutable `PaymentAllocation` → `SupplierPayment`) — so a payment recorded through
  the canonical `finance/ap/payments/*` flow becomes visible on the invoice. No stored paid column.
- **Write side STILL BLOCKED:** recording an initial payment from the invoice form is architecturally
  blocked by five independent constraints — no payable exists for a commercial invoice in Mode 1
  without receipt anchors (bill only at POST); maker ≠ checker forbids single-operator recording; GL
  posting + open period + funding/expense GL accounts are required; it is a 6-step / 3-permission
  Finance workflow; and no AP write UI exists to hand off to. No shadow writer was fabricated.

**This report's status is unchanged — FORM-FUNCTIONAL-CLOSURE-001 remains PARTIAL** (operator payment
entry is not closed; it is deferred to a Finance/AP decision).

---

IMPLEMENTATION STATUS:
PARTIAL — attachment, Product/Raw-Material selection + search, and VAT 0% are COMPLETE; payment entry is BLOCKED on the canonical AP payment-write authority (no shadow field fabricated). Follow-up AP-PAYMENT-INTEGRATION-001 added the canonical read integration (payment history/total/due); the write side remains BLOCKED.

FINAL CERTIFICATION:
DEFERRED TO FINAL SYSTEM REVIEW
