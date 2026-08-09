# TASK-UAT-002 — Enterprise UAT Campaign 2
## Master Data Platform

**Date:** 2026-08-08
**Role:** Enterprise customer evaluating whether ECOS ERP can build and maintain operational master data.
**Method:** UI only. No SQL, no Tinker, no DB modification, no code, no fixes.
**Rules applied:** Enterprise Validation Rules v2 — Maximum Coverage · Architecture Validation ·
Fresh Data Validation · Enterprise Consistency · Business Reality.

**Question:** *Can a company build and maintain complete, correctly-isolated master data?*

# Answer: **No — master data is not isolated between companies.**

---

## Coverage

**Scope: 18 entity areas. Audited: 6. Coverage ≈ 33%. Confidence: high for what was audited, nil elsewhere.**

### Visited screens (6)

| # | Screen | Route | Result |
| --- | --- | --- | --- |
| 1 | Categories | `/app/inventory/master-data/categories` | ❌ **Leaks across companies** |
| 2 | Units of Measure | `/app/inventory/master-data/units` | ❌ **Leaks across companies** |
| 3 | Raw & Packaging Materials | `/app/raw-materials` | ❌ **Leaks across companies** |
| 4 | Finished Products | `/app/products` | ❌ **Leaks across companies** |
| 5 | Brands | `/app/brands` | ⚠️ Duplicate codes (Campaign 1) |
| 6 | Inventory Dashboard (nav discovery) | `/app/inventory/dashboard` | ✅ Pass |

### Blocked screens (0)

No screen in this campaign was blocked. **Every finding below was reachable** — the failure is
data isolation, not availability.

### Untested screens / entities (12)

| Entity | Reason not tested |
| --- | --- |
| Recipes (BOM) | Reachable at `/app/inventory/recipes`; session budget exhausted after the isolation findings took priority |
| Price Review | Reachable at `/app/inventory/cost-management/price-review`; not opened |
| Stock Ledger | Reachable at `/app/stock-ledger`; not opened |
| Suppliers | Audited in Campaign 1 (`/app/suppliers`, 1 record) — **not re-tested for company isolation** |
| Customers | Audited in Campaign 1 (`/app/crm/customers`) — **not re-tested for company isolation** |
| Customer Groups | **No dedicated screen found** in CRM or Administration navigation |
| Product Types | **No dedicated screen found.** Type appears as a field (`Raw Material` / `Packaging` badges) |
| Inventory Classes | **No dedicated screen found.** Classes surface only as Category names (`Raw Materials`, `Packaging Materials`) |
| Price Lists | **No dedicated screen found** in any audited navigation |
| Storage Locations | **No dedicated screen found.** Warehouses exist; sub-locations not observed |
| Product Attributes · Tags · Images · Variants | **No dedicated screens found.** Images appear as a thumbnail column only |

**Eight of eighteen scoped entities have no discoverable screen at all.** That is itself a
finding (UAT2-004) and not merely missing coverage.

### Skipped workflows

| Workflow | Reason |
| --- | --- |
| Create / edit / archive / delete master data | **Deliberately not attempted.** Categories and Units are shared across companies with live edit and delete controls (UAT2-001); creating or mutating a record from the wrong tenant risked corrupting another company's data. As a customer I would not take that risk on a demo tenant. |
| Import | No Import control on Categories, Units or Raw Materials. Products offers **Import** — not exercised |
| Bulk actions | Row checkboxes present on Raw Materials and Products; bulk toolbar not exercised |
| Duplicate prevention | Not exercised — would have required creating records (see above) |
| Localization (AR/RTL) of master data | Not re-tested this campaign |

---

## Findings

### UAT2-001 — Master data is shared across all companies · **P0**

| | |
| --- | --- |
| **Module / Screen** | Inventory → Categories; Inventory → Units of Measure |
| **Workflow** | Company context switching (Rule 2) |
| **Steps** | 1. As `ECOS Holding 20`, open **Categories** — 6 records; open **Units of Measure** — 5 records. 2. Header → switch active company to **`Nile Foods Trading`** (created today, 0 brands, 0 warehouses). 3. Re-open both screens. |
| **Expected** | A company created today owns no taxonomy. Either an empty state, or records explicitly marked as shared/global with edit disabled. |
| **Actual** | **Byte-identical lists.** Categories shows the same 6 (`Electronics`, `Phones`, `Smartphones`, `Groceries`, `Raw Materials`, `Packaging Materials`), all dated `Jul 11, 2026` — *before Nile Foods Trading existed*. Units shows the same 5 (`PCS`, `KG`, `BOX`, `LTR`, `MTR`). Neither screen has a Company column, a company filter, or any indication the data is shared. **Inline edit (✏) and delete (🗑) controls are live on every row.** |
| **Business impact** | **Severe.** Two independent problems. (a) *Visibility* — a new tenant inherits another company's taxonomy unasked, which is confusing but survivable. (b) **Mutability** — the same screens offer edit and delete. One customer can rename or delete a category another customer's products depend on. In a hosted multi-tenant deployment this is a cross-tenant data-integrity failure; in a single-group deployment it is still uncontrolled shared state with no ownership signal. |
| **Root cause hypothesis** | Categories and Units have no company association, or the list queries are not company-scoped. The absence of a Company column suggests the former — these entities may have been modelled as platform-global. |
| **Regression risk of fixing** | **Medium–high.** If these are genuinely global by design, scoping them per-company requires deciding what happens to existing shared records and to products already referencing them. This is a data-model decision, not a UI fix. |
| **Console / Network** | No console errors. Screens render identically under both company contexts. |
| **Screenshot** | Categories and Units under `ECOS Holding 20` and under `Nile Foods Trading` — visually identical, header company differs. |

