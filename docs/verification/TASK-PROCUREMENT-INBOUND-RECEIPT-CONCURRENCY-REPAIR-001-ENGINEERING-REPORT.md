# TASK-PROCUREMENT-INBOUND-RECEIPT-CONCURRENCY-REPAIR-001 — Engineering Report

**Date:** 2026-08-17 · **Branch:** `develop` · **Runtime:** MySQL **8.4.10** (InnoDB) / PHP 8.4.24 / PHPUnit 11.5.55
**Scope:** D-INB-03 only — make Goods Receipt posting safe under concurrent execution.

**Status: IMPLEMENTATION COMPLETE — FINAL CERTIFICATION DEFERRED**

---

## 1. Original D-INB-03 evidence

Recorded twice, never repaired:

| Source | Entry |
|---|---|
| `TASK-PROCUREMENT-INBOUND-OWNERSHIP-CLOSURE-001` §20 | **D-INB-03** — "`PostGoodsReceiptAction` guard is check-then-act, no receipt lock (§12)" · medium · PRE-EXISTING · reported |
| `TASK-PROCUREMENT-INBOUND-SECURITY-AND-IDEMPOTENCY-REPAIR-001` §21 | **D-INB-03** — "check-then-act with no receipt lock" · open — distinct defect, blast radius reduced |
| Same report, acceptance matrix | "Concurrent duplicate attempt — **NOT PROVEN**"; "Concurrency protection where applicable — **PARTIAL**" |

Neither certified task was reopened. `InboundPostingGuard`, `GoodsInwardAuthority` and the
one-authority-per-company contract are unchanged.

---

## 2. Existing architecture inspected

`PostGoodsReceiptAction::execute()` as it stood:

| Line | Step | Transaction? |
|---|---|---|
| 48 | `findById($id)` — loads the receipt | **outside** |
| 55 | **Guard 1** — `$receipt->status === Posted` | **outside**, no lock |
| 66 | **Guard 1b** — `inboundGuard->alreadyPosted(...)` (ledger query) | **outside**, no lock |
| 116 | `inwardAuthority->receiptMayPost($companyId)` | **outside** |
| 125 | pre-receipt on-hand snapshot | **outside** |
| **132** | `DB::transaction(` opens | — |
| 137 | Guard 4 — over-receipt, `lockForUpdate()` on the **PO line** | inside |
| 161 | `ReceiveStockAction` → quantity + `stock_ledger_entries` | inside |
| 185 | `increment('received_qty')` on the PO line | inside |
| **204** | receipt stamped **Posted** | inside |
| 216 | `CreateReceiptLayersAction` → FIFO layer | inside |

Locking conventions already in the platform (all reused, none replaced): `ReceiveStockAction`
locks the inventory row; the over-receipt guard locks the PO line; the **certified**
`ApproveSupplierReturnAction` performs a `lockForUpdate()` re-read of its own document inside the
transaction and re-asserts the guard evaluated outside it. That last one is the exact shape this
repair adopts.

---

## 3. Race reproduction — proven, not asserted

Reproduced against the real runner **before** any fix was deployed (runner verified pre-fix:
`grep -c "Guard 1c"` → `0`).

A `DB::listen` hook fires on the `goods_inward_mode` lookup — issued **after** Guard 1 and Guard 1b
have passed and **before** the transaction opens — and runs a competing, fully-completed post of the
same receipt. That is precisely the interval a second request occupies.

**Pre-fix run — `OK` was NOT reached:**

```
..FF...F                                                  8 / 8 (100%)
Tests: 8, Assertions: 32, Failures: 3.

1) test_c_concurrent_double_post_produces_exactly_one_inventory_effect
   The second poster was not rejected: the check-then-act window is still open.
2) test_d_the_locked_reread_happens_inside_the_transaction
   The receipt row was never locked with FOR UPDATE.
3) test_h_mode3_receipt_is_still_protected_against_a_concurrent_duplicate
   The duplicate post was not rejected in Mode 3.
```

The other five tests passed on the unrepaired code, so the suite discriminates — it is not simply
red everywhere.

**Why the existing guards did not catch it.** The over-receipt guard *does* lock the PO line, but a
duplicate post only breaches the ordered quantity when one receipt consumes more than half the
order. The 9-of-100 shape used here passes it twice (9 + 9 = 18 ≤ 100), which is exactly why the
race was silent in normal operation.

**InnoDB behaviour, two genuine MySQL 8.4 sessions.** Session 1 held `SELECT … FOR UPDATE` on a
committed row; session 2 then attempted each read style with `innodb_lock_wait_timeout=4`:

| Session 2 read | Outcome |
|---|---|
| plain `SELECT` — the old guards' pattern | returned in **0.013 s**, entirely unserialised |
| `SELECT … FOR UPDATE` — the repair's pattern | **blocked 4.011 s → ERROR 1205 (HY000)** lock wait timeout |

That is the cross-process half: an unlocked read cannot serialise two posters; the locked read does.

---

## 4. Root cause

Classic check-then-act. The decision *"has this inbound already posted?"* was made from reads taken
outside the transaction and without a lock, while the mutation and the `Posted` stamp happened
inside it. Nothing bound the two, so two requests could interleave:

```
A: Guard 1 + 1b → not posted
B: Guard 1 + 1b → not posted
A: BEGIN … post stock, layer, received_qty, status=Posted … COMMIT
B: BEGIN … post stock, layer, received_qty, status=Posted … COMMIT   ← duplicate
```

Result: two `stock_ledger_entries`, two FIFO layers, double `received_qty` for one delivery.

---

## 5. Concurrency authority selected

**The `goods_receipts` row itself.** It is the canonical document being posted and the row whose
`status` column *is* the posting state, so it is the natural authority — no lock table was invented,
no global/application lock introduced, no filesystem lock, no PHP process state. It works across
processes because it is an InnoDB row lock (§3).

The Goods Inward authority contract is untouched: `companies.goods_inward_mode` and
`GoodsInwardAuthority` still decide *whether* a receipt posts inventory. This repair only decides
*how many times*.

---

## 6 & 7. Transaction boundary and locking mechanism

The repair is one block, added as the **first statement inside the existing transaction** — the
transaction boundary itself is unchanged:

```php
DB::transaction(function () use (...) {
    // ── Guard 1c (locked): close the check-then-act window — D-INB-03 ──
    $locked = GoodsReceipt::query()->whereKey($receipt->id)->lockForUpdate()->first();

    if ($locked === null) {
        throw new GoodsReceiptNotFoundException((string) $receipt->id);
    }
    if ($locked->status === GoodsReceiptStatus::Posted) {
        throw new GoodsReceiptAlreadyPostedException($receipt->receipt_number);
    }
    if ($this->inboundGuard->alreadyPosted(InboundPostingGuard::REF_GOODS_RECEIPT, $receipt->id)) {
        throw new GoodsReceiptAlreadyPostedException($receipt->receipt_number);
    }

    // … existing over-receipt guard, stock mutation, FIFO layers, status stamp, unchanged
});
```

Realised sequence — exactly the shape PART 6 specifies:

```
BEGIN → lock the receipt row → re-verify posting state → (already posted ⇒ stand down)
      → authorised stock mutation → FIFO layers → mark posted → COMMIT
```

`status` is cast to `GoodsReceiptStatus`, so the comparison is an enum identity check, not a string
compare. The re-read goes through `GoodsReceipt::query()`, which carries the certified `tenant`
global scope (§12). Total production delta: **this block plus one `use` import.** No stock mutation
logic was duplicated, no second FIFO engine created, no new idempotency mechanism added.

---

## 8, 9 & 10. Duplicate prevention, FIFO protection, idempotent second request

Post-fix run, same test file, byte-identical assertions:

```
[GATE] acquired ecos:testrunner:ecos_dev_test (connection 3908)
PHPUnit 11.5.55 · PHP 8.4.24
........                                                  8 / 8 (100%)
OK (8 tests, 41 assertions)      Time: 06:59.103
```

| | Pre-fix | Post-fix |
|---|---|---|
| Failures | **3** | **0** |
| Assertions reached | 32 | **41** |

Counts are asserted on the **canonical entities**, not merely on the final quantity — per PART 7 a
race producing two ledger rows with a coincidentally-correct total is still a failure:

- `stock_ledger_entries` where `movement_type = PurchaseReceipt` → **exactly 1**
- `inventory_receipt_layers` → **exactly 1**
- `on_hand_qty` → **9.0**, not 18.0
- `purchase_order_lines.received_qty` → **9.0**, not 18.0
- receipt status → `Posted` once

The second request is rejected with the **existing** `GoodsReceiptAlreadyPostedException` — the
established Goods Receipt contract. No new API response shape was invented.

**Rollback (PART 10)** — `test_e`: a post that fails *after* the lock is held (over-receipt, 9 + 9
against 10 ordered) leaves on-hand at 9.0, one ledger row, one layer, `received_qty` at 9.0, and the
receipt **not** marked Posted. A corrected retry then succeeds and reaches 10.0 with 2 ledger rows —
so a failed attempt neither leaks state nor bricks the document.

---

## 11 & 12. Tenant isolation and Goods Inward Authority

