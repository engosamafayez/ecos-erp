# TASK-PROCUREMENT-FINANCE-INTEGRATION-CLOSURE-001 — Engineering Report

**Date:** 2026-08-17 · **Branch:** `develop` · **Runtime:** MySQL 8.4.10 / PHP 8.4.24 / PHPUnit 11.5.55

**OUTCOME: D-1 CLOSED AND VERIFIED · TASK NOT CERTIFIED — 2 STOP CONDITIONS TRIGGERED**

| | |
|---|---|
| **D-1 control-subledger vocabulary** | **CLOSED** — AP **and** AR control resolution now work; Finance suite 147/147 green |
| **PART 29 #1 — VAT rate undefined** | **STOP** — no authoritative rate exists anywhere in the project |
| **PART 29 #7/#2 — price-amendment costing rule** | **STOP** — no purchase-price-variance account; GRNI cannot clear on a price amendment |

Production changes: **exactly two files** — the Finance chart-of-accounts seeder and one additive,
idempotent, reversible data migration. No accounting treatment was invented.

---

## 1. Historical authority

Confirmed against current HEAD/worktree, not assumed from the prior audit. The previous task
established `AccountsPayableService` as the sole supplier-payable authority and identified D-1…D-5.
This task closes **D-1** and stops on the two gaps that require accounting rulings.

## 2. Finance architecture audit

Everything the prior audit reported was re-verified. Two findings are **new and materially good**:

1. **Mode 1's GL posting already works, live.** `ReceiveStockAction` publishes
   `InventoryStockReceived` after commit (:111–128); `EventPostingCatalog` (:104) maps it to
   `BusinessEventType::GoodsReceipt`; the bridge is active (`auto_subscribe = true`, 44 rules). Two
   real journals already exist with `source_event_id = inventory.goods_receipt:…`. So
   **`Dr @inventory_class / Cr GRNI` is not theoretical — it is running.**
2. **The critical PART 5 invariant already holds.** The Goods Receipt credits **GRNI**, never Trade
   Payables. `Goods Receipt ≠ Supplier Payable` is true today, with no change required.

## 3. AP authority · 4. AR authority

`SupplierLedgerEntry` is written by exactly two call sites, both inside `AccountsPayableService`
(`postDocument()`:142, `postPayment()`:259). No other path exists anywhere in the platform, so
**PART 4's "one financial posting authority" is already structurally true** — nothing needed removing.

AR shares the same `ControlAccountResolver`, and was broken by the same defect (§5).

## 5. Control account vocabulary — D-1 CLOSED

**The defect.** `ControlAccountResolver::payable()` resolves `control_subledger = 'ap'` and
`receivable()` resolves `'ar'`. `ChartOfAccountsSeeder` wrote `'payables'` / `'receivables'`. Nothing
matched, so **both** threw. Because `postDocument()` resolves the control account before anything
else, **the AP and AR subledgers were unusable platform-wide** — which is precisely why
`finance_supplier_bills` and `finance_supplier_ledger_entries` are empty despite a complete AP
implementation.

**Why `ap`/`ar` is the correct side to align:** the accounts-table migration documents the vocabulary
in the column itself (`// ar | ap | inventory | ...`); the other seeded values (`inventory`, `vat`)
already conform; and `ControlAccountReconciliationService` also uses `'ar'`/`'ap'`. Aligning the
readers instead would entrench the only two divergent values.

**Safety, verified before changing anything:** nothing reads the old strings. A repo-wide search finds
`'payables'`/`'receivables'` only as unrelated dashboard array keys; the sole reader of the column is
`ControlAccountResolver`. No column renamed, no account created, deleted or re-pointed, and no control
account changed meaning — 2110 was already AP and still is.

**Result:**

| | before | after |
|---|---|---|
| `control_subledger` values | `payables`(3), `receivables`(3), `inventory`(12), `vat`(6), `payroll`(12) | **`ap`(3), `ar`(3)**, `inventory`(12), `vat`(6), `payroll`(12) |
| `ControlAccountResolver::payable()` | **throws** | **`2110 Trade Payables`** |
| `ControlAccountResolver::receivable()` | **throws** | **`1310 Trade Receivables`** |

## 6. VAT configuration — **STOP (PART 29 #1)**

PART 3 permits using an existing configured Egyptian VAT rate and **forbids inventing one**. An
exhaustive search found **no authoritative rate anywhere**:

- `finance_tax_codes` — **0 rows**; `finance_tax_categories` — **0 rows**
- **No TaxCode/TaxCategory seeder exists** anywhere in the repository
- `config/finance.php` contains no VAT or tax rate; no other config file does
- No company setting holds a tax/VAT rate (`config_company_settings` — no matching keys)
- The only `14` in the project is an **illustrative column comment** in the tax-codes migration:
  `$table->decimal('rate', 8, 4)->default(0);  // percent, e.g. 14.0000`
- The only rate actually stored is `supplier_invoice_lines.tax_rate`, which **defaults to 0** and is
  **client-supplied per line** (`$lineData['tax_rate'] ?? 0`) — user input, not a jurisdiction rate

Treating an "e.g." in a code comment as the authoritative Egyptian VAT rate would be inventing a tax
rate on the basis of a comment. **Stopped, as instructed.**

**Zero-VAT posting is unaffected and works:** `buildDocumentPostingRequest()` adds a tax leg only when
`$line->tax_amount > 0 && $line->tax_code_id !== null`, so a bill with `tax_code_id = null` posts
`Dr <account> / Cr Trade Payables` correctly with no VAT entry — satisfying PART 13's zero-VAT rule.

**Decision required (V-1):** the authoritative Egyptian VAT rate and its `TaxCode` (linking rate →
`input_account_id = 1530 VAT Receivable (Input)`, which already exists and is role-mapped as
`vat_input`).

## 7. Mode 1 · 8. Mode 3

**Mode 1 (default, `goods_receipt`) — the accounting model PART 5 describes is already implemented:**

```
Goods Receipt : Dr @inventory_class (net)   Cr grni (net)         ← live, 2 journals exist
Supplier Invoice : Dr grni  Dr vat_input   Cr ap_control (gross)  ← rule active, unfed
```

Resolved per company: `grni` → 2120 Goods Received Not Invoiced, `vat_input` → 1530,
`ap_control` → 2110, `@inventory_class` → 1410/1420/1430/1440.

**Mode 3 (`supplier_invoice`) — no conflict found with the certified inbound contract.** The invoice
is the inbound authority and posts inventory once; the receipt posts none. Proven earlier today by
`InboundCrossDocumentConcurrencyTest::test_g/test_i` and `GoodsReceiptConcurrencyTest::test_g`. The C-1
shared canonical inbound lock and `InboundPostingGuard` already prevent double posting. **PART 29 #3
is NOT triggered.** Mode 3 was not exercised end-to-end here because the payable leg is blocked (§6, §9).

The default was not changed and no mode was created.

## 9. GRNI — **STOP (PART 29 #7 / #2)**

For a **quantity-only** amendment the model closes perfectly. Receipt of 80 KG at 500 books GRNI
40,000; the approved invoice for 80 KG × 500 = 40,000 debits GRNI 40,000 → **GRNI clears exactly**.

For a **price** amendment it does not. Under PART 5's mandated Mode 1 timing, inventory, FIFO **and**
GRNI are all booked at **receipt cost**. If the invoice is then approved at a different unit price
(500 → 450), GRNI holds 40,000 while AP posts 36,000, leaving a **4,000 residual with no defined
home**:

- **No purchase-price-variance account or role exists** — verified: zero roles matching
  `variance` / `price` / `purchase` in `finance_account_roles`.
- **No retroactive FIFO cost correction exists** anywhere in `Modules/Inventory`.

PART 8 forbids leaving GRNI and a payable simultaneously outstanding "unless the existing accounting
model explicitly requires it", and PART 29 #7 requires a STOP when a price amendment needs a costing
rule that does not exist. **Both apply. Stopped.**

*(The previous contract avoided this by posting inventory only after invoice approval. PART 5 of this
task restores receipt-time posting for Mode 1, which necessarily reintroduces the variance question.)*

**Decision required (V-2):** the accounting treatment for a purchase price amendment after receipt —
a purchase-price-variance account, or a rule that price amendments are disallowed once received, or
Mode 3 timing for companies that need them.

## 10. Inventory accounting · 11. FIFO

Unchanged and correct. `@inventory_class` → role → company account is used throughout; **no account id
is hardcoded** and none was added. FIFO, `average_cost`, recipe cost and the product cost architecture
were not touched (PART 9/10 respected). `expense_account_id` was neither renamed nor redesigned.

