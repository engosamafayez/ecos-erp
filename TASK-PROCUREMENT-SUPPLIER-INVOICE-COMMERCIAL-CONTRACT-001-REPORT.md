# TASK-PROCUREMENT-SUPPLIER-INVOICE-COMMERCIAL-CONTRACT-001 — Engineering Report

**Supplier Invoice — Creation + Commercial / Payment Contract Enhancement**
Date: 2026-08-29 · Branch: `develop` · Status: **COMPLETE** (verification narrow, per policy)

---

## Executive Summary

The Supplier Invoice is completed as the canonical supplier **commercial/financial** document — header
(incl. a secure attachment), product/material lines with two-way line calculation, canonical totals,
and a **payment section whose Paid / Remaining / Payment-Status are DERIVED** from the canonical AP
settlement authority — **without becoming a Warehouse-receipt or inventory-posting authority**. The
certified `PO → Goods Receipt → Inventory` chain is untouched; invoice approval never moves stock and
never creates a Goods Receipt. Everything reuses existing canonical authorities (`DocumentService`,
`syncLines()`/`recalculateTotals()`, `PaymentAllocation`/`SupplierBill`); no second receiving, payment,
or inventory authority was created, and no Finance journal posting was added. Nothing was committed,
pushed, deployed, or run against DEV business data (verification on the isolated test schema only).

## 1. Existing Supplier Invoice Architecture

`SupplierInvoice` / `SupplierInvoiceLine` (`Modules/Purchasing/SupplierInvoices`) with lifecycle
`SupplierInvoiceStatus` (Draft → Validated → AutoProcessing → Posted / Failed / Cancelled). Controller
exposes `index/store/show/update/destroy` + `validate/post/cancel`; posting is `PostSupplierInvoiceService`
(the Mode-3 auto-posting path / Mode-1 financial-only path). This task reused all of it; it added a
read-model on `show`, an attachment controller, and a two-way line-calculation UX — nothing in the
lifecycle or posting authority changed.

## 2. Header Fields

Reused as-is: Company (`company_id`, derived server-side from the warehouse = the tenant boundary),
Warehouse (`warehouse_id`), Supplier (`supplier_id`), Invoice Date (`invoice_date`), Supplier Invoice
Number/Reference (`supplier_invoice_ref` + system `invoice_number`), Due Date (`due_date`). The editor
reuses the existing supplier/warehouse selectors (`useSupplierOptions`, `useWarehouseOptions`). Company
is presented implicitly (no cross-company selector — an invoice's company is always the acting company's,
via the warehouse), which is the correct tenancy behaviour rather than a redundant/ misleading control.

## 3. Attachment

Reuses the canonical **`App\Core\Documents\DocumentService`** (generic `documents` table) — no new upload
system and **no schema change**. Files are stored on the **private `local` disk**
(`storage_path('app/private')`), owned by `subject_type='SupplierInvoice'`/`subject_id`, scoped by
`company_id`, and **streamed through an auth+permission-gated controller** (never a public path), exactly
like the certified `SupplierDocument` / `PaymentProof` pattern. New `SupplierInvoiceDocumentController`
(index/store/download/destroy); routes gated `purchasing.supplier_invoices.view` (read) /
`.edit` (write). Accepts pdf/jpg/jpeg/png/webp, max 20 MB. Every document query is additionally filtered
by subject + company (the `documents` table has no global tenant scope), and the invoice is resolved
through its own tenant-scoped route binding first.

## 4. Lines

Reuses `SupplierInvoiceLine` unchanged — Product/Material (`product_id`), Quantity (`quantity`), Unit
Price (`unit_price`), Line Total (`line_total`). No second invoice-line authority was created. The
settlement anchor `goods_receipt_line_id` is now surfaced on each line (read-only, §15).

## 5. Line Calculation

Canonical relationship `Line Total = Quantity × Unit Price (+ tax − discount)`, at the existing
decimal:4 money precision. The **backend stays authoritative**: `store`/`update` recompute `line_total`
via `syncLines()` and the client cannot supply it (it is not a request field — a client-sent value is
stripped and ignored, proven by test). The editor provides the approved two-way UX as a *preview* over
the same formula: changing Quantity or Unit Price recomputes Line Total; changing Line Total derives
Unit Price when Quantity > 0 (`deriveUnitPrice` = the exact inverse). React arithmetic is preview-only;
the persisted numbers are the backend's.

## 6. Tax / Discount Handling

Preserved exactly, not simplified: `syncLines()` computes `subtotal = qty × unit_price`,
`tax = subtotal × tax_rate/100`, `line_total = subtotal + tax − discount`, rounded to 4 decimals. The
editor keeps the VAT % field; discount remains a backend-supported field (defaulted to 0 in this editor,
not removed). The two-way derivation accounts for the tax rate in its inverse so it stays consistent with
this formula.

## 7. Totals

Mapped to the existing canonical fields — Items Total = `subtotal`, Transport = `freight_amount`,
Additional = `additional_costs`, Invoice Total = `grand_total`. No duplicate total columns were added.

## 8. Transport / Additional Costs

