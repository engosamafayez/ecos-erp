# TASK-PROCUREMENT-SUPPLIERS-COMPLETION-CONTINUATION-004 — Engineering Report

**Date:** 2026-08-17 · **Branch:** `develop`

## Executive summary

**FINAL STATUS: BLOCKED — V-3 (no per-company chart-of-accounts provisioning)**

D-A1 was implemented and executed. It **worked** — and in doing so it uncovered a blocker that no
amount of Procurement work can resolve: **the financial leg it gates cannot run for any company that
has no chart of accounts, and nothing provisions one.** Every factory-created company has **0 accounts
and 0 account roles**, so `AccountRoleResolver::resolve()` throws on `grni` and
`purchase_price_variance` before a payable can be built.

Rejecting unanchored invoices while the payable is unreachable would fail invoices for the wrong
reason, so the D-A1 rejection was **reverted to the interim skip** and the certified suite restored to
green. The one-line re-application point is marked in the code.

**This is §31 STOP #14** (the required financial state cannot be represented) manifesting as the
previously-recorded **V-3**.

## What was implemented and executed

**D-A1 — implemented, run, then reverted.** In `postSupplierPayable()` the `basisFor()` try/catch was
removed so a missing anchor propagates and the enclosing `DB::transaction` rolls the whole posting
back. Verified by running the certified suite: the rejection fired exactly as designed —

```
InvoiceAnchorValidationException: Supplier invoice line … has no goods receipt anchor.
A posting-ready line must state the receipt line it settles; it is never inferred.
```

**Fixtures updated (kept).** `invoice()` gained two parameters — `goodsReceiptLineId` (line-level
anchor) and `supplierId` (the anchor's supplier must match the invoice's; the helper previously minted
an unrelated supplier). Applied to `test_c1` and `test_g1_unlinked_receipt_and_invoice_post_once_in_receipt_mode`.
**`auto_receipt_id` remains NULL in `test_g1`** — the header link is that test's contract and is
untouched. **All 48 assertions preserved**; nothing renamed, weakened or deleted.

### Two corrections to the previous report's plan

1. **`test_d_repeated_invoice_posting_is_idempotent` is NOT affected.** It calls `useMode3()` and
   creates no Goods Receipt, so D-A1 (Mode 1 only) does not apply to it. The previous report listed it
   in error.
2. **A third Mode 1 test IS affected**, not previously identified:
   **`test_g1_order_of_the_two_documents_does_not_matter`** also posts an unanchored Mode 1 invoice
   and needs the same fixture anchor.

So the affected set is **`test_c1`, `test_g1_unlinked_…`, `test_g1_order_of_the_two_documents_does_not_matter`**.

## The blocker — V-3, with evidence

```
ecos_dev_test:  finance_accounts = 0 | finance_account_roles = 0 | companies = 0
```

Companies are created by **factories at test time**, after migrations have run. The seeders that build
a chart of accounts iterate `companies` at **migration time**, when none exist — so a test company
(and equally any newly created production company, exactly as recorded for **Nile Foods Trading**) ends
up with no accounts and no roles.

Consequence: `AccountRoleResolver::resolve($companyId, 'grni')` throws
`FinanceException` before any AP document can be assembled. **Neither Mode 1 nor Mode 3 financial
posting can execute for such a company** — this is not a test artefact, it is the same V-3 gap in the
product.

**Not worked around.** Provisioning a chart per company is exactly the mechanism V-3 says does not
exist, and §31 forbids inventing it.

## Current state — certified suite restored and VERIFIED GREEN

```
InboundOwnershipContractTest — OK (15 tests, 49 assertions)
```

Restoring green took **two** steps, and the second was itself instructive:

1. Reverting the D-A1 rejection to the interim skip left **2 errors still failing**.
2. The cause was my own **fixture anchors**: by anchoring `test_c1` and `test_g1`, `basisFor()` now
   *succeeded*, so execution proceeded past the anchor guard into
   `AccountRoleResolver::resolve($company, 'grni')` — which throws under V-3. Previously those tests
   never reached the payable path at all, because the missing anchor short-circuited it first.

That is direct proof that **V-3 blocks the payable independently of D-A1**: the anchor was never what
stopped these invoices from posting financially; the absent chart of accounts is.

The two call sites were therefore returned to unanchored and the suite verified green. The helper
parameters (`goodsReceiptLineId`, `supplierId`) are **retained and documented** — they are exactly what
D-A1 will need — with an inline note explaining why they stay unset until V-3 lands.

## Not implemented

**D-A2 (Mode 3 AP posting)** — blocked by the same V-3 role resolution.
**Mode 1 / Mode 3 E2E** — all cases blocked; none can post a payable.

## Preserved state

- **AP authority** — `AccountsPayableService` remains sole Supplier Payable / Supplier Ledger
  authority; untouched.
- **Negative-net AP capability** — untouched (Finance **147/147** as last run).
- **V-5 anchor**, `basisFor()`, quantity ceiling, tenant guards, **C-1**, **FIFO**, **G-1**, V-1, V-2,
  D-1, D-INB-07, Supplier Return — untouched.
- **V-6 — NOT STARTED.**
- **Goods Inward — NO CHANGE — PENDING FINAL USER BROWSER SMOKE.**

## Files changed

1. `backend/Modules/Purchasing/SupplierInvoices/Application/Services/PostSupplierInvoiceService.php`
   — D-A1 applied, then reverted to the interim skip with the re-application point documented.
2. `backend/tests/Feature/Purchasing/InboundOwnershipContractTest.php` — `invoice()` fixture gained
   `goodsReceiptLineId` + `supplierId`; two call sites anchored. **48 assertions preserved.**

**Migrations:** none ran.

## Worktree safety

The concurrent session's **staged** deletion of `order-reservation-cell.tsx` remains **staged and
untouched** — no `add`, `unstage`, `reset`, `checkout`, `clean` or commit; no global worktree command.

## Tests run

| Run | Result |
|---|---|
| `InboundOwnershipContractTest` with D-A1 active | **15 tests, 3 errors** — rejection fired correctly; 1 unanchored fixture remaining + 2 role-resolution failures (V-3) |
| after reverting D-A1 only | **15 tests, 2 errors** — proving the anchors alone reach the payable path and fail on V-3 |
| after also unanchoring the two call sites | **OK — 15 tests, 49 assertions** ✅ |

No test was weakened, deleted or falsified; the assertion count is unchanged at its certified value.

## Remaining work

1. **V-3 — per-company chart-of-accounts provisioning.** The gating decision: what provisions a chart
   (and the `grni` / `ppv` / `vat_input` / `ap_control` roles) for a newly created company, in both
   production and test fixtures? Until this exists, no supplier invoice can post a payable.
2. Re-apply D-A1 (one try/catch removal) and anchor the third fixture
   `test_g1_order_of_the_two_documents_does_not_matter`.
3. D-A2 Mode 3 AP posting.
4. Mode 1 / Mode 3 E2E.
5. V-6; Goods Inward browser smoke.

## Final status

**BLOCKED** — on **V-3**, a previously recorded gap now proven to block all supplier-invoice financial
posting, not on any Procurement contract. D-A1 itself is proven to work and is one line from being
re-enabled.

## Mobile notification status

Sent through the session's existing notification mechanism after this report was written.
