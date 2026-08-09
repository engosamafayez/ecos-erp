# TASK-PHASE3-GD1-STEP3-CLOSE-001 — Engineering Report

**Date:** 2026-08-09 · **Worktree:** `develop` @ `C:\ecos-develop` · Host PHP 8.4.22

| | |
| --- | --- |
| **GD-1 (Product population)** | ✅ **RESOLVED** — Option A, from existing behaviour |
| **Step 3** | ✅ **COMPLETE** |
| **Guardian** | ✅ `GUARDIAN_EXIT=0` · TypeScript baseline **24** held |

---

# 1 — GD-1 EVIDENCE

| # | Source | Finding |
| --- | --- | --- |
| 1 | `ProductController::stats():222` | **Always** scoped: `whereHas('brand', … company_id = Auth::user()->company_id)`. The platform's own statement of the intended Product population |
| 2 | `EloquentProductRepository:134-138` | List scoped **only if the caller supplies** `company_id` — `if ($companyIdFilter !== '')`. No baseline scope |
| 3 | `Product::booted()` | **No `addGlobalScope('tenant')`.** Only three models ever had it — Warehouse, Order, Supplier — all now fail closed |
| 4 | `frontend/src/features/products/**` | `company_id` exists as a *filter type* only. **No `CompanySelect` component** — unlike Warehouses, which exposes one |
| 5 | Certification RC-2 | Flagged `All companies` browsing on **Purchases and Recipes** — **Products was never named** |
| 6 | RC-6 (certified) | Cross-company capability must be explicit via the documented **`is_system`** path; a null `company_id` is **not** privilege |

## Answers to the seven questions

| # | Question | Answer |
| --- | --- | --- |
| 1 | Authoritative product population? | **The authenticated company's products**, resolved through `brand.company_id` (post-ADR-013 there is no direct column) |
| 2 | When is cross-company access intentional? | Only for an actor holding an **`is_system`** role |
| 3 | Which users? | Holders of an is_system role — no other path exists |
| 4 | Does an explicit capability already exist? | **Yes** — `TenantOwnershipResolver::isUnrestricted()`, certified in RC-6. **Nothing new was needed** |
| 5 | Does stats honour that population? | **Yes**, and always did |
| 6 | Does the list honour it? | **No** — this was the defect |
| 7 | Bug or intentional distinction? | **Bug.** No UI, permission, scope or documentation supports cross-company product browsing for a normal user |

---

# 2 — EXISTING PRODUCT POPULATION MODEL

Products carry no `company_id` column; ownership is reached through `brand.company_id`. `stats()`
encoded that correctly. The list simply never applied it, so the two endpoints described different
sets whenever no filter was supplied — producing **`All Materials = 0`** above a populated table.

---

# 3 — RESOLUTION

# GD-1 (Product population) = RESOLVED — **OPTION A**

> **LIST population = STATS population = the authenticated company's products.**
> Cross-company visibility remains available **only** through the certified `is_system` path.
> A caller-supplied `company_id` may **narrow** within that scope and can never widen it.

**No new permission. No new business policy.** Option B was tested against the codebase and found
unsupported: no UI surface, no permission, no scope, no documentation — and the certification's
group-buyer note named Purchases and Recipes, not Products.

**Scope note:** this resolves the **Product population** question only. GD-1's platform-wide
classification of every entity as GLOBAL / SHARED / COMPANY SCOPED remains an **owner decision** under
the tenant-2 gate.

---

# 4 — STEP 3 IMPLEMENTATION

**Two files. No new endpoint, no fabricated totals, no schema change.**

| File | Change |
| --- | --- |
| `EloquentProductRepository::paginate()` | Authoritative company scope added via `->tap()` using `TenantOwnershipResolver`: no actor or unrestricted → skip; null company → `whereRaw('1 = 0')`; otherwise `whereHas('brand', …)`. The caller's `company_id` filter still applies further down and can only narrow |
| `ProductController::stats()` | Repointed from `Auth::user()->company_id` to the **same resolver**, and now also honours a caller-supplied `company_id` as a narrowing filter — so both endpoints share one population source |

**Preserved:** pagination, search, every existing filter, API shape, and the is_system capability.
**Frontend untouched** — the backend contract now returns the correct population, so no client-side
filtering or page-derived totals were introduced.

## 4.1 Residual, recorded not fixed

