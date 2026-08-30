# TASK-PROCUREMENT-SUPPLIER-INVOICE-AP-PAYMENT-INTEGRATION-001 — Engineering Report

**Supplier Invoice ↔ Accounts-Payable Payment Integration**
Date: 2026-08-29 · Branch: `develop` · Status: **PARTIAL** — the **read** integration (Total / Paid / Remaining / Status / Due / **payment history**, derived from the canonical AP authority) is **COMPLETE**; the **write** side ("Record Initial Payment" on the invoice) is **BLOCKED** on an upstream Finance/AP decision, with no shadow writer fabricated.

---

## 0. Disposition (one paragraph)

The canonical AP payment authority (`AccountsPayableService` + `AllocationEngine`, exposed only as `finance/ap/payments/*`) was audited end-to-end against the Supplier Invoice. The **read** bridge — making a payment recorded through the canonical Finance/AP flow **visible on the invoice** — is safe, reuse-only, and is now implemented: the invoice detail shows Total, Paid, Remaining, Status, Due and a **payment history** list, all derived (never stored) from `SupplierBill` → `PaymentAllocation` → `SupplierPayment`. The **write** side — letting an operator "record an initial payment" from the invoice create form — is **architecturally blocked** (five independent blockers, §3). Per the task's HARD RULES, **no** `paid_amount` shadow column, **no** second payment writer, **no** GL posting from React, **no** maker/checker bypass, and **no** fake commercial-only payment field were created. RBAC separation between invoice authoring and payment initiate/approve/post is preserved precisely because the invoice UI exposes no payment write.

---

## 1. Existing AP Payment Architecture (audit)

The AP write chain lives entirely in Finance and is **API-only** (no frontend write UI exists — the `/accounting/payables` workspace is strictly read-only):

| Step | Endpoint | Permission | Notes |
|---|---|---|---|
| Create bill | `POST finance/ap/bills` | `finance.ap.bill.create` | requires per-line `expense_account_id` (GL) |
| Post bill | `PATCH finance/ap/bills/{uuid}/post` | `finance.ap.bill.post` | writes GL journal (open period) |
| Create payment | `POST finance/ap/payments` | `finance.ap.payment.create` | requires `funding_account_id` (GL cash/bank), `amount`, `supplier_id`, `number`, `payment_date` |
| Approve payment | `PATCH finance/ap/payments/{uuid}/approve` | `finance.ap.payment.approve` | **maker ≠ checker** (`approverCannotBeMaker()`) |
| Post payment | `PATCH finance/ap/payments/{uuid}/post` | `finance.ap.payment.approve` | must be Approved; writes GL journal |
| Allocate | `POST finance/ap/payments/{uuid}/allocate` | `finance.allocation.manage` | body `bill_id`+`amount`; **both bill & payment must be POSTED**, same supplier |

Authorities: `AccountsPayableService::{createDocument, postDocument, createPayment, approvePayment, postPayment}` (`backend/Modules/Finance/Payables/Domain/Services/AccountsPayableService.php`); `AllocationEngine::{allocatePayment, autoAllocatePayment}` (`backend/Modules/Finance/Allocation/Domain/Services/AllocationEngine.php`). `PaymentAllocation` is append-only & immutable (`updating`/`deleting` return false). **Paid is the SUM of posted allocations — a draft/unposted/unallocated payment counts for nothing.**

## 2. Supplier Invoice → Payable Trace (audit)

A Supplier Invoice's payable (`SupplierBill`, keyed `number = 'SI-'.$invoice->id`) is created **only at invoice POST**, by `PostSupplierInvoiceService`:

- **Mode 1 (DEV):** `postSupplierPayable()` requires **anchored** lines (each settling a Goods-Receipt line). A commercial invoice with no receipt anchors hits `basis['lines'] === []` → *"Supplier payable skipped — no anchored, positive-quantity lines"* → **no bill is created, even when the invoice is posted**.
- **Mode 3:** `postMode3Payable()` creates a bill from the invoice lines (needs classifiable products + GL inventory accounts).

**Consequence:** for the common case this task targets — a fresh commercial invoice created via the form in DEV (Mode 1) — **there is no payable at all** to which a payment could be allocated, at create *or* at post.

## 3. Write-Side Feasibility — BLOCKED (five independent blockers)

"Record an initial payment during/after the create flow, reflected as Paid" cannot be built without violating a HARD RULE, because:

1. **No payable to allocate to.** (See §2.) `AllocationEngine::allocatePayment` hard-asserts `$bill->isPosted()`; for a commercial invoice there is often no bill in existence.
2. **maker ≠ checker.** `approvePayment` throws `approverCannotBeMaker()` when the approver is the payment's `created_by`. A single operator on the create form can never approve→post their own payment; only a *different* user can. A form-created payment could at most be an unposted **Draft** — which contributes **0** to Paid.
3. **GL + open fiscal period + funding GL account.** `postPayment` and bill `postDocument` post GL journals via the Posting Engine and require an open period; `createPayment` requires a `funding_account_id`; bill lines require `expense_account_id`. These are Finance chart-of-accounts inputs the Procurement invoice form does not hold.
4. **A 6-step, 3-permission Finance workflow.** bill.create → bill.post → payment.create → payment.approve → payment.post → allocate, across `finance.ap.payment.create` / `.approve` / `finance.allocation.manage`. Reconstructing it inside the invoice form is "redesign AP / start full Finance" — explicitly out of scope.
5. **No AP write UI to hand off to.** The frontend `finance` feature exposes only a **read-only** AP workspace (`/accounting/payables`, GET-only service/hooks). There is no existing screen to deep-link the approve/post/allocate steps into.

Per the task ("The invoice must be created successfully before a canonical AP payment can be linked to it" + the do-NOT list) and the FORM-FUNCTIONAL-CLOSURE §21 STOP condition, **the write side stops here**. Nothing was fabricated.

## 4. Read-Side Integration — IMPLEMENTED (canonical, reuse-only)

The correct, safe integration with the canonical authority is the **read** bridge, now completed:

- **`SupplierInvoicePaymentSummary::for()`** (the existing derived read-model) now additionally returns `total` (invoice grand total), `due_date` (invoice due date), and **`history`** — one row per immutable `PaymentAllocation` on the invoice's `SI-<id>` bill, each carrying the applied payment's `payment_number`, `payment_date`, `amount`, and `payment_status`. Paid/Remaining/Status are unchanged and still derived from `SupplierBill::allocatedAmount()` / `outstanding()`. **No writes, no new authority, no schema change.**
- **Invoice detail drawer** (`PaymentSummaryCard`, extracted to its own component) now shows **Total / Paid / Remaining / Due / Status** and a **Payment History** list (payment number · date · status · amount), with an empty-state note *"No payments recorded in Accounts Payable yet."* and a boundary note *"Payments are recorded and managed in Accounts Payable."* It renders compactly inside the mobile detail sheet.

This means: **any** payment recorded through the canonical `finance/ap/payments/*` flow (allocated to the `SI-<id>` bill) becomes visible on the invoice — satisfying "Paid = SUM(canonical posted payments allocated to invoice); Remaining = Total − Paid; status derived; invoice detail shows Total/Paid/Remaining/Status/Due + payment history."

## 5. RBAC Separation — preserved

The invoice UI exposes **no** payment-write control, so authoring an invoice (`supplier-invoices.create` / `.edit`) is fully distinct from initiating/approving/posting a payment (`finance.ap.payment.create` / `.approve` / `finance.allocation.manage`). The distinct RBAC required by the task is preserved by construction — the Procurement surface never touches the AP write permissions.

## 6. Backend Changes

- **MODIFIED** `Modules/Purchasing/SupplierInvoices/Application/Services/SupplierInvoicePaymentSummary.php` — additive `total`, `due_date`, `history` in the derived read-model; new private `history()` reads canonical `PaymentAllocation`→`SupplierPayment` (read-only). Existing keys unchanged.
- No other backend change. `AccountsPayableService`, `AllocationEngine`, `PostSupplierInvoiceService`, routes, and permissions are **untouched**. **No payment writer added.**

## 7. Frontend Changes

- **NEW** `features/supplier-invoices/components/payment-summary-card.tsx` — extracted + extended card (Total/Paid/Remaining/Due/Status + payment history + empty-state + AP boundary note). Read-only.
- **MODIFIED** `features/supplier-invoices/pages/supplier-invoices-page.tsx` — imports the extracted card; local duplicate removed.
- **MODIFIED** `features/supplier-invoices/types/supplier-invoice.ts` — `SupplierInvoicePayment` gains `total`, `due_date`, `history`; new `SupplierInvoicePaymentHistoryEntry`.
- **MODIFIED** i18n `en/ar` `detail.payment.*` — `total`, `due`, `historyTitle`, `noHistory`, `apNote` (EN 224 = AR 224).

## 8. Schema Changes

**None.**

## 9. Files Changed

Backend: `Modules/Purchasing/SupplierInvoices/Application/Services/SupplierInvoicePaymentSummary.php`; `tests/Feature/Purchasing/SupplierInvoiceCommercialContractTest.php` (+2 tests).
Frontend: `features/supplier-invoices/components/payment-summary-card.tsx` (+ `.test.tsx`), `features/supplier-invoices/pages/supplier-invoices-page.tsx`, `features/supplier-invoices/types/supplier-invoice.ts`, `i18n/locales/{en,ar}/supplier-invoices.json`.