`Invoice Total = Items Total + Transport + Additional` (± the existing tax/discount rules), owned by the
canonical **`recalculateTotals()`** on the model — no alternative calculation engine. Proven: the two
expenses move `grand_total` by exactly their sum.

## 9. Payment Authority Audit

Audited `Modules/Finance/Payables`. The canonical applied-payment authority is **`PaymentAllocation`**
(`finance_payment_allocations`) — append-only, immutable, written only by
`AllocationEngine::allocatePayment()`, surfaced through `SupplierBill::allocatedAmount()` (sum of
allocations) and `SupplierBill::outstanding()` (`total − allocated`). A Supplier Invoice's AP bill is the
one `SupplierBill` whose `number` is the `'SI-'.$invoice->id` convention its writer
(`PostSupplierInvoiceService`) uses. **No second supplier-payment authority was created.**

## 10. Paid Amount

**Derived, never stored/editable.** New read-model `SupplierInvoicePaymentSummary` resolves the bill by
`company_id + 'SI-'.id` and returns `paid = $bill->allocatedAmount()` (sum of canonical
`PaymentAllocation` rows). When no bill exists yet (unposted, or Mode-1 unanchored where the payable is
skipped by design), Paid = 0 — nothing is fabricated. This deliberately does **not** reproduce the legacy
`goods_receipts.paid_amount` anti-pattern (a hand-entered, cash-unlinked figure the AP subledger replaced).

## 11. Remaining Balance

`Remaining = Invoice Total − Paid`, taken from `$bill->outstanding()` (canonical) when a bill exists, else
the invoice total. It is a derived read-model value; the UI shows it read-only and provides no editable
"remaining" field.

## 12. Payment Status

A derived read-model dimension (`unpaid` / `partially_paid` / `paid`) computed from Paid vs the reference
total — kept **separate** from the invoice document `status` (never collapsed). Surfaced as its own badge
in the editor and the detail drawer.

## 13. Due Date

Reused (`due_date`), editable in the header; persists via the existing store/update path.

## 14. Invoice Status

Lifecycle preserved unchanged. `Validated` remains the approval/eligibility gate (`canPost()`). No
rename/redesign; the editor's Validate action reuses the existing `validate` endpoint.

## 15. PO / Goods Receipt Linkage

`show` now returns `receipt_links`: for each invoice line carrying the canonical
`goods_receipt_line_id` (V-5) anchor, it resolves the Goods Receipt (`receipt_number`) and its Purchase
Order (`po_number`) — read-only navigation from **what the supplier invoiced → the physical receipt → the
order**, without merging the three documents. The line resource also exposes `goods_receipt_line_id`.

## 16. Ordered vs Received vs Invoiced Quantities

Surfaced as **distinct facts** per anchored line: Ordered = the receipt line's `ordered_quantity`,
Received = its `effectiveReceivedQty()` (net received), Invoiced = the invoice line's `quantity`. A
mismatch is shown, never silently reconciled.

## 17. Supplier Account Boundary

The Supplier Invoice remains the commercial source for the future Supplier Account / AP relationship; its
Paid/Remaining/Status are **read** from the AP subledger, not authored here. Physical receipt and financial
obligation stay linked but separate; the commercial balance may be partial while receipt is complete, and
vice-versa. No GL journal / AP posting was added by this task.

## 18. Inventory Boundary

HARD RULE upheld and proven: invoice **validation/approval creates no Goods Receipt and moves no
inventory** (validation only writes `status`; `PostSupplierInvoiceService` runs on `post`, and in
`goods_receipt` mode posts nothing). Selecting a warehouse on the invoice is reference-only. No path in
this task posts stock.

## 19. Desktop UX

One operational Sheet — General Information / Items / Totals / Payment / Attachment / Actions. Items use
an efficient grid with Product, Qty, Unit Price, **Line Total** (editable), VAT %, remove. Totals and the
derived Payment panel sit alongside. Actions: Save Draft / Save Changes, and Validate (draft) — reusing
canonical action names/endpoints.

## 20. Mobile UX

The line editor renders **stacked per-line cards** below `lg` (Product, then Qty / Unit Price / VAT% /
Line Total in a 2-col grid, with add/remove) instead of a wide spreadsheet; totals and payment stack
vertically; the primary Save/Validate action stays reachable. Desktop keeps the table-style editor.

## 21. Backend Changes

- **NEW** `SupplierInvoiceDocumentController` — attachment index/store/download/destroy via
  `DocumentService` (private disk, tenant-scoped, streamed download).
- **NEW** `SupplierInvoicePaymentSummary` — derived Paid/Remaining/Payment-Status read-model over the
  canonical `SupplierBill`/`PaymentAllocation`.
- **MODIFIED** `SupplierInvoiceController::show` — enriched with `payment` + `receipt_links`.
- **MODIFIED** `SupplierInvoiceResource` — exposes each line's `goods_receipt_line_id`.
- **MODIFIED** `routes/api.php` — four `supplier-invoices/{…}/documents` routes (view/edit gated).

No change to any certified authority, the lifecycle, `PostSupplierInvoiceService`, `goods_inward_mode`, or
the receiving/inventory path.

## 22. Frontend Changes

