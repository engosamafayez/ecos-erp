# TASK-UAT-004 — Enterprise Certification Campaign 4
## Manufacturing Platform

**Date:** 2026-08-08
**Framework:** ECF v4 (Rules 1–18) + Enterprise Certification Principle
**Method:** UI only. No SQL, no Tinker, no DB modification, no code, no fixes.
**Context:** Audited as **`ECOS Holding 20`** (the tenant owning warehouse and stock), per the
instruction to execute transactions where safe.

**Question (Rule 18):** *Can a paying enterprise customer safely rely on ECOS ERP Manufacturing?*

# Answer: **No. There is no Manufacturing platform to rely on.**

---

## The finding in one line

**ECOS ERP has an excellent Bill of Materials. It has no manufacturing.**

Recipes (BOM) is one of the best-built screens audited across four campaigns. Everything that
*consumes* a BOM — production orders, work orders, execution, reservations, waste recording,
cost rollup to finished goods — **does not exist**. The application's own command palette
advertises a Manufacturing module that returns **404**.

---

## Coverage

**Scope: 19 areas. Audited: 4. Coverage ≈ 21%. Confidence: high.**

Lowest coverage of any campaign — **because 14 of the 19 scoped areas have no screen to audit.**
This is not thin testing; it is a thin module.

### Visited screens (3)

| # | Screen | Route | Result |
| --- | --- | --- | --- |
| 1 | Recipes (BOM) list | `/app/inventory/recipes` | ⚠️ Cross-company visible |
| 2 | Recipe detail — Overview · Materials · Cost History | drawer | ✅ **Excellent** |
| 3 | **Manufacturing** | `/app/manufacturing` | ❌ **404 — advertised by the app itself** |

### Blocked screens (1)

| Screen | Reason |
| --- | --- |
| Manufacturing module | Route returns 404. Reached via the product's **own command palette entry**, not a guessed URL. |

### Untested areas (14) — no screen exists

Manufacturing Orders · Work Orders · Production Planning · Production Execution · Material
Availability · Production Reservations · Raw Material Consumption *(defined in BOM, never
executed)* · Packaging Consumption *(same)* · Finished Goods Production · Waste Recording
*(defined as a BOM %, never recorded)* · By-products · Manufacturing Dashboard · Manufacturing
KPIs · Manufacturing Reports · Manufacturing Notifications.

**Recipe Versions:** a `Cost History` tab exists, but no version list, no version compare, no
activate/deactivate per version. Versioning is **unconfirmed**.

### Skipped workflows

| Workflow | Reason |
| --- | --- |
| Produce finished goods from a recipe | **No execution surface exists.** Not a tenant-scope refusal — there is simply nothing to execute. |
| Approval / planning / scheduling / completion / cancellation | Same — no manufacturing order object exists |
| Create / edit a recipe | **Not attempted.** The only recipe belongs to `AxieFood` while `ECOS Holding 20` was active; creating one would have required choosing a company through a cross-company selector (UAT4-003) |
| Manufacturing permissions | No manufacturing screens to permission |
| Integrations (Inventory · Procurement · Finance · Notifications · Executive) | **Cannot be exercised** — all require a production transaction |

---

# SECTION 1 — Individual Findings

### UAT4-001 — The Manufacturing module does not exist · **P0**

| | |
| --- | --- |
| **Class (R9)** | **BUSINESS** |
| **Screen** | `/app/manufacturing` |
| **Steps** | 1. Press `Ctrl+K`. 2. Type `production`. 3. The palette returns **"Manufacturing — BOM and production management"** under NAVIGATION. 4. Press Enter. |
| **Expected** | A manufacturing workspace: production orders, execution, material availability, waste. |
| **Actual** | **404 Page not found.** No Manufacturing entry exists in the module rail. No production order, work order, execution, reservation, waste-recording or by-product screen exists anywhere in the application. |
| **Console / Network** | Zero console errors. Route resolves to the 404 component. |
| **Business consequence** | A manufacturer cannot manufacture. Raw materials can be purchased and a BOM defined, but stock can never be converted into finished goods through the product. There is no path from `عسل الصال` (raw) to `عسل الصال كيلو` (finished) — the exact transformation the BOM describes. Every downstream expectation fails with it: manufacturing cost never posts to Finance, consumption never posts to Inventory, and the Products KPI `Mfg Ready 1` describes a readiness that leads nowhere. |
| **Rule 12 category** | **Missing Product Decision** — whether Manufacturing is in scope for v1.0 |
| **Root cause (R10)** | **Missing Feature** |
| **Pattern (R13)** | **RC-3** — absent surfaces |
| **Fix strategy (R16)** | **PRODUCT DECISION**, then implementation |
| **Impact (R17)** | **Entire module** |
| **Effort (R11)** | **XL** |

