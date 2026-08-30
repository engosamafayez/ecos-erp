# TASK-PROCUREMENT-SUPPLIERS-COMPLETION-CONTINUATION-001 — Engineering Report

**Date:** 2026-08-17 · **Branch:** `develop` · **Runtime:** MySQL 8.4.10 / PHP 8.4.24 / PHPUnit 11.5.55

## Executive summary

**FINAL IMPLEMENTATION STATUS: PARTIAL**

| Part | Status |
|---|---|
| **A — AP negative-net directional capability** | **COMPLETE** — all three variance directions proven at runtime |
| **B — V-5 GRNI/PPV/AP posting integration** | **IMPLEMENTED**, certified regression preserved; **not yet end-to-end runtime-proven** |
| **C — V-6 Invoice Amendment workflow** | **NOT STARTED** |
| **D — Goods Inward Configuration/UI** | **IMPLEMENTATION EXISTS** — inspected, no gap, no changes made |

Two files changed. No certified contract was reopened. Not certified, and not claimed to be.

## Previous blocker

The prior task stopped on **STOP #3 / #14**: a favourable Purchase Price Variance requires a
**credit** to PPV, and `AccountsPayableService` could emit only an AP-control credit plus **positive**
debits — `if ($net > 0.0)` silently dropped a negative line, so the journal unbalanced and the engine
refused it (`Unbalanced: debits 40000 ≠ credits 36000`). The owner has now authorised signed AP lines,
which is what unblocked this task.

## Part A — AP authority change

**File:** `backend/Modules/Finance/Payables/Domain/Services/AccountsPayableService.php`,
method `buildDocumentPostingRequest()`.

**Old:** `if ($net > 0.0)` → line emitted as a debit; a negative or zero net produced **no line at all**.
**New:** `if ($net !== 0.0)` → `abs($net)` posted to the **same** account with the direction inverted
when `$net < 0`. Document-level direction is untouched: `payableSign()` still decides whether a
document increases or decreases the payable, and **positive-net behaviour is bit-for-bit unchanged**.

**Regression safety (§7).** All callers were inspected first: `createDocument`/`postDocument` are
called from exactly **two** controllers (`SupplierBillController`, and the AR equivalent through its
own service). **No caller produces a negative net today**, and credit notes express their reversal
through `payableSign()` — *not* through negative lines — so no existing document type had an approved
incompatible meaning. **STOP #3 not triggered.**

**Proven at runtime** through the real service, in a rolled-back transaction:

| Case | Journal | Ledger |
|---|---|---|
| **Favourable** receipt 40,000 / invoice 36,000 | `Dr 2120 GRNI 40,000` · `Cr 2110 AP 36,000` · **`Cr 5180 PPV 4,000`** | 36,000 |
| **Unfavourable** receipt 40,000 / invoice 44,000 | `Dr 2120 GRNI 40,000` · `Dr 5180 PPV 4,000` · `Cr 2110 AP 44,000` | 44,000 |
| **Equal** 40,000 / 40,000 | `Dr 2120 GRNI 40,000` · `Cr 2110 AP 40,000` — **no PPV line** | 40,000 |

**Finance regression after the change: OK (147 tests, 1587 assertions)** — §30.4 satisfied.

## Part B — V-5 posting integration

**File:** `backend/Modules/Purchasing/SupplierInvoices/Application/Services/PostSupplierInvoiceService.php`.

Added `postSupplierPayable()`, invoked as **step 7b inside the existing transaction**, immediately
before the invoice is marked posted.

**Posting path (§20C):**

```
Supplier Invoice → InvoiceReceiptAnchorService::basisFor()
   → anchored GoodsReceiptLine → stamped landed_unit_cost (receipt valuation)
   → GRNI line (role `grni` → 2120) at the receipt valuation
   → PPV line (role `purchase_price_variance` → 5180) for the variance, signed
   → VAT line (configured tax code → input 1530) when the invoice carries tax
   → AccountsPayableService::createDocument() + postDocument()
   → SupplierBill + journal + SupplierLedgerEntry
```

**Every account resolves through `AccountRoleResolver`** — no id is hardcoded, and no VAT rate is
referenced in posting logic.

**Financial authority (§20D):** `AccountsPayableService` remains the **sole** payable authority and
the only writer of `SupplierLedgerEntry`. This service builds a document and hands it over; it writes
no journal or ledger row itself. No second PPV path, no credit-note substitution.

**C-1 (§20E):** unchanged. The lock, `referenceForInvoice()`, the locked `canPost()` re-read and the
transaction boundary are exactly as certified; the payable posts **inside** that same transaction, so
inventory and payable cannot be committed independently.

**Idempotency (§20F):** the AP document is keyed `SI-{invoice id}`; if a bill with that number already
exists for the company the payable is skipped. No new idempotency mechanism, and no reversal of valid
records.

**FIFO (§20G):** no layer is read for valuation, rewritten or consumed. Valuation comes solely from
the anchored line's stamped `landed_unit_cost`.

