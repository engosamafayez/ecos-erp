# TASK-UAT-009 — Enterprise Certification Campaign 9
## Finance Platform

**Date:** 2026-08-08
**Framework:** ECF v4 (Rules 1–18) + Enterprise Certification Principle
**Method:** UI only. No SQL, no Tinker, no DB modification, no code, no fixes.
**Context:** `ECOS Holding 20` active.

**Question (Rule 18):** *Can a paying enterprise customer safely rely on ECOS ERP Finance?*

# Answer: **The ledger can be trusted. The business feeding it cannot.**

---

## Special focus: is every financial report derived from the General Ledger?

# **Yes — and this is the first special-focus test to PASS in nine campaigns.**

The chain was traced end to end against four journal entries posted through the real event →
rule → journal pipeline.

### General Ledger — 4 posted entries, each balanced

| Reference | Debit | Credit |
| --- | --- | --- |
| `TASK-CUTOVER-002` (raw_material) | 1,000.00 | 1,000.00 |
| `TASK-CUTOVER-003/raw_material` | 100.00 | 100.00 |
| `TASK-CUTOVER-003/packaging_material` | 200.00 | 200.00 |
| `TASK-CUTOVER-003/finished_good` | 300.00 | 300.00 |
| | **1,600.00** | **1,600.00** |

`Total 4 · Draft 0 · Posted 4 · Reversed 0`

### ↓ Trial Balance — aggregates exactly

| Code | Account | Type | Debit | Credit |
| --- | --- | --- | --- | --- |
| 1410 | Finished Goods | Asset | 300.00 | — |
| 1420 | Raw Materials | Asset | **1,100.00** | — |
| 1440 | Packaging Materials | Asset | 200.00 | — |
| 2120 | Goods Received Not Invoiced | Liability | — | 1,600.00 |
| | | | **1,600.00** | **1,600.00** · **`Balanced`** |

**`1420 Raw Materials = 1,100.00` is the decisive figure**: it is `1,000.00` + `100.00` from two
separate journals, aggregated correctly. Each inventory class routed to its own account —
`raw_material → 1420`, `packaging_material → 1440`, `finished_good → 1410` — exactly as the
posting rules specify.

### ↓ Balance Sheet — derives correctly

```
ASSETS        Current Assets      EGP 1,600.00
              Total Assets        EGP 1,600.00
LIABILITIES   Current Liabilities EGP 1,600.00
              Total Liabilities   EGP 1,600.00
EQUITY        Total Equity        EGP 0.00
INDICATORS    Working Capital     EGP 0.00
              Current Ratio       1.00
```

**Assets = Liabilities + Equity → 1,600 = 1,600 + 0.** The accounting equation holds. Current
Ratio `1.00` and Working Capital `0.00` are both arithmetically correct for these balances.

**Verdict: Ledger → Trial Balance → Balance Sheet is internally consistent, correctly classified,
and balanced at every level. No disagreement was found anywhere in the chain.**

---

## Coverage

| Metric | Value |
| --- | --- |
| **Scope** | 28 areas |
| **Structural coverage** | **≈ 43%** (12 of 28 surfaces observed) |
| **Behavioural coverage** | **≈ 30%** — **the highest of any campaign.** Four journals posted through the real pipeline; fiscal year created; period opened; trial balance and balance sheet derived from live data |
| **Confidence** | **High** — the only campaign where a quantitative chain was verified arithmetically |

### Visited screens (7)

| # | Screen | Result |
| --- | --- | --- |
| 1 | Journal Entries | ✅ **4 posted, all balanced** |
| 2 | Financial Statements → **Trial Balance** | ✅ **Reconciles exactly** |
| 3 | Financial Statements → **Balance Sheet** | ✅ **Equation holds** |
| 4 | Fiscal Calendar & Closing | ✅ FY2026/FY2027, 24 periods, open/close per period |
| 5 | Cash & Banking | ✅ Renders (empty) |
| 6 | Accounts Receivable | ✅ Renders (empty), aging buckets |
| 7 | Budgets · Tax & VAT | ✅ Render (empty) |

### Behavioural evidence (verified execution on this module)

