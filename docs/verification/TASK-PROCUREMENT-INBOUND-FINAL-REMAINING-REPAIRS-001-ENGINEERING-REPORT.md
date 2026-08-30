# TASK-PROCUREMENT-INBOUND-FINAL-REMAINING-REPAIRS-001 — Engineering Report

**Date:** 2026-08-17 · **Branch:** `develop` · **Runtime:** MySQL **8.4.10** (InnoDB) / PHP 8.4.24 / PHPUnit 11.5.55

**Status: IMPLEMENTATION COMPLETE — FINAL CERTIFICATION DEFERRED**

---

## 1. Executive summary

Three open items closed, one investigated and deliberately left alone:

| Item | Outcome |
|---|---|
| **C-1** cross-document inbound concurrency | **REPAIRED** — the invoice path now locks the same canonical inbound row the receipt path locks |
| **D-INB-07** receipt-layer idempotency | **REPAIRED** — unique index on the exact canonical identity; zero duplicates found, migration applied to `ecos_dev` only |
| **PART 4** stale on-hand snapshot | **INVESTIGATED, UNCHANGED** — proven incapable of affecting any posting decision; fixing it properly would be a costing redesign the contract forbids |

**34 focused tests, 152 assertions, all green**, across the new cross-document suite, the certified
inbound contract suite, and the D-INB-03 suite. Nothing certified was reopened; no broad regression
was run.

---

## 2. C-1 root cause

`PostGoodsReceiptAction` locks the `goods_receipts` row before mutating (D-INB-03 repair).
`PostSupplierInvoiceService` did **not** lock that row — its first in-transaction statement was
`$invoice->update(AutoProcessing)`, which takes an exclusive lock on the **invoice** row only.

A Mode 3 invoice carrying `auto_receipt_id` posts inventory under **the receipt's** ledger
reference (`InboundPostingGuard::referenceForInvoice`). So the two documents mutated one physical
inbound while synchronising on **two different rows** — different mutexes are no mutex at all:

```
receipt path   → LOCK goods_receipts(R)   → post under ref goods_receipt/R
invoice path   → LOCK supplier_invoices(I) → post under ref goods_receipt/R   ← same target, different lock
```

The shared ledger guard (`alreadyPosted`) sat inside the invoice transaction but is a plain read:
under true concurrency both transactions could read "not posted" and both proceed.

Additionally, `canPost()` was evaluated **outside** the transaction (a PART 3 violation), so two
concurrent posts of the *same* invoice could both pass it.

---

## 3. C-1 repair

Inside the existing transaction, before anything mutates:

```php
DB::transaction(function () use ($invoice): void {
    // resolve the inbound identity FIRST, then lock whatever it resolves to
    [$refType, $refId] = $this->inboundGuard->referenceForInvoice($invoice->auto_receipt_id, $invoice->id);
    $this->lockCanonicalInbound($refType, $refId);

    // PART 3 — re-read this invoice's own posting state under that lock
    $locked = SupplierInvoice::query()->whereKey($invoice->getKey())->lockForUpdate()->first();
    if ($locked === null || ! $locked->status->canPost()) {
        throw new RuntimeException("Invoice {$invoice->invoice_number} cannot be posted (status: …).");
    }
    …unchanged…
});
```

```php
private function lockCanonicalInbound(string $refType, string $refId): void
{
    if ($refType !== InboundPostingGuard::REF_GOODS_RECEIPT) {
        return;   // the invoice IS its own inbound; its own row is locked immediately below
    }
    DB::table('goods_receipts')->where('id', $refId)->lockForUpdate()->first();
}
```

**The synchronisation point is shared, not merely doubled.** The lock target is whatever
`referenceForInvoice()` — the certified identity function — resolves to: the receipt row for a
linked invoice, the invoice row for an unlinked Mode 3 invoice. One physical inbound, one row,
either way. No new mechanism, no lock table, no application lock, no heuristic matching of
quantity, price, timestamp or supplier.

