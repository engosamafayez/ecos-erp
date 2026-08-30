# TASK-PROCUREMENT-INBOUND-OWNERSHIP-CLOSURE-001 — Engineering & Certification Report

**Date:** 2026-08-15 · **Branch:** `develop` · **Runtime:** PHP 8.4.24 / MySQL 8.4 / PHPUnit 11.5.55

## 1. Executive Summary

**VERDICT: NOT CERTIFIED.** Two blockers, both **pre-existing** and both requiring an owner
contract decision rather than an engineering repair.

| | Blocker | Stop condition |
|---|---|---|
| **B-1** | The duplicate-inbound guard **cannot fire between documents**. Its entire collision key is `supplier_invoices.auto_receipt_id`, and **no production code path anywhere writes that column.** A Goods Receipt and a Supplier Invoice for the same physical delivery resolve to different ledger references, so both post — double stock, two ledger rows, two FIFO layers, two cost cascades. | **#2** no reliable way to identify the same physical inbound · **#5** invoice/receipt relationship undefined |
| **B-2** | **Cross-tenant inbound posting.** A Company A actor holding `purchasing.goods_receipts.update` posted Company B's goods receipt over HTTP: **200, and 50 units landed in the other company's warehouse.** The invoice endpoint leaks no stock but is stopped by a database constraint rather than an authorization decision. No model on the inbound path carries a tenant global scope. | PART 10 / certification criterion "Tenant isolation passes" |

Everything else in the contract holds. Each document individually posts exactly once, is
idempotent on repeat, produces exactly one FIFO layer and one ledger entry, delegates all
mutation to the canonical actions, preserves the cost contract, and leaves the certified
Supplier Return valuation correct. The guard mechanism itself is correctly designed and
correctly implemented — **it is starved of its input, not broken.**

**Nothing was repaired in this task.** B-1 is explicitly a contract gap (PART 6 forbids
inventing a matching heuristic), and B-2's repair is module-wide tenant scoping (PART 10
forbids inventing new tenant logic). Both are documented below with options, not decisions.

The task's own instruction — *"Test the actual HTTP surface where available. Do not rely
exclusively on service tests"* — is what surfaced B-2. Eleven service-level tests were green
against a cross-tenant hole.

---

## 2. Existing Architecture

Two source documents may bring purchased goods into inventory:

```
Purchase Order ──► Goods Receipt ──┐
                                   ├──► InboundPostingGuard ──► ReceiveStockAction ──► stock_ledger_entries
Supplier Invoice (ADR-011 Mode 3) ─┘                        └──► CreateReceiptLayersAction ──► inventory_receipt_layers
                                                                                            └──► Product cost + MaterialCostService
```

The guard asks one question of the ledger — *does a `PurchaseReceipt` row already exist for
this `(reference_type, reference_id)`?* — and maps a linked invoice onto the **receipt's**
reference so both documents collide on one key. No new mechanism was introduced for it;
`stock_ledger_entries` already carried the reference columns.

---

## 3. Supplier Invoice Flow

`PostSupplierInvoiceService::execute()` — one production caller,
`SupplierInvoiceController::post()`, reachable only via `POST /api/supplier-invoices/{id}/post`.
No job, listener, observer, command or event invokes it.

Verified: **the service performs no inventory mutation itself.** Its only direct writes are to
its own aggregate (invoice status/log, line landed-cost allocation). Quantity and ledger go
through `ReceiveStockAction`; layers and cost through `CreateReceiptLayersAction`.

Note for the record: the step log says *"financial posting only"* when the guard fires, but
there is **no AP/Finance posting in this flow at all** — no events dispatched, and no
supplier-invoice integration exists in `Modules/Finance`. The log line overstates what happens.

---

## 4. Goods Receipt Flow

`PostGoodsReceiptAction::execute($id)` — reached from `GoodsReceiptController::post()` via
`POST /api/goods-receipts/{id}/post`. It writes the ledger reference as `'goods_receipt'`,
which matches `InboundPostingGuard::REF_GOODS_RECEIPT` exactly, so the two documents' reference
*types* do collide correctly. The defect in B-1 is the **id**, not the type.

`purchase_order_lines.received_qty` is written in exactly one place — this action. A Mode 3
invoice inbound therefore raises on-hand while procurement's received quantity stays behind,
and that column is the baseline the over-receipt guard trusts (see §9).

