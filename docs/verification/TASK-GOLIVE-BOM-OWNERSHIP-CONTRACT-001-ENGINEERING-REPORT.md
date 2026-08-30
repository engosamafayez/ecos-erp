# TASK-GOLIVE-BOM-OWNERSHIP-CONTRACT-001 — Engineering Report
## BOM / Recipe Company Ownership Contract

**Date:** 2026-08-09 · **Base:** `6149875b` · **Investigation only — no code, test, schema or commit change. Tree clean.**

---

# 1 — EXECUTIVE SUMMARY

**The ownership contract is OPTION A — but it is IMPLIED, not ENFORCED.**

| | |
| --- | --- |
| **Recipe/BOM company** | **The finished product's brand company.** Proven — the platform already computes it this way |
| **Component-level enforcement** | ❌ **NONE.** No validation anywhere prevents a BOM line referencing another company's raw material |
| **F4 implication** | ✅ **Can proceed** using `$product->brand?->company_id` — the same source the BOM module already uses. **No ownership-architecture change required** |

---

# 2 — EVIDENCE

## 2.1 Recipe has no company of its own

`Recipe::$fillable` (`:41-51`): `bom_number`, `product_id`, `version`, `bom_version_number`,
`is_active`, `notes`, `manufacturing_cost`, `other_costs`, `execution_instructions`,
`yield_quantity`.

**No `company_id`.** A BOM's company is derived, never stored.

## 2.2 The platform already derives it — from the finished product's brand

**`EloquentBomRepository:245`** — the authoritative line:

```php
$companyId = $product->brand?->company_id;
```

Used to stamp company on BOM cost events (`:259`, `:268`).

**`EloquentBomRepository:80-84`** — BOM listing scopes by company the same way:

```php
$companyId = trim((string) ($filters['company_id'] ?? ''));
if ($companyId !== '') {
    … whereHas('product.brand', fn ($q) => $q->where('company_id', $companyId))
}
```

**Two independent sites in the BOM module already treat *the finished product's brand company* as the
BOM's company.** This is the existing contract, not a new interpretation.

## 2.3 No component-level validation exists

A full grep of `Modules/Manufacturing/BillsOfMaterials/` for `company` returns **only**:
eager-load paths, the list filter, the cost-event company (`:245`), and two `BomResource` display
fields.

**Zero guards.** Nothing validates that a BOM line's raw material shares the finished product's
company — not in the repository, not in a FormRequest, not in `RecipeResolver`.

---

# 3 — OWNERSHIP MATRIX (Part 13)

| Entity | Ownership source | Company | Enforced? |
| --- | --- | --- | --- |
| **Finished Product** | `Product → Brand → Company` (ADR-013; no direct column) | Brand's company | Structural |
| **BOM / Recipe** | **Inherited from `product_id`** — no own column | FG's brand company | **Implied only** |
| **BOM Line** | `raw_material_id → Product → Brand → Company` | The component's *own* brand company | ❌ **NOT ENFORCED** |
| **Raw Material** | Same chain as any product | Its own brand company | Structural |
| **InventoryItem** | **Direct `company_id` column** | Independent of product ownership | Column present; agreement with product ownership **UNVERIFIED** |
| **Warehouse** | Direct `company_id` | Own | ✅ Enforced (RC-6, certified) |

## 3.1 Cross-company relationship matrix

| Relationship | Allowed? | Proven? |
| --- | --- | --- |
| A Product → A BOM | ✅ Yes | ✅ Yes — the normal path |
| A Product → A Raw Material | ✅ Yes | ✅ Yes |
| **A Product → B Raw Material** | ⚠️ **Structurally possible — nothing rejects it** | ✅ **Proven by absence of any guard** |
| A Product → A Inventory | ✅ Yes | ✅ Yes |
| **A Product → B Inventory** | ⚠️ Possible — `InventoryItem.company_id` is independent | ⚠️ **UNVERIFIED whether legitimate** |

---

# 4 — THE SIX QUESTIONS ANSWERED

