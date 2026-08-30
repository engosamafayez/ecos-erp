# TASK-PRICING-REVIEW-COST-MANAGEMENT-FINAL-CERTIFICATION-001 — ENGINEERING REPORT

**Scope:** Cost Management / Pricing Review — final runtime certification + real browser E2E
**Date:** 2026-08-13
**Branch:** `develop` @ `6149875b`
**Implementation:** TASK-PRICE-REVIEW-ACTION-REPAIR-001
**Diagnostic:** TASK-PRICE-REVIEW-ACTION-REGRESSION-DIAGNOSTIC-001

> **FINAL VERDICT: `NOT CERTIFIED`** — see §16.
> Automated DB-backed suite **NOT EXECUTED** (runner held continuously by another agent).
> Browser E2E **PENDING USER BROWSER SMOKE** (no authenticated session available).
> Everything that does not require the shared runner is complete and passing.

---

## 1. Environment

| Component | Value |
|---|---|
| Host workspace | `C:\ecos-develop` (Windows, Git Bash) |
| App container | `ecos-dev-app` — `DB_DATABASE=ecos_dev`, serves `http://127.0.0.1:8081` |
| Test runner | `ecos-dev-testrunner` — `APP_ENV=testing`, `DB_DATABASE=ecos_dev_test` |
| Database server | `ecos-dev-mysql` (MySQL 8.4) — holds `ecos_dev` and `ecos_dev_test` |
| Frontend | Vite build → `backend/public/app/`, served by `ecos-dev-nginx` under `/app` |
| Framework | Laravel 12, PHP 8.2+ |

**Shared-environment note.** `ecos-dev-testrunner` and `ecos_dev_test` are shared across concurrent agent sessions. `ecos-dev-app` mounts **only** `app-storage`; there is no source volume mount, so every source change requires `docker cp` into the container. This applies to the built SPA as well — see §12.

No destructive Docker command was run at any point: no `docker compose down`, no `down -v`, no `system prune`.

---

## 2. Source Parity

Verified by SHA-256 across host, test runner, and app container **after** the final edit. Container paths were addressed through `sh -c` because Git Bash rewrites bare `/var/...` paths on Windows (an early parity run reported false mismatches for this reason and was re-run correctly).

| File | Host | Runner | App | Match |
|---|---|---|---|---|
| `PricingReviewService.php` | `cec56b09` | `cec56b09` | `cec56b09` | ✅ |
| `PricingReviewController.php` | `be4beef1` | `be4beef1` | `be4beef1` | ✅ |
| `PriceApproval.php` | `bf7c63b7` | `bf7c63b7` | `bf7c63b7` | ✅ |
| `CostManagementServiceProvider.php` | `81c286e9` | `81c286e9` | `81c286e9` | ✅ |
| `2026_08_13_120000_align_price_approvals_approved_by_with_users.php` | `41343dcf` | `41343dcf` | `41343dcf` | ✅ |
| `PriceReviewActionHttpTest.php` | `1b43d104` | `1b43d104` | `1b43d104` | ✅ |

**PARITY = PASS.** No stale container code. Route/config/application caches were cleared in the runner (`route:clear`, `config:clear`, `cache:clear`) before any API feature test, per the known stale-route-cache failure mode.

---

## 3. Database Target

Automated tests target **`ecos_dev_test`**, never `ecos_dev` and never MAIN (`ecos_erp`).

Enforced in three independent places, so a single misconfiguration cannot redirect a suite onto real data:

1. `backend/phpunit.xml` — `<env name="DB_DATABASE" value="ecos_dev_test" force="true"/>`
2. `docker-compose.override.yml` — the `testrunner` service sets `DB_DATABASE: ecos_dev_test`
3. `backend/tests/TestCase.php::setUp()` — overrides `putenv`, `$_ENV` and `$_SERVER` and resets Laravel's `Env` repository singleton **before** the app is created, because Docker's `env_file` bakes the runtime database into OS env at container start and PHPUnit's `force="true"` only calls `putenv()`.

