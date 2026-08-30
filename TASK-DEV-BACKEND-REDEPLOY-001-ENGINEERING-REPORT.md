# TASK-DEV-BACKEND-REDEPLOY-001 — ENGINEERING REPORT

**Date:** 2026-08-19
**Scope:** Rebuild + recreate the **DEV backend** (`ecos-dev-app`) only, so the running container matches current on-disk code — then (owner-authorized exception) apply the single pending migration required to make the deployed product-create contract consistent with the DEV schema. DEV only. Production never touched.

## FINAL STATUS: **DEPLOYED + MIGRATION VERIFIED + UI VERIFIED**

Certification remains **DEFERRED**.

---

## 1. Pre-flight safety proofs

| Check | Result |
|---|---|
| DEV app live DB | `LIVE_DB = ecos_dev` |
| `ecos_erp` reachable from DEV | **NO** (not visible to the DEV connection) |
| Migrate/seed on container start | `MIGRATE_ON_START` unset → **false**; `SEED_ADMIN_ON_START` unset → **false** (recreation runs neither) |
| Git / worktree (deploy target) | branch `develop`, HEAD `abe4d10f`, worktree dirty (225 paths). Rebuild only *reads* disk — **no git change, nothing lost**. No `git reset/checkout/restore`. |

## 2. Image — before / after

| | Image tag | Image sha (short) |
|---|---|---|
| Tag `ecos-dev/app:latest` **before** | 7-days-old build | `b990badb71ce` |
| Running container **before** | (older than the tag) | `98f97eda7c78` |
| **After rebuild** | `ecos-dev/app:latest` | **`45dcc1b82842`** |

Build: `docker build -f docker/php/Dockerfile --target app -t ecos-dev/app:latest .` → exit 0. Frontend stage uses `npx vite build` (no `tsc`) so the pre-existing type baseline does not block it. `route:cache`/`event:cache` run at build; **no `artisan migrate` at build**.

## 3. Container — before / after

- **Before:** `ecos-dev-app` running sha `98f97e`, healthy, StoreProductRequest = stale (`unit_id` `sometimes|nullable`).
- **Recreate:** `docker compose -p ecos-dev -f docker-compose.yml -f docker-compose.override.yml up -d --no-deps --force-recreate app` (only `app`; mysql/redis/others untouched; no DB recreation).
- **After:** `ecos-dev-app` running sha **`45dcc1`**, **healthy**, `DB_DATABASE=ecos_dev`.
- **Production containers untouched** throughout: `ecos-app`, `ecos-mysql`, `ecos-redis`, `ecos-nginx` all "Up 2 days" (not recreated).

## 4. Database target
`ecos_dev` (running app + testrunner `ecos_dev_test` for the gated suite). `ecos_erp` unreachable from the DEV stack. No DB recreation, no data wipe.

## 5. StoreProductRequest runtime evidence (the core deliverable)

| | Rule at line 76 | Missing-unit HTTP |
|---|---|---|
| Running container **before** | `['sometimes','nullable','uuid','exists:units,id']` | **500** (DB NOT NULL) |
| Running container **after** | `['required','uuid','exists:units,id']` | **422** `"The unit id field is required."` |

The **running container itself** now carries the fix — proven at runtime, not by disk inspection.

## 6. Regression surfaced by the redeploy → owner-authorized migration

Deploying current disk code also activated product-create code that writes `products.company_id`, but `ecos_dev.products` had **no `company_id` column** → a **valid** create 500'd (`Unknown column 'company_id'`). Exactly one migration was pending: `2026_08_19_120000_converge_products_company_id`. Per the constraint conflict I **stopped and asked**; the owner authorized applying **only** that migration.

**Migration scope audit (read in full before running):** `up()` adds a **nullable** `products.company_id` (char 36) + index **only if absent** (idempotent); deterministic NULL-only backfill via `brand→company` and `inventory_items→company`; **no** NOT-NULL on runtime; **no** unrelated schema change; **no** data deletion. Clean `down()`. → within authorized scope; migration **not modified**.

**Run:** `php artisan migrate --force --path=…/2026_08_19_120000_converge_products_company_id.php` → `DONE (4s)`. Only this migration ran (0 pending before/after otherwise).

**Before → after schema evidence:**

| | `products.company_id` column | index | products backfilled | rows |
|---|---|---|---|---|
| Before | absent (0) | — | — | products 4 / orders 1 / units 3 |
| After | **present (1)** | **present (1)** | **all 4 = set** (0 NULL) | products 4 / orders 1 / units 3 (unchanged) |

Fresh DEV data integrity preserved — no rows added/removed by the migration; all four existing products attributed deterministically (finished goods via brand, materials via inventory).

## 7. Test result (gated, serialized on `ecos_dev_test`)
- `ProductUnitContractTest` → **9 / 9 OK** (valid unit clears; missing/null/unknown unit → 422 + no product persisted; existing rows unaffected).
- `+ ProductTenantIsolationTest` (ownership) → combined **17 tests / 37 assertions, OK**.

## 8. API health
`GET /api/health` → `status: ok`, `database/redis/queue/storage/scheduler: true`, `built_at 2026-08-19T09:10:34Z` (this rebuild), `queue_workers: 4`.

## 9. UI Product creation (real UI, real HTTP, real DB)
Created **Honey Gift Box (FG-GIFTBOX-01)** through `/app/products` → New Product.
- **HTTP:** captured payload carried `unit_id`; **`POST /api/products` → 201**.
- **DB:** `unit = Pieces` (unit_id persisted), `company_id = set → ECOS Holding 20` (persisted), category Beverages, brand ECOS Holding, channel ECOS Main Store, price 200.00.

## 10. Disk vs running container difference
**None remaining for the backend.** Before this task the running container was two image builds behind disk (stale `StoreProductRequest`, no `company_id` write); it now runs an image built from the current worktree, and the DEV schema was converged (via the one authorized migration) to match the deployed contract. Frontend continues to be served live by Vite (`5173`).

## 11. Constraint compliance
DEV only · production untouched (verified) · `ecos_erp` unreachable · **only** the one authorized pre-existing migration run (migration not modified) · no DB recreation · no fresh-data deletion (4/1/3 preserved) · no `routes/api.php` change · no VerifyPaymentAction change · no Warehouse Brand Coverage change · no Payment Contract change · no `git reset/checkout/restore` · no new migration authored. No commits.