- `test_f` — a company-scoped actor holding **no** `is_system` role (`$grantsBaselineAuthorization = false`,
  since `is_system` would legitimately grant cross-company access) attempts to post another company's
  receipt. The tenant global scope makes the row invisible, `GoodsReceiptNotFoundException` is raised —
  the certified 404 contract — and on-hand, ledger and layer counts all remain **0**. The locked
  re-read is deliberately issued through the scoped model, so it cannot become a tenant bypass.
- `test_g` — Mode 3 (`goods_inward_mode = supplier_invoice`): the receipt still posts **no** inventory
  (0 ledger, 0 layers) while receiving bookkeeping advances (`received_qty` 9.0, status Posted).
- `test_h` — the same Mode 3 receipt is still protected against a concurrent duplicate: `received_qty`
  is 9.0, not 18.0. **This is the case the old code got wrong even with no inventory involved.**

`companies.goods_inward_mode`, `GoodsInwardAuthority` and the one-authority-per-company decision are
unmodified.

---

## 13. Targeted tests

New file: `backend/tests/Feature/Purchasing/GoodsReceiptConcurrencyTest.php`. **No existing test was
modified** — the certified `InboundOwnershipContractTest` is untouched; its fixture shapes were
copied, not edited.

| PART 16 requirement | Test | Result |
|---|---|---|
| 1 — Normal receipt post | `test_a` | PASS |
| 2 — Duplicate post after success | `test_b` | PASS |
| 3 — Concurrent double-post attempt | `test_c` | PASS (**failed pre-fix**) |
| 4 — Exactly one ledger mutation | `test_c` | PASS |
| 5 — Exactly one FIFO layer | `test_c` | PASS |
| 6 — Rollback on failure | `test_e` | PASS |
| 7 — Tenant isolation | `test_f` | PASS |
| 8 — Goods Inward Authority intact | `test_g`, `test_h` | PASS (`test_h` **failed pre-fix**) |
| — Lock is real and inside the transaction | `test_d` | PASS (**failed pre-fix**) |

Only this one file was executed. No Procurement, Inventory, Orders or platform regression was run.

**Two anti-false-green guards**, both of which earned their place:

1. `test_c`/`test_h` assert the injection **actually fired**, so they cannot pass vacuously if the
   hooked query ever moves. This fired on the first attempt: the helper returned `static fn () => $fired`,
   an arrow function that captures **by value** and therefore reported `false` forever. Fixed to a
   `use (&$fired)` closure — a defect in my test, caught by my test, in the safe direction.
2. `test_d` compares the lock's transaction level against a **baseline** captured at test start.
   `RefreshDatabase` already holds a transaction, so a naive `transactionLevel() > 0` would have been
   vacuously true and would have passed even with the lock outside the action's transaction.

**Stated limitation.** The PHPUnit race test proves the *check-then-act window is closed* — the
decision is re-made from a fresh locked read inside the transaction. It does not exercise InnoDB
cross-connection blocking, because `RefreshDatabase` confines the test to one connection. That half
is proven separately in §3 against two real MySQL sessions. Neither proof alone is sufficient;
together they cover both halves.

---

## 14. Static checks

Changed files only.

| Check | Scope | Result |
|---|---|---|
| `php -l` | deployed action, in **both** containers | **PASS** |
| `php -l` | new test | **PASS** |
| Pint | action + test | **PASS** (one `unary_operator_spaces` issue auto-fixed in the test) |
| PHPStan L0 | `Modules/Purchasing/GoodsReceipts` | **`[OK] No errors`** |

The Pint fix was applied **after** the green run, so it was verified to be a docblock
double-space → single-space: `diff -w` between the tested and final files reports **no
non-whitespace difference**. The green run therefore stands. No unrelated baseline errors were touched.

---

## 15. Deployment

Target: the `ecos-dev-*` stack (`C:\ecos-develop`), code baked into the image, so `docker cp` is required.

- `PostGoodsReceiptAction.php` → **`ecos-dev-testrunner`** and **`ecos-dev-app`** (the only production file changed)
- `GoodsReceiptConcurrencyTest.php` → **`ecos-dev-testrunner`** only. A copy briefly placed in
  `ecos-dev-app` to attempt a Pint run (Pint is absent from the production image) was **removed** —
  tests do not belong in the app container.
- `config:clear` on `ecos-dev-app`; opcache needed no action (`validate_timestamps=1`, `revalidate_freq=0`)

**No migration was created and none is required** — the repair uses the existing `goods_receipts`
row and its existing `status` column as the lock and the state. No schema object was added. No
`migrate`, `migrate:fresh`, drop or reset was run, and no other agent's pending migration was touched.

