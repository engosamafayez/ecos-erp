# TASK-PROCUREMENT-FINANCE-INTEGRATION-AUTHORITY-DECISION-001 — Engineering Report

**Date:** 2026-08-17 · **Branch:** `develop` · **Runtime:** MySQL 8.4.10 / PHP 8.4.24
**Type:** ARCHITECTURE INVESTIGATION — read-only

**FINAL VERDICT: OUTCOME B — PARTIAL, SPECIFIC CONFIGURATION REQUIRED**

**Production changes made: NONE.** No code, migration, schema, configuration, account or data was
modified. Every finding below comes from reading code/migrations/seeders and running `SELECT`
queries plus read-only runtime resolution.

---

## 0. Correction to the previous report

My previous report (`…RECEIVING-APPROVAL-INVOICE-AMENDMENT-IMPLEMENTATION-001`) concluded there was
**no AP control account configured** and treated the payable leg as an undefined contract gap. That
conclusion was **wrong**, and this task's deeper trace is what exposed it.

I had observed `ControlAccountResolver::payable()` throwing *"No AP control account is configured"*
and stopped there. I did not check whether the account existed under a **different key**. It does:
**`2110 Trade Payables`**, flagged `is_control = 1`, postable, active, present for **3 of 4
companies** — seeded under the comment *"CONTROL: moved only by the AP subledger."*

The resolver fails because of a **vocabulary mismatch**, not a missing account (§5). The material
consequence: what I reported as a *contract gap* is in fact a *configuration/wiring defect*, and the
Finance architecture required by the Procurement contract is **substantially complete**.

---

## 1. Objective · 2. Approved Procurement contract

Determine whether *Receiving → Review/Edit → Approval → Invoice Review/Edit → Approval → Final
Posting → Inventory + FIFO + Supplier Payable* can be implemented using existing canonical Finance
mechanisms, with final posting using the **final approved quantity and price** (75 KG × 450), no
intermediate posting and no retroactive correction.

## 3. Existing Finance architecture

| Layer | Component | State |
|---|---|---|
| GL | `finance_accounts` (300 rows), `PostingCoordinator`, `JournalEngine` | Live — 6 journal entries exist |
| AP subledger | `AccountsPayableService`, `SupplierBill`, `SupplierBillLine`, `SupplierLedgerEntry`, `SupplierPayment` | Complete, **never used** (0 bills, 0 ledger entries) |
| Balance/statement | `SupplierLedgerService::balance()/history()/statement()` | Ready — no data to read |
| Control accounts | `finance_accounts.is_control` + `control_subledger`; `ControlAccountResolver` | Seeded, **but key mismatch — §5** |
| Role mapping | `finance_account_roles` + `AccountRoleResolver::resolve($companyId,$role)` | Seeded, comprehensive |
| Event bridge | `BusinessEventType`, `finance_posting_rules` (44 active), `EventPostingSubscriber`, `ProcessFinancialEventJob` | **Active** (`auto_subscribe = true`), **asynchronous** |
| Tax | `finance_tax_codes`, `TaxCode.input_account_id` | Structure exists, **0 rows** |

## 4. Accounts Payable authority

Traced by call path, not by class name:

1. **What creates `SupplierLedgerEntry`?** Exactly two call sites, both in `AccountsPayableService`:
   `postDocument()` (:142) and `postPayment()` (:259). Nothing else, anywhere.
2. **What creates `SupplierBill`?** `AccountsPayableService::createDocument()`, reachable today only
   from Finance's own `SupplierBillController` and `AllocationEngine`.
3. **What updates the supplier balance?** `SupplierLedgerService::balance()` sums
   `finance_supplier_ledger_entries` — i.e. it is a *projection* of (1).
4. **What creates the GL journal?** `PostingCoordinator::post()`, called by `postDocument()` with
   `JournalType::Purchase`.
5. **Canonical transaction boundary?** `postDocument()` wraps journal + bill update + ledger entry in
   **one `DB::transaction`** — synchronous.

**The AP authority is `AccountsPayableService`. It is the only mechanism in the platform that can
move a supplier balance.**

## 5. AP control account — EXISTS, mis-keyed

`ControlAccountResolver` resolves by string:

```php
public function payable(string $companyId): Account { return $this->resolve($companyId, 'ap'); }
// → where('is_control', true)->where('control_subledger', 'ap')
```

`ChartOfAccountsSeeder` writes a different vocabulary:

```php
// CONTROL: moved only by the AP subledger.
['2110', 'Trade Payables', 'ذمم الموردين', $L, 'current_liability', $C, true, 'payables'],
['1310', 'Trade Receivables', …,                                    true, 'receivables'],
```

Live distribution of `control_subledger`:

| value | rows |
|---|---|
| `payables` | 3 |
| `receivables` | 3 |
| `inventory` | 12 |
| `vat` | 6 |
| `payroll` | 12 |
| **`ap` / `ar`** | **0** |

The table migration documents the intended vocabulary as `// ar | ap | inventory | ...`, so
`inventory` and `vat` match while **`ar`/`ap` diverge**. Consequences:

- `ControlAccountResolver::payable()` **and** `receivable()` both throw — **AR is equally blocked**,
  which is why the AP subledger has never been used.
- The account itself is unambiguous and company-scoped: **2110 Trade Payables**, `is_control = 1`,
  `is_postable = 1`, `is_active = 1`, for ECOS Holding 20, OSAMA FAYEZ AHEMD and AxieFood.
  **`Nile Foods Trading` has no chart of accounts at all** — it would still fail after any fix.
- Independently corroborated by the role table: **`ap_control` → 2110 Trade Payables** for the same
  3 companies. Two mechanisms already agree on which account is AP; only the `control_subledger`
  string disagrees.

**No account creation is required.** The canonical configuration mechanism exists
(`ChartOfAccountsSeeder`, and `AccountController` accepts `control_subledger`). What must be decided
is **which side to align** — see D-1.

## 6. Inventory asset accounting — mapping EXISTS

Answering PART 3's seven questions from evidence:

1. **Inventory asset accounts exist**: `1410 Finished Goods`, `1420 Raw Materials`,
   `1430 Work In Progress`, `1440 Packaging Materials` — all `asset`, postable, per company,
   `control_subledger = 'inventory'`.
2–4. **A Product → GL mapping exists**, via the class-deferred role mechanism, not a direct FK:
   `RulePostingStrategy::ROLE_BY_INVENTORY_CLASS = '@inventory_class'`;
   `FinancialEvent` reads `dimensions['inventory_class']`; and the inventory domain events
   (`InventoryStockReceived`, `InventoryStockAdjusted`, `InventoryStockShipped`,
   `InventoryTransferred`) **already carry `inventory_class` in their payload**. Roles
   `raw_materials` / `finished_goods` / `wip` / `packaging_materials` are seeded per company.
5–7. **`SupplierBill` supports inventory-asset lines, and `expense_account_id` does NOT enforce
   expense accounting.** In `buildDocumentPostingRequest()` the field is passed straight through as a
   plain account id to debit:

```php
$lines[] = $this->directional((int) $line->expense_account_id, $net, $sign >= 0, …);
```

There is no `account_type` check, no category validation, no constraint. Supplying `1420 Raw
Materials` produces exactly `Dr Inventory Asset … Cr Trade Payables`. **The field is historically
misnamed but semantically neutral — no Finance change is needed** (and per instruction none was made).

## 7. Supplier bill · 8. Supplier ledger · 9. Supplier balance

The chain `Final Approved Invoice → SupplierBill → SupplierLedgerEntry → balance` is fully supported
**without any direct Purchasing write to `SupplierLedgerEntry`**: Purchasing would call
`createDocument()` + `postDocument()`, and the service writes the ledger entry itself.

For 75 KG × 450 = **33,750**, `postDocument()` records
`amount = total × document_type->payableSign()` against the supplier, which
`SupplierLedgerService::balance()` then sums. The arithmetic and the mechanism are both already in place.

## 10. GL posting — the canonical treatment is already defined

The seeded, **active** posting rules define the complete accounting for this flow:

| Event | Legs |
|---|---|
| `inventory.goods_receipt` | **Dr `@inventory_class`** (net) · **Cr `grni`** (net) |
| `procurement.purchase_materials` *(supplier invoice)* | **Dr `grni`** (net) · **Dr `vat_input`** (tax) · **Cr `ap_control`** (gross) |
| `procurement.purchase_return` | Dr `ap_control` (gross) · Cr `@inventory_class` (net) · Cr `vat_input` (tax) |

