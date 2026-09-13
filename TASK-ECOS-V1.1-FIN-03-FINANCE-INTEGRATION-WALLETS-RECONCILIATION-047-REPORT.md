# TASK-ECOS-V1.1-FIN-03 — Finance Integration + Wallets Reconciliation

**Mode:** Read-only architecture & capability reconciliation (no agents, no background tasks, no DB, no implementation)
**Worktree:** `E:\ECOS\ECOS-NEXT-FIN`
**Branch:** `feature/finance-v1.1`
**Verified HEAD:** `bfa3797a2b9d1bc23e84b34c5f3fae70f686f49d`

---

## STATUS

**FIN-03 RECONCILED**

---

## FINANCE AUTHORITIES (preserve — canonical, mature, not to be rebuilt)

| Authority | File(s) |
|---|---|
| Journal Engine (sole GL writer) | `Ledger/Domain/Services/JournalEngine.php` |
| Chart of Accounts | `Ledger/Domain/Services/ChartOfAccountsService.php`, `Ledger/Domain/Models/Account.php` |
| Accounts Receivable | `Receivables/Domain/Services/AccountsReceivableService.php`, `CustomerLedgerService.php`, `ArAgingService.php`, `CustomerOpeningBalanceService.php` |
| Accounts Payable | `Payables/Domain/Services/AccountsPayableService.php`, `SupplierLedgerService.php`, `ApAgingService.php`, `SupplierOpeningBalanceService.php` |
| Cash / Banking | `Cash/Domain/Services/CashService.php`; `Banking/Domain/Services/BankingService.php`, `BankReconciliationService.php` |
| Posting bridge | `Posting/Domain/Services/PostingCoordinator.php`, `PostingValidator.php`; `Integration/Domain/Services/FinancialEventProcessor.php`, `RulePostingStrategy.php`, `PostingRuleRegistry.php`, `PostingRuleResolver.php`, `AccountRoleResolver.php`, `CommercialAccountingService.php`, `DriverFinanceService.php`; `Integration/Application/Services/FinancialIntegrationService.php`; `Integration/Application/Bridge/EventPostingCatalog.php`, `EventPostingSubscriber.php` |
| Fiscal periods / closing | `Fiscal/Domain/Models/FiscalYear.php`, `FiscalPeriod.php`; `Closing/Domain/Services/ClosingService.php`, `PeriodClosingService.php`, `YearEndClosingService.php`, `ClosingWorkspaceService.php`, `CloseReadinessScorer.php` |
| Budgets / reporting | `Budget/*`; `Reporting/*`; `Ledger/Domain/Services/TrialBalanceService.php` |
| Allocation engine | `Allocation/Domain/Services/AllocationEngine.php` (AR/AP receipt/payment allocation); `CostAllocation/Domain/Services/CostAllocationService.php` (management-dimension/Brand cost redistribution) |
| Payment/receipt authorities | `AccountsReceivableService` (invoices, receipts), `AccountsPayableService` (bills, payments) |
| Finance dimensions | Carried on every `PostingLine`/`JournalLine`: `company_id`, `branch_id`, `cost_center_id`, `profit_center_id`, `project_id`, `campaign_id`, `currency` |
| Controls | `Controls/Domain/Services/FinancialValidationEngine.php`, `ControlExceptionService.php` |
| Shared idempotency/provisioning | `Shared/Domain/Services/CommandIdempotencyGuard.php`, `CompanyFinanceProvisioner.php`, `ControlAccountReconciliationService.php`, `FundingAccountPolicy.php` |

**JournalEngine confirmed as the sole GL writer** — every other service (`PostingCoordinator`, `CashService`, `DriverFinanceService`, subledger services) *requests* a journal through it; none writes `finance_journal_entries`/`finance_journal_lines` directly. `JournalEngine::post()` enforces three gates (balanced, postable, in an open fiscal period); correction is only ever a mirrored reversing entry, never an edit. It also has its own maker/checker segregation for manual drafts (`approveAndPost()` throws if the checker is the maker) — no new ledger was found or is proposed.

---

## COMMERCE → FINANCE

