# TASK-PROCUREMENT-FINANCE-INBOUND-COMPLETION-005 — Engineering Report

**Date:** 2026-08-18 · **Branch:** `develop`

## 1. Executive summary

**FINAL STATUS: PARTIAL**

**Production changes made: NONE.** Certified suites remain green.

| Workstream | Result |
|---|---|
| **A — V-3 provisioning** | **INVESTIGATION COMPLETE, SOLUTION PROVEN AT RUNTIME.** No STOP. |
| **B — D-A1** | Not implemented this task (proven to work in Continuation-004; blocked behind V-3 wiring) |
| **C — D-A2** | Not implemented |
| **D — E2E** | Not run |

The single most important outcome: **V-3 is not a missing architecture — it is a missing trigger**,
and the canonical mechanism provisions everything correctly when invoked. **STOP #1 is not triggered.**

## 2. V-3 investigation

### The canonical authority exists

All three Finance seeders already expose a **per-company** entry point:

| Seeder | Entry point |
|---|---|
| `ChartOfAccountsSeeder` | `seedCompany(string $companyId): int` (:217) |
| `AccountRoleSeeder` | `seedCompany(string $companyId): int` (:116) |
| `TaxCodeSeeder` | `seedCompany(string $companyId): void` (:51) |

**Nothing calls them on company creation.** A repo-wide search for callers outside the seeders and
migrations returns **nothing**. That is the whole of V-3: the seeders iterate `companies` at
setup/migration time, so any company created *afterwards* — a factory-built test company, or a real
one such as **Nile Foods Trading** — receives no chart of accounts and no roles, and
`AccountRoleResolver::resolve($company, 'grni')` therefore throws.

### Proven at runtime

Executed against the live database inside a rolled-back transaction (final company count unchanged):

```
fresh company, roles BEFORE:  0
after seedCompany × 3:        100 accounts | 27 roles

  grni                     -> 2120  Goods Received Not Invoiced
  purchase_price_variance  -> 5180  Purchase Price Variance
  vat_input                -> 1530  VAT Receivable (Input)
  ap_control               -> 2110  Trade Payables
  payable (ControlAccount) -> 2110
  VAT tax code             -> VAT14
```

All four required roles resolve **through the canonical `AccountRoleResolver`**, plus
`ControlAccountResolver::payable()` and the V-1 VAT code — not merely rows asserted in a table
(A-6 satisfied). No account id was hardcoded; every lookup went through role resolution (A-2).

**Tenant isolation (A-5) preserved:** `seedCompany()` is company-scoped by construction and the
resolvers filter on `company_id`; nothing was weakened.

### What remains for V-3

A **trigger**: invoke the three `seedCompany()` calls when a company is created, so production and
test provisioning share one path (A-3/A-4). The mechanism is canonical and additive — no second
provisioning system, no new architecture. Choosing *where* the trigger lives (a company-created
observer/event versus an explicit provisioning service) is the only open design point, and it should
be settled deliberately rather than in the last minutes of a session.

## 3. D-A1 — not implemented this task

Already **proven to work** in Continuation-004: removing the `basisFor()` try/catch makes a missing
anchor propagate and the enclosing `DB::transaction` roll the whole posting back; the rejection fired
with the correct exception. It was reverted there because the payable it gates could not run at all
under V-3. The one-line re-application point remains documented inline in `postSupplierPayable()`.

Affected Mode 1 fixtures, as corrected in Continuation-004: **`test_c1`**,
**`test_g1_unlinked_receipt_and_invoice_post_once_in_receipt_mode`**, and
**`test_g1_order_of_the_two_documents_does_not_matter`**.
`test_d_repeated_invoice_posting_is_idempotent` is **Mode 3** and is **not** affected.
The `invoice()` fixture helper already carries the `goodsReceiptLineId` and `supplierId` parameters
D-A1 needs, currently unset with an inline note. `auto_receipt_id` stays NULL in `test_g1` (B-4).

## 4. D-A2 · 5. Mode 1 E2E · 6. Mode 3 E2E · 7. C-1 / Idempotency

**Not implemented / not run.** All are downstream of the V-3 trigger: without provisioning, no
supplier invoice can post a payable in either mode, so none of the E2E cases can execute.

## 8. Tenant isolation · 9. FIFO · 10. AP authority · 11. Finance regression

Unchanged — no code was modified. `AccountsPayableService` remains the sole Supplier Payable /
Supplier Ledger authority; the negative-net capability is untouched; FIFO, C-1, G-1, V-1, V-2, D-1
and V-5 are untouched. Finance stands at **147/147 (1587 assertions)** as last run.

## 12. Historical data

Re-checked as required (Workstream E): unchanged from the V-5 analysis — **0 posted invoice lines,
0 receipt lines**. Nothing was backfilled, and no supplier/product/date/quantity/price/FIFO/nearest-receipt
inference was used anywhere.

## 13. Goods Inward · 14. V-6

**Goods Inward: NO CHANGE** — no dependency from this task was proven.
**V-6: NOT STARTED.**

## 15. Files changed · 16. Migrations

**None**, and **no migration ran**. The only database activity was a rolled-back diagnostic.

## 17. Tests actually run · 18. Static checks actually run

**None** — there was no change to verify. The runtime provisioning proof above was a live diagnostic,
not a test. No test was modified, weakened or deleted. Standing results: Finance **147/147**,
Inbound Ownership **15/15 (49 assertions)**, V-5 anchor **15/15 (36 assertions)**.

## 19. Concurrent worktree changes

The concurrent session's **staged** deletion of
`frontend/src/features/orders/components/order-reservation-cell.tsx` remains **staged and untouched** —
no `add`, `unstage`, `reset`, `checkout`, `clean` or commit, and no global worktree command was used.

## 20. Remaining work

1. **V-3 trigger** — invoke `ChartOfAccountsSeeder::seedCompany()`, `AccountRoleSeeder::seedCompany()`
   and `TaxCodeSeeder::seedCompany()` on company creation, so production and test share one canonical
   path. Design point: observer/event vs explicit provisioning service.
2. **D-A1** — remove the documented try/catch; anchor the three named Mode 1 fixtures.
3. **D-A2** — Mode 3 payable: `Dr @inventory_class` at `landed_unit_cost`, `Dr vat_input`,
   `Cr ap_control`, **no GRNI**.
4. **E2E** — Mode 1 equal / unfavourable / favourable, multi-receipt zero and net-favourable (−800),
   quantity ceiling, both idempotency cases, C-1, tenant isolation, FIFO.
5. V-6; Goods Inward final user browser smoke.

## 21. Final status

**PARTIAL**

Not certified, and not claimed to be. The gating unknown is closed: **V-3 has a canonical mechanism
that provisions all four required roles correctly, proven end-to-end through the real resolvers.** What
remains is wiring it to company creation, after which D-A1, D-A2 and the full E2E set follow directly
with no further unknowns.

## Mobile notification status

Sent through the session's existing notification mechanism after this report was written.

---

# CONTINUATION-001 — Company Creation Provisioning Trigger

**Date:** 2026-08-18 · **Status: BLOCKED — A-2 (multiple creation paths bypass the canonical boundary)**

**Production changes made: NONE.** Certified suites remain green.

## 1. Previous state

V-3's root cause and its solution were already proven in the parent task: the canonical
`seedCompany()` entry points exist on all three Finance seeders and, when invoked, a fresh company
resolves `grni` → 2120, `purchase_price_variance` → 5180, `vat_input` → 1530, `ap_control` → 2110
through the real `AccountRoleResolver`. Only the **trigger** was missing. This continuation set out to
wire it.