Resolved for the dev company: `grni` → **2120 Goods Received Not Invoiced**, `vat_input` → **1530 VAT
Receivable (Input)**, `ap_control` → **2110 Trade Payables**, `@inventory_class` → 1410/1420/1430/1440.

This is the textbook perpetual-inventory + AP accrual pattern, and **it is already modelled, seeded and
active**. Nothing needs inventing.

**Important dependency this creates:** the two-step treatment assumes inventory is debited at
*receipt* (against GRNI) and the accrual is cleared at *invoice*. The approved Procurement contract
posts inventory **only at final posting, after invoice approval** — so under that contract there is no
GRNI period, and the invoice's debit should go **directly to `@inventory_class`**, not to `grni`.
That is a rule-selection decision (D-3), not a missing capability.

## 11. Final cost authority

No new cost authority is required. `createDocument()` accepts explicit `quantity`, `unit_price` and
`net_amount` per line, and `CreateReceiptLayersAction` accepts the landed unit cost per line. Both
therefore consume the **final approved invoice price** directly.

Because posting happens only after approval, the FIFO layer is created **once, at 450** — there is no
500-cost layer and no retroactive correction, exactly as the contract requires. `average_cost`, the
FIFO engine, recipe cost and the product cost architecture are untouched.

## 12. VAT / tax — mapped by role, unconfigured as tax codes

- Accounts exist: **1530 VAT Receivable (Input)** (asset) and **2210 VAT Payable (Output)**, both
  `control_subledger = 'vat'`; role `vat_input` → 1530 is seeded per company.
- The bridge rule already books input VAT (`Dr vat_input` on `procurement.purchase_materials`).
- `AccountsPayableService` supports VAT **through `TaxCode`**: it reads
  `TaxCode::find($line->tax_code_id)?->input_account_id` and adds the tax leg.
- **`finance_tax_codes` contains 0 rows.** So via the AP service, a VAT-bearing line cannot resolve
  its input account today. A line with `tax_code_id = null` skips the tax leg cleanly, so **zero-VAT
  bills post correctly right now**.

**Not undefined — unconfigured.** The rate, the account and the mechanism all exist; a `TaxCode` row
linking them does not. No tax code, rate, account or calculation was invented here.

## 13. Finance event bridge

Verified by call path: `Modules/Finance/Integration` contains **zero** references to `SupplierBill`,
`SupplierLedgerEntry` or `AccountsPayableService`. It maps events to **general-ledger journals only**
and can never move a supplier balance.

It is **active** (`finance.integration.auto_subscribe = true`, 44 active rules) and **asynchronous** —
`EventPostingSubscriber` calls `ProcessFinancialEventJob::dispatch()`, and that job
`implements ShouldQueue`, so it executes on a worker **outside** the originating transaction.

Separately: **nothing in Purchasing or Inventory currently emits these events** — a repo-wide search
for `BusinessEventType::` outside `Modules/Finance` and the test suite returns nothing, and
`PostSupplierInvoiceService` emits none. The rules are live but unfed from Procurement.

## 14. Double-posting analysis — ONE FINANCIAL POSTING AUTHORITY

The risk is **real, not hypothetical**, because both paths credit AP:

- `AccountsPayableService::postDocument()` → `buildDocumentPostingRequest()` credits the AP control.
- `procurement.purchase_materials` rule → **Cr `ap_control` (gross)**.

Running both for one approved invoice would credit Trade Payables **twice** in the GL while producing
a single supplier-ledger entry — an out-of-balance subledger-to-control reconciliation.

> ### ONE FINANCIAL POSTING AUTHORITY: `AccountsPayableService`

**Why it must be this one, on evidence:**

1. **It is the only mechanism that produces a supplier balance.** The bridge writes journals only, so
   choosing it would leave PART 8/13 (supplier payable and balance) permanently unsatisfiable.
2. **It is the only one that can be atomic with inventory.** It is synchronous and transactional, so it
   can join the inventory/FIFO transaction. The bridge is queued, so a bridge-based payable could never
   satisfy "final posting must atomically establish Inventory + FIFO + Supplier Payable".
