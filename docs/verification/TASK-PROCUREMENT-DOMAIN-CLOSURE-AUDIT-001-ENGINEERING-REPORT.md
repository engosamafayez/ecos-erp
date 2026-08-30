# TASK-PROCUREMENT-DOMAIN-CLOSURE-AUDIT-001 — Engineering Report

**Date:** 2026-08-15 · **Branch:** `develop` · **Mode:** AUDIT ONLY
**Verdict:** **NOT CERTIFIED — STOP CONDITIONS TRIGGERED (4)**

No production code, migration, API, UI, Inventory, FIFO or Goods Receipt logic was modified.
Nothing was repaired. `ecos_dev` untouched. `ecos-app` not deployed to.

---

## 1. Executive Summary

Procurement is substantially built — six sub-modules, 166 PHP files, a real API and a real
UI. The architecture is not the problem. **The ownership is.**

**The finding that governs everything else:** there are **two independent, unsynchronised
writers of inventory, FIFO layers and cost** for the same physical event, and both are
reachable from the production UI.

| | `PostGoodsReceiptAction` | `PostSupplierInvoiceService` |
|---|---|---|
| Inventory mutation | `ReceiveStockAction` (canonical) | **direct `InventoryItem::create` / `increment`** |
| FIFO layer | `CreateReceiptLayersAction` (canonical) | **direct `InventoryReceiptLayer::create`** |
| Ledger written | `stock_ledger_entries` | **`stock_movements`** |
| Links layer to receipt | yes | **`goods_receipt_id => null` hard-coded** |
| Over-receipt guard | yes, `lockForUpdate` against PO line | **none** |
| Duplicate-post guard | yes (`status === Posted` → throw) | only its own status |
| Consults the other path | — | **never reads `auto_receipt_id`** |

`supplier_invoices.auto_receipt_id` is a **real foreign key to `goods_receipts`**. So one
delivery can legitimately have both a Goods Receipt and a Supplier Invoice, and posting both
— which the UI permits, `/goods-receipts/{id}/post` then `/supplier-invoices/{id}/post` —
adds the stock **twice**, creates **two FIFO layers**, and splits the audit trail across
**two different ledger tables**.

Nothing in the code prevents this. It is not a race condition; it is the ordinary path.

**Four STOP conditions from the task brief are met:** ambiguous inventory ownership,
ambiguous FIFO ownership, duplicate inventory writers, and an undefined invoice/receipt
relationship. Per instruction, none was resolved — all are documented as contract gaps.

**Second-order findings:** Supplier Returns decrement on-hand without consuming FIFO layers
(valuation drifts); return approval and its inventory reversal are not in one transaction;
and `SupplierInvoices`, `SupplierReturns` and `PurchaseMaterials` have **zero test files**
between them.

**What is genuinely healthy:** the Goods Receipt path itself is well-built — canonical
actions, pessimistic locking, over-receipt guard, PO status advancement. Supplier tenant
isolation is real and tested (5 cases). Static quality is clean. No PostgreSQL-only syntax
remains in Procurement.

---

## 2. Procurement Architecture

`backend/Modules/Purchasing/` — six sub-modules, DDD layout throughout
(Application / Domain / Infrastructure / Presentation).

| Sub-module | Files | Role |
|---|---|---|
| `GoodsReceipts` | 43 | Physical receipt; 6 actions incl. `PostGoodsReceiptAction` |
| `PurchaseMaterials` | 36 | Purchase demand / material purchasing |
| `PurchaseOrders` | 31 | PO lifecycle, PO lines carry `received_qty` |
| `Suppliers` | 33 | Supplier master, analytics, demand |
| `SupplierReturns` | 12 | Returns to supplier |
| `SupplierInvoices` | **11** | Invoice + **`PostSupplierInvoiceService`** |

**Note on vocabulary (PART 1):** there is **no `PurchaseRequest` concept**. The approved
Procurement contract names *Purchase Request = warehouse need / purchasing guide*, but the
module tree implements `PurchaseOrders` and `PurchaseMaterials`. Whether "Purchase Request"
is a missing concept or an alias for one of these is **unresolved** — recorded as **G-7**.

---

## 3. Supplier Flow