---

## 5. Inventory Ownership

**Single authority: `ReceiveStockAction`.** Both paths call it; neither mutates `on_hand_qty`
itself. It locks the inventory row, computes before/after, saves, and records the ledger entry
in the same operation. No second inventory engine exists on the inbound path.

---

## 6. Stock Ledger Ownership

**Single authority: `ReceiveStockAction`**, via `EloquentInventoryItemRepository::recordEntry()`.
It writes `movement_type = purchase_receipt` together with the `reference_type`/`reference_id`
the calling document resolved. This is the row the guard later reads — the ledger is both the
audit trail and the idempotency store, which is why no new mechanism was needed.

The legacy `stock_movements` table is not written by either inbound path.

---

## 7. FIFO Ownership

**Canonical writer on the inbound path: `CreateReceiptLayersAction`.** Both documents use it;
neither creates a layer directly. (Six further writers exist elsewhere in the platform —
transfer, manual stock, count approval, manufacturing output, disassembly, customer-return
receiving — none of which is on the purchase-inbound path.)

Two facts that matter:

- `executeForLines()` inserts one row per active line **unconditionally** — no upsert, no
  existence check — and `inventory_receipt_layers` has **no unique index of any kind**.
- The guard reads `stock_ledger_entries`, not the layer table.

So the only thing preventing a duplicate FIFO layer for one physical inbound is the guard.
When the guard cannot fire (B-1), duplicate layers follow directly.

---

## 8. Cost Ownership

**Source of truth: `inventory_receipt_layers.landed_unit_cost`**, written once at layer
creation and **never updated** — every write of that column platform-wide is a row CREATE. It
is also the value COGS actually consumes. The cost contract is preserved by this task.

**Contract gap (reported, not resolved).** The two documents compute that number by different
formulas from different inputs:

| | Price input | Freight/additional | Tax |
|---|---|---|---|
| Goods Receipt | the **PO line's** `unit_price` (force-copied over any submitted value) | spread **flat per unit** | **included** |
| Supplier Invoice | its **own** user-entered `unit_price` | allocated **pro-rata by line value** | **excluded** |

There is **no price-difference contract anywhere** — no revaluation, variance, PPV or
reconciliation code exists in `backend/`, and there is no unpost/reversal for a posted receipt.
When the guard does fire, the invoice's recomputed landed cost is written to
`supplier_invoice_lines` and then discarded; inventory keeps the PO-derived price permanently.

Per PART 7 this is a **CONTRACT GAP**, not something to invent an accounting rule for.

---

## 9. Quantity Ownership

The receipt path has a clear authority: `net_received_quantity`, read through
`GoodsReceiptLine::effectiveReceivedQty()` (`net ?? received`), mirrored by every certified
Purchasing analytics query as `COALESCE(net_received_quantity, received_quantity)` on posted
receipts.

**The invoice path has no equivalent.** `supplier_invoice_lines` carries a single `quantity` —
the **billed** quantity — and it is stocked verbatim. The two paths therefore post structurally
different quantities for the same physical inbound, and nothing reconciles them.

Whether that divergence is intended policy (invoice = billed, receipt = weighed) or a defect is
**undetermined** and is a second contract gap.

---

## 10. Physical Inbound Identity — **BLOCKER B-1**

This is PART 6, and it is where the task stops.

`supplier_invoices.auto_receipt_id` is a real, nullable, non-unique FK to `goods_receipts`, and
the migration documents it as *"Auto-generated document references (populated after posting)"* —
i.e. Mode 3 was designed so that posting an invoice **generates** the purchase and receipt.

**That generation was never implemented, and the column has no writer.** Verified along seven
independent lines:

1. `StoreSupplierInvoiceRequest` is the **only** request class; it declares no
   `auto_receipt_id` rule.
2. `SupplierInvoiceController::store()`/`update()` mass-assign `$request->safe()`, which
   returns validated keys only — the field is stripped even if a client sends it deliberately.
3. Repo-wide grep finds the column only in: the migration, `$fillable`, the model relation, the
   read-only API resource, the two consumers, the compiled JS bundle, and **one test fixture**.
4. `PostSupplierInvoiceService` never creates a receipt nor sets the field — **and neither did
   the pristine `HEAD` version** (`git show HEAD:…`), so this predates all repair work here.
