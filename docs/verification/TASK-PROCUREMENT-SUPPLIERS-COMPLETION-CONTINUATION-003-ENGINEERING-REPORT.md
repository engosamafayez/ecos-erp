# TASK-PROCUREMENT-SUPPLIERS-COMPLETION-CONTINUATION-003 — Engineering Report

**Date:** 2026-08-17 · **Branch:** `develop` · **Runtime:** MySQL 8.4.10 / PHP 8.4.24

## Executive summary

**FINAL STATUS: PARTIAL**

**Production changes made: NONE this task.**

| Item | Result |
|---|---|
| **D-A3 — Mode 3 valuation basis** | **RESOLVED from existing implementation** — no invention, **no STOP** |
| **D-A1 — Mode 1 unanchored rejection** | **NOT IMPLEMENTED** — scoped, with a certified-test impact identified |
| **D-A2 — Mode 3 AP posting** | **NOT IMPLEMENTED** |
| **Mode 1 / Mode 3 E2E** | **NOT RUN** — gated behind D-A1/D-A2 |

The gating unknown that blocked Continuation-002 is now closed: **D-A3 is answered from the
repository, not assumed.** What remains is implementation, and it is fully specified below.

## D-A3 — Mode 3 valuation basis: RESOLVED

**Source: the existing certified Mode 3 path in `PostSupplierInvoiceService`.** The basis is
`SupplierInvoiceLine.landed_unit_cost`, derived by the existing `allocateLandedCosts()`:

```php
$landed = round(((float) $line->unit_price + ($allocFrt + $allocAdd) / max((float) $line->quantity, 1)), 4);
```

— i.e. the invoice unit price plus that line's proportional share of freight and additional costs.

It is already the authority for **both** Mode 3 inventory effects today:

| Consumer | Value used |
|---|---|
| `postInboundToInventory()` → `ReceiveStockAction` (quantity + stock ledger) | `'unit_cost' => $line->landed_unit_cost ?? $line->unit_price` |
| `createCanonicalLayers()` → `CreateReceiptLayersAction` (FIFO layer) | `'landed_unit_cost' => $l->landed_unit_cost ?? $l->unit_price` |

So Mode 3 already values inventory at the invoice's landed unit cost, and the AP leg must use **the
same number** — reused exactly, with no new valuation rule, no FIFO guessing, and no fallback to
current product cost. **STOP #1 and #2 are not triggered.**

This also confirms the two authority models stay separate: Mode 1 values from the receipt's *stamped*
`landed_unit_cost`; Mode 3 values from the *invoice's* landed unit cost. Neither borrows the other's basis.

## D-A1 — Mode 1 unanchored: specified, not implemented

The ruling is unambiguous (reject, atomically, with no partial AP/GRNI/PPV/VAT/ledger effect), and the
change itself is small: in `PostSupplierInvoiceService::postSupplierPayable()`, the current
skip-and-log branch for a missing anchor becomes a thrown exception, which the existing transaction
already rolls back atomically — no new mechanism required.

**What makes it more than a one-line edit — and why I did not start it:**

Rejection changes the outcome of **at least three certified tests** that currently post Mode 1
invoices whose lines carry no anchor:

- `InboundOwnershipContractTest::test_c1_receipt_then_linked_invoice_does_not_double_post`
- `InboundOwnershipContractTest::test_g1_unlinked_receipt_and_invoice_post_once_in_receipt_mode`
- `InboundOwnershipContractTest::test_d_repeated_invoice_posting_is_idempotent`

Each of these **does** create a Goods Receipt, so an anchor *can* be added to the fixture while
preserving every assertion and the test's actual contract — note that `test_g1`'s "unlinked" refers to
`auto_receipt_id` at the **header**, which must stay NULL; adding a **line-level** anchor does not
touch that. This is exactly the fixture adjustment Continuation-002's Outcome C anticipated.