3. **It already produces the same GL entry** the bridge rule describes, provided the correct accounts
   are passed per line.

**Consequence:** `procurement.purchase_materials` must **not** also fire for an invoice posted through
the AP service. The rule stays valid for any other producer, but this flow must not emit it (D-3).

## 15. Transaction boundary

A safe boundary already exists. `PostSupplierInvoiceService` runs its work inside one
`DB::transaction` with the C-1 shared canonical-inbound `lockForUpdate` in front of it;
`ReceiveStockAction` and `CreateReceiptLayersAction` already run inside it; and
`AccountsPayableService::postDocument()` opens its own `DB::transaction`, which nests as a **savepoint**
under an outer one. So Inventory + FIFO + Payable can be established in a single atomic boundary with
no redesign of either module.

**The documented risk if the bridge were used instead:** the queued job commits independently, so a
failure after the inventory commit would leave inventory posted with no payable — a partial financial
state. This is a second, independent reason the bridge cannot be the payable authority.

## 16. Goods Inward mode

Current state: default **`goods_receipt`** (Mode 1); Mode 3 (`supplier_invoice`) fully supported and
**company-scoped** via `companies.goods_inward_mode`, configured through the certified Goods Inward
Configuration UI (certified earlier today). No mode was changed and none was created.

**Mode 3 already delivers the contract's required timing** — receipt records physical receiving and
posts zero inventory/ledger/FIFO, the invoice performs the posting (proven by
`InboundCrossDocumentConcurrencyTest::test_g/test_i`). Under **Mode 1**, the default, the receipt posts
inventory immediately, contradicting the contract's "no inventory before invoice approval".

Per PART 10 this is reported separately as a **business-wide decision (D-4)**, not resolved here.

## 17. Tenant isolation

Company-scoped throughout: `finance_accounts.company_id`, `finance_account_roles.company_id`,
`SupplierBill.company_id`, `SupplierLedgerEntry.company_id`; `ControlAccountResolver` and
`AccountRoleResolver` both filter by `company_id`; `GoodsReceipt`/`SupplierInvoice` carry the `tenant`
global scope. **No tenant risk identified.** Note only that `Nile Foods Trading` has no accounts or
roles, so any posting for it fails closed rather than leaking.

## 18. Existing certified contracts

Nothing in this investigation requires changing Supplier Return valuation, Inbound Ownership, Inbound
Security, Inbound Idempotency, Procurement MySQL compatibility, Goods Inward Configuration,
Inventory/FIFO, Orders or Preparation. **No certified work was reopened; this task was read-only.**

## 19. Decision matrix

| Decision | Existing Authority | Exists? | Reusable? | Gap? | Evidence |
|---|---|---|---|---|---|
| AP Control Account | `2110 Trade Payables` (`is_control`) + `ap_control` role | **YES** | **YES** | **Key mismatch** — resolver reads `'ap'`, seed writes `'payables'` | `ControlAccountResolver:30`; `ChartOfAccountsSeeder:103`; 3 rows live |
| Supplier Payable | `AccountsPayableService::createDocument/postDocument` | **YES** | **YES** | No integration point from Purchasing | Sole writer; 0 bills to date |
| Supplier Ledger | `SupplierLedgerEntry` via `postDocument()` | **YES** | **YES** | None (no direct Purchasing write needed) | `AccountsPayableService:142` |
| Inventory Asset Account | 1410/1420/1430/1440, `control_subledger='inventory'` | **YES** | **YES** | None | COA + roles, 3 companies |
| Product/Category GL Mapping | `@inventory_class` → role → account | **YES** | **YES** | Purchasing must supply `inventory_class`; events already carry it | `RulePostingStrategy:33`; `InventoryStockReceived:107` |
| Final Approved Cost | AP line `unit_price`/`net_amount`; `CreateReceiptLayersAction` landed cost | **YES** | **YES** | None | §11 |
| VAT / Input Tax | `vat_input` → 1530; `TaxCode.input_account_id` | **YES (accounts/roles)** | **YES** | **`finance_tax_codes` = 0 rows** | §12 |
| GL Posting | `PostingCoordinator` (via AP service) | **YES** | **YES** | Must not double-post with the bridge | §14 |
| Supplier Balance | `SupplierLedgerService::balance()` | **YES** | **YES** | No data until the AP path runs | §9 |
| Transaction Boundary | `DB::transaction` + nested savepoint + C-1 lock | **YES** | **YES** | Bridge is queued → unusable for the payable | §15 |
| Goods Inward Mode | `GoodsInwardAuthority` + `companies.goods_inward_mode` | **YES** | **YES** | Mode 1 default conflicts with contract timing | §16 |

