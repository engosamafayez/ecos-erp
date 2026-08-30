# TASK-FINANCE-AUDIT-AND-ARCHITECTURE-LOCK-001 — REPORT

**ECOS Finance — Existing Financial System Audit + Architecture Lock**

Mode: **AUDIT + ARCHITECTURE ONLY**. No code, schema, data, or configuration was modified. No tests were run. No commits/pushes/deploys. All findings are read-only observations against the current `develop` worktree with `file:line` evidence.

Date: 2026-08-28 · Working tree: `C:\ecos-develop` · Branch: `develop`

---

## 0. How to read this report

For each major capability the report states:

- **STATUS** — `EXISTING` · `PARTIAL` · `MISSING` · `CONFLICTING`
- **CANONICAL SOURCE** — the exact class/service/action/event/table that owns it (if any)
- **KEEP** — what must remain untouched
- **EXTEND** — the minimal missing capability
- **DO NOT REBUILD** — what must never be duplicated

The single most important structural fact, stated once up front so nothing below is misread:

> **A complete, well-architected double-entry accounting engine already exists (Finance OS, EPICs F1–F5) and is LIVE. What is missing is not the ledger — it is the *sales-cycle wiring into it* (revenue-on-delivery, COGS, COD→GL, order-payment→cash) and the *analytic dimensions* the agreed model needs (brand / channel / warehouse / shipping-operation / department / product / order).** The work ahead is overwhelmingly **REWIRE + EXTEND**, not **BUILD**. Rebuilding any part of F1–F5 would be a serious and expensive error.

---

## 1. Executive Summary

### 1.1 What already exists (and must not be rebuilt)

ECOS carries a full **Finance OS** under `backend/Modules/Finance/` — ~230 PHP files, ~45 migrations, 5 EPICs (F1–F5), and a Finance frontend of 10 screens under `/accounting`. It provides, verified end-to-end in code:

- **A general ledger with one and only one writer** — `JournalEngine` (balanced, postable, open-period, reversal-only, maker≠checker).
- **A per-company Chart of Accounts** — 101 accounts, bilingual (EN/AR), typed and categorised, with control accounts for AR/AP/Inventory/VAT/Payroll.
- **AR / AP / Cash / Banking subledgers** (F2) that post only through the ledger, never into it.
- **An event → posting-rule → journal bridge** (F3) that is **ON by default** and already posts real journals for **POS sales, inventory movements, and supplier invoices**.
- **Fiscal periods, closing, year-end, budgets, forecasting, controls, and a read-only intelligence/reporting layer** (F4/F5).

### 1.2 The core gap — the sales cycle never reaches the ledger

The posting engine is real and live, but it is **fed almost nothing that recognises a sale on the delivery/COD channel**:

| Sales event | Reaches the GL today? | Evidence |
|---|---|---|
| POS sale revenue | ✅ YES (`Dr cash / Cr sales_revenue / Cr vat_output`) | `seed_finance_posting_rules.php:96-100` |
| **Order-delivery revenue** | ❌ **NO** — routed to the analytics bus, not Finance | `HandleOrderDelivered.php:51-68`, `BusinessEventType.php:56` (`// no GL`) |
| **COGS (any channel)** | ❌ **NO** — no posting rule anywhere debits COGS | `seed_finance_posting_rules.php` (no `cogs` leg) |
| **Customer order payment (cash/bank/COD)** | ❌ **NO** — recorded only as `orders.deposit_amount` | `RecordOrderPaymentAction.php:57` |
| **COD trip settlement / driver shortage** | ❌ **NO** — sealed off in Logistics | zero Finance imports in `Modules/Logistics` |

The financial data is *present operationally* and thrown away at the boundary — most starkly, `ShipStockAction` builds an `inventory.stock.shipped` event carrying `extended_cost`/`posting_amount`, but that event is not in the bridge's catalog, so the sale-time inventory relief (the natural COGS trigger) is discarded.

### 1.3 The dimensional gap

The agreed model needs analysis by **Company, Brand, Channel, Warehouse, Shipping Operation, Department, Product, Order**. The ledger journal line carries only `company_id`, `branch_id`, `cost_center_id` live (with `profit_center_id`/`project_id`/`campaign_id` present-but-unused). **Brand, channel, warehouse, shipping-operation, department, product, and order are absent from the ledger.** They *do* exist on the operational order (`orders.channel_id`, `orders.assigned_warehouse_id`, and an immutable `order_financial_snapshots` with `brand_id`, `channel_id`, `total_cogs`, `actual_margin_percent`) — so the sources exist; only the flow into accounting is missing.

### 1.4 What is outright missing

Quick-expense entry · cost allocation to brands · warehouse cost centers · shipping-operation P&L · driver advances (as a receivable) · driver payroll/expense · intercompany/internal-transfer elimination · a unified Target/Expected/Forecast/Actual/Variance model.

### 1.5 Recommended shape of the execution track

**8 consolidated implementation tasks** (§22–23), sequenced so the ledger's integrity is preserved and no capability is duplicated. The spine is: **(1) add the analytic dimensions, (2) wire revenue + COGS recognition on Delivered through the existing bridge, (3) wire order-payment and COD cash into AR/cash, (4) add the internal Warehouse→Brand charge, (5) add expense + allocation, (6) brand/shipping-operation P&L reporting.** VAT/Tax is explicitly deferred and left exactly as-is.

---

## 2. Existing Finance Components

**STATUS: EXISTING (comprehensive).**

**CANONICAL SOURCE:** `backend/Modules/Finance/` registered by `Infrastructure/Providers/FinanceServiceProvider.php`.

The module is organised into bounded contexts by EPIC:

| EPIC | Contexts | Representative services | Commit (per project memory) |
|---|---|---|---|
| **F1 — Ledger Core & Fiscal** | `Ledger`, `Fiscal`, `Posting`, `Tax` | `JournalEngine`, `ChartOfAccountsService`, `TrialBalanceService`, `FiscalCalendarService`, `PostingCoordinator`, `PostingValidator` | `e7f3294` |
| **F2 — Subledgers** | `Receivables`, `Payables`, `Cash`, `Banking`, `Allocation`, `Shared` | `AccountsReceivableService`, `AccountsPayableService`, `CashService`, `BankingService`, `AllocationEngine`, `ControlAccountReconciliationService` | `f90daf7f` |
| **F3 — Integration** | `Integration` | `FinancialEventProcessor`, `PostingRuleResolver`, `RulePostingStrategy`, `AccountRoleResolver`, `EventPostingCatalog`, `EventPostingSubscriber`, `DeadLetterService`, `PostingTraceService` | `677c5fa6` |
| **F4 — Control/Closing/Budget** | `Closing`, `Budget`, `Vat`, `Controls` | `PeriodClosingService`, `YearEndClosingService`, `ClosingService`, `BudgetService`, `BudgetControlEngine`, `VatService`, `FinancialValidationEngine` | `247812f5` |
| **F5 — Intelligence** | `Analytics`, `Intelligence`, `Workspace`, `Reporting` | `FinancialMetricsService` (the kernel), `ForecastService`, `ProfitabilityService`, `CostIntelligenceService`, `CashFlowIntelligenceService`, `ScenarioEngine`, `ExecutiveReportingService` | `a4dce38b` |

**Activation state (verified):** the operational→ledger bridge is **live** — `config/finance.php:44` `'auto_subscribe' => env('FINANCE_AUTO_SUBSCRIBE', true)`, `'posting_mode' => 'queued'`; `FinanceServiceProvider::registerIntegrationSubscribers()` (`:183-208`) subscribes the bridge to the catalog's known event names on the `EnterpriseEventBus`. Idempotency receipts, dead-letters (365-day retention), retry/backoff, and SoD are all configured on.

**Provisioning:** the CoA + account roles + tax codes are **per-company** and delivered by `CompanyFinanceProvisioner` (over `ChartOfAccountsSeeder::seedCompany`), invoked **inside the company-creation transaction** (`CreateCompanyAction::execute` calls `$this->finance->provision($company->id)`) and idempotently backfillable via `php artisan finance:provision-companies`. Seeder-based (does not self-run on bare `migrate:fresh`).