## 12. Supplier invoice · 13. Invoice amendment · 14. Supplier account · 15. Supplier ledger

**Not implemented — blocked by §6 and §9.** The mechanism is ready and requires no new code in
Finance: Purchasing would call `createDocument()` + `postDocument()`, and the service writes the
`SupplierLedgerEntry` itself, so **no direct Purchasing ledger writer is needed** (PART 4/11
respected). `SupplierLedgerService::balance()` then projects the balance.

The amendment workflow's contracts from `…RECEIVING-INSPECTION-INVOICE-AMENDMENT-001` are preserved
and unmodified: a rejected amendment must create no `SupplierLedgerEntry`, which holds trivially since
nothing in the amendment path can write one.

## 16. Tenant isolation

Verified and unchanged. Every Finance table is company-scoped (`finance_accounts.company_id`,
`finance_account_roles.company_id`, `SupplierBill.company_id`, `SupplierLedgerEntry.company_id`), and
both `ControlAccountResolver` and `AccountRoleResolver` filter by `company_id`. The D-1 migration is
company-agnostic — it rewrites a vocabulary value and touches no `company_id`. Cross-company posting
fails closed for a company with no chart (§19).

## 17. Idempotency · 18. Atomicity

Unchanged; no new mechanism introduced. `AccountsPayableService::postDocument()` wraps journal + bill
+ ledger entry in **one `DB::transaction`**, and nests as a savepoint under an outer transaction — so
Inventory + FIFO + Payable can be established atomically inside `PostSupplierInvoiceService`'s
existing transaction, behind the C-1 shared lock. The queued bridge is correctly **not** used for the
payable (PART 17 respected).

## 19. Nile Foods Trading — determination only, no accounts created

| Company | active | accounts | roles | users | warehouses |
|---|---|---|---|---|---|
| ECOS Holding 20 | 1 | 100 | 43 | 2 | 1 |
| OSAMA FAYEZ AHEMD | 1 | 100 | 26 | 0 | 0 |
| AxieFood | 1 | 100 | 26 | 0 | 0 |
| **Nile Foods Trading** | 1 | **0** | **0** | **0** | 1 |

It carries `is_active = 1` but has **no users and no financial activity**, so whether it "must support
Procurement financial posting" is a business determination, not an engineering one. Critically,
**no automated per-company chart provisioning mechanism exists** — the only path is
`ChartOfAccountsSeeder`, run at setup. PART 14 says to reuse an automated mechanism if one exists;
none does, and PART 14 also forbids manually creating arbitrary accounts. **No accounts were created.**
This is **PART 29 #5** in the narrow sense and is reported rather than worked around (**V-3**).

## 20. Partial receipt · 21. Full receipt · 22. Rejected amendment · 23. Runtime E2E

**Not executed.** Every PART 18–21 scenario terminates in a supplier payable, which cannot be
established while V-1/V-2 are unresolved. Running only the inventory half would misrepresent the
contract as satisfied — the failure mode this programme has repeatedly recorded.

## 24. Regression

| Suite | Result |
|---|---|
| **`tests/Feature/Finance`** (exercises the changed seeder) | **OK — 147 tests, 1577 assertions** |
| `SupplierReturnValuationTest` (certified) | OK — 20 tests, 56 assertions ¹ |
| `InboundOwnershipContractTest` (certified) | OK — 15 tests, 49 assertions ¹ |
| `InboundCrossDocumentConcurrencyTest` | OK — 11 tests, 62 assertions ¹ |
| `GoodsReceiptConcurrencyTest` | OK — 8 tests, 41 assertions ¹ |
| `GoodsInwardModeConfigurationTest` | OK — 12 tests, 69 assertions ¹ |

¹ executed earlier today against this same deployed code. The D-1 change touches only
`finance_accounts.control_subledger`, which no Purchasing code path reads, so those suites are
unaffected; the Finance suite — the one that actually consumes the changed seeder — was re-run in full
and is green.

No certified business semantics were changed.

## 25. Static quality

| Check | Scope | Result |
|---|---|---|
| `php -l` | migration | **PASS** |
| Pint | seeder + migration | **PASS — 2 files** |
| PHPStan **L0** | `Modules/Finance/Infrastructure/Database` | **`[OK] No errors`** |

