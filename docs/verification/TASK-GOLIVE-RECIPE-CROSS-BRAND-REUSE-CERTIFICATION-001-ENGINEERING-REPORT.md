# TASK-GOLIVE-RECIPE-CROSS-BRAND-REUSE-CERTIFICATION-001 — Engineering Report

**Date:** 2026-08-09 · **Commit:** `6149875b` · **Production code changed: NONE**

---

# 1 — EXECUTIVE SUMMARY

# ✅ PASS — Raw Material reuse is NOT Brand-restricted. Brand-level scoping is DISPROVEN.

```
OK (3 tests, 18 assertions)   —   6m42s, database-backed (PostgreSQL, RefreshDatabase)

CROSS_BRAND:
  company         = 019fe77a-2817-73a2-916e-b06f1ef73311
  brandA          = 019fe77a-282d-722e-be54-ba469da0df80
  brandB          = 019fe77a-2835-73d3-995f-4f79b85eff95
  rawX_brand      = 019fe77a-282d-722e-be54-ba469da0df80   <-- Brand A
  shared_component= yes
  A = instock   B = instock
  reverse:  B = instock   A = instock
```

**One** Raw Material product, owned by **Brand A**, served the recipe of **Brand B** and returned
`instock`. `rawX_brand === brandA` and `brandA !== brandB` are both asserted, so the cross-brand
condition is real and not an artifact of two brands collapsing to one.

**Consequence for F4:** the predicate `$product->brand?->company_id` resolves to a **Company**, and
Company is the level at which reuse actually occurs. Scoping component availability by company will
**not** break legitimate multi-brand catalogues.

---

# 2 — WHAT WAS PROVEN, AND WHAT WAS NOT

This distinction matters and must not be blurred.

| Claim | Status |
| --- | --- |
| A Raw Material owned by Brand A can serve a recipe owned by Brand B in the same Company | ✅ **PASS** — runtime-proven |
| Brand is **not** an isolation boundary for recipe components | ✅ **PASS** — runtime-proven |
| F4's company predicate will not cause false negatives for multi-brand tenants | ✅ **PASS** — follows directly |
| Reuse **stops** at the Company boundary | ❌ **NOT PROVEN — and currently FALSE** (see §5) |

At `6149875b` there is **no ownership predicate of any kind** in
`ManufacturingAvailabilityService:61-68`. Reuse today is therefore **unbounded**, not company-bounded.
This test proves reuse is *at least* company-wide; it cannot, on its own, prove reuse is *exactly*
company-wide, because the upper bound does not yet exist in the code.

**The test's full value is realised as a regression guard after F4 lands:** if the F4 predicate is
ever written as brand-level rather than company-level, all three tests fail immediately.

---

# 3 — FIXTURE (Parts 1–5)

```
Company A
├── Brand A ──> FG A ──> Recipe A ──┐
│           └─> Raw Material X <────┤   ONE product, owned by Brand A
└── Brand B ──> FG B ──> Recipe B ──┘
Warehouse (Company A) : InventoryItem(X) = 100 on_hand, 0 reserved
```

Exactly **one** raw-material Product row is created. Both recipe lines carry the **same**
`raw_material_id`, asserted by `assertSame($componentA, $componentB)` — not two look-alike products.

---

# 4 — ASSERTION RESULTS — all executed, none skipped

| # | Assertion | Result |
| --- | --- | --- |
| 1 | `brandA.company_id` = Company A | ✅ PASS |
| 2 | `brandB.company_id` = Company A | ✅ PASS |
| 3 | `fgA.brand.company_id` = Company A | ✅ PASS |
| 4 | `fgB.brand.company_id` = Company A | ✅ PASS |
| 5 | `rawX.brand.company_id` = Company A | ✅ PASS |
| 6 | `warehouse.company_id` = Company A | ✅ PASS |
| 7 | `brandA.id !== brandB.id` | ✅ PASS |
| 8 | `fgB.brand_id !== rawX.brand_id` — cross-brand condition is genuine | ✅ PASS |
| 9 | Both recipes reference the **same** `raw_material_id` | ✅ PASS |
| 10 | Recipe A = `instock` | ✅ PASS |
| 11 | **Recipe B = `instock` despite Raw X belonging to another Brand** | ✅ **PASS — the certification** |
| 12 | Reverse evaluation order (B then A) — both `instock` | ✅ PASS — no order-dependent state |
| 13 | **Third brand** (Brand C) reuses the same Raw X → `instock` | ✅ PASS |
| 14 | Cross-brand reuse with `allow_negative_stock = true`, **zero inventory** → both `instock` | ✅ PASS |

