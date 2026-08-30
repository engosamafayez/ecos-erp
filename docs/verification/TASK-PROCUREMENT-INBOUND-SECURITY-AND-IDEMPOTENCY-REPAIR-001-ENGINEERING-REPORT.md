# TASK-PROCUREMENT-INBOUND-SECURITY-AND-IDEMPOTENCY-REPAIR-001 — Engineering & Certification Report

**Date:** 2026-08-15 · **Branch:** `develop` · **Worktree:** `C:\ecos-develop`
**Runtime:** PHP 8.4.24 / MySQL 8.4 / PHPUnit 11.5.55
**Source of truth:** `TASK-PROCUREMENT-INBOUND-OWNERSHIP-CLOSURE-001-ENGINEERING-REPORT.md` (audit not repeated)

## 1. Executive Summary

Both confirmed blockers are repaired. **VERDICT: CERTIFIED** — `OK (137 tests, 394 assertions)` under the T-6 gate, deployed to `ecos-dev-app` and runtime-verified.

| | Blocker | Repair |
|---|---|---|
| **B-1** | One physical delivery documented as both a Goods Receipt and a Supplier Invoice posted to inventory **twice** — the guard's collision key (`auto_receipt_id`) had no production writer | **G-1 option (c)**: `companies.goods_inward_mode` names exactly ONE authoritative inbound document per company. The other document never moves stock, so there is nothing to match |
| **B-2** | A Company A actor posted Company B's goods receipt over HTTP (**200**, 50 units into another company's warehouse) | The canonical `tenant` global scope on `GoodsReceipt` and `SupplierInvoice` → foreign row invisible → existing not-found exception → **404**, zero mutations |

The decisive property of the B-1 repair is that it **eliminates the matching problem rather than solving it**. No quantity comparison, no price comparison, no timestamp window, no fuzzy similarity, no reliance on operator discipline or on the order paperwork is raised in. Authority is configuration; a non-authoritative document cannot post regardless of what else exists.

---

## 2. G-1 Decision

**SELECTED: option (c) — mutually exclusive goods-inward authority per company.**

`companies.goods_inward_mode ∈ {goods_receipt, supplier_invoice}`, default `goods_receipt`.

**Ownership.** Exactly one document type posts inventory for a company at a time:

| Mode | Inventory / ledger / FIFO owner | The other document |
|---|---|---|
| `goods_receipt` (default) | `PostGoodsReceiptAction` | Supplier Invoice completes as a **financial document only** |
| `supplier_invoice` (ADR-011 Mode 3) | `PostSupplierInvoiceService` | Goods Receipt completes as a **receiving record only** — PO received quantity and receipt status still advance |

**Identity / reference mechanism.** Unchanged and reused: `stock_ledger_entries.(reference_type, reference_id)` with `movement_type = purchase_receipt`, read by the existing `InboundPostingGuard`. That guard still prevents a document duplicating **itself**. What is new is that it is no longer asked to recognise two documents as one delivery — a question the data could not answer.

**Why (c) preserves the existing architecture with the smallest safe change:**

- **No new schema for the documents.** One column on `companies`, alongside `currency` and `timezone`, which are the established per-company policy fields.
- **No new inventory or FIFO engine.** Both paths still converge on `ReceiveStockAction` and `CreateReceiptLayersAction`. The repair only decides *whether* a path runs.
- **No new tenant model, no new idempotency mechanism.** The ledger reference, the posting guard and the transaction boundaries are untouched.
- **It follows an existing ECOS pattern exactly.** `GoodsInwardAuthority` mirrors `CompanyTimezoneResolver`: query-builder reads (never the model, because it is consulted from console/queue contexts where a tenant scope would silently return nothing), memoised per company.
- **Historical traceability is preserved.** No document is deleted, altered or auto-generated; `auto_receipt_id` remains available for attribution and is still honoured when set, it is simply no longer load-bearing for correctness.
- **Certified Procurement behaviour is preserved** — default `goods_receipt` means the receiving path behaves exactly as before.