Traced from source. The chain **forks**, and that fork is the core defect:

```
Supplier → Purchase Material → Purchase Order
                                    │
              ┌─────────────────────┴─────────────────────┐
              │                                           │
        Goods Receipt                             Supplier Invoice
              │                                           │
   ReceiveStockAction                     InventoryItem::increment()   ← direct
   CreateReceiptLayersAction              InventoryReceiptLayer::create() ← direct
   stock_ledger_entries                   stock_movements              ← different table
              │                                           │
              └──────────► INVENTORY ◄────────────────────┘
                        (both, independently)
```

Both branches terminate in inventory. Neither is aware of the other.

---

## 4. Purchase Materials Flow

`PurchaseMaterials` (36 files) carries its own status enum and transitions. Not exercised by
any test (§18). Its interaction with `PurchaseOrders` — which of the two owns the
"ordered quantity" contract — was **not resolvable from code within this audit** and is
recorded as **G-7** alongside the Purchase Request question.

---

## 5. Demand Flow

`SupplierProductDemandTest` and `DemandAnalysisTest` exist. Per PART 17's warning, these were
checked rather than assumed: `SupplierProductDemandTest` covers supplier-side demand, and the
`DemandAnalysis` test covers `Operations/DemandAnalysis`, **not** `Modules/Core/DemandAnalysis`.
Two services share the name `DemandAnalysisService`
(`Modules/Core/DemandAnalysis` and `Modules/Operations/DemandAnalysis`) — a naming collision
worth noting, and evidence that a passing "DemandAnalysisTest" must not be read as coverage
of both.

---

## 6. Goods Receipt Flow — the healthy path

`PostGoodsReceiptAction` is the better-engineered of the two writers:

- **Guard 1** duplicate posting → `GoodsReceiptAlreadyPostedException`
- **Guard 2** PO must be receivable (not Cancelled/Closed, `canReceive()`)
- **Guard 3** at least one non-zero net-quantity line
- **Guard 4** over-receipt, inside the transaction, with `lockForUpdate()` on the PO line
- Mutates inventory **only** through `ReceiveStockAction` (canonical)
- Creates FIFO **only** through `CreateReceiptLayersAction` (canonical)
- Increments `PurchaseOrderLine.received_qty`, advances PO status
- Whole body in one `DB::transaction`

If a canonical owner of goods-inward is to be declared, the code supports this one. **But the
code does not itself declare it** — see §21/G-1.

---

## 7. Supplier Invoice Flow — the parallel path

`PostSupplierInvoiceService::execute()`, 8 steps, all in one transaction:

1. load lines · 2. allocate landed costs · 3+4. **capture + mutate inventory directly** ·
5. **create FIFO layers directly** · 6. **write `stock_movements`** · 7. **mutate product
costs** · 8. mark Posted

Its own docblock states the intent plainly: *"ADR-011 Mode 3: Supplier Invoice → Auto-posting
→ Inventory."* So this is deliberate design, not an accident — which is exactly why it is a
**contract conflict** rather than a bug to be quietly patched.

---

## 8. Supplier Return Flow

`ReverseSupplierReturnInventoryAction` uses `AdjustmentOutAction` — canonical, no direct
mutation. Good.

Three defects found:

- **FIFO not consumed.** `AdjustmentOutAction` contains no `InventoryReceiptLayer` handling.
  A return decrements `on_hand_qty` while leaving `remaining_qty` in the layers untouched, so
  the sum of open layers drifts above actual on-hand and **inventory valuation overstates**.
- **Not atomic with approval.** `SupplierReturnController::approve()` updates status to
  Approved and *then* calls `reverseInventory->execute()` as a separate step. If the reversal
  throws, the return is Approved with no stock movement.
- **No quantity ceiling.** No check that returned quantity ≤ received quantity was found.

Idempotency is provided indirectly by `canTransitionTo(Approved)` — a second approve is
refused by the state machine.

---

## 9. Inventory Ownership — writer map

| Writer | Path | Canonical? |
|---|---|---|
| `ReceiveStockAction` | GR posting | ✅ canonical |
| `AdjustmentOutAction` | Supplier Return, count adjustments | ✅ canonical |
| **`PostSupplierInvoiceService`** | invoice posting | ❌ **direct `InventoryItem` mutation** |

