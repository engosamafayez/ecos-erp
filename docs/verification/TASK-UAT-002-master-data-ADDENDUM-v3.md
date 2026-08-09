# TASK-UAT-002 — Addendum under Enterprise Validation Rules v3

**Date:** 2026-08-08
**Purpose:** Re-examine Campaign 2's findings under Rules 6–11, which did not exist when the
campaign was reported. Includes new **request-level evidence** obtained specifically to satisfy
Rule 7.
**Parent report:** [TASK-UAT-002](TASK-UAT-002-master-data.md)

---

## Why this addendum exists

Rule 7 states: *"Never conclude that filtering exists because the UI changed. Always verify the
actual request."*

**Campaign 2 concluded that master-data queries were not company-scoped by observing the UI.**
That is exactly the reasoning Rule 7 prohibits. The conclusion turned out to be correct, but the
evidence was not sufficient to support it. Below is the evidence that is.

Rules 6, 8, 9 and 10 also change how three of the six findings should be classified — in one case
materially, from *defect* to *governance decision*.

---

## Rule 7 — Request-level evidence

Captured with **`Nile Foods Trading`** active
(`company_id = 019fe003-9b60-71eb-a2d1-81b809c15256`).

### Requests that DO carry the tenant identifier

```
GET /api/brands?per_page=100&company_id=019fe003-9b60-71eb-a2d1-81b809c15256    200
GET /api/warehouses?per_page=100&company_id=019fe003-9b60-71eb-a2d1-81b809c15256 200
GET /api/context/company?company_id=019fe003-9b60-71eb-a2d1-81b809c15256         200
```

### Requests that DO NOT — every master-data list

```
GET /api/products?page=1&per_page=25&sort_by=name&sort_dir=asc
    &product_types=raw_material,packaging_material                200   ← no company_id
GET /api/products/stats?product_types=raw_material,packaging_material 200   ← no company_id
GET /api/categories?scope=material&status=active&per_page=200         200   ← no company_id
GET /api/suppliers?status=active&per_page=200                         200   ← no company_id
GET /api/warehouses?per_page=200                                      200   ← no company_id
```

### The decisive observation

**`/api/warehouses` is called twice on the same page load — once with `company_id`, once
without.** The header-context call is scoped; the filter-dropdown call
(`?per_page=200`) is not. The same endpoint, the same page, the same session — one call carries
the tenant, the other drops it.