### UAT4-002 — Command palette advertises a dead route · **P1**

| | |
| --- | --- |
| **Class (R9)** | **BUG** |
| **Steps** | `Ctrl+K` → `production` → select the only NAVIGATION result → 404. |
| **Expected** | Global search offers only destinations that exist. |
| **Actual** | The palette presents `Manufacturing — BOM and production management` as a first-class navigation target. It 404s. |
| **Business consequence** | Distinct from UAT4-001 and worth separating. An absent module is an honest gap a buyer can evaluate. **A module the product advertises and then fails to open reads as broken software, not missing scope** — and it is the first thing a customer finds when they search for the capability. It also undermines the palette generally: if one entry is fictional, none can be trusted. |
| **Root cause (R10)** | **Implementation** — the palette's navigation registry is not validated against the router |
| **Fix strategy (R16)** | **IMPLEMENTATION FIX** — remove the entry, or validate registry against routes at build time |
| **Impact (R17)** | 1 screen (the palette), platform-wide in perception |
| **Effort (R11)** | **XS** |

### UAT4-003 — Recipes leak across companies · **P0**

| | |
| --- | --- |
| **Class (R9)** | **SECURITY** |
| **Steps** | With **`ECOS Holding 20`** active, open Recipes. |
| **Expected (R6)** | **COMPANY SCOPED.** ECOS's recipes only. |
| **Actual (R6)** | Recipe `BOM-00001` displayed with **`COMPANY: AxieFood`** — a different tenant. Full disclosure: `Recipe Cost EGP 3,155.00`, per-line material costs (`EGP 3,060.00` raw, `EGP 95.00` packaging), waste percentages, and a `Cost History` tab. The filter bar offers **`All Companies`**. |
| **Business consequence** | **A bill of materials is a manufacturing trade secret.** It reveals exactly what a competitor's product is made of, in what proportions, at what input cost, with what yield loss. This is materially more sensitive than the supplier and margin leaks found in Campaigns 2–3. The `All Companies` filter again presents cross-tenant browsing as a feature. |
| **Root cause (R10)** | **Implementation** (visibility) + **Governance Decision** (the filter) |
| **Pattern (R13)** | **RC-1** and **RC-7** — no new root cause |
| **Fix strategy (R16)** | **ARCHITECTURAL FIX** (RC-1) after **PRODUCT DECISION** (RC-7 / D7) |
| **Effort (R11)** | **S** within RC-1 |

### UAT4-004 — Recipe versioning unconfirmed · **P2**

| | |
| --- | --- |
| **Class (R9)** | **BUSINESS** |
| **Actual** | The drawer offers `Overview`, `Materials (2)` and `Cost History`, plus `Clone` and `Edit`. There is no version list, no compare, no per-version activate/deactivate, and no effective-date. `Cost History` tracks cost movement — not BOM revisions. |
| **Business consequence** | In regulated or quality-controlled manufacturing, knowing *which BOM revision produced a given batch* is a traceability requirement. `Clone` suggests the intended pattern is copy-then-edit, which creates parallel recipes rather than versions of one. Cannot be confirmed without a second recipe. |
| **Root cause (R10)** | **Unknown** — insufficient evidence. Versioning may exist behind Edit; the audit could not open Edit without mutating another tenant's record. |
| **Fix strategy (R16)** | **PRODUCT DECISION** — define the BOM lifecycle |
| **Effort (R11)** | **Unknown** |

---

# SECTION 2 — Root Cause Matrix