**Tenant isolation (§20H):** unchanged — the anchor service's company-first guard still reports
not-found, leaking no foreign supplier, product, quantity or valuation.

### Deliberate skip conditions — and why they are not gaps

The payable is skipped, with the reason written to the invoice's own posting log, when:

1. **Any line lacks an anchor.** GRNI can only be cleared at a valuation, and without an anchor there
   is none that is not a guess. **The certified `InboundOwnershipContractTest` posts invoices with
   unanchored lines**, so refusing them would have changed a certified contract (**STOP #12**).
   Making the anchor mandatory here is a contract decision, not something to slip in.
2. **Mode 3.** The invoice itself moved the stock, so no GRNI was accrued and the debit belongs to the
   inventory account — a different entry, deliberately not invented.
3. **Already posted** — the idempotency guard.

**Certified inbound regression after the integration: OK (15 tests, 49 assertions).**

### What is not yet proven

The full path **anchored invoice → `PostSupplierInvoiceService` → GRNI/PPV/AP/Supplier Ledger** has
**not** been executed end to end. Part A's three cases were proven directly against
`AccountsPayableService`, and the integration is wired, DI-resolvable and regression-safe — but no
test yet drives an anchored invoice through the service and asserts the resulting journal. That is the
main outstanding verification item.

## Part C — V-6 Invoice Amendment

**NOT STARTED.** No receiving-review, evidence, amendment proposal, approval or rejection code was
written, and no schema was added. It was sequenced last per §30 and not reached. Nothing was
half-built: there is no partial V-6 surface to unwind.

## Part D — Goods Inward Configuration/UI

**Inspected only; no changes (§27).** Backend controller present in the app container, **2 routes**
registered, the feature is present in the **served** bundle (verified over HTTP, not on disk), and the
company's mode is the canonical default `goods_receipt`. **No implementation gap found**, so no work
was manufactured. Its browser smoke was completed and certified in an earlier task.

## Tests run

| Suite | Result |
|---|---|
| `tests/Feature/Finance` (after Part A) | **OK — 147 tests, 1587 assertions** |
| `tests/Feature/Purchasing/InboundOwnershipContractTest` (after Part B) | **OK — 15 tests, 49 assertions** |
| Direct runtime proof of all three PPV directions | **PASS** (rolled back; 0 bills persisted) |

No test was modified, weakened or deleted; no assertion was removed; no business semantics were
changed to make anything green. **No certification is claimed.**

## Static checks

| Check | Result |
|---|---|
| `php -l` (both changed files) | **PASS** |
| Pint | **PASS** (one auto-fix on `PostSupplierInvoiceService`, synced to host and both containers) |
| PHPStan **L0** — `Modules/Purchasing/SupplierInvoices` + `Modules/Finance/Payables` | **`[OK] No errors`** |

PHPStan core L6 not run for these files; the module-wide L6 baseline is pre-existing and separated.
Frontend unchanged → no TypeScript/ESLint/Vite run.

## Files changed

1. `backend/Modules/Finance/Payables/Domain/Services/AccountsPayableService.php` — Part A
2. `backend/Modules/Purchasing/SupplierInvoices/Application/Services/PostSupplierInvoiceService.php` — Part B

**Parity HOST == RUNNER == APP:** `6057dd564b882838` and `6bdcdfbd10e0c04d` — both **MATCH**.
No migration was required or applied.

## Worktree safety

`git status` / `git diff --cached` were inspected first. The concurrent session's **staged** deletion
of `frontend/src/features/orders/components/order-reservation-cell.tsx` (123 lines) is **still staged
and untouched** — I ran no `add`, `commit`, `reset`, `checkout` or `clean`, and deployed only my two
files. No unrelated dirty file was staged, reverted or deployed.

## STOP conditions

None triggered. #3 was cleared by inspection (no document type relies on negative nets); #12 was
avoided by skipping rather than refusing unanchored invoices, preserving the certified contract.

## Remaining work

1. **End-to-end proof** of Part B: an anchored invoice through `PostSupplierInvoiceService` asserting
   GRNI cleared, PPV signed correctly, VAT, AP and exactly one `SupplierLedgerEntry` — plus the
   multi-receipt (§11) and favourable multi-receipt (§12) cases through the service.
2. **Contract decision:** should an unanchored line block invoice posting outright? Today it skips the
   payable to preserve the certified inbound suite.
3. **Mode 3 payable**: debit target is the inventory account, not GRNI — needs its own ruling.
4. **Part C — V-6** in full.
5. API/UI read-only receipt context.

## Final implementation status

**PARTIAL**

Part A is complete and proven; Part B is implemented and regression-safe but not end-to-end proven;
Part C was not started; Part D needed nothing. Not certified — final consolidated verification remains
deferred as instructed.

## Mobile notification status

Sent through the session's existing notification mechanism after this report was written; the tool
confirmed the push was requested. No notification infrastructure was created or modified.
