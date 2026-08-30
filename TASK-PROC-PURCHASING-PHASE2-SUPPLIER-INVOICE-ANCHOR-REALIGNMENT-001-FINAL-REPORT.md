# TASK-PROC-PURCHASING-PHASE2-SUPPLIER-INVOICE-ANCHOR-REALIGNMENT-001 — FINAL REPORT

**Date:** 2026-08-21 · **Scope:** Phase A + Phase B only
**Verdict:** **PHASE A + B — COMPLETE** (see §17)
**Commit:** none · **Migration:** none · **Business data changed:** none

---

## 1. Phase A implementation — supplier identity

Two call sites resolved the anchor's supplier from the purchase order alone. Each now consults the
legacy authority first and the Purchase Material line second — the same shape as the company
fallback that already sat one guard above in the same method.

**`InvoiceReceiptAnchorService::resolve()`**

```php
// before
$anchorSupplier = (string) ($receipt->purchaseOrder?->supplier_id ?? '');

// after
$anchorSupplier = (string) (
    $receipt->purchaseOrder?->supplier_id
    ?? $anchor->purchaseMaterialLine?->supplier_id
    ?? ''
);
```

**`ApproveSupplierReturnAction::resolveReceiptLine()`**

```php
// before
$lineSupplierId = (string) ($line->goodsReceipt?->purchaseOrder?->supplier_id ?? '');

// after
$lineSupplierId = (string) (
    $line->goodsReceipt?->purchaseOrder?->supplier_id
    ?? $line->purchaseMaterialLine?->supplier_id
    ?? ''
);
```

Both eager-loads gained `purchaseMaterialLine` so the fallback costs no extra query per line.

## 2. Phase B implementation — receipt-line anchor

**`StoreSupplierInvoiceRequest`** — the anchor became a declared, tenant-scoped field:

```php
'lines.*.goods_receipt_line_id' => [
    'nullable',
    'uuid',
    Rule::exists('goods_receipt_lines', 'id')->where(
        fn ($q) => $q->whereIn(
            'goods_receipt_id',
            DB::table('goods_receipts')->select('id')->where('company_id', $this->actorCompanyId()),
        ),
    ),
],
```

with the `actorCompanyId()` helper copied verbatim in shape from `StoreGoodsReceiptRequest`.

**`SupplierInvoiceController`** — line synchronisation now takes the validated set:

```php
// before (both store and update)
$this->syncLines($invoice, $request->input('lines'));
// after
$this->syncLines($invoice, $request->validated('lines'));
```

This is the half that actually closes the hole. The rule alone would have been decorative: `syncLines()`
mass-assigns into a fillable model, so raw input reached columns regardless of the rules. Both `store`
and `update` bind the same request class, so both are covered.

## 3. Exact files changed

| File | Change |
|---|---|
| `Modules/Purchasing/SupplierInvoices/Domain/Services/InvoiceReceiptAnchorService.php` | supplier fallback + eager-load |
| `Modules/Purchasing/SupplierReturns/Application/Actions/ApproveSupplierReturnAction.php` | supplier fallback + eager-load |
| `Modules/Purchasing/SupplierInvoices/Presentation/Http/Requests/StoreSupplierInvoiceRequest.php` | `actorCompanyId()` + scoped anchor rule + 2 imports |
| `Modules/Purchasing/SupplierInvoices/Presentation/Http/Controllers/SupplierInvoiceController.php` | `input('lines')` → `validated('lines')` ×2 + docblock |
| `tests/Feature/Purchasing/SupplierInvoiceAnchorRealignmentTest.php` | **new** — 11 focused tests |

Nothing else was touched. No refactor, no rename, no new abstraction.

**A caution on reading `git diff` here.** This working tree carries many uncommitted changes from
earlier tasks, and two of the files above are untracked relative to HEAD, so a raw diff shows far
more than this task did. The five entries in the table are the complete change set; each was verified
individually by grep after editing.

## 4. Exact behaviour changed

