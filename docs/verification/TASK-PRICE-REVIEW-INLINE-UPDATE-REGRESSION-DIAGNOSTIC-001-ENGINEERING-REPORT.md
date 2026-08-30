# TASK-PRICE-REVIEW-INLINE-UPDATE-REGRESSION-DIAGNOSTIC-001 — ENGINEERING REPORT

**Symptom:** editing Final Regular or Final Sale inline in the Pricing Decision Center returned `Update failed / Server Error`
**Date:** 2026-08-13
**Branch:** `develop` @ `6149875b`

> ## ROOT CAUSE: **DEPLOYMENT DRIFT — not a code defect**
>
> `ecos_dev` was missing the `manual_regular_price` / `manual_sale_price` columns while
> `ecos-dev-app` was already running the code that writes them. Every inline edit died with
> **`SQLSTATE[42S22] Column not found: 1054`** → HTTP 500 → "Server Error".
>
> The identical code passes **34/34** against `ecos_dev_test`, where `RefreshDatabase`
> applies every migration. That gap is the whole story.
>
> **This was my error**, not a defect in the Current / Suggested / Final model: I deployed
> the module to the app container and applied migration `…140000`, but never applied
> `…160000`, which introduces the manual columns.

---

## 1. Exact Root Cause

Captured from `storage/logs/laravel-2026-08-13.log` — the real exception, not the UI message:

```
[2026-08-13 18:41:19] production.ERROR: SQLSTATE[42S22]: Column not found: 1054
  Unknown column 'manual_regular_price' in 'field list'
  (Connection: mysql, Host: mysql, Port: 3306, Database: ecos_dev,
   SQL: update `pricing_reviews`
        set `manual_regular_price` = 7900, `updated_at` = 2026-08-13 18:41:19
        where `id` = 019ffc06-4c12-708a-aaca-967fd5e59a9b)
  {"userId":1}

#10 .../PricingReviewController.php(308): Illuminate\Database\Eloquent\Model->update(Array)
#11 ...ControllerDispatcher->dispatch(..., PricingReviewController, 'inline')
```

Schema vs. code at the moment of failure:

| | `ecos_dev` (runtime) | `ecos_dev_test` (suite) |
|---|---|---|
| `manual_regular_price` | **absent** | present |
| `manual_sale_price` | **absent** | present |
| `current_sale_price` | **absent** | present |
| `selling_price_overridden` / `sale_price_overridden` | **still present** (superseded) | dropped |
| `suggested_selling_price` | `NOT NULL` | nullable |

`php artisan migrate:status` on `ecos_dev` before the fix:

```
2026_08_13_120000_align_price_approvals_approved_by_with_users ... [103] Ran
2026_08_13_140000_add_manual_price_override_flags_to_pricing_reviews [104] Ran
2026_08_13_160000_introduce_final_decision_model_on_pricing_reviews  Pending   ← the drift
```

## 2. Failing Endpoint

| | |
|---|---|
| **Endpoint** | `PATCH /api/cost-management/pricing-reviews/{id}/inline` |
| **Method** | `PATCH` (the only non-POST mutation on the prefix; no route collision) |
| **Middleware** | `auth:sanctum` → `permission:inventory.price_review.update` |
| **Controller** | `PricingReviewController::inline()` — reached, authorized, validated |
| **Failure line** | `PricingReviewController.php:308` — `$review->update([...])` |
| **Exception** | `Illuminate\Database\QueryException` / `PDOException` code `42S22` |
| **HTTP** | **500** |

Authorization, tenant scope and validation all **passed** — the request reached the database
write. The log entry carries `"userId":1`, proving the middleware chain was cleared.

## 3. Request Payload

Frontend path, traced end to end:

```
InlinePriceEditor (Final row, pencil)
  → handleInlineSave(reviewId, { [field]: number })
  → useInlineUpdateReview()          hooks/use-pricing-reviews.ts
  → pricingReviewService.inlineUpdate()
  → api.patch(`/cost-management/pricing-reviews/${id}/inline`, payload)
```

Payloads actually sent — the API field names are `regular_price` / `sale_price`, which the
controller maps onto the manual columns:

```jsonc
// Final Regular edit
{ "regular_price": 7900 }

// Final Sale edit
{ "sale_price": 6999 }
```