**Fail-safe, not fail-closed.** An unset or unrecognised mode resolves to `goods_receipt`. Failing closed would halt all stock movement on a missing setting, which is worse than a well-defined default that matches prior behaviour.

---

## 3. G-1 Alternatives Rejected

| Option | Verdict | Reason |
|---|---|---|
| **(a) Implement Mode 3 auto-generation** — posting an invoice generates the purchase + receipt and stores their ids | **Rejected** | Solves only the case where the invoice is the *sole* document. If an operator also raises a Goods Receipt by hand for the same delivery — which the application fully permits — the invoice generates a *second* receipt and both post. The guarantee still rests on operator discipline, which the task forbids. It is also the largest change of the three. |
| **(b) Expose `auto_receipt_id` on the create contract** | **Rejected** | Smallest code change, but **opt-in**: protection exists only when the operator supplies the link. An omitted link reproduces the exact defect being repaired. That is operator discipline, explicitly excluded. It also makes a nullable client-supplied field load-bearing for an accounting invariant. |
| **(c) Mutually exclusive modes** | **Selected** | The only option that holds by construction, independent of linkage, ordering, timing and discipline. |

No fourth option was invented.

---

## 4. Physical Inbound Identity

The audit's finding stands and is not re-litigated: `supplier_invoices.auto_receipt_id` is nullable, has **no production writer**, and Mode 3's auto-generation was never implemented (verified against pristine `HEAD`).

The repair's answer is that **the system no longer needs to identify two documents as the same physical inbound.** Only one document type is capable of posting for a given company, so two postings for one delivery cannot arise, whether or not the documents are linked.

Within a single document, identity is unchanged: the ledger reference (`goods_receipt:<id>` or `supplier_invoice:<id>`) plus the existing status contract.

---

## 5. Goods Receipt Ownership

`PostGoodsReceiptAction` gains one guard (3b) after the company is resolved and **before** the transaction opens:

```php
$postsInventory = $this->inwardAuthority->receiptMayPost((string) $companyId);
```

When false, the receipt still performs its receiving bookkeeping — landed cost stamped on lines, `purchase_order_lines.received_qty` incremented, PO status advanced, receipt marked Posted — but `ReceiveStockAction` and `CreateReceiptLayersAction` are skipped. The goods physically arrived; only the *inventory effect* belongs to the other document. The success message states which happened rather than always claiming "Inventory updated."

---

## 6. Supplier Invoice Ownership

`PostSupplierInvoiceService` gains the same question, checked **before** the ledger guard because it is the stronger statement:

```php
$mayPost = $this->inwardAuthority->invoiceMayPost($companyId);
if (! $mayPost)            { /* financial document only */ }
elseif ($guard->alreadyPosted(...)) { /* existing duplicate protection */ }
else                       { /* canonical inbound posting */ }
```

The no-inventory branch already existed for the "already posted" case, so the invoice path required no new structure — only a second reason to take it.

---

## 7. Inventory Posting Ownership

Unchanged and single: **`ReceiveStockAction`**. Neither path mutates `on_hand_qty` directly, and no second inventory engine was created. The repair gates *entry* to the canonical action; it does not duplicate or bypass it.

---

## 8. FIFO Ownership

Unchanged and single on the inbound path: **`CreateReceiptLayersAction`**. FIFO valuation rules were not modified. The repair only prevents a second, non-authoritative document from invoking it for a delivery already layered by the authority.

---

## 9. Stock Ledger Ownership

Unchanged: **`ReceiveStockAction`** writes `stock_ledger_entries` with `movement_type = purchase_receipt`. The Stock Ledger was not redesigned; it remains both the audit trail and the per-document idempotency store.

---

## 10. Idempotency

Two independent mechanisms, each covering a different failure:

| Failure | Mechanism |
|---|---|
| The **same document** posted twice | Existing: document status contract + `InboundPostingGuard` reading the ledger reference |
| **Two different documents** for one delivery | New: G-1 authority — only one document type can post at all |

Neither replaces the other, and neither depends on the documents being linked.

---

## 11. Concurrency

No new locking architecture was introduced, as instructed.

