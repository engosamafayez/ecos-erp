# TASK-SUPPLIER-RETURN-VALUATION-001 — Engineering & Certification Report

**Date:** 2026-08-15 · **Branch:** `develop` · **Runtime:** PHP 8.4.24 / MySQL 8.4 / PHPUnit 11.5.55
**Rulings implemented:** SR-1 (receipt-scoped FIFO valuation), SR-2 (returnable ceiling), SR-3 (atomic approval, no AP in V1)

**Outcome:** **CERTIFIED** — `OK (122 tests, 334 assertions)` under the T-6 gate. Six further defects found by adversarial review after the first green run, all fixed and re-proven. Two open items declared in §10.

---

## 1. What the task closed

Three defects carried from the Procurement audit:

| ID | Defect | Resolution |
|---|---|---|
| **G-6 / D-5** | Supplier returns did not consume FIFO layers; valuation was not the acquired cost | Approval consumes the anchored receipt line's layers through the canonical engine and values the line at the **actually consumed** weighted cost |
| **D-6** | Approval was non-atomic: status was written, then inventory was mutated separately | One `DB::transaction`: validate → FIFO → inventory + ledger → status **last** |
| **D-7** | No returned-vs-received ceiling existed anywhere | `Returnable = Received − Previously Returned`, per `goods_receipt_line_id` |

No second inventory engine was created. Quantity and ledger go through `AdjustmentOutAction`; FIFO goes through `InventoryLayerConsumptionService`. The return path writes no ledger row directly and does not touch the legacy `stock_movements` table.

---

## 2. Change surface

**New**

- `Modules/Purchasing/SupplierReturns/Application/Actions/ApproveSupplierReturnAction.php`
- `Modules/Purchasing/SupplierReturns/Domain/Services/ReturnableQuantityService.php`
- `Modules/Purchasing/SupplierReturns/Domain/Exceptions/SupplierReturnValidationException.php`
- `tests/Feature/Purchasing/SupplierReturnValuationTest.php`

**Modified**

- `Modules/Inventory/ReceiptLayers/Application/Services/InventoryLayerConsumptionService.php` — one optional `?string $goodsReceiptLineId = null` parameter, applied via `->when(...)`. Every existing caller omits it and is bit-for-bit unchanged.
- `Modules/Purchasing/SupplierReturns/Presentation/Http/Controllers/SupplierReturnController.php` — `approve()` delegates to the action; `SupplierReturnValidationException` → 422.
- `.../Requests/StoreSupplierReturnRequest.php` — `lines.*.goods_receipt_line_id` is now `required|uuid|exists`.
- `.../Resources/SupplierReturnResource.php` — exposes `goods_receipt_line_id`.
- `frontend/src/features/supplier-returns/types/supplier-return.ts` — anchor typed as required.

**Deleted**

- `.../Actions/ReverseSupplierReturnInventoryAction.php` — superseded. It was the non-atomic second step; its behaviour now lives inside the single transaction. No source reference to it remains (only stale PHPStan cache and composer classmap artifacts).

**No migration was written. No schema was changed. No production data was modified.**

---

## 3. The three rulings, as implemented

### SR-1 — receipt-scoped FIFO

`consume()` gains one predicate: `where('goods_receipt_line_id', $goodsReceiptLineId)`. Ordering (`created_at`, `id`), `lockForUpdate`, company scoping and the weighted-cost return value are untouched, so this narrows the eligible layer set without altering the engine's behaviour for anyone else.

The return line's valuation is `$consumption->weightedCost` — the cost of the layers actually consumed. Never `material_cost`, never the latest supplier price.

### SR-2 — the ceiling

`Returnable = effectiveReceivedQty() − Σ previously returned`, keyed on `goods_receipt_line_id`.

`original_received_qty` is deliberately **not** used as the received quantity: it is client-supplied in the create request, so trusting it would let the caller declare its own ceiling. Received always comes from the receipt line.

A return line with no `goods_receipt_line_id` is **refused, never guessed** — historical rows are left exactly as they are, not backfilled.

### SR-3 — atomicity, no AP

Status is the last write inside the same transaction as the mutations. No payable, credit note or debit note is posted; `credit_amount`, `credit_method` and `debit_note_number` remain plain return data.

Atomicity survives the nesting: `AdjustmentOutAction` opens its own transaction (a savepoint under an outer one) **and** defers publication with `DB::connection()->afterCommit(...)`, so an outer rollback undoes the stock write *and* suppresses the domain event.