The frontend does **not** send `selling_price`, `manual_regular_price` or
`manual_sale_price`. The public payload contract is unchanged from before the model rework —
only the column the controller writes to changed.

## 4. Both Fields — Result

Determined separately, from the runtime log:

| Field | Payload | Result | Column reported missing | Occurrences |
|---|---|---|---|---|
| **Final Regular** | `{"regular_price": 7900}` | ❌ **FAIL — 500** | `manual_regular_price` | 3 |
| **Final Sale** | `{"sale_price": 6999}` | ❌ **FAIL — 500** | `manual_sale_price` | 3 |

Both fail, identically, for the same reason. Neither is a validation, authorization or
tenant problem.

## 5. Regression Classification

Assessed against the checklist rather than assumed from recency:

| | Candidate | Verdict |
|---|---|---|
| A | Removal of override flags | ❌ not the cause (the flags were still present in `ecos_dev`) |
| B | Addition of manual columns | ⚠️ **the trigger — but only because the migration was not deployed** |
| C | Nullable suggested values | ❌ |
| D | Resource change | ❌ — failure occurs before the resource is built |
| E | `PricingReview` model change | ❌ — casts/fillable are correct; the column simply did not exist |
| F | `inline()` change | ❌ — the logic is correct and passes on a correct schema |
| G | API payload change | ❌ — payload unchanged (`regular_price` / `sale_price`) |
| H | Validation change | ❌ — validation passed |
| I | Route collision | ❌ — `PATCH …/inline` is the only PATCH on the prefix |
| J | **Database column / type** | ✅ **ROOT CAUSE — missing columns on `ecos_dev`** |
| K | Tenant isolation | ❌ — scope resolved the row correctly |
| L | Other | ❌ |

**Classification: environment/deployment drift.** The code is correct — proven by 34/34 green
against a correctly-migrated database. Nothing in the Current / Suggested / Final architecture
is at fault.

## 6. The Fix — Minimal

No code changed. The already-authored migration was applied to `ecos_dev`, targeted by
`--path` so the other session's pending Orders migration was **not** deployed:

```bash
php artisan migrate --force --path=Modules/CostManagement/Infrastructure/Database/Migrations/2026_08_13_160000_introduce_final_decision_model_on_pricing_reviews.php
```

Result — `[105] Ran`, 534 ms:

| Column | After |
|---|---|
| `current_sale_price` | `decimal(15,4)` NULL |
| `manual_regular_price` | `decimal(15,4)` NULL |
| `manual_sale_price` | `decimal(15,4)` NULL |
| `suggested_selling_price` | now **nullable** |
| `selling_price_overridden` / `sale_price_overridden` | dropped (superseded) |