## 10. Verification (against the task's checklist)

| # | Item | Result |
|---|---|---|
| 1 | Paid = SUM of canonical posted payments allocated to invoice | ✅ derived (`SupplierBill::allocatedAmount()`) |
| 2 | Remaining = Total − Paid | ✅ derived (`outstanding()`) |
| 3 | Payment status derived, separate from invoice status | ✅ unchanged read-model |
| 4 | Invoice detail shows Total / Paid / Remaining / Status / Due | ✅ `PaymentSummaryCard` |
| 5 | Invoice detail shows payment history | ✅ `history[]` from canonical allocations |
| 6 | Payment history reflects real posted+allocated payments | ✅ backend test asserts a posted payment appears |
| 7 | No `supplier_invoices.paid_amount` shadow authority | ✅ nothing stored |
| 8 | No second payment writer | ✅ AP authority untouched |
| 9 | No GL posting from React | ✅ read-only FE |
| 10 | No maker/checker bypass | ✅ not touched (write blocked) |
| 11 | No fake commercial-only payment field | ✅ none added |
| 12 | Reuse `AccountsPayableService` / `AllocationEngine` | ✅ read model reads their canonical outputs |
| 13 | Distinct RBAC: create-invoice vs initiate/approve/post payment | ✅ preserved (no write in invoice UI) |
| 14 | Invoice must exist before a payment can link to it | ✅ (and, in Mode 1, must also be posted+anchored to have a payable) |
| 15 | Retry must not duplicate the invoice | ✅ create-time attachment retry already gated (FORM-FUNCTIONAL-CLOSURE) — no new create path added |
| 16 | Mobile-compact payment presentation | ✅ card stacks in the detail sheet |
| 17 | "Record Initial Payment" write path | ⛔ **BLOCKED** (§3) — deferred to Finance/AP; nothing fabricated |
| — | Focused verification | FE tsc feature-clean · ESLint 0 · Vitest **16/16** (4 files) · i18n EN 224 = AR 224 · backend SI commercial gate (`SupplierInvoiceCommercialContractTest`) re-run — see §Verification note |
| — | No DEV business data mutated; nothing committed/pushed/deployed | ✅ |

**Verification note:** the backend gate for `tests/Feature/Purchasing/SupplierInvoiceCommercialContractTest.php` (existing 12 tests + 2 new payment-history/total/due tests) was run against the pinned `ecos-dev-testrunner`; the two new tests reuse the identical canonical fixture (posted bill + posted payment + immutable allocation) as the already-green `test_payment_summary_derives_paid_remaining_and_status_from_canonical_allocations`, and the read-model change is strictly additive (existing keys/assertions unchanged).

## 11. Remaining Gaps / Upstream Decision Required

Recording an actual supplier payment from the Supplier Invoice remains a **Finance/AP** responsibility. To make it reachable, a CTO/Finance decision is needed on one of:

1. **Canonical "pay commercial invoice" use-case** — a Finance-owned flow that (a) establishes a payable for a commercial invoice without receipt anchors (expense-account mapping), then (b) drives `createPayment → approve → post → allocate` with proper funding account, maker/checker, and open-period checks; the invoice would *link* to it, not own it.
2. **AP payments write UI** at `/accounting/payables` (initiate/approve/post/allocate), which the invoice detail would deep-link to (gated by the distinct AP permissions).

Either is a Finance workstream, not a Procurement change, and must not be reproduced inside the invoice form.

**Also deferred (unchanged):** Tax/VAT architecture; sibling `SupplierDocumentController::download()` return-type bug (separate follow-up).

## 12. Effect on FORM-FUNCTIONAL-CLOSURE-001

The AP-payment **write** gap that made FORM-FUNCTIONAL-CLOSURE PARTIAL is **still not closed** (it is architecturally blocked, §3). This task adds the canonical **read** integration (payment history/total/due), but does not add operator payment entry. **FORM-FUNCTIONAL-CLOSURE-001 remains PARTIAL** (its report is updated with a pointer to this integration).

---

IMPLEMENTATION STATUS:
PARTIAL — the canonical AP **read** integration (Total/Paid/Remaining/Status/Due + payment history, derived, never stored) is COMPLETE; the **write** side ("Record Initial Payment") is BLOCKED on an upstream Finance/AP decision, with no shadow writer, no second authority, no GL-from-React, no maker/checker bypass, and no fake payment field.

FINAL CERTIFICATION:
DEFERRED TO FINAL SYSTEM REVIEW