5. No route, job, command, seeder, factory or importer links an invoice to a receipt.
6. The frontend `CreateSupplierInvoicePayload` omits it; the UI renders it read-only.
7. The invoice's only other structural link, `auto_purchase_id` → `purchase_materials`, is
   equally unwritten. There is no `purchase_order_id` on `supplier_invoices` at all.

The only invoice↔receipt data production actually writes is
`goods_receipts.supplier_invoice_number` — an unvalidated free-text string that no code reads.

**Consequence.** `referenceForInvoice()` always takes the `[REF_SUPPLIER_INVOICE, $invoiceId]`
branch. A Goods Receipt and its Supplier Invoice post under different keys, neither guard sees
the other's row, and both post: doubled `on_hand_qty`, two `PurchaseReceipt` ledger rows, two
FIFO layers, two cost cascades. Nothing detects it — there is no unique constraint on
`(reference_type, reference_id)`.

There is also **no procurement-mode configuration anywhere** (`mode_3`, `procurement_mode`,
`inbound_mode` all return zero matches), so nothing makes the two paths mutually exclusive.
The system permits both concurrently with no linkage.

**This was reached independently by four of nine audit lenses and by direct tracing, each
flagging it a stop condition.**

### Why `test_c1`/`test_c2` are green

They set `auto_receipt_id` directly on the model, bypassing the controller — a state the
application cannot produce. They prove the guard works *when linked*; they do not prove the
link ever exists. Same false-green class as the `company_id` defect closed in
TASK-SUPPLIER-RETURN-VALUATION-001.

### Options for the owner — **not chosen here**

| | Option | Note |
|---|---|---|
| **a** | Implement Mode 3 auto-generation as the schema intends: posting an invoice creates the purchase + receipt and stores their ids | Matches the original design and the migration comment. Largest change |
| **b** | Expose the existing FK: accept `auto_receipt_id` on create, validated to the same company and supplier and not already linked | Smallest change, but **opt-in** — a user who omits it still double-posts, so it does **not** deliver PART 2's guarantee |
| **c** | Enforce mutual exclusivity via a procurement-mode setting so only one path may post inventory | Delivers the guarantee by construction; needs a new configuration contract |

PART 6 forbids inventing a matching heuristic, and no minimum repair achieves PART 2's
"MUST NEVER be posted twice". **This is a business decision.**

---

## 11. Idempotency

Per document, idempotency **passes** and is proven at both service and HTTP level:

- Repeated Goods Receipt post → no duplicate quantity, ledger row or layer.
- Repeated Supplier Invoice post → likewise.
- Receipt→Invoice and Invoice→Receipt **when linked** → single posting, second document
  completes as financial only.

Across documents **when unlinked**, idempotency does not hold — that is B-1.

---

## 12. Concurrency

`PostGoodsReceiptAction` evaluates its guard **before** the transaction opens and takes no lock
on the receipt, so two simultaneous posts of the same receipt are a check-then-act race; the
only serialising lock (the PO line) rejects over-receipt, not double posting. The invoice path
is incidentally safer because `$invoice->update([...])` is the first statement inside its
transaction and row-locks the invoice.

This is the same defect class closed in the Supplier Return approval path. It is **reported,
not repaired** — it is a distinct defect from the inbound-ownership contract and its repair
belongs with B-1's resolution, since both concern how the guard is consulted.

---

## 13. Tenant Isolation — **BLOCKER B-2**

The platform has a single canonical authority, `App\Core\Company\TenantOwnershipResolver`, but
it is enforced only by per-model global scopes that each model must opt into. Platform-wide
exactly **four** models do: `Order`, `Warehouse`, `Supplier`, `ShippingPricingRule`.

**No model on the inbound path has one** — not `GoodsReceipt`, `SupplierInvoice`,
`PurchaseOrder`, `InventoryItem`, `StockLedgerEntry` or `InventoryReceiptLayer`. No policy is
registered for `GoodsReceipt` or `SupplierInvoice`. `RequirePermissionMiddleware` checks a
permission **name** only and never consults a company. `GoodsReceiptController` takes raw string
ids (`post`, `show`, `destroy`) rather than route-model binding, so not even a scope would apply.

**Proven at runtime, not inferred.** Both endpoints were driven over HTTP by a Company A actor
holding the correct permission, against Company B documents:

| Endpoint | Status | Stock leaked | Denied by |
|---|---|---|---|
| `POST /goods-receipts/{id}/post` | **200** | **50.0 units into Company B's warehouse** | nothing — the post succeeded |
| `POST /supplier-invoices/{id}/post` | 422 | 0.0 units | a database constraint, **not** authorization |

The two differ in severity but share one root cause. On the receipt path company is resolved
purely from the document (`$po->company_id ?? $receipt->warehouse->company_id`) with no
comparison against the actor, so the write simply succeeds — **this is a genuine cross-tenant
data breach.** On the invoice path company resolves through `$invoice->warehouse?->company_id`,
and `Warehouse` *is* one of the four scoped models, so the lookup returns nothing, `company_id`
degrades to `''`, and the NOT NULL/FK constraint on `stock_ledger_entries` rejects the insert.

That distinction matters for the repair: **the invoice endpoint is protected by accident.**
Nothing authorizes the request; a schema change that made `company_id` resolvable — or any
future nullable-column relaxation — would open it silently. Neither endpoint returns 403/404,
so neither makes an authorization decision at all.

**Not repaired here.** The fix is tenant scoping across both modules, changing every
list/read/write endpoint they expose. PART 10 forbids inventing new tenant logic, and this is
its own task — the precedent is `SupplierTenantIsolationTest`, which exists because `Supplier`
and `Warehouse` had this same class of defect and each got a dedicated task.

The two tests are written to **pass once repaired** and to report `incomplete` with the exact
observed status and leaked quantity until then. They never assert the defect is correct.

---

## 14. API Verification

Both inbound endpoints live in `backend/routes/api.php`:

| Endpoint | Auth | Permission | Tenant |
|---|---|---|---|
| `POST /api/goods-receipts/{id}/post` | `auth:sanctum` | `purchasing.goods_receipts.update` | **none** |
| `POST /api/supplier-invoices/{id}/post` | `auth:sanctum` | `purchasing.supplier_invoices.post` | **none** |

Both sit in groups carrying `['auth:sanctum','throttle:120,1']`. **Authentication and permission
are sound and now proven over HTTP** (401 unauthenticated, 403 without the permission, for both
endpoints). Tenant is the failure — §13.

Before this task, exactly **zero** feature tests exercised these endpoints over HTTP.

---

## 15. UI Verification

Verification only; nothing redesigned, no mock data added.

**No stale endpoints.** All 30 frontend service calls across `supplier-invoices`,
`goods-receipts`/`receiving-center` and `purchase-materials`/`procurement-hub` resolve to live
routes, and the create/update payload shapes match their FormRequests field-for-field.

Recorded mismatches, none expanded into scope:

- Query params the frontend sends and the controllers never read: `record_type` (purchase
  materials), `supplier_id` and `payment_status` (goods receipts).
- The goods-receipt post mutation has **no error handler**, and the axios interceptor handles
  only 401 — so a 422 duplicate-post rejection produces no user-visible feedback.
- `CreateSupplierInvoicePayload` omits `auto_receipt_id`; the UI renders it read-only. Relevant
  to option (b) in §10.

---

## 16. Test Matrix (PART 16)

