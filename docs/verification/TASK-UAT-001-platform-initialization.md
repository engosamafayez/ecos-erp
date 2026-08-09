# TASK-UAT-001 — Enterprise UAT Campaign 1
## Platform Initialization & Company Setup

**Date:** 2026-08-08
**Role:** Prospective customer onboarding a brand-new company without engineering assistance.
**Method:** UI only. No SQL, no Tinker, no DB modification, no UI bypass, no code, no fixes.
**Rule applied:** Maximum Coverage — auditing continued past every blocker, using existing valid
records (`ECOS Holding 20`) once the new company could not proceed.

**Question:** *Can a brand-new company onboard itself into ECOS ERP?*

# Answer: **No.**

---

## Coverage

**Campaign scope: 17 areas. Audited: 9. Coverage ≈ 53%.**

### Visited screens (15)

| # | Screen | Route | Result |
| --- | --- | --- | --- |
| 1 | Organization Overview | `/app/organization` | ✅ Pass |
| 2 | Companies (list) | `/app/companies` | ⚠️ Search broken |
| 3 | New Company drawer | — | ⚠️ Currency optional |
| 4 | Company switcher | header | ⚠️ Leaks context |
| 5 | Warehouse switcher | header | ❌ Shows foreign warehouse |
| 6 | Warehouses (list) | `/app/warehouses` | ❌ Created record invisible |
| 7 | Create Warehouse drawer | — | ⚠️ Ignores active company |
| 8 | Branches | `/app/branches` | ✅ Pass |
| 9 | Branch Coverage | `/app/settings/branch-coverage` | ✅ Pass |
| 10 | Teams | `/app/teams` | ✅ Pass (empty) |
| 11 | Brands | `/app/brands` | ⚠️ Duplicate codes |
| 12 | Business Accounts | `/app/business-accounts` | ⚠️ Orphaned record |
| 13 | Sales Channels | `/app/channels` | ⚠️ Contradictory status |
| 14 | Users | `/app/admin/users` | ❌ **BLOCKED** — Coming Soon |
| 15 | Roles & Permissions | `/app/admin/roles` | ❌ **BLOCKED** — Coming Soon |
| 16 | Settings | `/app/settings` | ⚠️ Redirects to Configuration OS |
| 17 | Configuration OS | `/app/admin/configuration` | ✅ Pass (landing) |

### Blocked screens (2)

| Screen | Reason |
| --- | --- |
| Users | Renders "Coming Soon" placeholder — module not implemented |
| Roles & Permissions | Renders "Coming Soon" placeholder — module not implemented |

### Untested screens (8) — reachable but not opened

| Screen | Reason not tested |
| --- | --- |
| Company Settings detail (currency, timezone, fiscal year, default warehouse, language) | Row click did not register; session budget exhausted before retry. **This is the single highest-value untested screen** — it likely covers Currencies, Fiscal Year and General Settings. |
| Brand configuration workspace (14 configuration areas) | Requires selecting a brand on Configuration OS; not reached |
| Product Mapping | Not reached |
| Sync Logs | Not reached |
| Fiscal Calendar & Closing (Finance) | Outside the Administration nav; in campaign scope but not reached |
| Tax & VAT (Finance) | Same |
| Departments | **No Departments screen exists in Administration.** Likely lives under HR & Workforce → Structure — outside this campaign's navigation area |
| Currencies · Notification Settings · Branding | **No dedicated screens found anywhere in Administration.** Currency appears as a field on the company; branding as a logo upload. Whether standalone screens exist is unconfirmed |

### Skipped workflows

| Workflow | Reason |
| --- | --- |
| Complete new-company onboarding | **Blocked** by UAT1-002 — warehouse invisible after creation |
| Create user / assign role / configure permission | **Blocked** by UAT1-003 — no UI exists |
| Configure fiscal year & taxes for the new company | **Dependent** on the above; also requires the untested Company Settings screen |
| Edit / delete Company, Warehouse, Branch | Not attempted — audit prioritised create-path coverage |
| Import / bulk actions | No import control observed on any Administration screen |
| Localization (AR/RTL) of Administration screens | Not re-tested this campaign (verified platform-wide previously) |

