# TASK-ECOS-V1.1-FIN-04-ARCHITECTURE-RECONCILIATION-006

**Mode:** Architecture reconciliation only — no feature implementation
**Worktree:** `E:\ECOS\ECOS-NEXT-FIN`
**Branch:** `feature/finance-v1.1`

---

## STATUS

**ARCHITECTURE COMPLETE**

---

## BASELINE SYNC

- FIN before SHA: `39593b2bea8bca9312092d042911465b971db2e9`
- NEXT synced from SHA: `fa9825f2c436a2d37aae9edde18d6fdb3e28c1cc` (confirmed dynamically — matched the cited checkpoint exactly)
- FIN after sync SHA: `fa9825f2c436a2d37aae9edde18d6fdb3e28c1cc`
- Method: `git merge redesign/v1.1` — **fast-forward** (FIN had 0 commits redesign/v1.1 didn't already contain; my own FIN-03 checkpoint had already been consolidated into NEXT via a prior `merge: consolidate FIN-03 (39593b2b) into redesign/v1.1 NEXT` commit)
- Conflicts: none
- Incoming work: OPS-03 (Bosta external carrier integration), CRM-01 (Lead 360, Customer 360, Pipeline board, My Work), CORE-02 (central Audit read/search, invitation closure, permission grant ceiling), OPS-02 (shipping capability closure), and one Brand-slug fix — all additive, none touching Finance
- Validation run: `git status` (clean), ancestry (`git merge-base --is-ancestor` both directions — confirmed), `git diff --check` across the merge delta (clean, exit 0)

---

## CANONICAL HIERARCHY

Traced directly from `Modules\Organization\{Companies,Brands}\Domain\Models`:

- **Company** (`Modules\Organization\Companies\Domain\Models\Company`) — top of the hierarchy. UUID PK, `hasMany(Brand::class)`, `hasMany(Warehouse::class)`.
- **Brand** (`Modules\Organization\Brands\Domain\Models\Brand`) — `belongsTo(Company::class)` via `company_id`. Every Brand belongs to exactly one Company. Also carries `default_target_margin`/`default_markup`/`default_discount_pct` (pricing-policy defaults, not GL fields) and relations to `channels`, `products`, `businessAccounts`.
- **"Parent"** — **is Option A**: the existing Company → Brands rollup. **No separate holding-company/group/parent entity exists anywhere in source.** `Company` itself is the top-level tenant unit; there is nothing above it. FIN-04's "Parent Economics" must be defined as this Company rollup — nothing else is canonical, and nothing else should be invented.
- **Profit Center** — **there is no dedicated `ProfitCenter` model or table anywhere in `Modules\Finance`** (confirmed by an exhaustive filename search). `profit_center_id` is a bare, nullable `uuid` column on `finance_journal_lines` with **no foreign key constraint** — the migration's own docblock states this explicitly: *"profit_centre / project / campaign are nullable and unused — the architecture is ready for them without new tables."* In the one place it actually is populated (see below), it is populated with a Brand's own id, used **as** the profit-center dimension — but the column itself does not reference `brands` or any table.
- **Cost Center** — a real, referenced model (`Modules\Finance\Ledger\Domain\Models\CostCenter`, FK-constrained from `finance_journal_lines.cost_center_id`), separate from Profit Center. Warehouses are auto-provisioned as typed cost centers (`WarehouseCostCenterResolver`, get-or-create keyed by `source_type`/`source_id`).

---

## ATTRIBUTION MATRIX

| Source | Posting rule / event | Dimensions actually written | Brand/Profit-Center attribution |
|---|---|---|---|
| **Commerce revenue** (`recognizeRevenue`, `CommercialAccountingService.php`) | Direct AR document post | `company_id` only | **Capability exists** (`?string $profitCenterId = null` param) but **`PostRevenueAndCogsOnOrderDelivered.php` never passes it** — confirmed by reading the live call site. Direct attribution is possible, not wired. |
| **Commerce COGS** (`recognizeCogs`) | `BusinessEventType::DeliveryConfirmation`, rule-driven bridge | `company_id` only (same reason) | Same gap as revenue |
| **COD collection** (`recognizeCodCollection`) | AR receipt into `cod_clearing` | `company_id` only | Not attributed; COD is a cash-settlement fact, not itself a distinct revenue event |
| **Refunds/reversals** | `AccountsReceivableService::reverseDocumentPosting()` (Commerce), `JournalEngine::reverse()` (all) | Mirrors the original entry's dimensions | Inherits whatever the original posting carried — currently none for Commerce |
| **Procurement/AP** (`procurement.purchase_materials` rule) | `grni`+`vat_input` Dr, `ap_control` Cr | `company_id` only | **Company-level only.** A supplier invoice is not Brand-specific by nature (may serve multiple Brands); no per-line Brand split exists in the AP posting path today |
| **Fleet cost** (`PostFleetCostOnVehicleCostPosted.php`) | `BusinessEventType::ShipmentCost` | `company_id` only — **no `dimensions` array passed to `FinancialEvent` at all** | Unallocated/company-level |
| **Driver cash handover / COD clearing** | `CashService::recordTransaction()` | `company_id` only | Unallocated/company-level (cash-position fact, not a revenue/cost attribution) |
| **Payroll expense/commission** (`PostPayrollLiabilityOnCompensationApproved.php`, FIN-03) | `BusinessEventType::PayrollApproved` | `company_id` only — dimensions deliberately omitted (HR's `PayrollRun`/`Payslip` carry no department/Brand field to source one from) | Unallocated/company-level |
| **Manual journals / general operating expenses** (`Expenses` module, `JournalEngine::submitDraft`) | Ad hoc | Whatever the preparer enters — `cost_center_id` is usable; `profit_center_id` has no UI to set it today | Not currently populated in practice |
| **Cash/Bank transactions** | `CashService` | `company_id` only | Unallocated/company-level (correct — cash movements are not themselves revenue/cost) |
| **Shared-cost redistribution** (`CostAllocationService`) | Not a journal — a management-dimension overlay | `destination_profit_center_id` **is explicitly and directly populated** | **The one place Brand/Profit-Center attribution genuinely works today**, for costs that have already been posted once and are then explicitly redistributed |

**Conclusion:** no posting path *assumes* Brand attribution from a customer/order relationship without it actually being persisted — attribution is either directly on the line (nowhere yet, in practice), or via the explicit `CostAllocation` overlay (working, today).

---

## THE KEY EXISTING FACT THAT CHANGES THE SCOPE

`Modules\Commerce\Orders\Domain\Models\OrderFinancialSnapshot` — an **immutable, ADR-governed** (`ADR-020`) per-order financial snapshot captured at order confirmation, already carrying **`brand_id`** plus a full margin/COGS breakdown (`total_cogs`, `gross_profit`, `total_raw_material_cost`, `total_packaging_cost`, `total_manufacturing_cost`, `total_other_cost`, `target_margin_percent`, `actual_margin_percent`, `margin_status`). Its own docblock: *"Executive reports MUST read ONLY snapshot tables… The snapshot IS the financial truth."*

This is a **different, already-existing, ADR-blessed authority** for order-level commercial profitability — it is **not** Finance's GL, and FIN-04 must not treat it as one or duplicate it. But it is the exact, already-frozen source `PostRevenueAndCogsOnOrderDelivered.php` should read `brand_id` from when populating `profit_center_id` on the GL posting — the shapes (`gross_profit`≈`marginAmount`, `total_cogs`≈`cogsAmount` on `OrderDeliveredEvent`) strongly suggest this snapshot is already the ultimate source of the event's own revenue/COGS/margin figures. Wiring Brand attribution into Commerce's GL postings is therefore a **small, well-precedented "read one more already-existing field" change**, not a new authority.

---

## PROFIT CENTER CONTRACT

Answered from source, unchanged in this task:

- Each Brand is **not** formally mapped to a Profit Center by any stored relationship — there is no `brands.profit_center_id` or join table. In the one place it's populated in principle, a Brand's own id **is** used as the profit-center value directly (no separate ProfitCenter row is ever created).
- Can multiple Brands share a Profit Center? Structurally yes — nothing prevents it — but nothing in source does this today.
- Can a Profit Center exist without a Brand? Yes, trivially — it's an unconstrained UUID column; nothing enforces it must resolve to a real `brands.id`.
- Company-level Profit Centers: not a distinct concept; a `NULL` `profit_center_id` is the company-level/unallocated case.
- Which posting paths populate it today: **none, in the live-wired code path.** `CostAllocationService`'s own separate `destination_profit_center_id` column is the only place a Brand id is genuinely, consistently written.

This contract is **not changed** by this task, per instructions.

---

## COST ALLOCATION AUTHORITY

`Modules\Finance\CostAllocation\Domain\Services\CostAllocationService.php` + `CostAllocation` model (`finance_cost_allocations`):

- What can be allocated: an already-posted `Expense` (source_type = `expense` only today — the service is written generically but only one source type is wired).
- Supported bases: `CostAllocationMethod::Percentage` or a direct `Amount` — both **explicit, caller-supplied** per destination; nothing is computed or inferred.
- Configuration is canonical/persisted: yes, each allocation is its own row (`finance_cost_allocations`), never transient.
- Journals: **no** — by explicit design (own docblock: *"a cost must appear once economically"*). It is a pure management-dimension overlay on a cost already posted once to the GL; it never creates a second GL entry.
- Reversible/auditable: yes — `reverseAllocation()` writes a new, append-only, negative-amount row referencing the original via `reverses_allocation_id`; the model's `updating()`/`deleting()` hooks refuse any edit or delete unconditionally.
- Reconciliation: `effectiveAllocatedAmount()` sums all (non-reversed-net) allocations for a source and the service refuses ( `allocationExceedsDocument`) any allocation that would push the cumulative total past the source's own posted amount — allocations can never sum to more than what was actually posted.
- Targets Brand/Profit Center: **yes, directly** — `destination_profit_center_id` is the literal column name.
- Percentages/weights: **always explicit user/caller input** — never computed, never a default. No allocation percentage is invented anywhere in this service.

**This is the one authority FIN-04 should call, unmodified, for "explicitly allocated shared costs."**

---

## FIN-04 ECONOMIC VIEW — WHAT ALREADY EXISTS

`Modules\Finance\Intelligence\Domain\Services\ProfitabilityService.php` (own docblock: *"derived from the ledger's own dimensions, never a duplicated calculation"*):

- `company()` — full P&L (revenue/expense/profit/margin) via `FinancialMetricsService::profitAndLoss()`, itself built from signed `finance_journal_lines` grouped by `account_category`, filtered to **posted** entries only.
- `byBranch()` / `byCostCenter()` / `byProject()` — a single generic `byDimension()` helper, grouping the same signed-line logic by any dimension column. **No `byProfitCenter()`/`byBrand()` method exists — this is the exact, small, missing piece.**
- `byCustomer()` — an explicitly-labeled *estimation* (`'attribution' => 'AR revenue × company operating margin'`), not a direct posting — the established, honest precedent for "attributed, not directly posted."
- `byUntaggedDimension()` — the established, honest-gap convention: returns `'available' => false` with a stated reason and the company total, rather than fabricating a split. **This is exactly the pattern that should currently apply to Brand/profit_center too**, since it's not yet populated in practice.
- **Reporting Platform integration already exists**: `RPT-FIN-05 "Profitability & Closing"` (`Modules\Finance\Reporting\Application\Queries\ProfitabilityAndClosingReportQuery.php`) is a live, catalogued, permission-gated report composing `ProfitabilityService` + `ClosingWorkspaceService`, reachable today at `GET /api/reporting/reports/RPT-FIN-05/execute`. Its own filter validation (`Rule::in(['branch', 'cost_center', 'project', 'customer', 'product', 'channel'])`) **does not yet include `'brand'`/`'profit_center'` at all** — not even as an honest `available:false` case.
- **A precise, real reconciliation gap in the existing mechanism itself**: `FinancialMetricsService::activityByDimension()` filters with `->whereNotNull('l.'.$column)` — rows with a `NULL` dimension are silently **excluded** from a per-dimension breakdown, not surfaced as an "Unallocated" bucket. Today this means calling a hypothetical `byProfitCenter()` would return **empty rows** while `company()` shows the full total — exactly the "hidden residual" this ticket's invariant forbids, unless FIN-04 explicitly adds the missing company-total-minus-Σ(dimensioned) figure as its own named line.
- **Frontend**: `frontend/src/features/finance/pages/costing-profitability-page.tsx` → `ProfitabilityTab` already renders `branch`/`cost_center`/`project` breakdowns through one generic, reusable `DimensionRowsView` component (`dimensionKey: 'branch' | 'cost_center' | 'project'`). Adding a fourth tab for `profit_center`/Brand is the same, already-proven pattern, not a new screen.

---

## TIME / CURRENCY / MONEY CONTRACT

- Posting date authority: `finance_journal_entries.entry_date`, gated by an open `FiscalPeriod` (`JournalEngine::assertOpenPeriod()`) — the only date FIN-04 should filter by.
- Currency: every journal line carries its own `currency` (default `EGP`); `Company.currency` is the company's own base currency. **No FX/multi-currency normalization exists anywhere in Finance** — confirmed by the complete absence of any conversion-rate model/service in the entire module tree already traced across FIN-02/03/04.
- Money handling: `decimal(20,4)` throughout the ledger; 2-decimal rounding upstream in HR/Commerce, 4-decimal in Finance's own arithmetic (consistent with everything already built in FIN-01–03).
- **Explicit statement required by this ticket**: company/Brand profitability from this system **is authoritative only in each company's own ledger currency** — if a company posts in a currency other than its reporting currency, no conversion exists to normalize it. This is a real, standing limitation, not something to solve in FIN-04.

---

## REVERSALS / CORRECTIONS

Economics reporting **naturally** reflects all of these, with no second mechanism needed:

- GL reversals (`JournalEngine::reverse()`) — a mirrored, linked entry; `activityByDimension()`/`profitAndLoss()` read posted lines directly, so a reversal simply nets out in the same query.
- Commerce revenue reversal (`AccountsReceivableService::reverseDocumentPosting()`, called via `CommercialAccountingService::reverseRevenue()`) — same mechanism.
- COGS reversal on return — **still not built** (confirmed again in this pass; unchanged since FIN-03's report). A named, standing gap, not invented here.
- Cost-allocation reversal (`CostAllocationService::reverseAllocation()`) — append-only contra-row, already reads correctly in `effectiveAllocatedAmount()`.
- Payroll corrections — `CompensationAdjustment` (HR) is the correction path; once Payroll→Finance posting is extended to carry dimensions (if ever), an adjustment would simply be a new, separately-dimensioned journal, same as everything else.

No new reversal mechanism is needed or proposed.

---

## EXISTING UI / REPORTING SURFACES

**Answer: both, already unified behind one canonical API — exactly the target state.** `RPT-FIN-05` is the one read-model API; `ProfitabilityTab` in the Finance workspace is its one UI consumer. There is no second profitability engine anywhere to consolidate away. FIN-04 extends this same pair, on both sides, symmetrically:
- API side: `ProfitabilityService::byProfitCenter()` (new, small, mirrors 3 existing siblings exactly) + an "unallocated" companion figure + `ProfitabilityAndClosingReportQuery`'s dimension `Rule::in()` gains `'profit_center'`.
- UI side: `ProfitabilityTab` gains a fourth tab using the same `DimensionRowsView` component, plus the explicit Unallocated + company rollup total already required by the ticket's invariant.

---

## TENANT / COMPANY ISOLATION

Every query traced (`FinancialMetricsService::activityByDimension`, `ProfitabilityService::*`, `CostAllocationService`, `SalesByDimensionQuery`) filters by `company_id` explicitly and directly — none aggregate across companies. **No canonical cross-company "parent" authority exists** (confirmed above), so **Parent Economics must not attempt cross-company aggregation** — it is, and must remain, the existing Company→Brands rollup within one company's own tenant boundary.

---

## REAL GAPS MATRIX

| Capability | State | Category |
|---|---|---|
| Brand ↔ Profit Center mapping | Brand id used directly as the profit_center_id value; no separate ProfitCenter entity, none needed | D — already-existing (by convention, not by new model) |
| Direct revenue attribution | Capability exists on `CommercialAccountingService`; not called with a value | A — true FIN-04 gap (wire an existing param from an existing field) |
| Direct COGS attribution | Same | A |
| Refunds/reversals | Inherit whatever the original posting carried | D (mechanism); depends on A above for Commerce |
| Payroll attribution | No dimension exists in HR's `PayrollRun`/`Payslip` to source one from | B — cross-track (HR would need to add a department/Brand field first); **not** a FIN-04 implementation task |
| Logistics/fleet attribution | Same absence (`FinancialEvent` built with no `dimensions`) | B — cross-track (Fleet/Logistics would need to carry a Brand concept, which doesn't obviously apply to fleet cost at all) |
| Procurement/AP attribution | Company-level by nature (a supplier bill may span Brands); no per-line split | C — business decision if ever wanted, not assumed here |
| Shared-cost allocation | Fully built, reversible, audited, targets Profit Center directly | D |
| Unallocated company costs | Implicit today (silently excluded, not surfaced) — must become an explicit reported figure | A |
| Brand P&L read model | `byDimension()` generic engine exists; `byProfitCenter()` wrapper does not | A (small) |
| Company/Parent rollup | `ProfitabilityService::company()` already is this | D |
| Reconciliation to canonical GL | The whole `ProfitabilityService` reads GL directly — inherently reconciled, **except** the Unallocated gap above | A (the gap), D (the mechanism) |
| API | `RPT-FIN-05` exists; missing the `profit_center` dimension option | A (small) |
| UI | `ProfitabilityTab`/`DimensionRowsView` exists; missing the 4th tab | A (small) |
| Drill-down to source facts | Not built for any existing dimension either (branch/cost_center/project have none today) — a pre-existing limitation, not Brand-specific | C/A — a genuinely new capability if wanted; scope it explicitly rather than assume it's included |
| Permissions | `reports.finance.view` + Finance's own view permissions already gate `RPT-FIN-05`; extending its filters needs no new permission | D |
| Auditability | Every dimensioned figure derives from posted, immutable journal lines — inherently auditable via the existing journal/audit trail; no new audit engine needed | D |
| Tests | None exist yet for a Brand/profit_center cut specifically | A |

---

## BUSINESS DECISIONS (only where source genuinely cannot answer)

1. **Should Commerce revenue/COGS actually be wired to populate `profit_center_id` from `OrderFinancialSnapshot.brand_id`?** Source shows this is possible and low-risk, but it is a real posting-behavior change (retroactively, all *future* postings would start carrying a dimension they don't today) that should be a deliberate decision, not assumed inside a reconciliation pass. *Safe default if left unresolved: leave revenue/COGS company-level-only; FIN-04's Brand view then honestly reports Commerce's own share as `available:false`/unallocated, exactly like product/channel do today.*
2. **Should Procurement/AP ever get a per-line Brand split?** No existing rule or model supports this; a supplier invoice is not inherently Brand-scoped. *Safe default: keep Procurement in the "Unallocated/Company-level" bucket permanently unless a future task defines how a multi-Brand supplier bill would even be split.*
3. **Should Parent Economics ever mean cross-company aggregation?** No canonical model supports it today. *Safe default: Parent Economics = Company→Brands rollup only, explicitly documented as such; do not build cross-company aggregation without a real canonical parent/group model existing first.*

---

## IMPLEMENTATION PLAN

**TASK 1 — Canonical Brand/Company Economics read model + API + GL reconciliation.**
- Scope: add `ProfitabilityService::byProfitCenter()` (mirrors `byBranch`/`byCostCenter`/`byProject` exactly) with an explicit "Unallocated" total (`company total − Σ dimensioned rows`, computed once, named honestly); add `'profit_center'` (surfaced to the UI/API as "Brand") to `ProfitabilityAndClosingReportQuery`'s filter `Rule::in()`; wire `PostRevenueAndCogsOnOrderDelivered.php` to read `brand_id` from the order's `OrderFinancialSnapshot` (or wherever `OrderDeliveredEvent`'s own figures are sourced from) and pass it through as `profitCenterId` to `recognizeRevenue()`/`recognizeCogs()` — **only if Business Decision 1 is resolved yes**; add focused tests proving Brand totals + Unallocated = company total, no hidden residual, for a period with a mix of dimensioned and undimensioned postings.
- Likely files: `Modules\Finance\Intelligence\Domain\Services\ProfitabilityService.php`, `Modules\Finance\Reporting\Application\Queries\ProfitabilityAndClosingReportQuery.php`, `Modules\Finance\Integration\Application\Listeners\PostRevenueAndCogsOnOrderDelivered.php`, `Modules\Finance\Integration\Domain\Services\CommercialAccountingService.php` (no signature change needed — the param already exists), new/extended `tests/Feature/Finance/*`.
- No-new-authority constraints: no new ledger, no new model beyond what's listed, no new account/role, reuses `CostAllocationService` untouched, reuses `JournalEngine`/`PostingCoordinator` untouched.
- Focused test plan: (a) company total = Σ(all dimensioned rows) + Unallocated, exactly, for a mixed period; (b) a posting with a real `profit_center_id` appears under its Brand; (c) Commerce revenue/COGS carry the Brand id once wired (if Decision 1 = yes); (d) `RPT-FIN-05` accepts `dimension=profit_center` and returns the same shape as `branch`/`cost_center`/`project`; (e) company isolation preserved (two companies' figures never mix).
- Visible user result: the existing Finance → Costing & Profitability page gets a working Brand tab, reconciling exactly to the company P&L already shown there.

**TASK 2 — not proposed.** Source evidence does not show a material safety reason to split UI from the API here — they are already one canonical pair (`RPT-FIN-05` + `ProfitabilityTab`), and extending both together is the same size of change as the other three dimensions already following this exact pattern. A second task would only be justified if the Business Decision on Commerce wiring is deferred; even then, Task 1 stands on its own (Brand simply stays `available:false`, same as product/channel, until that decision lands).

**IMPLEMENTATION TASK COUNT: 1**

---

## FIN TRACK CLOSURE CRITERIA

FIN development may be declared **FIN DEVELOPMENT COMPLETE** (source/development closure only — not deployment, not NEXT integration, not user verification) once, in addition to FIN-04 Task 1 landing:

- FIN-01 preserved (Attendance/KPI/Compensation foundation, HR-side — untouched by FIN-02/03/04)
- FIN-02 preserved (Compensation/Payroll/Advances/Commission functional completeness — untouched)
- FIN-03 preserved (Payroll→Finance posting, advance-recovery safety block — verified untouched at every reconciliation in this session)
- FIN-04 implemented per Task 1 above, with the Unallocated invariant holding and tested
- `JournalEngine` remains the sole GL writer (true at every checkpoint traced)
- No duplicate wallet/ledger exists anywhere (confirmed absent again this pass — `OrderFinancialSnapshot` is a distinct, ADR-governed, non-GL commercial authority, explicitly not a second ledger)
- Canonical GL reconciliation holds for every reported figure (Brand + Unallocated = company total, always)
- Company isolation holds everywhere (confirmed, no exception found)
- Money handling exact (`decimal(20,4)`, no invented FX conversion, ledger-currency-only stated honestly)
- No allocation percentage/formula ever invented (`CostAllocationService` remains caller-supplied only)
- No financial overtime (re-confirmed absent, unchanged)
- Payroll advance safety preserved (re-confirmed unchanged at every checkpoint)
- Reversals respected everywhere reporting reads from posted facts
- Reporting stays honest about what isn't attributed (`available:false` convention preserved and extended, never silently papered over)

This is **development/source closure only** — it does not mean NEXT-integrated, TEST-deployed, user-verified, or certified.

---

## CONFIRM

- FIN-01 preserved
- FIN-02 preserved
- FIN-03 preserved
- No FIN implementation performed in this task
- No FIN → NEXT merge (this was NEXT → FIN, one-way, as instructed)
- No NEXT deployment
- `develop` untouched
- `LIVE` untouched
- `TEST` runtime untouched
- No DB
- No migrations
- No agents
- No background tasks
- No push
- `E:\ECOS\ECOS-V1-STAGING` untouched

STOP.