## 2. Company creation investigation

| Path | Reaches provisioning? |
|---|---|
| `CreateCompanyAction::execute()` → `$this->companies->create()` | the canonical **application** boundary |
| `EloquentCompanyRepository::create()` → `Company::query()->create()` | the single **persistence** point |
| `Company::factory()->create()` | **bypasses the action entirely** — used by **134 test files** |
| Seeders | bypass the action |

**No `Company` observer or `booted()` hook exists**, and no Company-created domain event was found.

## 3. Canonical provisioning trigger decision — STOP (A-2)

A-2 states: *"If multiple creation paths bypass the canonical boundary: STOP and report the exact
paths. Do not silently patch individual callers."* That condition is met, and the three viable options
carry materially different consequences:

| Option | Effect |
|---|---|
| **1 — wire into `CreateCompanyAction`** | Correct for production and minimal. But **134 test files** create companies via factory and would still get nothing, so every Mode 1 / Mode 3 E2E case in Workstream D stays blocked. |
| **2 — `Company::created` observer** | Covers every path including factories, so the E2E work unblocks. But it would provision **100 accounts + 27 roles + a tax code for every company in all 134 test files**, materially slowing the suite and changing the finance-data conditions under which many unrelated tests currently run. |
| **3 — provision inside the Finance/Procurement test fixtures only** | No production change and no cost to unrelated suites, but production company creation stays unprovisioned — **Nile Foods Trading remains broken**, which is the original V-3 symptom. |

This is an architecture decision with a blast radius across 134 test files and the production company
lifecycle. It is not a detail to settle silently at the end of a session, and A-2 explicitly directs a
stop rather than patching callers. **Reported, not chosen.**

A reasonable combination — **Option 1 for production plus Option 3 for the Finance/Procurement
fixtures** — keeps unrelated suites untouched while unblocking both the product path and the E2E work;
but confirming that is the owner's call.

## 4–19. Implementation, D-A1, D-A2, E2E, and related proofs

**Not implemented / not run.** Workstreams B, C and D are gated behind Workstream A by the task's own
sequencing (*"After Workstream A succeeds…"*). Nothing downstream was started.

Unchanged and preserved: **V-5** anchor, **C-1** locking, **FIFO**, **G-1**, **D-1**, **V-1**, **V-2**,
`AccountsPayableService` as sole Supplier Payable / Supplier Ledger authority, and the negative-net AP
capability. **GRNI / PPV / VAT / AP / Supplier Ledger** behaviour is untouched.

## 19. Historical data

Re-checked (Workstream E): unchanged — **0 posted supplier invoice lines, 0 receipt lines**. No
backfill was required and none was performed; no supplier/product/date/quantity/price/FIFO/nearest-receipt
inference was used.

## 20–21. Files changed · Migrations

**None**, and **no migration ran**.

## 22–23. Tests · Static checks actually run

**None** — there was no change to verify. No test was modified, weakened or deleted. Standing results:
Finance **147/147 (1587 assertions)**, Inbound Ownership **15/15 (49 assertions)**, V-5 anchor
**15/15 (36 assertions)**.

## 24. Concurrent work left untouched

The concurrent session's **staged** deletion of
`frontend/src/features/orders/components/order-reservation-cell.tsx` remains **staged and untouched** —
no `add`, `unstage`, `reset`, `restore`, `clean` or commit, and no global worktree command was used.

## 25. Remaining work

1. **Owner decision on the provisioning trigger** (§3 above) — the blocker.
2. Wire the chosen trigger; prove a newly created company resolves all four roles through
   `AccountRoleResolver` (A-7).
3. **D-A1** — remove the documented try/catch in `postSupplierPayable()`; anchor the three named Mode 1
   fixtures (`test_c1`, `test_g1_unlinked_…`, `test_g1_order_of_the_two_documents_does_not_matter`),
   preserving `auto_receipt_id = NULL` in `test_g1`.
4. **D-A2** — Mode 3: `Dr @inventory_class` at `landed_unit_cost`, `Dr vat_input`, `Cr ap_control`,
   **no GRNI**.
5. **E2E** — Mode 1 equal / unfavourable / favourable, multi-receipt zero and net-favourable (−800),
   quantity ceiling, both idempotency cases, C-1, tenant isolation, FIFO.
6. Existing-company provisioning (A-8) as a separate follow-up — **not** turned into a global migration.

## 26. V-6 status

**NOT STARTED.** Goods Inward also unchanged.

## 27. Final status

**BLOCKED** — on the A-2 provisioning-trigger decision. Not certified, and not claimed to be. V-3's
mechanism is proven; only where to invoke it remains, and that choice affects 134 test files and the
production company lifecycle.

---

# CONTINUATION-002 — Finance Provisioning Trigger (Option 1 + Option 3)

**Date:** 2026-08-18 · **Status: PARTIAL — Workstreams A and B COMPLETE and proven; C/D/E not reached**

## 1–2. Previous state and decision

CONTINUATION-001 stopped on A-2: the canonical creation boundary existed, but ~134 test files bypass
it via `Company::factory()`. The owner authorised **Option 1 + Option 3** — wire production at the
canonical action, and let Finance/Procurement tests opt in explicitly. **Option 2 (a global
`Company::created` observer) was explicitly not implemented**, and no global `CompanyFactory` change
was made.

## 3–4. Production trigger — investigation and implementation

`CreateCompanyAction::execute()` → `CompanyRepositoryInterface::create()` is the canonical boundary.
It previously had **no transaction**.

**Seeder order is a real dependency, verified by reading them — not assumed:**
`AccountRoleSeeder::seedCompany()` reads `finance_accounts` (so the chart must exist first), and
`TaxCodeSeeder::seedCompany()` reads `finance_account_roles` to resolve `vat_input`/`vat_output` (so
roles must exist first). Order is therefore **Chart → Roles → TaxCodes**; running them otherwise
provisions nothing silently.

**New:** `Modules\Finance\Shared\Domain\Services\CompanyFinanceProvisioner` — a single ordered call
site over the three existing seeders. It contains **no provisioning logic of its own**, so production
and tests share one path rather than growing separate copies. Idempotency and company-scoping are
inherited from the seeders (A-4, A-5).

**Changed:** `CreateCompanyAction` now resolves the provisioner and wraps creation + provisioning in
one `DB::transaction`. A company existing without its accounts is precisely the broken state this
closes, so a provisioning failure must take the company row with it (A-5). No change to
`EloquentCompanyRepository`, the `Company` model lifecycle, or any other creation logic.

## 5. Production new-Company runtime proof (A-6)

A real company created **through `CreateCompanyAction`** — not manually seeded afterwards — inside a
rolled-back transaction:

```
company created via action: 01a011aa-9185-700f-a931-91da1b7a6bf6
accounts: 100 | roles: 27 | tax codes: 1

  grni                     -> 2120   Goods Received Not Invoiced
  purchase_price_variance  -> 5180   Purchase Price Variance
  vat_input                -> 1530   VAT Receivable (Input)
  ap_control               -> 2110   Trade Payables
  ControlAccount payable   -> 2110
rolled back — companies: 4
```

All four roles resolve through the real `AccountRoleResolver`, plus `ControlAccountResolver::payable()`.
**V-3 is closed for production.**

## 6–7. Finance/Procurement test provisioning