---

## 4. Adversarial review — six further defects, two of them false greens in my own tests

The first full suite passed 115/115. That proved my tests agreed with my code, not that the contract was right, so the implementation went through an independent read-only adversarial review (6 lenses → 24 findings → refutation panel). It found six real defects. **All six are fixed.**

| # | Defect | Why the passing suite did not catch it |
|---|---|---|
| **1** | **SR-1 was not actually enforced.** The anchor was validated for existence, company and product — never that its supplier matched the return's own `supplier_id`. A return addressed to Supplier A could name Supplier B's receipt line and consume B's layer at B's cost. | **False green.** The fixture minted a *fresh unrelated supplier* for every return, so all 13 tests were already supplier-mismatched documents — and all were approved. `test_2`, labelled "SR-1's core guarantee", was proving anchor-scoping only; the cross-supplier case SR-1 is worded about was never exercised. |
| **2** | **The SR-2 ceiling was inert in production.** It scoped on `supplier_returns.company_id`, but `store()` persists `$request->safe()->except('lines')` and `company_id` is not a validated key — so *every* API-created return carries NULL, `NULL = '<uuid>'` is UNKNOWN, the sum returned 0, and `Returnable` degenerated to `Received`. | **False green.** The fixture set `company_id` explicitly — a column the production create path never sets. Verified on the live engine: `(NULL = 'x') IS NULL` → 1. |
| **3** | **Idempotency was check-then-act.** The `inventory_restocked` guard read a stale in-memory model before the transaction opened and took no lock, so two concurrent approvals both passed it and both posted — one 4-unit return removing 8 units. | Covered only sequentially (`test_11` re-reads after the first commit). |
| **4** | **The ceiling did not accumulate within one return.** `returnable()` excludes the return being approved, so two lines anchored to the same receipt line each measured against the full untouched allowance: 60 + 60 against a 100-unit receipt both passed. | No multi-line-same-anchor test existed. Raised independently by three lenses. |
| **5** | **`Approved → Cancelled` handed the allowance back.** That transition is legal and `Cancelled` is not a consuming status, but no restock path exists — the stock stays gone, so the same units could be returned twice. | No cancellation test existed. |
| **6** | **The now-mandatory anchor was invisible to clients.** The read model omitted `goods_receipt_line_id` while `update()` (which reuses `StoreSupplierReturnRequest` and replaces every line) requires it — a client could not round-trip fetch→edit→PUT. The frontend payload type still declared it optional. | Contract surface, not covered by backend tests. |

### How each was fixed

1. Fail-closed supplier guard in `resolveReceiptLine()`, checked **after** the company guard so a cross-company anchor still reports as such. Both identities are NOT NULL, so it cannot fail open.
2. Scope through the warehouse — `COALESCE(sr.company_id, srw.company_id)`. `supplier_returns.warehouse_id` is NOT NULL, and the column's own backfill migration defines company exactly this way (`SET sr.company_id = w.company_id`). Correct for populated and NULL rows alike.
3. `lockForUpdate()` re-read of the return row **inside** the transaction; the loser of a race observes the winner's committed flag.
4. Per-approval accumulator keyed by receipt line, subtracted from the allowance for each subsequent line.
5. The ceiling now counts a row on status **or** `inventory_restocked` — the exact discriminator between "cancelled after the stock moved" and "cancelled before it ever did", written in the same transaction as the movement. `ApproveSupplierReturnAction` is the sole writer of that flag anywhere in `backend/Modules`, so it cannot be set spuriously.
6. Anchor exposed in `SupplierReturnResource`; frontend type made required so a future create form fails to compile rather than 422-ing at runtime.

### Fixtures corrected, assertions never weakened

Per the standing instruction, every fix was made at the cause:

- `makeReturn()` now **derives** `supplier_id` from the anchored receipt line, with an override so the mismatch can be tested deliberately (`test_14`).
- `makeReturn()` now leaves `company_id` **NULL by default**, mirroring what `store()` actually produces.
- `test_2` and `test_10` were pinned to a single supplier so they fail on the condition they were written to prove, rather than tripping the new supplier guard.
- The ledger assertion `assertNotSame(PurchaseReceipt)` — satisfied by every value the enum has, so it could never fail — became a positive `assertSame(AdjustmentOut)` plus a quantity check.

---

