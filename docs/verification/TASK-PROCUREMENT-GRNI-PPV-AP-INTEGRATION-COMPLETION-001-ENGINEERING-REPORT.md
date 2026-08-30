# TASK-PROCUREMENT-GRNI-PPV-AP-INTEGRATION-COMPLETION-001 — Engineering Report

**Date:** 2026-08-17 · **Branch:** `develop` · **Runtime:** MySQL 8.4.10 / PHP 8.4.24

## Executive summary

**FINAL IMPLEMENTATION STATUS: BLOCKED — STOP CONDITIONS #3 AND #14**

**Production changes made: NONE.** Only read-only inspection and one diagnostic executed inside a
rolled-back transaction.

The integration is **half-representable**, and shipping the half that works would be worse than
shipping nothing:

| Case | Result through `AccountsPayableService` |
|---|---|
| **Unfavourable** (invoice > receipt) | **POSTS CORRECTLY** — proven at runtime |
| **Favourable** (invoice < receipt) | **REFUSED BY THE ENGINE** — `Unbalanced: debits 40000 ≠ credits 36000` |

A favourable variance requires a **credit to PPV**. `AccountsPayableService::buildDocumentPostingRequest()`
can emit only an AP-control credit plus **positive** line and tax debits; a negative line net is
silently discarded, leaving the journal unbalanced. Since §10 of this task explicitly requires the
favourable case (the multi-receipt scenario yields −800 favourable), integrating now would produce a
system that posts some supplier invoices and throws a hard `FinanceException` on others.

Per §21 I did not work around it.

---

## Current V-5 state · What was already existing

Verified against the current tree, not assumed from the prior report. Everything V-5 delivered is
present and reusable — **nothing was reimplemented**:

| Asset | State |
|---|---|
| `supplier_invoice_lines.goods_receipt_line_id` | present, `char(36)` nullable, real FK → `goods_receipt_lines`, indexed |
| `InvoiceReceiptAnchorService` | present — `resolve()`, `receiptValuation()`, `invoiceable()`, `basisFor()` |
| `InvoiceAnchorValidationException` | present — company/supplier/product/quantity guards |
| Anchor test suite | present — 15 tests, 36 assertions, green |
| AP control `2110` | resolves (D-1) |
| PPV role → `5180` | resolves (V-2) |
| GRNI role → `2120` | resolves |
| VAT `VAT14` → input `1530` | resolves (V-1) |
| C-1 lock in `PostSupplierInvoiceService` | present and untouched |

## Phase B — dependency verification, and where it breaks

The intended chain was traced end to end. **Seven of eight links work.** The break is at the last one:

```
Supplier Invoice → Receipt Anchor ✓ → Receipt Valuation ✓ → GRNI ✓ → PPV ✓(debit only) → VAT ✓ → AP ✓ → Supplier Ledger ✓
                                                                        ↑
                                                        favourable variance needs a CREDIT — not expressible
```

## The blocker, with runtime evidence

`AccountsPayableService::buildDocumentPostingRequest()` builds exactly three kinds of line:

1. the AP control, **credited** with `$bill->total`;
2. each bill line's `expense_account_id`, **debited** — but only `if ($net > 0.0)`;
3. each tax account, **debited** — only `if ($tax > 0.0)`.

There is **no path that emits a credit to a non-control account**, and a negative line net is skipped
rather than inverted.

Both cases were executed against the real engine, inside a transaction that was rolled back
(`finance_supplier_bills` returned to 0 — no data created):

**Unfavourable — receipt 40,000, invoice 44,000:**

```
  2110 Trade Payables               Cr 44,000
  2120 Goods Received Not Invoiced  Dr 40,000
  5180 Purchase Price Variance      Dr  4,000
```

Exactly the required entry, produced by the canonical service with no modification.

**Favourable — receipt 40,000, invoice 36,000** (GRNI 40,000 debit, PPV −4,000):

```
  bill total = 36,000
  FAILED: FinanceException — Posting refused: Unbalanced: debits 40000 ≠ credits 36000
```

The PPV line was dropped by the `$net > 0.0` filter; the ledger engine correctly refused the
unbalanced journal.

### Alternatives considered and rejected

| Alternative | Why rejected |
|---|---|
| Post the PPV credit outside `AccountsPayableService` | Creates a second posting path — forbidden by §1/§5 and STOP #5 |
| Issue a `CreditNote` supplier document for the difference | Produces a **second `SupplierLedgerEntry`**, so the supplier balance becomes 40,000 then −4,000 instead of 36,000; PPV is not a supplier-facing document; breaks one-posting-per-invoice and idempotency |
| Debit GRNI at the invoice value (36,000) and omit PPV | Leaves a permanent 4,000 GRNI residual — defeats GRNI clearing and the entire purpose of V-5 |
| Modify the `$net > 0.0` filter to invert negatives | The correct fix, **but it changes the line-emission semantics of the certified Finance authority for every AP document platform-wide** — STOP #3, and an owner decision |

### Minimum architectural change required (NOT made)

In `AccountsPayableService::buildDocumentPostingRequest()`, allow a signed line net: where
`$net < 0`, emit `directional($account, abs($net), ! $debit, …)` instead of skipping. Roughly three
lines, entirely inside the existing authority — no new engine, no new account, no new ledger writer.
It affects **all** supplier documents (bills, credit notes, debit notes), so it belongs to the
Finance owner, not to this task.