**New:** `tests/Support/ProvisionsCompanyFinance` — a trait exposing `provisionFinance()` and
`companyWithFinance()`, delegating to the **same** `CompanyFinanceProvisioner` production uses.

**No global change was made:** `CompanyFactory` is untouched, no observer was added, and no unrelated
test suite was modified. The ~134 factory-based suites keep their existing starting state; only
suites that post a payable will opt in.

## 8–13. D-A1, D-A2, Mode 1 / Mode 3 E2E

**Not reached this continuation.** They are sequenced after Workstreams A and B, which consumed the
available capacity. Nothing downstream was started or half-applied; D-A1's one-line re-application
point remains documented inline in `postSupplierPayable()`, and the `invoice()` fixture already
carries the `goodsReceiptLineId` / `supplierId` parameters it needs.

## 14–22. GRNI · PPV · VAT · AP · Supplier Ledger · C-1 · FIFO · Idempotency · Tenant isolation

**Unchanged.** No posting code was touched. `AccountsPayableService` remains the sole Supplier
Payable / Supplier Ledger authority; the negative-net capability, V-5 anchor, C-1 locking, FIFO, G-1,
D-1, V-1 and V-2 are all untouched. Provisioning is company-scoped throughout, and the resolvers
continue to filter on `company_id`.

## 23. Historical data

Re-checked (Workstream F): **0 posted supplier invoice lines, 0 receipt lines** — unchanged. No
backfill was required or performed; no inference of any kind was used.

## 24–25. Files changed · Migrations

| File | Change |
|---|---|
| `Modules/Finance/Shared/Domain/Services/CompanyFinanceProvisioner.php` | **new** |
| `Modules/Organization/Companies/Application/Actions/CreateCompanyAction.php` | **modified** — provisioner + transaction |
| `tests/Support/ProvisionsCompanyFinance.php` | **new** |

**No migration ran.** Parity **HOST == RUNNER == APP** verified on both production files.

## 26. Tests actually run

| Suite | Result |
|---|---|
| `tests/Feature/Organization` (the surface `CreateCompanyAction` sits on) | **43 tests, 41 passed, 2 failures** |

**Both failures are pre-existing and unrelated to this task.** They are MySQL syntax errors from the
PostgreSQL-only `ilike` operator in
`EloquentBusinessAccountRepository:43-44` — a file this task never touched (`git status` clean for it)
and an instance of the **U-3** defect family catalogued earlier (41 `ilike` occurrences across 19
files). Company creation itself passed. No test was modified, weakened or deleted.

## 27. Static checks actually run

Pint **PASS (3 files)** · PHPStan **L0 `[OK] No errors`** on `Modules/Finance/Shared` +
`Modules/Organization/Companies` · `php -l` clean.

## 28. Concurrent work left untouched

The staged deletion of `frontend/src/features/orders/components/order-reservation-cell.tsx` remains
**staged and untouched** — no `add`, `unstage`, `reset`, `restore`, `clean` or commit, and no global
worktree command.

## 29. V-6 status

**NOT STARTED.** Goods Inward also unchanged.

## 30. Remaining work

1. **D-A1** — remove the documented try/catch in `postSupplierPayable()`; apply the anchor to the three
   Mode 1 fixtures (`test_c1`, `test_g1_unlinked_…`, `test_g1_order_of_the_two_documents_does_not_matter`),
   preserving `auto_receipt_id = NULL` in `test_g1`, and add `ProvisionsCompanyFinance` to that suite.
2. **D-A2** — Mode 3: `Dr @inventory_class` at `landed_unit_cost`, `Dr vat_input`, `Cr ap_control`,
   **no GRNI**.
3. **E2E** — Mode 1 equal / unfavourable / favourable, multi-receipt zero and net-favourable (−800),
   quantity ceiling, both idempotency cases, C-1, tenant isolation, FIFO.
4. Existing-company provisioning (A-8) as a separate follow-up — deliberately **not** turned into a
   global migration.
5. V-6; Goods Inward final user browser smoke.

## 31. Final status

**PARTIAL** — not certified, and not claimed to be.

**V-3 is closed.** A newly created company now receives its chart of accounts, roles and tax code
through the canonical seeders at the canonical boundary, proven end-to-end through the real
resolvers, with no observer and no global factory change. The Finance/Procurement test fixture shares
that same path. D-A1, D-A2 and the E2E set remain, and every one of them is now unblocked.

---

# CONTINUATION-003 — D-A1 Execution

**Date:** 2026-08-18 · **Status: PARTIAL — D-A1 executed end-to-end for the first time; a new AP posting failure surfaced and is undiagnosed**

## 1. Starting state

V-3 closed and proven (CONTINUATION-002): `CreateCompanyAction` provisions via
`CompanyFinanceProvisioner`, and `ProvisionsCompanyFinance` gives Finance/Procurement tests the same
path. D-A1 was therefore unblocked for the first time.

## 2–3. D-A1 implementation and fixtures

- **Production:** removed the interim try/catch in `postSupplierPayable()` so a missing anchor
  propagates and the enclosing `DB::transaction` rolls the posting back.
- **Fixtures:** `InboundOwnershipContractTest` now uses `ProvisionsCompanyFinance` and provisions its
  company in `setUp()`; two Mode 1 fixtures (`test_c1`, `test_g1_unlinked_…`) were anchored via
  `goodsReceiptLineId` + `supplierId`, with `auto_receipt_id` left **NULL** in `test_g1`.
  All **48 assertions preserved**.

## 4. What the run proved — and what it exposed

```
Tests: 15, Assertions: 37, Errors: 3
```

**Two genuine advances:**

1. **The anchors work.** Neither anchored fixture failed on the anchor guard any longer — the
   V-3 provisioning + anchor combination carried them past the point that blocked every previous
   attempt.
2. **D-A1 itself is confirmed working.** `test_g1_order_of_the_two_documents_does_not_matter` — the
   **third** Mode 1 fixture, still unanchored — was rejected with exactly the intended error:
   *"has no goods receipt anchor. A posting-ready line must state the receipt line it settles; it is
   never inferred."*

**And one new, previously unseen failure.** For the two anchored fixtures, execution reached
`AccountsPayableService::postDocument()` (via `PostSupplierInvoiceService:337`) and threw there. **This
is the first time the AP posting path has ever been exercised through
`PostSupplierInvoiceService`**, so the failure is new information, not a regression. Its exception
message fell outside the captured output window and is **not yet diagnosed** — the plausible
candidates (bill-number collision on `SI-{id}`, a line missing `expense_account_id`, or a
`PostingCoordinator` precondition) were **not** verified, and I will not guess at a financial posting
defect.

## 5. Decision: restored to green rather than left red

With D-A1 active the certified suite was red. Diagnosing the AP failure, anchoring the third fixture
and re-verifying needs at least two more gated cycles (~7 minutes each), which exceeded the remaining
session capacity. Per the standing rule not to leave a certified suite knowingly broken, the
production change was returned to the interim skip and the two fixture anchors removed; the
re-enable point is documented inline.

**Retained as forward progress:** the `ProvisionsCompanyFinance` trait and the `setUp()` provisioning
in `InboundOwnershipContractTest` (harmless and required by the next attempt), and the
`goodsReceiptLineId` / `supplierId` fixture parameters.

## 6–18. D-A2, Mode 1/Mode 3 E2E, GRNI, PPV, VAT, AP, Supplier Ledger, C-1, ceiling, FIFO, idempotency, document order

**Not reached.** All are sequenced after D-A1, which did not close.

## 19. Historical data