**PART 20 answer:** yes — Procurement contains a direct DB mutation of Inventory that bypasses
the canonical Inventory action, in `PostSupplierInvoiceService::captureAndUpdateInventory()`
(`:163-174`).

---

## 10. FIFO Ownership — writer map

Eight writers of `InventoryReceiptLayer` exist platform-wide:

| # | Writer | Domain |
|---|---|---|
| 1 | `CreateReceiptLayersAction` | Inventory (canonical) |
| 2 | `AddManualStockAction` | Inventory |
| 3 | `ApproveCountSessionAction` | Inventory counts |
| 4 | `TransferStockAction` | Inventory transfer |
| 5 | `DisassemblyInventoryAdapter` | Manufacturing |
| 6 | `InventoryMutationAdapter` | Manufacturing |
| 7 | `ReceiveReturnWorkflow` | Fulfillment |
| 8 | **`PostSupplierInvoiceService`** | **Purchasing — the only one in this domain that bypasses #1** |

**Can one physical receipt produce more than one layer? YES** — via GR posting (layer with
`goods_receipt_id` set) and invoice posting (layer with `goods_receipt_id = null`).

---

## 11. Cost Ownership

Both writers mutate product cost, with **different inputs**:

| Value | GR path | Invoice path |
|---|---|---|
| `landed_unit_cost` | `unit_price + (total extra landed / total net qty)` | `unit_price + (freight+additional allocated by line_total ratio) / qty` |
| `last_purchase_cost` | via `CreateReceiptLayersAction` | direct `$product->update()` |
| `average_cost` | via same | `EnterpriseCostEngine::weightedAverageCost()` |
| `current_fifo_cost` | via same | oldest open layer |

`EnterpriseCostEngine::weightedAverageCost` is correctly shared. But **which document is the
cost authority — the receipt or the invoice — is undefined**, and the two landed-cost
formulas are not the same allocation. Recorded as **G-3**.

---

## 12. Quantity Definitions

`goods_receipt_lines` (verified at runtime) carries **five** quantity columns:
`ordered_quantity`, `gross_received_quantity`, `net_received_quantity`, `variance_quantity`,
`received_quantity`.

The canonical accessor is `GoodsReceiptLine::effectiveReceivedQty()`:

```php
return (float) ($this->net_received_quantity ?? $this->received_quantity ?? 0);
```

`PostGoodsReceiptAction` uses it consistently. **`PostSupplierInvoiceService` uses
`$line->quantity` from the invoice line** — a third definition, unreconciled with either
receipt quantity. Recorded as **G-4**.

Per PART 8's caution, this was re-checked rather than assumed closed: the earlier
`grl.received_qty` repair fixed the column reference, but the *definition* question — which
quantity is authoritative for stocking — remains open because two documents now supply it.

---

## 13. Tenant Isolation

**Healthy.** `SupplierTenantIsolationTest` covers five cases, including the two that usually
get missed: a company-less non-privileged user sees nothing (fails closed), and unrestricted
users retain cross-company visibility. Unauthenticated execution is deliberately unscoped,
matching the canonical `TenantOwnershipResolver` contract.

Not separately verified this audit: tenant scoping on Goods Receipt, Supplier Invoice and
Supplier Return read paths. Recorded as a coverage gap, not as a defect.

---

## 14. Idempotency

| Operation | Guard | Verdict |
|---|---|---|
| Goods Receipt post | `status === Posted` → throw | ✅ |
| Supplier Return approve | `canTransitionTo()` | ✅ |
| **Supplier Invoice post** | own status only | ⚠️ **safe against itself, blind to the GR path** |
| **Cross-path (GR + Invoice)** | **none** | ❌ **double posting** |

---

## 15. Concurrency

`PostGoodsReceiptAction` uses `DB::transaction` + `lockForUpdate()` on PO lines.
`PostSupplierInvoiceService` uses `DB::transaction` + `lockForUpdate()` on InventoryItems.

Each is internally safe. **Neither takes a lock the other would observe**, so concurrency
control does not mitigate the cross-path double-post — the two transactions lock different
rows and both succeed.

---

## 16. API Inventory