- **NEW** `invoice-line-calc.ts` (pure two-way formula helpers) + **NEW** `invoice-line-editor.tsx`
  (desktop grid + mobile cards, two-way calc).
- **NEW** `invoice-attachments.tsx` (upload/list/download/delete).
- **REWRITTEN** `supplier-invoice-editor.tsx` — create **and** edit, composes the line editor, totals, a
  Payment section (method editable; Paid/Remaining/Status derived read-only), and attachments.
- **MODIFIED** `supplier-invoices-page.tsx` — detail drawer gains a Payment card, PO/GR linkage, and
  attachments; Edit action; single combined editor state.
- **MODIFIED** `types` / `services` / `hooks` — payment + receipt-link + attachment contracts; attachment
  endpoints and hooks.
- **MODIFIED** i18n `en/ar/supplier-invoices.json` — payment/attachment/linkage keys (EN/AR parity).

## 23. Schema Changes

**None.** Attachment reuses the existing `documents` table; payment is derived from existing
`finance_supplier_bills` / `finance_payment_allocations`. No migration was added.

## 24. Files Changed

**Backend**
- `Modules/Purchasing/SupplierInvoices/Presentation/Http/Controllers/SupplierInvoiceDocumentController.php` *(new)*
- `Modules/Purchasing/SupplierInvoices/Application/Services/SupplierInvoicePaymentSummary.php` *(new)*
- `Modules/Purchasing/SupplierInvoices/Presentation/Http/Controllers/SupplierInvoiceController.php`
- `Modules/Purchasing/SupplierInvoices/Presentation/Http/Resources/SupplierInvoiceResource.php`
- `routes/api.php`
- `tests/Feature/Purchasing/SupplierInvoiceCommercialContractTest.php` *(new)*

**Frontend**
- `features/supplier-invoices/components/invoice-line-calc.ts` *(new)*
- `features/supplier-invoices/components/invoice-line-editor.tsx` *(new)*
- `features/supplier-invoices/components/invoice-line-editor.test.tsx` *(new)*
- `features/supplier-invoices/components/invoice-attachments.tsx` *(new)*
- `features/supplier-invoices/components/supplier-invoice-editor.tsx` *(rewritten)*
- `features/supplier-invoices/pages/supplier-invoices-page.tsx`
- `features/supplier-invoices/types/supplier-invoice.ts` · `services/supplier-invoices-service.ts` · `hooks/use-supplier-invoices.ts`
- `i18n/locales/en/supplier-invoices.json` · `i18n/locales/ar/supplier-invoices.json`
- `eslint-suppressions.json` (removed the now-obsolete `set-state-in-effect` entry the editor rewrite paid down)

## 25. Focused Verification

- **Frontend:** `tsc -p tsconfig.app.json` — **0 errors in the feature**; **ESLint 0**; **Vitest 5/5**
  (`invoice-line-editor.test.tsx`: line-total = qty×price(+tax); Line-Total→Unit-Price inverse; Qty→Line-Total;
  desktop grid **and** mobile stacked cards render); i18n EN/AR parity **195 = 195**.
- **Backend gate (isolated test schema, `RefreshDatabase`):** **OK — 12 tests / 55 assertions**
  (`scripts/test-gate.sh tests/Feature/Purchasing/SupplierInvoiceCommercialContractTest.php`): header
  persists (company derived from the warehouse); line_total & grand_total are **server-computed** and a
  client-supplied line_total is ignored (backend authoritative); transport/additional move the invoice
  total; payment is **unpaid** with no AP bill and **paid=100 / remaining=280 / partially_paid** derived
  from a canonical `PaymentAllocation`; **validation creates no Goods Receipt and moves no inventory** (§18);
  PO→GR→Invoice ordered/received/invoiced surfaced read-only (§15–§17); the attachment uploads to the
  **private** disk and streams back on download; upload is **forbidden without permission**; and
  unauthorized create/edit → **403**.
- **No DEV business data mutated; nothing committed/pushed/deployed.**

## 26. Remaining Gaps

- **Recording** an actual supplier payment (cash out + allocation) remains the Finance AP workstream's job
  (`SupplierPayment` → `AllocationEngine`); this task only **reads** the derived Paid/Remaining/Status.
- Attachment thumbnails/inline preview are not implemented (download only) — cosmetic.

## 27. Finance Handoff

The commercial contract (Invoice Total, derived Paid/Remaining, Due Date, Payment Status) is the source
for the future Supplier Account / AP integration. **No** GL journal / AP accounting / VAT / COGS posting
was implemented here — Finance integration remains a separate workstream, and the AP posting that does
exist (`PostSupplierInvoiceService` → `AccountsPayableService`) was left untouched.

## 28. Implementation Status

The Supplier Invoice is a complete commercial/financial document — header + secure attachment + two-way
line calculation + canonical totals + derived payment read-model + read-only PO/GR linkage + mobile UX —
reusing every canonical authority, changing no schema, and never becoming a receiving/inventory authority.

---

IMPLEMENTATION STATUS:
COMPLETE

FINAL CERTIFICATION:
DEFERRED TO FINAL SYSTEM REVIEW
