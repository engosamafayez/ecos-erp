# TASK-PROCUREMENT-SUPPLIER-INVOICE-UX-CLOSURE-001 — Engineering Report

**Supplier Invoice — Create/Edit UX Closure + Contract Presentation Fixes**
Date: 2026-08-29 · Branch: `develop` · Status: **COMPLETE** (verification narrow, per policy)

---

## 1. Executive Summary

Focused **UX/presentation** closure of the Supplier Invoice create/edit experience — no architecture,
authority, schema, or backend behaviour changed. The misleading "Mode 3 … posts directly to inventory"
copy is removed; the editor is reorganised into scannable sections (**General Information → Items →
Invoice Totals → Payment Summary → Actions**); Company shows as read-only canonical context; the
attachment, two-way line editing, derived payment read-model, and PO→GR linkage are presented clearly;
the desktop drawer is widened and the mobile stacked-card line editor is preserved. A small note makes
the receiving boundary explicit. Everything reuses the authorities from the prior task; approval still
never touches stock. FE-only — nothing committed, pushed, deployed, or run against DEV business data.

## 2. Existing UX Audit

Before: one long flat drawer — header grid, line grid, an "expenses + summary" block, a payment block,
attachments (edit only), actions — in a `sm:max-w-2xl` sheet, with a page subtitle claiming Mode-3
direct-to-inventory posting. The line grid felt crowded at that width. All backend contracts (payment
read-model, attachment API, two-way calc, linkage) were already in place from
TASK-…-COMMERCIAL-CONTRACT-001; this task is pure presentation.

## 3. Incorrect Architecture Copy

Removed the `page.subtitle` "Mode 3 Purchasing — supplier invoices post directly to inventory" and the
editor `subtitle` "…posts to inventory on confirmation". Replaced with architecture-correct wording
(EN + AR): *"Supplier commercial invoices linked to procurement and receiving. Inventory is updated
through Goods Receipt."* Verified absent by grep. `goods_inward_mode` behaviour is unchanged.

## 4. General Information Layout

A titled **General Information** section holds Company (read-only), Supplier, Warehouse, Invoice Date,
Supplier Reference, Notes, and the Invoice Attachment — a responsive 1-col (mobile) / 2-col (desktop) grid.

## 5. Company Context

Company is shown **read-only** from the canonical global context: `useOrganizationContext().activeCompanyId`
resolved to a name via the existing `useCompanyOptions()` (over `companiesService`) — no second Company
selector, no new endpoint. The user always sees which company the invoice belongs to; the server still
derives `company_id` from the warehouse.

## 6. Supplier / Warehouse

The existing canonical `useSupplierOptions` / `useWarehouseOptions` selectors are preserved — no
duplicate lookups. Both sit in the General Information grid, usable on desktop and mobile.

## 7. Attachment UX

The prior task's canonical attachment (`App\Core\Documents\DocumentService` via
`SupplierInvoiceDocumentController`, private disk, streamed download) is surfaced in General Information.
On **edit** the full `InvoiceAttachments` control renders (upload, filename, size, download, delete). On
**create** — where no invoice id exists yet — a clear hint asks the user to save first (the canonical
document owner requires the persisted invoice). No new upload system; no raw filesystem paths.

## 8. Items UX

A titled **Items** section renders the existing `InvoiceLineEditor` — Product, Quantity, Unit Price,
VAT %, Line Total per line, with add/remove. Backend-authoritative calculations are preserved.

## 9. Two-Way Line Editing

Verified and presented: Quantity change → Line Total recomputes from Unit Price; Unit Price change →
Line Total recomputes; **Line Total change → Unit Price derived when Quantity > 0**. The Line Total is a
normal editable numeric money input (not disabled). React provides the immediate preview only; saved
values still pass through the backend `syncLines()`/`recalculateTotals()`. Proven by
`invoice-line-editor.test.tsx` (compute + inverse + qty→total).

## 10. Canonical Line Formula

Preserved exactly (documented): `line_total = quantity × unit_price + tax − discount`, where
`tax = (quantity × unit_price) × tax_rate/100`, at decimal:4. It was **not** reduced to `qty × price`;
the two-way inverse (`deriveUnitPrice`) accounts for the tax rate so it stays consistent with the
backend. Discount remains a backend-supported field (0 in this editor, not removed).

## 11. Invoice Totals

A distinct **Invoice Totals** section: editable Transport (`freight_amount`) and Additional
(`additional_costs`) inputs beside a summary card mapping to the canonical fields — Items Total
(`subtotal`/line sum), Transport, Additional, and the emphasized **Invoice Total** (`grand_total`). No
duplicate total columns. All amounts render currency-aware via `useFormatter().money` (no hardcoded EGP).

## 12. Payment Summary

A distinct **Payment Summary** section: Payment Method (editable), Due Date (editable), and a read-only
Paid / Remaining / Payment-Status panel.

## 13. Paid Amount

Read-only, **derived** from the canonical `SupplierInvoicePaymentSummary` (over `PaymentAllocation`/
`SupplierBill`). No editable Paid field. Rendered currency-aware.

## 14. Remaining Balance

Read-only, `Invoice Total − canonical Paid`. No manual entry. A real zero renders as `0.00` (currency
format); before the invoice exists (create), the panel shows "available after saving" rather than a
fabricated figure.