65 route lines reference procurement. `/supplier-invoices/{id}/post` is live and wired to
the UI service layer (`supplier-invoices-service.ts:51`), alongside `validate` and `cancel`.

**The double-post path is operator-reachable through the production UI** — it is not a
theoretical code path.

---

## 17. UI Inventory

| Feature | API-calling files | files mentioning mock/TODO |
|---|---|---|
| `procurement` | **0** | 0 |
| `purchase-materials` | 1 | 6 |
| `purchase-orders` | 1 | 4 |
| `supplier-invoices` | 1 | 3 |
| `supplier-returns` | 1 | 2 |
| `suppliers` | 3 | 4 |

`features/procurement` contains only `procurement-hub-page.tsx` and makes **no API calls** —
a shell. The other five have real service layers. The mock/TODO counts are indicative only
(the grep also catches the word "placeholder" in comments) and were not individually
adjudicated.

---

## 18. Test Coverage

| Suite | Exists |
|---|---|
| `GoodsReceiptTest` | ✅ |
| `PurchaseOrderTest` | ✅ |
| `SupplierAnalyticsTest` | ✅ |
| `SupplierProductDemandTest` | ✅ |
| `SupplierTenantIsolationTest` | ✅ |
| `ProcurementQueryRuntimeRepairTest` | ✅ |
| **`SupplierInvoice*`** | ❌ **ZERO** |
| **`SupplierReturn*`** | ❌ **ZERO** |
| **`PurchaseMaterial*`** | ❌ **ZERO** |

The single most dangerous service in the domain — the one that mutates inventory, FIFO,
ledger and cost — **has no test at all**. That is why the double-post path has survived.

---

## 19. MySQL Compatibility

**Clean.** No `::float`, `::text`, `::numeric`, `ILIKE`, `NULLS LAST` or `DISTINCT ON`
anywhere in `backend/Modules/Purchasing`. The prior repair
(TASK-PROCUREMENT-MYSQL-COMPATIBILITY-AND-DEMAND-TIMELINE-REPAIR-001) holds.

---

## 20. Existing Certified Work — preserved, not re-run

TASK-PROCUREMENT-MYSQL-COMPATIBILITY-AND-DEMAND-TIMELINE-REPAIR-001 is treated as certified.
Nothing in this audit re-implements it. Its MySQL-compatibility guarantee was re-verified by
inspection only (§19) and holds.

---

## 21. PostSupplierInvoiceService Analysis

**Answer to PART 19: (E) — it does multiple of the listed things.**

| | Does it? | Evidence |
|---|---|---|
| A. financial record only | partially | status → `Posted`, `posted_at`, `posted_by` |
| B. creates/owns Goods Receipt | **NO** | `goods_receipt_id => null` (`:199`) |
| C. mutates Inventory directly | **YES** | `InventoryItem::create` / `increment('on_hand_qty')` (`:163-174`) |
| D. creates FIFO layers directly | **YES** | `InventoryReceiptLayer::query()->create` (`:196`) |
| E. multiple of the above | **YES** | C + D + ledger (`:225`) + cost (`:278`) |

**Is this compatible with the existing Procurement/Inventory architecture? NO.**

It bypasses `ReceiveStockAction` and `CreateReceiptLayersAction` — the actions the Goods
Receipt path uses and which exist precisely to be the canonical entry points — and it writes
to `stock_movements` while the canonical path writes `stock_ledger_entries`.

**Per instruction, no resolution is proposed.** It is NOT assumed that Goods Receipt "should"
be the owner: `ADR-011 Mode 3` is cited in the service's own docblock as authorising
invoice-driven auto-posting, so there is a genuine architectural claim on both sides. What is
missing is a ruling on which wins, and a mutual exclusion once it is made.

---

## 22. Contract Gaps