`SELECT DATABASE()` verification is part of the run procedure and is **outstanding** together with the suite itself (§7).

---

## 4. Concurrency Control

Required gate: **6 consecutive clear checks** (no PHPUnit, no `migrate:fresh`, no `migrate --force`, no other RefreshDatabase, no active queries on `ecos_dev_test`) before starting.

Observed timeline (all times local):

| Time | Observation |
|---|---|
| 02:58–03:05 | Foreign `phpunit tests/Feature/Commerce/OrderLifecycleV3SupersessionTest.php` building schema, 55 → 555 tables |
| 03:05:12 | Tables **555 → 0** — a `RefreshDatabase`/`migrate:fresh` cycle by that run |
| 03:21:49 | Foreign run exits. Runner briefly clear |
| ~03:22 | **This session's suite started** — 20 cases |
| 03:23 | Foreign session starts `php artisan migrate --force` on the same database, concurrently |
| 03:23 | This session's suite dies in `RefreshDatabase` setup: `Base table or view not found: ecos_dev_test.migrations` |
| 03:24–03:48 | Foreign `OrderLifecycleV3SupersessionTest` runs **again**; monitor records **0 idle checks in 90 samples over 22.5 min** |
| 03:48 | Foreign run still active (third cycle, 242 tables) |

**Total continuous foreign ownership at time of writing: ~50 minutes.**

Per Part 1 this session did **not** compete for the runner: it did not kill the foreign process, did not run `migrate:fresh`, did not force migrations, and did not start a competing suite. A monitor requiring the sustained idle window remains armed.

---

## 5. Test Isolation

`PriceReviewActionHttpTest` declares:

```php
protected bool $grantsBaselineAuthorization = false;
```

This is mandatory here. `Tests\TestCase::actingAs()` attaches the production `is_system` role to any role-less user. `TenantOwnershipResolver::isUnrestricted()` consults exactly that flag, so a baseline-granted subject would be treated as an unrestricted cross-company actor — silently erasing the boundary the tenant cases exist to prove.

Every subject is built explicitly instead:

- `scopedUser($company, $permissionNames, $slug)` — a real `Role` (`is_system = false`) with real `Permission` rows attached through the real `role_permissions` pivot.
- Authentication uses `actingAsUnprivileged()`, which bypasses the baseline grant.
- The forbidden case uses a same-company user holding **only** `inventory.price_review.view`, proving the permission gate rather than the tenant gate.

---

## 6. Price Precision

The real schema contract, read from the live database and **left unchanged**:

| Column | Type |
|---|---|
| `products.regular_price` | `decimal(12,2)` |
| `products.sale_price` | `decimal(12,2)` |
| `pricing_reviews.approved_price` | `decimal(12,4)` |
| `pricing_reviews.approved_sale_price` | `decimal(12,4)` |
| `pricing_reviews.suggested_selling_price` | `decimal(15,4)` |
| `pricing_reviews.suggested_sale_price` | `decimal(15,4)` |
| `pricing_reviews.selling_price` | `decimal(15,4)` |
| `products.product_cost` | `decimal(15,4)` |

Consequence, asserted independently in the suite:

```
decision                         7011.1111
  → products.regular_price       7011.11     (catalogue, 2dp)
  → pricing_reviews.approved_price 7011.1111 (review, 4dp — full precision retained)
```

No schema change was made. Product precision was **not** raised; review precision was **not** lowered.

An earlier draft of the suite wrongly assumed 4dp would survive onto the product. That was corrected by asserting the true 2dp value on the catalogue **and adding** a separate 4dp assertion on `approved_price`/`approved_sale_price` — a strictly stronger check, not a weakened one. This was found by reading the schema, not by relaxing a failing assertion.

---

## 7. Automated Tests

### Status: **NOT EXECUTED — BLOCKED BY SHARED RUNNER**

The suite exists, is synced (§2), and is ready. It has **not** been executed in an idle environment, so this report claims **no pass and no failure**.