**4 findings → 0 new root causes.** Every finding maps to a pattern already established.

| Root cause | Class | Status | Findings | Sev | Effort | Fix strategy | Priority |
| --- | --- | --- | --- | --- | --- | --- | --- |
| **RC-3** Absent surfaces | BUSINESS | **Existing** — now the *dominant* cause | UAT4-001, UAT4-004 | **P0** | XL | PRODUCT DECISION | **1** |
| **RC-1** Tenant scope not applied | SECURITY | **Existing** — confirmed in a **4th** module | UAT4-003 | **P0** | S (within RC-1) | ARCHITECTURAL FIX | 2 |
| **RC-7** No cross-company visibility policy | GOVERNANCE | **Existing** — 2nd module | UAT4-003 | P1 | XS–M | PRODUCT DECISION | 3 |
| **RC-8** Navigation registry not validated against router | **BUG** | **NEW** | UAT4-002 | P1 | XS | IMPLEMENTATION FIX | 4 |

## RC-8 — Navigation registry is not validated against the router *(new)*

| | |
| --- | --- |
| **Rule 12 category** | Not applicable — this is engineering |
| **Root cause (R10)** | **Implementation** |
| **Evidence** | The command palette lists `Manufacturing → /app/manufacturing`; the router has no such route. |
| **Related but distinct** | Campaign 1 found `Settings` and `Configuration OS` resolving to one page, and two sidebar items highlighting at once (UAT1-010). Both are the same class: **navigation metadata maintained separately from routing, with nothing enforcing agreement.** |
| **Findings explained** | 2 across campaigns — UAT4-002, UAT1-010 |
| **Why it deserves its own root cause** | It is not RC-3. RC-3 is *"the capability was never built"*; RC-8 is *"the app claims a capability it does not have."* Fixing RC-3 does not fix RC-8, and RC-8 is **XS** while RC-3 is **XL**. |
| **Priority** | 4 — cheap, and removes a false promise a buyer encounters immediately |

### Consolidation across four campaigns

| | Count |
| --- | --- |
| Findings this campaign | 4 |
| New root causes | **1** (RC-8) |
| Findings explained by existing root causes | **3 of 4 (75%)** |
| Modules where **RC-1** is confirmed | **4 of 4 audited** |
| Modules where **RC-7** is confirmed | 2 (Procurement, Manufacturing) |

**RC-1 has now appeared in every single module audited.** It is not a module defect. It is the
platform's default behaviour.

---

# SECTION 3 — Enterprise Risk Matrix

| Risk | UAT4-001 No module | UAT4-003 BOM leak | UAT4-002 Dead palette link | UAT4-004 Versioning |
| --- | --- | --- | --- | --- |
| **Customer** | **Critical** | **Critical** | Medium | Medium |
| **Operational** | **Critical** | Low | Low | Medium |
| **Financial** | **Critical** | **Critical** | None | Low |
| **Security** | None | **Critical** | None | None |
| **Compliance** | Medium | **Critical** | None | **High** |
| **Data integrity** | Low | Low | None | Medium |
| **Reputation** | High | **Critical** | **High** | Low |
| **Engineering** | **Critical** (XL) | Low | Very low (XS) | Unknown |

### Reading the matrix

**UAT4-003 is the most sensitive disclosure found in four campaigns.** A BOM reveals product
composition, proportions, input costs and yield loss — a competitor learns how to make the product
and what it costs. Supplier lists (Campaign 3) and margins (Campaign 2) are commercially damaging;
a BOM is the recipe itself. Security, Compliance, Financial and Reputation all Critical.

**UAT4-001 carries no security risk at all** — nothing leaks, because nothing runs. Its risk is
Operational and Financial: a manufacturer cannot use the system for its core process. This is an
honest, visible gap; a buyer sees it during evaluation and prices accordingly.

**UAT4-002 has near-zero engineering cost (XS) and disproportionate reputational impact.** It is
the first thing a customer hits when searching for manufacturing, and it converts *"module not
included"* into *"software is broken"*.

**UAT4-004 scores High on Compliance alone.** For food manufacturing — which this tenant plainly
is (`عسل الصال`, honey, with packaging) — batch-to-BOM-revision traceability is a regulatory
expectation, not a nicety.