**Task errors: 0.** Frontend unchanged, so TypeScript/ESLint/Vite were not run. PHPStan **core L6** was
not run for this change: the two files are a seeder array and a data migration, and the module-wide L6
baseline (~184 errors, dominated by the `Authenticatable::$company_id` pattern documented previously)
is **pre-existing** and out of scope per PART 25's separation requirement.

## 26. Migration state

Pending before: `2026_08_14_100000_create_recipe_cost_snapshots`, plus this task's migration.
Applied via `--path=` targeting **only** this task's file:

```
2026_08_17_100000_align_control_subledger_vocabulary ......... 732.00ms DONE
```

Pending after: **`2026_08_14_100000_create_recipe_cost_snapshots`** — unrelated, still pending,
untouched. No `migrate:fresh`, no reset, no database dropped, no other agent's migration applied.

## 27. Deployment parity

| File | HOST | RUNNER | APP | |
|---|---|---|---|---|
| `ChartOfAccountsSeeder.php` | `94c4eea8330b5251` | `94c4eea8330b5251` | `94c4eea8330b5251` | **MATCH** |
| `2026_08_17_100000_align_control_subledger_vocabulary.php` | `442ea673270b6f37` | `442ea673270b6f37` | `442ea673270b6f37` | **MATCH** |

`MSYS_NO_PATHCONV=1` throughout. **Runtime behaviour verified on the running application**, not just
on disk: `ControlAccountResolver` resolves 2110/1310 live in `ecos-dev-app`. No unrelated dirty-tree
file was deployed.

## 28. Browser E2E

**Not applicable to this task's scope.** The PART 28 scenario (receiving → approval → amendment →
supplier account) exercises a workflow that does not exist, and no user-facing behaviour changed here.
An authenticated session **is** available (used earlier today to certify the Goods Inward
Configuration UI), so E2E is not blocked by access — there is simply nothing new to exercise. No
credentials were entered.

## 29. Known gaps

| # | Gap | Type | Blocks |
|---|---|---|---|
| **V-1** | Authoritative Egyptian VAT rate undefined — no tax code, category, seeder, config value or company setting; only an illustrative comment | **Business/Finance configuration** | VAT-bearing invoices (zero-VAT unaffected) |
| **V-2** | No purchase-price-variance account and no retroactive FIFO correction → a price amendment after receipt leaves GRNI unclearable in Mode 1 | **Accounting policy** | Price amendments; PART 8 GRNI reconciliation |
| **V-3** | Nile Foods Trading has no chart of accounts, and no automated per-company provisioning mechanism exists | Business determination + provisioning | Any posting for that company |
| **V-4** | Mode 1 vs Mode 3 for companies adopting the receiving-approval workflow | Business decision (carried from the prior audit) | Company-wide rollout |

**Not triggered:** PART 29 #3 (Mode 3 vs certified inbound — no conflict), #4 (single payable path —
already structurally true), #6 (no FIFO redesign needed), #8 (no genuine conflict between committed
Finance contracts).

## 30. Final certification

**NOT CERTIFIED.**

PART 30 requires, among others, "VAT configuration works", "GRNI works", "Invoice Amendment approval
works" and "Mode 1/Mode 3 work" end-to-end. V-1 and V-2 make those unprovable without inventing a tax
rate and an accounting treatment, which PARTs 3, 8 and 29 explicitly forbid.

**What is genuinely closed and certified-by-test:**

- **D-1 is fixed and verified.** AP **and** AR control resolution work for the first time; the
  Finance suite passes **147/147 (1577 assertions)**; static clean; parity MATCH; runtime-verified on
  the live application. This was a latent platform-wide defect that silently disabled both subledgers,
  and it is now closed.
- **Mode 1's `Dr Inventory / Cr GRNI` posting was found already working**, and PART 5's key
  invariant — *Goods Receipt never creates supplier payable* — already holds.
- **The single-payable-authority requirement is structurally satisfied**: `AccountsPayableService`
  is the only writer of `SupplierLedgerEntry`, so no duplicate payable leg existed to remove.

With **V-1** and **V-2** ruled, the remaining work is one integration point calling
`createDocument()` + `postDocument()` from the approved-invoice path inside the existing transaction —
no change to `AccountsPayableService`, no second AP subsystem, and no direct `SupplierLedgerEntry`
write from Purchasing.

Stopping here as instructed. No further Procurement task was started and no certified contract was
reopened.