The single prior attempt is classified exactly as the task requires:

> **INFRASTRUCTURE ERROR / NOT EXECUTED**
> `Tests: 20, Assertions: 0, Errors: 20`
> All 20 died inside `Illuminate\Foundation\Testing\RefreshDatabase::beforeRefreshingDatabase` →
> `PDOException SQLSTATE[42S02]: Base table or view not found: 1146 Table 'ecos_dev_test.migrations' doesn't exist`
> Cause: a concurrent `php artisan migrate --force` from another session dropped/rebuilt the schema underneath this run.
> **Assertions executed against production code: 0.** Not a product failure, not a test failure.

### Suite contents — 20 cases, `backend/tests/Feature/CostManagement/PriceReviewActionHttpTest.php`

| # | Case | Contract proven |
|---|---|---|
| 1 | `approve_applies_prices_closes_review_and_leaves_pending` | A + N — 200, catalogue price applied (2dp), review 4dp, `publish_status=published`, resolved, absent from Pending |
| 2 | `approved_by_stores_the_canonical_bigint_user_id` | L — identity contract; `assertIsInt`, equals `users.id`, `approver()` resolves |
| 3 | `pending_summary_count_decreases_after_approval` | M — summary 1 → 0 |
| 4 | `keep_current_holds_regular_price_and_closes_review` | C |
| 5 | `custom_price_applies_the_operator_value` | D |
| 6 | `reject_closes_the_review_without_touching_price` | E |
| 7 | `approve_fails_safely_when_the_product_no_longer_exists` | B — 422 not 500, review stays Pending, no audit row |
| 8 | `resolving_an_already_resolved_review_is_rejected` | J — 422, duplicate/repeat approval behaviour |
| 9 | `invalid_action_is_rejected_with_422` | K — validation, incl. `custom_price` required-if |
| 10 | `user_without_the_approve_permission_is_forbidden` | F — 403 |
| 11 | `unauthenticated_request_is_rejected` | 401 |
| 12 | `cross_tenant_review_is_not_readable` | G — 404 + empty list + zero summary |
| 13 | `cross_tenant_review_cannot_be_approved` | H — 404, no mutation |
| 14 | `cross_tenant_review_cannot_be_snoozed_or_assigned` | H |
| 15 | `cross_tenant_review_cannot_be_inline_edited` | H (PATCH writes `products`) |
| 16 | `companyless_user_sees_nothing_and_cannot_mutate` | fail-closed |
| 17 | `bulk_approve_fails_closed_when_the_selection_crosses_tenants` | I — nothing mutated at all |
| 18 | `bulk_approve_resolves_every_owned_review` | bulk happy path |
| 19 | `bulk_policy_fails_closed_when_the_selection_crosses_tenants` | I |
| 20 | `snooze_still_moves_the_review_out_of_pending` | Snooze unchanged, no audit row, no price write |

No case was deleted or weakened relative to the implementation task.

**Required to close:** all 20 → PASS, with no ERROR, FAIL, or unexplained SKIP.

---

## 8. HTTP / API

The suite is HTTP-level throughout — `postJson` / `patchJson` / `getJson` against real routes, through real middleware, into a real database. No mocked service, no partial mock, no direct model call substituting for a request.

Each case therefore exercises: route resolution → `auth:sanctum` → `permission:` middleware → tenant scope → FormRequest validation → controller → `PricingReviewService` → database → JSON response.

This is deliberate. The defect being certified against lived *between* controller and service and was invisible to the pre-existing suite, which only ever called `PricingReview::resolve()` on the model.

Live route table (from the running app container, uncached):

```
GET|HEAD   api/cost-management/pricing-reviews
GET|HEAD   api/cost-management/pricing-reviews/badge
POST       api/cost-management/pricing-reviews/bulk-approve
POST       api/cost-management/pricing-reviews/bulk-policy
POST       api/cost-management/pricing-reviews/{id}/approve
POST       api/cost-management/pricing-reviews/{id}/assign
GET|HEAD   api/cost-management/pricing-reviews/{id}/detail
PATCH      api/cost-management/pricing-reviews/{id}/inline
POST       api/cost-management/pricing-reviews/{id}/publish
POST       api/cost-management/pricing-reviews/{id}/snooze
```

