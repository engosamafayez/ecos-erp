# TASK-PROCUREMENT-SUPPLIERS-COMPLETION-CONTINUATION-002 — Engineering Report

**Date:** 2026-08-17 · **Branch:** `develop` · **Runtime:** MySQL 8.4.10 / PHP 8.4.24

## Executive summary

**FINAL STATUS: BLOCKED — §7 / STOP #1 (unanchored invoice contract cannot be determined)**

**Production changes made: NONE.** Objective A was answered by inspection; the answer is that **both**
candidate outcomes break something certified, and the repository contains no rule that resolves it.
Objective B is explicitly gated behind Objective A (§8: *"Once Objective A is resolved…"*), so it was
not attempted.

Per §7 I did not choose the business rule myself.

## Objective A — what the test actually proves

**Question 1 — what is `InboundOwnershipContractTest` proving?**

**Inventory ownership and idempotency. Nothing financial.** A grep across the whole file for
`SupplierBill`, `SupplierLedgerEntry`, `payable` and `finance_` returns **0 matches**. Every assertion
is inventory-side:

```
assertSame(9.0, $this->onHand($product));          // quantity
assertSame(1,   $this->inboundLedgerCount(...));   // stock ledger
assertSame(1,   $this->layerCount($product));      // FIFO layer
assertSame(SupplierInvoiceStatus::Posted, ...);    // document status
```

**Question 2 — which outcome?** **Outcome C.** The unanchored invoice is an inbound
ownership/idempotency **fixture**. It does **not** establish a Supplier Invoice *financial posting*
contract, so it cannot be cited as evidence that unanchored financial posting is valid.

That finding on its own would point to keeping the V-5 anchor mandatory and adjusting the fixtures
(§6, Outcome C). **It does not survive contact with the next fact.**

## Why Outcome C cannot be applied — the blocking ambiguity

`test_b_mode3_supplier_invoice_inbound_posts_once` creates a **Mode 3 invoice with no Goods Receipt
in existence at all** — only a Product and an invoice:

```php
$this->useMode3();
$product = Product::factory()->create();
$invoice = $this->invoice($product, qty: 7.0, unitPrice: 12.0);
$this->postInvoice($invoice);
```

There is **no receipt line to anchor to, and by design there never will be**: under G-1 Mode 3 the
Supplier Invoice *is* the inbound document. So:

| Candidate outcome | Consequence |
|---|---|
| **A — reject unanchored posting** | **Mode 3 standalone invoices become unpostable**, destroying a certified G-1 capability |
| **C — anchor the fixtures** | **Impossible for Mode 3** — the fixture cannot be given an anchor that cannot exist |
| **B — operational-but-unposted state** | Requires a documented "financially pending" concept; §6 forbids inventing a workflow state the architecture does not already support |

The anchor therefore **cannot be universally mandatory**, and the condition that would make it
conditional is precisely what no approved contract defines:

| Case | Contract status |
|---|---|
| **Mode 1 + anchored** | **Defined and implemented** — GRNI cleared at the stamped receipt valuation, PPV signed, VAT, AP, Supplier Ledger (Continuation-001 Part B) |
| **Mode 1 + unanchored** | **UNDEFINED** — no ADR, contract or test states whether the invoice should be rejected, posted without GRNI clearing, or held financially pending |
| **Mode 3 (anchor structurally impossible)** | **UNDEFINED** — no GRNI was ever accrued, so the payable debit belongs to the inventory account rather than GRNI; no rule specifies this entry |

**This is exactly §26 STOP #1**: *"The unanchored invoice contract cannot be determined from existing
repository evidence."*

## Why the previous behaviour was not simply retained

§7 prohibits keeping *"unanchored invoice → silently skip payable"* as final behaviour unless an
approved contract requires it. The inspection above proves **no such contract exists**, so I did not
retain it as approved — it remains in the code as the previous task's interim behaviour, now
explicitly flagged as unapproved and awaiting this ruling. Nothing was changed to entrench it.

## Decision required (owner)

**D-A1 — Mode 1, unanchored supplier invoice line.** Choose one:
1. **Reject** posting outright — deterministic, and consistent with V-5's "financial clearing requires
   deterministic receipt identity", but it makes an invoice unpostable until its receipt exists.
2. **Post the payable without GRNI clearing** (debit an expense/clearing account instead) — keeps
   invoices postable but leaves the GRNI accrual outstanding, which PART 19 of the earlier task
   forbids without an explicit model.
3. **Hold financially pending** — requires an approved "operationally accepted, financially unposted"
   state; the architecture has no such concept today.

**D-A2 — Mode 3 payable.** What does a Mode 3 invoice debit, given no GRNI was accrued? Presumably the
`@inventory_class` account, but that is an accounting ruling, not an inference I should make.

Both are business/accounting decisions. With **D-A1** answered, Objective B's six E2E cases can be
executed immediately — the machinery is already in place and proven at the AP layer.

## Objective B — not attempted

Gated behind Objective A by §8. The anchored path itself is already implemented and wired
(Continuation-001 Part B), and its three variance directions were proven at the
`AccountsPayableService` layer, but the end-to-end proof through `PostSupplierInvoiceService` was not
run because the contract governing which invoices may reach that path is unresolved.

## Preserved state (unchanged this task)

- **AP authority** — `AccountsPayableService` remains the sole AP/Supplier Ledger authority; untouched.
- **Finance change** — the authorised negative-net capability remains confined to the AP posting
  request builder; untouched and still backed by Finance **147/147**.
- **V-5 anchor**, `basisFor()`, quantity ceiling, tenant guards, C-1, FIFO — all untouched.
- **V-6 — NOT STARTED.** No receiving evidence, review, amendment proposal, approval or rejection code
  exists.
- **Goods Inward — NO CHANGE. PENDING FINAL USER BROWSER SMOKE.**

## Files changed · Migrations

**None**, and **no migration ran**.

## Worktree safety

`git status` / `git diff --cached` inspected before any work. The concurrent session's **staged**
deletion of `frontend/src/features/orders/components/order-reservation-cell.tsx` is **untouched** — no
`add`, `unstage`, `reset`, `checkout`, `clean` or commit was performed, and no global worktree command
was used.

## Tests run · Static checks

**None** — there is no change to verify. No test was modified, weakened or deleted. The suites from
Continuation-001 stand as last executed: Finance **147/147 (1587 assertions)**, Inbound Ownership
**15/15 (49 assertions)**, V-5 anchor **15/15 (36 assertions)**.

## Remaining work

1. **D-A1** and **D-A2** rulings (above) — the blocker.
2. Objective B: the six E2E cases through `PostSupplierInvoiceService` with persisted journal
   assertions, plus ceiling, FIFO, tenant, C-1 and idempotency proofs.
3. V-6 — Invoice Amendment workflow.
4. Goods Inward final user browser smoke.

## Final status

**BLOCKED**

Not certified, and not claimed to be. The inspection Objective A asked for was completed and produced
a clear, evidence-backed answer: the certified test defines **no** financial posting contract, and
because a Mode 3 invoice can have no anchor by design, the anchor cannot be made universally
mandatory. The rule that resolves this does not exist in the repository, so per §7 it is reported
rather than invented.

## Mobile notification status

Sent through the session's existing notification mechanism after this report was written; the tool
confirmed the push was requested. No notification infrastructure was created or modified.