Existing protections retained: `ReceiveStockAction` locks the inventory row; the over-receipt guard locks the PO line inside the transaction; `PostSupplierInvoiceService` row-locks the invoice as the first statement in its transaction.

**Recorded, not repaired:** `PostGoodsReceiptAction` evaluates its guards before the transaction opens and takes no lock on the receipt itself, so two simultaneous posts of the *same* receipt remain a check-then-act race. This is unchanged from the audit (D-INB-03), is a distinct defect from the two blockers this task was authorized to close, and adding a receipt-level lock is outside "only close the two confirmed blockers". Its blast radius is now strictly smaller, because the non-authoritative document cannot participate at all.

---

## 12. Tenant Isolation

`GoodsReceipt` and `SupplierInvoice` now carry the platform's canonical `tenant` global scope — copied verbatim from the four models that already had one (`Order`, `Warehouse`, `Supplier`, `ShippingPricingRule`). No new tenant model, resolver or middleware was written; `TenantOwnershipResolver` and `CurrentCompanyService` are reused unchanged.

Resulting order of enforcement: **authentication → permission → tenant ownership → business validation → inbound mutation.** A foreign row is invisible, the repository lookup returns null, and the pre-existing `GoodsReceiptNotFoundException` (constructed with 404) produces the status the certified ECOS tenant contract already expects — the same shape `SupplierTenantIsolationTest` asserts for suppliers.

### The prerequisite the audit exposed

`goods_receipts.company_id` and `supplier_invoices.company_id` were both **nullable, backfilled once, and never written since** — so every document created since carried NULL, and the goods-receipt list filter that reads the column had been matching nothing. Scoping on a permanently-NULL column would have hidden every document from every actor. The repair therefore also:

1. **Populates ownership at create time** — `CreateGoodsReceiptAction` from the purchase order (falling back to the warehouse) and `SupplierInvoiceController::store()` from the warehouse, exactly the derivations the original backfill migrations define. Read through the query builder, because `Warehouse` is itself tenant-scoped and resolving a write path through a scope derived from the actor would be circular.
2. **Re-runs the backfill idempotently** for pre-existing NULL rows.
3. **Stamps `company_id` in `GoodsReceiptFactory`**, so fixtures mirror what production now writes.

Database FK/NOT NULL failures are explicitly **not** relied upon anywhere in this repair.

---

## 13. HTTP Security Tests

`tests/Feature/Purchasing/InboundOwnershipHttpTest.php` — 11 tests over the real HTTP surface,
with `$grantsBaselineAuthorization = false` so the actor holds only the named permission and no
is_system role.

| Required | Test | Result |
|---|---|---|
| 1 — Company A posts **own** Goods Receipt → success | `test_12c…`, `test_1…over_http` | **PASS** — 200, 30/100 units, 1 ledger row, 1 layer |
| 2 — Company A posts **Company B** Goods Receipt → rejected | `test_12…` | **PASS** — **404** (was 200 + 50 units leaked) |
| 3 — Company A posts **own** Supplier Invoice → success | `test_2…over_http` | **PASS** — 200, status `Posted` |
| 4 — Company A posts **Company B** Supplier Invoice → rejected | `test_12b…` | **PASS** — **404** (was 422 from a DB constraint) |
| 5 — Repeated authorized post → idempotent | `test_3…`, `test_4…` | **PASS** — quantity, ledger and layer all unchanged |
| 6 — Cross-tenant MUST NOT mutate Inventory | asserted in `test_12`, `test_12b` | **PASS** — `on_hand` 0.0 |
| 7 — Cross-tenant MUST NOT create FIFO layer | asserted in `test_12`, `test_12b` | **PASS** — layer count 0 |
| 8 — Cross-tenant MUST NOT create Stock Ledger entry | asserted in `test_12`, `test_12b` | **PASS** — inbound ledger count 0 |

Authentication and permission are covered independently: `test_13a`/`test_13b` assert **401**
unauthenticated and `test_13c`/`test_13d` assert **403** without the permission, on both
endpoints, each with no stock movement.