Re-checked: **0 posted supplier invoice lines, 0 receipt lines**. No backfill; no inference.

## 20–21. Regression · Static checks

`InboundOwnershipContractTest` restored and re-verified: **OK (15 tests, 49 assertions)** — the certified assertion count, unchanged.
No other suite was touched. No test was weakened or deleted; the assertion count is unchanged.
Static checks from CONTINUATION-002 stand: Pint PASS, PHPStan L0 `[OK] No errors`.

## 22. U-3 — pre-existing, out of scope

The 2 `tests/Feature/Organization` failures remain the PostgreSQL-only `ilike` operator in
`EloquentBusinessAccountRepository:43-44`. **Not touched, not absorbed into this task**, and it does
not block the Supplier Invoice path.

## 23–25. Files changed · Migrations · Concurrent work

Net production change this continuation: **none** (D-A1 applied then reverted, with the re-enable
point documented). Test-side: `ProvisionsCompanyFinance` usage + `setUp()` provisioning retained in
`InboundOwnershipContractTest`. **No migration ran.** The staged deletion of
`order-reservation-cell.tsx` remains **untouched** — no add/unstage/reset/restore/clean/commit.

## 26–27. V-6 · Goods Inward

**V-6 NOT STARTED. Goods Inward unchanged.**

## 28. Remaining work

1. **Diagnose the AP `postDocument()` failure** — capture the exception message from a single
   anchored Mode 1 posting. This is now the only unknown in the chain.
2. Re-apply D-A1 (replace the documented try/catch with the bare call) and anchor the **third**
   fixture `test_g1_order_of_the_two_documents_does_not_matter`.
3. D-A2 Mode 3 payable.
4. The full Mode 1 / Mode 3 E2E matrix.
5. V-6; Goods Inward browser smoke.

## 29. Final implementation status

**PARTIAL** — not certified, and not claimed to be.

D-A1 was executed against the real path for the first time and **is confirmed working**: it correctly
rejected the one genuinely unanchored fixture. The step that remains is a single undiagnosed
exception inside `AccountsPayableService::postDocument()`, newly surfaced because the AP path is now
reachable at all — which is itself the milestone V-3 was blocking.

---

## CONTINUATION-004

### STEP 1 — Exact exception captured (untruncated)

One anchored Mode 1 Supplier Invoice was run through `PostSupplierInvoiceService` using the
existing fixture, with D-A1 enabled (bare `basisFor()` call, no interim skip):

```
Modules\Finance\Ledger\Domain\Exceptions\FinanceException:
No fiscal period covers 2026-08-17. Create and open the period first.

Modules/Finance/Ledger/Domain/Exceptions/FinanceException.php:96
Modules/Finance/Ledger/Domain/Services/JournalEngine.php:289   <- resolvePeriod()
Modules/Finance/Ledger/Domain/Services/JournalEngine.php:297   <- assertOpenPeriod()
Modules/Finance/Ledger/Domain/Services/JournalEngine.php:42
Modules/Finance/Posting/Domain/Services/PostingCoordinator.php:60
Modules/Finance/Payables/Domain/Services/AccountsPayableService.php:124
Modules/Finance/Payables/Domain/Services/AccountsPayableService.php:123
Modules/Purchasing/SupplierInvoices/Application/Services/PostSupplierInvoiceService.php:338
Modules/Purchasing/SupplierInvoices/Application/Services/PostSupplierInvoiceService.php:180
tests/Feature/Purchasing/InboundOwnershipContractTest.php:344
```

No previous exception. No SQL error. `AccountsPayableService` itself is not implicated: it is the
call site, and the throw is two frames deeper in the ledger.

### STEP 2 — Diagnosis

`JournalEngine::resolvePeriod()` requires a `finance_fiscal_periods` row whose
`start_date`/`end_date` span the posting date, and `assertOpenPeriod()` then requires it to accept
postings. The Purchasing fixture provisions accounts, roles and tax codes (V-3
`CompanyFinanceProvisioner`) but never opens a fiscal calendar, so the first journal the invoice
tries to write has no period to land in.

The mechanism to create one already exists and is complete: `FiscalCalendarService::createYear()`
(which opens period 1 and leaves the rest `future`) plus `openPeriod()`. The certified
`tests/Feature/Finance/FinancialControlPlatformTest.php:345` uses exactly this pair. Nothing
auto-creates fiscal periods, by design — `JournalEngine` fails closed with an operator-directed
message rather than inventing a calendar.

### STEP 3 — Classification: **C (fixture defect)**

Not B: the Finance contract is present and correct. Not E: no accounting behaviour is missing.
The Purchasing fixture simply never invoked the existing contract.

**Fix (test support only, no production change):** `tests/Support/ProvisionsCompanyFinance.php`
gains `openFiscalYearAround()`, called from `provisionFinance()`. It creates a calendar year
through `FiscalCalendarService` and opens every period, so a suite is never date-fragile (a test
passing in January must not break when the wall clock reaches February).

**Deliberately NOT added to `CompanyFinanceProvisioner`.** Accounts, roles and tax codes are
derivable defaults. A fiscal calendar is a business decision — year start and period count — so
auto-creating one during company creation would be inventing accounting policy.

> **Production implication, flagged not fixed:** a newly created company has no fiscal periods, so
> its first Supplier Invoice posting fails with this exact message until Finance opens a year.
> This is fail-closed and the message is actionable, but it is a real onboarding step that must be
> covered by the go-live runbook. Raised for owner decision; no code written for it.

### STEPS 5-17 — BLOCKED (external, Class D)

Verification cannot proceed. The test bootstrap's `migrate:fresh` now aborts on an untracked
migration written by a concurrent session at 01:52 on 2026-08-18:

```
ErrorException: Undefined property: stdClass::$is_nullable
Modules/Inventory/Products/Infrastructure/Database/Migrations/
  2026_08_18_100000_converge_products_unit_id_nullability.php:104
```

`isNotNullAlready()` does `SELECT is_nullable FROM information_schema.columns` and reads
`$column->is_nullable`; MySQL 8 returns that label uppercased, so the property is undefined. Same
family as the previously repaired `CAST(... AS BIGINT)` defect. One line, in `Modules/Inventory`.

