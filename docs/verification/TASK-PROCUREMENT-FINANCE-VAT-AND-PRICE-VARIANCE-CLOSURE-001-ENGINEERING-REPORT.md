# TASK-PROCUREMENT-FINANCE-VAT-AND-PRICE-VARIANCE-CLOSURE-001 — Engineering Report

**Date:** 2026-08-17 · **Branch:** `develop` · **Runtime:** MySQL 8.4.10 / PHP 8.4.24 / PHPUnit 11.5.55

**OUTCOME**

| | |
|---|---|
| **V-1 — VAT configuration** | **CLOSED and runtime-proven** (14%, canonical tax code, posts to 1530) |
| **V-2 — PPV account role** | **CLOSED** (`purchase_price_variance` → existing `5180`) |
| **V-2 — PPV / GRNI *posting*** | **STOP — PART 30 #3 and #4** |
| **Task certification** | **NOT CERTIFIED** |

The two accounting *decisions* you ruled are now configuration in the platform, and both are
verified against the real engine. What cannot be built is the **posting** that consumes them: the
data model provides no way to determine which receipt valuation an invoice line clears, so GRNI
cannot be cleared and PPV cannot be computed without inventing an allocation rule — which PART 16
and PART 30 forbid.

---

## 1. V-1 authority · 2. VAT architecture

`finance_tax_codes` is company-scoped and complete: `company_id` (NOT NULL), `tax_category_id`
(NOT NULL), `code`, `name`, `tax_type`, `rate`, `is_recoverable`, `input_account_id`,
`output_account_id`, `is_active`. `finance_tax_categories` mirrors it. `AccountsPayableService`
already consumes it — `buildDocumentPostingRequest()` reads
`TaxCode::find($line->tax_code_id)?->input_account_id` and adds the VAT leg. **No engine change was
needed; only the configuration was missing.** PART 30 #1 not triggered.

## 3. VAT configuration — CLOSED

Added `TaxCodeSeeder` (the canonical provisioning mechanism, matching `AccountRoleSeeder`'s
conventions) plus a migration that runs it for already-seeded databases.

**The rate lives in configuration, never in posting logic** — no `if rate == 14` exists anywhere.
**Accounts are resolved by role, never by code**: `vat_input` / `vat_output` are looked up through
`finance_account_roles`, so a company that re-points those roles gets its own accounts automatically.
No account id is hardcoded and no account was created.

| company | code | name | rate | type | recoverable | input | output |
|---|---|---|---|---|---|---|---|
| ECOS Holding 20 | `VAT14` | VAT 14% | **14.0000** | vat | yes | **1530 VAT Receivable (Input)** | 2210 |
| OSAMA FAYEZ AHEMD | `VAT14` | VAT 14% | 14.0000 | vat | yes | 1530 | 2210 |
| AxieFood | `VAT14` | VAT 14% | 14.0000 | vat | yes | 1530 | 2210 |

Idempotent, keyed on `(company_id, code)` — re-running never overwrites a rate a company has since
changed.

## 4. VAT posting — runtime-proven

Executed against the live engine through `AccountsPayableService`, inside a transaction that was
**rolled back** (final bill count 0 — no dev data created):

```
SCENARIO F — net 50,000 @ 14%
  subtotal = 50,000   tax = 7,000   total = 57,000
  1420  Raw Materials             Dr 50,000
  1530  VAT Receivable (Input)    Dr  7,000
  2110  Trade Payables                        Cr 57,000
  SupplierLedgerEntry = 57,000
```

This single proof closes four things at once: the D-1 control-account fix works (2110 resolved), the
V-1 tax code resolves and books exactly 14% to 1530, `expense_account_id` accepts an **inventory
asset** and yields `Dr Inventory Asset / Cr Trade Payables` as the audit predicted, and the supplier
ledger entry is written by the canonical service alone.

## 5. Zero VAT — preserved

