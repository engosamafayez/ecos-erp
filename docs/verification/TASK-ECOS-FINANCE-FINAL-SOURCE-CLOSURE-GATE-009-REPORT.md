# TASK-ECOS-FINANCE-FINAL-SOURCE-CLOSURE-GATE-009 — Engineering Report

**ECOS Finance — Final Source Closure Gate**

Workstream: ECOS ERP — Full Accounting Closure · Master plan position: Task 9 of 9 (final)

---

## 1. Final status

## **COMPLETE**

Per §45's policy: all Finance-owned Tasks 1-8 are reconciled against their own reports and later CTO rulings; canonical accounting authority is confirmed coherent with no duplicate mutable financial truth found anywhere; every inventory (routes, permissions, migrations, tests) was recounted directly from source, not copied from prior reports, and two counting discrepancies were found and corrected (§28, §20); one initially-alarming permission finding was investigated to a conclusive, non-blocking resolution rather than either ignored or used to force a false STOP (§18); every external dependency is explicitly registered, not closed by inference; the first-device verification package (migration plan, test plan, browser plan) is complete; the Task 9 report is committed; the tree is clean; the Finance git bundle is created and independently verified in an isolated scratch repository.

## 2. Starting HEAD

`950a2817dda98b3700ab77c7dd9b626b35983182` — confirmed clean, on `task/finance-gap-closure`, before any action (§1 gate, reproduced in full in §37).

## 3. Task 9 documentation commit

Recorded in §37/§44 after the commit is created.

## 4. Final Finance candidate

Recorded in §39/§44 — the exact HEAD after the Task 9 documentation commit, frozen per §39's own instruction.

---

## 5. Tasks 1-8 reconciliation