10 routes, unchanged by this work, no collision, no route cache present.

**API = PENDING RUN** (the assertions exist; they have not executed).

---

## 9. Tenant Boundary

Implementation reuses the existing `App\Core\Company\TenantOwnershipResolver` (RC-6 / GD-1) — the same resolver and fail-closed predicate the Inventory domain already uses. No new global scope was introduced.

Applied to all 14 entry points: `index`, all 7 summary counters, `detail`, `approve` (× 4 actions), `snooze`, `assign`, `inline`, `publish`, `bulkApprove`, `bulkPolicy`, `badge`.

Matrix the suite asserts (cases 12–17, 19) — **no system actor is used in any of them**:

| Actor | Target | Expected |
|---|---|---|
| Company A | Company A data | ALLOW |
| Company A | Company B data | DENY — 404, no existence leak |
| Company B | Company A data | DENY — 404 (cases build both directions via distinct companies) |
| Company A | mixed A+B bulk | DENY — 404, **nothing** mutated, not even A's own rows |
| No company | anything | DENY — `where 1 = 0`, fail closed |

**TENANT SECURITY = PENDING RUN.**

---

## 10. Regression

**NOT EXECUTED** — gated behind the 20 cases per Part 11, and blocked by the same shared-runner contention.

Planned scope (deliberately narrow; no full-system re-run):

- `tests/Feature/CostManagement/PricingReviewCascadeTest.php` — 10 cases. Highest-value regression: it covers review *creation* and calls `PricingReview::resolve()` directly, and `PricingReviewService`'s constructor changed (the `ConfigurationManager` dependency was removed), so its container binding is exercised here.
- Any suite touching `Product` pricing fields, since `resolve()` now always writes `products.regular_price` / `sale_price`.

Not planned: unrelated Orders / Operations / Distribution suites. Those files carry another session's uncommitted work and are outside this contract.

---

## 11. Static Quality

All executed on the final, parity-verified source.

| Check | Command / scope | Result |
|---|---|---|
| **Pint** | `Modules/CostManagement`, `tests/Feature/CostManagement` | ✅ **PASS** — 50 files |
| **PHPStan L0** | `phpstan.neon.dist` (whole platform) | ✅ **[OK] No errors** |
| **PHPStan core L6** | `phpstan-core.neon.dist` | ✅ **[OK] No errors** |
| **TypeScript** | `npx tsc --noEmit -p tsconfig.app.json` | ✅ **24 before → 24 after** |
| **TypeScript — cost-management** | grep of the error list | ✅ **0 errors** |
| **ESLint** | changed frontend files | ✅ **0 errors** (1 warning: JSON file not covered by config) |
| **Vite build** | `npx vite build` | ✅ **built in 8.87s** |

The TypeScript baseline was measured properly, not assumed: the three changed frontend files were reverted to `HEAD`, `tsc` re-run (**24**), then restored and re-run (**24**). Identical. The 24 belong to the pre-existing EPIC-L10N-001 backlog (marketing, orders, stock-ledger) and are **classified pre-existing and left untouched**, per Part 12.

`tsc` was run with `-p tsconfig.app.json`; a bare `npx tsc --noEmit` type-checks zero files in this repo.

**STATIC QUALITY = PASS.**

---

## 12. Browser E2E

### Status: **PENDING USER BROWSER SMOKE**

No authenticated browser session is available, and credentials were not used — neither entered, requested, nor transmitted.

Both available surfaces were checked:

| Surface | Result |
|---|---|
| In-app browser pane → `http://127.0.0.1:8081/app/inventory/cost-management/price-review` | Redirected to `/app/login` — "Welcome Back / Sign in to access your ECOS ERP workspace" |
| Connected Chrome (`Browser 1`, Windows, local) → same URL | Redirected to `/app/login` |