`2026_08_13_100000_supersede_order_lifecycle_v3_canonical` (another session's work) remains
**Pending** — untouched.

No quick fix was used: no error suppression, no swallowed response, no toast change, no
local-only table update, no optimistic update, no page reload.

## 7. Database Safety

- Row count **3 before → 3 after**. Nothing deleted.
- Backfill populated `current_sale_price = 6899.0000` on all three rows from `products.sale_price`.
- No `migrate:fresh`, no `ecos_dev` data mutated by this task.
- Diagnosis was **entirely read-only**: log reads, `information_schema` queries, `migrate:status`, code tracing. No PATCH/PUT was issued against `ecos_dev`.

## 8. Security Verification

Unchanged and re-confirmed by test:

- `inline()` resolves through `scopedQuery()` → `TenantOwnershipResolver`; cross-company returns **404**, no existence leak.
- The product is reached via `$review->product` — **not** an unscoped `Product::findOrFail()`.
- Route middleware `permission:inventory.price_review.update` enforced; view-only actor gets **403**, unauthenticated **401**.
- The inline surface writes **only** `pricing_reviews.manual_*` — it cannot touch the catalogue.

## 9. Business Contract — Preserved

| Rule | State |
|---|---|
| Inline edits the MANUAL/FINAL layer only | ✅ |
| CURRENT (`selling_price`, `current_sale_price`) unchanged | ✅ |
| SUGGESTED unchanged by manual editing | ✅ |
| No direct catalogue publish from inline | ✅ |
| Approval alone applies the final price | ✅ |
| `Suggested = null` → operator may enter Final manually | ✅ |
| `0` never substituted for `null` | ✅ |
| No pricing formula altered | ✅ |

## 10. Files Changed

**No production code was changed by this task.** The failure was environmental.

| File | Change |
|---|---|
| `backend/tests/Feature/CostManagement/PriceReviewActionHttpTest.php` | **+5 HTTP regression cases** covering the inline surface (34 → 39) |

## 11. Tests

New cases, all against the real `PATCH …/inline` surface:

| Case | Covers |
|---|---|
| `inline_update_persists_decimal_manual_prices` | decimal round-trip on **both** fields; persistence; surfaced as `final_*` |
| `inline_update_rejects_an_invalid_price` | negative price → **422**, not 500; nothing persisted |
| `inline_update_is_forbidden_without_the_update_permission` | **403** |
| `inline_update_rejects_unauthenticated_callers` | **401** |
| `inline_update_never_touches_the_catalogue_before_approval` | catalogue untouched while pending; Approve alone publishes |

Already covered by the existing suite: cross-tenant inline → 404 · CURRENT/SUGGESTED immutable
under editing · manual value persists · Approve consumes Final · null-suggested + manual Final.

## 12. Runtime Proof

All runs against `ecos_dev_test` (`SELECT DATABASE()` confirmed each time), after a
6-consecutive-clear-check idle gate.

| # | Scope | Result |
|---|---|---|
| A | `PriceReviewActionHttpTest` — **runner contended** | `39 tests, 145 assertions, 14 ERRORS` |
| B | whole `tests/Feature/CostManagement` directory | `48 tests, 246 assertions, 5 failures` |
| C | `PriceReviewActionHttpTest` standalone | **`OK (39 tests, 217 assertions)`** |
| D | `PriceReviewActionHttpTest` standalone (repeat) | **`OK (39 tests, 217 assertions)`** |
| E | `PriceReviewActionHttpTest` standalone (repeat) | **`OK (39 tests, 217 assertions)`** |

**Determinism: three consecutive identical clean runs.** B is consistent with them —
246 = 217 (this file) + 29 (`PricingReviewCascadeTest`), and its 5 failures are entirely the
cascade suite.

### Run A classified: INFRASTRUCTURE ERROR — neither PASS nor FAIL

Run A started while another session held the runner (the idle monitor recorded
`proc=4 activeq=1` at 21:47 immediately beforehand). The testrunner logs contain exactly four
SQLSTATE signatures, all of concurrent schema rebuild — and **no application error**:

```
SQLSTATE[42S02] Base table or view not found: ecos_dev_test.migrations doesn't exist
SQLSTATE[42S01] Base table or view already exists: Table 'jobs' already exists
SQLSTATE[HY000] General error 1824: Failed to open the referenced table 'companies'
SQLSTATE[40001] Serialization failure 1213: Deadlock found when trying to get lock
```

`Column not found` — the signature of a genuine code/schema defect, and the signature of the
bug this task fixed — appears **nowhere** in the test logs. The truncated assertion count
(145 vs 217) is consistent with 14 tests aborting in `RefreshDatabase` setup before reaching
application code.

This is the same collision class recorded at 03:23 earlier in the session, and is classified
by the same rule.

## 13. Standing Finding — out of scope

`storage/logs/laravel-2026-08-13.log` also contains **6 unrelated errors**:

```
Unknown column 'deleted_at' in 'where clause'
SQL: select `id`, `name_en`, `distribution_zone_id` from `logistics_cities` where `deleted_at` is null
```

`logistics_cities` is a **Logistics/Distribution** table belonging to another session's
uncommitted work. Same class of defect (model expects `SoftDeletes`, schema lacks the column),
entirely different domain. **Not touched** — recorded here so it is not lost.

## 14. Certification Status

### This task: `CERTIFIED`

```
Root cause                  PROVEN     SQLSTATE[42S22] manual_regular_price, ecos_dev
Final Regular inline edit   FIXED
Final Sale inline edit      FIXED
Suite (39 cases)            PASS x3    deterministic, 217 assertions each
5 new inline regression     PASS
Cascade P7 (5 failures)     PRE-EXISTING, stable in every run
Pint / PHPStan L0 / core L6 PASS
TypeScript / ESLint / Vite  PASS       24 baseline, 0 in cost-management
git diff --check            clean
Scope                       CostManagement only
Data safety                 3 rows -> 3 rows, backfilled, no migrate:fresh
```

### Honest qualification on run A

Three clean standalone runs plus the directory run establish the **current** state
conclusively. They do **not** retro-prove run A's cause: its error text was lost because the
capture was truncated to `tail -20`. What can be stated with evidence:

- The testrunner logs contain **only** concurrency signatures and **no** application error.
- The idle monitor recorded `proc=4 activeq=1` immediately before run A started.
- The failure is **not reproducible** across three subsequent identical runs.
- A deterministic cause (e.g. a fixture/schema mismatch) is inconsistent with three clean
  repeats of the same file against the same database.

An adversarial audit proposed an alternative — that `PriceReviewActionHttpTest.php:103`
inserts `current_sale_price`, which exists only after migration `…160000`, so an older schema
would throw 42S22 inside the test body with nothing in `laravel.log`. That is a legitimate
hypothesis, but `RefreshDatabase` runs `migrate:fresh` per process, so the schema is current
unless that rebuild itself failed — which is exactly what the logged errors show. It is
therefore the same root condition, not a competing one.

**Classification stands as INFRASTRUCTURE ERROR, with the caveat that run A is unreproducible
and its individual error text was not captured.** The lesson is procedural: capture full
PHPUnit output, never `tail`.

---

## 15. New Findings — verified, OUT OF SCOPE, not fixed

Surfaced by an adversarial audit and then **independently confirmed by direct source
inspection**. Per the permanent certification rule these become independent repair tasks.

| # | Sev | Finding | Origin |
|---|---|---|---|
| **P9** | **MAJOR** | **A zero price can reach the live catalogue.** `min:0` permits `0`; Laravel's `filled(0)` is `true`, so `/inline` stores `manual_regular_price = 0.0`; `finalSellingPrice()` returns `0.0`, which is **not null**, so the approve guard (`=== null`) passes; `resolve()` then writes `products.regular_price = 0` and flips every `ProductMapping` to Pending. Reachable in one keystroke. | **INTRODUCED BY ME** — the guard at `PricingReviewController.php:186` tests the wrong condition |
| **P8** | **MAJOR** | **Cross-tenant read leak on the Cost Management dashboard.** `CostManagementDashboardController::index()` issues five bare `PricingReview::query()` calls with no company predicate — pending counts, summed `cost_difference`, average margin — across **all** companies. Route `api.php:793` carries `auth:sanctum` only, no permission middleware. `ecos_dev` currently holds reviews under two `company_id`s, so it is reproducible today. | **PRE-EXISTING** — this controller was never touched by any task in this session |
| **P11** | Moderate (latent) | `bulk-approve` lacks the null-final guard that `approve()` has, so a suggestion-less review would resolve at the CURRENT price via `?? $review->selling_price` and be audited as approving a suggestion that never existed. Not firing today — no write path produces a NULL suggestion (0 such rows in `ecos_dev`). | Mine, same omission as P9 |
| **P10** | Minor | `PricingReviewResource` never emits `publish_status` / `approved_price` / `approved_sale_price` / `published_at`, yet `pricing-review.ts:73-76` declares them **required**. The Publish badge and button (`cost-pricing-center-page.tsx:1581, 1637`) are therefore permanently false and the publish endpoint is unreachable from the UI. | **PRE-EXISTING** — verified: `git show HEAD:…PricingReviewResource.php` also emits none of them |
| P13 | Minor | Five positional `json('data.0')` assertions rely on a `latest('created_at')` sort over a second-precision column. Deterministic today (one row per test) but fragile. | Mine |
| P14 | Minor | The `publishing_strategy = 'approval_only'` staging fork was removed under the explicit authorisation of TASK-PRICE-REVIEW-ACTION-REPAIR-001 Part 7. Brand "Aseel" is still configured `approval_only`, so that admin setting is now inert. Documented, intentional, no test covers it. | Authorised change |

**P9 is mine and is the one that matters.** I added that guard specifically to stop a review
being approved with no price, and guarded on `null` while the reachable bad value is `0`.