Both tenant tests previously reported `incomplete` with the observed leak. They now assert the
repaired behaviour outright.

---

## 14. Inventory Mutation Tests

| Required scenario | Test | Result |
|---|---|---|
| 1 — Invoice → inbound | `test_b_mode3_supplier_invoice_inbound_posts_once` | **PASS** |
| 2 — Repeated Invoice | `test_d_repeated_invoice_posting_is_idempotent` | **PASS** |
| 3 — Receipt → inbound | `test_a_goods_receipt_inbound_posts_once` | **PASS** |
| 4 — Repeated Receipt | `test_e_repeated_receipt_posting_is_idempotent` | **PASS** |
| 5 — Invoice → Receipt | `test_c2…in_mode3` (linked) · `test_g1_unlinked_invoice_and_receipt_post_once_in_mode3` (**unlinked**) | **PASS** |
| 6 — Receipt → Invoice | `test_c1…` (linked) · `test_g1_unlinked_receipt_and_invoice_post_once_in_receipt_mode` (**unlinked**) | **PASS** |
| 7 — Retry after partial execution | `test_d`, `test_e`, `test_c2b` | **PASS** |
| 8 — Concurrent duplicate attempt | — | **NOT PROVEN** — see §11 (D-INB-03, pre-existing, out of scope) |

**The decisive tests are the unlinked ones.** They construct a goods receipt and a supplier
invoice for the same delivery exactly as the application does — `auto_receipt_id` deliberately
NULL, asserted as such — in both modes and both orders. Before the repair each pair produced 18
units for a 9-unit delivery, two ledger rows and two FIFO layers. Each now yields 9.0 on hand,
one ledger row, one layer.

---

## 15. FIFO Tests

Exactly one receipt layer per physical inbound, asserted in every posting test via
`layerCount()`:

| Case | Result |
|---|---|
| Goods Receipt inbound | 1 layer |
| Supplier Invoice inbound (Mode 3) | 1 layer |
| Repeated Invoice | still 1 |
| Repeated Receipt | still 1 |
| Invoice + Receipt, linked and unlinked, both orders | still 1 |
| Cross-tenant attempt | **0** — no layer created |

FIFO valuation rules were not modified. `test_i_cost_is_propagated_by_the_canonical_inbound`
still asserts `landed_unit_cost` 15.0 and `last_purchase_cost` 15.0, and
`test_i2_invoice_layer_is_attributed_to_its_linked_receipt` still asserts the layer carries its
receipt id.

---

## 16. Supplier Return Regression

**TASK-SUPPLIER-RETURN-VALUATION-001 remains CERTIFIED.** All 20 tests of
`SupplierReturnValuationTest` pass unchanged inside the certifying run — not one was modified,
weakened or reopened.

The end-to-end chain is also re-proven by `InboundOwnershipContractTest::test_11…`: a Goods
Receipt inbound at 12.5 → FIFO layer → receipt-scoped supplier return of 8 units valued at
12.5/100.0, on-hand 20 → 12, the inbound layer consumed rather than a new one created.

---

## 17. Procurement Regression

**Certifying run — `tests/Feature/Purchasing`, under the T-6 advisory-lock gate:**

```
[GATE] acquired ecos:testrunner:ecos_dev_test (connection 89322)

PHPUnit 11.5.55 by Sebastian Bergmann and contributors.
Runtime:       PHP 8.4.24

OK (137 tests, 394 assertions)     Time: 06:53.819

[GATE] released ecos:testrunner:ecos_dev_test
```

**Zero failures, zero errors, zero incomplete.**

Composition: 91 pre-existing Purchasing + 15 `InboundOwnershipContractTest` + 20
`SupplierReturnValuationTest` + 11 `InboundOwnershipHttpTest` = 137.

`TASK-PROCUREMENT-MYSQL-COMPATIBILITY-AND-DEMAND-TIMELINE-REPAIR-001` remains green:
`ProcurementQueryRuntimeRepairTest` and `SupplierProductDemandTest` pass, and neither MySQL
compatibility, Demand Timeline, Supplier Price History nor Supplier analytics was modified.
`SupplierTenantIsolationTest` — the certified tenant suite — also passes, which matters here
because this repair adds scopes of the same kind.