| Situation | Before | After |
|---|---|---|
| Invoice line anchored to a **PM-anchored** receipt line | always `supplierMismatch` — a Purchase Material could never be invoiced | resolves via `purchase_material_lines.supplier_id` |
| Return line anchored to a **PM-anchored** receipt line | always refused | resolves via the same authority |
| Invoice line anchored to a **PO-anchored** receipt line | resolves via PO | **unchanged** — PO is still consulted first |
| Anchor whose supplier cannot be resolved | refused | **still refused** — the fallback yields `''` and the same guard fires |
| `goods_receipt_line_id` in the request | undeclared; persisted from raw input with no checks | declared, `uuid`, tenant-scoped `exists` |
| Any undeclared line key | mass-assigned if fillable | dropped — only validated keys reach a column |

## 5. Legacy PO behaviour preserved

The purchase order is still consulted **first**; the fallback is only reached when there is no
purchase order to ask. Nothing in the PO path was edited.

The proof is the pre-existing `InvoiceReceiptAnchorTest` — 15 tests, entirely PO-anchored, covering
valid resolution, missing anchor, cross-company, cross-supplier, cross-product, quantity ceiling,
double-clearing, draft reservation, variance in three directions, partial receipt, multi-receipt
determinism, and the FIFO non-mutation invariant. **It was deliberately left untouched and re-run
unchanged** (§9), so it functions as the regression proof rather than as a test edited to fit.

## 6. PM supplier resolution

`purchase_material_lines.supplier_id` — the certified RD-1 authority — reached through
`GoodsReceiptLine::purchaseMaterialLine()`, which already existed. No new column, no new
relationship, no second supplier source of truth, and nothing inferred: a PM line with no supplier
still resolves to `''` and is still refused.

## 7. `goods_receipt_line_id` contract

**Optional at the request boundary, mandatory at posting where the mode demands it.**

`nullable` because a Mode 3 invoice *is* the inbound and anchors nothing; forcing it here would
refuse Mode 3 invoices and change a certified contract. Where the anchor is genuinely required —
Mode 1, to clear GRNI at the valuation the receipt committed — `InvoiceReceiptAnchorService` enforces
it at posting time, which is the only layer that knows the company's goods-inward mode.

Rejected: non-existent line · line owned by another company · non-UUID · any undeclared sibling key.

## 8. Tenant-scope validation

The receipt line must belong to a Goods Receipt owned by the actor's company, expressed as a
`Rule::exists(...)->where(...)` over `goods_receipts.company_id` — the same construction already used
for `purchase_material_line_id`, whose own comment records that a bare global `exists:` is exactly how
the legacy `purchase_order_line_id` became a cross-tenant edge.

**Why this is security-critical, concretely:** without the scope, Company A could anchor an invoice
line to Company B's receipt line, and B's `landed_unit_cost` would be read back to A through the GRNI
and PPV legs at posting — a cross-tenant cost disclosure.

**Fails closed.** A null actor company matches no row, so the anchor is refused rather than waved
through. The failure is Laravel's generic invalid-selection message, so a foreign id is not
distinguishable from a non-existent one — no existence oracle beyond the current security contract.

## 9. Tests executed

New suite `SupplierInvoiceAnchorRealignmentTest` (11 tests) plus the untouched
`InvoiceReceiptAnchorTest` (15 tests) as the legacy guard.

```
tests/Feature/Purchasing/SupplierInvoiceAnchorRealignmentTest.php   (11, new)
tests/Feature/Purchasing/InvoiceReceiptAnchorTest.php               (15, unedited)

.......................... 26 / 26 (100%)
OK (26 tests, 60 assertions)
```