`stats()` defaults to `product_type IN (raw_material, packaging_material)` when no type is supplied;
the list applies no such default. This diverges **only** when a caller sends no product-type filter —
neither workspace does. Changing it would alter what the Raw Materials KPI counts, which is a product
decision, not a scope bug. **Out of Step 3's scope; documented rather than guessed.**

---

# 5 — TENANT-ISOLATION VERIFICATION

| Required | Result |
| --- | --- |
| 1. Normal user sees own company products | ✅ `test_company_user_sees_only_their_own_products` |
| 2. Cannot escape scope via an arbitrary company filter | ✅ `test_company_filter_cannot_widen_beyond_the_authoritative_scope` — returns `[]`, not other rows |
| 3. NULL-company non-system fails closed | ✅ `test_companyless_non_privileged_user_sees_no_products` — list **0**, stats **0** |
| 4. is_system capability still works | ✅ `test_unrestricted_user_retains_cross_company_visibility` — sees both companies |
| 5. Stats == list for the same query | ✅ Two tests |
| 6. No accidental cross-company disclosure | ✅ Covered by 1, 2, 3 |

**RC-6 was not reopened** — its `TenantOwnershipResolver` was reused unchanged.

---

# 6 — REGRESSION TESTS

`backend/tests/Feature/Inventory/ProductPopulationScopeTest.php` — 7 cases.

**The original symptom is pinned directly** by
`test_statistics_and_list_describe_the_same_population_without_a_filter`: two companies' products
exist, no filter is sent, and the KPI must equal the table (1 == 1) rather than reporting a different
population.

```
OK (7 tests, 24 assertions)   —   Time: 07:48.338
```

**One correction during the run:** my first version guessed the stats key as `total_products`; the
endpoint returns **`total_count`**. Four assertions read `-1` as a result. **The scoping assertions
passed from the first run** — only the key lookup was wrong. Fixed and re-run green.

---

# 7 — VALIDATION MATRIX

| Gate | Result |
| --- | --- |
| Targeted PHPUnit | ✅ `OK (7 tests, 24 assertions)` |
| PHP lint — HOST PHP 8.4.22 | ✅ `No syntax errors detected` ×3 |
| PHPStan level 0 (platform) | ✅ `[OK] No errors` |
| PHPStan level 6 (`app/Core`) | ✅ `[OK] No errors` |
| **Guardian pre-push** | ✅ **All 8 validators — `GUARDIAN_EXIT=0`** |
| TypeScript | ✅ PASS — baseline **24** held |
| ESLint | ✅ PASS |
| i18n missing keys | ✅ **0** — no frontend file changed |
| EN/AR parity · RTL | ✅ Unaffected |
| `--no-verify` · container PHP · new suppressions | ✅ None |

---

# 8 — DECISION REGISTER UPDATE

- **GD-1 (Product population) = RESOLVED** — Option A, engineering resolution from existing behaviour
- **GD-1 (platform-wide entity classification)** = still **OWNER DECISION REQUIRED**, tenant-2 gate
- **Step 3 = COMPLETE**
- Steps 4–7 = **BLOCKED** (PD-1 + PD-2) · RC-10 = **BLOCKED**
- All previously certified decisions untouched

---

# 9 — STEP 3 FINAL STATUS

# ✅ COMPLETE

The KPI and the table now describe the same population by construction, both resolving through one
authoritative source. The `All Materials = 0` contradiction cannot recur, and closing it **also
removed a real cross-company product disclosure** — the list previously returned every company's
products to any authenticated user.

---

# 10 — EXACT REMAINING PHASE 3 BLOCKERS

| Step | Status |
| --- | --- |
| 1 · 2 · 3 · 8 | ✅ **COMPLETE** |
| **4–6** — RC-10 transition track | ⛔ **PD-1 + PD-2** — must ship as one release |
| **7** — remove V2 translation layers | ⛔ **PD-2** |

**4 of 8 steps complete. Phase 3 is NOT complete.**

**Two owner decisions now gate everything that remains: PD-1 and PD-2.**

- **PD-1** — reduced to ratifying existing enforcement plus one open question: warehouse assignment at *Ready for Dispatch* or at *Dispatch*?
- **PD-2** — decide `completed`, `review`, `preparing`, and the `confirmed`/`processing` merge.

---

**Steps 4–7 not started. RC-10 untouched — vocabulary, guards, transitions and transition UI
unchanged. PD-1 and PD-2 not modified. No certified work reopened. No new permission, no destructive
migration, no `--no-verify`, no deployment.**