Test 3 (assertion 14) is a meaningful independent path: it proves the cross-brand result is not an
artifact of the inventory-row company assignment, since **no inventory row exists at all** —
availability comes solely from the component's `allow_negative_stock` flag.

---

# 5 — CROSS-COMPANY BEHAVIOUR (the F4 leak) — unchanged

Not re-tested here; already runtime-proven in
`TASK-GOLIVE-RECIPE-OWNERSHIP-RUNTIME-FIXTURE-001` (`TENANT: recipe=instock (companyA=0, companyB=100)`).

Company A's recipe still evaluates `instock` from **Company B's** stock. That is the defect F4 fixes.
Taken together:

> Reuse **must** cross Brands (proven here). Reuse **must not** cross Companies (currently violated).
> **Company is exactly the right predicate level.**

---

# 6 — ARCHITECTURAL CORROBORATION (static, secondary)

Runtime is the primary evidence; these are consistent with it.

| Source | Finding |
| --- | --- |
| `StoreBomRequest:34-40` / `UpdateBomRequest:37` | `raw_material_id` validated as `exists:products,id` + not-self. **No brand rule.** |
| `RecipeLine:20` docblock | Only stated constraint: component ≠ recipe output. Brand not mentioned. |
| `BomResource:41-42` | Derives `company_id` via `$m->channel->brand?->company_id` — company is the derived unit, brand the intermediary. |
| `EloquentBomRepository:245` | Already derives a BOM's company by the same Product → Brand → Company path. |
| ADR-013 | `Product → Brand → Company`. **No contradiction found.** |

---

# 7 — ⚠️ FINDING (out of scope, not fixed): write-path has no tenancy rule either

`StoreBomRequest` / `UpdateBomRequest` validate `raw_material_id` only as `exists:products,id`.
There is **no company constraint**, so the HTTP write path would currently accept a component
belonging to **another Company**.

| | |
| --- | --- |
| Severity | To be assessed — not assessed in this task |
| Runtime-tested here? | ❌ **NO** — the fixture wrote components via the model, bypassing FormRequest validation |
| Status | ⚠️ **UNVERIFIED** — observed statically, **not** proven exploitable at runtime |

F4 as scoped fixes the **read** path (availability evaluation). It does not add a write-path rule.
Recommend tracking separately; **do not** assume F4 closes it.

---

# 8 — STOP CONDITIONS

| Condition | Fired? |
| --- | --- |
| 2 — cross-brand reuse turns out to be invalid | ❌ No — it is valid and works |
| 3 — reuse proves to be Brand-level | ❌ No — **disproven** |
| 6 — F4 predicate would break legitimate data | ❌ No |
| 12 — ambiguity forcing a production change | ❌ No |

**No STOP condition fired. F4 remains cleared to proceed.**

---

# 9 — FINAL STATE

| | |
| --- | --- |
| HEAD | `6149875b` |
| Tracked modifications | **NONE** (`git status --porcelain` shows untracked files only) |
| Production logic | **UNTOUCHED** — `ManufacturingAvailabilityService`, `EloquentProductRepository`, `ReserveOrderInventoryAction`, factories, schema |
| Persistent change | One untracked test file: `backend/tests/Feature/Manufacturing/RecipeCrossBrandReuseTest.php` |
| Recommendation | **Keep the test** — it is the regression that catches a brand-level collapse of F4 |

**Note on the test file:** a private helper was initially named `status()`, which collides with
PHPUnit's `final TestCase::status()` and fatal-errored the first run. Renamed to `recipeStatus()`.
The results above are from the successful run after that rename.

---

# 10 — RECOMMENDATION

# ✅ PROCEED to TASK-GOLIVE-RECIPE-GATE-TENANT-REPAIR-001 (F4 + Option B)

Implement the company predicate at **Company** level — `$product->brand?->company_id`, failing closed
on null. **Do not** scope by `brand_id`; assertion 11 above is the proof that doing so would break
legitimate multi-brand tenants.

After implementation, this suite must **still return 3/3 green** — that is the specific signal that
F4 landed at the correct level.

---

**Certification task only. No production business logic, schema, factory, seeder, baseline or
deployment was modified. No commit made.**