**Affected file:** `backend/Modules/Finance/Payables/Domain/Services/AccountsPayableService.php`
**Affected contract:** AP document line semantics (currently "lines are positive debits").
**Owner decision required:** may an AP document line carry a negative net, posting as a credit to its
own account?

---

## Section-by-section status

**GRNI posting** — mechanism proven (`Dr 2120` at the anchored receipt valuation) but **not wired**,
because the invoice path cannot post both variance directions.

**PPV posting** — proven for the debit direction against role `purchase_price_variance` → `5180`, no
hardcoded id. Credit direction blocked.

**VAT posting** — unchanged and working (V-1): `VAT14` → `1530`, resolved from the tax code; no `14`
appears in any posting logic. Zero-VAT invoices remain unaffected.

**AP posting / Supplier Ledger** — `AccountsPayableService` remains the **sole** authority and sole
writer of `SupplierLedgerEntry`. This task added no writer and no posting path.

**C-1 locking** — untouched. `PostSupplierInvoiceService` was not modified; the shared canonical
inbound lock and its transaction boundary are exactly as certified.

**Idempotency** — unchanged. No posting was added, so nothing new can double-post. The V-5 quantity
ceiling (`received − already invoiced`, keyed on the anchor) still prevents the same physical receipt
being financially cleared twice.

**Multi-receipt** — the deterministic V-5 model is intact and was not altered. Its sign convention is
preserved: a receipt costing 500 invoiced at 510 is **unfavourable**. Note this is precisely why the
blocker matters — §10's multi-receipt case nets to **−800 favourable**, the direction that cannot post.

**Quantity ceiling** — unchanged, keyed on the anchor; no other receipt may satisfy a shortage.

**Tenant isolation** — unchanged. The company guard still runs first and reports not-found, so no
cross-company supplier, product, quantity or valuation leaks through a validation error.

**FIFO integrity** — unchanged. No FIFO layer was read for valuation, rewritten, or consumed; GRNI
valuation comes from the stamped `landed_unit_cost`, exactly as V-5 proved.

**API / UI** — **nothing added.** Deliberate: exposing an anchor whose posting path cannot complete
would advertise a capability that does not exist.

**V-6** — **not implemented.** No amendment proposal, approval, rejection, revision or replacement
workflow was created. It remains a separate task.

---

## Files changed

**None.** No production file, migration, seeder, test or configuration was created or modified by
this task.

## Unrelated changes avoided (§22)

`git status` was inspected before any work. Two things were deliberately left alone:

1. **Another session has a staged change** — `frontend/src/features/orders/components/order-reservation-cell.tsx`
   (123 deletions) sits in the index. I did not run `git add`, `git commit`, `git reset` or any
   worktree clean, so that staged work is untouched.
2. Pre-existing dirty files across Purchasing and Finance (certified inbound work, my earlier D-1 /
   V-1 / V-2 / V-5 changes) were not restaged, reverted or deployed.

Nothing was deployed, so HOST/RUNNER/APP parity is unchanged from the V-5 task's verified state.

## Tests run · Static checks

**No tests were run and no static checks were needed** — there is no change to verify. The existing
suites remain as last executed today: the V-5 anchor suite **15/15 (36 assertions)**, Finance
**147/147 (1587 assertions)**, plus the certified Procurement suites. **No test was modified, weakened
or deleted**, and no assertion was removed.

## STOP conditions

| # | Condition | Status |
|---|---|---|
| **3** | **Existing Finance authority cannot support the required posting without redesign** | **TRIGGERED** |
| **14** | **The required accounting result cannot be represented deterministically** | **TRIGGERED** (favourable variance only) |
| 1, 2 | V-5 anchor reusable; deterministic identity established | No — both fine |
| 4 | GRNI account/authority differs | No — `2120` via role, as approved |
| 5 | AP requires a new authority | No — `AccountsPayableService` sufficient for the debit direction |
| 6 | PPV requires a new account | No — `5180` via role |
| 7 | VAT requires hardcoding | No — resolved from `VAT14` |
| 8 | C-1 cannot cover the posting | No — untouched and adequate |
| 9 | Idempotency cannot be preserved | No |
| 10 | Historical data needs fuzzy inference | No — re-checked: still 0 posted invoice lines |
| 11 | FIFO must change | No |
| 12 | Concurrent task owns a required file | No — but see §22 for the staged file avoided |
| 13 | Unrelated approved contract must change | No |

## Known remaining work

1. **Owner decision** on signed AP document lines (above) — the single blocker.
2. Then the integration itself: in `PostSupplierInvoiceService`, inside the existing transaction and
   behind the existing C-1 lock, call `InvoiceReceiptAnchorService::basisFor()`, build a GRNI line at
   the receipt valuation plus a PPV line for the variance, attach the VAT tax code, and post through
   `AccountsPayableService`. All inputs are already proven.
3. API/UI read-only receipt context (deferred with the posting).
4. **V-6** — Invoice Amendment workflow, still a separate task.

## Final implementation status

**BLOCKED.**

Not certified, and not claimed to be. Seven of the eight links in the chain are proven working; the
eighth — a credit to Purchase Price Variance on a favourable invoice — cannot be expressed by the
existing AP posting authority, and the fix changes that authority's line semantics for every supplier
document in the platform. That is the Finance owner's call, so it is reported rather than made.

## Mobile notification status

Sent via the session's existing notification mechanism after this report was written — see the
covering message for confirmation. No new notification infrastructure was created and no unrelated
notification architecture was modified.
