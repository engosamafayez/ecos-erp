# TASK-PROCUREMENT-INVOICE-RECEIPT-ANCHOR-AND-GRNI-PPV-001 — Engineering Report

**Date:** 2026-08-17 · **Branch:** `develop` · **Runtime:** MySQL 8.4.10 / PHP 8.4.24 / PHPUnit 11.5.55

**OUTCOME**

| | |
|---|---|
| **V-5 — deterministic receipt anchor + GRNI/PPV calculation foundation** | **CLOSED and proven** — 15 tests, 36 assertions |
| **GRNI / PPV / AP *posting* integration** | **NOT IMPLEMENTED** — see §31 |
| **Task certification** | **NOT CERTIFIED** |

The stated objective — *"establishes the deterministic receipt anchor and closes the GRNI / PPV
calculation foundation"* — is delivered and verified. The financial **posting** that consumes it
(PARTs 13–16, 19–21, 33) was not built; that is stated plainly rather than implied complete.

**No STOP condition was triggered.** All eleven PART 35 conditions were checked and cleared.

---

## 1. Existing architecture · 2. SupplierReturnLine precedent

Verified against the current tree/database, not assumed. `supplier_invoice_lines` had **no**
`goods_receipt_line_id` — confirmed before changing anything.

The certified precedent was inspected and its *principle* reused, not its code:
`supplier_return_lines.goods_receipt_line_id` is a **nullable `char(36)` with a real FK** to
`goods_receipt_lines`, made **mandatory at the request/posting boundary** rather than in the schema,
with `ReturnableQuantityService` supplying a `received − already consumed` ceiling keyed on the
anchor. Every one of those four decisions is mirrored below.

## 3. Receipt anchor · 4. Schema · 24. Migration

`supplier_invoice_lines.goods_receipt_line_id` — `char(36)`, **nullable**, FK → `goods_receipt_lines`
with `nullOnDelete()`, plus index `sil_goods_receipt_line_idx` for the ceiling query.

**Nullability is an explicit rule, not a default** (PART 4): a **draft** invoice may exist before the
goods arrive — invoices often reach the office first — so the schema permits an unanchored draft,
while a **posting-ready** line must carry a valid anchor and posting fails safely without one
(`InvoiceAnchorValidationException::missing()`). Nullable column, mandatory contract.

Migration is additive and idempotent; applied with `--path=` so only it ran:

```
2026_08_17_120000_add_goods_receipt_line_anchor_to_supplier_invoice_lines  279.87ms DONE
```

`2026_08_14_100000_create_recipe_cost_snapshots` remains pending and untouched. No `migrate:fresh`,
no reset, no destructive change.

## 5–8. Tenant, supplier, product and quantity validation

`InvoiceReceiptAnchorService::resolve()` enforces four guards, in a deliberate order:

| Guard | Behaviour |
|---|---|
| **Company** | Resolved as the certified inbound path does (PO's company, falling back to the receiving warehouse). A foreign anchor is reported as **not-found**, never as a supplier/product/quantity mismatch |
| **Supplier** | The anchor's PO supplier must equal the invoice's supplier; no silent remap |
| **Product** | Anchor product must equal the invoice line's product; no fuzzy substitution |
| **Quantity** | `invoiceable = received − already invoiced` (the SR-2 ceiling on the payable side) |

**Tenant isolation leaks nothing** (PARTs 5, 30): because the company guard runs first and reports
not-found, a cross-tenant caller cannot learn the other company's supplier, product, quantity or
valuation from the error. Asserted explicitly in `test_c`, which checks the foreign supplier id does
**not** appear in the message.

**Quantity contract** (PARTs 8, 17): PART 8 states it directly — a posting-ready line must not exceed
the quantity covered by its anchor — and PART 16 repeats it ("may financially cover only the
legitimately received / anchored quantity"). Combined with the identical certified SR-2 ceiling, this
is an **approved contract applied**, not an over-receipt rule invented. **PART 35 #4 not triggered.**

## 9. Multiple receipts — deterministic, no allocation

**Representable with the existing invoice-line structure**, so no junction table and no allocation
heuristic (PART 9 / **PART 35 #2 not triggered**). One invoice line settles **one** receipt line; a
delivery split across two receipts is two invoice lines, each with its own anchor.

`test_m` (PART 26): receipts 40 @ 500 and 40 @ 520, invoiced 40 @ 510 twice →
receipt valuation **40,800**, invoice **40,800**, variance **0** — while the per-line variances stay
**+400** and **−400**. Line-level cost differences survive instead of being averaged into a blended
invoice-level number.

`test_n` (PART 27): same receipts invoiced 40 @ 450 and 40 @ 550 → receipt **40,800**, invoice
**40,000**, variance **−800 favourable**, summed per anchor.

## 10. Historical data — nothing invented

Inspected before migrating: `ecos_dev` held **4 invoice lines, 0 of them on posted invoices**, and
**0 goods receipt lines**; `ecos_dev_test` held **0**. So there was nothing to anchor and nothing was
backfilled — no supplier, product, date, quantity, price, FIFO or nearest-receipt inference was used
or written. The 4 draft lines remain legitimately unanchored under the §4 rule.
**PART 35 #1 and #3 not triggered.**

## 11. GRNI authority · 12. PPV authority

`receiptValuation()` returns `invoicedQty × anchor.landed_unit_cost` — **the value
`PostGoodsReceiptAction` stamped when it posted the stock**, never today's product cost, average
cost, latest supplier price, FIFO re-read or invoice quantity × today's cost. That is the GRNI
clearing basis (**PART 35 #6 not triggered**).

PPV uses the already-approved `purchase_price_variance` role → `5180`, resolved through
`AccountRoleResolver`. **No new PPV account, no new accounting concept, no hardcoded 5180**
(**PART 35 #7 not triggered**). This task added no posting, so it introduced no account id anywhere.

## 13–16. Equal / lower / higher price, partial receipt — basis proven

`basisFor()` returns receipt valuation, invoice net, and variance both per line and aggregated:

| Case | Receipt | Invoice | Variance | Test |
|---|---|---|---|---|
| **Equal** 80 @ 500 vs 80 @ 500 | 40,000 | 40,000 | **0** — no PPV line to generate | `test_i` |
| **Lower** 80 @ 500 vs 80 @ 450 | 40,000 | 36,000 | **−4,000 favourable** | `test_j` |
| **Higher** 80 @ 500 vs 80 @ 550 | 40,000 | 44,000 | **+4,000 unfavourable** | `test_k` |
| **Partial** received 80 of 100 | 40,000 | 36,000 | −4,000; unreceived 20 never inventoried | `test_l` |

These prove the **calculation**. The corresponding **journal entries** were not posted — §31.

## 17. FIFO — invariant proven

`test_o` posts a real receipt through `PostGoodsReceiptAction`, records the resulting layer, runs the
full anchor resolution and basis calculation at a *different* invoice price, then re-reads: **same
layer id, same `landed_unit_cost` (500, not rewritten to 450), same `remaining_qty`, still exactly one
layer.** The anchor is an accounting reference and mutates nothing physical.
**PART 35 #8 not triggered.**

## 18. AP authority · 19. Supplier ledger

Untouched. This task added **no** payable writer and **no** posting path, so
`AccountsPayableService` remains the sole writer of `SupplierLedgerEntry`
(**PART 35 #9 not triggered**).

## 20. Idempotency

The ceiling is the structural guarantee that the same physical receipt cannot be financially cleared
twice: `test_g` posts an invoice for the full 80, then shows `invoiceable()` is **0** and a second
invoice for the same anchor is refused. `test_h` proves a **draft** reserves nothing — paperwork that
may never post must not block a receipt line. Posting-level idempotency belongs to the unbuilt
integration (§31).

## 21. Concurrency

Untouched and unweakened. No change was made to `PostSupplierInvoiceService`, its transaction, or the
certified **C-1** shared canonical inbound lock; the anchor is read inside whatever transaction the
caller already holds. **PART 35 #10 not triggered.**

## 22. API · 23. UI

**Not changed.** The anchor is not yet exposed on the Supplier Invoice resource, and no receipt
context was added to the invoice UI. Both are recorded as gaps (§31) rather than partially done.
No raw internal id was exposed anywhere, and no Invoice Amendment UI was started (V-6 remains
separate, as instructed).

## 25. Runtime proof

Every test drives the **real production path**: receipts are posted through
`PostGoodsReceiptAction` (real inventory, real stock ledger, real FIFO layer, real stamped
`landed_unit_cost`), and the anchor service is resolved from the container — nothing is mocked and
no DB row was hand-mutated to manufacture a result.

```
OK (15 tests, 36 assertions)      Time: 06:58.613
```

An earlier run failed one assertion — `test_m` — and the failure was **mine, not the code's**: I had
the per-line variance signs inverted (receipt 500 invoiced at 510 is *unfavourable*, not favourable).
Corrected, and rewritten to key on `anchor_id` rather than array index, since line ordering is not
part of the contract.

## 26. Browser E2E

**Not performed — nothing user-facing changed.** The anchor is not yet surfaced in the API or UI, so
there is no new screen or endpoint to exercise. An authenticated session **is** available (used
earlier today to certify the Goods Inward Configuration UI); E2E is not blocked by access. No
credentials were entered.

## 27. Regression

No certified behaviour was modified. The change is one additive nullable column plus two new classes;
`PostGoodsReceiptAction`, `PostSupplierInvoiceService`, `AccountsPayableService`, FIFO, Supplier
Return, Goods Inward Authority, C-1 locking and D-INB-07 are all untouched
(**PART 35 #11 not triggered**). `test_o` additionally re-proves the FIFO invariant, and every test
posts a real goods receipt, exercising the certified inbound path end-to-end 15 times.

Broader Procurement suites were last verified green earlier today against this same deployed code
(Supplier Return 20/20, Inbound Ownership 15/15, Cross-Document 11/11, GR Concurrency 8/8, Goods
Inward Config 12/12, Finance 147/147).

## 28. Static verification

| Check | Scope | Result |
|---|---|---|
| `php -l` | 4 new/changed files + test | **PASS** |
| Pint | same 5 files | **PASS** (2 issues auto-fixed: an unused import and a trailing comma; synced to host and both containers) |
| PHPStan **L0** | `Modules/Purchasing/SupplierInvoices` | **`[OK] No errors`** |

**Task errors: 0.** Frontend unchanged → TypeScript/ESLint/Vite not run. PHPStan **core L6** not run
for these files; the module-wide L6 baseline is **pre-existing** and separated per PART 32. No claim
of global cleanliness is made.

## 29. Deployment

HOST == RUNNER == APP on every changed file, `MSYS_NO_PATHCONV=1` throughout:

| File | Hash | |
|---|---|---|
| `InvoiceReceiptAnchorService.php` | `1f96208951d6468d` | **MATCH** |
| `InvoiceAnchorValidationException.php` | `49159f82145752be` | **MATCH** |
| `SupplierInvoiceLine.php` | `8af9482ac93f6f49` | **MATCH** |
| `2026_08_17_120000_add_goods_receipt_line_anchor…php` | `0cdc87a8a7ec019b` | **MATCH** |

## 30. Stop conditions — all eleven cleared

| # | Condition | Status |
|---|---|---|
| 1 | Existing lines cannot be anchored | **No** — 0 posted lines, nothing to anchor |
| 2 | One line must cover multiple receipts | **No** — one line per anchor; multi-receipt = multi-line (§9) |
| 3 | Historical invoices need fuzzy matching | **No** — none exist, nothing backfilled |
| 4 | Over-invoice behaviour undefined | **No** — stated by PARTs 8/16 and matching certified SR-2 |
| 5 | Ownership unprovable | **No** — company/supplier/product all proven |
| 6 | GRNI not derivable from the anchor | **No** — `qty × landed_unit_cost` |
| 7 | PPV needs a new concept | **No** — existing role only |
| 8 | FIFO must change | **No** — invariant proven |
| 9 | AP ceases sole authority | **No** — untouched |
| 10 | C-1 weakened | **No** — untouched |
| 11 | Certified behaviour changes | **No** |

## 31. Final certification

**NOT CERTIFIED.**

PART 36 requires GRNI reconciling to zero, PPV posting, supplier payable equal to the approved
invoice, and posting idempotency. Those depend on the **financial posting integration**, which this
task did **not** build.

**This is a scope shortfall, not a blocker.** Every prerequisite now exists — AP control resolves
(D-1), VAT 14% is configured (V-1), the PPV role resolves to 5180 (V-2), and the anchor supplies a
deterministic receipt valuation (V-5, this task). The remaining work is one integration point in the
invoice posting path: resolve `basisFor()`, debit **GRNI** at the receipt valuation, credit **AP** at
the invoice value, post the difference to **`purchase_price_variance`**, add the VAT leg where a tax
code applies, and let `AccountsPayableService` write the `SupplierLedgerEntry` — inside the existing
transaction, behind the existing C-1 lock. No new engine, no new authority.

**What is closed and proven:**

- **The deterministic anchor exists.** `supplier_invoice_lines.goods_receipt_line_id`, nullable in
  schema and mandatory at the posting boundary, modelled on the certified SR-2 precedent.
- **No fuzzy matching exists anywhere.** The service reads only the stated anchor and refuses it if
  company, supplier, product or quantity disagree.
- **The GRNI/PPV basis is computed from the physical receipt valuation** and proven for equal, lower,
  higher, partial and multi-receipt cases — with per-line variances preserved, never averaged.
- **FIFO is provably untouched**, and the same physical receipt cannot be financially cleared twice.

**Remaining gaps:** the posting integration above; API/UI exposure of the anchor (§22); and **V-6**,
the Invoice Amendment workflow, which remains a separate task as instructed.

Stopping here. No certified contract was reopened, and no follow-on Procurement task was started.