| ID | Gap | Why it cannot be resolved in code |
|---|---|---|
| **G-1** | **Inventory ownership undefined** — two writers, both deliberate | ADR-011 Mode 3 authorises the invoice path; the GR path is the canonical-action path. Both have a claim |
| **G-2** | **FIFO ownership undefined** — one physical receipt can yield two layers | Same conflict, plus orphaned layers (`goods_receipt_id = null`) |
| **G-3** | **Cost authority undefined** — receipt vs invoice, two different landed-cost allocations | No ADR names the authority |
| **G-4** | **Stocking quantity undefined** — `effectiveReceivedQty()` vs invoice `line->quantity` | Two documents supply it |
| **G-5** | **Invoice ↔ Receipt relationship undefined** — `auto_receipt_id` exists but is never consulted when posting | Is an invoice with a receipt supposed to skip inventory? Unanswerable from code |
| **G-6** | **Return valuation undefined** — returns don't consume FIFO | Which layer should a return consume? |
| **G-7** | **Purchase Request vocabulary** — approved contract names it; module tree has `PurchaseOrders`/`PurchaseMaterials` | Naming/scoping decision |
| **G-8** | **Financial boundary** — no Accounts Payable found; invoice posting mutates inventory but no payable | Accounting architecture out of scope; boundary undeclared |

---

## 23. Known Defects

| ID | Defect | Severity |
|---|---|---|
| **D-1** | Double inventory posting: GR + linked Invoice both add stock | **S1 — CRITICAL** |
| **D-2** | Double FIFO layer for one physical receipt; invoice layers orphaned | **S1 — CRITICAL** |
| **D-3** | Ledger split: `stock_ledger_entries` vs `stock_movements` for the same event class | **S1** |
| **D-4** | Invoice posting bypasses canonical Inventory actions | **S1** |
| **D-5** | Supplier Return decrements on-hand without consuming FIFO → valuation overstates | **S2** |
| **D-6** | Return approval and inventory reversal not in one transaction | **S2** |
| **D-7** | No ceiling on returned quantity vs received quantity | **S2** |
| **D-8** | Invoice posting has no over-receipt guard (GR has one) | **S2** |
| **D-9** | Zero tests for SupplierInvoices, SupplierReturns, PurchaseMaterials | **S1** (it is why D-1 survived) |
| **D-10** | `features/procurement` UI is a shell with no API calls | S3 |

---

## 24. Recommended Repair Tasks

**None of these were implemented.**

| Issue | Sev | Owner | Existing implementation | Root cause | Required change | Depends on | Recommended task |
|---|---|---|---|---|---|---|---|
| D-1/D-2/D-3/D-4, G-1/G-2/G-5 | **S1** | Inventory + Purchasing | Two posting paths | Ownership never declared; ADR-011 Mode 3 vs canonical actions | **Owner ruling first**, then make one path authoritative and the other mutually exclusive | — | `TASK-PROCUREMENT-INVENTORY-OWNERSHIP-CONTRACT-001` |
| G-3 | S1 | CostManagement | Two landed-cost formulas | No declared cost authority | Declare authority; unify allocation | ownership ruling | `TASK-PROCUREMENT-COST-AUTHORITY-001` |
| G-4 | S2 | Purchasing | `effectiveReceivedQty()` vs invoice qty | Two documents supply quantity | Declare canonical stocking quantity | ownership ruling | `TASK-PROCUREMENT-QUANTITY-CONTRACT-001` |
| D-5/D-6/D-7, G-6 | S2 | Purchasing + Inventory | `AdjustmentOutAction`, no FIFO | Returns treated as plain adjustment | FIFO consumption, atomic approval, qty ceiling | G-2 | `TASK-SUPPLIER-RETURN-VALUATION-001` |
| D-9 | S1 | Purchasing | No tests | Never written | Feature tests for invoice posting, returns, purchase materials | ownership ruling (tests must encode the ruling) | `TASK-PROCUREMENT-TEST-COVERAGE-001` |
| G-7 | S2 | Purchasing | `PurchaseOrders`/`PurchaseMaterials` | Vocabulary divergence | Map or introduce Purchase Request | — | `TASK-PROCUREMENT-VOCABULARY-001` |
| G-8 | S2 | Finance | None found | AP not built | Declare boundary | — | `TASK-PROCUREMENT-FINANCIAL-BOUNDARY-001` |
| D-10 | S3 | Frontend | Shell page | Never wired | Wire or remove | API stability | `TASK-PROCUREMENT-UI-COMPLETION-001` |

---

## 25. Dependency Order