**Why the mutex is a plain table lock, not an Eloquent read.** It is a mutex; nothing about the
locked row is used to make a decision, so it cannot become a tenant bypass — authorisation stays on
the invoice lookup where the certified tenant repair put it. Applying the receipt's tenant scope
here would be actively harmful: a foreign row would return `null`, acquire **no lock**, and silently
reopen the race the code exists to close.

**Lock ordering.** Both paths take `goods_receipts` first, so the two never form a cycle.

---

## 4. Goods Receipt concurrency

Unchanged from the D-INB-03 repair and re-verified here: `GoodsReceiptConcurrencyTest` →
**OK (8 tests, 41 assertions)**. The receipt still locks its own row as the first in-transaction
statement and re-asserts both duplicate guards under it.

## 5. Supplier Invoice concurrency

Previously untested and genuinely exposed. Now: `canPost()` is re-asserted under
`lockForUpdate()` inside the transaction, so two concurrent posts of one invoice cannot both
proceed — the loser observes the winner's committed status and stands down through the existing
workflow guard (a `RuntimeException` from the canonical status check), **not** by silently
succeeding with zero effect. Proven by `test_d`.

## 6. Cross-document concurrency

`test_e` asserts the contract directly rather than inferring it from ordering: it records every
`FOR UPDATE` issued against `goods_receipts` and asserts the **invoice path locks the receipt's
row**. Before this repair that count was zero.

One subtlety worth stating, because it shaped the test: once a linked invoice has posted, the
receipt path is refused by the certified pre-transaction guard (`PostGoodsReceiptAction:68`) and
therefore short-circuits *before* taking its own lock. So the shared row is demonstrated on the
invoice path, and the receipt path's lock on the same row is demonstrated on a fresh, unsuperseded
inbound in the same test.

`test_e2` covers the genuine interleaving: a linked invoice completes **inside** the receipt path's
pre-transaction window (injected on the `goods_inward_mode` query, after the receipt's outer guards
have already passed). Only the in-transaction locked re-check can stop it — and it does.

---

## 7. Transaction boundaries

| Path | Lock | Guards re-asserted under lock | Mutation |
|---|---|---|---|
| Goods Receipt | `goods_receipts` row, first statement in transaction | status + ledger reference | same transaction |
| Supplier Invoice | canonical inbound row, first statement in transaction | own `canPost()` + authority + ledger reference | same transaction |

No path checks, leaves the transaction, and posts later. The invoice's authority and ledger guards
were already inside its transaction (certified); this repair adds the lock in front of them and the
locked status re-read.

---

## 8. Goods Inward Authority behaviour

`GoodsInwardAuthority` remains the sole resolver; mode resolution is not duplicated anywhere in the
change. `companies.goods_inward_mode` is untouched, default `goods_receipt`.

`test_i` proves both directions on one company: under Mode 1 an invoice posts **no** stock, no
ledger, no layer (status still advances to Posted — it remains a financial document); under Mode 3
a receipt posts **no** stock, no ledger, no layer while its receiving bookkeeping advances. Neither
document can become a second inbound authority.

## 9. Tenant isolation

Unchanged and re-proven. `test_f`: a company-scoped actor with **no** `is_system` role (which would
legitimately grant cross-company access) posting a foreign receipt gets `GoodsReceiptNotFoundException`
— the certified 404 contract — with on-hand, ledger and layer all **0**. `test_g`: a foreign invoice
is invisible to the same actor (`find()` → null), zero mutations, status unchanged.

Authorisation remains explicit on the document lookup, never a side effect of an FK or constraint.
The C-1 mutex deliberately does not participate in authorisation (§3).

---

## 10. D-INB-07 analysis

`inventory_receipt_layers` had **no** uniqueness guarantee — only `PRIMARY(id)`.

**The canonical identity is `goods_receipt_line_id`, and it is exact.** Seven code paths create
layers; six write `goods_receipt_line_id => null` **explicitly** (count approval, manual stock,
transfer, disassembly, manufacturing execution, customer-return receipt). The only writer of a
non-null value is `CreateReceiptLayersAction` on the Goods Receipt path, where one layer per receipt
line is the intended invariant. No fuzzy key was invented.

