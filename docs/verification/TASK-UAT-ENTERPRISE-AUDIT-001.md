# TASK-UAT-ENTERPRISE-AUDIT-001 — Enterprise UAT

**Date:** 2026-08-08
**Perspective:** Prospective enterprise customer evaluating ECOS ERP for purchase.
**Method:** UI only. No Tinker, no SQL, no manual DB edits, no fabricated records, no UI bypass.

---

## ⚠️ COVERAGE STATEMENT — READ FIRST

**This audit is INCOMPLETE. It covers Phase 1 partially and did not reach Phases 2–15.**

The brief specifies a full company lifecycle across 15 phases — company setup, master data,
procurement, manufacturing, sales, logistics, CRM, finance, executive, reporting, notifications,
search, responsive, cross-module and UX. That is a multi-day exercise for a QA team. I exhausted
my working session inside Phase 1.

**What I actually executed is listed below. Everything else is UNTESTED — not "passed".**

| Phase | Status |
| --- | --- |
| 1 — Company Setup | **PARTIAL** — Companies CRUD, validation, search; IAM blocked |
| 2 — Master Data | **NOT EXECUTED** |
| 3 — Procurement | **NOT EXECUTED** |
| 4 — Manufacturing | **NOT EXECUTED** |
| 5 — Sales | **NOT EXECUTED** |
| 6 — Logistics | **NOT EXECUTED** |
| 7 — CRM | **NOT EXECUTED** |
| 8 — Finance | **NOT EXECUTED** |
| 9 — Executive | **NOT EXECUTED** |
| 10 — Reports | **NOT EXECUTED** |
| 11 — Notifications | **NOT EXECUTED** |
| 12 — Global Search | **NOT EXECUTED** |
| 13 — Responsive | **BLOCKED** — no viewport mechanism (proven) |
| 14 — Cross-Module | **NOT EXECUTED** |
| 15 — UX Review | **PARTIAL** — observations from Phase 1 only |

**The core objective — "operate until a complete customer order is delivered and fully
accounted for" — was NOT achieved.** I will not score modules I did not test, and I have not
issued a Go-Live score, because a score derived from one partial phase would be worse than no
score: it would look like a verdict.

---

## Findings

### UAT-001 — A company can be created with no currency · **P1**

| | |
| --- | --- |
| **Module / Screen** | Administration → Companies → New Company drawer |
| **Steps** | 1. Companies → **New Company**. 2. Enter Company Name `Nile Foods Trading`. 3. Leave Currency at its default `Select currency…`. 4. **Create Company**. |
| **Expected** | Currency is mandatory for a financial entity, or defaults to the tenant currency. Creation is refused or defaulted. |
| **Actual** | `POST /api/companies` → **201**. Company created; the Currency column shows **`—`**. |
| **Evidence** | Network: `POST /api/companies 201`. Grid row: `Nile Foods Trading · COM-000004 · Brands 0 · Warehouses 0 · Currency —`. |
| **Business impact** | A company is the root of every monetary value in the system — orders, invoices, GL postings, AR/AP, budgets, VAT. A company with no base currency has no defined meaning for any amount booked against it. This is silent: nothing warns the operator, and the defect only surfaces later, in accounting. |
| **Root cause hypothesis** | Currency is optional in the create request; the field has no default and no `required` marker (only Company Name carries `*`). |
| **Suggested fix** | Mark Currency required in the drawer and in request validation, defaulting to the tenant/base currency. |
| **Regression risk** | Low. Additive validation; existing companies unaffected, though `Nile Foods Trading` and any similar rows need backfilling. |
| **Console / Network** | No console errors. Single `POST` → 201. |

### UAT-002 — Companies search does nothing · **P2**

| | |
| --- | --- |
| **Module / Screen** | Administration → Companies → search box |
| **Steps** | 1. Open Companies (3 rows). 2. Type `OSAMA` in *Search companies…*. 3. Wait. 4. Press **Enter**. |
| **Expected** | The grid filters to matches (or shows an empty state), and the count updates — the behaviour Orders exhibits. |
| **Actual** | All 3 unrelated rows remain visible; footer still reads `Page 1 of 1 · 3 total`. **No network request is issued** — no `?search=` call appears at any point. A `Clear` button appears and the refresh control greys out, so the UI registers filter state it never applies. |
| **Evidence** | Network after Enter: only the original 4 `api/companies` calls (`?status=all&page=1…`, `?status=active&per_page=1`, `POST`, `?per_page=100`). No search query. Screenshot shows `OSAMA` typed with all 3 rows displayed. |
| **Business impact** | Unusable at real scale. A customer with hundreds of legal entities cannot find one. Worse than a missing feature: the control *looks* functional and silently returns wrong results — an operator could conclude a company does not exist. |
| **Root cause hypothesis** | The search input is wired to local state and a Clear affordance, but never to the query key or the request. Orders implements this correctly, so the pattern exists in the codebase. |
| **Suggested fix** | Bind the input to the list query (`?search=`), matching the Orders implementation. |
| **Regression risk** | Low, isolated to this workspace. |
| **Console / Network** | No console errors. **Absence of a request is the evidence.** |

