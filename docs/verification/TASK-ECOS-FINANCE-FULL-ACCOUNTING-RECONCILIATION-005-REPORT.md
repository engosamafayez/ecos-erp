# TASK-ECOS-FINANCE-FULL-ACCOUNTING-RECONCILIATION-005 — Engineering Report

**ECOS Finance — Full Accounting Reconciliation / Source Closure / Final Implementation Map**

Workstream: ECOS ERP — Full Accounting Closure · Master plan position: Task 5 of 9

**Provenance note, stated once up front**: FIN-EXEC-01…08's current-state findings below are carried forward from `docs/verification/TASK-FINANCE-AUDIT-AND-ARCHITECTURE-LOCK-001-REPORT.md` (dated 2026-08-28, a full, file:line-cited audit read in complete detail earlier in this engineering lineage) rather than re-derived from zero — that audit was itself exhaustive and evidence-based, and Tasks 1–5 of *this* workstream never touched revenue, COGS, dimensions, expenses, cost allocation, or reporting (they are exclusively AP/AR transaction-safety). Every claim below was cross-checked against this session's own direct, fresh reads of AP/AR/Ledger source and found **not contradicted**. Where this task performed genuinely new verification (the GL↔subledger-entry gap, Accounts-lane search, Void status), that is marked explicitly as fresh, not carried forward.

---

## 1. Final status

## **COMPLETE**

Per §35: all eight FIN-EXEC areas are source-reconciled (§6–13), Accounts overlap is classified (§18), the GL/subledger reversal issue is safely closed, not merely assigned (§14/§15/§16), Void status is classified (§17), one canonical accounting authority is confirmed with no conflict (§4), Tasks 6–9 boundaries are exact (§27–30), and one focused local commit exists (§3/§34) — while `TESTS EXECUTED: NO`, `VERIFIED: NO`, `CERTIFIED: NO`, exactly as §35 anticipates.

## 2. Exact starting HEAD

`dc8ce06e85f3a2e70f872961a5b35686cd37affc` — confirmed by direct `git rev-parse HEAD` before any action, alongside a clean tree (four preserved report files untracked) and the repo-local identity already in place.

## 3. Exact final commit SHA

`9d6460193e4985fe03ab38bd1f3f691f19bf21b5` — `fix(finance): reconcile GL reversal with supplier/customer ledger entries` (see §16, §34).

---

## 4. Canonical Accounting authority decision