| TEST | Requirement | Evidence | Verdict |
|---|---|---|---|
| **1** | Goods Receipt → inventory once | `test_a…` (service) + `test_1…over_http` — on-hand 100, 1 ledger row, 1 layer | **PASS** |
| **2** | Supplier Invoice Mode 3 → inventory once | `test_b…` (service) + `test_2…over_http` — on-hand 40, 1 ledger row, 1 layer, status `Posted` | **PASS** |
| **3** | Repeated Goods Receipt → no duplicate | `test_e…` (service) + `test_3…over_http` | **PASS** |
| **4** | Repeated Supplier Invoice → no duplicate | `test_d…` (service) + `test_4…over_http` | **PASS** |
| **5** | Invoice → Receipt → no duplicate | `test_c2_linked_invoice_then_receipt…` | **PASS — only when linked** (see §10; the link is unreachable in production) |
| **6** | Receipt → Invoice → no duplicate | `test_c1_receipt_then_linked_invoice…` | **PASS — only when linked** (same caveat) |
| **7** | FIFO → exactly one layer | asserted in `test_1`/`test_2`/`test_3`/`test_4` over HTTP and in `test_a`/`test_b` | **PASS** per document |
| **8** | Stock Ledger → exactly one inbound posting | `inboundLedgerCount()` asserted in every posting test | **PASS** per document |
| **9** | Inventory quantity correct | on-hand asserted against the posted quantity throughout | **PASS** |
| **10** | Cost contract preserved | `test_i_cost_is_propagated_by_the_canonical_inbound` — layer `landed_unit_cost` 15.0, `last_purchase_cost` 15.0 | **PASS** |
| **11** | Supplier Return valuation still correct | `test_11…` — GR inbound at 12.5 → return of 8 valued 12.5/100.0, on-hand 20→12, the inbound layer consumed (1 layer, remaining 12) | **PASS** |
| **12** | Tenant isolation | `test_12` receipt → **200, 50 units leaked**; `test_12b` invoice → 422, 0 units (stopped by a DB constraint, not authorization) | **FAIL — blocker B-2**, reported `incomplete` |
| **13** | Permission / authentication | `test_13a`–`test_13d` — 401 unauthenticated and 403 without permission, on **both** endpoints, with no stock movement | **PASS** |
| **14** | Concurrent / retry | retry proven (TESTs 3, 4). True concurrency **NOT PROVEN**; §12 records the check-then-act race on the receipt path | **PARTIAL** |

Cross-document duplicate prevention — the task's central requirement — is **NOT PROVEN in a
production-reachable configuration**. TESTs 5 and 6 pass only against a fixture-created link.

---

## 17. Regression

**Certifying run — `tests/Feature/Purchasing`, under the T-6 advisory-lock gate:**

```
[GATE] busy (an ungated phpunit process is running) — queueing up to 3000s
[GATE] acquired ecos:testrunner:ecos_dev_test (connection 87958)

OK, but there were issues!
Tests: 133, Assertions: 370, Incomplete: 2.     Time: 07:03.442
```

**Zero failures.** The 2 incomplete are `test_12` and `test_12b`, the documented D-INB-01
tenant defect. Neither endpoint makes an authorization decision; only the receipt endpoint
actually leaks stock (§13).

Composition: 91 pre-existing Purchasing + 12 `InboundOwnershipContractTest` + 20
`SupplierReturnValuationTest` + 10 `InboundOwnershipHttpTest` = 133.

Both protected certifications remain green inside this run:

- **TASK-SUPPLIER-RETURN-VALUATION-001** — all 20 tests pass, plus the new end-to-end TEST 11.
- **TASK-PROCUREMENT-MYSQL-COMPATIBILITY-AND-DEMAND-TIMELINE-REPAIR-001** — its suites
  (`ProcurementQueryRuntimeRepairTest`, `SupplierProductDemandTest`) pass. Neither was modified
  or reopened. D-INB-06 is a separate latent Postgres-ism outside those suites' coverage.

---

## 18. Static Quality

| Tool | Scope | Result |
|---|---|---|
| PHPStan **L0** (`phpstan.neon.dist`) | `Modules/Purchasing`, `Modules/Inventory` | **PASS** — no errors |
| PHPStan **core L6** (`phpstan-core.neon.dist`) | `app/Core` + Contracts + Traits | **PASS** — no errors |
| Pint | `Modules/Purchasing`, `tests/Feature/Purchasing` | **PASS** — 178 files |

Frontend checks were not run: this task changed no frontend files.

---

## 19. Deployment Parity — **GAP**

| Container | Role | Inbound files |
|---|---|---|
| host (`C:\ecos-develop`) | source of truth | current |
| `ecos-dev-testrunner` | test runner | **matches host** |
| `ecos-dev-app` | this worktree's dev app | **STALE** |
| `ecos-app` | **another worktree** | not touched, by standing instruction |

`ecos-dev-app` is running **pre-repair** code: `InboundPostingGuard.php` is absent entirely, and
`PostGoodsReceiptAction`, `PostSupplierInvoiceService` and `CreateReceiptLayersAction` all differ
from host/testrunner (which match each other). The earlier inbound task deployed to the test
runner only.

**Not deployed in this task.** This task authorizes *determining* deployment ownership, not
deploying, and certification is being withheld. Flagged for the owner.

---

## 20. Defects Found