## 20. Exact gaps

| # | Gap | Type | Blocks |
|---|---|---|---|
| **D-1** | `control_subledger` vocabulary mismatch: resolver reads `'ap'`/`'ar'`, seed writes `'payables'`/`'receivables'` — **AR is blocked too** | Configuration / one-line alignment | All AP + AR subledger posting |
| **D-2** | `finance_tax_codes` empty — no `TaxCode` links a rate to `1530 VAT Receivable (Input)` | Configuration | VAT-bearing bills (zero-VAT unaffected) |
| **D-3** | Posting-authority selection + rule targeting: confirm `AccountsPayableService` as sole authority, ensure `procurement.purchase_materials` does not also fire, and decide whether the invoice line debits `grni` (receipt-first) or `@inventory_class` (final-posting-only) | Contract decision — **evidence points to a single answer** (§14) | Correct, non-duplicated GL |
| **D-4** | Mode 1 vs Mode 3: the contract's inventory timing holds under Mode 3 but contradicts the Mode 1 default | **Business decision** (PART 10) | Company-wide rollout |
| **D-5** | `Nile Foods Trading` has no chart of accounts or roles | Configuration | Any posting for that company |

None of the PART 13 Outcome-C triggers is undefined: AP control account **defined**, inventory asset
mapping **defined**, supplier payable authority **defined**, VAT mapping **defined at account/role
level**, final cost authority **defined**, safe transaction boundary **exists**.

## 21. Recommended next step

1. **Resolve D-1** — align the control-subledger vocabulary. Recommended: **change the seed data to
   `'ap'`/`'ar'`** rather than the resolver, because the table migration already documents
   `// ar | ap | inventory | ...` as the intended vocabulary and `inventory`/`vat` already conform;
   changing the resolver would entrench the divergence. Either way it is a one-word alignment plus a
   data update for 6 rows, and it unblocks **AR as well as AP**.
2. **Ratify D-3** — record `AccountsPayableService` as the single financial posting authority for
   approved Purchasing invoices, and choose the invoice debit target (`grni` vs `@inventory_class`)
   consistently with the D-4 timing decision.
3. **Resolve D-2** — create the Egyptian VAT `TaxCode` (rate + `input_account_id → 1530`) through the
   existing Finance tax configuration.
4. **Rule D-4** — Mode 1 vs Mode 3 for the companies adopting this workflow.
5. Then implement **one** integration point calling `createDocument()` + `postDocument()` from the
   approved-invoice posting path — inside the existing transaction — with **no change to
   `AccountsPayableService`** and **no direct `SupplierLedgerEntry` write from Purchasing**.

## 22. Final verdict

**OUTCOME B — PARTIAL, SPECIFIC CONFIGURATION REQUIRED.**

The Finance architecture the approved Procurement contract needs **already exists and is reusable
without redesign**: the AP authority, the AP control account, inventory-asset accounts, the
product-class → account mapping, input-VAT accounts, GL posting, the supplier balance projection and a
safe atomic transaction boundary are all present, seeded and company-scoped. The canonical accounting
treatment is already expressed in active posting rules.

What is missing is **configuration and one ratified decision**, each with an existing canonical
mechanism: the `control_subledger` key alignment (D-1), a VAT tax code (D-2), the posting-authority
ratification (D-3, where the evidence permits only one answer), the Goods Inward mode ruling (D-4,
business), and chart-of-accounts coverage for one company (D-5).

This is **not** OUTCOME C. My earlier "no AP control account" finding was an incomplete inference and
is corrected in §0.

Stopping here as instructed — no implementation, and no follow-on Procurement task started. Awaiting
the Finance authority decision on D-1 through D-4.