```
SCENARIO G — net 40,000, tax_code_id = null
  tax_total = 0   total = 40,000
  1420  Raw Materials      Dr 40,000
  2110  Trade Payables               Cr 40,000        ← no VAT line
```

No invoice is forced to carry VAT (PART 5 satisfied).

## 6. V-2 authority · 7. PPV account — CLOSED

**`5180 Purchase Price Variance` already existed** in the seeded chart — `expense`,
`cost_of_sales`, **postable**, **non-control**, present for all three provisioned companies. It was
simply never mapped to a role. So **no account was created**; the closure is the role mapping, added
to `AccountRoleSeeder` alongside the other cost-of-sales roles:

| company | role | account | type | postable | control |
|---|---|---|---|---|---|
| ECOS Holding 20 | `purchase_price_variance` | **5180 Purchase Price Variance** | expense | yes | no |
| OSAMA FAYEZ AHEMD | `purchase_price_variance` | 5180 | expense | yes | no |
| AxieFood | `purchase_price_variance` | 5180 | expense | yes | no |

Resolvable through the existing `AccountRoleResolver`; no account id is hardcoded anywhere.
**PART 30 #2 not triggered.**

## 8–16. PPV / GRNI posting — **STOP (PART 30 #3 and #4)**

PARTs 9–13 require posting `Dr GRNI <received valuation>` and deriving
`PPV = invoice value − received valuation`, per line (PART 15) and across multiple receipts
(PART 16). **The data model cannot express which receipt valuation an invoice line clears:**

1. **`supplier_invoice_lines` has no receipt linkage.** Its columns are
   `supplier_invoice_id, product_id, description, quantity, unit_price, tax_rate, tax_amount,
   discount_amount, line_total, uom_*, allocated_freight, allocated_additional_costs,
   landed_unit_cost, notes` — **no `goods_receipt_line_id`**, no receipt reference of any kind.
2. **Only two header-level links exist**, `auto_receipt_id` and `auto_purchase_id`, and **neither is
   ever written by production code.** `GoodsInwardAuthority:17` states it outright: *"no production
   code path ever wrote that column."* A grep confirms `auto_purchase_id` appears only as a fillable
   attribute, a relation and a resource field — never assigned.
3. **Both links are singular**, so PART 16's case (one purchase, receipts of 40 @ 500 and 40 @ 520,
   one invoice for 80 @ 510) **cannot be represented at all** — there is no structure to attach two
   receipts to one invoice, let alone allocate value between them.

Consequences, stated precisely:

- **GRNI cannot be cleared at the received amount** (PART 19), because the received amount for the
  invoiced quantity is unknown → **PART 30 #4**.
- **PPV cannot be computed** (PARTs 10, 11, 15), because it is defined as invoice value minus that
  same unknown → **PART 30 #3**.
- Even the debit target is undecidable: my runtime proof debited `1420 Raw Materials` because I
  supplied that account. In the real Mode 1 flow it must be **GRNI** — and choosing correctly
  requires the receipt basis.

Recovering the basis would mean matching invoice lines to receipts by supplier + product + date or
FIFO order. That is exactly the fuzzy allocation PART 16 forbids, so I stopped.

**The fix is small and has a certified precedent in this codebase.** `SupplierReturnLine` solved the
identical problem with a mandatory **`goods_receipt_line_id` anchor** (SR-2), which makes the
receipt line the single source of truth for both scope and valuation ceiling. The same anchor on
`supplier_invoice_lines` would make GRNI clearing and per-line PPV deterministic, with no fuzzy
matching and no new accounting concept. **That is a schema + contract decision (V-5), not something
I should choose unilaterally.**

## 17. FIFO · 18. Supplier account · 19. Supplier ledger · 20. AP authority