---

## Issues

### UAT1-001 — Cross-tenant context leak · **P0**

| | |
| --- | --- |
| **Screen** | Header switchers; Administration → Warehouses |
| **Steps** | 1. Create `Nile Foods Trading` (0 warehouses). 2. Header → switch active company to it. 3. Open the warehouse switcher. 4. Open Warehouses. |
| **Expected** | Warehouse context clears or is refused; list scoped to the active company. |
| **Actual** | Header still reads **"Main Warehouse · Cairo, Egypt"** — owned by **ECOS Holding 20**. The switcher lists it as the selected (✓) and only option. The grid shows it with `Company = ECOS Holding 20` while Nile is active. |
| **Business impact** | **Severe.** A user who believes they are in Company A operates against Company B's inventory. Any stock movement, receipt, order or transfer hits another legal entity. Invisible — the header *looks* right because the company name changed. In a group structure this is a cross-entity integrity and audit failure. |
| **Root cause hypothesis** | Warehouse context is not invalidated on company change; the list query is not company-scoped. |
| **Recommendation** | Clear/rescope warehouse context on company switch; scope the list; refuse a context not owned by the active company. |
| **Console / Network** | No console errors. `GET /api/warehouses?company_id=<Nile>` **200** returns nothing, yet the UI retains ECOS's warehouse — the empty scoped result is ignored. |

### UAT1-002 — A created warehouse never appears anywhere · **P0**

