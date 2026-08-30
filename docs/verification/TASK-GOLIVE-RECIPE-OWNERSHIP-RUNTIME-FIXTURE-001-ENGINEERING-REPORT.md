# TASK-GOLIVE-RECIPE-OWNERSHIP-RUNTIME-FIXTURE-001 — Engineering Report

**Date:** 2026-08-09 · **Commit:** `6149875b` · **No production code changed.**

---

# 1 — EXECUTIVE SUMMARY

# ✅ PROVEN — the fixture was the cause. F4's company boundary is a safe candidate.

```
OK (8 tests, 18 assertions)   —   7m09s, database-backed

OWNERSHIP: fg=019fe757-…0881 | rmA=019fe757-…0881 | rmB=019fe757-…0881
           warehouse=019fe757-…0881 | expected=019fe757-…0881
A: recipe=instock    | order=ready_for_dispatch | reserved_qty=1.00
B: recipe=outofstock | order=ready_for_dispatch | reserved_qty=1.00
C: recipe=instock    | order=ready_for_dispatch | reserved_qty=1.00
E: recipe=outofstock | order=ready_for_dispatch | reserved_qty=1.00
TENANT: recipe=instock (companyA=0, companyB=100)
```

**All five ownership identifiers are the same company.** Scenarios A, C and D1 return `instock`
again — the false `outofstock` seen during the reverted F4 attempt is **fully explained by the
fixture**, not by the F4 predicate.

---

# 2 — ROOT CAUSE OF THE PREVIOUS FAILURE

The original fixture called `Product::factory()->finishedGood()` and
`Product::factory()->rawMaterial()` **without a brand**. Each factory generated **its own Brand and
therefore its own Company**, while `InventoryItem` rows were explicitly assigned to Company A.

Result: `$product->brand?->company_id` (the F4 predicate) resolved to a company that owned **no
inventory at all**, so every component read as zero.

**The fixture never modelled a valid single tenant. It passed before only because no company
predicate existed anywhere.**

---

# 3 — CORRECTED FIXTURE

| Change | Detail |
| --- | --- |
| Added | `Brand::factory()->create(['company_id' => $this->company->id])` in `setUp()` |
| Finished good | `->create(['brand_id' => $this->brand->id])` |
| Raw materials | `'brand_id' => $this->brand->id` added |
| Everything else | Unchanged |

**`ProductFactory` was not modified** (Part 9 honoured) — ownership is explicit in the fixture, so
future tests cannot silently inherit a surprise company.

---

# 4 — OWNERSHIP ASSERTIONS (Part 4) — all executed

| Assertion | Result |
| --- | --- |
| Finished product → Brand → Company = expected | ✅ **PASS** |
| Every BOM component company = finished-good company | ✅ **PASS** (loop over `$recipe->components`) |
| Every `InventoryItem.company_id` for components = expected | ✅ **PASS** |
| Warehouse company = expected | ✅ **PASS** |

**Runtime-proven:** `Finished Product Company = Recipe Company = Raw Material Company`.

---

# 5 — SCENARIO RESULTS AT `6149875b` (baselines preserved)

| Scenario | Recipe | Order outcome | Result |
| --- | --- | --- | --- |
| **A** — all components stocked | `instock` | reserved, `ready_for_dispatch` | ✅ **PASS** |
| **B** — one short, `allow_neg=OFF` | `outofstock` | **still reserved** | ✅ **PASS** — Option B gap preserved |
| **C** — short but `allow_neg=ON` | `instock` | reserved | ✅ **PASS** |
| **D1 / D2** — mixed policies | `instock` / `outofstock` | — | ✅ **PASS** |
| **E** — `can_manufacture` bypass | `outofstock` | **still reserved** | ✅ **PASS** — gap preserved |
| **TENANT** — A=0, B=100 | **`instock`** | — | ✅ **PASS** — **F4 leak still present, as expected** |

**Both known defects remain reproducible after the fixture correction.** The corrected fixture did
not accidentally mask them — which is exactly what makes it a valid baseline for F4.

---

# 6 — F4 READINESS

# ✅ READY

The reverted predicate `$product->brand?->company_id` is **not** disproven. With a valid
single-tenant fixture, a same-company stocked recipe evaluates `instock`, so scoping component
availability by the finished good's company will **not** produce false negatives for correctly-owned
data.

**STOP condition 4 did not fire:** a same-company stocked recipe is `instock` at `6149875b`.
**STOP condition 5 did not fire:** no production logic was needed to make the fixture pass.

---

# 7 — ⚠️ REMAINING UNCERTAINTY (Parts 7 & 8)

**The `InventoryItem` company contract is still UNVERIFIED.**

The TENANT scenario creates an `InventoryItem` for a **Company-A-owned product** carrying
`company_id = Company B`. Part 7 explicitly warns against manufacturing an invalid cross-company
inventory row to force a test.

| Question | Status |
| --- | --- |
| Can `InventoryItem.company_id` legitimately differ from Product → Brand → Company? | ❌ **UNVERIFIED** |
| Is the TENANT fixture row architecturally valid, or invalid data? | ❌ **UNVERIFIED** |

**Observation, not proof:** `inventory_items` carries both `warehouse_id` and `company_id`, and
warehouses are company-owned (RC-6, certified). That suggests `InventoryItem.company_id` denotes
**stock tenancy** — which company holds this stock — rather than catalogue ownership. If so, the
TENANT row is legitimate. **This was not proven and must not be assumed.**

**Consequence for F4:** none for the *fix* — F4 scopes by the finished good's company either way.
It matters for how the **tenant-isolation test** should be expressed. If a cross-company
`InventoryItem` turns out to be invalid data, the isolation test should instead use two
company-owned products with equivalent semantics.

---

# 8 — ADR CONSISTENCY

Consistent with ADR-013 (`Product → Brand → Company`) and with `EloquentBomRepository:245`, which
already derives a BOM's company the same way. **No ADR contradiction found.** No ADR text was
re-read in this task beyond the prior investigation.

---

# 9 — FINAL STATE

| | |
| --- | --- |
| Production tree | **`6149875b` — no tracked modifications** |
| F4 | **NOT implemented** |
| Option B | **NOT implemented** |
| Persistent change | **One untracked test file**, now a valid single-tenant fixture worth keeping |

---

# 10 — RECOMMENDATION

**Proceed to F4 + Option B implementation**, re-applying the three reverted edits verbatim:

1. `ManufacturingAvailabilityService:61-68` — company predicate, fail closed on null
2. `EloquentProductRepository:106-112` — the correlated SQL equivalent
3. `ReserveOrderInventoryAction:130` — the gate, consuming `ManufacturingAvailabilityService`

**Expected inversion after implementation:**

| Evidence line | Now | Must become |
| --- | --- | --- |
| `TENANT` | `instock` | **`outofstock`** |
| `B` | reserved, `ready_for_dispatch` | **reserved_qty 0, `AwaitingStock`** |
| `E` | reserved, `ready_for_dispatch` | **reserved_qty 0, `AwaitingStock`** |
| `A`, `C`, `D1` | `instock`, reserved | **unchanged** |

**Resolve §7 first** if the tenant-isolation test is to be certified as architecturally sound; it
does not block the F4 fix itself.

Then: RC-10 re-certification, Phase 3 regression, PHPStan, Guardian.

---

**Investigation + fixture correction only. No production business logic modified.
`ManufacturingAvailabilityService`, `EloquentProductRepository`, `ReserveOrderInventoryAction`,
`FulfillmentEngine`, order workflows and inventory logic all untouched. `ProductFactory` unchanged.
No schema change, no deployment, no commit.**