**KEEP:** the entire module and its layering. **DO NOT REBUILD:** any of F1–F5.

---

## 3. Existing GL / Journal Architecture

**STATUS: EXISTING (robust, complete for double-entry).**

**CANONICAL SOURCE:** `Modules/Finance/Ledger/Domain/Services/JournalEngine.php` — *the one and only writer of `finance_journal_entries` / `finance_journal_lines`.*

| Capability | Present? | Evidence |
|---|---|---|
| Journal (header) | ✅ | `finance_journal_entries` (`2026_08_10_100006`): company, fiscal_period, entry_date, source (`manual`\|`posting`), `source_module`/`source_event_id`, status, maker/checker/poster |
| Journal lines | ✅ | `finance_journal_lines` (`2026_08_10_100007`): immutable, append-only, `decimal(20,4)` |
| Posting engine | ✅ | `Posting/PostingCoordinator` (idempotent, exactly-once receipt) + `PostingValidator` + `DirectPostingStrategy` |
| Account hierarchy | ✅ | `finance_accounts.parent_id`, `is_postable` (parents never postable) |
| Account types | ✅ | `AccountType` = asset/liability/equity/revenue/expense; `normal_balance` derived from type |
| Double-entry validation | ✅ | `JournalEngine::assertIntegrity()` — ≥2 lines, non-zero, Σdebit=Σcredit (`JournalEngine.php:236-278`) |
| Reversal | ✅ | `JournalEngine::reverse()` — mirror entry, links `reverses_journal_id`/`reversed_by_journal_id`; refuses double-reversal (`:137-195`) |
| Period locking | ✅ | `assertOpenPeriod()` — only an `open` `FiscalPeriod` accepts postings (`:295-304`) |
| Maker/checker (SoD) | ✅ | `submitDraft`/`approveAndPost`; `approveAndPost` throws `makerCannotApproveOwn` (`:104-130`); `config('finance.ledger.enforce_segregation_of_duties')` |
| Control-account guard | ✅ | a **manual** journal may not touch an `is_control` account (`:274-276`) — subledgers only |
| Audit trail | ✅ | append-only; posted entries immutable; F3 `finance_posting_audit` + trace |

**The three hard invariants** on every write: *balanced · postable/active accounts · open period.* A posted entry has **no update path anywhere in the platform** — correction is a reversing entry only.

**KEEP:** every invariant above; `JournalEngine` as sole writer. **DO NOT REBUILD:** the posting/validation/reversal path. **EXTEND (minor):** journal lines are already dimension-ready (`writeLines` carries `profit_center_id`/`project_id`/`campaign_id`) — see §5.

---

## 4. Existing Chart of Accounts

**STATUS: EXISTING (strong; conventionally structured; some anticipated accounts unused).**

**CANONICAL SOURCE:** `Modules/Finance/Infrastructure/Database/Seeders/ChartOfAccountsSeeder.php` (`definitions()` lines 46–211; `seedCompany()` 221–264). **101 accounts per company** (23 headers + 78 postable), bilingual, code blocks 1=asset · 2=liability · 3=equity · 4=revenue · 5=cost/expense. Hierarchy is inferred from the code prefix (`1310→1300→1000`).

**Reference accounts that matter for the sales cycle:**

| Purpose | Code | Account | Notes |
|---|---|---|---|
| Product revenue | **4110** | Product Sales | single revenue account for *all* channels today |
| POS / Online revenue | 4120 / 4130 | POS Sales / Online Sales | exist; **only 4110 is role-mapped** |
| **Shipping revenue** | **4140** | Shipping Revenue | **account exists, no role, never posted** |
| Sales returns/discounts | 4210 / 4220 | contra-revenue (debit-normal) | |
| **COGS** | **5100** (+5110/5120/5130) | Cost of Goods Sold / Material / Labour / Overhead | **accounts exist, no role, never posted** |
| Scrap / write-off / loss | 5150 / 5160 / 5170 | cost_of_sales | role-mapped |
| Inventory (control) | 1410/1420/1430/1440 | FG / RM / WIP / Packaging | control, subledger `inventory` (1400 is a non-postable header) |
| Trade receivables (control) | 1310 | AR control | |
| Trade payables (control) | 2110 | AP control | |
| GRNI | 2120 | Goods Received Not Invoiced | |
| Shipping payables | 2130 | carrier_payable | |
| Cash / Bank | 1110 / 1210 | Cash on Hand / Bank Current | |
| POS clearing | 1140 | pos_clearing | |
| **Customer deposits** | **2410** | Customer Deposits | **exists, unused** |
| **Deferred revenue** | **2420** | Deferred Revenue | **exists, unused** — the natural pre-delivery parking account |
| VAT input / output | 1530 / 2210 | control, subledger `vat` | |
| Payroll (control) | 2310–2340 | Salaries/Deductions/Contributions/Social Insurance | control, subledger `payroll` — **anticipated, no payroll engine** |

**Key observation:** the CoA already anticipates almost everything the agreed model needs — Shipping Revenue (4140), COGS (5100 family), Customer Deposits (2410), Deferred Revenue (2420), and payroll control accounts all exist. They are simply **not mapped to a posting role and never posted to** (see §6, §19). This is the difference between "the account exists" and "something posts to it."

**KEEP:** the whole chart. **DO NOT REBUILD / do not invent a new chart.** **EXTEND:** add account **roles** (not accounts) for `cogs`, `shipping_revenue`, `deferred_revenue`/`customer_deposit` where the model requires posting; optionally add brand/warehouse dimension typing (see §5). Any genuinely new account (e.g. an internal Warehouse→Brand clearing account) is a *small additive* seed, gap-filled by the idempotent provisioner.

---

## 5. Existing Financial Dimensions

**STATUS: PARTIAL — 3 of the 8 required dimensions flow to the ledger; the rest exist only operationally.**

**CANONICAL SOURCE (ledger):** `finance_journal_lines` (`2026_08_10_100007:41-51`) — dimension columns:

| Ledger dimension | State | Agreed-model role |
|---|---|---|
| `company_id` | **live** | Company ✓ |
| `branch_id` | **live** | (branch ≠ brand) |
| `cost_center_id` | **live** (`finance_cost_centers`, generic hierarchical tag) | could carry Warehouse / Department / Shipping-Op |
| `profit_center_id` | present, **unused** | could carry Brand (Brand = Profit Center) |
| `project_id` | present, **unused** | — |
| `campaign_id` | present, **unused** | could carry Channel/Campaign |

The `FinancialEvent` VO — the only shape operations speak to Finance in — exposes accessors for **only** `branch_id`, `cost_center_id`, and an opaque `inventory_class` token (`FinancialEvent.php:51-78`). Even if a module stuffed `brand_id` into the free-form `dimensions` array, nothing downstream reads it and no ledger column receives it.

**Mapping the agreed model to what exists:**

| Agreed dimension | In ledger? | Exists operationally? | Verdict |
|---|---|---|---|
| Company | ✅ `company_id` | ✅ | EXISTING |
| Brand (profit center) | ❌ | ✅ `order_financial_snapshots.brand_id`, `order_business_context_snapshots.brand_name` | MISSING in GL |
| Channel (revenue/analytics) | ❌ (4110 = all channels analytically) | ✅ `orders.channel_id` (FK `channels`), `order_financial_snapshots.channel_id` | MISSING in GL |
| Warehouse (cost center + inventory) | ❌ | ✅ `orders.assigned_warehouse_id` (FK `warehouses`) | MISSING in GL |
| Shipping Operation (cost/profit center) | ❌ | partial (trip/fleet operational) | MISSING |
| Department | ❌ (BudgetDimension has `department`, no ledger column) | — | MISSING |
| Product | ❌ | ✅ `order_line_snapshots` | MISSING in GL |
| Order (where applicable) | ❌ (journal has `source_event_id` string only, no `order_id`) | ✅ | MISSING in GL |