| | |
| --- | --- |
| **Screen** | Administration → Warehouses → Create Warehouse |
| **Steps** | 1. **New Warehouse**. 2. Company `Nile Foods Trading (COM-000004)`. 3. Name `Cairo Distribution Centre`. 4. City `Cairo`. 5. **Create warehouse**. 6. Observe grid/KPIs. 7. **Refresh**. 8. Open header switcher. |
| **Expected** | Appears in grid; `Total Warehouses` → 2; selectable in the header. |
| **Actual** | Drawer closes, no error. **`POST /api/warehouses` → 201 — the record is created.** It then appears **nowhere**: grid unchanged (1 row, ECOS's), `Total Warehouses` **1**, `Companies` **1**, header switcher unchanged. Persists after Refresh and reload. |
| **Business impact** | **Onboarding blocker.** The customer creates the first warehouse and the system denies its existence. No error to act on, no way to reach the record. A warehouse is prerequisite to all stock, procurement and order activity, so onboarding stops here. Also invites duplicates — an operator seeing nothing will retry. |
| **Root cause hypothesis** | List/KPI queries use a scope the new record does not satisfy — plausibly the same company-scoping fault as UAT1-001, or a missing required association. **The write path is healthy; the read path is not.** |
| **Recommendation** | Scope list/KPI queries to the active company and include new records; add a success toast linking to the record. |
| **Console / Network** | No console errors. `POST /api/warehouses 201`, then `GET …?status=all&page=1&per_page=10` **200** returning the stale single row. |
| **Evidence correction** | My first network read used a truncated limit and appeared to show *no POST at all*. The full log shows the **201**. **The defect is invisibility of a created record, not a failed write** — this changes the fix. |

### UAT1-003 — No Users, Roles or Permissions UI · **P1 (blocker)**

Both screens under **PEOPLE & ACCESS** render *"Coming Soon — The … module is not available yet."*
The customer cannot onboard one employee: no user creation, no role assignment, no permission
administration, no segregation of duties. Administration requires the vendor.

Note the contradiction: Organization Overview reports **Users 3** and **Pending Invitations 0** —
the platform reports on users it provides no way to manage, and implies an invitation flow with
no visible entry point.

### UAT1-004 — A company can be created with no currency · **P1**

Entering only Company Name → `POST /api/companies` **201**; Currency column shows **`—`**. Only
Company Name carries `*`. The company is the root of every monetary value — orders, invoices, GL,
AR/AP, VAT, budgets. With no base currency no amount booked against it has defined meaning. Fails
silently; surfaces later, in accounting. **Recommendation:** required + defaulted; backfill
`Nile Foods Trading`.

### UAT1-005 — Companies search issues no query and does not filter · **P2**

Typing `OSAMA` and pressing Enter leaves all 3 unrelated rows and `Page 1 of 1 · 3 total`
unchanged. **No network request is issued at any point.** A `Clear` control appears and Refresh
greys out — the UI holds filter state it never applies. Unusable at scale and actively
misleading: an operator could conclude a record does not exist. Orders implements this correctly,
so the pattern exists. **Recommendation:** bind the input to the list query.

### UAT1-006 — No guided onboarding; new companies dead-end · **P2**

`Nile Foods Trading` is created with 0 brands, 0 warehouses, no currency, no branch, no fiscal
year, and no prompt toward a next step. Pre-existing `AxieFood` likewise shows **0 warehouses** —
the state persists rather than being transient. **Recommendation:** post-creation checklist
(currency → branch → warehouse → fiscal year → users) with completion state on the company row.

### UAT1-007 — Duplicate codes across companies · **P2**

Both brands show code **`BRD-000001`** (Aseel/AxieFood and ECOS Holding/ECOS Holding 20). Both
business accounts show **`BA-000001`**. Codes appear to be generated per-company but are displayed
in global lists where they read as duplicates. **Business impact:** codes are what staff quote in
email and on paper; two records sharing one identifier in a cross-company view invites
mis-reference. **Recommendation:** make codes globally unique, or qualify them in global lists.

### UAT1-008 — Orphaned Business Account with no company or brand · **P2**

`CairoCash` shows `Company —` and `Brand —` while marked **Active**. It is nonetheless linked to
sales channel `AseelMob` (`https://cairocash.com/`). An active integration account belonging to no
legal entity cannot be governed, permissioned or reported on. **Recommendation:** require company
on creation; surface unlinked accounts as a warning state.

### UAT1-009 — Channels show "Disconnected" and "Active" simultaneously · **P3**

All 3 channels display `Connection: Disconnected` alongside `Status: Active`, with `Last Sync —`
and `Connected channels 0` while `Active channels 3`. Two truthful fields that read as a
contradiction to an operator. **Recommendation:** derive a single effective state, or visually
subordinate `Status` to `Connection`.

### UAT1-010 — "Settings" and "Configuration OS" are the same page · **P3**

`/app/settings` redirects to `/app/admin/configuration`. Two sidebar entries in two different
sections lead to one destination, and the sidebar then highlights **both** simultaneously.
Separately, Branch Coverage (`/app/settings/branch-coverage`) also highlights **Settings** at the
same time. **Recommendation:** remove the duplicate entry; fix active-state matching.

### UAT1-011 — Create Warehouse ignores the active company · **P3**

Company is required but defaults to `Select company…` although a company is active in the header.
Every creation re-asks a question the shell already knows, inviting mis-selection.

### UAT1-012 — Unknown routes hang ~15 s before 404 · **P3**

A plausible-but-wrong path shows a blank spinner for ~15 s, then the 404 page — reads as an
outage, not a wrong address. The 404 page itself is well built (Go back / Dashboard).

### UAT1-013 — First click after page load frequently swallowed · **P3 (observation)**

A control shows hover styling but performs no action; a second click works. Seen on Companies,
Warehouses, Overview, Sales Channels and Company Settings. **Partly attributable to automation
clicking faster than a human — recorded as an observation, not a confirmed user-facing defect**,
though it recurred often enough to be worth a look.

### UAT1-014 — Inconsistent KPI treatment · **P3**

Companies, Warehouses, Teams, Brands, Business Accounts and Channels each show a KPI band;
**Branches shows none**. Minor, but noticeable when moving between sibling screens.

---

## What worked well

| Observation | Evidence |
| --- | --- |
| Required-field validation | Empty Company Name → inline `Company name is required.`, red border, focus moved, **no request issued**. Same on Warehouse Name. |
| Company switcher | Search box, avatars, ✓ on current, inline **+ New company**. Genuinely good. |
| Organization Overview | 8 KPIs, org-structure tree, activity feed, and an honest empty state: *"Live health metrics available once Business Account integration is complete."* |
| Branch Coverage | Clean master–detail with a clear instruction: *"Select a branch to manage its coverage areas."* |
| Auto-generated codes | `COM-000004`; `Auto-generated` placeholder explains the rule. |
| Grid affordances | Sortable columns, segmented filters, column chooser, Export, pagination — consistent across 6 screens. |
| Form state retention | `City` survived a failed submit and a company re-selection. |
| Console cleanliness | **Zero console errors across all 17 screens visited.** |

---

## Scores

| Score | Value | Basis |
| --- | --- | --- |
| **Platform Setup** | **3 / 10** | Six organization screens work well. The sequence still cannot be completed: warehouse creation yields an invisible record, user/role setup does not exist, currency is optional. |
| **UX** | **6 / 10** | Genuinely well-crafted where it works — validation, switchers, grids, empty states, zero console errors. Held down by silent failure, a search that lies, duplicate nav entries, contradictory status pairs and a 15 s pause before 404. |
| **Business Readiness** | **1 / 10** | A company cannot reach an operational state through the product. No users, no usable warehouse, no currency, and an active context pointing at another company's inventory. |

Scores cover **only the 9 audited areas**. The 8 untested areas are excluded — not credited.

---

## Blocking issues

1. **UAT1-002 (P0)** — created warehouse never appears; onboarding cannot proceed.
2. **UAT1-001 (P0)** — active company operates against another company's warehouse.
3. **UAT1-003 (P1)** — no Users/Roles/Permissions UI.
4. **UAT1-004 (P1)** — company creatable with no currency.

## Non-blocking issues

5. UAT1-005 (P2) — Companies search does not filter.
6. UAT1-006 (P2) — no guided onboarding.
7. UAT1-007 (P2) — duplicate codes across companies.
8. UAT1-008 (P2) — orphaned active Business Account.
9. UAT1-009 (P3) — contradictory channel status.
10. UAT1-010 (P3) — duplicate Settings entry; double sidebar highlight.
11. UAT1-011 (P3) — Create Warehouse ignores active company.
12. UAT1-012 (P3) — 15 s spinner before 404.
13. UAT1-013 (P3) — first click swallowed (observation).
14. UAT1-014 (P3) — inconsistent KPI bands.

## Recommendations

**Before re-running this campaign:**
1. Fix warehouse list/KPI scoping so created warehouses are visible (UAT1-002).
2. Rescope warehouse context on company switch; refuse foreign warehouses (UAT1-001).
3. Ship the IAM administration UI (UAT1-003).
4. Make Currency required and defaulted (UAT1-004).

**Then:** wire the Companies search, add an onboarding checklist, default the company field,
qualify or globalise codes, require company on Business Accounts, and resolve unknown routes
immediately.

**For the next campaign:** begin at **Company Settings** — the highest-value screen left
untested, and the likely home of Currencies, Fiscal Year and General Settings.

---

## GO / NO-GO — Platform Initialization only

# NO-GO

A brand-new company **cannot onboard itself without engineering assistance** — the exact question
this campaign was set to answer.

Three independent stoppers, each sufficient alone:

- It cannot create a usable warehouse — the record is accepted, then denied by every screen.
- It cannot create a single user.
- While configuring itself it is silently pointed at another company's inventory.

**This is not a verdict on the whole product.** The craftsmanship in the audited screens is real:
honest validation, commercial-quality grids and switchers, thoughtful empty states, and a clean
console across all 17 screens. The failures concentrate in **multi-company scoping** and a
**missing administration surface** — both fixable without redesign, and neither visible on a
single-company demo. That is precisely why they would reach a multi-entity customer.

**Confidence in this verdict is high for the 53% audited and nil for the remainder.** The
untested 8 areas could contain further blockers; they must not be read as passing.

---

**No SQL. No Tinker. No database modification. No UI bypass. No code. No fixes. Every finding is
reproducible through the interface with screenshot, console and network evidence.**

**Records created during this campaign (flag for cleanup):** company `Nile Foods Trading`
(`COM-000004`); warehouse `Cairo Distribution Centre` (created via `POST … 201`, not visible in
any UI).