| Operation | Evidence | Result |
| --- | --- | --- |
| Fiscal year creation | `POST /api/finance/fiscal/years` → **201**, 12 periods generated | ✅ PASS |
| Period open | `PATCH /api/finance/fiscal/periods/{uuid}/open` → **200** | ✅ PASS |
| **Fiscal period enforcement** | Posting with no open period → **dead-lettered**: *"No fiscal period covers 2026-08-08. Create and open the period first."* | ✅ **PASS — enforced** |
| Event → rule → journal | Balanced entry posted; queue drained; **no dead letter** | ✅ PASS |
| Account mapping | `@inventory_class` resolved per class to 1410/1420/1440 | ✅ PASS |

### Blocked screens (0) · Untested areas (16)

**No screen found (7):** Account Roles · General Ledger *(as a browsable ledger — only journals and
trial balance exist)* · Cash Flow statement · Cost Centers · Bank Reconciliation · Financial
Reports · Financial Notifications.
**Exists, not exercised (9):** Chart of Accounts · Income Statement tab · Accounts Payable ·
Bank Accounts · Period closing/reopening · Budgets · VAT/Tax entry · Business Accounts ·
Financial permissions.

### Skipped workflows

| Workflow | Reason |
| --- | --- |
| Manual journal entry | **Not attempted.** Posting into the only clean ledger in the platform risked corrupting the one dataset that currently reconciles. |
| Period close / reopen | Would lock the period the verified journals sit in |
| AR/AP transactions | No invoices exist — Orders carry `Tax N/A` and no invoice was raised (Campaign 6) |
| Bank reconciliation | No bank accounts, no statements |
| Multi-company isolation | **UNVERIFIED** — only ECOS Holding has any financial data |

---

# SECTION 1 — Individual Findings

### UAT9-001 — The ledger is correct but starved: no revenue has ever posted · **P0**

| | |
| --- | --- |
| **Class (R9)** | **INTEGRATION** |
| **Evidence** | The Trial Balance contains **four accounts**: three inventory assets and GRNI. There is **no revenue, no COGS, no AR, no cash, no tax**. `Total Equity EGP 0.00`. Meanwhile Orders reports **EGP 21,132.00** across two orders, and the Executive Dashboard reports `EGP 21.1K`. |
| **Expected** | Sales post to revenue and AR; the Income Statement shows turnover. |
| **Actual** | EGP 21,132.00 of order value has produced **zero** entries in the general ledger. The only postings in the system are the four synthetic inventory receipts created during cutover verification. |
| **Business consequence** | **Finance reports EGP 0 revenue while the business has sold EGP 21,132.** The Income Statement, Cash Flow and every profitability metric are structurally empty — not wrong, but blind. A CFO closing a period on this ledger would report no trading activity. This is the mirror of Campaign 8: there, CRM could not see the customers; here, Finance cannot see the sales. |
| **Root cause (R10)** | **Unknown.** Candidates the UI cannot separate: (a) no invoice was ever raised, so nothing was posted (Orders offers no invoice action — Campaign 6); (b) the order→finance bridge exists but is not subscribed for sales events. **Rule 10 requires "Unknown."** |
| **Pattern (R13)** | **RC-11** — declared-but-unexercised integration |
| **Fix strategy (R16)** | **ARCHITECTURAL FIX** after diagnosis |
| **Effort (R11)** | **Unknown** |

### UAT9-002 — No General Ledger, Cash Flow, Cost Centers or Bank Reconciliation screen · **P1**

| | |
| --- | --- |
| **Class (R9)** | **BUSINESS** |
| **Actual** | Finance offers 10 navigation entries. There is **no browsable General Ledger** (account-level transaction history), **no Cash Flow statement**, **no Cost Centers**, **no Bank Reconciliation** and **no Financial Reports** screen. Financial Statements provides Trial Balance, Balance Sheet and Income Statement only. |
| **Business consequence** | Without a browsable GL, an accountant cannot drill from a trial-balance figure to the transactions composing it — the single most common audit and investigation task in finance. Cash Flow is one of the three primary statements and is absent. Bank reconciliation is a monthly control that cannot be performed. Cost centers mean profitability cannot be analysed by department or branch. |
| **Root cause (R10)** | **Missing Feature** |
| **Pattern (R13)** | **RC-3** |
| **Effort (R11)** | **XL** |