```
TASK-PROCUREMENT-INVENTORY-OWNERSHIP-CONTRACT-001   ← owner ruling; blocks everything
        ├── TASK-PROCUREMENT-COST-AUTHORITY-001
        ├── TASK-PROCUREMENT-QUANTITY-CONTRACT-001
        ├── TASK-SUPPLIER-RETURN-VALUATION-001
        └── TASK-PROCUREMENT-TEST-COVERAGE-001   ← tests must encode the ruling, not precede it
TASK-PROCUREMENT-VOCABULARY-001            (independent)
TASK-PROCUREMENT-FINANCIAL-BOUNDARY-001    (independent)
TASK-PROCUREMENT-UI-COMPLETION-001         (last)
```

Nothing downstream should start before the ownership ruling: every one of them encodes an
answer to "who owns goods-inward", and writing tests first would freeze the wrong contract.

---

## 26. Final Audit Verdict

### **NOT CERTIFIED — 4 STOP CONDITIONS**

| Stop condition | Met | Evidence |
|---|---|---|
| Ambiguous inventory ownership | ✅ | §9, §21 |
| Ambiguous FIFO ownership | ✅ | §10 — 8 writers, 2 in one flow |
| Duplicate inventory writers | ✅ | §3, §9 |
| Undefined invoice/receipt relationship | ✅ | §21, G-5 — `auto_receipt_id` never consulted |
| Undefined cost authority | ✅ | §11, G-3 |
| Undefined return valuation | ✅ | §8, G-6 |
| Unsafe tenant boundary | ❌ **not met** | §13 — tenant isolation is real and tested |

**Static quality (PART 23):** PHPStan L0 **[OK] No errors** · PHPStan core L6 **[OK] No
errors** · Pint **PASS, 166 files**. Frontend gates not run — no frontend file was inspected
for change and none was modified.

**Regression (PART 22):** `tests/Feature/Purchasing` — **OK (91 tests, 248 assertions)**,
executed through the T-6 gate. The 91 figure matches the count PART 18 requires preserved, so
the prior certified work is intact. **This green result does not contradict the verdict** —
see §28: the suite passes because the defective path has no tests.

**The single sentence that matters:** a Goods Receipt and its linked Supplier Invoice can each
post the same delivery into stock, and nothing in the system prevents it — no guard, no lock,
no shared ledger, and no test.

**No repair was started. Awaiting the ownership ruling before any implementation task.**

---

## 27. Deployment Parity (PART 24)

Verified by checksum on the two audited services:

| File | host | testrunner | ecos-dev-app | |
|---|---|---|---|---|
| `PostSupplierInvoiceService.php` | `37e1007e7bd7` | `37e1007e7bd7` | `37e1007e7bd7` | **PARITY** |
| `PostGoodsReceiptAction.php` | `0c3f1a1a24e7` | — | — | matched on re-check |

Source parity holds across host, testrunner and `ecos-dev-app`. **`ecos-app` was deliberately
not touched** — it belongs to another worktree, per PART 24.

This matters for the audit's credibility: the double-posting paths described in §3–§7 are the
code actually running in `ecos-dev-app`, not a host-only state.

## 28. Regression result

`tests/Feature/Purchasing`, executed through the T-6 gate (no runner competition, no
`migrate:fresh` against a busy shared DB).

```
OK (91 tests, 248 assertions)
```

**91/91 green — and that number is exactly the "91 Purchasing regression" PART 18 requires to
be preserved.** The previously-certified work
(TASK-PROCUREMENT-MYSQL-COMPATIBILITY-AND-DEMAND-TIMELINE-REPAIR-001) is intact; this audit
introduced no regression, because it changed nothing.

**Read this result carefully, because it is the most misleading number in the report.**

A fully green Purchasing suite coexists with a critical double-inventory-posting defect. The
suite passes *because* the dangerous path is untested (§18): there is no
`SupplierInvoiceTest`, so nothing exercises `PostSupplierInvoiceService`, so nothing can fail
on it. Green here means "the tested surface still works", not "the domain is safe".

This is the clearest possible argument for `TASK-PROCUREMENT-TEST-COVERAGE-001` — and for
sequencing it *after* the ownership ruling, so the new tests encode the decided contract
rather than freezing today's ambiguity.