**Duplicate check before writing anything (PART 18):** `ecos_dev` — 2 layers, **0** with a non-null
`goods_receipt_line_id`; `ecos_dev_test` — **0** rows. Zero duplicates in either. Nothing was
deleted or merged.

**Nullable-unique semantics verified empirically on MySQL 8.4.10**, not assumed: three NULL rows
coexist under a unique index while a genuine duplicate is rejected with **errno 1062**. So the index
constrains receipt-sourced layers precisely and is completely inert for the six null-writing paths.

## 11. D-INB-07 repair

Migration `2026_08_17_000001_add_unique_inbound_identity_to_receipt_layers` — additive and
idempotent: adds one index, alters no column, drops nothing, rewrites no data. It re-runs the
duplicate check at run time and **throws rather than destroying anything** if duplicates ever exist.
`down()` drops only that index.

**Known, deliberate gap:** invoice-sourced layers carry `goods_receipt_line_id = null` and are
therefore *not* covered. No canonical per-line identity column exists for them — the layer table has
no `supplier_invoice_line_id` — and inventing one would be new schema beyond this repair's minimum,
while keying on anything else would be the fuzzy matching the contract forbids. Those layers are
protected by the C-1 shared lock instead. Stated rather than papered over.

## 12. On-hand snapshot analysis (PART 4) — investigated, unchanged

| Question | Answer |
|---|---|
| Which value is read before the transaction? | `on_hand_qty` per product, in the **Goods Receipt** path only. The invoice path already reads it *inside* its transaction. |
| Does it participate in the posting decision? | **No.** It flows only to `EnterpriseCostEngine::weightedAverageCost($oldQty, …)` → `product.average_cost`. |
| Can it cause a wrong over-/under-receipt decision? | **No.** Over-receipt is bounded by the PO line quantity read under `lockForUpdate`; the quantity mutation itself re-reads on-hand under the inventory row lock inside `ReceiveStockAction`. |
| Can the canonical locked source be used instead? | It exists — `ReceiveStockAction` captures a locked `$onHandBefore` — but does **not** expose it. Threading it out would also change meaning: the snapshot is *pre-receipt, per product*, whereas the locked value is *per call*, so multi-line receipts for one product would silently change their weighted-average basis. |

**Conclusion: leave unchanged.** It cannot produce an incorrect posting, quantity, ledger or layer
outcome. Its only exposure is a slightly stale weighted-average basis when two **different** receipts
for the same product post concurrently — a costing-accuracy nuance whose correct fix is a costing
redesign, which PART 4 explicitly forbids doing automatically. Recorded in §19.

---

## 13. Database changes

One migration, applied to **`ecos_dev` only**, via
`migrate --path=…2026_08_17_000001_add_unique_inbound_identity_to_receipt_layers.php`:

```
2026_08_17_000001_add_unique_inbound_identity_to_receipt_layers  152.63ms DONE
```

Verified afterwards: `irl_goods_receipt_line_unique`, `NON_UNIQUE = 0`, on `goods_receipt_line_id`.
**The unrelated pending migration `2026_08_14_100000_create_recipe_cost_snapshots` was NOT applied
and remains pending** — confirmed by `migrate:status` before and after. No `migrate:fresh`, no reset,
no other agent's migration touched. `ecos_dev_test` receives it through `RefreshDatabase` during the
test runs.

## 14. Frontend impact

**None.** No frontend file was changed. The repair alters only concurrency behaviour and surfaces
the same existing exceptions through the same existing contracts, so no UI change is required. The
Goods Inward configuration UI was not touched, no second configuration screen was created, and
`company_id` was not added to any configuration payload. Its browser smoke remains deferred, as
recorded by the prior task.

---

## 15 & 16. Tests and runtime verification

All runs under the T-6 advisory-lock gate, queued via `GATE_WAIT` whenever another session held it —
never competing for the environment.