- **Revenue:** ALREADY_COMPLETE. `Integration\Domain\Services\CommercialAccountingService::recognizeRevenue()` creates and posts a real `CustomerInvoice` (AR) with a revenue line (+ VAT line when applicable), idempotent on the order (a second call for the same order returns the existing invoice rather than double-booking).
- **AR / payment:** ALREADY_COMPLETE. The same invoice posts to `ar_control`; `recognizeCodCollection()` creates+posts a `CustomerReceipt` into a dedicated `cod_clearing` (1130, "Cash in Transit") account and allocates it against the order's invoice when one is outstanding.
- **COGS:** ALREADY_COMPLETE. `recognizeCogs()` posts a pure GL movement (Dr `cost_of_goods_sold` / Cr `finished_goods`) through the generic rule-driven bridge, reusing the existing `BusinessEventType::DeliveryConfirmation` catalog entry — no new event type was needed. Queued (async), idempotent on `'order_delivered_cogs:'.$orderId`.
- **Dimensions:** `profit_center_id` (Brand) flows through both postings.
- **Wiring:** `Integration/Application/Listeners/PostRevenueAndCogsOnOrderDelivered.php` subscribes to `Operations\Fulfillment\Domain\Events\OrderDeliveredEvent`, fires post-commit, wraps revenue and COGS in independent try/catch (one failing never blocks the other, neither can throw back into delivery).
- **Gap:** COGS reversal on a cancelled/returned order is explicitly not built (`CommercialAccountingService::reverseRevenue()` reverses only the AR side; the docblock states a COGS reversal "is a distinct new journal against the same rule, out of this task's scope"). Minor, not currently causing incorrect books (COGS is simply not un-recognised on a later return) — worth a business decision if returns are material, but not a FIN-03 blocker.

## COGS / INVENTORY → FINANCE