Progression, stated plainly:

| Run | Result | What it established |
|---|---|---|
| 1 | 137 tests, 88 errors, 4 failures | **Invalid.** `Unknown column 'goods_inward_mode'` — the migration was copied to the runner after `migrate:fresh` had already globbed its file list. Not a code defect; re-run after deploying. |
| 2 | 137 tests, 1 error | `test_c2` — my rewrite wrongly assumed a *linked* invoice would let the receipt complete silently. It does not: the shared reference makes the pre-existing ledger guard refuse the receipt, exactly as before. The original assertion was right for that case and was restored, not weakened. |
| 3 | **OK 137 / 394** | Certifying run. |

Run 2 is worth recording rather than hiding: it showed the two mechanisms are genuinely
complementary — authority covers the unlinked case (all of production), and the ledger guard
still fires when a link happens to exist.

---

## 18. Static Quality

| Tool | Scope | Result |
|---|---|---|
| PHPStan **L0** | `Modules/Purchasing`, `Modules/Inventory`, `Modules/Organization` | **PASS** — no errors |
| PHPStan **core L6** | `app/Core` + Contracts + Traits | **PASS** — no errors |
| Pint | changed modules + `tests/Feature/Purchasing` | **PASS** |

No unrelated baseline failures were touched. No frontend files changed, so frontend checks were not run.

PHPStan earned its place here: it caught `class.notFound` for `GoodsInwardAuthority` because the new classes had been written on the host but never copied into the runner — a deployment gap that would otherwise have surfaced as a confusing mass test failure.

---

## 19. Deployment Parity

**Ownership established before any file was copied:**

| Container | Compose project | Working dir | Verdict |
|---|---|---|---|
| `ecos-dev-app`, `ecos-dev-testrunner` | `ecos-dev` | `C:\ecos-develop` | **this worktree** (branch `develop`) — legitimate target |
| `ecos-app` | `ecos-erp` | — | **different project** — standing instruction forbids deploying; not touched |

Ownership was unambiguous, so the complete repair was deployed to `ecos-dev-app`.

**Two prerequisites shipped with it.** The audit found `ecos-dev-app` running pre-repair code:
`InboundPostingGuard.php` was **absent entirely** and `CreateReceiptLayersAction.php` was stale.
The changed `PostGoodsReceiptAction` depends on the guard, so deploying without them would have
fatal-errored. They are part of "the complete repair", not scope expansion.

**Verified after deployment:**

- **File parity** — all 10 production files byte-identical (`md5`) between host and
  `ecos-dev-app`. No file was copied blindly: each was syntax-checked in the container.
- **Autoload** — the container has no `composer` binary, so rather than assume, class resolution
  was proven directly: `GoodsInwardAuthority`, `GoodsInwardMode` and `InboundPostingGuard` all
  resolve through PSR-4 against the app's own `vendor/autoload.php`.
- **Migration** — run **scoped**:
  `php artisan migrate --force --path=Modules/Organization/Companies/Infrastructure/Database/Migrations`.
  A blanket `migrate` was deliberately avoided: the dev database had one pending migration
  belonging to another agent (`2026_08_14_100000_create_recipe_cost_snapshots`), which was
  confirmed **still Pending** afterwards — untouched.
- **Runtime state** — `companies.goods_inward_mode` PRESENT; all 4 companies on the
  `goods_receipt` default, so live behaviour is unchanged by the deployment; `supplier_invoices`
  ownership backfilled to **0 NULL of 2 rows**; `goods_receipts` 0 rows.

**Remaining parity gap, reported not closed:** the certified
TASK-SUPPLIER-RETURN-VALUATION-001 repair is still undeployed on `ecos-dev-app`
(`ApproveSupplierReturnAction` absent, `SupplierReturnController` stale). It belongs to that
task, not this one, and deploying another task's deliverable here would be unauthorized. The dev
app is internally consistent in its current state — nothing in this repair depends on those
files.