That is careful work on a certified suite and each verification cycle is a ~7-minute gated run. I
stopped rather than land a change that knowingly breaks certified tests without completing and
verifying the fixture updates in the same pass. **STOP #3 is not triggered** — the conflict is
resolvable by fixture, not by contract change — but it must be done as one complete unit.

## D-A2 — Mode 3 AP posting: specified, not implemented

With D-A3 settled the entry is fully determined and uses only existing authorities:

```
Dr @inventory_class   qty × landed_unit_cost      (role-resolved, same basis as the FIFO layer)
Dr vat_input          tax                          (configured VAT code, when tax applies)
   Cr ap_control         invoice total             (2110, via AccountsPayableService)
                                                   → SupplierLedgerEntry
NO GRNI line — nothing was ever accrued.
```

It reuses `AccountsPayableService` exactly as the Mode 1 leg does — no Mode 3 payable writer, no
second ledger writer, no synthetic receipt, no artificial GRNI.

## Mode 1 / Mode 3 E2E — not run

All seven Mode 1 cases (equal, unfavourable, favourable, multi-receipt zero, multi-receipt
favourable, quantity ceiling, tenant/C-1/idempotency/FIFO) and the Mode 3 cases are gated behind
D-A1/D-A2 and were not executed. The machinery beneath them is already proven: the three PPV
directions post correctly at the `AccountsPayableService` layer (Continuation-001), and the
anchor/basis layer is proven by the V-5 suite (15 tests, 36 assertions).

## Preserved state (unchanged this task)

- **AP authority** — `AccountsPayableService` remains the sole Supplier Payable / Supplier Ledger
  authority. Untouched.
- **Negative-net AP capability** — unchanged and still confined to the AP posting request builder;
  Finance regression stands at **147/147 (1587 assertions)**. **STOP #4 not triggered.**
- **V-5 anchor**, `basisFor()`, quantity ceiling, tenant guards, **C-1**, **FIFO**, G-1 Mode 1/Mode 3
  authority, D-1, D-INB-07, Supplier Return — all untouched.
- **V-6 — NOT STARTED.**
- **Goods Inward — NO CHANGE — PENDING FINAL USER BROWSER SMOKE.**

## Files changed · Migrations

**None**, and **no migration ran**.

## Worktree safety

`git status` / `git diff --cached` inspected. The concurrent session's **staged** deletion of
`frontend/src/features/orders/components/order-reservation-cell.tsx` remains **staged and untouched** —
no `add`, `unstage`, `reset`, `checkout`, `clean` or commit, and no global worktree command.

## Tests run · Static checks

**None this task** — there is no change to verify. No test was modified, weakened or deleted. Standing
results: Finance **147/147**, Inbound Ownership **15/15**, V-5 anchor **15/15**.

## Remaining work — fully specified, ready to execute

1. **D-A1**: convert the unanchored branch to a rejection **and**, in the same unit, add line-level
   anchors to the three `InboundOwnershipContractTest` fixtures named above (header `auto_receipt_id`
   stays NULL; no assertion weakened). Verify with a gated run.
2. **D-A2**: add the Mode 3 AP branch using the §D-A3 basis — `Dr @inventory_class`, `Dr vat_input`,
   `Cr ap_control`, no GRNI.
3. **E2E**: the seven Mode 1 cases and the Mode 3 cases through `PostSupplierInvoiceService`, asserting
   persisted journal lines, Supplier Ledger, GRNI and PPV.
4. V-6; Goods Inward final browser smoke.

## Final status

**PARTIAL**

Not certified, and not claimed to be. D-A3 — the decision that blocked the previous continuation and
the one STOP condition most likely to halt this one — is resolved from existing repository evidence,
and D-A1/D-A2 are now fully specified including their certified-test impact. I stopped short of
landing D-A1 because doing so correctly requires updating a certified suite's fixtures in the same
verified pass, and I did not have the working capacity remaining to complete and prove that in one
unit. Everything needed to execute it is recorded above.

## Mobile notification status

Sent through the session's existing notification mechanism after this report was written; the tool
confirmed the push was requested.