### UAT2-002 — Products, costs and margins leak across companies · **P0**

| | |
| --- | --- |
| **Module / Screen** | Commerce → Products |
| **Steps** | 1. Set active company to **`Nile Foods Trading`**. 2. Open **Products**. |
| **Expected** | Empty state — Nile owns no products, no brands, no channels. |
| **Actual** | One product displayed in full: `عسل الصال كيلو` (`FG-000001`), Category `Groceries`, **Brand `Aseel` (`BRD-000001`)**, Channel `AseelMob`, **Product Cost 3,155.00**, Regular Price 7,044.00, Sale Price 6,600.00, **Markup 123%**, **Gross Profit 55.2%**, **Final Margin 52.2%**. KPI band reports `Total Products 1`, `Published 1`, `Mfg Ready 1`. |
| **Business impact** | **Critical — commercially sensitive.** Cost price, markup and margin are among the most confidential figures a business holds. They are visible from a company that owns none of it. Worse, brand `Aseel` belongs to **`AxieFood`** — a *third* company — so this is not a two-party leak but data from an unrelated tenant surfacing in a third tenant's workspace. Under any hosted or group-with-separate-management arrangement this is a disclosure incident. |
| **Root cause hypothesis** | The products list query is not scoped by active company; the same defect class as UAT1-001/002 in Campaign 1. |
| **Regression risk of fixing** | Low — scoping a list query. The risk is discovering how much existing data has no company association. |
| **Console / Network** | No console errors. |

### UAT2-003 — Raw and packaging materials leak, including cost · **P0**

| | |
| --- | --- |
| **Module / Screen** | Inventory → Raw Materials ("All Materials") |
| **Steps** | With **`Nile Foods Trading`** active, open **Raw Materials**. |
| **Expected** | Empty state. |
| **Actual** | Two materials shown: `بطرمان كيلو` (`RM-000002`, Packaging, **Current Cost EGP 95.00**) and `عسل الصال` (`RM-000001`, Raw Material, **Current Cost EGP 3,000.00**), both `In Stock`, with `Allow Negative` toggles **live and switchable**. Filters offer All Materials / All Categories / **All Suppliers** / **All Warehouses** — **no company filter anywhere**. |
| **Business impact** | Supplier cost prices exposed to an unrelated tenant. The `Allow Negative` toggle is a live control over another company's inventory policy. The warehouse and supplier filters compound Campaign 1's UAT1-001 — cross-company selection is offered as a normal feature. |
| **Regression risk of fixing** | Low–medium. |

### UAT2-004 — Eight of eighteen master-data entities have no screen · **P1**

| | |
| --- | --- |
| **Steps** | Enumerate every reachable navigation link across Inventory, Commerce, CRM, Purchasing and Administration; search for each scoped entity. |
| **Expected** | Discoverable management screens for the master data an ERP is expected to maintain. |
| **Actual** | **No screen found** for: Customer Groups · Product Types · Inventory Classes · Price Lists · Storage Locations · Product Attributes · Tags · Product Variants. Some exist as *fields* (Product Type as a badge; Inventory Class implied by category name; images as a thumbnail column) but none is manageable as an entity. |
| **Business impact** | **Price Lists** and **Storage Locations** are the most consequential. Without price lists a company cannot maintain differentiated pricing per customer, channel or contract — the single most common enterprise pricing requirement. Without storage locations, warehouses cannot be subdivided into bins/zones, which limits any operation beyond a single stockroom. Customer Groups blocks segmentation-based pricing and reporting. |
| **Note** | I searched navigation only. These may exist behind routes not linked in any menu — but from a customer's standpoint, **undiscoverable is equivalent to absent**. |

### UAT2-005 — No company/ownership column on any master-data screen · **P2**