---

# SECTION 4 — Engineering Backlog Recommendation

### Stage 0 — Decisions (no engineering)

| # | Decision | Owner | Blocks |
| --- | --- | --- | --- |
| **D9** | **Is Manufacturing in scope for v1.0?** If no, the product must not be sold as a manufacturing ERP and the palette entry must go. If yes, it is an XL build. | Product | RC-3, and the module's positioning |
| D10 | Define the **BOM lifecycle** — versioned revisions with effective dates, or clone-per-variant? | Product + Quality | RC-3 (UAT4-004) |
| D7 *(carried)* | Is cross-company visibility intended, and behind which permission? | Product + Business | RC-1, RC-7 |

> **D9 is not a backlog item — it is a positioning decision.** Everything else in this module
> depends on it, and it cannot be answered by engineering.

### Stage 1 — Immediate, regardless of D9

| # | Work | Root cause | Effort |
| --- | --- | --- | --- |
| 1 | **Remove the dead `Manufacturing` palette entry**, or validate the navigation registry against the router at build time | RC-8 | **XS** |
| 2 | Scope `/api/recipes` (or equivalent) by company, server-side | RC-1 | S |

> Item 1 is the **cheapest high-value fix in four campaigns**: minutes of work, and it removes a
> false capability claim from the first screen a buyer searches.

### Stage 2 — After D7

| # | Work | Root cause | Effort |
| --- | --- | --- | --- |
| 3 | Remove or permission-gate the Recipes `All Companies` filter | RC-7 | XS–M |

### Stage 3 — After D9 (only if Manufacturing is in scope)

| # | Work | Root cause | Effort |
| --- | --- | --- | --- |
| 4 | Manufacturing orders · execution · material availability · reservations | RC-3 | **XL** |
| 5 | Waste recording · by-products · cost rollup to finished goods | RC-3 | **XL** |
| 6 | BOM versioning per D10 | RC-3 | L |
| 7 | Manufacturing dashboard · KPIs · reports · notifications | RC-3 | L |

---

## GO / NO-GO — Manufacturing only

# NO-GO

Not because Manufacturing is broken — **because it is not there.**

### The distinction that matters

Campaigns 1–3 found capabilities that existed and misbehaved. This campaign found a capability
that **does not exist while being advertised**. Those require opposite responses: the first needs
engineering, the second needs a **product decision about what ECOS ERP claims to be**.

A customer evaluating this as a manufacturing ERP would define a BOM in a genuinely excellent
screen, search for "production", be offered a Manufacturing module by the product itself, and land
on a 404. That sequence is worse than an empty menu.

### What is genuinely excellent — and should not be lost

The **BOM implementation is the best-designed artefact found across four campaigns**:

- Raw and packaging materials in **separate costed sections** with subtotals
- **Per-line waste %** with effective-quantity rollup (`Qty 1.000 → Waste 2.0% → Eff. Qty 1.020`)
- Cost-source badges (`Manual`) distinguishing entered from derived costs
- A **`Live`** indicator on total cost, plus `Last Costed` and a dedicated `Cost History` tab
- Portfolio KPIs including `Avg Recipe Cost` and a `Waste %` health badge (`Excellent 1.00%`)
- `Clone` for variant creation
- **Zero console errors**

Whoever designed this understood manufacturing costing. The gap is not capability to build it —
it is that the consuming half was never built.

### The honest limit

**No manufacturing transaction was executed — and for the first time in four campaigns, not
because of tenant scope.** There is no execution surface at all. Consequently **every integration
this campaign was asked to verify — Inventory, Procurement, Finance, Notifications, Executive
Dashboard — is UNVERIFIED and unverifiable** until a production order object exists.

### Confidence

**High.** Coverage is low (≈21%) but the conclusion is not tentative: 14 of 19 scoped areas have
no screen, and the one route the product advertises returns 404. Further testing would not change
the verdict — it would only re-confirm absence.

---

**No SQL. No Tinker. No database modification. No UI bypass. No code. No fixes. No records created
or mutated. The 404 was reached through the application's own command palette, not a guessed URL.**