## 5. Findings raised but **not** fixed

Reported rather than silently resolved. None is a regression introduced by this task.

| Finding | Assessment |
|---|---|
| `supplier_returns.company_id` is never populated by `store()` | **Pre-existing data-quality defect.** The ceiling is now immune to it, but the column stays NULL, which still affects the `supplier_id`/company filters in `index()` and the totals in `stats()`. Fixing the create path is a wider blast radius than this task's fence and belongs in its own change. |
| Inventory item resolved via `findByWarehouseAndProduct()` without an explicit company argument | **Benign.** A warehouse belongs to exactly one company, so warehouse + product is already company-determined; `AdjustmentOutAction` re-resolves with the explicit company filter immediately afterwards. |
| 16 lower-severity review findings exceeded the verification cap | **Dropped, not cleared.** They were never adversarially verified and are not claimed as resolved. Listed in the run journal. |
| Supplier return financial/payable effect | **Out of scope by ruling.** SR-3 states there is no AP mutation in V1. `credit_*` and `debit_note_number` remain inert data. |

---

## 6. Certification matrix (PART 16)

Every gate is tied to a named test. Verdicts are recorded from the certifying run in §9.

| | Gate | Evidence |
|---|---|---|
| **A** | FIFO return valuation | `test_1` — 6 units of a 10 @ 100 layer value the line at exactly 100/600, taken from the consumed layer. `test_2` — a return anchored to the 120 layer values at 120, not the older 100. |
| **B** | FIFO layer consumption | `test_1` — `remaining_qty` 10 → 4. `test_2` — the anchored layer drops while the older one stays at 10. |
| **C** | Partial return | `test_3` — 20 then 30 then 50 against 100. `test_5c` — exactly the remaining allowance is accepted. |
| **D** | Full return | `test_3` — the third slice exhausts the line; on-hand and layer both reach 0 and a further unit is refused. |
| **E** | Over-return rejection | `test_5` (nothing mutated), `test_5b` (after a prior return), `test_15` (production-shaped NULL `company_id`), `test_17` (two lines, one anchor), `test_19` (net vs gross). |
| **F** | Multiple receipts / layers | `test_2` — two receipt lines, same product, same supplier, different costs. `test_14` — two suppliers. |
| **G** | Atomic approval | `test_10` — status is written last inside the transaction; a later invalid line leaves the return unapproved. |
| **H** | Rollback on failure | `test_10` — the earlier valid line's stock, layer **and** ledger are all restored. `test_10b` — a retry after the failure then succeeds exactly once. |
| **I** | Idempotent retry | `test_11` — sequential re-approval is a no-op. `test_16` — a *stale* model (the concurrent shape) cannot post a second time. |
| **J** | Duplicate approval | `test_11` — no duplicate ledger row, no second FIFO consumption. |
| **K** | Tenant isolation | `test_12` — a Company A return anchored to a Company B receipt line is refused and B's layer is untouched. |
| **L** | Quantity / unit correctness | `test_ledger…` — exactly one entry, movement type `AdjustmentOut`, quantity 3. `test_19` — the ceiling follows `net_received_quantity`, not gross. |
| **M** | Certified inbound regression | `InboundOwnershipContractTest` — all 11 tests, executed in the same run. |
| **N** | Static quality | Pint (16 files), PHPStan L0, `tsc -p tsconfig.app.json`. |
| **SR-1′** | Supplier identity | `test_14` — a return addressed to Supplier A naming Supplier B's receipt line is refused; neither layer moves. |
| **SR-2′** | Ceiling accumulation & cancellation | `test_17` — two lines cannot double-claim one receipt line. `test_18` — cancelling an approved return does not restore the allowance. `test_15b` — backfilled rows carrying `company_id` still work. |

---

## 7. Scope compliance

- No second inventory mutation path. No coordinating layer. No new FIFO engine.
- The certified `TASK-PROCUREMENT-INBOUND-OWNERSHIP-CLOSURE-001` architecture was not reopened; `InboundPostingGuard`, `PostGoodsReceiptAction` and `PostSupplierInvoiceService` are untouched by this task.
- Nothing in Shipping, Loading, Vehicle, Driver, Delivery, Settlement, Manufacturing, Preparation, Order lifecycle, Customer/CRM or Marketing was modified.
- Supplier Product Demand metrics were not touched. Note that `tests/Feature/Purchasing` also contains another agent's concurrent work (`GetSupplierProductDemandQuery`, `GetProcurementHealthQuery`, `GetSupplierPriceHistoryQuery`, `SupplierAnalyticsController`, `ProcurementQueryRuntimeRepairTest`, `SupplierProductDemandTest`). Those files are not mine and their results are not claimed as this task's evidence.
- No migration, no schema change, no production data change, no `migrate:fresh` outside the gate.
- Every runtime run was executed under the T-6 advisory-lock gate.