- FIFO/COGS is calculated upstream (Inventory/Commerce) and consumed by Finance **as-is** — "never recalculated here," so a later product-cost change cannot rewrite a historical posting.
- Inventory asset relief **is** posted for the commercial-delivery path (`finished_goods` credited on COGS recognition).
- Brand/company dimensions are preserved on this path.
- **This is complete for the commercial delivery path only.** Separately, the raw inventory-movement lifecycle (`inventory.goods_receipt`, `.supplier_return`, `.warehouse_transfer`, `.adjustment_increase/_decrease`, `.count_gain/_loss`, `.write_off`, plus `procurement.purchase_return`'s inventory leg — 9 posting-rule legs total, confirmed by reading `2026_08_18_100003_seed_finance_posting_rules.php` directly) all name a generic `'inventory'` account role. Per Finance's own approved policy, there is deliberately **no single postable Inventory account** — stock is split across four class-specific control accounts (raw materials 1420, packaging 1440, WIP 1430, finished goods 1410) — so the generic role has **no mapping** (`AccountRoleSeeder`'s own comment documents this: "Those nine rules must be re-authored to name the class role they mean. That is posting-rule work... Until then they dead-letter."). This fails *safely* (audited + dead-lettered, no journal produced, no corruption) but produces **no journal at all** for those 9 event codes today.
- **Classification: CROSS_TRACK_DEPENDENCY** — closing this needs Inventory to state which of the four classes each movement concerns (an Inventory-side taxonomy decision), which this reconciliation was explicitly told not to repair. Not a Payroll/Wallet item, and not proposed as part of FIN-03's implementation task.

## PAYMENT / COD → FINANCE

- **Cash payment / Treasury receipt:** ALREADY_COMPLETE — `Cash/Domain/Services/CashService.php` (accounts, sessions, `recordTransaction()`, `transfer()`), every movement posted through `PostingCoordinator`.
- **COD collection:** ALREADY_COMPLETE and wired — `Integration/Application/Listeners/PostCodCollectionOnCodCollected.php` subscribes to `Logistics\Delivery\Domain\Events\CodCollected` (the listener's own docblock notes this event "currently has zero subscribers anywhere in the codebase" before this listener was added — a previously-closed gap).
- **Driver cash handover:** ALREADY_COMPLETE, code-complete and tested (`tests/Feature/Operations/CashHandoverConfirmationTest.php` exists) — `Logistics\Distribution\Domain\Services\CashHandoverService::confirmReceipt()` posts the Treasury-counted amount (never the driver's declared amount) through the canonical `CashService`, against a `driver_cash_clearing` role, with careful concurrency control (row-lock before the Finance call, since `CashService` mints its own idempotency key per call) and idempotent re-confirmation. **Note:** `driver_cash_clearing` is not in the global `AccountRoleSeeder` list (unlike `cod_clearing`/`driver_receivable`, which are) — this is handled gracefully today (the controller explicitly catches an unmapped-role failure), so it is a one-time per-company configuration step rather than a code gap; adding it to the global seeder would be a trivial, optional tidy-up.
- **InstaPay / card / gateway:** not found. No listener, posting rule, or account role referencing a payment gateway was located anywhere in `Modules\Finance`. Either this is not yet a live payment method in the product, or it is handled entirely outside Finance today — I could not confirm which from this file set, so I'm not classifying it either way rather than guessing.

## PAYROLL → FINANCE

- `hr.compensation.approved` (`Modules\Hr\Compensation\Domain\Events\CompensationApproved`) fires after a `PayrollRun` is approved (confirmed in FIN-02).
- **No Finance listener subscribes to it.** `BusinessEventType` (the enum of every event Finance recognises) has **no HR/Payroll case at all** — Inventory, Procurement, Manufacturing, Orders, POS, Shipping, and CRM/Marketing all have cases; HR does not.
- The target GL accounts already exist (`ChartOfAccountsSeeder`: 2310 Salaries Payable, 2320 Employee Deductions Payable, 2330 Employer Contributions Payable, 2340 Social Insurance Payable, 5510 Salaries & Wages, 5540 Sales Commissions) but **no `AccountRole` mappings exist for them** — `AccountRoleSeeder::definitions()` has no `salaries_payable`/`salaries_expense`/`commission_expense`/`employee_deductions_payable` entries.
- **Required posting model** (matching the pattern already proven four times — Commerce revenue+COGS, COD, Fleet cost, Driver finance):
  1. One or more new `BusinessEventType` cases (e.g. `PayrollApproved = 'hr.payroll_approved'`).
  2. New `AccountRole` mappings to the *already-existing* accounts above (config, not schema).
  3. A `PostingRule` (config row) with legs: Dr `salaries_expense` + Dr `commission_expense`, Cr `salaries_payable` + Cr `employee_deductions_payable` (amounts read by name from the event, exactly like every existing rule).
  4. One new listener (e.g. `PostPayrollLiabilityOnCompensationApproved`, ~60–90 lines, matching the size of the three existing `Post*On*` listeners) subscribing to `hr.compensation.approved`, translating the run's totals into a `FinancialEvent`, and calling `FinancialIntegrationService::recordAsync()`.
- This keeps HR exactly where it already documents itself as belonging — "nothing in HR listens to it, and Finance may subscribe whenever it is ready without HR changing" — HR never writes GL directly.
- **Classification: IMPLEMENTATION_REQUIRED.** This is the single clearest, best-precedented gap found in this whole reconciliation.

## PROCUREMENT / SUPPLIER FINANCE

- Supplier Invoice/Bill ✓ (`SupplierBill`, `createDocument()`/`postDocument()`), AP recognition ✓ (`procurement.purchase_materials` rule: Dr `grni` + Dr `vat_input`, Cr `ap_control`), supplier payments ✓ (`createPayment()` → `approvePayment()` — maker/checker — → `postPayment()`), advances ✓ (`SupplierLedgerEntryType::Advance`, tracked and reported *separately* from the payable, never as debt), VAT/price variance ✓ (`vat_input`; `purchase_price_variance` 5180 explicitly for a supplier-invoice-vs-goods-receipt valuation gap), allocations ✓ (`AllocationEngine::allocatePayment()`/`autoAllocatePayment()`), reversal ✓ (`reversePaymentAllocation()`, `AccountsPayableService::reversePaymentPosting()`).
- **Verdict: ALREADY_COMPLETE.** No FIN-03 gap found; this track was correctly described as already closed and was not reopened.

## SHIPPING / OPS → FINANCE

- **External carrier charges:** ALREADY_COMPLETE — `shipping.shipment_cost`/`.delivery_failure`/`.return_shipment` all post Dr `shipping_expense` / Cr `carrier_payable` (2130, globally seeded).
- **Internal fleet cost:** ALREADY_COMPLETE and wired — `PostFleetCostOnVehicleCostPosted.php` subscribes to `Logistics\Fleet\Domain\Events\VehicleCostPosted` (its own docblock: "confirmed... to have zero subscribers anywhere in the codebase before now" — another previously-closed gap), reuses `BusinessEventType::ShipmentCost`. The docblock also explains why `FuelTransactionRecorded` deliberately has no separate listener: fuel cost only becomes postable once reconciled, and the reconciliation path already re-fires `VehicleCostPosted` at that point.
- **Driver cash custody (handover):** ALREADY_COMPLETE (see above).
- **Driver advances/costs (a driver's own float/reimbursement/shortage, distinct from cash handover):** **PARTIAL.** `Finance/OperationalCost/Domain/Services/DriverFinanceService.php` fully implements `recognizeDriverAdvance()`, `recognizeDriverExpense()`, `recognizeDriverShortage()`, and `reverseDriverLedgerPosting()` — all posting through the canonical `PostingCoordinator`, idempotent on `source_type`/`source_id`. But its own docblock states plainly that `recognizeDriverShortage()` "exists ONLY for an ALREADY-approved shortage — its caller (a future listener, once Logistics exposes a real approval event; **none exists today**...)." The Finance-side capability is built and ready; nothing in Logistics currently triggers it.
- **Classification: CROSS_TRACK_DEPENDENCY** — this is blocked on Logistics exposing a real "driver advance/expense/shortage approved" event, not on any Finance-side work. Once that event exists, wiring it is a small, well-precedented listener (same shape as the four already built) — but it cannot be scoped as a FIN-03 implementation task today because the trigger it would subscribe to doesn't exist yet.

---

## FINANCE DIMENSIONS

Already present and carried on every posted journal line: **Company** (`company_id`, on everything), **Branch** (`branch_id`), **Cost Center** (`cost_center_id` — including warehouses, which are auto-provisioned as typed cost centers by `WarehouseCostCenterResolver`, get-or-create, keyed by `source_type`/`source_id`), **Profit Center** (`profit_center_id` — this **is** the Brand dimension; its own docblock: "Ready-for-use since EPIC F1's journal-line schema; wired into RulePostingStrategy by TASK-ECOS-FINANCE-COMMERCIAL-ACCOUNTING-006"), **Project** (`project_id`), **Campaign** (`campaign_id`), and currency. Channel is not a separate dimension column — the seeded `sales_revenue` account is explicitly documented as "the default operational revenue account for every channel... they do not each get their own revenue account unless a posting rule names one," i.e. channel is an analytic/reporting concern layered on top, not a missing posting dimension.

No dimension the domain doesn't already use was invented for this report, and none was found missing from a **currently posting** path. (The 9 dead-lettering inventory-lifecycle rules above are blocked on a role mapping, not a missing dimension.)

---

## WALLETS

**Architecture confirmed:** grepping the entire backend for `Wallet` found exactly one hit outside this reconciliation's scope — `Modules\Crm\Loyalty\Domain\Services\WalletService.php`, a customer-facing **loyalty points** ledger, unrelated to Supplier/Employee/Shipping/Brand/Parent. **No Supplier, Employee, Shipping, Marketer, Brand, or Parent wallet engine exists anywhere in the codebase.** Every one of them resolves to an existing, already-complete authority:

**Supplier:**
- Authority: `SupplierLedgerService` (balance, `outstandingPayable()`, `availableAdvance()` — advances shown separately from debt, `history()`/`statement()` with a running balance) + `SupplierBill`/`SupplierPayment`/`SupplierLedgerEntry`/`SupplierOpeningBalanceService`.
- Status: **functionally complete already** — opening balance ✓, bills ✓, payments ✓, advances ✓, allocations ✓, outstanding balance ✓, history ✓. No dedicated SupplierWallet engine or UI is needed; the existing Accounts Payable page is this wallet.

**Employee:**
- Authority: HR `Compensation` (SalaryStructure/Payslip/Advance/Bonus/Deduction — all confirmed ALREADY_COMPLETE in the FIN-02 pass) plus, once Payroll→Finance is wired, the Finance-side postings; for driver-type employees specifically, `DriverFinanceService`'s `driver_receivable` ledger once wired.
- Status: composable today. HR's own Compensation-360 page (confirmed built in FIN-02) already **is** this view; no new Finance-side employee wallet engine or page is proposed.

**Shipping / Marketer:**
- Authority found: `carrier_payable` (2130) as a GL role, credited by the three shipping posting rules. **No dedicated subledger** (no carrier-equivalent of `SupplierLedgerService`/statement/aging) was found, and **no authority at all** was found for "marketer/agent" as a payable party.
- Status: **BUSINESS_DECISION_REQUIRED** — should external carriers/marketers be modelled as Suppliers (reusing the mature, complete AP subledger with zero new code) or does the business need a distinct authority for them? Building a bespoke "Shipping Wallet" before this decision would risk exactly the second-ledger anti-pattern this ticket forbids.

**Brand:**
- Authority: `profit_center_id` dimension (present on every journal line since EPIC F1, actively used by Commerce's revenue/COGS postings) + `CostAllocationService` (redistributes an already-posted shared expense across Brand-tagged profit centers for profitability analysis, without a second GL entry, with full reversal).
- Status: **foundation ready for FIN-04.** FIN-03 does not need to build anything further here — the linkage FIN-04's Brand economics will need already exists and is already exercised in production code paths.

**Parent Company:**
- Authority: none beyond ordinary `company_id` multi-tenancy. No consolidation/roll-up authority across multiple `company_id`s was found.
- Status: **DEFER_TO_FIN_04** — every posting already carries `company_id` as its base unit, ready to be rolled up later; inventing a "Parent Company" authority now would be premature and is explicitly FIN-04's territory per this ticket.

---

## REVERSAL

**ALREADY_RESOLVED**, at every layer this reconciliation checked:
- GL: `JournalEngine::reverse()` — mirrors every debit/credit, links the pair, refuses to reverse a draft or an already-reversed entry.
- GL-vs-subledger-allocation conflict (the exact concern Section 17 raised): `JournalEngine::assertNoActiveSubledgerAllocations()` explicitly blocks reversing a payment/receipt-sourced journal while its allocations are still economically active, checked *inside* the transaction against the live allocated amount. Its own docblock cites `TASK-ECOS-FINANCE-AP-AR-GL-WIRING-003 §10-11` as the task that resolved this.
- Subledger allocation itself: `AllocationEngine::reverseReceiptAllocation()` / `reversePaymentAllocation()` — the "unallocate" mechanism exists and is what a caller uses *before* a GL reversal is permitted.
- Cost allocation (Brand/profit-center distribution): `CostAllocationService::reverseAllocation()` — append-only negative contra-row, refuses to reverse a reversal.
- Driver ledger: `DriverFinanceService::reverseDriverLedgerPosting()`.
- Supplier payment: `AccountsPayableService::reversePaymentPosting()`.

## IDEMPOTENCY

**Consistent and layered, no gap found:**
- `PostingCoordinator` — exactly-once per `(source_module, source_event_id)`, backed by a real unique index on `PostedEventReceipt` (a race that slips the pre-check is caught by the DB constraint and reconciled to the winner, never a duplicate).
- `FinancialEvent` — carries its own `idempotencyKey`, read by `FinancialEventProcessor` before ever building a request.
- `Shared\Domain\Services\CommandIdempotencyGuard` — a second, command-level idempotency layer (`FinanceCommandReceipt`).
- `CashHandoverService` is the one documented, deliberate exception: `CashService::recordTransaction()` mints its own random UUID as the posting key, so it cannot dedupe a repeated *logical* handover by itself — `CashHandoverService` compensates with an explicit row-lock (`TripSettlement::lockForUpdate()`) taken *before* the existing-handover check and before calling Finance, with the DB unique index as a last-resort backstop. This is correctly reasoned, not an oversight.
- **Recommendation for the Payroll listener:** reuse `FinancialIntegrationService`/`PostingCoordinator` exactly as the four existing listeners do (an idempotency key derived from the `PayrollRun` id) — no new idempotency mechanism should be designed.

---

## FRONTEND

Existing Finance pages (`frontend/src/features/finance/pages/`): Accounts Payable, Accounts Receivable, Budgets, Cash & Banking, Chart of Accounts, Costing & Profitability, Expenses, Finance Executive (dashboard), Financial Statements, Fiscal Closing, Journals, Tax & VAT. These already are the "financial position" read surfaces for Suppliers (AP page), Customers (AR page), Cash/Bank, and — once Cost Allocation surfaces there — Brand. No Wallet-specific page exists, and per the Wallet Rule none should be built as a new page with its own mutable balance; the existing pages (plus HR's Compensation-360, confirmed in FIN-02) already satisfy the requirement.

---

## ALREADY COMPLETE

- Commerce revenue + AR (delivery-triggered, idempotent, dimensioned)
- COGS on commercial delivery (async, idempotent, reuses the generic bridge)
- COD collection → AR receipt into a dedicated clearing account, allocated to the order invoice
- Driver cash handover → Treasury Cash posting (code + tests)
- External carrier shipping cost → expense/payable
- Internal fleet cost → expense/payable
- Full Procurement/Supplier AP lifecycle (bill, payment, maker/checker approval, advances, VAT/price variance, allocation, reversal)
- Full Cash/Banking, Fiscal/Closing, Budgets/Reporting, Chart of Accounts (preserved, unchanged)
- GL reversal, subledger-allocation reversal, cost-allocation reversal, driver-ledger reversal, supplier-payment reversal
- Multi-layer idempotency (event receipt, command guard, application-level lock where warranted)
- Finance dimensions (Company/Branch/CostCenter incl. Warehouse/ProfitCenter=Brand/Project/Campaign) already wired into every posting
- Finance frontend (AR/AP/Cash-Banking/Budgets/CoA/Costing-Profitability/Expenses/Financial-Statements/Fiscal-Closing/Journals/Tax-VAT)
- Supplier "wallet" (SupplierLedgerService — already a complete read model)
- Employee "wallet" foundation (HR Compensation, confirmed complete in FIN-02)
- Brand dimension linkage for FIN-04 (profit_center_id + CostAllocationService)

## IMPLEMENTATION REQUIRED

- **Payroll → Finance posting.** New `BusinessEventType` case(s), `AccountRole` mappings to the already-existing payroll GL accounts, a `PostingRule`, and one new listener on `hr.compensation.approved` — following the exact pattern already used four times. This is the only item proposed as a FIN-03 implementation task.

## BUSINESS DECISIONS

1. **Shipping/Marketer Wallet authority** — model external carriers/marketers as Suppliers (reuse AP, zero new code) or build a distinct payable authority? No canonical authority currently exists for "marketer" specifically.
2. **COGS reversal on order cancellation/return** — currently not built (only the AR/revenue side reverses); worth a decision if returns are financially material.
3. **InstaPay/gateway/card payments** — no integration found in Finance; unclear whether this is a live payment method elsewhere in the product today.

## CROSS_TRACK_DEPENDENCIES

1. **9 inventory-lifecycle posting rules dead-letter** (`inventory.goods_receipt`, `.supplier_return`, `.warehouse_transfer`, `.adjustment_increase`, `.adjustment_decrease`, `.count_gain`, `.count_loss`, `.write_off`, `procurement.purchase_return`) — blocked on Inventory stating which of the four stock classes each movement concerns; explicitly self-documented in `AccountRoleSeeder` as unresolved "posting-rule work," not chart configuration.
2. **Driver advance/expense/shortage posting** — Finance side (`DriverFinanceService`) is fully built and ready; blocked on Logistics exposing a real "approved" domain event to trigger it. Self-documented in `DriverFinanceService`'s own docblock as not existing today.

## DEFER_TO_FIN_04

- Brand economics / profitability reporting itself (the *linkage* is already ready — see Dimensions/Wallets above; the *report* is FIN-04's).
- Parent Company consolidation / multi-`company_id` roll-up (no authority exists yet; premature to build now).

---

## PROPOSED FIN-03 IMPLEMENTATION

**TASK 1 — PAYROLL → FINANCE POSTING CLOSURE.** Add the `BusinessEventType` case(s), `AccountRole` config, `PostingRule` config, and one listener translating `hr.compensation.approved` into a `FinancialEvent`, posted through the existing `FinancialIntegrationService`/`PostingCoordinator`/`JournalEngine` — reusing every existing mechanism, inventing none. HR remains calculation-only; Finance remains the sole GL writer.

**TASK 2 — removed.** No wallet read model needs building: every wallet in scope already resolves to an existing, complete authority (Supplier → SupplierLedgerService/AP page; Employee → HR Compensation-360; Brand → profit_center_id + CostAllocationService), except Shipping/Marketer, which needs a **business decision**, not code, before any implementation could be scoped correctly.

**IMPLEMENTATION TASK COUNT: 1**

---

## RECOMMENDATION

**READY FOR IMPLEMENTATION** (Task 1 only) — **BUSINESS DECISIONS REQUIRED FIRST** for the three items listed above (Shipping/Marketer wallet authority, COGS reversal, InstaPay/gateway) before those specific gaps could be scoped as tasks; none of the three block Task 1.

---

## CONFIRM

- FIN-02 remains closed (source complete, 0 implementation tasks)
- No repeated NEXT back-sync performed
- No implementation performed
- No DB accessed
- No tests run
- No agents used
- No background tasks used
- No commit
- No push
- `E:\ECOS\ECOS-NEXT` untouched
- `E:\ECOS\ECOS-V1-STAGING` untouched

STOP — for CTO review.