**Environment contention was respected.** The gate (`ecos:testrunner:ecos_dev_test`) was held by
another session (conn 2707, then an ungated phpunit process); every run queued via `GATE_WAIT=2400`
rather than competing, and each acquired and released the advisory lock cleanly. Nothing from Orders,
Inventory, Preparation, Loading, Vehicle, Driver, Delivery, Settlement, Supplier Return or Purchase
Materials was deployed.

**Deployment smoke:** `route:list --path=goods-receipts` resolves all 6 routes including
`POST api/goods-receipts/{goodsReceipt}/post`; the action still builds through the DI container;
`GET /api/goods-receipts` → **401** through `ecos-dev-nginx:8081` (auth-gated, no 500).

---

## 16. Host / runner / app parity

`MSYS_NO_PATHCONV=1` on every container path, so Git Bash could not rewrite `/var/www/...` into a
false result.

| File | HOST | RUNNER | APP | |
|---|---|---|---|---|
| `PostGoodsReceiptAction.php` (production) | `cd6169b803ba7cea` | `cd6169b803ba7cea` | `cd6169b803ba7cea` | **MATCH** |
| `GoodsReceiptConcurrencyTest.php` (test) | `72050cb1064655ae` | `72050cb1064655ae` | *removed by design* | **MATCH** |

Content verified literally as well as by hash: `Guard 1c` present exactly once in both containers.

**Change scope.** `git status` for this task shows one modified production file
(`PostGoodsReceiptAction.php`) and one new test. The three other dirty files under
`GoodsReceipts/` — `CreateGoodsReceiptAction.php`, `GoodsReceipt.php`, `GoodsReceiptFactory.php` —
are the pre-existing **uncommitted certified inbound work**, not this task's, and were not touched.
Note that the certified inbound changeset is itself uncommitted, so the file I extended already
carried it; this repair adds to it rather than reopening it.

---

## 17. Unrelated findings — recorded, NOT fixed

| # | Finding | Disposition |
|---|---|---|
| C-1 | **The cross-document concurrent window remains open.** A Mode 3 Supplier Invoice carrying `auto_receipt_id` posts under the *receipt's* ledger reference but locks the **invoice** row, not the receipt. Two truly concurrent posts — one per document — can still both pass the ledger guard. The receipt path now re-checks that guard under its own lock, which narrows the window but cannot close it alone: closing it would require the invoice path to lock the same receipt row. | **OUT OF SCOPE.** D-INB-03 is the receipt-level race. `PostSupplierInvoiceService.php` is also another agent's dirty file (PART 18: do not overwrite). Directly relevant, so recorded explicitly. |
| C-2 | `PostSupplierInvoiceService` checks `canPost()` **outside** its transaction; its first in-transaction statement is an `UPDATE`, which does take an exclusive row lock, so the exposure is narrower than the receipt's was — but the check itself is still not made under that lock. | **OUT OF SCOPE** — same file/ownership constraint as C-1. |
| C-3 | The pre-receipt on-hand snapshot (used for weighted-average cost) is read **outside** the transaction, before the lock. Harmless for D-INB-03 — the duplicate poster now stands down before using it — but it can be stale when two *different* receipts for the same product post concurrently. | **OUT OF SCOPE** — a different-document concern, not the D-INB-03 contract. |
| C-4 | D-INB-07 (no unique index on `inventory_receipt_layers`) is still open. A database-level unique constraint would make duplicate layers structurally impossible rather than only guarded in application code. | **OUT OF SCOPE** — pre-existing, requires a migration (PART 15 says stop before inventing schema). |

Nothing in PART 20's exclusion list was modified. Supplier Return (PART 13) and Purchase Material
numbering (PART 14) were not touched.

---

## 18. Final implementation status

**IMPLEMENTATION COMPLETE — FINAL CERTIFICATION DEFERRED**

- D-INB-03 **reproduced** on the real runner before any fix: 3 targeted failures, 5 passes
- InnoDB blocking proven with two real MySQL 8.4 sessions: unlocked read 0.013 s vs `FOR UPDATE` blocked → ERROR 1205
- Repaired with the existing ECOS convention — a `lockForUpdate()` re-read of the canonical document row inside the existing transaction; no new lock table, no application lock, no second idempotency mechanism
- Post-fix: **OK (8 tests, 41 assertions)** on byte-identical test code
- Exactly one ledger entry, one FIFO layer, one quantity effect, one posting — asserted on the canonical entities
- Rollback after the lock proven clean and retryable; tenant contract and Goods Inward authority proven intact
- No migration; no database reset; parity HOST == RUNNER == APP
- One genuinely relevant residual window (C-1, cross-document) recorded rather than silently fixed

Not certified. Final system-wide review and certification remain deferred until all remaining
ECOS ERP modifications are complete. No further Procurement work was started.