---

## 8. Note on the test gate

`scripts/test-gate.sh` is written to run **inside** `ecos-dev-testrunner` — it requires the `mysql` client, `/proc` and `/var/www/html`. Invoked from the Windows host it silently degrades: every DB probe fails through `2>/dev/null`, and the gate reports "advisory lock held by: nobody" with `?` for the counts, i.e. *free without having checked anything*. The `?` is the tell.

Correct invocation:

```bash
docker exec -e GATE_WAIT=2400 ecos-dev-testrunner sh /var/www/html/scripts/test-gate.sh tests/Feature/Purchasing
```

Git Bash additionally rewrites container-absolute paths (`/var/www/...` → `C:/Program Files/Git/var/www/...`), so `MSYS_NO_PATHCONV=1` is required.

---

## 9. Runtime certification

**Certifying run — `tests/Feature/Purchasing`, executed under the T-6 advisory-lock gate:**

```
[GATE] busy (an ungated phpunit process is running) — queueing up to 3000s
[GATE] acquired ecos:testrunner:ecos_dev_test (connection 82524)

PHPUnit 11.5.55 by Sebastian Bergmann and contributors.
Runtime:       PHP 8.4.24

OK (122 tests, 334 assertions)     Time: 07:12.892

[GATE] released ecos:testrunner:ecos_dev_test
```

**Every gate A–N, SR-1′ and SR-2′ is PASS.** The whole directory passed, so every subset did — including all 20 Supplier Return tests and all 11 certified-inbound regression tests (gate M).

Composition: 91 pre-existing Purchasing tests + 11 `InboundOwnershipContractTest` + 20 `SupplierReturnValuationTest` = 122.

**Static quality (gate N)**

| Tool | Scope | Result |
|---|---|---|
| Pint | `Modules/Purchasing/SupplierReturns`, `InventoryLayerConsumptionService`, the test file | PASS — 16 files |
| PHPStan L0 | `SupplierReturns`, `ReceiptLayers`, `GoodsReceipts`, `SupplierInvoices` | PASS — no errors |
| `tsc -p tsconfig.app.json` | frontend | 0 errors in supplier-returns; the 24 repo-wide errors are pre-existing and all in unrelated features (admin, HR, marketing, orders, logistics, stock-ledger) |

### Progression, stated plainly

| Run | Result | What it established |
|---|---|---|
| 1 | 115 tests, 8 errors | Fixture defect: `approved_by` is `bigint unsigned`, the helper passed the string `'tester'`. Fixed at the cause — a real `User` — not by relaxing the signature. |
| 2 | **OK** 115 / 294 | Implementation green — and, as the adversarial review then showed, green partly for the wrong reasons. |
| 3 | **OK** 119 / 326 | Supplier guard, warehouse-scoped ceiling and the locked idempotency re-check, with fixtures corrected to the production shape. |
| 4 | 122, 1 failure | Ceiling accumulation, cancellation and net-vs-gross all passed. The single failure was my own assertion comparing a string to a cast enum. |
| 5 | **OK** 122 / 334 | Certifying run. |

Run 4's failure is worth recording rather than hiding: the assertion it broke was the one introduced to *replace* a tautology, and it failed for the same underlying reason the tautology existed — `movement_type` is cast to `LedgerMovementType`, so the original `assertNotSame(PurchaseReceipt->value, …)` had been comparing a string with an enum instance and could never have failed.

---

## 10. Verdict

**CERTIFIED** for SR-1, SR-2 and SR-3, on runtime evidence, with the six additional defects found by adversarial review fixed and re-proven.

Two caveats stated explicitly rather than buried:

1. **`supplier_returns.company_id` is still never populated by `store()`.** The SR-2 ceiling no longer depends on it, but `index()` filtering and `stats()` totals still do. Pre-existing; recommended as its own task.
2. **16 lower-severity review findings exceeded the verification cap.** They were dropped before adversarial verification and are *not* cleared.

No further Procurement work was started.