---

## 20. MySQL CAST Finding

**Recorded as `P-NEW — PROCUREMENT MYSQL CAST REPAIR`. Not folded into this task.**

`EloquentPurchaseMaterialRepository.php:123` uses `CAST(REPLACE(request_number,'PM-','') AS BIGINT)`, which MySQL rejects (`ERROR 1064`; `AS SIGNED` is the valid target — verified live against this instance in the closure audit).

Dependency on this repair was tested and is **absent**: it sits on the purchase-material *request-number generation* path, which neither the inbound authority nor the tenant scopes touch. Nothing in B-1 or B-2 requires it, so per the task's instruction it stays a separate repair candidate.

---

## 21. Remaining Issues

Carried forward from the audit, unchanged and out of this task's authorized scope:

| ID | Issue | Status |
|---|---|---|
| **D-INB-03** | `PostGoodsReceiptAction` guard is check-then-act with no receipt lock (§11) | open — distinct defect, blast radius reduced |
| **D-INB-04** | Invoice stocks **billed** quantity, receipt stocks **net received**; nothing reconciles | open — contract gap G-2 |
| **D-INB-05** | Invoice and receipt landed-cost formulas diverge; no price-difference contract | open — contract gap G-3 |
| **D-INB-06** | `CAST(… AS BIGINT)` — invalid MySQL (§20) | open — **P-NEW** |
| **D-INB-07** | No unique index on `inventory_receipt_layers` | open |
| **D-INB-08** | GR post mutation has no frontend error handler | open |
| **D-INB-09** | Dropped query params (`record_type`, `supplier_id`, `payment_status`) | open |
| **G-4** | Whether a posted Supplier Invoice has any AP/Finance effect | open — undefined |

A **new** operational consequence worth flagging: `goods_inward_mode` is a per-company policy with no UI. Switching a company to Mode 3 currently requires a direct update. Exposing it in Configuration is a natural follow-up, deliberately not added here (the task authorizes closing two blockers, not adding UI).

---

## 22. Final Certification

**CERTIFIED.**

| Criterion | Result |
|---|---|
| G-1 contract implemented correctly | **PASS** — option (c), §2 |
| Invoice/Receipt cannot double-post | **PASS** — including the unlinked case production actually produces |
| Inventory cannot double-post | **PASS** |
| FIFO cannot double-post | **PASS** — exactly one layer per inbound |
| Stock Ledger cannot double-post | **PASS** — one inbound entry |
| Idempotency | **PASS** — repeated Invoice and repeated Receipt, service and HTTP |
| Concurrency protection where applicable | **PARTIAL** — existing row locks retained; the pre-existing receipt-level race (D-INB-03) is unchanged and out of scope, §11 |
| Goods Receipt tenant isolation | **PASS** — 404 |
| Supplier Invoice tenant isolation | **PASS** — 404, by authorization rather than a DB constraint |
| Cross-tenant requests cause ZERO mutations | **PASS** — quantity, ledger and FIFO all asserted at 0 |
| Supplier Return remains certified | **PASS** — 20/20 unchanged |
| Existing Procurement certifications green | **PASS** |
| HTTP tests pass | **PASS** — 11/11 |
| Static checks pass | **PASS** — PHPStan L0, core L6, Pint |
| Runtime deployment verified | **PASS** — §19 |

**One criterion is qualified rather than claimed clean:** true concurrent duplicate posting of
the *same* Goods Receipt is still not proven, because `PostGoodsReceiptAction` evaluates its
guards before opening its transaction. That defect is pre-existing (D-INB-03), was recorded by
the closure audit, is not one of the two blockers this task was authorized to close, and its
blast radius is now strictly smaller since a non-authoritative document cannot participate at
all. It is listed in §21 rather than quietly folded in.

No second inventory engine, no second FIFO engine, no duplicated stock mutation logic, no new
tenant model, no new idempotency mechanism, no invented fourth G-1 option, no matching
heuristic, and no certified contract reopened. MAIN was not touched.