Untouched and intact. No FIFO layer was read, written or rewritten; `ReceiveStockAction`,
`CreateReceiptLayersAction`, `InventoryLayerConsumptionService` and Mode 1 timing are all unchanged
(**PART 30 #6 and #7 not triggered**). `AccountsPayableService` remains the only writer of
`SupplierLedgerEntry` — this task added no payable path (**PART 30 #5 not triggered**), and the
supplier-ledger entry in Scenario F was produced by that service alone.

## 21. Tenant isolation

Every artefact added is company-scoped: `finance_tax_codes.company_id`,
`finance_tax_categories.company_id`, `finance_account_roles.company_id`. Each company received its
**own** `VAT14` code pointing at its **own** 1530, and its own PPV role pointing at its own 5180 —
verified in the tables above, three distinct company rows each. Nothing global was introduced, and
`AccountRoleResolver`/`ControlAccountResolver` filter by `company_id`. No reliance on FK errors.

## 22. Nile Foods Trading — correctly skipped

Unchanged from the previous determination: `is_active = 1` but **0 users, 0 accounts, 0 roles**, and
**no automated per-company chart provisioning mechanism exists** (only the setup seeders). Per
PART 22 I did not provision it — `TaxCodeSeeder` deliberately **skips any company whose `vat_input`
role does not resolve**, so it received no orphan tax code that could never post. Whether it is an
operational tenant remains a business determination (**V-3**).

## 23. UI

**Not changed.** No Finance tax-configuration UI exists today, so per PART 23 backend configuration
is acceptable and the **UI gap is recorded**: the VAT rate/code and the PPV role are currently
settable only through seeders/migrations, not through the Configuration workspace. No unrelated
configuration workspace was created and no account id was exposed to any UI.

## 24. Runtime proof

| Scenario | Status |
|---|---|
| **F — VAT invoice** | **PASS** — 7,000 on 50,000; 1420/1530/2110 correct; ledger 57,000 |
| **G — Zero-VAT invoice** | **PASS** — no VAT line, 40,000 balanced |
| A/B/C — receipt-cost vs invoice-cost (PPV) | **BLOCKED** — §8–16 |
| D — partial receipt (GRNI/PPV) | **BLOCKED** — §8–16 |
| E — rejected amendment | **NOT RUN** — the amendment workflow does not exist (never built; blocked in prior tasks) |

Both executed scenarios ran inside a rolled-back transaction; `finance_supplier_bills` returned to 0.

## 25. Regression

| Suite | Result |
|---|---|
| **`tests/Feature/Finance`** (consumes both changed seeders) | **OK — 147 tests, 1587 assertions** |
| Certified Procurement suites | Unaffected — no Purchasing/Inventory code path reads `finance_tax_codes` or `finance_account_roles`; last verified green earlier today (Supplier Return 20/20, Inbound Ownership 15/15, Cross-Document 11/11, GR Concurrency 8/8, Goods Inward Config 12/12) |

No certified business behaviour was altered.

## 26. Static verification

| Check | Scope | Result |
|---|---|---|
| `php -l` | new seeder + both migrations | **PASS** |
| Pint | 3 changed/new files | **PASS** |
| PHPStan **L0** | `Modules/Finance/Infrastructure/Database` | **`[OK] No errors`** |

**Task errors: 0.** Frontend unchanged → TypeScript/ESLint/Vite not run. PHPStan **core L6** not run
for these files: they are seeder definitions and data migrations, and the module-wide L6 baseline
(~184 errors, dominated by the `Authenticatable::$company_id` pattern) is **pre-existing** and
separated per PART 26. No claim of global cleanliness is made.

## 27. Database & deployment

Applied **only** this task's migrations, each with `--path=`:

```
2026_08_17_100000_align_control_subledger_vocabulary ......... 732.00ms DONE   (D-1, prior task)
2026_08_17_110000_seed_vat_code_and_ppv_role ................. 118.14ms DONE   (V-1 + V-2)
```

`2026_08_14_100000_create_recipe_cost_snapshots` remains **pending and untouched**. No
`migrate:fresh`, no reset, no dropped table, no other agent's migration.

**Parity — HOST == RUNNER == APP, `MSYS_NO_PATHCONV=1` throughout:**

| File | Hash | |
|---|---|---|
| `AccountRoleSeeder.php` | `9d720510d965173b` | **MATCH** |
| `TaxCodeSeeder.php` | `1a5f86b85f19304e` | **MATCH** |
| `2026_08_17_110000_seed_vat_code_and_ppv_role.php` | `d0675d1b86f0c664` | **MATCH** |
| `2026_08_17_100000_align_control_subledger_vocabulary.php` | `442ea673270b6f37` | **MATCH** |
| `ChartOfAccountsSeeder.php` | `94c4eea8330b5251` | **MATCH** |

Runtime behaviour was verified on the **running application**, not just on disk (§4).

## 28. Browser E2E

**Not performed — nothing new to exercise.** The PART 29 scenario (Purchase Material → partial
receiving → receiving review → invoice discrepancy → amendment approval → supplier account) depends
on the receiving-review and amendment workflows, which do not exist, and on the PPV/GRNI posting,
which is blocked. This task changed no user-facing surface. An authenticated session **is** available
(used earlier today to certify the Goods Inward Configuration UI), so E2E is not blocked by access.
No credentials were entered.

## 29. Remaining gaps

| # | Gap | Type | Blocks |
|---|---|---|---|
| **V-5** | **No link from a supplier invoice line to the goods receipt line(s) it pays for.** Header links are singular and never populated; no line-level anchor exists | **Schema + contract decision** | GRNI clearing, PPV computation, PART 16 multi-receipt |
| **V-6** | The Supplier Invoice **Amendment workflow does not exist** (entity, states, approval) | Feature not yet built | PARTs 13, 14, 28-E |
| **V-3** | Nile Foods Trading unprovisioned; no automated per-company chart provisioning mechanism | Business + provisioning | Any posting for that company |
| **V-4** | Mode 1 vs Mode 3 for companies adopting the receiving-approval workflow | Business decision (carried) | Rollout |
| **V-7** | No Finance tax-configuration UI — VAT rate/code settable only via seeder/migration | UI gap (PART 23 permits backend config) | Self-service VAT changes |

**Not triggered:** PART 30 #1 (VAT representable — proven), #2 (PPV representable — done), #5 (AP
authority intact), #6 (no FIFO mutation), #7 (Mode 1 unchanged), #8 (no conflict between certified
contracts).