`ProfitabilityService::byUntaggedDimension()` (`ProfitabilityService.php:109-117`) is the ledger's own admission: for any dimension it doesn't tag, it returns `available: false` with the note *"The ledger does not tag journal lines by {dimension}."* Profitability exists only by branch/cost_center/project/customer — **never brand**.

**KEEP:** the multi-dimensional-line design (dimensions on the line, not the account — this is what keeps the CoA from exploding, per the migration's own ADR-F8 note). **DO NOT REBUILD:** the journal-line schema — `JournalEngine::writeLines` already persists all six columns. **EXTEND:** (a) decide the mapping — recommended: **Brand→`profit_center_id`**, **Warehouse/Department/Shipping-Op→`cost_center_id`** (typed cost centers), **Channel→`campaign_id`** or a new nullable `channel_id`; (b) teach the `FinancialEvent` VO + `RulePostingStrategy` to carry/read them; (c) have order-cycle publishers stamp them from the order. Product/Order granularity is better served by the **existing `order_financial_snapshots`** read-model than by exploding the ledger — keep GL at brand/channel/warehouse grain, drill to product/order via the snapshot.

---

## 6. Existing Revenue Recognition

**STATUS: PARTIAL — POS revenue posts; the delivery/COD channel recognises nothing in the GL; the operational expected/actual-margin layer exists but is GL-disconnected.**

**CANONICAL SOURCE (what posts):** `EventPostingCatalog::map()` (`EventPostingCatalog.php:62-157`) maps exactly **five** operational events; the only revenue-bearing one is `pos.sale.finalized` → rule `pos.sale` → `PostingCoordinator`/`JournalEngine`.

**The delivery path posts nothing to the GL:**
- `CompleteDeliveryWorkflow` sets `OrderStatus::Delivered` and emits `OrderDeliveredEvent`; its docblock *claims* "revenue recognition happens at this stage" — this is **misleading**.
- `HandleOrderDelivered.php:51-68` writes an audit row and publishes `order.delivered` to the **`BusinessEventBusService` (analytics BAE bus)** — *not* the finance bus. No `PostingCoordinator`/`JournalEngine` call exists on the delivery path.
- `BusinessEventType::DeliveryConfirmation` (`:56`) is annotated `// no GL by default`; `OrderConfirmation`/`Reservation`/`Cancellation` (`:43-45`) are all "no GL." There is **no `orders.delivered` GL event at all**.

**The operational expected/actual-margin layer (EXISTING, separate from GL):**

**CANONICAL SOURCE:** `order_financial_snapshots` (immutable, `OrderFinancialSnapshot` — updates silently rejected, deletes throw) + `order_line_snapshots` + `order_business_context_snapshots` + `orders.actual_cogs_amount`/`actual_margin_amount`/`actual_margin_percent`.

- Captured once at confirmation (`CreateOrderSnapshotService`, `OrderFinancialSnapshotAdapter`), it holds `subtotal`, `shipping_cost`, `total_cogs`, `gross_profit`, `target_margin_percent`, `actual_margin_percent`, `margin_status` (`within_target`\|`below_target`\|`above_target`), integrity hash, and the `brand_id`/`channel_id` dimensions.
- This **is** the agreed model's "Expected Revenue / Expected Cost / Expected Margin" (§3 of the brief) as an operational forecast — and it already distinguishes target vs actual margin. It simply never becomes an accounting posting.

So the model's rule — *"an order entering the system is NOT actual accounting revenue; revenue becomes actual on approved Delivered"* — is **half-honoured**: pre-delivery figures correctly live outside the GL (good), but the **post-delivery actual revenue posting is missing** (`2420 Deferred Revenue` / `2410 Customer Deposits` accounts exist but are never used).

**KEEP:** the order snapshot layer (it is the expected/forecast + drill-to-product truth, and ADR-020 already mandates reports read snapshots, not live products). **DO NOT REBUILD:** the snapshot mechanism, nor the POS revenue path. **EXTEND / REWIRE:** add a GL event `orders.delivered` (or map `shipping.delivery_confirmation`) → posting rule `Dr AR-control/Cash · Cr sales_revenue (+ Cr shipping_revenue 4140 for the shipping component)`; recognise at the approved Delivered state; guard against double-posting with POS (see §21).

---

## 7. Existing Payment Architecture

**STATUS: PARTIAL/CONFLICTING — three unlinked payment truths; none of the customer-facing ones reach the GL.**

There are **three parallel, unlinked** representations of "a customer paid":

| Layer | CANONICAL SOURCE | Holds | Posts to GL? |
|---|---|---|---|
| **Operational order payment** | `RecordOrderPaymentAction` → `orders.deposit_amount` (cumulative) + derived `PaymentState` (unpaid/partially_paid/paid) | a **single scalar**, no method, no reference, no value date | ❌ NO (`:57` updates deposit_amount; emits `OrderEvent` only) |
| **Payment proof** | `payment_proofs` (`2026_08_19_140000`), `UploadPaymentProofAction`/`VerifyPaymentProofAction` | an uploaded file + verify/reject state; **no amount, no method** | ❌ NO |
| **Distribution cash** | `distribution_payment_collections` (`PaymentCollection`) | `payment_type` (cash/bank_transfer/card/already_paid), amount, status, SoD collector≠verifier | ❌ NO |
| **Accounting cash/bank** | F2 `CashService` / `BankingService` → `finance_cash_transactions` / bank | posts a **balanced journal** on cash-in/out | ✅ YES (but manual, decoupled from orders) |

**"Where is the money?" — the model's central question — cannot be answered from the GL today**, because the customer-facing payment layers (order deposit, proof, distribution collection) never post to Cash (1110), Bank (1210), Card/POS clearing (1140), or AR (1310). The only GL cash movements are the **manual** F2 cash/bank transactions and POS sales — neither linked to an order.

- Payment *method* destinations the model lists (Company Cash, Bank, Card Clearing, InstaPay/transfer, COD/receivable/custody) have **accounts** (1110/1210/1140; `bank_transfer` maps conceptually to bank; COD→1310 or 2410) but **no posting path** binds a customer payment to them.
- The `PaymentFulfillmentGate` (`Modules/Commerce/Orders/Domain/Services/PaymentFulfillmentGate.php`) is the single, correct implementation of the payment→fulfilment *control* — keep it; it is not an accounting writer and should not become one.

**KEEP:** `orders.deposit_amount` as the operational payment scalar, `PaymentState` (derived), `PaymentFulfillmentGate`, the F2 `CashService`/`BankingService` GL writers. **DO NOT REBUILD:** the F2 cash/bank posting path. **EXTEND / REWIRE:** on a recorded order payment (and on COD verification), emit a financial event that posts the cash/bank/clearing leg against AR — so the customer payment lands in the GL at its true destination. This is the "where is the money" wiring.

---

## 8. Existing COD Architecture

**STATUS: EXISTING operationally · MISSING in accounting (the two layers are provably disconnected).**

**CANONICAL SOURCE (operational):** `SettlementService` (`Modules/Logistics/Distribution/Domain/Services/SettlementService.php`) over `distribution_trip_settlements` (`TripSettlement`, unique per trip) + `distribution_payment_collections` (`PaymentCollection`) + `delivery_cod_records` (`CodRecord`).

- `cash_expected` = Σ non-rejected **physical cash** collections (`:257`); `discrepancy` = `driver_cash_submitted − cash_expected` (`TripSettlement.php:77-84`). Bank/card tracked separately, not in the cash discrepancy.
- `finalize()` (`:186-212`): status→`Finalized`, Trip→`Closed`, dispatches `TripSettled` — **and that is the entire terminal action. No journal, no posting.** `TripSettled` has **zero listeners**.
- `delivery_cod_records` records door-side collection (`amount_due`/`amount_collected`/`method`/`status`, `shortfall()`); `CodCollected` event has **zero listeners**; it "performs NO settlement arithmetic and writes nothing to Finance" by design.

**Driver shortage / liability (model §5):**
- Cash shortage = `TripSettlement.discrepancy` (a decimal) + `isShort()`. **Never turned into a receivable or GL posting.**
- Goods liability = `distribution_trip_returns.driver_liable` (a **boolean**) + `discrepancy_qty`. **Never valued into money, never posted.**

So the model's requirement — *"an unexplained COD difference becomes Driver Liability / Driver Shortage and must NOT reduce Brand Revenue"* — is **structurally satisfied today only because none of it posts at all**: brand revenue can't be reduced by a shortage because brand revenue is never recognised in the first place. Once revenue-on-delivery is wired (§6), the shortage→driver-liability posting must be built alongside it, or the gap re-opens.

**Approved deductions (expenses/advances/fuel):** no expense engine and no driver-advance model exist (see §12, §13) — so these have no representation.

**KEEP:** `distribution_payment_collections` as the operational money SSOT (Directive D8 — "Distribution is the Single Cash Authority"), `SettlementService`, `TripSettlement`, `CodRecord`. **DO NOT REBUILD:** the settlement engine (per project memory a second one must never be built). **EXTEND / REWIRE:** add listeners on `TripSettled`/`CodCollected` that emit financial events → post `Dr Cash / Bank / Driver-Liability · Cr AR-control`, with the unexplained shortfall to a **Driver Shortage/Liability** account (a new role), never to revenue.

---

## 9. Existing Inventory / COGS Architecture

**STATUS: Inventory valuation EXISTING · COGS MISSING (the single largest accounting gap).**

**CANONICAL SOURCE (inventory quantity/valuation):**
- `stock_ledger_entries` (`StockLedgerEntry`, `Modules/Inventory/InventoryItems`) — the **canonical** append-only stock ledger; single write sink `EloquentInventoryItemRepository::recordEntry()`; 13 movement types. (A legacy `stock_movements` ledger is being consolidated into it — see §21.)
- FIFO valuation via `inventory_receipt_layers`; canonical cost via `EnterpriseCostEngine` (`Modules/CostManagement`, FIFO = Σ remaining_qty × landed_unit_cost).
- Perpetual GL postings exist for **goods receipt, transfer, adjustments, count gain/loss, write-off, supplier return** (`seed_finance_posting_rules.php:28-59`), addressing inventory by class via the `@inventory_class` sentinel (`RulePostingStrategy` resolves `raw_material`/`packaging_material`/`finished_good`).

**COGS — the gap:**
- **No posting rule anywhere contains a `cogs`/`cost_of_sales` leg.** Confirmed across `backend/Modules`. Accounts `5100` family exist; **no account role maps to them; nothing posts.**
- The sale-time inventory relief event exists and is **valued** — `ShipStockAction` → `InventoryStockShipped` carries `unitCost`/`extendedCost()` — but `eventName()='inventory.stock.shipped'` is **not in the catalog's five keys**, so it is silently discarded (`EventPostingSubscriber` records it unpostable). *"The financial data is right there and thrown away."*
- COGS **is** computed operationally: `DispatchOrderWorkflow` runs FIFO consumption and stamps `orders.actual_cogs_amount`, forwarded to `OrderDispatchedEvent`/`OrderDeliveredEvent` → the analytics BAE for period P&L — **never a `Dr COGS / Cr Inventory` journal.**

**Returns / negative stock (model §6):** returns and negative-stock semantics exist on the inventory side (`allow_negative_stock` governs on-hand); `ReceiveReturnWorkflow` restores stock. The **financial** reversal of a pending cost on cancel/return does not exist because the pending cost itself does not exist (see §11).

**Caveat (MTO):** per prior diagnosis (`TASK-MTO-MANUFACTURING-TRIGGER-GAP-DIAGNOSIS-001`), made-to-order manufacturing currently never fires, so the WIP→FG posting rules (`manufacturing.*`), though defined, are not exercised for MTO in practice. This is upstream of Finance and tracked separately; it means FG on-hand (and thus any FG-based COGS) has no data until that trigger is fixed.

**KEEP:** `stock_ledger_entries` as canonical, `EnterpriseCostEngine` (FIFO), the existing inventory posting rules and `@inventory_class` mechanism. **DO NOT REBUILD:** the inventory ledger, the cost engine, or the perpetual postings. **EXTEND / REWIRE (the prize):** map `inventory.stock.shipped` in the catalog → new rule `Dr COGS (5100) · Cr Inventory/FG (1410)` using the cost already on the event, recognised at Delivered in lockstep with revenue. This is a *wiring* of data that already exists, not new computation.

---

## 10. Existing Supplier / AP Architecture

**STATUS: EXISTING (strong).**

**CANONICAL SOURCE:** F2 `Payables` — `AccountsPayableService`, `SupplierLedgerService`, `ApAgingService`; tables `finance_supplier_bills`/`_lines`, `finance_supplier_payments`, `finance_payment_allocations`, `finance_supplier_ledger_entries`.

- **Purchase → GRN → invoice → AP → payment** chain is wired: `procurement.purchase_materials` posts `Dr GRNI · Dr VAT-input · Cr AP-control` (`seed_finance_posting_rules.php:62-66`); supplier return reverses it; `PostSupplierInvoiceService` is the invoice writer.
- `SupplierBillLine` carries `cost_center_id`; supplier payments carry `draft→approved→posted` with maker≠checker (`approvePayment` throws on self-approval).
- Supplier ledger balance / bill outstanding / aging are all **derived** (append-only entries + allocations), never stored.
- `AllocationEngine::allocatePayment`/`autoAllocatePayment` matches supplier payments to bills (FIFO oldest-due).

**Model §8 note:** "Supplier liability remains a Company liability while Warehouse is the cost dimension." Today AP is company-scoped (correct); the warehouse cost dimension on purchases is available only via `cost_center_id` on the bill line and is not yet used as a warehouse.

**KEEP / DO NOT REBUILD:** the AP subledger and procurement posting. **EXTEND:** stamp the warehouse dimension on GRNI/inventory legs when §5 lands.

---

## 11. Existing Warehouse Cost Architecture

**STATUS: MISSING (warehouse is not a Finance cost object; only supplier-inventory purchasing posts).**

- **Warehouse is not represented as a Finance cost center.** `finance_cost_centers` is a **generic** hierarchical tag (`code/name/parent_id/is_active`) with no warehouse/department typing; nothing seeds a warehouse as a cost center. "warehouse" appears in Finance only as a keyword in `CostIntelligenceService`'s logistics expense bucket (name classification, not a cost object).
- **Warehouse operating costs** (rent, labor, electricity, maintenance) have **no capture path** — there is no expense engine (§13) and no warehouse cost center to book them against.
- **Warehouse→Brand internal cost / pending position (model §6)** — the concept is entirely **MISSING**: there is no "pending internal cost" posting when inventory moves into brand/vehicle custody, no reversal on cancel/return, and no internal-charge account. The **vehicle-custody engine exists operationally** (quantity-only, keyed on `(assignment, product)`; `TransferLoadedStockToVehicleAction` posts the warehouse on-hand deduction) but carries **no financial position**.
- The only site-level cost ledger anywhere is **Fleet vehicle cost** (Logistics, per-vehicle) — see §12 — which is not per-warehouse and not a Finance cost center.

**KEEP:** `finance_cost_centers` (the vehicle for warehouse-as-cost-center), the canonical inventory deduction on custody transfer. **EXTEND (new):** (a) seed/allow warehouses as typed cost centers; (b) an **internal Warehouse→Brand charge**: on custody transfer post `Dr Brand pending inventory (internal) · Cr Warehouse inventory-out (internal clearing)`; convert pending→COGS on Delivered; reverse on cancel/return; mark both legs internal for elimination (§14/§21).

---

## 12. Existing Shipping / Driver Financial Architecture

**STATUS: MIXED — fleet cost EXISTING operationally (not in GL) · shipping cost rule EXISTING but unfed · shipping revenue MISSING · driver payroll/advances MISSING.**

**CANONICAL SOURCE (operational fleet cost):** `Modules/Logistics/Fleet` — `fleet_cost_entries` (`CostEntry`, append-only, reversing corrections) via `VehicleCostService`; `fleet_fuel_transactions`/`fleet_fuel_cards` via `FuelReconciliationService`. Cost types include fuel and monthly depreciation accrual.

| Capability | Status | Evidence |
|---|---|---|
| Fleet/vehicle cost + fuel ledger | **EXISTING (operational)** | `VehicleCostService`, `FuelReconciliationService` |
| Fleet cost → GL | **MISSING** | `VehicleCostPosted`/`FuelTransactionRecorded` have **zero listeners**; "onward to Accounting" is an unimplemented comment (`VehicleCostService.php:17-25,166`) |
| Shipping **cost** (3rd-party carrier) | **PARTIAL** — GL rule exists, no operational emitter | rule `shipping.shipment_cost`→`Dr 5550 · Cr 2130` (`seed:120-131`); **no catalog mapping**; manual trigger only via `PostingIntegrationController` |
| Shipping **revenue** (customer charge) | **MISSING in GL** | `orders.shipping_total`/`shipping_cost` operational; `4140 Shipping Revenue` unused; regular orders never post revenue |
| Shipping Operation as P&L / cost center | **MISSING** | no shipping-operation entity/dimension in Finance |
| Driver salaries / payroll | **MISSING** | no `driver_salary`/`driver_payment` engine |
| Driver expenses | **MISSING** | no expense engine (§13) |
| **Driver advances** (as receivable) | **MISSING** | only HR `hr_advances` (employee-scoped); drivers not linked to HR employees; `AdvanceService` posts no GL |
| Driver-app settlement endpoints | **FROZEN (403)** | `DriverRuntimeController::frozen()` — 4 money routes frozen by design; settlement is operator-only |

**Model §12/§13 gaps:** the model wants Shipping Operation to have its **own P&L**, driver costs booked to Shipping Operation (not deducted from Brand merely because a driver served a brand order), and a driver advance treated as a **receivable** (not an expense) reconciled at settlement. **None of this exists financially today.** The operational primitives (fleet cost ledger, trip settlement, COD) exist; the accounting representation and the shipping-operation dimension do not.

**KEEP:** `fleet_cost_entries`/fuel ledgers (operational cost SSOT), operator-only settlement, the existing `shipping.shipment_cost` rule. **DO NOT REBUILD:** the fleet cost ledger. **EXTEND (new):** a Shipping-Operation cost/profit center dimension; wire fleet cost + shipping cost + driver cost to the GL under it; model shipping revenue (4140); a **driver-advance receivable** model reconciled at settlement.

---

## 13. Existing Expense Architecture

**STATUS: MISSING (no operational expense entry; AP bills are the only vendor-invoice path).**

- **No `Expense` model, no `expenses` table, no expense controller/route, no "quick expense" flow** anywhere in `backend/Modules` (verified: `**/*Expense*.php` = 0; `quick.?expense|record.?expense|createExpense` = 0 matches).
- Every "expense" in the codebase is one of: a GL **expense account** (`AccountCategory::OperatingExpense`/`OtherExpense`), **Fleet/fuel** cost (Logistics), or a **supplier bill** (AP).
- **Nearest primitive:** `CashService::recordTransaction()` (`CashService.php:100-159`) records a cash-out and auto-derives the balanced journal (`Dr counterparty · Cr cash`) — but it takes a **raw GL account id, not an expense type**, and passes **no cost-center/dimension**. It is the plumbing for a quick-expense flow, not the flow.
- **AP (`SupplierBill`/`AccountsPayableService`)** is real vendor-invoice entry with `cost_center_id` support — but it is AP, not fast operational expense capture.

**Model §14 requirement** ("expense type + amount + date + payment account + cost center/dimension + attachment → system derives the posting; no manual Dr/Cr") is **entirely MISSING** as a product surface, though the posting primitive (`CashService` + `PostingCoordinator`) and the dimension columns exist to build it cleanly.

**KEEP:** `CashService::recordTransaction` (the posting primitive), AP bills. **EXTEND (new):** an **Expense Type → posting template** registry (reuse the F3 posting-rule/role machinery — expense type is just another rule), a thin `expenses` capture table (type/amount/date/payment-account/dimension/description/attachment), and a controller that maps type→rule→`PostingCoordinator`. Do **not** invent a second posting engine.

---

## 14. Existing Allocation Architecture

**STATUS: CONFLICTING NAME — the existing "Allocation" is AR/AP cash-application, NOT cost allocation. Shared-cost allocation to brands is MISSING.**

- **CANONICAL SOURCE (what exists):** `Modules/Finance/Allocation/Domain/Services/AllocationEngine.php` — its own header: *"matches money to the documents it settles… never posts a journal."* Methods `allocateReceipt`/`autoAllocateReceipt` (AR) and `allocatePayment`/`autoAllocatePayment` (AP) write only `ReceiptAllocation`/`PaymentAllocation` rows. **This is subledger cash-matching. It has nothing to do with overhead allocation.**
- **Shared-cost / overhead allocation to Brands with a configurable basis (Revenue% / Orders% / Units% / …)** — model §9/§10/§11 — is **MISSING**. No allocation-basis, allocation-rule, apportionment, or driver-based engine exists; and it *cannot* exist yet because the **brand dimension is absent from the ledger** (§5).
- The one adjacent read-model is `CostIntelligenceService::operationalClassification()` — keyword-buckets expense accounts into manufacturing/logistics/marketing/administrative for reporting. Not allocation.

**KEEP:** `AllocationEngine` for AR/AP matching (and **rename references in planning docs** to avoid confusing it with cost allocation). **EXTEND (new):** a **Cost Allocation engine** — allocation rules (source cost center/account set → target brands, basis = revenue/orders/units/manual %), run per period, posting internal `Dr Brand allocated-cost · Cr Shared-cost-pool` (internal, elimination-marked). Depends on §5 (brand dimension) landing first.

---

## 15. Existing Budget / Forecast Architecture

**STATUS: EXISTING (budget + forecast) · PARTIAL (unified target/actual/variance model).**

- **Budget — EXISTING.** `BudgetService` (create with version + scenario, `addLine` per `BudgetDimension` + period, `approve` maker≠checker, `cloneAsVersion`); `BudgetControlEngine` (budget-vs-actual, alerts, availability = budget−actual−committed, `evaluate` → ok/warn/blocked); real **commitments** (`finance_budget_commitments`). Actuals aggregated live from the ledger. `BudgetDimension` = company/department/branch/cost_center/project. Never writes the ledger.
- **Forecast — EXISTING (deterministic, no AI).** `ForecastService` — OLS linear regression (`linear_least_squares`) for revenue/expense/profit; straight-line run-rate for budget. Explainable-only.
- **Target / Expected / Forecast / Actual / Variance (model §15) — PARTIAL.** Multiple un-unified forms exist: budget variance (`VarianceAnalysisService`: `within_budget`/`approaching_limit`/`over_budget`), KPI scorecard target (`ExecutiveReportingService`: `on_target`/`below_target`), cash-session variance, BS variance. **No single comparison model** across Target/Expected/Forecast/Actual, and **no named three-tier "On Target / Attention / Critical"** performance status (the word "Critical" exists only as a *control-exception severity*, unrelated).

**KEEP / DO NOT REBUILD:** `BudgetService`, `BudgetControlEngine`, `ForecastService`, `VarianceAnalysisService`. **EXTEND:** a thin unifying read-model that places Target vs Expected vs Forecast vs Actual side by side per dimension/period and derives an On-Target/Attention/Critical status — composed over the existing services, writing nothing.

---

## 16. Existing Reporting

**STATUS: EXISTING (read-only) · PARTIAL vs the agreed model (no brand/channel/shipping-op P&L).**

- **CANONICAL SOURCE:** F5 `FinancialMetricsService` (the single kernel for every derived number) + `FinancialStatementService`, `ExecutiveReportingService`, `FinancialDashboardService`, `CfoWorkspaceService`, `CashFlowIntelligenceService`.
- **Exists:** P&L (gross/operating/net + margins), Balance Sheet (+ working capital, current ratio), Trial Balance, Cash Position, AR/AP (via control reconciliation), profitability by branch/cost_center/project/customer, monthly P&L series, forecasts, scenarios, health score, executive/CFO workspaces, immutable report snapshots.
- **Cash Flow:** schedules derived from F2 AR/AP aging (indirect/rule-based), not a posted cash-flow statement.
- **Missing vs model:** **Brand P&L, profitability by brand/channel/segment, Shipping-Operation P&L, warehouse cost reporting** — all blocked by the §5 dimension gap (the kernel honestly returns `available:false` for untagged dimensions).

**KEEP / DO NOT REBUILD:** the metrics kernel and all statement services. **EXTEND:** once §5 lands, add brand/channel/warehouse/shipping-op cuts to the existing kernel (it already signs by normal balance and groups by dimension) — no new reporting engine.

---

## 17. Existing Fiscal Closing

**STATUS: EXISTING (robust; closely matches the agreed model §16).**

**CANONICAL SOURCE:** `Fiscal` (`FiscalPeriod`, `PeriodStatus`, `FiscalCalendarService`) + F4 `Closing` (`PeriodClosingService`, `YearEndClosingService`, `ClosingService`, `CloseReadinessScorer`, `ClosingWorkspaceService`).

| Model §16 requirement | Present? | Evidence |
|---|---|---|
| Open periods | ✅ | `PeriodStatus::Open` — the only status accepting postings |
| Closing readiness | ✅ | `CloseReadinessScorer` + `ClosingService` validate-before-close (maker/checker) |
| Period close | ✅ | `PeriodClosingService::softClose` (open→closed, reversible) |
| Locked periods | ✅ | `hardClose` (closed→locked, permanent, requires prior soft close) |
| Controlled reopening | ✅ | `reopen` (closed→open, authorised; **never** for locked) |
| Adjustments/reversals after close | ✅ | reopen for late adjustments; posted entries corrected only via reversing entries |
| Audit trail | ✅ | append-only `finance_period_closures` (`PeriodClosure`) governance log |
| Close must not modify history | ✅ | posted entries immutable; closing orchestrates F1 transitions, never edits ledger |
| Year-end | ✅ | `YearEndClosingService` — P&L sweep to retained earnings + carry-forward pair (idempotent, reversible) |

**KEEP / DO NOT REBUILD:** the entire closing/period/year-end machinery — it is complete and correct. **EXTEND:** none required for the model; ensure new posting paths (revenue/COGS/COD) respect the open-period gate (they will — they go through `JournalEngine`).

---

## 18. Existing Frontend Finance Screens

**STATUS: EXISTING (10 screens under `/accounting`) · MISSING model-specific surfaces.**

**CANONICAL SOURCE:** `frontend/src/features/finance/`, mounted at `/accounting` (`router.ts:250-259`), 10 sidebar items (`module-navigation.ts:362-377`).

| Screen | Route | Read/Write |
|---|---|---|
| Executive (CFO dashboard) | `/accounting` | Read-only |
| Chart of Accounts | `/accounting/chart-of-accounts` | create account, activate/deactivate (no edit) |
| Journal Entries | `/accounting/journals` | create, post/approve, discard, reverse |
| Financial Statements (TB / BS / IS) | `/accounting/statements` | Read-only |
| Accounts Receivable | `/accounting/receivables` | Read-only |
| Accounts Payable | `/accounting/payables` | Read-only |
| Cash & Banking | `/accounting/cash-banking` | create accts, sessions, transactions, transfer, reconcile |
| Fiscal Closing | `/accounting/fiscal-closing` | year/period open/close/lock/reopen, closing runs, year-end |
| Budgets | `/accounting/budgets` | create/version/approve, evaluate/commit, control rules |
| Tax / VAT | `/accounting/tax-vat` | VAT periods/returns/settle, tax categories (tax codes read-only) |

**Absent screens (model-relevant):** expenses/quick-expense · cost allocation · brand P&L · profitability by brand/channel · revenue recognition. Driver/COD settlement UI exists but is **operational** (`features/operations/driver-settlement/`, read-only rollup over `/logistics/distribution/driver-settlement`), not a finance screen and not GL-posting.

**Documented API contract gaps (already in code):** no GL account-ledger/running-balance endpoint (only trial-balance + journals-by-status); journal approval is single-step (no reject/submit/history); no account update endpoint; AR/AP expose only `customer_id`/`supplier_id` (no names — Finance↔CRM boundary); no cash-transaction or reconciliation LIST endpoints; tax codes read-only (numeric-id write contract).

**KEEP / DO NOT REBUILD:** all 10 screens. **EXTEND:** new screens for expenses, allocation, and brand/shipping-op P&L follow the backend work; wire the already-built-but-unused AR/AP control-reconciliation hooks.

---

## 19. Canonical Writer Map

*The one authoritative writer per financial event. "GL?" = does it post to `finance_journal_*` via `JournalEngine`.*

| Financial event | Canonical writer (file) | GL? | Status |
|---|---|---|---|
| **Any GL journal** | `JournalEngine` (`Ledger/Domain/Services/JournalEngine.php`) — **SOLE writer** | — | EXISTING |
| Operational event → journal | `FinancialEventProcessor`→`RulePostingStrategy`→`PostingCoordinator` | ✅ | EXISTING |
| POS sale revenue | POS `ProcessSaleService` → `pos.sale.finalized` → rule `pos.sale` | ✅ | EXISTING |
| POS COGS | — | ❌ | **MISSING** |
| Inventory goods receipt | `ReceiveStockAction` → `inventory.stock.received` | ✅ | EXISTING |
| Inventory transfer / adjustment | `TransferStockAction` / `AdjustmentIn/OutAction` | ✅ | EXISTING |
| **Inventory ship (sale relief / COGS)** | `ShipStockAction` → `inventory.stock.shipped` | ❌ | **MISSING (event unmapped — valued payload discarded)** |
| Manufacturing WIP/FG | rules `manufacturing.*` exist | ✅ rule / ⚠️ emitter | PARTIAL (MTO trigger gap upstream) |
| Supplier invoice / AP | `PostSupplierInvoiceService` / `AccountsPayableService` → `procurement.purchase_materials` | ✅ | EXISTING |
| **Order-delivery revenue** | `HandleOrderDelivered` → analytics BAE bus | ❌ | **MISSING** |
| **Order-delivery COGS** | (`orders.actual_cogs_amount` stamped, analytics only) | ❌ | **MISSING** |
| **Customer order payment** | `RecordOrderPaymentAction` → `orders.deposit_amount` | ❌ | **MISSING (no GL cash/AR)** |
| F2 customer invoice revenue | `AccountsReceivableService::postDocument` (manual, no order link) | ✅ | EXISTING (decoupled) |
| COD collection | `PaymentCollection` / `CodRecord` (Distribution) | ❌ | MISSING (GL) |
| Trip cash settlement | `SettlementService.finalize` → `TripSettled` (no listener) | ❌ | MISSING (GL) |
| Driver shortage / liability | `TripSettlement.discrepancy` / `driver_liable` | ❌ | MISSING (GL) |
| Fleet / fuel cost | `VehicleCostService` → `fleet_cost_entries` (`VehicleCostPosted` no listener) | ❌ | MISSING (GL) |
| Cash / bank transaction | F2 `CashService` / `BankingService` | ✅ | EXISTING (manual) |
| VAT settlement | `VatService` → `PostingCoordinator` | ✅ | EXISTING |
| Period close / lock / reopen | `PeriodClosingService` (governance) | — | EXISTING |
| Year-end close | `YearEndClosingService` → `PostingCoordinator` | ✅ | EXISTING |
| Order financial/margin snapshot | `CreateOrderSnapshotService` / `OrderFinancialSnapshotAdapter` → `order_financial_snapshots` (immutable) | ❌ | EXISTING (operational read-model) |

**Rule (must hold going forward):** every new financial event gets exactly **one** canonical writer, and it posts to the GL **only** through `JournalEngine`. No operational module ever writes `finance_journal_*` directly.

---

## 20. EXISTING → KEEP → EXTEND → REWIRE → MISSING Matrix

| Capability | STATUS | KEEP (do not rebuild) | EXTEND / REWIRE / MISSING |
|---|---|---|---|
| General Ledger (journal engine, invariants, reversal, periods) | EXISTING | `JournalEngine`, `PostingCoordinator` | — |
| Chart of Accounts (101, per-company, bilingual) | EXISTING | `ChartOfAccountsSeeder`, provisioner | EXTEND: add roles for cogs/shipping_revenue/deferred_revenue; small new internal-clearing/driver-liability accounts |
| Subledgers AR/AP/Cash/Bank | EXISTING | F2 services | — |
| Posting bridge (event→rule→journal) | EXISTING (live) | F3 engine, catalog, dead-letters | EXTEND: add catalog mappings + rules for delivery/COGS/COD |
| POS revenue | EXISTING | POS accounting adapter | — |
| Inventory valuation + perpetual postings | EXISTING | `stock_ledger_entries`, `EnterpriseCostEngine`, inventory rules | — |
| **Revenue on Delivered** | MISSING | order snapshot layer | REWIRE: `orders.delivered` → revenue rule |
| **COGS** | MISSING | cost already on `inventory.stock.shipped` | REWIRE: map the event → COGS rule |
| **Customer payment → GL (cash/bank/AR)** | MISSING | `orders.deposit_amount`, F2 CashService | REWIRE: payment event → cash/AR posting |
| **COD → GL + driver shortage/liability** | MISSING (GL) | `SettlementService`, collections (SSOT) | REWIRE: `TripSettled`/`CodCollected` listeners → posting |
| Financial dimensions (brand/channel/warehouse/shipping/dept/product/order) | PARTIAL | journal-line schema (dimension-ready), order snapshots | EXTEND: map brand→profit_center, warehouse/dept/ship-op→cost_center, channel→campaign/new; carry through VO + rules |
| **Warehouse→Brand internal cost / pending position** | MISSING | vehicle-custody engine, canonical deduction | EXTEND: internal charge posting + convert-to-COGS + reverse-on-return |
| Warehouse operating costs / cost centers | MISSING | `finance_cost_centers` | EXTEND: warehouses as typed cost centers + expense capture |
| Supplier / AP | EXISTING | F2 payables | EXTEND: warehouse dimension on GRNI |
| **Shipping revenue / cost separation + Shipping-Op P&L** | MISSING (rev) / PARTIAL (cost) | `4140`, `shipping.shipment_cost` rule, fleet cost ledger | EXTEND: shipping-op dimension; feed cost rule; model shipping revenue |
| **Driver advances (receivable)** | MISSING | HR advance pattern (reference only) | MISSING → build driver-advance receivable |
| Driver payroll / expense | MISSING | — | MISSING (out of core scope; sequence late) |
| **Quick-expense entry** | MISSING | `CashService.recordTransaction`, F3 rules | EXTEND: expense-type→rule registry + capture UI |
| **Cost allocation to brands** | MISSING | (rename F2 AllocationEngine to avoid confusion) | MISSING → build allocation engine (after dimensions) |
| Budget | EXISTING | `BudgetService`, `BudgetControlEngine` | — |
| Forecast | EXISTING | `ForecastService` | — |
| Target/Expected/Forecast/Actual/Variance unified + On-Target/Attention/Critical | PARTIAL | variance/KPI services | EXTEND: unifying read-model + 3-tier status |
| Reporting (P&L/BS/TB/cash/AR/AP/profitability) | EXISTING | F5 kernel + statement services | EXTEND: brand/channel/ship-op cuts after dimensions |
| **Intercompany / internal-transfer elimination** | MISSING | — | EXTEND: `is_internal` marker + consolidated elimination |
| Fiscal periods / closing / year-end | EXISTING | F4 closing | — |
| VAT / Tax | EXISTING | F1 Tax + F4 Vat | **DEFERRED — do not touch** |
| Finance frontend (10 screens) | EXISTING | `features/finance` | EXTEND: expenses, allocation, brand P&L screens |

---

## 21. Conflicts / Duplications / Architectural Risks

1. **Duplicate-revenue risk (latent, HIGH once delivery revenue is added).** A POS sale posts revenue via `pos.sale` **and** creates an ERP order (`PosSaleOrderListener`). If an `orders.delivered` revenue rule is added naively, POS-originated orders could be recognised **twice**; a manual F2 invoice for the same order would be a third. The engine's idempotency is keyed per source-event-id and will **not** catch cross-path duplication. **Mitigation:** the delivery revenue rule must exclude POS-originated orders (channel/source guard) and the F2 manual-invoice path must remain the exception, not the norm — one canonical revenue writer per sale.

2. **Two inventory ledgers mid-consolidation.** `stock_ledger_entries` (canonical) vs `stock_movements` (legacy, 2 remaining writers). This is an inventory-quantity concern, not GL, but any COGS wiring must read the **canonical** ledger/cost engine, and the GL inventory accounts must reconcile to it. **Do not** let the inventory ledger and GL diverge as competing truths (model rule §4).

3. **Misleading "revenue recognition" docblocks.** `CompleteDeliveryWorkflow`/`HandleOrderDelivered` comments claim revenue is recognised at delivery; it is only sent to analytics. This will mislead implementers — treat the code, not the comment, as truth (this report does).

4. **Stale "off by default" comments.** `EventPostingSubscriber` docstring says the bridge is off by default; `config/finance.php:44` defaults it **on**. Harmless (config wins) but confusing.

5. **Operational cash sealed from accounting by design (D8).** "Distribution is the Single Cash Authority" is a deliberate boundary. The fix is **not** to breach it but to add a **one-directional** event bridge (Distribution emits → Finance posts), preserving Distribution as the operational SSOT and Finance as the accounting SSOT.

6. **"Allocation" name collision.** F2 `AllocationEngine` (cash-application) vs the model's cost allocation. Planning must not conflate them or someone will "extend" the wrong engine.

7. **Order-status ≠ accounting.** `Order.status` is guarded (written only by the FulfillmentEngine). The delivery→revenue posting must hang off the **event** at the approved Delivered state, not make `Order.status` the accounting trigger (model rule §3).

8. **Anticipated-but-unused accounts.** 2410/2420/4140/5100-family and payroll control accounts exist with no writers — a reviewer could mistake their existence for working capability. They are scaffolding, not function.

---

## 22. Recommended Minimal Implementation Sequence

Ordered so each step unblocks the next and no capability is duplicated:

1. **Dimensions first (foundation).** Decide the dimension mapping (Brand→`profit_center_id`, Warehouse/Department/Shipping-Op→typed `cost_center_id`, Channel→`campaign_id`/new `channel_id`); extend `FinancialEvent` + `RulePostingStrategy` to carry/read them; have order-cycle publishers stamp them. *Nothing meaningful about brand/warehouse P&L can post until this lands.*
2. **Revenue + COGS on Delivered (the core).** Add `orders.delivered` financial event (or map `shipping.delivery_confirmation`) + rules for revenue (incl. shipping-revenue leg) and COGS (map the already-valued `inventory.stock.shipped`). Recognise at the approved Delivered state; guard against POS double-post; use `2420 Deferred Revenue`/`2410 Customer Deposits` for pre-delivery where required.
3. **Customer payment + COD → GL.** Wire order-payment recording and `TripSettled`/`CodCollected` to post cash/bank/clearing against AR, with the unexplained shortfall to a **Driver Shortage/Liability** account (never revenue).
4. **Warehouse→Brand internal charge.** On custody transfer post the internal pending-cost position; convert to COGS on Delivered; reverse on cancel/return; mark internal legs for elimination.
5. **Expense capture + expense-type→rule registry.** Reuse F3 rules; add the capture table + quick-entry surface (no new engine).
6. **Cost allocation to brands.** Allocation rules (basis = revenue/orders/units/manual %) posting internal charges; depends on step 1.
7. **Shipping-Operation P&L + fleet/driver cost to GL + driver advances.** Shipping-op dimension; feed fleet/shipping/driver cost; driver-advance receivable reconciled at settlement.
8. **Reporting + unified Target/Actual/Variance + elimination.** Brand/channel/shipping-op P&L cuts over the F5 kernel; the unified performance read-model with On-Target/Attention/Critical; consolidated internal-transfer elimination.

Throughout: **VAT/Tax untouched**; every posting via `JournalEngine`; one canonical writer per event; internal vs external legs distinguished.

---

## 23. Proposed Number of Execution Tasks (after consolidation)

**8 tasks** (mapping 1:1 to §22), which is the natural minimum that keeps each task independently reviewable and preserves ledger integrity:

| # | Task | Depends on |
|---|---|---|
| **FIN-EXEC-01** | Ledger analytic dimensions (brand/channel/warehouse/dept/shipping-op) end-to-end | — |
| **FIN-EXEC-02** | Revenue + COGS recognition on Delivered (with POS-dedup guard) | 01 |
| **FIN-EXEC-03** | Customer payment + COD/settlement → GL (cash/AR/driver-liability) | 02 |
| **FIN-EXEC-04** | Warehouse→Brand internal cost (pending position, convert-to-COGS, reversal) | 01, 02 |
| **FIN-EXEC-05** | Quick-expense capture + expense-type→posting-rule registry | 01 |
| **FIN-EXEC-06** | Cost allocation to brands (configurable basis) | 01, 05 |
| **FIN-EXEC-07** | Shipping-Operation P&L + fleet/driver cost to GL + driver-advance receivable | 01, 03 |
| **FIN-EXEC-08** | Brand/channel/ship-op reporting + unified Target/Actual/Variance + internal-transfer elimination | 01–07 |

(If capacity is tighter, 05+06 can merge and 07+08 can merge → **6 tasks**; the spine 01→02→03→04 should not be merged, as each is a distinct posting contract.)

---

## 24. Explicitly Deferred Items

- **VAT / Tax** — per the brief, DEFERRED. VAT already exists (F4 `VatService`, roles `vat_input`/`vat_output`, accounts 1530/2210, and VAT legs already post on `procurement.purchase_materials` and `pos.sale`). **Do not redesign, do not extend, do not remove.** New revenue rules should carry a VAT-output leg only where the existing tax model already dictates, using existing roles.
- **Driver payroll / full driver-expense engine** — out of the accounting core; sequence late (part of FIN-EXEC-07's cost feed, but full payroll is its own track).
- **MTO manufacturing trigger** — the WIP→FG posting rules exist but the upstream manufacturing trigger is broken (separate diagnosis `TASK-MTO-MANUFACTURING-TRIGGER-GAP-DIAGNOSIS-001`); FG-based COGS depends on it but the fix is not a Finance task.
- **Inventory ledger consolidation** (`stock_movements`→`stock_ledger_entries`) — a separate approved-but-unstarted track; COGS wiring must read the canonical side.
- **Multi-entity consolidation beyond internal-transfer elimination** — only the elimination marker is in scope (FIN-EXEC-08); a full consolidation module is not.

---

## 25. Final Architecture Lock Candidate

The following are proposed as **locked invariants** for the entire Finance execution track. They encode both the existing architecture and the model's rules, and are the acceptance frame for every subsequent task.

1. **One GL writer.** `JournalEngine` is the sole writer of `finance_journal_*`. Every posting goes through `PostingCoordinator`→`JournalEngine`. No operational module writes the ledger.
2. **One canonical writer per financial event.** No event is posted by two paths. New recognition (delivery revenue/COGS) must guard against the POS and manual-invoice paths.
3. **Accounting is event-driven, not status-driven.** Postings hang off business events at the correct lifecycle point (approved Delivered for revenue/COGS), never off `Order.status` directly.
4. **Ledger and inventory ledger reconcile, never compete.** GL inventory accounts derive from canonical `stock_ledger_entries`/`EnterpriseCostEngine`; COGS reads that cost.
5. **Expected ≠ Actual.** Pre-delivery expected revenue/cost/margin stay in the operational `order_financial_snapshots` read-model; only the approved Delivered state posts actual revenue/COGS.
6. **Internal ≠ External.** Warehouse→Brand and Shipping-Op→Brand movements are internal charges, marked for elimination; they never appear as external Company revenue.
7. **Driver advances are receivables, not expenses;** unexplained COD shortfalls are Driver Liability/Shortage, **never** a reduction of brand revenue.
8. **Dimensions live on the journal line,** not the account; Brand=profit center, Warehouse=cost center, Shipping-Op=cost/profit center, Channel=analytic — carried by the `FinancialEvent` VO through the rules.
9. **Expenses derive their posting from an expense type** via the existing F3 rule/role machinery; users never enter Dr/Cr.
10. **Period gate is absolute.** Every posting respects the open-period rule; closing never edits history (reversal-only).
11. **VAT/Tax is frozen** for this track — existing behaviour preserved, not extended.
12. **Additive only to F1–F5.** The execution track REWIRES and EXTENDS; it does not refactor or rebuild the existing Finance OS.

---

## Appendix A — Final answers to the brief's closing questions

**A. What is already DONE (reuse unchanged):** the entire double-entry Finance OS — GL/`JournalEngine`, per-company 101-account CoA + provisioner, AR/AP/Cash/Banking subledgers, the live event→rule→journal bridge, POS-revenue and inventory/procurement/VAT postings, fiscal periods + soft/hard close + reopen + year-end, budgets, forecasting (OLS), controls, the F5 read-only intelligence/reporting kernel, and 10 finance UI screens.

**B. What remains to be implemented:** revenue-on-Delivered, COGS, customer-payment/COD→GL, the analytic dimensions (brand/channel/warehouse/shipping-op/department/product/order), Warehouse→Brand internal cost, quick-expense entry, cost allocation to brands, Shipping-Operation P&L + fleet/driver cost to GL, driver-advance receivable, unified Target/Actual/Variance + On-Target/Attention/Critical, and internal-transfer elimination. (See FIN-EXEC-01…08.)

**C. What can be reused unchanged:** `JournalEngine`, `PostingCoordinator`, `PostingRuleResolver`/`RulePostingStrategy`/`AccountRoleResolver`, `EventPostingCatalog`/`EventPostingSubscriber`, the CoA + provisioner, F2 subledgers + `AllocationEngine` (cash-application), F4 closing/budget, F5 metrics kernel + statement services, and `order_financial_snapshots`.

**D. What must be extended:** the journal-line dimensions (populate the spare columns + add channel), the posting-rule/role set (cogs, shipping_revenue, deferred_revenue, driver-liability, internal-charge), the `FinancialEvent` VO, the catalog mappings, and the F5 kernel's dimensional cuts.

**E. What should be removed/deprecated:** **nothing material.** Do **not** delete the anticipated-but-unused accounts (2410/2420/4140/5100-family/payroll) — they are the scaffolding the execution track fills. Only cosmetic cleanup is warranted: stale docblocks ("revenue recognition happens here", "off by default") and stale nav comments; and planning docs should rename references to F2 `AllocationEngine` so it is not confused with cost allocation.

**F. Recommended consolidated execution plan:** **8 tasks** (FIN-EXEC-01…08, §23), spine = dimensions → revenue+COGS → payment/COD → internal cost, then expense/allocation → shipping-op/driver → reporting/variance/elimination. Compressible to 6 if needed; the 01→02→03→04 spine stays distinct.

---

*End of report. No code, schema, data, or configuration was modified in producing it. The next execution task is to be decided after this report is reviewed.*