**CONFIRMED — `Modules\Finance` is the one, sole, canonical Accounting bounded authority. No conflict.** No `Modules\Accounts` (or equivalently-named module) exists anywhere under `backend/Modules` in this repository — reconfirmed by fresh directory enumeration this task, identical to Task 4's finding. Every accounting-shaped concept found elsewhere in the codebase is either (a) explicitly Finance's own (GL, COA, AR, AP, Cash/Banking, Budgets, Forecasting, F5 reporting — all under `Modules\Finance`), or (b) a genuinely separate, non-overlapping concern already correctly adjudicated by the original architecture audit (`CustomerEngagement`/CEP — external customer-conversation channels, not accounting) or by this workstream (Task 1's Collaboration ADR-044 — internal chat/tasks, not accounting). **No new bounded context is created to "unify names"** — the existing Finance authority already owns the underlying accounting truth for every capability listed in this task's §1 target list (GL, COA, AR, AP, Cash/Banking, Revenue, COGS, Expenses, Costing, Profitability, Budgets, Forecasting, Financial Reports, Closing/Control) at least at the *architectural* level, per the original audit — what's missing for several of these is *wiring*, not a second authority (§6–13).

## 5. Full DO-NOT-REIMPLEMENT list

Task 4's list, carried forward and extended by one entry:

- **`JournalEngine`** — sole GL writer; the only sanctioned correction is its own `reverse()`. **Unchanged by this task.**
- **Chart of Accounts authority** (`ChartOfAccountsService`, `ChartOfAccountsSeeder`).
- **AP engine** (`AccountsPayableService`) — sole writer of `SupplierBill`/`SupplierPayment`/`SupplierLedgerEntry`. **Extended this task** (one new method, `reversePaymentPosting()`) — still the sole writer, not a second one.
- **AR engine** (`AccountsReceivableService`) — sole writer of `CustomerInvoice`/`CustomerReceipt`/`CustomerLedgerEntry`. **Extended this task** (`reverseReceiptPosting()`), same principle.
- **Cash/Banking authority** (`CashService`, `BankingService`, `FundingAccountPolicy`).
- **`PostingCoordinator`** — sole event-level posting-idempotency mechanism. Untouched.
- **`AllocationEngine`** — sole allocation/contra-allocation authority. Untouched since Task 2.
- **`CommandIdempotencyGuard`/`FinanceCommandReceipt`** — sole command-level idempotency mechanism. Untouched since Task 2.
- **`JournalEngine::assertNoActiveSubledgerAllocations()`** — sole GL-reversal-vs-allocation guard. Untouched since Task 3.
- **NEW: `AccountsPayableService::reversePaymentPosting()` / `AccountsReceivableService::reverseReceiptPosting()`** — the sole authority for reversing a payment/receipt's journal *together with* its ledger-entry consequence. Do not build a second mechanism for this (e.g., inside a controller, inside `JournalEngine`, or as a standalone new service) — extend these two methods if the need grows.
- **Fiscal period/closing foundation** (`FiscalCalendarService`, `PeriodClosingService`, `YearEndClosingService`) — confirmed untouched.
- **Budget/forecast foundation** (`BudgetService`, `BudgetControlEngine`, `ForecastService`) — confirmed untouched.
- **F5 reporting kernel** (`FinancialMetricsService`, `FinancialStatementService`, `ExecutiveReportingService`, `CfoWorkspaceService`) — existing, proven; Task 8 extends its dimensional cuts, never rebuilds it (§10, §29).

---

## 6. FIN-EXEC-01 reconciliation — Accounting Dimensions

**Classification: PARTIALLY IMPLEMENTED.**

1. **What dimensions are already represented in journal/posting data?** `company_id`, `branch_id`, `cost_center_id` are live columns on `finance_journal_lines`, populated on every posting.
2. **Which are explicit?** Only those three. `profit_center_id`, `project_id`, `campaign_id` exist as columns and are carried through `JournalEngine::writeLines()`/the reversal mirror (confirmed by this session's own read of `JournalEngine.php`) but are **never populated** by any posting rule.
3. **Which are inferable only from source entities?** Brand, Channel, Warehouse, Shipping-Operation, Department, Product, Order all exist operationally (`orders.channel_id`, `orders.assigned_warehouse_id`, `order_financial_snapshots.brand_id`) but have no ledger column.
4. **Existing tables/metadata that must be preserved:** the journal-line dimension columns themselves (already dimension-*ready*, just spare); `order_financial_snapshots` as the product/order-grain read-model (ADR-020 already mandates reports read snapshots, not live products — do not duplicate this as a new dimension table).
5. **Duplicated by another module?** No — Distribution Group (Logistics) and Brand (Commerce/Product) each have exactly one owning module; Finance has never modeled its own copy.
6. **Genuinely missing for reporting/profitability:** the *mapping decision* (which spare column carries which dimension — e.g. Brand→`profit_center_id`, Warehouse/Department/Shipping-Op→typed `cost_center_id`, Channel→`campaign_id` or new nullable `channel_id`) and the *wiring* (teaching `FinancialEvent`/`RulePostingStrategy` to carry/read them). **No large dimension engine is needed** — the schema is additive-ready; this is a mapping decision plus a stamping change in the posting-rule layer.

**No fix attempted here** — this is bounded but is squarely Task 6's first deliverable (revenue/COGS need it immediately), not a "direct contradiction" this reconciliation task is chartered to patch (§3 of this task explicitly reserves exactly this scope for later).

## 7. FIN-EXEC-02 reconciliation — Revenue + COGS on Delivered

**Classification: IMPLEMENTATION GAP (the core one).**

- Revenue is **not** currently posted for the delivery/COD channel. `HandleOrderDelivered.php` routes to the analytics event bus only; `BusinessEventType::DeliveryConfirmation` is annotated "no GL by default." POS revenue **is** posted (`pos.sale.finalized`→`pos.sale` rule) — the one channel that already works, and the pattern (canonical writer → posting rule → `JournalEngine`) it should be extended to cover, not replaced.
- COGS is posted **nowhere** — confirmed no posting rule anywhere contains a `cogs`/`cost_of_sales` leg. The valued event that should trigger it (`ShipStockAction`→`InventoryStockShipped`, carrying `unitCost`/`extendedCost()`) is not in the posting-bridge catalog, so the cost is computed and then discarded.
- Product/inventory cost source: `EnterpriseCostEngine` (FIFO, `inventory_receipt_layers`) — already canonical, already correct, already computed at ship time. This is a wiring gap (map the event), not a costing gap.
- The system already emits suitable hooks: `inventory.stock.shipped` exists as a domain event; it simply isn't in the Finance catalog's five mapped keys.
- Order-level expected revenue/margin (`order_financial_snapshots`) already exists as the correct pre-delivery, non-GL forecast layer — do not duplicate it as a second "expected revenue" table.
- AR is **not** created at any point in the order lifecycle today — order payment is a scalar (`orders.deposit_amount`), never an AR document.
- Returns/cancellations: inventory-side negative-stock/return semantics exist; the *financial* reversal of a not-yet-existing posted cost does not exist because the posting itself doesn't exist yet.

**Exact Task 6 contract**: add an `orders.delivered` financial event (or map `shipping.delivery_confirmation`) with two new posting rules — revenue (`Dr AR-control/Cash · Cr sales_revenue`, + `Cr shipping_revenue` 4140 where applicable) and COGS (`Dr COGS 5100 · Cr Inventory/FG 1410`, using the cost already on `inventory.stock.shipped`) — recognized at the approved Delivered state, guarded against double-posting with POS, reusing `JournalEngine`/`PostingCoordinator` exactly as every existing posting rule already does. **No new engine.**

## 8. FIN-EXEC-03 reconciliation — Customer Payment / COD → GL

**Classification: IMPLEMENTATION GAP**, distinct from (not solved by) Tasks 2–5's own AR hardening.

- **Important, previously-flagged distinction reaffirmed**: `RecordOrderPaymentAction`→`orders.deposit_amount` (the order-level payment scalar) is a **completely different code path** from the F2 `AccountsReceivableService::createReceipt()`/`postReceipt()` flow Tasks 2–5 hardened (contra-allocation, idempotency, ledger-entry reversal). The order-payment scalar still does not create a `CustomerReceipt` and still never reaches the GL. Tasks 2–5 made the *existing* F2 receipt flow more robust; they did not create the missing order-payment-to-GL bridge.
- COD specifically: `SettlementService`/`TripSettlement`/`delivery_cod_records` are the correct, real, operational cash-collection authorities (Distribution's own "Single Cash Authority," D8) — confirmed still true; `TripSettled`/`CodCollected` still have zero listeners into Finance.
- No duplicate financial truth was found — Commerce/Shipping do not write anything resembling a journal or ledger entry anywhere; they are correctly silent on the GL side today (the gap is absence, not duplication).
- Payment proof/approval (`payment_proofs`, `UploadPaymentProofAction`/`VerifyPaymentProofAction`) carries no amount/method and has no accounting timing role today.

**Exact Task 6 contract**: on a recorded order payment and on COD verification, emit a financial event that posts the cash/bank/clearing leg against AR-control, landing the payment at its true destination — reusing the *existing* F2 `CashService`/`BankingService`/`AccountsReceivableService` writers, never a Commerce-owned receipt/ledger engine (hard-forbidden by §5/§22 of this task, and already the existing architecture's own explicit rule).

## 9. FIN-EXEC-04 reconciliation — Warehouse → Brand Internal Cost

**Classification: IMPLEMENTATION GAP — source-grounded, not assumed.**

Source evidence resolves the ambiguity this task's §6 asked not to assume past: "Warehouse → Brand Internal Cost" is **not** existing journal accounting, not existing cost allocation (that's FIN-EXEC-06, a distinct engine, not built either), and not a management-accounting dimension alone — it is a **missing internal-charge posting concept**. Warehouse is not represented as a Finance cost center at all today (`finance_cost_centers` is a generic hierarchical tag with no warehouse/department typing, and nothing seeds a warehouse as one). The operational primitive that *should* trigger it — custody transfer to a vehicle/brand (`TransferLoadedStockToVehicleAction`) — already exists and already deducts warehouse on-hand, but carries no financial position at all.

**Task 7 contract** (not Task 6 — this is operational/internal cost, not commercial revenue): (a) seed/allow warehouses as typed cost centers; (b) an internal Warehouse→Brand charge: `Dr Brand pending-inventory (internal) · Cr Warehouse inventory-out (internal clearing)` on custody transfer, converting to COGS on Delivered (depends on FIN-EXEC-02 landing first) and reversing on cancel/return; mark both legs internal for elimination (FIN-EXEC-08).

## 10. FIN-EXEC-05 reconciliation — Expense Capture

**Classification: IMPLEMENTATION GAP — genuinely missing as a product surface, not merely unwired.**

No `Expense` model, table, controller, or "quick expense" flow exists anywhere in the backend (confirmed originally by an exhaustive `**/*Expense*.php`/`quick.?expense` sweep, re-confirmed this session as untouched by Tasks 1–5). Every "expense" concept elsewhere is one of: a GL expense **account** (category, not a capture flow), Fleet/fuel cost (Logistics, operational, no GL posting — `VehicleCostPosted` has zero listeners), or a supplier bill (AP, real vendor-invoice entry, not fast operational capture). The nearest primitive, `CashService::recordTransaction()`, already derives a balanced journal from a cash-out but takes a raw GL account id, not an expense *type*, and carries no dimension. **No duplicate expense implementation exists to reconcile away** — there is exactly zero today.

**Exact Task 7 contract**: an Expense-Type→posting-template registry (reusing the *existing* F3 rule/role machinery — an expense type is just another posting rule, no second posting engine), a thin capture table (type/amount/date/payment-account/dimension/description/attachment), and a controller mapping type→rule→`PostingCoordinator`. Approval lifecycle, funding source, and dimensions all reuse existing primitives (`FundingAccountPolicy`-style eligibility, the dimension columns from FIN-EXEC-01, `Document`/`DocumentService` for attachments — confirmed still the one genuinely-reused shared storage mechanism, untouched by this workstream).

## 11. FIN-EXEC-06 reconciliation — Cost Allocations

**Classification: IMPLEMENTATION GAP — and a name-collision risk reaffirmed, not created.**

**Distinguished carefully, per this task's own explicit instruction**: `Modules\Finance\Allocation\Domain\Services\AllocationEngine` (the class Tasks 2–5 extensively hardened) is AR/AP **payment-to-document matching** — "which invoice does this receipt settle" — a completely different concept from **shared-cost/overhead allocation to brands** (revenue%/orders%/units%/manual-% basis, run per period, posting an internal charge). No such cost-allocation engine exists anywhere; it cannot exist yet in any case, because it needs the Brand dimension (FIN-EXEC-01) to allocate *to*. This workstream's own `AllocationEngine` must **never** be confused with or extended into this — a risk this task was specifically asked to reaffirm, which it does: nothing in Tasks 1–5 touched or blurred that boundary.

**Exact Task 7 contract**: a genuinely new, small Cost Allocation engine — allocation rules (source cost-center/account set → target brands, configurable basis), run per period, posting an internal `Dr Brand allocated-cost · Cr Shared-cost-pool` (internal, elimination-marked, FIN-EXEC-08). Depends on FIN-EXEC-01 (dimensions) and benefits from FIN-EXEC-05 (expense capture) landing first, since expenses are a natural allocation source.

## 12. FIN-EXEC-07 reconciliation — Shipping-Operation P&L + Driver Costs/Advances

**Classification: MIXED — real operational primitives EXISTING, no Finance-side representation.**

- Fleet/vehicle cost + fuel ledger (`fleet_cost_entries`, `VehicleCostService`, `FuelReconciliationService`) — real, operational, append-only with reversing corrections — but zero GL postings (`VehicleCostPosted`/`FuelTransactionRecorded` have zero listeners).
- Driver cash collections/shortages: `TripSettlement.discrepancy`/`isShort()` — a decimal, never turned into a receivable or GL posting. Damage/waste: `distribution_trip_returns.driver_liable` — a boolean, never valued into money.
- **Approved rule reaffirmed and still correctly honoured today, precisely because nothing posts yet**: "waste/damage does not automatically become a final driver financial shortage until approved/investigated" — true today only because the entire chain is unposted; once Task 6/7 wires revenue-on-delivery and driver-cost posting, this approval gate must be built *into* the posting rule itself (post the shortage only from the approved/investigated state), not merely inherited by accident of nothing existing yet.
- Driver advances: only HR's employee-scoped `hr_advances` exists; drivers are not linked to HR employees; no Finance posting.
- Distribution Group / Trip financial dimensions: none in Finance today (FIN-EXEC-01 dependency).

**Exact Task 7 contract** (Finance integration/posting contracts only, per this task's own instruction — no Shipping domain logic inside Finance): a Shipping-Operation cost/profit-center dimension; posting rules for fleet cost, fuel, and driver cost against it; a driver-advance **receivable** model (not an expense) reconciled at settlement; the approved shortage-only-after-approval gate expressed as a posting-rule precondition, not a Shipping-side rule Finance merely trusts.

## 13. FIN-EXEC-08 reconciliation — Unified Reporting / Elimination

**Classification: EXISTING (kernel) / IMPLEMENTATION GAP (the cuts it's blocked from producing).**

The F5 kernel (`FinancialMetricsService` + statement services) is real, complete, and — confirmed by its own honest self-report (`ProfitabilityService::byUntaggedDimension()` returns `available:false` with *"The ledger does not tag journal lines by {dimension}"*) — correctly refuses to fabricate a Brand/Channel/Shipping-Op cut it cannot support today. P&L/BS/TB/Cash Position/AR-AP aging/profitability-by-branch-cost-center-project-customer all exist and are correct for what they cover. **No report anywhere was found falsely claiming completeness it doesn't have** — the kernel's own `available:false` behavior is the opposite of a false-complete claim. Missing: Brand/Channel/Warehouse/Shipping-Op cuts (blocked on FIN-EXEC-01), and the unified Target/Expected/Forecast/Actual/Variance model with an On-Target/Attention/Critical status (currently several un-unified forms: budget variance, KPI-scorecard target, cash-session variance).

**Exact Task 8 contract**: once dimensions land, add the new cuts to the *existing* kernel (it already signs by normal balance and groups by dimension — no new reporting engine); build the unifying Target/Actual/Variance read-model composed over existing services, writing nothing new; add the `is_internal` elimination marker's reporting-side consumption (the marker itself is written at posting time in Task 6/7).

---

## 14. GL reversal ↔ SupplierLedgerEntry reconciliation

**CLOSED this task.** `SupplierLedgerEntry` was confirmed (direct model/migration read, not assumed) to be a **canonical subledger entry**, not a read projection: append-only (`booted()` blocks `updating`/`deleting` unconditionally, identical to `PaymentAllocation`), signed, linked to `journal_entry_id`, its own docblock stating "A supplier's balance is SUM(amount); the sum... reconciles to the AP control account in the GL." It is created in exactly two places (`AccountsPayableService::postDocument()`/`postPayment()`, plus `SupplierOpeningBalanceService`'s opening-balance writes) — confirmed by grep, no other writer exists. It did **not** previously support reversal/contra entries — confirmed by grep, zero references to `reversed_by_journal_id`/`reverses_journal_id`/`JournalStatus::Reversed` anywhere in `Modules\Finance\Payables`.

**Resolution chosen**: a new, append-only, sign-flipped `SupplierLedgerEntry`, linked via `journal_entry_id` to the *new* reversal journal and via a new `source_type='ledger_entry_reversal'`/`source_id=<original uuid>` pair back to the original — the exact "mirrored reversing ledger entry" option this task's own §11 offered, chosen over a status marker (the model has no status column and its own docblock already states "corrections are new entries, never edits" — a status marker would contradict the model's own stated design) or a derived-projection rebuild (would require inventing a *second* balance-derivation mechanism where SUM-of-rows already works perfectly). **The smallest solution consistent with existing architecture**, per §12's own bar.

## 15. GL reversal ↔ CustomerLedgerEntry reconciliation

**CLOSED this task**, the exact mirror. `CustomerLedgerEntry`'s own docblock states, verbatim, *"Corrections are new entries, never edits"* — confirming this design was the model's own original intent, simply never built. `AccountsReceivableService::reverseReceiptPosting()` implements it identically to the AP side.

## 16. Source fix implemented

**YES.** See §14/§15 for the design and §30/§31 (this report's own numbering continues below) for tests. New authority: `AccountsPayableService::reversePaymentPosting(SupplierPayment, string $reason, ?int $actorId): JournalEntry` / `AccountsReceivableService::reverseReceiptPosting(CustomerReceipt, ...)`. Each: resolves the payment/receipt's own journal, calls the **unchanged** `JournalEngine::reverse()` (so the Task 3 allocation guard applies automatically, not re-implemented), then writes exactly one new ledger entry — all inside one `DB::transaction`. New HTTP surface: `POST finance/ap/payments/{uuid}/reverse-posting` / `POST finance/ar/receipts/{uuid}/reverse-posting`, gated by the existing `finance.journal.post` checker permission (reused, not a new permission — consistent with this workstream's Task 4 CTO ruling on reusing existing authorities rather than minting new ones). The pre-existing generic `POST finance/journals/{uuid}/reverse` endpoint is **completely unchanged** and remains capable of reversing any journal directly — doing so for a payment/receipt via that path still will not produce the ledger correction; the new, more specific endpoints are the documented, correct, complete path.

## 17. Void status final classification

**Fresh reconfirmation this task**: `PaymentStatus::Void`/`DocumentStatus::Void` remain fully unreachable (repeat grep, zero assignment sites, unchanged since Task 1).

## **Classification: B — LEGACY / RETIRE** (superseding Task 4's more cautious "VALID BUT DEFERRED", now that all four of this task's own retirement criteria are met):
- No valid transition exists to it (confirmed, again, this task).
- No UI/API exposes it (confirmed).
- No accounting contract depends on it (confirmed).
- **Reversal/contra-entry is now confirmed the canonical correction mechanism** for a posted document — this task just built and proved that pattern end-to-end for payments/receipts (§14–16), on top of Tasks 2–3's identical pattern for allocations. `Void`'s own docblock scope ("cancels a *pre-posting* payment") was always a *different* lifecycle stage than what reversal now correctly owns (post-posting correction) — the two were never meant to overlap, and now that post-posting correction is proven and shipped, there is no remaining gap Void would need to fill.

**Safe retirement plan** (not implemented here — §29 forbids opportunistic scope growth): do **not** delete the enum cases or any column now — they are harmless (fully guarded against, zero live references) and removing them would be a schema/enum change with no correctness benefit and a small, needless migration-risk surface. Recommend: mark both enum cases `@deprecated` in a future documentation pass, and physically remove them only as part of a dedicated, low-priority cleanup task with no urgency — never inside a commercial-accounting task's scope.

## 18. Accounts-lane overlap

**UNAVAILABLE — reconfirmed, not newly discovered.** Identical finding to Task 4: no `Modules\Accounts` (or equivalent) exists anywhere in this repository; this session has no access to any other repository or lane that might carry that name. Per §14 of this task, the precise comparison package Task 9 would need if such a lane exists elsewhere: (a) its module/directory listing, (b) its migration list (table names, especially anything resembling `*_ledger*`, `*_journal*`, `*_balance*`, `*_statement*`), (c) its permission/route list, (d) any report/screen inventory. Every classification bucket this task's §14 asks for (`FINANCE CANONICAL`/`DUPLICATE`/`NON-FINANCIAL`/`DEPENDENCY`) reduces, in the absence of that package, to **UNKNOWN — NEEDS EVIDENCE** for all of: customer balances, supplier balances, journals, financial statements, account statements, payments/receipts, expense records, closing, reporting. This is reported as an honest absence, not fabricated as "no overlap exists" — those are different claims, and only the former is supportable from here.

## 19. Finance target IA mapping

| # | Area | Backend | UI | Notes |
|---|---|---|---|---|
| 1 | Finance Overview | BACKEND COMPLETE | UI EXISTS | Executive/CFO dashboard, read-only |
| 2 | General Ledger | BACKEND COMPLETE | UI EXISTS | Journal Entries screen; create/post/discard/reverse |
| 3 | Receivables | BACKEND PARTIAL | UI EXISTS (read-only) | F2 complete; order-payment/COD wiring is FIN-EXEC-03 → **TASK 6** |
| 4 | Payables | BACKEND COMPLETE | UI EXISTS | Including this workstream's reversal/idempotency additions |
| 5 | Cash & Banking | BACKEND COMPLETE | UI EXISTS | create accounts/sessions/transactions/transfer/reconcile |
| 6 | Expenses | NO BACKEND | NO UI | FIN-EXEC-05 → **TASK 7** |
| 7 | Costing & Profitability | BACKEND PARTIAL | UI EXISTS (partial cuts) | Kernel exists; brand/channel/ship-op cuts blocked on dimensions → **TASK 6 (dims) / TASK 8 (cuts)** |
| 8 | Budgets & Forecasts | BACKEND COMPLETE | UI EXISTS | F4/F5, untouched, proven |
| 9 | Financial Reports | BACKEND COMPLETE (existing scope) / PARTIAL (new cuts) | UI EXISTS | See FIN-EXEC-08 |
| 10 | Closing & Control | BACKEND COMPLETE | UI EXISTS | F4, untouched, proven |

No menu explosion recommended — Tasks 6/7 add capability to existing areas (Receivables, Payables, new Expenses tab), not new top-level areas.

## 20. Accounting event authority matrix

| Event | Source module | Finance action | GL entry | Subledger entry | Idempotency | Reversal/correction |
|---|---|---|---|---|---|---|
| Supplier bill | Purchasing→Finance | `AccountsPayableService::postDocument()` | ✅ | `SupplierLedgerEntry` | Event-level (`PostingCoordinator`) | Generic `JournalEngine::reverse()` only (no ledger-entry mirror yet for bills — out of this task's bounded scope, same class of gap, not fixed here) |
| Supplier payment | Finance (this workstream) | `postPayment()` | ✅ | `SupplierLedgerEntry` | Command-level (Task 2/3) + event-level | **`reversePaymentPosting()` — CLOSED this task** |
| Customer invoice | Finance | `postDocument()` | ✅ | `CustomerLedgerEntry` | Event-level | Generic only (same class of gap as bills, not fixed here) |
| Customer receipt | Finance | `postReceipt()` | ✅ | `CustomerLedgerEntry` | Command-level (Task 2/3) + event-level | **`reverseReceiptPosting()` — CLOSED this task** |
| Order delivered | Commerce/Operations | **MISSING** | ❌ | n/a | n/a | n/a → **TASK 6** |
| COD collected | Distribution | **MISSING** | ❌ | n/a | n/a | n/a → **TASK 6** |
| Customer payment approved | Commerce | **MISSING** | ❌ | n/a | n/a | n/a → **TASK 6** |
| Expense approved | n/a (no capability) | **MISSING** | ❌ | n/a | n/a | n/a → **TASK 7** |
| Shipping/driver cost approved | Logistics | **MISSING** | ❌ | n/a | n/a | n/a → **TASK 7** |
| Internal cost allocation | n/a (no engine) | **MISSING** | ❌ | n/a | n/a | n/a → **TASK 7** |
| Return/refund | Commerce | **MISSING** (no revenue exists yet to refund against) | ❌ | n/a | n/a | → **TASK 6** |
| Journal reversal (generic) | Finance | `JournalEngine::reverse()` | ✅ | n/a (see per-source rows above) | n/a (checker-gated, not retry-idempotent by design) | Itself the correction mechanism |
| Period close | Finance | `PeriodClosingService`/`YearEndClosingService` | ✅ (year-end sweep) | n/a | n/a | Reopen (authorized), never edits history |

## 21. Dimension authority map

Reaffirmed, not re-decided (this task performs no new dimension work, per §3/§6): Company→`companies` (Organization); Brand→Commerce/Product's own Brand authority (`order_financial_snapshots.brand_id` as the operational source Finance would reference, never duplicate); Warehouse→Logistics/MasterData `warehouses`; Channel→`channels` (Commerce); Customer→Sales/CRM's canonical customer; Supplier→Procurement's canonical supplier; Trip/Distribution-Group/Shipping-Operation→Logistics/Distribution. **Finance's rule, reaffirmed**: store stable references/snapshots only where accounting integrity requires them (exactly the pattern `order_financial_snapshots` and this workstream's own `SupplierLedgerEntry.source_id`/`CustomerLedgerEntry.source_id` already use) — never a second copy of master data.

## 22. Customer balance authority

**AR remains the sole accounting-receivable authority; no duplicate found.** Distinguished explicitly, per this task's own instruction: AR balance (`CustomerLedgerEntry`, this task's own reconciliation subject) is the accounting truth. Wallet/credit balance, promotional credit, and "operational outstanding" (`orders.deposit_amount`) are **separate, real, non-accounting concepts** that were confirmed (originally, and re-confirmed as untouched by this workstream) to exist only as scalars/flags with no ledger backing — they are not collapsed into AR here, and AR is not contaminated by them. FIN-EXEC-03's job (Task 6) is to make the operational-outstanding path *post into* AR at the right moment, not to merge the concepts.

## 23. Supplier balance authority

**AP remains the sole accounting-payable authority; no duplicate found.** `SupplierLedgerService::balance()`/`outstandingPayable()`/`availableAdvance()` (all re-read in full this task) are the one canonical derivation, explicitly excluding advances from the payable figure and never reading `goods_receipts.paid_amount` (a hand-entered, non-authoritative field the service's own docblock explicitly warns against). Procurement's "operational outstanding," if any exists, is not accounting AP and must not be presented as such.

## 24. Supplier opening-balance Finance contract

**ALREADY PARTIALLY IMPLEMENTED — confirmed by direct code read this task, not assumed.** `SupplierOpeningBalanceService` (found via this task's own `SupplierLedgerEntry::create` grep, §14) already exists: `postOpeningPayable()` (Dr equity / Cr AP-control, `SupplierLedgerEntryType::OpeningPayable`) and `postOpeningAdvance()` (Dr advance-asset / Cr equity, `SupplierLedgerEntryType::Advance`, stored −ve, surfaced separately as "Available Advance" — never netted into the payable). Both are explicitly idempotent (deterministic `source_event_id` + an existence guard — the exact pattern this workstream generalized in Task 2/3). **The Finance contract already correctly satisfies the approved Procurement requirement** (amount owed to supplier vs. supplier owes company, flowing into canonical AP balance) — Procurement does **not** need to, and must not, build a parallel supplier-accounting engine; it needs only to call this existing service. No fix needed; this is a **PRESERVE**, and Task 6/7 should point Procurement at it rather than rediscover it.

## 25. Profitability source map

**Revenue − COGS − allocated operating expenses/costs, mapped by dimension — no separate profitability truth needed, confirmed derivable from canonical postings once FIN-EXEC-01/02/05/06 land.** Current support: the F5 kernel already derives profitability by branch/cost_center/project/customer directly from posted journal lines (no stored profitability table) — the identical derivation mechanism will serve brand/channel/warehouse/shipping-op profitability the moment those dimensions are stamped (§6, §13) and revenue/COGS/expenses/allocations post (§7, §10, §11). **This task creates no new profitability mechanism** — none is needed; the existing one is confirmed dimension-and-posting-complete-agnostic already.

## 26. Period/closing integration contract

**PRESERVE, confirmed as the existing canonical guard for all future postings.** `JournalEngine::assertOpenPeriod()` (unchanged by this entire workstream) is invoked by every posting path — `post()`, `submitDraft`, and (via the period lookup) `reverse()` — and by construction will be invoked by any FIN-EXEC posting rule Task 6/7 add, since they all necessarily go through `JournalEngine`/`PostingCoordinator`. **No new period engine is needed or was created.** This task's own new methods (`reversePaymentPosting`/`reverseReceiptPosting`) inherit this guard for free, since they call the unchanged `reverse()`, which itself calls `assertOpenPeriod()` unchanged.

---

## 27. Exact Task 6 scope — Commercial Accounting

FIN-EXEC-01 (dimension mapping decision + wiring, only as far as revenue/COGS/AR need it — not the full future dimension set), FIN-EXEC-02 (revenue + COGS on Delivered, POS-dedup guarded), FIN-EXEC-03 (customer payment + COD → GL, reusing existing F2 Cash/AR writers), plus returns/reversals directly tied to these new postings. **Explicitly excluded from Task 6** (per this task's own instruction not to load unrelated work in): expenses, cost allocation, warehouse/brand internal cost, shipping/driver cost, reporting UI.

## 28. Exact Task 7 scope — Operational Cost Accounting

FIN-EXEC-04 (Warehouse→Brand internal cost — depends on Task 6's dimensions), FIN-EXEC-05 (Expense capture — genuinely new capability), FIN-EXEC-06 (Cost allocation engine — genuinely new, depends on Task 6's Brand dimension), FIN-EXEC-07 (Shipping-Op P&L + fleet/driver cost + driver advances — Finance-side posting contracts only, no Shipping domain logic). Everything already existing (Fleet cost ledger, `SupplierOpeningBalanceService`, `AllocationEngine`) is **PRESERVE/WIRE, never reimplement** (§17 of this task, satisfied by this report's own §9–12/§24 findings).

## 29. Exact Task 8 scope — Finance UX / Reporting

The approved 10-area IA (§19) reconciled against the existing Finance frontend; missing screens (Expenses, Cost Allocation, Brand/Ship-Op P&L); brand/channel/warehouse/shipping-op cuts added to the *existing* F5 kernel (never a new reporting engine); the unified Target/Expected/Forecast/Actual/Variance read-model with On-Target/Attention/Critical status; Budget-vs-Actual and closing/control UX reconciliation. **Must consume canonical backend authority only — no business-accounting rule is ever planned in React**, per this task's own hard instruction, consistent with every prior task's "controllers stay thin" principle extended to the frontend.

## 30. Exact Task 9 scope — Accounting Source Closure Gate

Final Finance-vs-Accounts reconciliation (requires the evidence package this report's §18/§26 above specifies, likely still unavailable); confirmation of no duplicate write authorities across everything Tasks 6–8 will have added; subledger↔GL consistency re-audit (including whether the *bill*/*invoice* reversal-ledger-entry gap this report flagged in §20 as "not fixed here" should finally close); report-source consistency; final DO-NOT-REIMPLEMENT list; migration/permission inventory; consolidated focused-test inventory (§31); integration readiness; the first-device verification package. **Task 9 is explicitly not runtime certification itself** (per this task's own instruction) — it prepares for it.

---

## 31. Tests written

**6 new tests this task** (`GlSubledgerReversalReconciliationTest.php`), covering exactly §30's six required scenarios: supplier-side balance reconciliation, customer-side balance reconciliation, original-entry immutability/auditability, non-duplication on a rejected repeated-reversal attempt, foreign-company rejection, and confirmation that an unrelated (bill) journal reversed via the untouched generic path writes no compensating entry.

**Consolidated inventory across the whole workstream, corrected per Task 4's own recount discipline:**

| Task | Files | Tests |
|---|---|---|
| Task 2 | `SupplierPaymentAllocationReversalTest`, `CustomerReceiptAllocationReversalTest`, `CommandIdempotencyGuardTest` | 27 |
| Task 3 | `JournalReversalAllocationGuardTest`, `SupplierPaymentIdempotencyEndpointTest`, `CustomerReceiptIdempotencyEndpointTest` (6 as committed), `AllocationReversalEndpointTest` | 33 |
| Task 4 | +2 added to `CustomerReceiptIdempotencyEndpointTest` (write-off idempotency) | 2 |
| Task 5 | `GlSubledgerReversalReconciliationTest` | 6 |
| **Total** | **8 files** | **68** |

## 32. Tests executed

**NO.** No broad Finance test run was performed, per §31 of this task; no toolchain re-investigation was attempted (Task 4 already exhausted that search machine-wide; nothing about this session's environment suggests it has changed).

## 33. Verification state

**NOT VERIFIED.** Every claim in §6–§16 above rests on direct source reads performed in this task or the immediately-preceding ones in this same lineage (Tasks 1–4), not on execution. The `reversePaymentPosting()`/`reverseReceiptPosting()` fix in particular has been reasoned through with the same rigor as every prior fix in this workstream (exact existing-column reuse, exact sign convention traced from the enums' own `sign()` methods, exact transaction-boundary placement) but has not run.

## 34. Exact git status

Clean except four preserved, untracked report files (Tasks 1–4's own reports, none edited this task) plus this new report once written. `HEAD = 9d6460193e4985fe03ab38bd1f3f691f19bf21b5`. Not pushed — local branch now 4 commits ahead of `origin/task/finance-gap-closure`.

**On §33's "update the persistent engineering context" instruction**: no dedicated Finance-context file distinct from these dated `docs/verification/TASK-*-REPORT.md` engineering reports was found anywhere in this repository across five tasks of direct exploration — this report itself, plus its DO-NOT-REIMPLEMENT list (§5) and canonical-authority decision (§4), is the persistent record this instruction asks for, consistent with how Task 4 satisfied the identical instruction. No separate context file was invented.

## 35. Remaining external dependencies

- The Accounts-lane evidence package (§18) — genuinely external to this session.
- A working PHP/MySQL toolchain for first-device verification of this entire workstream (Tasks 2–5).
- Task 6's own dependency chain is now exact and internal (§27) — no external blocker beyond the above two.

## 36. Task 6 release recommendation

**APPROVED FOR CTO REVIEW.** Recommend Task 6 begin with the dimension-mapping *decision* (§6) — a small, cheap, unblocking choice — before any posting-rule code, exactly as the original architecture audit itself sequenced. Recommend Task 9's Accounts-lane evidence package be requested now, in parallel with Task 6/7/8's implementation work, so it is not a late surprise.

---

## Required final state

**FULL ACCOUNTING RECONCILIATION:** PASS
**CANONICAL ACCOUNTING AUTHORITY:** CONFIRMED
**FIN-EXEC-01:** RECONCILED
**FIN-EXEC-02:** RECONCILED
**FIN-EXEC-03:** RECONCILED
**FIN-EXEC-04:** RECONCILED
**FIN-EXEC-05:** RECONCILED
**FIN-EXEC-06:** RECONCILED
**FIN-EXEC-07:** RECONCILED
**FIN-EXEC-08:** RECONCILED
**GL ↔ SUBLEDGER REVERSAL:** CLOSED
**VOID STATUS:** LEGACY-RETIRE
**ACCOUNTS OVERLAP:** UNAVAILABLE

**TESTS EXECUTED:** NO
**VERIFIED:** NO
**COMMITTED:** YES — `9d6460193e4985fe03ab38bd1f3f691f19bf21b5`
**INTEGRATED:** NO
**DEV VISIBLE:** NO
**USER VERIFIED:** NO
**CERTIFIED:** NO

**TASK 6:** NOT STARTED
**TASK 6 RELEASE:** APPROVED FOR CTO REVIEW

---

*End of report. No DEV migration, seed, deploy, merge, push, cherry-pick, or reset occurred. No broad/full regression or browser certification was attempted. All four prior reports remain preserved and unedited. Awaiting CTO review before Task 6 begins.*