## 30. Final certification

**NOT CERTIFIED.**

PART 31 requires, among others, favorable/unfavorable/zero PPV proofs, GRNI reconciling to zero, and
amendment approval/rejection behaviour. Those cannot be proven while V-5 leaves the invoice→receipt
valuation basis undefined and V-6 leaves the amendment workflow unbuilt. Producing them would require
inventing an allocation rule, which PARTs 16 and 30 forbid.

**What is closed, verified and now permanent:**

- **V-1 — VAT.** `VAT14` at **14%** exists per company through the canonical tax-code architecture,
  with input VAT resolved by role to **1530**. Runtime-proven: **7,000 on 50,000 net**, correctly
  booked, with zero-VAT invoices still posting cleanly. The rate is configuration; no posting logic
  knows the number 14.
- **V-2 — PPV account.** `purchase_price_variance` resolves to the **existing** `5180 Purchase Price
  Variance` for every provisioned company, through the standard role mechanism. No account created,
  no id hardcoded.
- Finance regression **147/147 (1587 assertions)**, static clean, parity MATCH, and only this task's
  migrations applied.

**One decision unblocks the rest.** Ruling **V-5** — adding a `goods_receipt_line_id` anchor to
`supplier_invoice_lines`, exactly as the certified `SupplierReturnLine` already does — makes GRNI
clearing and per-line PPV deterministic with no fuzzy matching and no new accounting concept. With
V-5 and V-6, the posting work becomes a single integration point calling `createDocument()` +
`postDocument()` with a GRNI debit and a PPV line, inside the existing transaction.

Stopping here as instructed. No certified contract was reopened, no Finance/Inventory/FIFO redesign
was performed, and no further Procurement task was started.