## 15. Payment Status

The derived read-model status (Unpaid / Partially Paid / Paid) renders as its own badge, kept **separate**
from the invoice document status.

## 16. Payment Method Semantics

Payment Method stays the invoice's commercial field. Helper text states: *selecting a method does not
record a payment — actual payments are recorded in Accounts Payable* — so the UI never implies a payment
write. Actual settlement remains `PaymentAllocation`/`SupplierBill`.

## 17. Due Date

Lives once, inside the Payment Summary / commercial-terms context (not duplicated). Editable per the
existing lifecycle.

## 18. Invoice Lifecycle Actions

Unchanged lifecycle. Actions: **Save Draft / Save Changes** (create/update) and **Validate** (draft) —
reusing the existing endpoints; no new approval lifecycle. Primary (Save) vs secondary (Validate/Cancel)
are visually distinct.

## 19. Create UX

Same section hierarchy as edit. Create focuses on entering the commercial invoice; the attachment shows a
save-first hint and the payment panel shows "available after saving".

## 20. Edit UX

Same hierarchy, plus live attachment, the derived payment summary, document status (via the drawer/actions),
and a read-only **Procurement Linkage** section (PO → Goods Receipt → Invoice: ordered/received/invoiced)
when `receipt_links` exist. Structurally identical to Create.

## 21. Desktop Layout

Reorganised into titled sections with spacing (no heavy nested panels). The drawer widened to
`sm:max-w-3xl lg:max-w-4xl` so the line editor has room; it never becomes a horizontally scrolling
spreadsheet.

## 22. Mobile Layout

Mobile keeps the approved stacked line-card pattern (Product / Qty / Unit Price / VAT / Line Total),
sections stack vertically, totals read as a vertical currency-aware summary, and the primary Save action
stays reachable. The desktop line table is never squeezed onto phone width.

## 23. Receiving / Inventory Boundary

An explanatory note is shown at the top of the form: *"Inventory is updated through Goods Receipt, not by
Supplier Invoice approval."* No Receive Goods / Received Qty / Receiving Status / stock-posting control
exists in the Supplier Invoice form.

## 24. RBAC

Unchanged. No warehouse-receiving, inventory-posting, or Finance/AP payment-write authority is granted
through this UX closure; existing `purchasing.supplier_invoices.*` gating is untouched.

## 25. Files Changed

**Frontend only** (no backend, no schema):
- `features/supplier-invoices/components/supplier-invoice-editor.tsx` *(rewritten — sections, company
  context, boundary note, wider drawer, Invoice Totals + Payment Summary sections, edit-mode linkage,
  attachment placement, currency-aware totals)*
- `features/supplier-invoices/components/supplier-invoice-editor.test.tsx` *(new — section structure + edit payment/attachments)*
- `i18n/locales/en/supplier-invoices.json` · `i18n/locales/ar/supplier-invoices.json` *(Mode-3 copy fix + sections/company/boundary/payment-helper keys; EN/AR parity)*

## 26. Focused Verification

- **`tsc -p tsconfig.app.json`** — 0 errors in the feature.
- **ESLint** — 0 problems on the feature.
- **Vitest — 7/7**: `invoice-line-editor.test.tsx` (compute; Line-Total→Unit-Price inverse; Qty→Line-Total;
  desktop grid **and** mobile stacked cards render) + `supplier-invoice-editor.test.tsx` (section hierarchy
  renders; boundary note present; **no** "posts directly to inventory" copy; Company read-only context;
  payment-method helper; create-mode attachment hint + "available after saving"; **edit** shows derived
  Paid/Remaining/Status read-only + live attachments).
- **i18n EN/AR parity** — 206 = 206; Mode-3 copy absent (grep).
- **Backend invariants** (unchanged this task) remain covered by TASK-…-COMMERCIAL-CONTRACT-001's gate
  (12 tests / 55 assertions): approval creates no Goods Receipt / moves no inventory; Paid/Remaining
  derived from canonical allocations; selecting a payment method records no payment.
- **No DEV business data mutated; nothing committed/pushed/deployed.**

## 27. Remaining Gaps

- **Recording** a supplier payment (cash-out + allocation) and any GL/AP posting remain the Finance
  workstream — this UX only reads the derived figures.
- **Sibling latent bug (NOT touched, per §29):** `SupplierDocumentController::download()` has the same
  `: Response`-vs-`StreamedResponse` return-type issue that was fixed in the Supplier Invoice's own
  controller; recorded as a separate follow-up. The Supplier Invoice attachment path does not depend on it.
- Attachment thumbnails / inline preview not implemented (download only) — cosmetic.

## 28. Implementation Status

The Supplier Invoice create/edit UX is closed: architecture-correct copy, a scannable sectioned layout,
read-only Company context, clear attachment/two-way-editing/totals/payment presentation, a wider desktop
drawer, preserved mobile cards, and an explicit receiving boundary — reusing every canonical authority,
changing no backend/schema, and never becoming a receiving/inventory authority.

---

IMPLEMENTATION STATUS:
COMPLETE

FINAL CERTIFICATION:
DEFERRED TO FINAL SYSTEM REVIEW