| ID | Defect | Severity | Status |
|---|---|---|---|
| **D-INB-01** | Cross-tenant inbound posting over HTTP — receipt endpoint posts 50 units into another company's warehouse (200); invoice endpoint blocked only by a DB constraint (§13) | **critical** | PRE-EXISTING · reported · own task |
| **D-INB-02** | Guard inert: `auto_receipt_id` never populated (§10) | **critical** | PRE-EXISTING · contract gap |
| **D-INB-03** | `PostGoodsReceiptAction` guard is check-then-act, no receipt lock (§12) | medium | PRE-EXISTING · reported |
| **D-INB-04** | Invoice stocks **billed** quantity; receipt stocks **net received**; nothing reconciles (§9) | medium | contract gap |
| **D-INB-05** | Invoice vs receipt landed-cost formulas diverge; no price-difference contract (§8) | medium | contract gap |
| **D-INB-06** | `CAST(… AS BIGINT)` in `EloquentPurchaseMaterialRepository:123` — **invalid MySQL**, on the purchase-material create path | high | PRE-EXISTING · reported · own task |
| **D-INB-07** | No unique index on `inventory_receipt_layers`; guard reads the ledger, not the layer table (§7) | low | reported |
| **D-INB-08** | GR post mutation has no error handler; 422 invisible to the user (§15) | low | reported |
| **D-INB-09** | Dropped query params: `record_type`, `supplier_id`, `payment_status` (§15) | low | reported |

**D-INB-06 was verified directly against the live engine**, not taken on report:
`SELECT CAST(SUBSTRING('PM-00042',4) AS BIGINT)` → `ERROR 1064`; `AS SIGNED` → `42`. It sits
adjacent to the certified MySQL-compatibility task, which is **not reopened** — recorded only.

---

## 21. Repairs Made

**None to production code.** Per the implementation rule, the existing architecture is correct
where it is reachable, and both blockers require contract decisions rather than repairs.

Tests added (the rule's *"if only tests are missing: add tests"*):

- `tests/Feature/Purchasing/InboundOwnershipHttpTest.php` — **new**, 11 tests. The HTTP surface
  PART 11 requires: authentication, permission, single-post, repeat-post and tenant boundary for
  both endpoints.
- `tests/Feature/Purchasing/InboundOwnershipContractTest.php` — **+1 test** (TEST 11): the full
  Goods Receipt → FIFO → Supplier Return chain, proving the certified return valuation still
  reads the inbound cost.

No test was weakened. No production behaviour was changed to make a test green.

---

## 22. Contract Gaps

| # | Gap | Decision required |
|---|---|---|
| **G-1** | **How does the system know an invoice and a receipt describe the same physical inbound?** | Choose (a) Mode 3 auto-generation, (b) an explicit user-supplied link, or (c) mutually exclusive procurement modes. §10 |
| **G-2** | **Which quantity is authoritative** when the invoice bills a different amount than was received? | Reconcile, or declare the divergence intended. §9 |
| **G-3** | **What happens when the invoice price differs from the PO/receipt price?** | Revaluation, variance account, or "inventory keeps the receipt price" stated explicitly. §8 |
| **G-4** | Does a posted Supplier Invoice have any **Finance/AP effect**? | The step log claims "financial posting only"; no such posting exists. §3 |

---

## 23. Final Verdict

**NOT CERTIFIED.**

**Exact blockers:**

1. **B-1 / D-INB-02** — the same physical inbound *can* post twice, because the guard's
   collision key (`supplier_invoices.auto_receipt_id`) is never populated by any production code
   path. Certification criterion *"Same physical inbound cannot double-post"* fails.
   Stop conditions **#2** and **#5**.
2. **B-2 / D-INB-01** — cross-tenant inbound posting, proven at 200 over HTTP. Certification
   criterion *"Tenant isolation passes"* fails.

**What IS proven and holds:**

Invoice inbound correct · Receipt inbound correct · single inventory posting authority
(`ReceiveStockAction`) · single ledger authority · single inbound FIFO authority
(`CreateReceiptLayersAction`) · cost contract preserved · per-document idempotency ·
authentication and permission correct on both endpoints · Supplier Return valuation still
certified · static quality green.

The guard is well-designed and correctly implemented. It is starved of its input.

**Neither blocker was introduced by this work, and neither can be closed without an owner
ruling.** No matching heuristic was invented, no second inventory or FIFO engine was created, no
tenant logic was invented, no certified contract was reopened, and MAIN was not touched.