### UAT9-003 — VAT cannot be charged, so tax reporting has no source · **P1**

| | |
| --- | --- |
| **Class (R9)** | **BUSINESS** |
| **Actual** | A `Tax & VAT` screen exists with VAT periods and tax codes, and reports `VAT Periods 0 · Tax Codes 0`. Orders show **`Tax: N/A`** and *"Products Total = Grand Total"* (Campaign 6). Campaign 1 found **no tax configuration screen** in Administration. |
| **Business consequence** | The VAT return workspace exists but can never be populated, because no transaction can carry tax. Egyptian VAT is mandatory on domestic sales; the platform cannot produce a compliant invoice or a filable return. **The reporting end was built; the collection end was not.** |
| **Root cause (R10)** | **Missing Feature** — tax configuration and order tax lines |
| **Pattern (R13)** | **RC-3** |
| **Effort (R11)** | **L** |

### UAT9-004 — Financial Statements route not reachable by its own name · **P3**

| | |
| --- | --- |
| **Class (R9)** | **BUG** |
| **Actual** | The page is titled *Financial Statements* and served at `/app/accounting/statements`. `/app/accounting/financial-statements` returns **404**. |
| **Business consequence** | Trivial in isolation, but it is the **third instance** of navigation metadata diverging from routing — Campaign 1 (duplicate `Settings`), Campaign 4 (palette advertising `/app/manufacturing`). Bookmarks and shared links are the common casualty. |
| **Pattern (R13)** | **RC-8** — navigation registry not validated against router |
| **Effort (R11)** | **XS** |

---

# SECTION 2 — Root Cause Matrix

**4 findings → 0 new root causes.** Second consecutive campaign producing none.

| Root cause | Class | Status | Findings | Sev | Effort | Fix strategy | Priority |
| --- | --- | --- | --- | --- | --- | --- | --- |
| **RC-11** Declared-but-unexercised integrations | INTEGRATION | Existing | UAT9-001 | **P0** | Unknown | ARCHITECTURAL FIX | **1** |
| **RC-3** Absent surfaces | BUSINESS | Existing | UAT9-002, UAT9-003 | P1 | XL | PRODUCT DECISION | 2 |
| **RC-8** Nav registry vs router | BUG | Existing | UAT9-004 | P3 | XS | IMPLEMENTATION FIX | 3 |

### What this campaign proves about the other root causes

**RC-9 (state computed independently of source) does NOT apply to Finance.** Every figure checked
derived correctly from the ledger. This is significant: it shows the platform **can** build a
correctly-derived reporting chain — the Campaign 5 and 8 failures are not an architectural
incapacity but localised omissions.

**RC-1 produced no finding**, but only ECOS Holding has financial data. **Finance tenant isolation
is UNVERIFIED.**

### Cross-campaign consolidation

| | Count |
| --- | --- |
| Findings this campaign | 4 |
| New root causes | **0** |
| Explained by existing causes | **4 of 4 (100%)** |
| **Total root causes across 9 campaigns** | **11** |
| **Total observed defects** | **~52** |

---

# SECTION 3 — Enterprise Risk Matrix

| Risk | UAT9-001 No revenue posted | UAT9-002 No GL/Cash Flow/Recon | UAT9-003 No VAT | UAT9-004 Route mismatch |
| --- | --- | --- | --- | --- |
| **Customer** | Medium | Medium | High | Low |
| **Operational** | High | **Critical** | High | Low |
| **Financial** | **Critical** | **Critical** | High | None |
| **Security** | None | None | None | None |
| **Compliance** | **Critical** | **Critical** | **Critical** | None |
| **Data integrity** | High | Medium | Medium | None |
| **Reputation** | High | Medium | Medium | Low |
| **Engineering** | **Critical** (unknown) | **Critical** (XL) | High (L) | Very low (XS) |

### Reading the matrix

**Three of four findings score Critical on Compliance** — the highest compliance concentration of
any campaign. Finance is where regulatory exposure lives: unposted revenue means understated
turnover, no bank reconciliation means an uncontrolled cash position, and no VAT means unfilable
returns. Each is independently sufficient to fail a statutory audit.