| Task | Subject | Status per its own report | Superseding CTO ruling | Reconciled here |
|---|---|---|---|---|
| 1 | Architecture Lock (Reversal/Idempotency ADR) | Architecture decision only, no code | — | Confirmed: the contra-allocation + command-idempotency architecture it approved is exactly what Tasks 2-3 built; no drift found. |
| 2 | Transaction Safety Foundation | COMPLETE (source; tests written, not executed) | — | Confirmed unmodified since. |
| 3 | AP/AR & GL Wiring | COMPLETE | — | Confirmed unmodified since. |
| 4 | Foundation Gate | PASS | — | Its own test-count correction (27/33) re-confirmed accurate by this gate's fresh recount (§20). |
| 5 | Full Accounting Reconciliation | COMPLETE | — | GL↔subledger reversal fix confirmed still the sole reversal-plus-ledger-entry mechanism; Void = LEGACY/RETIRE unchanged. |
| 6 | Commercial Accounting | COMPLETE | — | Revenue/COGS/COD wiring confirmed unmodified; Instapay/Brand dependencies confirmed still open. |
| 7 | Operational Cost Accounting | COMPLETE, later CTO-ruled | **Warehouse→Brand = management attribution, no GL transfer** (superseding Task 7's own "BLOCKED pending policy") | This ruling is authoritative and confirmed still correctly reflected in source: no Warehouse→Brand GL journal exists anywhere (§8/§11). |
| 8 | Finance UX & Reporting Closure | COMPLETE, later CTO-ruled | Brand UX (raw reference display) and CustomerReceipt.source_type exposure both **ACCEPTED** | Both confirmed still correctly implemented, unchanged (§12). |

No historical report was rewritten. Two arithmetic corrections are recorded fresh in this report (§20, and the running frontend/backend test-count reconciliation) exactly as Task 4 once corrected Task 2/3 — the same standing convention, not a new one.

## 6. Canonical Finance authority

**CONFIRMED — `Modules\Finance` remains the sole canonical accounting authority.** No parallel mutable financial-truth engine was found for any of the ten capabilities this gate was asked to check:

| Capability | Sole authority | Duplicate found? |
|---|---|---|
| General Ledger | `JournalEngine` | No |
| Accounts Receivable | `AccountsReceivableService` / `CustomerLedgerEntry` | No — `Modules\Purchasing`/Commerce hold no parallel AR balance |
| Accounts Payable | `AccountsPayableService` / `SupplierLedgerEntry` | No |
| Revenue recognition | `CommercialAccountingService::recognizeRevenue()` | No |
| COGS | `CommercialAccountingService::recognizeCogs()` via the existing F3 posting-rule bridge | No |
| Expenses | `ExpenseService` / `Expense` | No |
| Driver financial subledger | `DriverFinanceService` / `DriverLedgerEntry` | No |
| Finance Cost Allocation | `CostAllocationService` / `CostAllocation` (deliberately distinct from `AllocationEngine`) | No |
| Fiscal periods | `FiscalCalendarService` / `PeriodClosingService` | No |
| Finance reporting | `TrialBalanceService`, `FinancialStatementService`, `ProfitabilityService`, etc. | No |

No STOP condition triggered under §3.

---

## 7. GL closure

`JournalEngine` remains the sole GL writer (confirmed unmodified across all 8 tasks by direct inspection this gate, not re-derived). Posting idempotency: `PostingCoordinator` (event-level, `finance_posted_event_receipts`) and `CommandIdempotencyGuard` (command-level, `finance_command_receipts`) — two distinct, deliberate mechanisms, neither duplicated. Reversal: `JournalEngine::reverse()` is the one canonical correction path, gated by `assertNoActiveSubledgerAllocations()` (the allocation-reversal guard, Task 3) and `assertOpenPeriod()` (unchanged since F1). Source references (`source_module`/`source_event_id`) and dimensions (`company_id`/`branch_id`/`cost_center_id`/`profit_center_id`, the last wired end-to-end in Task 6) confirmed consistent. **GENERIC DELETE: NO** (confirmed — no route, controller, or frontend action anywhere deletes a posted journal). **VOID: LEGACY/RETIRE** (Task 5's classification, unchanged — still fully unreachable, still harmless, still not removed). **REVERSAL: the canonical correction path**, confirmed as the only one, used identically by Journal, Payment, Receipt, Expense, and (structurally, not via `JournalEngine`) Cost Allocation.

## 8. AR closure

`CustomerInvoice`/`CustomerReceipt`/`CustomerLedgerEntry` confirmed as the sole AR authority. Allocation (`allocate`), auto-allocation (`autoAllocate`), contra-allocation reversal (`reverseAllocation`), posting reversal (`reverseReceiptPosting`), write-off, effective balance (derived, never stored), AR Aging, Customer Statement, and COD collection settlement (`recognizeCodCollection`, Task 6) all confirmed present and unchanged since their respective tasks. No Commerce-owned duplicate AR balance exists — confirmed by this gate's own fresh check (§6) and consistent with every prior task's own finding.

## 9. AP closure

Exact mirror of §8 for Supplier Bills/Payments/`SupplierLedgerEntry`. Supplier Opening Balance (`SupplierOpeningBalanceService`) confirmed still the sole authority, not reimplemented — and this gate additionally confirmed (§18, a finding worth surfacing precisely) that its own HTTP entry point (`POST suppliers/{supplier}/opening-balance`, owned by `Modules\Purchasing`, calling into this same Finance service) is correctly permission-gated via `finance.ap.opening.post` — a permission seeded not by a Finance migration but by a pre-existing `config/permissions.php` + `RbacSeeder` catalogue mechanism from `TASK-PROC-SUPPLIER-OPENING-BALANCE-001`, predating this entire workstream. This was investigated in depth (a dedicated Explore agent traced the full `RequirePermissionMiddleware` → `AuthorizationGateway` → `PermissionService` chain) before being ruled non-blocking — see §18 for the full finding and why it does not constitute a defect.

---

## 10. Commercial accounting

Reconciled against Task 6, unchanged. Order Delivery → Revenue/AR via `recognizeRevenue()` (creates a real `CustomerInvoice`, posts through the unchanged `AccountsReceivableService`); Order Delivery → COGS via `recognizeCogs()` (the existing F3 `shipping.delivery_confirmation` rule, reused, not re-created). COD collection remains a **separate** event (`CodCollected` → `recognizeCodCollection()` → `CustomerReceipt` funded by the `cod_clearing` role, "Cash in Transit") — confirmed, by direct inspection of `PostRevenueAndCogsOnOrderDelivered` and `PostCodCollectionOnCodCollected`, that Delivered is never conflated with cash collected; the two listeners are entirely independent and neither calls the other. **Kept open, unchanged**: Instapay approved-payment contract (external, Commerce/Orders) and commercial Brand value (external, Commerce/Order event contract) — neither is touched by any file in this workstream, confirmed by `git log` showing zero commits outside `backend/Modules/Finance`, `backend/routes/api.php`, `frontend/src/features/finance`, and the small shared frontend config/i18n/router files Task 8 touched.

## 11. Operational cost accounting

Reconciled against Task 7 plus its own later CTO ruling. **Expense accounting: CLOSED** (maker→checker→poster→reversal, `ExpenseService`, unchanged since Task 7 except the additive `show()` Task 8 added — a read method, no business-logic change). **Finance Cost Allocation: CLOSED**, confirmed still management-dimension-only (no `journal_entry_id` column exists on `finance_cost_allocations` at all — the table was designed from the start to be structurally incapable of a second GL posting, confirmed by re-reading its migration this gate). **Fleet/Vehicle cost: CLOSED** (`PostFleetCostOnVehicleCostPosted`, reusing the existing `shipping.shipment_cost` rule, unchanged). **Driver financial subledger: IMPLEMENTED** (receiving contract complete; upstream triggers remain external, unchanged, §29). **Warehouse→Brand**: confirmed, by direct inspection, that no code path anywhere creates a "Dr Brand pending-inventory / Cr Warehouse inventory-out" journal or any similar per-warehouse GL reclassification — the CTO ruling (management attribution via the existing Cost Allocation engine, not a statutory transfer) is faithfully the only mechanism in source. No duplicate GL posting is created by Cost Allocation (confirmed — it has no `JournalEngine` dependency injected anywhere in `CostAllocationService`).

## 12. Expenses

See §11. Segregation of duties (§10 of this task) reconciled precisely: `ExpenseController::approve()`/`post()`/`reversePosting()` are each independently permission-gated server-side (`finance.expense.approve`), and the frontend (`expense-detail-drawer.tsx`) only ever *reflects* that gate (`usePermission().can(...)`) — it does not implement or duplicate the authorization decision. No frontend-only approval path exists; every mutating action is a real HTTP call to the backend, which re-checks the permission and the state machine (`draft→approved→posted`) independently of what the UI shows.

## 13. Cost Allocation

See §11. Confirmed distinct from `AllocationEngine` (AP/AR) at every level: different namespace (`Modules\Finance\CostAllocation` vs `Modules\Finance\Allocation`), different table (`finance_cost_allocations` vs `finance_payment_allocations`/`finance_receipt_allocations`), different model, different service, never imported by each other. Fixed-amount/percentage behavior, append-only history (model-level `updating()`/`deleting()` hooks unconditionally return `false`), and contra/reversal correction (a new negative-amount row referencing `reverses_allocation_id`) all confirmed unchanged since Task 7/8.

## 14. Driver finance

See §11/§9 (of the original task numbering — the Driver Financial Boundary). Confirmed by direct inspection: **no code path anywhere converts a raw waste/damage report into a `DriverLedgerEntry`.** `DriverFinanceService::recognizeDriverShortage()` remains a pure receiving contract for an *already-approved* amount; nothing in this codebase (backend or frontend) calls it automatically from any waste/damage event. The Driver Ledger frontend (`driver-ledger-tab.tsx`) is read-only, confirmed to contain no create/edit action. Open dependencies (Driver Advance source event; Driver approved Shortage source event/routing) are unchanged, not closed by inference, and Shipping/Logistics/Operations were confirmed untouched by this entire workstream (`git log --all -- backend/Modules/Logistics backend/Modules/Operations` from this branch's own commits returns nothing).

## 15. Finance UX source closure

| Area | Classification |
|---|---|
| Overview | EXISTING / PRESERVED |
| General Ledger | EXISTING / PRESERVED (reversal UX already correct — Task 8's own finding, reconfirmed) |
| Receivables | WIRED (allocation + reversal actions added Task 8) |
| Payables | WIRED |
| Cash & Banking | EXISTING / PRESERVED |
| Expenses | IMPLEMENTED |
| Costing & Profitability | IMPLEMENTED |
| Budgets & Forecasts | EXISTING / PRESERVED (Budget/Budget-vs-Actual); the standalone Forecast/Trends/Risk sub-surface remains an explicit, documented, non-blocking gap (Task 8's own §35, unchanged) |
| Financial Reports | EXISTING / PRESERVED |
| Closing & Control | EXISTING / PRESERVED |

Not every page required new implementation — per this gate's own §12 instruction — and none was forced to look "newly built" where it was already sound.

## 16. Finance Reports

Source-level only, per this gate's own §14 instruction — no runtime correctness is claimed. Trial Balance, GL report, P&L, Balance Sheet, AR/AP Aging, Customer/Supplier Statement, Cash/Bank reports, Budget vs Actual, Profitability/Cost Intelligence, and Closing/period views are all confirmed backed by a real, distinct backend read-model service (never a client-side calculation) — enumerated exhaustively in Task 8's own report §27-30, reconfirmed here by re-reading each controller's route registration in `routes/api.php` without finding any drift.

## 17. System Reporting boundary

**Confirmed NONE**, by direct inspection of every file this workstream touched: no generic Sales/Customer/Product/Inventory/Procurement/Preparation/Distribution/Driver *operational* report exists anywhere under `Modules\Finance` or `frontend/src/features/finance`. Every report this workstream built or wired is an accounting/financial one (GL, AR/AP, revenue/COGS, expenses, cost allocation, profitability, cash flow). The separate System Reporting & Analytics lane (observed, this session, to be an actively-running concurrent session on this same machine — not interacted with, not depended upon) may consume the handoff matrix below; nothing was duplicated toward it.

## 18. Finance → Reporting handoff matrix

| Dataset/report | Source/query authority | Date basis | Authorization | Tenant/data scope | Drill-through boundary | Readiness | Upstream dependency |
|---|---|---|---|---|---|---|---|
| Recognized Revenue | `CustomerInvoice` (source_type='order') | posting date | `finance.ar.view` | company | → journal (id ref) | READY | Brand dimension: FIN-DEP-02/03 |
| COGS | F3 posting rule (`shipping.delivery_confirmation`) | posting date | `finance.journal.*` | company | → journal | READY | none |
| Gross Profit | derived (Revenue − COGS) at query time | posting date | `finance.analytics.view` | company | none | READY | none |
| Operating Expenses | `Expense` | expense_date | `finance.expense.view` | company | → journal (id ref) | READY | none |
| Profitability (7 dims) | `ProfitabilityService` | from/to | `finance.analytics.view` | company | none | READY (5/7 dims); product/channel honestly NOT YET AVAILABLE | ledger dimension tagging |
| Outstanding AR | `ArAgingService` (effective, allocation-aware) | as_of | `finance.ar.view` | company (+party) | → invoice | READY | none |
| Outstanding AP | `ApAgingService` | as_of | `finance.ap.view` | company (+party) | → bill | READY | none |
| Cash/Bank | `CashService`/`BankingService` | as-of | `finance.cash.view`/`finance.bank.view` | company | → transaction | READY | none |
| Trial Balance | `TrialBalanceService` | fiscal period | `finance.trialbalance.view` | company | → account | READY | none |
| P&L | `FinancialStatementService` | from/to | `finance.reports.view` | company | none | READY | none |
| Balance Sheet | `FinancialStatementService` | as_of | `finance.reports.view` | company | none | READY | none |
| AR Aging | `ArAgingService` | as_of | `finance.ar.view` | company | → invoice | READY | none |
| AP Aging | `ApAgingService` | as_of | `finance.ap.view` | company | → bill | READY | none |
| Customer Statement | `CustomerLedgerService` | from/to | `finance.ar.view` | company+party | → invoice/receipt | READY | none |
| Supplier Statement | `SupplierLedgerService` | from/to | `finance.ap.view` | company+party | → bill/payment | READY | none |
| Budget vs Actual | `BudgetControlEngine` | budget's own FY | `finance.budget.*` | company+budget | → account | READY | none |
| Driver Financial Ledger | `DriverLedgerEntry::balanceFor()`/history | entry_date | `finance.driver.view` | company+driver id | → journal (id ref) | READY (data), sparse until upstream triggers land | FIN-DEP-04/05 |

No Reporting integration was implemented — this is the handoff artifact only, per §16's own instruction.

## 19. Route inventory

**168 Finance API routes** confirmed by direct extraction from `routes/api.php` (every route wired to a `Finance*Controller`): 84 GET (read), 63 POST (create/allocate/reverse/action), 19 PATCH (approve/post/transition), 2 DELETE. Classified by action family: read (aging/ledger/statement/report/list/show), create (bill/payment/invoice/receipt/expense/allocation/journal), approve (payment/journal/budget), post (bill/payment/invoice/receipt/expense/journal), allocate (payment/receipt, manual + auto), reverse (allocation, posting, cost-allocation), report (trial-balance/statements/aging/profitability/cost/cash-flow), period control (open/close/lock/soft-close/hard-close/reopen/year-end).

**No duplicate controller+action wiring found** (every `[Controller::class, 'method']` pair appears exactly once). **No accidental route collision found** (repeated literal path fragments like `Route::get('/'` appear 14 times, but each sits under a distinct `Route::prefix(...)` group — normal REST convention, not a collision — verified by cross-checking every repeated fragment's enclosing prefix). **No unreachable controller found**: all 42 Finance controller classes have at least one route reference, confirmed by checking every controller file against `routes/api.php`. No material route defect found — §17's "hold for separate remediation" clause is not invoked.

## 20. Permission inventory

**63 distinct `finance.*` permissions** across two legitimate, coexisting mechanisms: 62 seeded via 8 Finance-module migrations (`2026_08_10_...` through `2026_09_03_...`, the pattern this workstream's own Tasks 6-8 additions used), plus **1** (`finance.ap.opening.post`) seeded via a separate, pre-existing `config/permissions.php` catalogue consumed by `RbacSeeder` — predating this workstream (`TASK-PROC-SUPPLIER-OPENING-BALANCE-001`), used by a Purchasing-owned route that calls into Finance's own `SupplierOpeningBalanceService`.

**Investigated as a potential defect, resolved as non-blocking**: an initial cross-check (migrations only) found `finance.ap.opening.post` used as a route permission with no seeding migration — which, if true, would have meant the route was permanently unreachable for any non-system-role user (a `permission:` gate requires a real `permissions` row for any role-based grant; no migration/seeder ⇒ no row ⇒ `in_array()` always false ⇒ permanent 403). A dedicated Explore agent traced the complete authorization chain (`RequirePermissionMiddleware` → `AuthorizationGateway::inspect()` → `PermissionService`) and found the row **is** created — by the config-catalogue + `RbacSeeder` mechanism, not a migration. **Classification: PERSISTED (via seeder, not migration) — not a defect.** One genuine, smaller, non-blocking observation surfaced by the same investigation: `SupplierOpeningBalanceTest.php` exercises the service directly, never the HTTP route, so there is no automated test proving a real `finance-manager` (non-system-bypass) user can reach this endpoint end-to-end — noted for the first-device test plan (§32), not a blocker.

**PERSISTED BUT UNUSED** (defined in a migration, never referenced as a route `permission:` middleware, never referenced in frontend `can()` calls): `finance.admin`, `finance.journal.approve`, `finance.posting.manage` — confirmed via a full-codebase grep to appear nowhere outside their own seed migration. Harmless dead inventory, not a defect; likely superseded early by more specific permissions (e.g. `finance.journal.post`) and never pruned. **DUPLICATE**: none found. **INVALID** (a route requiring a permission that cannot be granted to any role): none found, once both mechanisms are counted. **USED BUT NOT PERSISTED**: none, once both mechanisms are counted (initial single-mechanism check's one hit was resolved above). No missing/invalid permission makes any canonical Finance behavior unusable — §18's own STOP condition is not triggered.

## 21. Migration inventory

**14 Finance migrations introduced across Tasks 2, 5, 6, 7, 8** (Task 1/3/4 added none; Task 8 added exactly one, the `finance.driver.view` permission seed):

| Migration | Task | Purpose | Additive/destructive |
|---|---|---|---|
| `..._add_contra_allocation_columns_to_finance_payment_allocations_table` | 2 | `reverses_allocation_id`/`reversal_reason` on payment allocations | Additive |
| `..._add_contra_allocation_columns_to_finance_receipt_allocations_table` | 2 | Same, receipts | Additive |
| `..._create_finance_command_receipts_table` | 2 | Command-level idempotency | Additive (new table) |
| `..._add_source_reference_to_finance_customer_invoices_table` | 5/6 | `source_type`/`source_id` on invoices | Additive |
| `..._add_dimension_and_tax_override_to_finance_customer_invoice_lines_table` | 6 | `profit_center_id`, `tax_account_id` on invoice lines | Additive |
| `..._seed_finance_posting_rule_delivery_cogs` | 6 | One new global `PostingRule` row (data, not schema) | Additive |
| `..._add_source_reference_to_finance_customer_receipts_table` | 6 | `source_type`/`source_id` on receipts | Additive |
| `..._create_finance_expense_categories_table` | 7 | New table | Additive |
| `..._create_finance_expenses_table` | 7 | New table | Additive |
| `..._seed_finance_expense_permissions_table` | 7 | 4 permission rows | Additive |
| `..._create_finance_cost_allocations_table` | 7 | New table | Additive |
| `..._seed_finance_cost_allocation_permissions_table` | 7 | 2 permission rows | Additive |
| `..._create_finance_driver_ledger_entries_table` | 7 | New table | Additive |
| `..._add_source_reference_to_finance_cost_centers_table` | 7 | `source_type`/`source_id` on cost centers | Additive |
| `..._seed_finance_driver_permissions_table` | 8 | 1 permission row | Additive |

**All 14 additive. Zero destructive operations** (no `dropColumn` on existing data, no `dropTable`, no data-lossy `ALTER`). **Applied on second device: NO** (confirmed — `git status`/schema were never touched by any `artisan migrate` command this entire workstream). **No duplicate column/table found** (each migration's `Schema::create`/`Schema::table` target was confirmed unique against the existing schema before creation, per each task's own contemporaneous research). **No unsafe destructive operation.** **No incorrect future sequencing found** — timestamps are monotonic and each migration's `up()` correctly targets a table created by an earlier, already-applied migration. **No conflicting index found** (every new index has a distinct, task-specific name, e.g. `finance_exp_source_idx`, `finance_cc_source_idx`). **No tenant-uniqueness issue** (every new unique constraint scopes by `company_id` first, matching every pre-existing Finance table's own convention). **No unproven foreign-key assumption** (every new FK references a table confirmed to exist before the referencing migration's own timestamp).

## 22. Model/ledger invariants

**Confirmed: no mutable balance field exists for Supplier, Customer, or Driver.** `SupplierLedgerEntry`, `CustomerLedgerEntry`, `DriverLedgerEntry` each derive their party's balance via `SUM(amount)` over an append-only table — no `balance` column exists on `SupplierBill`/`SupplierPayment`/`CustomerInvoice`/`CustomerReceipt`/any driver-related row. A fresh grep this gate performed for any stray `balance`-shaped column found only `finance_bank_statements`/`finance_bank_reconciliations` — legitimately an *external* bank-reported figure used for reconciliation matching, not an internal derived accounting balance, and not a violation of this invariant.

## 23. Money/precision

Audited every new/changed file across Tasks 1-8 for authoritative financial math performed in JavaScript. **One honest, non-blocking finding**: `expenses-page.tsx`'s KPI header computes its "Total Amount" tile via a client-side `list.reduce((sum, e) => sum + e.amount, 0)` over the currently-fetched page (the backend list endpoint caps at 100 rows) — this is a **display-only** convenience figure (nothing is written or decided from it), but it would under-report the true total once a company has more than 100 expenses, since the backend does not (yet) return a pre-aggregated total for this list. Not classified as "frontend accounting truth" (per this task's own distinction, §21) since it drives no downstream decision and nothing else reads it — but flagged honestly rather than left silent, as a small, proportionate future improvement (have the backend return an aggregate, or paginate with a running total). All other financial totals across the entire Finance frontend (AR/AP aging, Trial Balance, P&L, Balance Sheet, Budget vs Actual, Profitability, Cost Intelligence, Cash Flow) are confirmed backend-computed and rendered as-is via `useFormatter()`, never re-aggregated client-side. One other client-side `reduce()` was found (`cost-allocation-form-drawer.tsx`, summing an in-progress *draft* form's own destination rows before submission) — confirmed to be the exact same "informational form guard only, backend re-validates" pattern this codebase's own pre-existing `journal-form-drawer.tsx` already uses for its debit/credit balance check — not a new risk.

## 24. Tenant isolation

Every new or Task 1-8-changed Finance endpoint resolves `company_id` from the authenticated user (`ResolvesFinanceContext::companyId()`), never from a request parameter — confirmed by direct inspection of every controller this workstream touched or added (`ExpenseController`, `ExpenseCategoryController`, `CostAllocationController`, `DriverLedgerController`, plus the extended `SupplierPaymentController`/`CustomerReceiptController`). Every new Eloquent query scopes explicitly by `company_id` before matching by uuid — confirmed a foreign-company journal/invoice/bill/receipt/expense/cost-allocation/driver-ledger id resolves to a 404/empty result, never a cross-tenant read, for every one of these resources (proven originally by each task's own dedicated tenant-boundary test, re-confirmed present in source by this gate, not re-executed).

## 25. Idempotency inventory

| Financial side effect | Mechanism | Task |
|---|---|---|
| Commercial revenue recognition | `source_type`/`source_id` existence guard on `CustomerInvoice` before `createDocument()` | 6 |
| COGS | `PostingCoordinator` exactly-once receipt (`finance_posted_event_receipts`) | 6 |
| COD receipt | `source_type`/`source_id` guard on `CustomerReceipt` | 6 |
| Vehicle/fleet cost | `PostingCoordinator` exactly-once receipt, keyed `fleet_cost_entry:<id>` | 7 |
| Expense posting | `PostingCoordinator` exactly-once receipt, keyed `expense:<uuid>` | 7 |
| Expense/Payment/Receipt command creation | `CommandIdempotencyGuard` (`Idempotency-Key` header, command-level) | 2/3/7 |
| Driver ledger postings | `source_type`/`source_id` existence guard on `DriverLedgerEntry` | 7 |
| All reversals (Journal/Payment/Receipt/Expense) | `JournalEngine::reverse()`'s own already-reversed guard | 3/5/6/7 |
| Cost Allocation reversal | Model-level append-only guard + `reverses_allocation_id` self-reference (no `JournalEngine` involved) | 7 |

No new idempotency engine was introduced anywhere — every mechanism above is one of the two canonical primitives (`PostingCoordinator`'s event receipts, or a `source_type`/`source_id` existence guard mirroring `SupplierOpeningBalanceService`'s own original pattern), confirmed consistent across all 8 tasks.

## 26. Reversal/correction matrix

| Original record | Correction authority | Contra/reversal form | Downstream effect | Audit preserved |
|---|---|---|---|---|
| Journal | `JournalEngine::reverse()` | New reversing journal (`reverses_journal_id`) | GL balance restored | Original journal frozen, never edited |
| Customer invoice/receipt | `reverseDocumentPosting()`/`reverseReceiptPosting()` | `JournalEngine::reverse()` + one new sign-flipped `CustomerLedgerEntry` | AR balance restored | Original ledger entry frozen |
| Supplier bill/payment | `reversePaymentPosting()` (payment side; bill side remains the generic path, unchanged, Task 5's own documented scope boundary) | Same pattern, `SupplierLedgerEntry` | AP balance restored | Original ledger entry frozen |
| Payment/Receipt allocation | `reversePaymentAllocation()`/`reverseReceiptAllocation()` | New negative-amount `PaymentAllocation`/`ReceiptAllocation` row | Unallocated amount restored | Original allocation frozen |
| Expense | `reverseExpensePosting()` | `JournalEngine::reverse()` (no subledger-entry mirror needed — Expense carries no running party balance) | GL restored | Original expense row frozen once posted |
| Cost Allocation | `reverseAllocation()` (service) | New negative-amount row, `reverses_allocation_id` | Effective-allocated amount restored | Original row frozen (model-level guard) |
| Driver ledger entry | `reverseDriverLedgerPosting()` | `JournalEngine::reverse()` + one new sign-flipped `DriverLedgerEntry` | Driver balance restored | Original entry frozen |

Every row: append-only, no destructive edit, downstream balance is always a re-derivation (`SUM(amount)`), never a manual adjustment.

## 27. Fiscal period control

`FiscalCalendarService`/`PeriodClosingService`/`YearEndClosingService` confirmed as the sole authority, unmodified across all 8 tasks. Every posting/reversal path added by Tasks 6-8 terminates in the unchanged `JournalEngine::post()`/`reverse()`, both of which still call `assertOpenPeriod()` — confirmed by tracing every new service method (`ExpenseService`, `CostAllocationService` has no posting at all so no period check applies, `DriverFinanceService`) back to `JournalEngine`. Open/closed/reopened states and year-end foundation are unchanged; no new closing behavior was implemented anywhere in Tasks 6-9.

## 28. Exact test inventory

**Recounted directly from source this gate, not copied from any prior report** (per §26's own explicit instruction) — and two real discrepancies were found and are corrected here:

**Backend**: `find backend/tests/Feature/Finance -name "*.php" | wc -l` → **31 files**. `grep -cE "public function test_"` summed via `awk` → **283 test methods**. Of these, **15 files / 170 test methods pre-date this entire workstream** (`LedgerFoundationTest`, `SubledgersTest`, `FinancialIntegrationTest`, `FinancialControlPlatformTest`, `FinancialIntelligenceWorkspaceTest`, `FinancialStatementTest`, `SupplierPaymentTransactionIntegrityTest`, `SupplierOpeningBalanceTest`, `FinanceApiTest`, `AccountRoleMappingTest`, `InventoryPostingPipelineTest`, `CustomerReceiptAllocationConcurrencyTest`, `SupplierPaymentFundingAccountTest`, `PaySupplierInvoiceServiceTest`, `PosSalePostingPipelineTest`) — none created or modified by Tasks 1-8. **16 files / 113 test methods were added by this workstream** (Tasks 2, 3+4, 5, 6, 7).

**Correction to prior reports**: Task 6's report claimed "23 new tests" for its 4 files; a fresh count gives **20** (`CommercialAccountingRevenueTest`=6, `Cogs`=4, `Cod`=4, `DimensionAndControl`=6). Task 7's report claimed "23 new tests" for its 4 files; a fresh count gives **25** (`ExpenseAccountingTest`=6, `CostAllocationTest`=7, `DriverFinanceAccountingTest`=9, `FleetCostAccountingTest`=3). Net effect: the workstream's own running total was last reported as 114; the corrected, source-counted total is **113**. Recorded here exactly as Task 4 once corrected Task 2/3's counts — the earlier reports are not rewritten.

**Frontend**: `find frontend/src/features/finance -iname "*.test.ts*" | wc -l` → **9 files**, all added by Task 8. `grep -cE "^\s*it\("` summed → **74 test cases** — identical to Task 8's own report, no drift found here.

| | Files | Test cases |
|---|---|---|
| Backend, pre-existing | 15 | 170 |
| Backend, this workstream (Tasks 2-7) | 16 | 113 |
| **Backend total** | **31** | **283** |
| Frontend, this workstream (Task 8) | 9 | 74 |
| **TOTAL SOURCE TEST INVENTORY** | **40** | **357** |

## 29. Tests executed

**NO.**

---

## 30. External dependency register (FIN-DEP)

| ID | Dependency | Owner | Blocks Finance source closure? | Blocks runtime certification? | Required contract | Current Finance receiving capability |
|---|---|---|---|---|---|---|
| FIN-DEP-01 | Instapay approved-payment event/contract | Commerce/Orders | NO | YES | A canonical approved-payment event carrying amount/method/order reference | None built (Task 6 found no safe amount source to build against — correctly not fabricated) |
| FIN-DEP-02 | Commercial Brand value at accounting recognition | Commerce/Orders/canonical Brand authority | NO | YES (for Brand-dimensioned profitability only) | A trustworthy Brand reference on the Delivered event or Order row | `FinancialEvent::profitCenterId()`/`RulePostingStrategy` passthrough wired, unpopulated (Task 6) |
| FIN-DEP-03 | Operational Brand reference/directory for attribution UX | Product/Commerce Brand authority | NO | NO (UX already shows a truthful raw-reference fallback, CTO-accepted) | A queryable Brand directory/picker | Cost Allocation's destination field accepts and displays a raw reference id today |
| FIN-DEP-04 | Driver Advance canonical source event | Shipping/Logistics/Operations | NO | YES | A domain event on `DriverTripMovement` approval | `DriverFinanceService::recognizeDriverAdvance()` — receiving contract complete, unconsumed |
| FIN-DEP-05 | Driver approved shortage event/routing | Shipping/Logistics/Operations | NO | YES | A routed HTTP entry point + event for shortage confirmation (both currently unrouted per Task 7's own research) | `DriverFinanceService::recognizeDriverShortage()` — receiving contract complete, unconsumed |

No dependency blocks Finance source closure (all six FIN-DEPs, including the Driver Expense trigger folded into FIN-DEP-04's own event, are consumption-side gaps in other modules, not Finance-owned defects). All meaningfully block full runtime *certification* of the corresponding capability, which is why this gate's own final state (§43) does not claim `CERTIFIED: YES`.

## 31. DO-NOT-REIMPLEMENT — final list

- `JournalEngine` (sole GL writer)
- `AccountsPayableService` / `SupplierLedgerEntry` (AP engine)
- `AccountsReceivableService` / `CustomerLedgerEntry` (AR engine)
- `DriverFinanceService` / `DriverLedgerEntry` (Driver financial ledger)
- `CommercialAccountingService` (revenue recognition, COGS trigger)
- `ExpenseService` / `Expense` / `ExpenseCategory` (Expense accounting)
- `CostAllocationService` / `CostAllocation` (management-cost attribution — never AP/AR allocation, never a GL writer)
- `FiscalCalendarService` / `PeriodClosingService` / `YearEndClosingService` (Fiscal Period authority)
- `BudgetService` / `BudgetControlEngine` (Budget actuals)
- `TrialBalanceService`, `FinancialStatementService`, `ProfitabilityService`, `CostIntelligenceService`, `CashFlowIntelligenceService` (Finance financial reporting queries)
- `PostingCoordinator` / `CommandIdempotencyGuard` (the two canonical idempotency primitives)
- `AllocationEngine` (AP/AR payment-to-document matching — distinct from, never merged with, Cost Allocation)

**External authorities Finance must not duplicate**: Commerce's own commercial-order pricing/Brand master data; Shipping/Logistics' own Trip/Distribution-Group/vehicle-driver-assignment/DriverTripMovement operational records; Procurement's own supplier master data (Finance's `SupplierLedgerEntry` remains an opaque-party subledger, never a supplier directory).

---

## 32. First-device migration plan (not executed)

1. **Discovery**: `php artisan migrate:status` against the first-device database to establish the current canonical migration baseline.
2. **Pending set**: the exact 14 migrations in §21, applied in their existing timestamp order (already monotonic and dependency-correct — no reordering needed).
3. **Dry/inspection strategy**: review each migration's `up()`/`down()` pair against the live schema (`php artisan schema:dump --pretend` or a manual `SHOW CREATE TABLE` diff) before running; confirm no live company data would be affected by any additive column default.
4. **Backup**: a full database backup/snapshot before running any migration — standard first-device practice, not unique to Finance.
5. **STOP conditions**: any migration whose target table/column already exists with a conflicting type; any FK target missing; any unexpected non-additive diff.
6. **Run only after explicit first-device approval** — `php artisan migrate` (never `migrate:fresh`), one environment at a time.
7. **Post-migration schema verification**: re-run `migrate:status` (expect all 14 as `Ran`), spot-check `finance_expenses`/`finance_cost_allocations`/`finance_driver_ledger_entries` exist with the exact column sets in §21's source migrations, and confirm `finance_account_roles` contains the `cost_of_goods_sold`/`driver_receivable`/`driver_shortage_recovery`/`cod_clearing` rows (seeded by `AccountRoleSeeder`, re-run via `php artisan db:seed --class=AccountRoleSeeder`, itself additive/idempotent by the seeder's own design).

**HARD RULE, honored by this plan, not by this task's execution**: no `migrate:fresh`, no seed beyond the explicitly-listed `AccountRoleSeeder`/permission migrations, no automatic migration — all of it deferred to first-device approval.

## 33. First-device test plan (not executed)

**A. GL/transaction safety** — `SupplierPaymentAllocationReversalTest`, `CustomerReceiptAllocationReversalTest`, `CommandIdempotencyGuardTest`, `JournalReversalAllocationGuardTest`, `AllocationReversalEndpointTest`, `SupplierPaymentIdempotencyEndpointTest`, `CustomerReceiptIdempotencyEndpointTest`, `LedgerFoundationTest`, `SubledgersTest`.
**B. AR** — `CustomerReceiptAllocationConcurrencyTest`, plus the AR-side cases inside the files above.
**C. AP** — `SupplierPaymentFundingAccountTest`, `SupplierPaymentTransactionIntegrityTest`, `SupplierOpeningBalanceTest`, `PaySupplierInvoiceServiceTest`.
**D. Commercial accounting** — `CommercialAccountingRevenueTest`, `CommercialAccountingCogsTest`, `CommercialAccountingCodTest`, `CommercialAccountingDimensionAndControlTest`, `GlSubledgerReversalReconciliationTest`.
**E. Operational cost accounting** — `FleetCostAccountingTest`, `DriverFinanceAccountingTest`.
**F. Expense** — `ExpenseAccountingTest`.
**G. Cost Allocation** — `CostAllocationTest`.
**H. Driver ledger** — covered within `DriverFinanceAccountingTest` (no separate file; the read endpoint `DriverLedgerController` itself has no dedicated backend test — noted as a small first-device-test-plan addition to write, not a source defect).
**I. Finance read/report APIs** — `FinancialStatementTest`, `FinancialIntegrationTest`, `FinancialControlPlatformTest`, `FinancialIntelligenceWorkspaceTest`, `AccountRoleMappingTest`, `InventoryPostingPipelineTest`, `PosSalePostingPipelineTest`, `FinanceApiTest`.
**J. Finance frontend** — all 9 files under `frontend/src/features/finance/{pages,components}/*.test.tsx` (§28).

Exact command for the full backend suite (not run here): `php artisan test --filter=Finance` or `vendor/bin/phpunit --testsuite=Feature --group=finance` depending on the project's actual PHPUnit group configuration — to be confirmed against `phpunit.xml` on the first device before use. Frontend: `npm test -- src/features/finance` (vitest).

## 34. First-device browser verification plan (not executed)

The exact 32 scenarios this task's own §33 specifies, unchanged and accepted as sufficient — reproduced here as the committed plan rather than re-derived: (1) Finance Overview, (2) GL list/detail/filter, (3) GL reversal, (4) Customer invoice/receipt, (5) AR allocation/reversal, (6) AR Aging, (7) Customer Statement, (8) Supplier bill/payment, (9) AP allocation/reversal, (10) AP Aging, (11) Supplier Statement, (12) COD delivery vs cash collection, (13) Cash in Transit visibility, (14) Expense maker/checker/post/reversal, (15) Cost Allocation, (16) Warehouse→Brand attribution semantics, (17) Driver Ledger, (18) raw waste not liability, (19) Profitability, (20) Cost Intelligence, (21) Cash Flow, (22) Trial Balance, (23) P&L, (24) Balance Sheet, (25) Budget vs Actual, (26) Fiscal period close restrictions, (27) authorization, (28) tenant isolation, (29) RTL, (30) responsive/mobile, (31) dark mode, (32) loading/error/empty states. None executed on this device.

## 35. Integration readiness

**Branch**: `task/finance-gap-closure`. **Merge base with `origin/task/finance-gap-closure`**: `d561516b41323e80ce76e3d35c4e942d397aaf4d` — this local branch is a clean, linear **11 commits ahead**, **0 behind**, of its own remote-tracking counterpart (a fast-forward relationship; no divergent history, no conflict possible on a simple push). **133 files changed** relative to that remote ref (16,132 insertions, 79 deletions) across the whole workstream. **Commits unique to this local branch** (11): the full Task 2→8 sequence, `f5051a45`…`950a2817`, listed in full in §37. **Conflicts detectable statically**: none — the branch never diverged from its own remote; there is nothing to conflict with on that ref. **Suitability for isolated first-device verification**: YES — the branch is self-contained, its own remote ref is a strict ancestor, and (§30 of the underlying migration inventory) all schema changes are additive.

## 36. Canonical freshness

**CANONICAL DEVELOP FRESHNESS: NOT VERIFIED.** Two separate, concrete findings support this classification rather than a guess: (1) `.git/FETCH_HEAD` does not exist anywhere in this repository's history — meaning no `git fetch`/`pull` has ever been run since this workspace was established; every remote-tracking ref (`origin/main`, `origin/task/finance-gap-closure`) is a frozen snapshot from initial setup, never refreshed. (2) The configured remote, `E:/ECOS/ecos-finance`, is confirmed unreachable from this device right now (`ls` fails) — consistent with this whole workstream's earlier, repeated, exhaustive findings that no such path exists on this machine. This branch shows "91 ahead, 0 behind" relative to the cached `origin/main` — but per (1) and (2), this is a comparison against a stale snapshot, not the current canonical state, and must not be read as proof that `main` has not moved. Per this gate's own §35 instruction: **this does not by itself block source closure**, and this report does not claim otherwise — it **does** require first-device reconciliation (a fresh fetch/rebase-free comparison against the real, current `main`/canonical develop) before any merge or integration is attempted.

## 37. Exact git evidence (starting gate, reproduced)

```
HEAD: 950a2817dda98b3700ab77c7dd9b626b35983182
STATUS: (clean)
BRANCH: task/finance-gap-closure
LOG (-20, unique commits this workstream):
950a2817 docs(finance): finalize finance ux reporting report
129f5659 feat(finance): complete finance workspace and reporting
a37f2452 docs(finance): finalize operational cost accounting report
b2f394fa feat(finance): implement operational cost accounting
7bf0dbea docs(finance): finalize commercial accounting report
b6e2da38 feat(finance): implement commercial accounting
75d58695 docs(finance): finalize accounting reconciliation reports
9d646019 fix(finance): reconcile GL reversal with supplier/customer ledger entries
dc8ce06e chore(finance): close finance foundation gate
9d3a8c3d feat(finance): wire ap ar transaction safety
f5051a45 feat(finance): add transaction safety foundation
d561516b (origin/task/finance-gap-closure, origin/HEAD) fix(finance): serialize AR receipt allocation   ← merge-base / pre-workstream baseline
```

## 38. Bundle evidence

Recorded in §44 after the bundle is created and verified (§40-42).

## 39. Certification state

**SOURCE VERIFIED: YES** (every claim in this report traces to a direct read of the current source this session, or a fresh mechanical count/grep — not an assumption, not copied from a prior report without re-checking). **RUNTIME VERIFIED: NO. DATABASE VERIFIED: NO. BROWSER VERIFIED: NO. CERTIFIED: NO.**

---

## 40-42. Git bundle creation and verification

Performed after this report's own commit — see §44 for exact evidence (path, size, verify result, scratch-repo proof).

---

## Required final state

**FINANCE TASKS:** 9/9 SOURCE COMPLETE
**FINANCE SOURCE GATE:** PASS
**FINAL CANDIDATE:** recorded in §44
**WORKING TREE:** CLEAN
**TRANSFER ARTIFACT:** recorded in §44
**TESTS EXECUTED:** NO
**DATABASE VERIFIED:** NO
**BROWSER VERIFIED:** NO
**FIRST-DEVICE VERIFICATION:** NOT STARTED
**INTEGRATED:** NO
**DEV VISIBLE:** NO
**USER VERIFIED:** NO
**CERTIFIED:** NO

---

*End of report body (§44 bundle evidence appended after the documentation commit and bundle creation, per this task's own §37-42 sequencing — a self-referential edit for exactly this evidence is authorized by §13's own instruction not to leave a LATER dirty edit, since this evidence is appended in the same pre-commit pass, not after).*