| # | Required behaviour | Covered by | Result |
|---|---|---|---|
| 1 | PM receipt resolves supplier from the PM line | `test_a` | **PASS** |
| 2 | Legacy PO receipt still resolves from PO | `InvoiceReceiptAnchorTest` (15, unedited) | **PASS** |
| 3 | Missing PM supplier remains refused | `test_c1` + `test_c2` | **PASS** |
| 4 | Mixed supplier anchors remain refused | `test_d` + existing `test_d` | **PASS** |
| 5 | Tenant scope remains enforced | `test_e` + existing `test_c` | **PASS** |
| 6 | Valid `goods_receipt_line_id` accepted | `test_f` | **PASS** |
| 7 | Invalid `goods_receipt_line_id` rejected | `test_g` | **PASS** |
| 8 | Cross-tenant `goods_receipt_line_id` rejected | `test_h` | **PASS** |
| 9 | Existing anchor validation intact | `InvoiceReceiptAnchorTest` (unedited) | **PASS** |
| 10 | No Purchase Order required for the PM invoice path | `test_b` | **PASS** |

Two extra cases guard the contract edges: `test_i` (an unanchored invoice is still accepted — the
Mode 3 path) and `test_j` (an undeclared fillable key such as `landed_unit_cost` can no longer reach
a column).

Receipts in the new suite are built through the real `CreateGoodsReceiptAction` +
`PostGoodsReceiptAction`, so `purchase_order_id` is genuinely null and `landed_unit_cost` is genuinely
stamped — not a hand-built fixture that could pass while the production path fails.

### A finding from the first run — the guard is stronger than assumed

The first gate returned **24/25 with one error**, and the error was in my test, not in the code.
`test_c` tried to receive against a Purchase Material line with no supplier and then assert the
anchor refused it. It never reached the anchor: `CreateGoodsReceiptAction:243` already throws
`PurchaseMaterialReceivingException` — *"Purchase material line […] has no supplier selected. Select
a supplier for the line before receiving against it."*

So the state my test tried to construct is **unreachable through the production path**: a posted
PM-anchored receipt line whose Purchase Material line has no supplier cannot exist. The guarantee is
one layer earlier and stronger than the test assumed.

Rather than delete the case and leave required behaviour #3 unproven, it was split to prove what is
actually true:

- **`test_c1`** — receiving refuses a supplier-less PM line outright (the earlier guard).
- **`test_c2`** — the anchor's fail-closed branch still refuses when the supplier has gone missing
  after the fact. Reaching that branch requires writing the column directly *precisely because*
  `test_c1` shows the production path forbids it, which is stated in the test itself.

Defence in depth, both layers pinned. No production code was changed in response — the failure was a
wrong assumption in a test, and it was corrected rather than accommodated.

**RESULT: 26/26 PASS, 60 assertions, zero failures.**

No full ERP regression. No fabricated suppliers, purchases, receipts, or invoices in the live
database; all test data is `RefreshDatabase`-scoped to the test schema.

## 10. Static gates

| Gate | Scope | Result |
|---|---|---|
| `php -l` | all 5 touched files | **PASS** — no syntax errors |
| Pint | all 5 touched files | **PASS** — 4 files + 1 file |
| PHPStan L0 | 4 touched source files | **PASS** — `[OK] No errors` |
| Frontend gates | — | **not run** — no frontend file was touched |

## 11. Business-data side effects — none

Verified against the live database after implementation:

| Measure | Value | Expected |
|---|---|---|
| `goods_receipts` / `goods_receipt_lines` | 1 / 1 | unchanged |
| GR-00001 `landed_unit_cost` | **0.0000** | unchanged — deliberately not corrected |
| FIFO layer (40 units) | 40.0000 @ 0.0000 | unchanged |
| `inventory_receipt_layers` | 3 | unchanged |
| `stock_ledger_entries` | 24 | unchanged |
| `finance_supplier_bills` | 0 | unchanged |
| `finance_supplier_ledger_entries` | 0 | unchanged |
| `finance_supplier_payments` | 0 | unchanged |
| `users` | 1 | unchanged — no user created |
| PM-00002 `agreed_price` | **NULL** | unchanged — deliberately not corrected |
| `purchase_orders` | 0 | unchanged |