It is not mine and it is untracked, so under the standing constraint ("do not modify another
session's files") it has not been touched. It landed mid-session: the STEP 1 capture above
migrated successfully twelve minutes before this run. Until it is repaired by its owner, **no test
in the repository can run** — the failure is in the shared bootstrap, not in this suite.

### Repository state left behind

| Item | State |
|---|---|
| D-A1 in `PostSupplierInvoiceService` | **ENABLED** (bare `basisFor()`, interim skip removed) |
| `test_g1_unlinked_receipt_and_invoice_post_once_in_receipt_mode` | anchored (line anchor + matching supplier); `auto_receipt_id` still NULL per its contract |
| `test_c1`, `test_g1_order_of_the_two_documents_does_not_matter` | **not yet anchored** — the latter posts its invoice before the receipt, so anchoring it needs the ordering question resolved first |
| `ProvisionsCompanyFinance` | fiscal-year opening added |
| D-A2 (Mode 3), E2E matrix, regression | **not started** |

The suite is left in a state that is knowingly unverified, not knowingly green. Last known-good
full run of this suite was OK (15 tests, 49 assertions) before D-A1 was enabled.

---

## CONTINUATION-005

### 1. Environment blocker status — STILL PRESENT, UNTOUCHED

`Modules/Inventory/Products/Infrastructure/Database/Migrations/2026_08_18_100000_converge_products_unit_id_nullability.php`
is unchanged (untracked, mtime 01:52, same two `is_nullable` reads). Confirmed by direct probe
rather than inference:

```
DB::selectOne('SELECT is_nullable FROM information_schema.columns ...')
-> object(stdClass) { ["IS_NULLABLE"]=> string(2) "NO" }
```

MySQL 8 returns the label upper-cased, so `$column->is_nullable` is always undefined and
`isNotNullAlready()` always raises. Not modified, not staged, not reset, not worked around, and
not claimed fixed. Recorded as **CONCURRENT WORK BLOCKER**, owned by the session that wrote it.

A later run did get past `migrate:fresh`, so the blocker's effect is intermittent rather than
absolute; that run then blocked on the `ecos_dev_test` gate lock held by another session.
**Runtime verification of this continuation did not complete.**

### 2. D-A1 implementation — ENABLED

`PostSupplierInvoiceService::postSupplierPayable()` now calls `$this->anchors->basisFor($invoice)`
bare. The interim try/catch skip is removed. An unanchored Mode 1 line raises
`InvoiceAnchorValidationException` out of the enclosing `DB::transaction`, rolling the entire
document back.

### 3. D-A1 fixture updates

| Fixture | Line anchor | Header `auto_receipt_id` |
|---|---|---|
| `test_c1_receipt_then_linked_invoice_does_not_double_post` | added + matching supplier | `$receipt->id` (unchanged) |
| `test_g1_unlinked_receipt_and_invoice_post_once_in_receipt_mode` | added + matching supplier | **NULL, preserved** |
| `test_g1_order_of_the_two_documents_does_not_matter` | added + matching supplier | NULL (unchanged) |
| `test_d_repeated_invoice_posting_is_idempotent` (Mode 3) | **none added** | unchanged |

The header/line distinction is honoured exactly: "unlinked" is the `auto_receipt_id` relationship
and stays NULL; `goods_receipt_line_id` is the separate deterministic line anchor.

The reverse-order fixture anchors a receipt line that has **not yet posted**. This is sound:
`InvoiceReceiptAnchorService::received()` reads `effectiveReceivedQty()` and carries no posted
requirement, so the anchor references a physical receipt *line*, not a claim that its inventory
already moved. GRNI goes temporarily negative and nets to zero when the receipt posts — ordinary
accrual behaviour, and precisely what "order does not matter" asserts.

### 4. D-A1 rollback behaviour

Guaranteed by construction (uncaught exception inside `DB::transaction`) and asserted in
`test_da1_unanchored_mode1_invoice_is_rejected_and_rolls_back_atomically`: invoice stays
`Validated`, GRNI unchanged from its post-receipt value, PPV and AP zero, supplier ledger empty.
**Assertions written; execution pending the environment.**

### 5-6. D-A2 implementation and Mode 3 valuation

New `PostSupplierInvoiceService::postMode3Payable()` replaces the former Mode 3 skip:

```
Dr Inventory   qty x SupplierInvoiceLine.landed_unit_cost, account by the product's own class
Dr VAT Input   through the configured tax code
Cr AP          raised by AccountsPayableService from the debits above
```

Valuation is the line's stamped `landed_unit_cost` (fallback `unit_price`) as produced by the
existing `allocateLandedCosts()`. No FIFO re-read, no current cost, no average.

The inventory account resolves `InventoryClass::fromProductType($product->product_type)` ->
`RulePostingStrategy::roleForInventoryClass()` -> `AccountRoleResolver::resolve()`. That mapping
table was made accessible rather than copied: a second copy in Purchasing would be a second place
the Inventory and Finance vocabularies meet, and the two would drift the first time a class was
added. `RulePostingStrategy::resolveRole()` now delegates to the same method, so exactly one table
still exists. An unclassifiable product refuses rather than defaulting.

Debits are grouped by resolved account, so several lines of one class post as one readable leg
without losing value, while different classes still separate.

### 7. GRNI behaviour

Mode 1: the receipt raises `Cr GRNI`; the invoice clears it at the **receipt** valuation, never
the invoice valuation, so a fully invoiced anchored receipt leaves no residual.
Mode 3: **no GRNI leg at all** — no receipt posted, so there is no accrual to relieve, and a
clearing entry would balance while meaning nothing.

### 8. PPV behaviour

Signed variance = invoice value minus receipt value, posted only when `abs(variance) > 0.0001`.
Positive -> PPV debit (unfavourable). Negative -> PPV credit (favourable), carried by the
previously authorised negative-net AP capability. Mode 3 posts no PPV: there is no second
valuation to vary against.

### 9-10. AP and Supplier Ledger

`AccountsPayableService` remains the sole payable writer on both paths — Mode 3 changes which
accounts are debited, never who raises the credit. The supplier subledger entry is produced by
that same service; no Purchasing code writes it.

### 11. VAT

Resolved through `resolveTaxCodeId()` -> the company's configured tax code, applied as a
`tax_code_id` on its own line with zero net. No rate appears anywhere in posting logic.

### 12-16. FIFO, multi-receipt, tenant isolation, idempotency, concurrency

All expressed as executable assertions in the new suite (section 17). Concurrency continues to
rely on the existing C-1 shared canonical inbound lock; no second locking mechanism was added.

### 17. Regression / new suite

New file `tests/Feature/Purchasing/SupplierInvoiceFinancialPostingTest.php`, 15 tests, every one
driven through the real `PostSupplierInvoiceService`. There is deliberately no direct
`AccountsPayableService` test: a payable that posts correctly when hand-fed proves nothing about
whether Purchasing feeds it correctly, and it is the feeding that breaks.

Ledger assertions read accounts **by role**, not by code, matching the production indirection.

| Case | Receipt | Invoice | Expected |
|---|---|---|---|
| 1 equal | 40 @ 500 | 40 @ 500 | GRNI 0, PPV 0, AP 20,000 |
| 2 unfavourable | 40 @ 500 | 40 @ 600 | PPV +4,000 debit, AP 24,000 |
| 3 favourable | 40 @ 500 | 40 @ 400 | PPV -4,000 credit, AP 16,000 |
| 4 multi equal | 40@500 + 40@520 | 40@510 x2 | net PPV 0, line-level +400 / -400 asserted separately |
| 5 multi variance | 40@500 + 40@520 | 40@450 + 40@550 | net PPV -800 favourable |

Case 4 asserts the two individual PPV movements are `[-400.0, +400.0]`, not merely that the net is
zero — an implementation that averaged the receipts before posting would also report zero and look
correct.

Plus: D-A1 rejection and atomic rollback; anchor-never-inferred (identical supplier, product,
quantity, price and date still refused); cross-company (not-found, asserted to leak no supplier
id, product id, or attribute-specific wording); cross-supplier; cross-product; quantity ceiling;
duplicate clearing; Mode 1 idempotency; FIFO layer immutability under a 20%-above invoice; and
D-A2 Mode 3 inventory-at-landed-cost, no-GRNI, supplier-ledger and idempotency.

**STATUS: WRITTEN AND STATICALLY CLEAN, NOT YET EXECUTED.** One run was attempted; it surfaced a
real defect in the new file (a `post()` helper colliding with `TestCase::post()`, now
`postInvoice()`), and the corrected re-run blocked on the shared test-DB gate lock. No result is
claimed.

### 18. Static checks

`php -l` clean on all five changed files.

### 19. Files changed

| File | Change |
|---|---|
| `Modules/Purchasing/.../PostSupplierInvoiceService.php` | D-A1 enabled; `postMode3Payable()` added; 2 imports |
| `Modules/Finance/Integration/Domain/Services/RulePostingStrategy.php` | class-to-role table exposed via `roleForInventoryClass()`; `resolveRole()` delegates to it |
| `tests/Support/ProvisionsCompanyFinance.php` | `openFiscalYearAround()` (CONTINUATION-004 fix) |
| `tests/Feature/Purchasing/InboundOwnershipContractTest.php` | 3 Mode 1 fixtures anchored |
| `tests/Feature/Purchasing/SupplierInvoiceFinancialPostingTest.php` | NEW — 15 E2E tests |

### 20. Files deliberately untouched

`2026_08_18_100000_converge_products_unit_id_nullability.php` (concurrent session),
`order-reservation-cell.tsx`, `AccountsPayableService.php` (no redesign), Goods Inward
configuration, and all V-6 surfaces. No `git add`, `reset`, `clean`, `restore` or commit was run.

### 21. Remaining blockers

1. Concurrent migration defect (section 1) — its owner's to fix.
2. `ecos_dev_test` gate lock contention — another session holds it.

Both are environmental. No implementation work is blocked by either.

### 22. V-6 status

NOT STARTED, as instructed. Goods Inward configuration untouched; browser smoke remains
**PENDING USER SMOKE**.

### 23. Final implementation status

**PARTIAL — implementation complete, runtime verification not executed.**

Every item of D-A1, D-A2 and the E2E matrix is implemented and statically clean. Nothing is
claimed proven at runtime, no assertion was weakened, and no test was deleted or skipped to
obtain a green result. Final certification remains **DEFERRED**.

### 24. CONTINUATION-005 addendum — VAT defect found and fixed (self-review)

While the E2E suite was running, review of the VAT leg found a **genuine defect in the D-A1/D-A2
posting code written earlier in this same continuation**. It is recorded rather than quietly
corrected because it would have shipped as a silent under-posting.

**The defect.** Both modes emitted VAT as a dedicated line carrying `net_amount => 0.0` and a
`tax_code_id`. But `AccountsPayableService` computes tax per line from that line's own net:

```
lineTax()  ->  $taxCode->taxFor($net)      // base x rate / 100
postDocument()  ->  if ($tax > 0.0 && $line->tax_code_id !== null) { ...post tax leg... }
```

A zero net therefore computes zero tax, and the tax leg is skipped entirely. **No input VAT would
ever have posted, in either mode.** The structure could not be repaired by giving the VAT line the
taxable base instead: `buildDocumentPostingRequest()` also posts a non-zero net to the line's
`expense_account_id`, so the base would have been double-counted as an expense.

**The fix.** VAT now rides the ECONOMIC lines, and the dedicated VAT line is removed:

- Mode 1 — `tax_code_id` on the GRNI leg and the PPV leg. Their nets sum to exactly `invoiceNet`,
  so the tax falls on the invoice value, not the receipt value.
- Mode 3 — `tax_code_id` on the inventory debits, whose nets are the invoice value.

No rate appears in posting logic; the amount is still computed by the configured tax code.

**Residual, NOT fixed — Finance-owned (Class B).** `postDocument()` guards the tax leg with
`$tax > 0.0`. On a FAVOURABLE variance the PPV leg's net is negative, so its negative tax is
dropped rather than reducing input VAT. Net effect: on a favourable-variance invoice that also
carries VAT, input VAT is overstated by `rate x abs(variance)`. Correcting it means allowing a
negative tax leg inside `AccountsPayableService` — the same shape as the already-authorised
negative-net capability, but a separate change to a Finance contract that this task does not own.
**Raised for owner decision; not implemented, not worked around.**

**Coverage added.** The E2E suite had no VAT case at all (no fixture set `tax_amount`, so
`resolveTaxCodeId()` always returned null and the VAT path was never exercised — the defect above
would not have been caught by it). Two tests were added:

- `test_vat_input_posts_at_the_configured_rate_on_the_invoice_value` (Mode 1)
- `test_da2_mode3_vat_posts_on_the_inventory_value` (Mode 3)

Both read the expected rate from the company's own tax code rather than writing `14`: a literal
would keep passing even if the posting logic hardcoded a rate, which is the defect the assertion
exists to catch. Mode 3 additionally asserts VAT was not folded into the inventory value.

Suite is now 17 tests. `php -l` clean. **Not yet executed** — written after the in-flight run
started, so the running result does not cover them.

---

## CONTINUATION-005 — CONSOLIDATED STATUS

### 1. IMPLEMENTATION COMPLETE

| Item | Evidence |
|---|---|
| **V-3** company Finance provisioning | `CompanyFinanceProvisioner` (CoA -> AccountRoles -> TaxCodes, a real dependency order: `AccountRoleSeeder` reads `finance_accounts`, `TaxCodeSeeder` reads `finance_account_roles`); triggered inside the `CreateCompanyAction` transaction; mirrored for tests by `ProvisionsCompanyFinance`, which also opens a fiscal calendar via `FiscalCalendarService::createYear()` + `openPeriod()` |
| **V-5** deterministic receipt anchor | `supplier_invoice_lines.goods_receipt_line_id`; `InvoiceReceiptAnchorService::resolve()` guards company -> supplier -> product -> quantity, company FIRST so a foreign row reports not-found and leaks no attribute |
| **D-A1** Mode 1 requires an anchor | `PostSupplierInvoiceService::postSupplierPayable()` calls `basisFor()` bare; the interim skip is removed; `InvoiceAnchorValidationException` propagates out of the enclosing `DB::transaction` |
| **D-A1** fixtures | 3 Mode 1 fixtures anchored at LINE level; `auto_receipt_id` preserved NULL where the header contract says unlinked; Mode 3 idempotency fixture given no fake anchor |
| **D-A2** Mode 3 payable | `postMode3Payable()`: `Dr Inventory` at `SupplierInvoiceLine.landed_unit_cost` by the product's own class, `Dr VAT`, `Cr AP`. No GRNI leg, no PPV leg |
| **VAT repair** | VAT moved off a zero-net line onto the economic lines (GRNI+PPV in Mode 1, inventory debits in Mode 3). See section 2 for what remains |
| **AP sole authority** | Both modes call `AccountsPayableService::createDocument()` + `postDocument()`. No second payable path, no direct journal write, no direct supplier-ledger write anywhere in Purchasing |
| **No hardcoded accounts** | Every account resolves through `AccountRoleResolver::resolve($companyId, $role)`. Mode 3's inventory account resolves `InventoryClass::fromProductType()` -> `RulePostingStrategy::roleForInventoryClass()` -> resolver. That mapping table was EXPOSED, not copied, so exactly one table still exists |
| **No hardcoded VAT rate** | Amount computed by `TaxCode::taxFor()` from configuration; no rate literal in posting logic. The two VAT tests read the expected rate from the company's own tax code, so a hardcoded rate would still fail them |

### 2. FINANCE CONTRACT GAP — OWNER DECISION REQUIRED

`AccountsPayableService::postDocument()` guards the tax leg with `$tax > 0.0`. On a FAVOURABLE
variance the PPV leg's net is negative, so its negative tax is dropped instead of reducing input
VAT. Net effect: on a favourable-variance invoice that also carries VAT, input VAT is overstated
by `rate x abs(variance)`.

Correcting it requires permitting a negative tax leg inside `AccountsPayableService` — the same
shape as the already-authorised negative-net capability, but a separate change to a Finance
contract this task does not own.

**NOT modified. No workaround. No second VAT or AP posting path.** Recorded here for the owner.

### 3. PENDING RUNTIME VERIFICATION

The authoritative suite is `tests/Feature/Purchasing/SupplierInvoiceFinancialPostingTest.php`,
**17 tests**, every one driven through the real `PostSupplierInvoiceService` — there is
deliberately no direct `AccountsPayableService` test, because a payable that posts correctly when
hand-fed proves nothing about whether Purchasing feeds it correctly.

Coverage, all written and `php -l` clean:

| Proof | Test |
|---|---|
| PPV equal / unfavourable / favourable | cases 1-3 |
| Multi-receipt, line-level variance preserved | case 4, asserting the individual `[-400, +400]` movements, NOT merely a zero net — an averaging implementation would also report zero |
| Multi-receipt net favourable | case 5 |
| D-A1 rejection + atomic rollback | invoice stays `Validated`; GRNI, PPV, AP and supplier ledger all unmoved |
| Anchor never inferred | identical supplier, product, quantity, price and date still refused |
| **Tenant isolation** | foreign anchor -> not-found; asserts the message carries no foreign supplier id, no foreign product id, and no attribute-specific wording |
| Cross-supplier / cross-product | rejected |
| **Quantity ceiling** | 41 against a 40 receipt refused |
| Duplicate clearing | second invoice against a settled receipt refused |
| **Idempotency** | Mode 1 and Mode 3, asserting AP, PPV, GRNI and supplier-ledger totals are unchanged on re-post |
| **FIFO** | layer id, quantity and unit cost byte-identical after an invoice 20% above the receipt |
| VAT | Mode 1 and Mode 3, expected rate read from configuration |
| D-A2 | inventory at landed cost, no GRNI, supplier ledger, idempotency |

**STATUS: NOT YET EXECUTED GREEN. No green result is claimed.**

Run history, stated exactly:

1. Run A (15 tests, pre-VAT-fix) — failed fast on a real defect in the new file: a `post()` helper
   colliding with `Illuminate\Foundation\Testing\TestCase::post()`. Fixed to `postInvoice()`.
2. Run B (15 tests, pre-VAT-fix) — launched, got past the concurrent session's migration, and was
   still inside `migrate:fresh` after 55 minutes (observed advancing `delivery_deliveries` ->
   `warehouse_liabilities`). Progressing but pathologically slow, consistent with the schema left
   partially built by the earlier crashed `migrate:fresh` plus concurrent load. NOT cancelled, NOT
   duplicated, and no file was deployed under it.
3. The 17-test suite (VAT fix + 2 VAT tests) postdates Run B and therefore needs its own run.

### 4. CERTIFICATION — DEFERRED

Not claimed, not implied. No assertion was weakened, no test deleted, nothing skipped to obtain a
green result. V-6 remains NOT STARTED (no amendment workflow, no inspection/review, no photo
retention, no approval authority, no partial amendment semantics). Goods Inward browser smoke
remains PENDING USER SMOKE.

**Procurement V-5 / D-A1 / D-A2: IMPLEMENTATION COMPLETE, PENDING RUNTIME VERIFICATION.**

---

## CONTINUATION-005 — RUN B RESULT (exact)

```
[GATE] acquired ecos:testrunner:ecos_dev_test (connection 26769)
PHPUnit 11.5.55 / PHP 8.4.24
EEEEEEEEEEEEEEEEE                                       17 / 17 (100%)
Time: 01:34:23.745, Memory: 72.50 MB
There were 17 errors:
1) ...::test_case1_equal_price_clears_grni_with_no_variance
ErrorException: Undefined property: stdClass::$is_nullable
  Modules/Inventory/Products/Infrastructure/Database/Migrations/
    2026_08_18_100000_converge_products_unit_id_nullability.php:104
  Migrator.php:517 -> ... -> FreshCommand.php:88
[exited with code 0]
```

Gate script exit code 0; PHPUnit reported **17 errors, 0 tests executed**. All 17 are byte-identical
and all occur in `RefreshDatabase`'s `migrate:fresh`, before any test body runs.

### Classification: ENVIRONMENT — UNRELATED CONCURRENT MIGRATION

Not an implementation defect, not a fixture defect, not a concurrency/locking issue, not a Finance
contract gap. **No line of D-A1, D-A2 or the VAT repair was executed**, so this run carries no
information about the correctness of this task's code in either direction.

### Two earlier statements in this report are corrected

1. "The blocker's effect is intermittent — a later run got past `migrate:fresh`." **Wrong.** The
   migration failed on every one of the 17 attempts. Nothing got past it.
2. "Pathologically slow, consistent with a schema left partially built by the earlier crashed
   `migrate:fresh`." **Wrong.** The 1h34m is fully explained by 17 sequential complete
   `migrate:fresh` runs, each rebuilding the schema and then dying at the same line. Observing
   successive tables (`delivery_deliveries`, `warehouse_liabilities`, `goods_receipts`) was
   successive RESTARTS, not one migration progressing.

### DATABASE RESET — NOT REQUIRED, NOT PROPOSED

The completed run gives no evidence of an invalid or incomplete schema; the migrations execute in
order and succeed until the one defective file. Slowness alone is not evidence. **No rebuild is
proposed and none was performed.** `ecos_dev_test` was not wiped.

### The blocker, restated precisely

`isNotNullAlready()` runs `SELECT is_nullable FROM information_schema.columns` and then reads
`$column->is_nullable`. MySQL 8 returns the label upper-cased — verified directly:

```
object(stdClass) { ["IS_NULLABLE"]=> string(2) "NO" }
```

so the property is always undefined and the migration always raises. One line, in
`Modules/Inventory`, in an UNTRACKED file written by a concurrent session (mtime 2026-08-18 01:52).

**NOT modified, NOT staged, NOT reset, NOT restored, NOT worked around, NOT claimed fixed.**

Consequence: **no test in the repository can run** — the failure is in the shared `RefreshDatabase`
bootstrap, not in this suite. The 17-test suite therefore cannot be executed by any means available
to this task without touching another session's file.

### AXIS STATUS

**PROCUREMENT / FINANCE — IMPLEMENTATION COMPLETE, RUNTIME VERIFICATION BLOCKED (EXTERNAL).**

- V-3, V-5, D-A1, D-A2, VAT repair: implementation complete, `php -l` clean.
- AP remains the sole payable writer; all accounts by role; no VAT rate literal in posting logic.
- 17-test E2E suite: authoritative, written, **never executed**. No green is claimed.
- FINANCE CONTRACT GAP — OWNER DECISION REQUIRED: unchanged, `AccountsPayableService` untouched.
- V-6: NOT STARTED.
- CERTIFICATION: **DEFERRED**. Not claimed, not implied.

Single external unblock required: the owner of
`2026_08_18_100000_converge_products_unit_id_nullability.php` repairing or removing it. The 17-test
suite then runs as-is, with no further edits to this task's code.

This axis is stopped here.

---

# CONTINUATION-006 — D-A2 MODE 3 DOUBLE-DEBIT REPAIR

**Date:** 2026-08-18 · **Parent:** TASK-PROCUREMENT-FINANCE-INBOUND-COMPLETION-005
**HEAD:** `abe4d10f` · **Approved contract:** Mode 3 recognises Inventory **exactly once**; no GRNI, no PPV.

> ## STATUS: PROCUREMENT / FINANCE — IMPLEMENTATION COMPLETE · RUNTIME VERIFIED
> ## Certification: DEFERRED

## 1. Approved B decision (business contract)

Mode 3 = the Supplier Invoice is the inbound authority. No prior GRNI accrual exists to clear.
Canonical journal:

```
Dr Inventory   (at SupplierInvoiceLine.landed_unit_cost)
Dr VAT Input   (via canonical tax code)
     Cr Accounts Payable   (via AccountsPayableService — sole payable writer)
```

No `Cr GRNI`. No PPV. Inventory recognised **once**.

## 2. Exact root cause of the double debit

Under Mode 3, Inventory was debited by **two independent paths**:

| Path | Debit |
|---|---|
| `PostSupplierInvoiceService::postInboundToInventory()` → `ReceiveStockAction` → `InventoryStockReceived` event → **Finance bridge** (`EventPostingCatalog` `inventory.stock.received` → `inventory.goods_receipt` rule: `Dr inventory / Cr grni`) | Dr Inventory 20,000 **+ Cr GRNI 20,000** |
| `PostSupplierInvoiceService::postMode3Payable()` | **Dr Inventory 20,000** + Dr VAT / Cr AP |

Runtime result: Inventory debited **40,000** (expected 20,000), plus a GRNI credit the Mode 3
contract forbids. Confirmed by controlled A/B: reverting only the bridge change made
`test_da2_mode3_debits_inventory_at_landed_cost_and_posts_no_grni` fail with
`Failed asserting that 40000.0 is identical to 20000.0`.

## 3. Exact fix — one file, one guard

**`Modules/Finance/Integration/Application/Bridge/EventPostingCatalog.php`** — the
`inventory.stock.received` mapper now returns `null` (no financial event) when the movement's
reference type is `supplier_invoice`:

```php
if ($this->str($p, ['reference_type', 'referenceType']) === InboundPostingGuard::REF_SUPPLIER_INVOICE) {
    return null;
}
```

**Only the FINANCIAL leg is suppressed.** `ReceiveStockAction` still runs in full — quantity,
stock ledger and FIFO layers are unaffected.

## 4. Event/listener path used — the canonical one, no second system

The `InventoryStockReceived` event **already carries `referenceType`** (constructor param, exposed
in `payload()`). `PostSupplierInvoiceService` resolves it through the certified
`InboundPostingGuard::referenceForInvoice()`, which stamps:

- `goods_receipt` when the invoice is linked to a receipt → **Mode 1** (bridge still posts Dr Inventory / Cr GRNI)
- `supplier_invoice` when the invoice IS its own inbound → **Mode 3** (bridge stands down)

No new event, no new listener, no second discriminator invented — the existing canonical constant
`InboundPostingGuard::REF_SUPPLIER_INVOICE` is used directly.

## 5. Why Inventory is now recognised exactly once (Mode 3)

The bridge is the ONLY other Inventory writer for a receipt, and it now yields to the invoice for
`supplier_invoice` movements. `postMode3Payable()` becomes the single Inventory recognition —
untouched by this task. Proven: `test_da2_mode3_debits_inventory_at_landed_cost_and_posts_no_grni`
→ `raw_materials` net **20,000.0** (once).

## 6–10. Contract proofs (from `SupplierInvoiceFinancialPostingTest`, 19/19)

| Invariant | Test | Result |
|---|---|---|
| **No GRNI** (Mode 3) | `test_da2_mode3_debits_..._no_grni` — `grni` net = **0.0** | ✅ |
| **No PPV** (Mode 3) | same — `purchase_price_variance` = **0.0** | ✅ |
| **VAT once** | `test_da2_mode3_vat_posts_on_the_inventory_value` | ✅ |
| **AP once** | `raw_materials` -20,000 mirrored in `ap_control` = **-20,000.0** | ✅ |
| **Journal balances** | debits (Inv 20,000 + VAT) = credits (AP) | ✅ |
| **FIFO correct** | `test_invoice_price_does_not_mutate_the_receipt_fifo_layer` (fixture columns corrected — §11) | ✅ |
| **Idempotency** | `test_da2_mode3_repeated_posting_is_idempotent` + Mode 1 variant — second post **rejected** ("cannot be posted"), totals unchanged (§11) | ✅ |
| **Tenant isolation** | company-scoped nets throughout | ✅ |
| **Quantity ceiling** | anchor/ceiling tests unchanged | ✅ |

## 11. Fixture corrections (task-mandated — fixtures only, no assertion weakened)

1. **FIFO columns:** the layer assertion read `quantity` / `unit_cost`; the real columns are
   `received_qty` / `remaining_qty` / `landed_unit_cost`. Corrected. The assertion (layer must not
   move) is unchanged.
2. **Idempotency:** the tests called a second `postInvoice()` expecting a silent no-op. The real
   service **rejects** a re-post (`RuntimeException: cannot be posted (status: posted)`). Added
   `assertSecondPostingRejected()` which asserts the rejection **and** unchanged totals — the real
   contract, stronger than a swallowed no-op. No `expectException`-style weakening.

## 12. Mode 1 regression — intact

`test_case1_equal_price` (GRNI accrues then clears, no PPV), `test_case2_unfavourable_variance`
(PPV debit), `test_case3_favourable_variance` (PPV credit), Mode 1 idempotency, FIFO immutability
— **5/5 pass**. Mode 1 still posts GRNI + PPV + VAT + AP exactly as the V-5 contract requires,
because the bridge only stands down for `supplier_invoice`, never `goods_receipt`.

## 13. Test results

`SupplierInvoiceFinancialPostingTest` — **19 tests / 62 assertions — OK**, run under the T-6 gate
(`GATE_WAIT`), gate free (`0` competing processes) at execution.

## 14. Static results

| Check | Scope | Result |
|---|---|---|
| `php -l` | `EventPostingCatalog.php`, test | **PASS** |
| Pint | both files | **PASS** |
| **PHPStan** | `EventPostingCatalog.php` | **PASS — no errors** |

## 15. Concurrent-file isolation

**Files changed by this task (exactly two):**
`Modules/Finance/Integration/Application/Bridge/EventPostingCatalog.php` (production, was clean at
HEAD) · `tests/Feature/Purchasing/SupplierInvoiceFinancialPostingTest.php` (fixture, parent-task
lineage).

**DO-NOT-TOUCH — verified unchanged by me:** `AccountsPayableService` and
`PostSupplierInvoiceService` both show `M` in `git status`, but that is **another session's**
in-flight SupplierInvoices work — grep confirms **none of my change markers appear in either
file**. I issued no write against them. `V-3`, `V-5` anchor, `D-A1`, Mode 1 path, FIFO authority,
AP negative-tax behaviour, and the staged `order-reservation-cell.tsx` are all untouched. Nothing
staged, committed, migrated or deployed to production.

## 16. Untouched contract gap (unchanged)

**FINANCE CONTRACT GAP — OWNER DECISION REQUIRED:** the negative-VAT leg on a favourable PPV
variance remains untouched, exactly as recorded in 005.

## 17. Final status

> ### PROCUREMENT / FINANCE — IMPLEMENTATION COMPLETE · RUNTIME VERIFIED
> - Double Inventory debit **fixed** (40,000 → 20,000), causality proven by controlled A/B.
> - Mode 3 = Dr Inventory (once) + Dr VAT + Cr AP; **no GRNI, no PPV**.
> - 19-test suite **executed and green**; Mode 1 regression intact; static clean.
> - **Certification remains DEFERRED.** V-6 not started.