Categories, Units, Raw Materials and Products display no Company, Branch, Warehouse or Brand
ownership column, and offer no company filter. Combined with UAT2-001/002/003, the customer has
**no way to determine which company owns any master-data record** — the ownership question Rule 2
requires cannot be answered from the UI at all. Products does show a Brand column, which is the
only ownership signal present anywhere.

### UAT2-006 — Categories master-data screen lacks the standard toolbar · **P3**

Categories offers search and a status filter, but **no Refresh, no Export, no Columns chooser and
no pagination footer** — all present on Units, Raw Materials, Products and every Administration
grid. Inconsistent with the platform's own pattern (Rule 4).

---

## What worked well

| Observation | Evidence |
| --- | --- |
| Categories model is genuinely good | Hierarchical `L1/L2/L3` with Parent, `Product`/`Material` typing, a `Used By` column, and tabbed `All 6 / Products 4 / Materials 2` with live counts |
| Raw Materials workspace is rich | Five KPI cards, six filters, per-row cost, on-hand/reserved/available split, `Allow Negative` policy toggle, image thumbnails |
| Products workspace is commercial-grade | Twelve KPI cards including `Mfg Ready`, `Missing Recipe`, `Price Review Required`; markup, gross profit and final margin computed inline; quick-filter chips |
| Arabic master data renders correctly | `عسل الصال كيلو`, `بطرمان كيلو` display correctly in LTR tables |
| Products offers Import/Export | The only master-data screen with an Import path |
| Console cleanliness | **Zero console errors across all six screens, under both company contexts** |

---

## Scores

| Score | Value | Basis |
| --- | --- | --- |
| **Master Data Readiness** | **2 / 10** | The screens that exist are well built, but master data is not isolated by company and eight of eighteen scoped entities have no screen. Data a company cannot own or scope is not master data — it is shared state. |
| **UX** | **7 / 10** | The highest UX mark of any campaign so far. Categories' hierarchy, the Raw Materials filter bar and the Products KPI band are genuinely strong. Held down by a missing Categories toolbar and the total absence of ownership signals. |
| **Business Readiness** | **1 / 10** | No customer could operate on this: confidential cost and margin data is visible across tenants, shared taxonomy is editable by anyone, and price lists — a baseline enterprise requirement — do not exist. |

Scores cover **only the 6 audited screens**. The 12 untested entities are excluded, not credited.

---

## Blocking issues

1. **UAT2-002 (P0)** — product cost, markup and margin visible from an unrelated company.
2. **UAT2-003 (P0)** — material costs and inventory policy toggles visible/mutable across companies.
3. **UAT2-001 (P0)** — Categories and Units shared across all companies with live edit/delete.
4. **UAT2-004 (P1)** — Price Lists and Storage Locations do not exist.

## Non-blocking issues

5. UAT2-005 (P2) — no ownership column or company filter on any master-data screen.
6. UAT2-006 (P3) — Categories missing the standard toolbar.

## Recommendations

1. **Decide the ownership model for Categories and Units** — genuinely global, or per-company. If
   global, disable edit/delete for non-owners and label them as platform data. This is a
   data-model decision and should precede the UI fix.
2. **Scope Products and Raw Materials queries to the active company.** Treat the current state as
   a disclosure issue, not a display bug.
3. **Add an ownership column and company filter** to every master-data grid.
4. **Build Price Lists and Storage Locations**, or confirm they are out of scope for v1.0 — a
   customer will ask about both during evaluation.
5. Re-run this campaign afterwards; **10 of 18 entities remain unaudited.**

---

## GO / NO-GO — Master Data only

# NO-GO

A company cannot build or maintain master data it actually owns.

Three P0 leaks were reproduced by the simplest possible test — switching the company selector and
reloading. A tenant created minutes earlier, owning no brands and no warehouses, was shown another
company's product catalogue **with cost price, markup and gross margin**, another company's
material costs, and a shared taxonomy it can edit or delete. One of those records belongs to a
*third* company, so this is not a paired-tenant quirk.

**The screens themselves are the best-built I have audited** — the category hierarchy, the
materials filter bar and the products KPI band are genuinely commercial quality, and the console
was clean throughout. That is what makes this finding worth acting on rather than despairing over:
the product surface is sound and the fault is concentrated in query scoping and an undecided
ownership model.

**Coverage is only 33% and confidence beyond that is nil.** Ten entities were never audited and
eight of those have no screen to audit. This verdict rests on what was seen; it is not a ceiling
on what may be found.

---

**No SQL. No Tinker. No database modification. No UI bypass. No code. No fixes. No records were
created or mutated during this campaign — deliberately, because the shared-taxonomy defect made
writing from the wrong tenant unsafe.**