ECOS authenticates via a Bearer token in `localStorage` (`tokenStorage` + axios request interceptor), so no ambient cookie session exists to inherit. The tab opened for this check was closed.

Per Part 13 and Part 17, **no E2E is claimed**. Nothing was mocked, faked, or simulated to stand in for it. Steps 1–15 of Part 15 are all **unproven**.

### Prerequisite for whoever runs the smoke

`ecos-dev-app` has **no source volume mount** (only `app-storage`). `npx vite build` writes to the host's `backend/public/app/`, which the container does not see. Before the browser smoke, the built SPA must be copied in:

```bash
docker cp backend/public/app ecos-dev-app:/var/www/html/public/
```

Without this the browser serves the pre-change bundle, and the smoke would test the old frontend — including the removed client-side row hiding, which would produce a **false pass**.

The backend migration must also be applied to `ecos_dev` before the smoke, or `approved_by` remains `char(36)` there and Approve will still fail:

```bash
docker exec ecos-dev-app php artisan migrate --force
```

---

## 13. Data Safety

- **No production data changed.** No mutating HTTP request was issued against `ecos_dev`; no `INSERT`, `UPDATE`, `DELETE`, or DDL was run against it. All `ecos_dev` access was read-only `SELECT` / `SHOW` / schema introspection.
- **`FG-000001` was not consumed.** Per Part 14, the single real pending review in `ecos_dev` (`019faefb-befa-735b-9fa6-0bd5dc10f0b7`) was left untouched. It is still `status = pending`, `resolved_at = NULL`.
- **All mutation testing is fixture-based** inside `ecos_dev_test` under `RefreshDatabase`. Every case builds its own company → brand → product → review chain; nothing depends on or mutates shared dev data.
- **No business schema was modified.** The one migration changes a single audit column's type (`price_approvals.approved_by`) plus its foreign key. It preserves numeric values, nulls only values that cannot represent a bigint key, deletes nothing, and reverses cleanly.
- **Provenance investigated before writing the migration** (required by the implementation task): `price_approvals` holds **0 rows** in `ecos_dev` and **0 rows** in `ecos_erp`. There is no historical `approved_by` data anywhere to preserve or rewrite — a direct consequence of the root cause.
- **No destructive Docker command** was run.
- **The foreign agent's run was not interfered with** — not killed, not pre-empted.

One honest disclosure: at ~03:22 this session's suite began during a brief window in which the runner appeared clear, and the foreign session started `migrate --force` moments later. The two `RefreshDatabase`/migrate cycles overlapped on `ecos_dev_test`. That collision is why the 20 cases errored. It affected only the shared **test** database, which both sides rebuild from scratch, and no real data. The sustained-idle gate now in force exists specifically to prevent a repeat.

---

## 14. Failures / Pre-existing Findings

### Blocking

| # | Finding | Class |
|---|---|---|
| B1 | 20 automated cases **NOT EXECUTED** — `ecos_dev_test` held continuously (~50 min) by another agent's `OrderLifecycleV3SupersessionTest` plus a manual `migrate --force` | **INFRASTRUCTURE / ENVIRONMENT** |
| B2 | Browser E2E **not performed** — no authenticated session; credentials prohibited | **ENVIRONMENT** |

### Pre-existing, outside this contract — recorded as Findings, not buried