**One reading corrected during verification.** `supplier_invoices` shows **1**, and my initial check
annotated that as "expect 0". That expectation was my own assumption, not a measured baseline — I had
never counted the table before this task. Inspection settles it: `INV-202608-0001` was created
**2026-08-20 17:12:46**, roughly ten hours before this task began, for a different supplier
(`01a0180f…`, not PM-00002's `01a020ee…`), with a NULL anchor. It is pre-existing data and nothing in
this task created or modified it.

*(Incidental observation, not acted on: that pre-existing invoice is `validated` with a NULL anchor,
so under Mode 1 it would fail to post for want of an anchor. Out of scope.)*

## 12. Schema / migration status

**No migration created. None required.** Every column used already exists:
`supplier_invoice_lines.goods_receipt_line_id`, `goods_receipt_lines.purchase_material_line_id`,
`purchase_material_lines.supplier_id`, `goods_receipts.company_id`. No schema change, no new
permission, no new endpoint — the existing `POST /api/supplier-invoices` contract was extended, not
replaced.

## 13. Phase C boundary — respected

Not implemented, not started: Supplier Invoice **posting** · Accounts Payable / `SupplierBill`
creation · Supplier Ledger entries · financial journals · payment creation · payment approval ·
payment UI · any change to Maker/Approver controls.

`PostSupplierInvoiceService` was **read** during implementation and **not modified**.

## 14. Analytics boundary — respected

No analytics file touched. The `INNER JOIN purchase_orders` sites remain **GO-LIVE FOLLOW-UP**:
`GetSupplierAnalyticsQuery`, `GetProcurementHealthQuery`, `DemandAnalysisService`,
`GetSupplierTimelineQuery`, `EloquentSupplierRepository`. `ExpectedIncomingQuery` remains PO-native
and untouched. No Supplier 360 balance work.

## 15. Supplier Returns boundary — respected

`ApproveSupplierReturnAction` was touched **only** to align supplier resolution with D1 — a single
expression plus one eager-load. Financial behaviour is untouched: no credit notes, no supplier ledger
credits, no change to `credit_method` semantics. Returns remain financially inert, which stays a
separate go-live decision.

## 16. Remaining blockers

| ID | Blocker | Status |
|---|---|---|
| **D-1** | PM-00002 `agreed_price` is NULL — the remaining 60 units must not be received until it is set | **ACTIVE** — STOP condition 8; operator action, not engineering |
| **B-3** | No supplier-payment UI — `finance-ap-service.ts` is GET-only | open, Phase D |
| **SoD** | Only 1 user exists; `approverCannotBeMaker()` is an identity check a system role cannot bypass | open, Phase D — requires an authorized second approver |
| **GR-00001** | 40 units capitalised at 0.00, no GRNI accrued | accepted as historical anomaly; correction needs separate authorization |
| **Analytics** | 14 PO-join sites exclude PM receipts | go-live follow-up |
| **Returns** | financially inert (no credit note / ledger credit) | separate go-live decision |

Phases A and B remove the two blockers that were in their scope: **B-1** (anchor supplier from PO
only) and **B-2** (no validated anchor field).

## 17. Final verdict

# PHASE A + B — COMPLETE

| Criterion | Result |
|---|---|
| Phase A implemented | **PASS** |
| Phase B implemented | **PASS** |
| Legacy PO behaviour preserved | **PASS** — PO consulted first; its suite unedited and re-run |
| Tenant scope enforced and fails closed | **PASS** |
| Focused tests | **PASS** — 26/26, 60 assertions |
| Static gates | **PASS** — php -l, Pint, PHPStan L0 |
| Migration / schema / permission / endpoint | **none** |
| Business data changed | **none** |
| Phase C started | **no** |

**Phase 2 is NOT complete. Purchasing is NOT certified.** Phase C (Supplier Invoice posting →
Accounts Payable) remains explicitly pending and requires separate authorization, as does Phase D
(Payment), which additionally needs a second financial approver.