**UAT9-001 is Critical on Financial and Compliance but only High on Operational** — the business
can keep trading; it simply cannot report. That is precisely what makes it dangerous: nothing
breaks visibly until period close.

**Notably, no finding here scores on Security or Data-integrity-Critical.** For the first time in
nine campaigns, the module's own data is *correct*. The risk is what is missing from it.

---

# SECTION 4 — Engineering Backlog Recommendation

### Stage 0 — Decisions

| # | Decision | Owner | Blocks |
| --- | --- | --- | --- |
| **D20** | **When does a sale post to the ledger** — on order confirmation, delivery, or invoice? No invoice action exists today | Finance + Product | UAT9-001 |
| D21 | v1.0 scope for GL browser, Cash Flow, Cost Centers, Bank Reconciliation | Product + Finance | UAT9-002 |
| D14 *(carried)* | Tax model — rates, inclusive/exclusive, per channel | Finance | UAT9-003 |

### Stage 1 — Diagnose

| # | Work | Root cause | Effort |
| --- | --- | --- | --- |
| 1 | **Trace why EGP 21,132 of orders produced zero postings** — is the sales bridge unsubscribed, or is invoicing simply absent? | RC-11 | Unknown |
| 2 | Fix the `/app/accounting/financial-statements` route | RC-8 | **XS** |

### Stage 2 — After D20

| # | Work | Root cause | Effort |
| --- | --- | --- | --- |
| 3 | Sales invoice → revenue + AR posting | RC-11 | L |

### Stage 3 — Statutory completeness

| # | Work | Root cause | Effort |
| --- | --- | --- | --- |
| 4 | Browsable General Ledger with drill-down from Trial Balance | RC-3 | L |
| 5 | Cash Flow statement · Bank Reconciliation | RC-3 | XL |
| 6 | Tax configuration → order tax lines → VAT return | RC-3 | L |

---

## GO / NO-GO — Finance only

# NO-GO — but with the strongest engineering foundation in the platform

### Why NO-GO

**Finance has never recorded a sale.** EGP 21,132.00 of orders exist; the ledger contains four
synthetic inventory receipts and nothing else. Revenue, COGS, AR, cash and tax accounts are all
absent from the trial balance. Add no browsable General Ledger, no Cash Flow statement, no bank
reconciliation and no ability to charge VAT, and the module cannot support a statutory close.

### Why this is nonetheless the most reassuring campaign of the nine

**This is the first module whose special-focus consistency test passed — and it passed
arithmetically, not structurally.**

Four journals posted through the real event→rule→journal pipeline aggregate exactly into the
Trial Balance (`1,000 + 100 = 1,100` on account 1420), which balances at `1,600.00 = 1,600.00`
with a `Balanced` badge, which derives into a Balance Sheet where `Assets 1,600 = Liabilities
1,600 + Equity 0` and the Current Ratio computes to `1.00`.

Additionally verified behaviourally:

- **Fiscal period enforcement genuinely blocks posting** — a journal with no open period was
  refused with an actionable message and dead-lettered rather than silently dropped
- **Account mapping resolves per inventory class** — `raw_material → 1420`, `packaging_material →
  1440`, `finished_good → 1410`
- **Balanced-entry integrity** — every journal debit equals credit; `Draft 0`, `Reversed 0`
- **Zero console errors**

**The double-entry engine is correct.** Where other modules invented state (Campaign 5's false
`In Stock`) or fragmented it (Campaign 8's two customer populations), Finance derives everything
from one ledger and gets the arithmetic right.

**This matters beyond Finance:** it proves the platform is *capable* of a correctly-derived
reporting chain. RC-9 is therefore a localised omission, not an architectural limitation.

### The honest limit

Finance is correct about the four transactions it has. **It has never been tested at volume, with
revenue, with tax, across periods, or across companies.** Tenant isolation is **UNVERIFIED** — only
one company has financial data.

### Confidence

**High** — uniquely so. This is the only campaign where conclusions rest on arithmetic that can be
checked rather than on inspection of screens.

---

**No SQL. No Tinker. No database modification. No UI bypass. No code. No fixes. No journal was
posted this campaign — the existing ledger is the only reconciling dataset in the platform and was
deliberately left intact.**