That rules out several explanations. The context plumbing is not missing: it exists, it works,
and it is used correctly a few lines away. **No company, branch, warehouse, brand or channel
filter is sent on any master-data list query, and no tenant identifier appears in the query
string.** Whether the backend infers a tenant from the bearer token is unproven — but the observed
response (another company's records) shows that if it does, it is not applied to these endpoints.

**Confidence: high.** This is request-level evidence, not inference from rendering.

---

## Rule 6 — Ownership classification, applied before judgement

Rule 6 requires establishing the intended model *before* calling something a defect. Doing so
splits Campaign 2's single "shared master data" finding into two genuinely different problems.

| Entity | Expected model | Actual model | Verdict |
| --- | --- | --- | --- |
| **Units of Measure** (PCS, KG, BOX, LTR, MTR) | **GLOBAL** — SI/standard units; one shared copy, platform-editable, **read-only to tenants** | Shared copy, but **tenant-editable and tenant-deletable** | ✅ Sharing correct · ❌ **Governance wrong** |
| **Categories** (Electronics, Phones, Smartphones, Groceries, Raw Materials, Packaging Materials) | **Ambiguous.** `Raw Materials`/`Packaging Materials` behave as system taxonomy (GLOBAL); `Electronics`/`Groceries` are merchandising taxonomy a tenant would own (COMPANY SCOPED) | One shared copy, tenant-editable | ⚠️ **Undecided model — the platform has not chosen** |
| **Finished Products** | **COMPANY SCOPED** | Visible to all companies with cost and margin | ❌ **Isolation failure** |
| **Raw / Packaging Materials** | **COMPANY SCOPED** | Visible to all companies with cost | ❌ **Isolation failure** |
| **Suppliers** | **COMPANY SCOPED** | Queried without company filter | ❌ **Isolation failure** (request-level evidence; UI not re-checked) |
| **Brands** | **COMPANY SCOPED** | Correctly scoped — request carries `company_id` | ✅ **Correct** |
| **Warehouses** | **COMPANY SCOPED** | Header call scoped; filter-dropdown call unscoped | ⚠️ **Inconsistent** |

**What this changes.** Campaign 2 reported "Units of Measure leak across companies" as a P0
isolation defect. **Under Rule 6 that is wrong.** Units *should* be shared — a kilogram is a
kilogram. The defect is not the sharing; it is that a GLOBAL entity exposes **edit and delete to
tenants**. Correct classification: **GOVERNANCE**, not BUG. Correcting this materially lowers the
severity and completely changes the fix.

**Categories is the harder case, and I will not pretend otherwise.** The list mixes system
taxonomy with merchandising taxonomy under one model. Which entity type it is meant to be is a
**product decision that has not been made**, and no amount of testing can establish it. Root
cause: **Governance Decision**, not Implementation.

---

## Rules 8–11 — Reclassified findings

| ID | Finding | Class (R9) | Root cause (R10) | Sev | Fix (R11) |
| --- | --- | --- | --- | --- | --- |
| **UAT2-002** | Product cost / markup / margin visible to unrelated companies. Request carries **no** `company_id` | **SECURITY** | **Implementation** — the app sends `company_id` on `/api/brands` and omits it here | **P0** | **S** — add the filter to the products query + server-side enforcement |
| **UAT2-003** | Material costs and `Allow Negative` toggles exposed cross-company | **SECURITY** | **Implementation** — same query layer | **P0** | **S** |
| **UAT2-001a** | **Units** GLOBAL but tenant-editable/deletable | **GOVERNANCE** *(was BUG)* | **Governance Decision** — no ownership policy on global reference data | **P1** *(was P0)* | **XS** — make read-only for non-platform roles |
| **UAT2-001b** | **Categories** ownership model undecided; mixes system and merchandising taxonomy | **ARCHITECTURE** | **Governance Decision** — model never chosen | **P1** | **L** — decide the model, then split/migrate existing rows |
| **UAT2-004** | Price Lists, Storage Locations, Customer Groups, Product Types, Inventory Classes, Attributes, Tags, Variants have no screen | **BUSINESS** | **Missing Feature** | **P1** | **XL** — eight entities, each with model + UI + relationships |
| **UAT2-005** | No ownership column or company filter on any master-data grid | **UX** | **Implementation** | **P2** | **S** |
| **UAT2-006** | Categories lacks the standard toolbar (Refresh/Export/Columns/pagination) | **UX** | **Implementation** | **P3** | **XS** |
| **UAT2-007** *(new)* | `/api/warehouses` called twice on one page — once scoped, once not | **DATA** | **Implementation** | **P2** | **XS** — add `company_id` to the dropdown query |

### Finding count by classification

| Classification | Count |
| --- | --- |
| SECURITY | 2 |
| UX | 2 |
| GOVERNANCE | 1 |
| ARCHITECTURE | 1 |
| BUSINESS | 1 |
| DATA | 1 |
| BUG · PERFORMANCE · LOCALIZATION · REPORTING · INTEGRATION | 0 each |
| **Total** | **8** |

**Not one finding is classified BUG.** Under Rule 9's own instruction that is the point: the two
severest are SECURITY, and the two hardest to resolve are GOVERNANCE and ARCHITECTURE — neither
fixable by an engineer without a product decision first.

### Bug count by severity

| Severity | Count | IDs |
| --- | --- | --- |
| **P0** | 2 | UAT2-002, UAT2-003 |
| **P1** | 3 | UAT2-001a, UAT2-001b, UAT2-004 |
| **P2** | 2 | UAT2-005, UAT2-007 |
| **P3** | 1 | UAT2-006 |

### Fixability distribution

| Effort | Count | Note |
| --- | --- | --- |
| **XS** | 2 | Units read-only; warehouse dropdown filter |
| **S** | 3 | **Both P0 security leaks are S** — the scoping fix is small |
| **L** | 1 | Categories model decision + migration |
| **XL** | 1 | Eight missing master-data entities |

**The most important line in this addendum:** the two P0 data-disclosure findings are **S — under
two hours each**. The evidence for that is the app already sending `company_id` correctly on
`/api/brands` a few lines away. The expensive items are the ones requiring a decision, not code.

---

## What I got wrong in Campaign 2

Recorded because an audit that hides its own errors is not worth reading:

1. **I concluded scoping from the UI**, which Rule 7 forbids. The conclusion held, but the
   evidence did not support it until now.
2. **I classified Units of Measure as a P0 isolation leak.** Under Rule 6 that is incorrect —
   units *should* be shared. The real defect is tenant edit/delete on global data: GOVERNANCE,
   P1, XS to fix. I reported a symptom as an architecture failure.
3. **I treated Categories and Units as one finding.** They have different expected ownership
   models and different root causes, and merging them obscured both.

None of this changes the campaign verdict.

---

## Verdict — unchanged

# NO-GO for Master Data

Two P0 **SECURITY** findings stand, now on request-level evidence: product cost, markup, margin
and material costs are served to a company that owns none of them, and the query that serves them
carries no tenant identifier at all.

The verdict is unchanged; the **shape** of the work is clearer. Roughly four hours of scoping work
closes both P0s. What remains after that is not engineering — it is two product decisions
(the Categories ownership model, and whether the eight absent entities are in scope for v1.0).

**Coverage remains ≈33%. Confidence: high for the six audited screens and the request evidence
above; nil for the twelve untested entities.**

---

**No SQL. No Tinker. No database modification. No UI bypass. No code. No fixes. No records created
or mutated. Request evidence captured by reading the browser's own network log.**