| # | Question | Answer |
| --- | --- | --- |
| **Q1** | What company owns a Recipe? | **The finished product's brand company** — `EloquentBomRepository:245`, `:80-84`. The Recipe stores none |
| **Q2** | What company owns each BOM component? | **Its own brand's company** — and it is **never checked against the finished product's** |
| **Q3** | Can a FG legitimately use another company's raw material? | **Not enforced either way.** No feature enables it, no guard forbids it. **There is no evidence of an intended cross-company manufacturing capability** |
| **Q4** | What company must scope InventoryItem availability for a Recipe? | **The finished product's brand company** — consistent with how the BOM module already derives BOM company |
| **Q5** | Is scoping by the FG's company architecturally safe? | ✅ **Yes** — it uses the identical expression the platform already uses at `:245`. It introduces no new ownership concept |
| **Q6** | Can F4 be implemented without changing ownership architecture? | ✅ **YES** |

---

# 5 — CLASSIFICATION (Part 8)

# **OPTION A — implied, unenforced**

Not Option B: **no cross-company manufacturing feature exists**. The BOM module has no
component-ownership concept at all — it simply never asks the question.

Not Option C: the company source **is** established, twice, in the BOM module itself.

**STOP condition 8 does NOT apply** — the investigation found no undocumented cross-company
manufacturing feature. It found an **absence of validation**, which is a different thing.

---

# 6 — F4 IMPLICATION

**F4 may proceed.** Scope the component-availability aggregation by
`$product->brand?->company_id` in both `ManufacturingAvailabilityService:61-68` and
`EloquentProductRepository:77-83`.

**This is not a new ownership rule** — it makes recipe availability agree with the company the BOM
module already attributes the recipe to.

## 6.1 The residual risk, stated precisely

Because component ownership is **unenforced**, legacy or seeded data *may* contain a BOM whose
components belong to another company. After F4 such a recipe would evaluate `outofstock` — and under
approved **Option B** that becomes a hard reservation gate, so those orders would park in
`AwaitingStock`.

**This is correct behaviour under Option A**, but it is a **visible behavioural change** for any such
data.

**Recommended precaution — read-only, no fix:** before F4 ships, run a single query to size the
population:

```sql
SELECT COUNT(*) FROM bill_of_material_lines boml
JOIN bills_of_materials bom ON bom.id = boml.bom_id
JOIN products fg   ON fg.id = bom.product_id
JOIN products comp ON comp.id = boml.raw_material_id
JOIN brands bfg    ON bfg.id = fg.brand_id
JOIN brands bcomp  ON bcomp.id = comp.brand_id
WHERE bfg.company_id <> bcomp.company_id;
```

**Zero rows ⇒ F4 carries no behavioural risk.** Non-zero ⇒ those BOMs need an owner decision before
Option B's gate goes live.

**Not executed here** — this task's scope forbids it.

---

# 7 — UNVERIFIED / GAPS

| # | Item | Status |
| --- | --- | --- |
| **U1** | Whether `Product.brand.company_id` and `InventoryItem.company_id` are guaranteed to agree | **UNVERIFIED — data-integrity gap.** Not repaired |
| **U2** | Whether cross-company BOM lines exist in real data | **UNVERIFIED** — §6.1 query not run |
| **U3** | Recipe creation path validation (Part 5) | **NOT ENFORCED** — confirmed by absence |
| **U4** | ADR review (Part 12) | **NOT PERFORMED** — no ADR document was read. Ownership answered from implementation only |

**U4 matters:** if an ADR explicitly permits cross-company BOMs, it would contradict this report's
Option A classification. **The implementation shows no such capability, but the ADR text was not
checked.**

---

# 8 — RECOMMENDATION FOR THE NEXT TASK

**Proceed with `TASK-GOLIVE-RECIPE-GATE-TENANT-REPAIR-001`**, with two additions:

1. **Run the §6.1 query first** as the task's Part 1 baseline. It is read-only and decides whether F4
   is risk-free or needs an owner decision.
2. **Confirm U4** — check the manufacturing ADRs for any cross-company statement before treating
   Option A as settled.

If §6.1 returns zero rows and no ADR contradicts it, **F4 and Option B can be implemented exactly as
specified**, using `$product->brand?->company_id` in both aggregation sites.

---

**INVESTIGATION ONLY — honoured. No F4 or Option B implementation. `ReserveOrderInventoryAction`,
`ManufacturingAvailabilityService`, `EloquentProductRepository`, RC-6 and D-8 untouched. No schema
change, no test change, no commit. Working tree clean at `6149875b`.**