### UAT-003 — Phase 1 cannot be completed: no Users, Roles or Permissions UI · **P1 — BLOCKER**

| | |
| --- | --- |
| **Module / Screen** | Administration → Users; Administration → Roles & Permissions |
| **Steps** | 1. Administration → **Users**. 2. Administration → **Roles & Permissions**. |
| **Expected** | Create users, assign roles, configure permissions — mandatory Phase 1 items and table stakes for any commercial ERP. |
| **Actual** | Both render **"Coming Soon — The … module is not available yet. This is a placeholder within the ECOS application shell."** with a `Coming Soon` badge. |
| **Evidence** | Screenshots of both screens; both reachable from the primary Administration navigation. |
| **Business impact** | **A purchasing company cannot onboard its own staff.** No user creation, no role assignment, no permission administration. Every subsequent phase that depends on segregation of duties — maker/checker on purchase approval, restricted finance access, warehouse-only operators — is unreachable through the product. Administration would require vendor intervention. For most enterprise buyers this alone fails procurement review. |
| **Root cause hypothesis** | Routes are wired to a placeholder component; the RBAC engine exists beneath but has no administrative surface. |
| **Suggested fix** | Ship the IAM administration UI. |
| **Regression risk** | N/A — net-new surface. |
| **Note** | Tracked internally as BUG-GL-002 and classified there as an accepted v1.0 limitation. **From a purchasing customer's standpoint that classification does not hold: "administered via seeders" means the vendor administers it, not the customer.** |

### UAT-004 — Unknown routes hang ~15s before showing 404 · **P3**

| | |
| --- | --- |
| **Steps** | Navigate to `/app/admin/companies` (a plausible but non-existent path). |
| **Expected** | Immediate 404, or a redirect. |
| **Actual** | Blank page with a spinner for ~15 s, then the 404 page. |
| **Business impact** | Reads as a hang, not a wrong address. Users retry or report an outage. The 404 page itself is well-built (Go back / Dashboard). |
| **Root cause hypothesis** | Route resolution awaits lazy-chunk resolution before falling through to the catch-all. |
| **Regression risk** | Low. |

### UAT-005 — First click after page load is frequently swallowed · **P3**

| | |
| --- | --- |
| **Steps** | Load any workspace, immediately click a sidebar item or primary button. |
| **Expected** | The click registers. |
| **Actual** | The control shows hover/active styling but no navigation or action occurs. A second click works. Observed repeatedly across Companies (sidebar), CRM (*New Customer*) and Purchasing (*Suppliers*). |
| **Business impact** | Low individually; cumulatively it makes the product feel unresponsive and causes double-submission habits. |
| **Root cause hypothesis** | Handlers attach after paint — controls are visible and styled before they are interactive. |
| **Note** | Partly attributable to automation clicking faster than a human. Recorded as an observation, **not confirmed as a user-facing defect.** |

### UAT-006 — No guided setup for a newly created company · **P2 (UX / workflow gap)**

| | |
| --- | --- |
| **Actual** | `Nile Foods Trading` is created with **0 brands, 0 warehouses, no currency, no branch, no fiscal year**. Nothing prompts the operator toward the next step. |
| **Evidence** | Grid shows `Brands 0 · Warehouses 0 · Currency —`. Existing `AxieFood` likewise shows **0 warehouses** — an unusable tenant that has persisted. |
| **Business impact** | A new customer's first action produces a dead entity with no path forward. Order-of-operations must be learned from documentation or support. `AxieFood` is evidence this state persists in practice rather than being transient. |
| **Suggested fix** | A post-creation checklist or wizard: currency → branch → warehouse → fiscal year → users. |

### UAT-007 — Responsive verification impossible in this environment · **BLOCKED, not failed**