| Suite | Result |
|---|---|
| `InboundCrossDocumentConcurrencyTest` (new, PART 13 A–I + D-INB-07) | **OK (11 tests, 62 assertions)** |
| `InboundOwnershipContractTest` (**certified**, regression) | **OK (15 tests, 49 assertions)** |
| `GoodsReceiptConcurrencyTest` (D-INB-03, regression) | **OK (8 tests, 41 assertions)** |
| **Total** | **34 tests, 152 assertions, 0 failures** |

| PART 13 | Test | Proves |
|---|---|---|
| A | `test_a` | Mode 1: receipt posts, linked invoice adds no second inbound |
| B | `test_b` | Mode 3: invoice posts; linked receipt refused per certified contract |
| C | `test_c` | Two concurrent receipt posts → one inbound, second rejected |
| D | `test_d` | Two concurrent invoice posts → one inbound, second rejected |
| **E** | `test_e`, `test_e2` | **C-1: the invoice path locks the receipt row; mid-window invoice cannot be double-posted** |
| F | `test_f` | Cross-company receipt → denied, zero mutations |
| G | `test_g` | Cross-company invoice → invisible, zero mutations |
| H | `test_h` | Repeated authoritative post → one inbound effect |
| I | `test_i` | Only the configured authority posts, both directions |
| D-INB-07 | `test_receipt_line_can_own_at_most_one_fifo_layer` | A second layer for one receipt line is rejected by the database |

Every assertion is on the **canonical entities** — ledger rows, FIFO layers, on-hand, `received_qty` —
never on quantity alone, per PART 7/14.

**A first run failed 3 of 11, and the failures were mine, not the code's.** Tests B, E and E2
asserted that a Mode 3 receipt completes as a receiving record after its linked invoice posts. The
certified contract (`InboundOwnershipContractTest::test_c2`) states the opposite: because a linked
invoice posts under the receipt's own reference, the pre-existing guard **refuses the receipt
outright**, so `received_qty` stays 0. I corrected my tests to the certified behaviour rather than
changing production code to match my expectation. That is also what exposed the `test_e` design flaw
described in §6.

**Static checks (changed files only):** `php -l` PASS on all three · Pint **PASS, 3 files** ·
PHPStan L0 on `Modules/Purchasing/SupplierInvoices` + `Modules/Inventory/ReceiptLayers` →
**`[OK] No errors`**.

**Deployment smoke:** both services build through the DI container on `ecos-dev-app`;
`GET /api/goods-receipts` → 401 and `GET /api/supplier-invoices` → 401 through `ecos-dev-nginx:8081`
(auth-gated, no 500).

---

## 17. Files changed

| File | Change |
|---|---|
| `Modules/Purchasing/SupplierInvoices/…/PostSupplierInvoiceService.php` | **modified** — C-1 shared lock + locked status re-read |
| `Modules/Inventory/ReceiptLayers/…/Migrations/2026_08_17_000001_add_unique_inbound_identity_to_receipt_layers.php` | **new** — D-INB-07 unique index |
| `tests/Feature/Purchasing/InboundCrossDocumentConcurrencyTest.php` | **new** — 11 focused tests |

**PART 17 finding, stated plainly:** the entire pre-existing dirty diff of
`PostSupplierInvoiceService.php` (141 insertions / 166 deletions) **is the certified P-7 inbound
convergence changeset itself** — the removal of its private inventory engine in favour of
`ReceiveStockAction` + `CreateReceiptLayersAction`. It is not unrelated third-party work, so the C-1
fix could be isolated safely: I added a block and a helper without reinterpreting or reverting any
of it. Nothing was reset, no `checkout HEAD`, no overwrite. The certified inbound changeset remains
uncommitted, exactly as found.

Other dirty files under the same modules — `CreateReceiptLayersAction.php`,
`InventoryLayerConsumptionService.php`, `SupplierInvoice.php`, `SupplierInvoiceController.php` —
are pre-existing certified/other work and were **not** touched.

**Parity (PART 20), `MSYS_NO_PATHCONV=1` throughout:**