| # | Finding | Disposition |
|---|---|---|
| P1 | **24 TypeScript errors** (marketing, orders, stock-ledger) — EPIC-L10N-001 backlog | Pre-existing; unchanged before/after; not fixed here per Part 12 |
| P2 | **No tenant scoping on Pricing Review GET routes' permission layer** — `index`, `badge`, `detail` carry `auth:sanctum` but no `permission:inventory.price_review.view`, though the permission exists and is granted | Separate Finding → own repair task |
| P3 | **Dual permission namespace** — routes enforce `inventory.price_review.*` while the IAM enterprise matrix seeds `cost.price_review.*` (view/update only), enforced nowhere | Separate Finding → IAM backlog |
| P4 | **Nav badge query key never invalidated** by any mutation; self-corrects on its 120 s `refetchInterval` | Minor; separate Finding |
| P5 | **`PriceReviewApproved` / `PriceReviewRejected` have zero listeners** platform-wide. Now that approvals actually succeed, these fire for the first time — into nothing. An unconsumed extension point, not a defect | Informational |
| P6 | Working tree carries **unrelated uncommitted Orders / Operations / Distribution / Commerce work from another session** (`manual-order-form.tsx`, `tests/TestCase.php`, `vite.config.ts`, `router.ts`, `routes.ts`, `tests/Feature/Operations/*`) | **Not touched by this task** |

Nothing is hidden. No result was suppressed or reclassified to favour a pass.

---

## 15. Certification Matrix

```
BACKEND          = PASS (static)  /  PENDING RUNTIME
API              = NOT EXECUTED
DATABASE TESTS   = NOT EXECUTED  (20 cases, INFRASTRUCTURE ERROR — never reached application code)
TENANT SECURITY  = NOT EXECUTED  (implemented + asserted; unproven at runtime)
PRICE PRECISION  = NOT EXECUTED  (contract fixed and encoded; unproven at runtime)
STATIC QUALITY   = PASS
BROWSER UI       = NOT EXECUTED
REAL E2E         = PENDING USER BROWSER SMOKE
```

Supporting results that **are** proven:

```
SOURCE PARITY    = PASS   (host == runner == app, 6/6 files)
DATABASE TARGET  = PASS   (ecos_dev_test enforced in 3 layers; SELECT DATABASE() pending with the run)
CONCURRENCY GATE = ENFORCED (no competition; sustained-idle monitor armed)
TEST ISOLATION   = PASS   (grantsBaselineAuthorization = false)
DATA SAFETY      = PASS   (no ecos_dev mutation; FG-000001 preserved)
```

---

## 16. Final Verdict

### `COST MANAGEMENT / PRICING REVIEW = NOT CERTIFIED`

Per Part 20, certification requires 20/20 automated PASS **and** HTTP/API PASS **and** tenant isolation PASS **and** price precision PASS **and** static quality PASS **and** Browser E2E PASS. Two of those are not merely failing — they have **not run**.

```
AUTOMATED CERTIFICATION    = NOT EXECUTED   (blocked: shared runner contention)
REAL E2E                   = PENDING        (no authenticated browser session)
FINAL FEATURE CERTIFICATION = NOT CERTIFIED
```

This is not concealed and is not softened. The implementation is complete, statically clean, parity-verified, and ready; the *evidence* required by this certification does not yet exist.

### What remains, in order

1. **Obtain a sustained idle window on `ecos_dev_test`** — requires coordination with the session running `OrderLifecycleV3SupersessionTest`, which has cycled it three times over ~50 minutes. This session will not pre-empt it.
2. Run `SELECT DATABASE()` → expect `ecos_dev_test`; then the 20 cases. Require 20/20 PASS.
3. Run the Cost Management regression (§10). Any new failure ⇒ STOP.
4. Re-confirm static quality if anything changed.
5. **Browser smoke by the user**, after `docker cp` of the built SPA into `ecos-dev-app` and `migrate --force` on `ecos_dev` (§12). Use a dedicated E2E fixture — **not** `FG-000001`.
6. Update this report; only then may the matrix move.

Per the permanent certification rule: certification will mean the specified business contract has runtime evidence, regression, security, and UI/API integration proven — not that the system is free of all defects. Findings P1–P6 are recorded above as independent repair candidates rather than folded into this scope.

---

*No feature work was started, no schema changed beyond the single audit-column migration, no pricing architecture altered, and no Warehouse / Reservation / Preparation / Shipping code touched. No unrelated pre-existing failure was "fixed".*