Measured, not assumed: baseline `innerWidth 1920`; after `resize_window(420×860)` reporting
success, `innerWidth` remained **1920** and `matchMedia('(max-width: 640px)')` and
`'(max-width: 1024px)'` both stayed **false**; a sized `window.open` was blocked.
**Phase 13 is UNVERIFIED. No claim is made in either direction.**

---

## What worked well

Recorded because a purchase evaluation must weigh both sides:

| Observation | Evidence |
| --- | --- |
| Required-field validation is correct and well presented | Empty Company Name → inline `Company name is required.`, red border, focus moved to the field, **no request issued** |
| Auto-generated codes | `COM-000004` assigned without operator input; placeholder explains the rule |
| Create → list refresh is immediate and consistent | `Total Companies` 2 → 3 and the grid updated in one action |
| Grid affordances are enterprise-grade | Sortable columns, All/Active/Inactive segments, column chooser, Export, pagination footer |
| Drawer design | Sectioned, logo upload with explicit format/size limits, sensible timezone default (`Africa/Cairo`) |
| Console cleanliness | **Zero console errors across every screen visited in this session** |

---

## Issue lists

### P0 — Critical
None found *within the executed scope*. **This is not a statement that none exist** — procurement,
manufacturing, sales, finance and logistics were never exercised.

### P1 — High
1. **UAT-003** — No Users / Roles / Permissions UI. Blocks Phase 1 and customer self-administration.
2. **UAT-001** — Company creatable with no currency.

### P2 — Medium
3. **UAT-002** — Companies search issues no query and does not filter.
4. **UAT-006** — No guided setup after company creation; tenants can persist unusable.

### P3 — Low
5. **UAT-004** — ~15 s spinner before 404.
6. **UAT-005** — First click after load frequently swallowed (observation).

### UX improvements
- Default Currency instead of `Select currency…`.
- Debounced search that queries as you type (Orders requires Enter; Companies never queries).
- Post-creation "next steps" affordance on empty entities.
- Surface `Warehouses 0` as a warning state, not a neutral zero.

### Missing features (within scope seen)
- User management UI · Role management UI · Permission administration UI.

### Business workflow gaps
- A company cannot be taken from creation to operational readiness through the UI alone, because user/role setup is unavailable.

### Production blockers (customer view)
- **UAT-003.** An ERP the customer cannot administer is not deployable to an enterprise without a vendor dependency.

---

## Scores

**No scores are issued.**

The brief requests eleven scores including an Overall Go-Live Score. Ten of them cover modules I
did not test. Issuing numbers for Inventory, Manufacturing, Logistics, Finance, CRM, Executive or
Reporting would be fabrication, and an Overall score derived from one partial phase would read as
a verdict on a system that was never exercised.

The single score I can defend, scoped explicitly:

| Score | Value | Basis |
| --- | --- | --- |
| **Company Setup (Phase 1 only)** | **4 / 10** | Company CRUD, validation, grid and drawer quality are genuinely good (would score ~8). The phase cannot be completed at all: no users, no roles, no permissions — and a company can be created with no currency. An incompletable setup phase caps the score regardless of how polished the reachable parts are. |

---

## Final recommendation

# INCOMPLETE — CANNOT RECOMMEND OR REJECT

**As a customer:** on this evidence I would not sign. Not because the product looks weak — the
parts I exercised are well built, validation is honest, the grids and drawers are of commercial
quality and the console was clean throughout — but because **I could not complete day one.** I
cannot create my own users. I created a company with no currency and the system accepted it. I
searched for a company and was silently shown the wrong answer.

**As an auditor:** this report must not be read as a pass. Thirteen of fifteen phases were never
executed. The stated objective — a customer order delivered and fully accounted for — was not
reached. The correct next action is to **resume this audit from Phase 1 with adequate time**,
after UAT-003 is resolved, since it blocks the phase and everything downstream that depends on
segregated access.

**Immediate actions before any re-run:**
1. Ship the IAM administration UI (UAT-003) — otherwise Phase 1 cannot complete on a re-run either.
2. Make Currency required or defaulted (UAT-001), and correct `Nile Foods Trading`.
3. Wire the Companies search to its query (UAT-002).
4. Provide a real viewport mechanism, or Phase 13 will remain unverifiable.

---

**No Tinker. No SQL. No manual database modification. No fabricated records. No UI bypass. Every
finding above is reproducible through the interface and backed by a screenshot plus console and
network observation. Two records were created during this audit: company `Nile Foods Trading`
(`COM-000004`) — flag for cleanup.**