| File | HOST | RUNNER | APP | |
|---|---|---|---|---|
| `PostSupplierInvoiceService.php` | `530861f5352da9f0` | `530861f5352da9f0` | `530861f5352da9f0` | **MATCH** |
| `2026_08_17_000001_…_receipt_layers.php` | `c5c63070476de73d` | `c5c63070476de73d` | `c5c63070476de73d` | **MATCH** |
| `PostGoodsReceiptAction.php` (prior repair, re-verified) | `cd6169b803ba7cea` | `cd6169b803ba7cea` | `cd6169b803ba7cea` | **MATCH** |
| `InboundCrossDocumentConcurrencyTest.php` (test) | `4eb1580c408ed971` | `4eb1580c408ed971` | *n/a by design* | **MATCH** |

---

## 18. Unresolved issues

**None blocking.** One residual concurrency window is narrowed but not provably closed by a lock:

- **Unlinked documents in the same mode.** When `auto_receipt_id` is NULL — which the audit records
  as every case production actually produces — a receipt and an invoice resolve to *different*
  references and therefore lock different rows. They cannot double-post because **G-1 authority**
  means only one document type posts inventory at all for that company; the protection is the
  authority model, not a lock. This is the certified design, not a gap introduced here, and `test_i`
  proves it holds. It is stated so the boundary of the lock-based guarantee is explicit.

## 19. Deferred issues

| # | Item | Why deferred |
|---|---|---|
| D-1 | **Pre-transaction on-hand snapshot** in the Goods Receipt path can be stale across *different* concurrent receipts, skewing `average_cost` only | §12 — cannot affect any posting decision; the correct fix is a costing redesign PART 4 forbids |
| D-2 | **Invoice-sourced FIFO layers have no DB-level uniqueness** | §11 — no canonical identity column exists; adding one exceeds this repair's minimum |
| D-3 | **A linked Mode 3 receipt never advances its receiving bookkeeping** (`received_qty` stays 0) once its invoice posts first | Certified consequence of the shared-reference guard (`test_c2`). A business-contract question, not a defect; reopening it is forbidden by PART 12/21 |
| D-4 | Goods Inward configuration **browser smoke** | Deferred by the prior task; unchanged here (PART 9) |
| D-5 | `nextRequestNumber()` concurrency (U-1) | PART 11 — this task's inbound changes do not depend on it; untouched |

## 20. Scope exclusions

Not touched, per PARTS 10–12 and 20: Supplier Return valuation; Purchase Material number generation
and its certified MySQL CAST repair; the ~62 PostgreSQL-only expressions outside this path; the
Goods Inward UI; DemandAnalysis and Procurement analytics; Inventory redesign; Orders, Preparation,
Manufacturing, Loading, Vehicle, Driver, Delivery, Settlement, Accounting and Finance. No
PostgreSQL-only syntax was introduced — the only SQL added is `ALTER TABLE … ADD UNIQUE INDEX`,
executed successfully on MySQL 8.4.10.

No broad regression, no full certification matrix, no browser E2E, no `migrate:fresh`, no database
reset, no unrelated fixture or test modified.

---

## 21. Final implementation status

**IMPLEMENTATION COMPLETE — FINAL CERTIFICATION DEFERRED**

- **C-1 repaired**: both inbound paths now lock the same canonical row for one physical inbound, resolved through the existing `InboundPostingGuard` identity — no new mechanism, no heuristics
- **Invoice posting made atomic**: `canPost()` re-asserted under the lock inside the transaction
- **D-INB-07 repaired**: exact canonical unique index, zero duplicates, additive and idempotent migration applied to `ecos_dev` alone; the unrelated pending migration left pending
- **On-hand snapshot investigated and deliberately unchanged**, with the reasoning recorded
- **34 tests / 152 assertions green**, including the certified contract suite and the D-INB-03 suite — no regression
- Static clean; HOST == RUNNER == APP on every changed production file
- Tenant isolation, Goods Inward Authority and every certified guarantee in PART 21 verified intact

The Procurement system is **not** certified by this task. Final system review and certification
remain deferred until all ECOS ERP domain modifications are complete. No further Procurement work
was started.
