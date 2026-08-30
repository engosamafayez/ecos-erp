# TASK-PREPARATION-WAVE-ORDERS-END-TO-END-REPAIR-001 — Engineering Report

**Date:** 2026-08-13 · **Branch:** `develop` · **HEAD:** `6149875b`
**Verdict:** **PARTIALLY CERTIFIED** — Product Demand pipeline **CERTIFIED (runtime proven)**;
postponement leg **NOT CERTIFIED — RUNTIME BLOCKED**.

---

## 1 — Executive Summary

**The Product Demand pipeline is fixed and proven end to end at runtime.** A `demand` queue worker was
added; the 12 stranded jobs drained to 0; the projection built and matches the canonical source
exactly; the API returns it.

**The `OrderProducts` crash was not a frontend bug.** It was **deployment drift** — the browser ran the
new bundle against an old backend that had no `products` key, so the component received `undefined`.
Per Part 8 I did **not** paper over it with `?.length` or `?? []`; I fixed the contract by deploying
the backend. The API now returns `products` for every order.

**A ratchet now prevents recurrence.** A guard test asserts every queue the Enterprise Event Platform
subscribes to has a supervisor consumer — and it was **proven to fail against the pre-fix config**,
so it is not a vacuous pass.

**Not certified:** the postponement leg. The 8-test `WavePostponeOrderTest` could not run
(`ecos_dev_test` occupied by another agent all session), and I declined to postpone a live `ecos_dev`
order because the operation is **irreversible** — there is no re-entry mechanism by design (§13), so
undoing it would require direct SQL. That needs your say-so, not mine.

---

## 2 — Original Symptoms

1. Product Demand empty despite 3 orders in the wave; UI showed "Generate demand first".
2. `TypeError: Cannot read properties of undefined (reading 'length')` at
   `wave-orders-page.tsx:98:15` in `OrderProducts`.

Both are now explained by a single theme — **runtime did not match the repository** — and neither was
a defect in application logic.

---

## 3–5 — Demand Queue: root cause, missing consumer, fix

**Root cause (carried and confirmed):** every Enterprise Event Platform subscription dispatches to
queue `demand`, and no supervisor program consumed it. 12 `HandleEnterpriseEventJob` jobs sat in Redis
with 0 delayed and 0 reserved — not failing, simply unconsumed.

**Fix — one supervisor program**, matching the file's existing convention exactly (same flags,
`autorestart`, log wiring; `timeout=300`/`stopwaitsecs=310` mirroring the finance and default workers,
since projection rebuilds are short rather than pipeline-length):

```ini
[program:laravel-queue-demand]
command=php /var/www/html/artisan queue:work redis --queue=demand --sleep=3 --tries=3 --max-time=3600 --timeout=300
user=www-data
numprocs=1
autostart=true
autorestart=true
stopwaitsecs=310
priority=21
```

No frontend polling, no manual Generate button, no synchronous calculation from React, no EventBus
bypass, no moving `DemandProjectionBuilder` into an HTTP request. The seam was repaired where it broke.

**Installed and running** (the config is image-baked, so it was copied in and supervisor reloaded):

```
supervisorctl reread → laravel-queue-demand: available
supervisorctl update → laravel-queue-demand: added process group
supervisorctl status → laravel-queue-demand   RUNNING   pid 131015, uptime 0:11:32
```

---

## 6 — Verification that the queue actually drains (Part 6, Part 23)

"Worker started" was explicitly **not** treated as success:

| Check | Before | After |
|---|---|---|
| `LLEN laravel_database_queues:demand` | **12** | **0** |
| `ZCARD …:demand:reserved` / `:delayed` | 0 / 0 | 0 / 0 |
| `wave_product_demand` rows | **0** | **1** |
| `wave_kpis` rows | 0 | 2 |
| `failed_jobs` **on queue `demand`** | 0 | **0** |

**The projection is correct, not merely non-empty.** Compared against the canonical source:

```
wave_product_demand : FG-000001  required_qty 5.0000  orders_count 3
canonical source    : FG-000001  required     5.0000  orders       3
                      (SELECT … FROM preparation_wave_orders ⋈ order_lines ⋈ products)
```

All three orders carry the same product (2 + 1 + 2 = 5), so **one** row with `required 5, orders 3` is
exactly right.

**API layer confirmed** (read-only, no token created):
`DemandReadRepository::getProductDemand()` → `API rows=1 · FG-000001 required=5 orders=3`.

**`failed_jobs` did not increase because of this worker** (Part 23.4). All 269 pre-existing failures
are on `health` (148), `default` (119) and `engineering` (2) — **zero on `demand`**, and zero in the
10 minutes around the drain. Those 269 are a pre-existing condition on other queues, outside this
task.

---

## 7 — Wave Orders API contract (Part 7)

Live response, real data:

```json
{"order_number":"ORD-00004","customer_name":"OSAMA FAYEZ AHEMD","delivery_zone":null,
 "products":[{"product_id":"019faef5-…","name":"عسل الصال كيلو","sku":"FG-000001","quantity":2}]}
{"order_number":"ORD-00003","customer_name":"OSAMA FAYEZ AHEMD","delivery_zone":null,
 "products":[{…"quantity":1}]}
{"order_number":"ORD-00001","customer_name":"أحمد محمد","delivery_zone":null,
 "products":[{…"quantity":2}]}
```

| Contract element | Source | Result |
|---|---|---|
| Order Number | `preparation_wave_orders.order_number` | ✅ |
| Customer Name | **`orders.customer_name`** (canonical, not a duplicated snapshot) | ✅ |
| Distribution Zone | `orders.logistics_city_id → logistics_cities.distribution_zone_id → distribution_zones` | ✅ `null` → "Unassigned" |
| Order Products | **`order_lines ⋈ products`** — one query per page, grouped by order | ✅ |

**`wave_items` is deliberately not used** for per-order products — it is a wave-level aggregate and
cannot answer "what is in *this* order" (§9).

**Distribution Zone is `null` for all three orders, and that is correct.** `orders.logistics_city_id`
is NULL on every order, so the canonical chain is unresolvable. The free-text values *do* exist
(`delivery_zone_snapshot` = "Shubra" / "Maadi") and are deliberately **not** substituted — §11 forbids
free text, `master_zones` and governorate as stand-ins. The column will populate once
`logistics_city_id` is backfilled.

---

## 8–9 — The `OrderProducts` crash: exact runtime root cause

**Root cause: deployment drift, not a frontend defect.**

| Question (Part 8) | Answer |
|---|---|
| Which variable is undefined? | `products` — the prop of `OrderProducts` |
| Where does it come from? | `o.products` from `GET /preparation/waves/{id}/orders` |
| Which API property feeds it? | `products` |
| **Did the API return it?** | **No.** The controller deployed to `ecos-dev-app` was the pre-REFINEMENT-002 version — `grep` showed only `customer_name_snapshot` and `delivery_zone_snapshot`, **no `products` key** |
| Does a mapper rename it? | No — the service passes the payload through unchanged |
| Does the TS type mismatch? | No — the type declares `products: WaveOrderProduct[]`, matching the *intended* contract |
| Different endpoint? | No — Product Demand and Wave Orders are distinct endpoints; the crash is on Wave Orders |

Drift, measured:

```
WaveDemandController.php   host=9f923bfc  app=52c5f9f4   DRIFT
WaveMembershipService.php  host=5440f08f  app=7241a7d8   DRIFT
PreparationWaveOrder.php   host=dc207be9  app=0dd213a6   DRIFT
```

**Fix: deploy the backend** — 11 files copied to `ecos-dev-app`. The API now returns `products` for
every order, so the prop is always an array.

**No defensive fallback was added** (Part 8, Part 18). `OrderProducts` still reads `products.length`
directly and the type keeps `products` required and non-optional, so a future contract break fails
loudly instead of being masked. The empty state renders only for a canonical `[]`.

---

## 10–11 — UI and Distribution Zone

Delivered in REFINEMENT-002 and unchanged here: tab renamed to **"طلبات" / "Orders"**; table is
**Order # · Customer · Distribution Zone · Products · Actions**; Paid, Created At and Governorate
removed; Product Demand KPI cards (Paid / Completion % / Missing Matls) and the operational summary
row removed; Distribution Zone canonical-only with "Unassigned" fallback.

---

## 12–14 — Postponement

The REFINEMENT-002 design is retained and matches every §13 criterion: nullable column, indexed, no
existing column altered, no `Order` write, idempotent (the `whereNull('postponed_at')` inside the
UPDATE *is* the guard), prevents automatic re-attachment, and emits the event at most once.

**Migration applied surgically to `ecos_dev`** — deliberately **not** a bare `php artisan migrate`,
because that would also have applied another agent's pending
`2026_08_13_100000_supersede_order_lifecycle_v3_canonical`:

```
php artisan migrate --path=…/2026_08_13_100000_add_postponed_at_to_preparation_wave_orders.php --force
  → 2026_08_13_100000_add_postponed_at_to_preparation_wave_orders  243.05ms DONE

tables       556 → 556      (a column, not a table)
migrations   706 → 707      (exactly +1 — mine)
orders         4 → 4        (unchanged)
postponed_at  timestamp NULL  ✅
other agent's migration → still Pending ✅ untouched
```

**§14 — postpone → demand refresh — NOT PROVEN AT RUNTIME.** The wiring is now sound (the queue has a
consumer, and `postponeOrder()` calls `demandDispatcher->dispatch()`), but I did not execute it
because **postponement is irreversible by design**: §13 forbids inventing a re-entry mechanism, and
none exists, so undoing a postponement on a live `ecos_dev` order would require direct SQL — which
Part 21 forbids. **This needs your authorisation, or a free test runner.**

---

## 15 — Shipping / Loading exclusion (Part 17)

`AutoAllocationService` uses the shared `PreparationWaveOrder::scopeActive()` rather than repeating the
predicate, so postponed orders are excluded from loading/shipping allocation through one shared scope.
Verified statically; not exercised at runtime for the same reason as §14.

---

## 16 — Tests

| Suite | Status |
|---|---|
| `EventPlatformQueueConsumerTest` (3 tests, dead-queue ratchet) | **PASS 3/3, 7 assertions — RUN** |
| `WavePostponeOrderTest` (8 tests) | **NOT RUN** — runner occupied all session |

**The ratchet was proven to bite.** Run against the HEAD (pre-fix) `supervisord.conf` it fails with:

> `Queue(s) [demand] are subscribed by the Enterprise Event Platform but no supervisor worker consumes
> them. … Consumed today: [finance-posting, engineering, health, default].`

and passes against the fixed config. It also carries a self-check
(`test_the_event_platform_subscribes_to_at_least_one_queue`) so a regex that stops matching cannot make
it pass vacuously. This suite is DB-free — no `RefreshDatabase` — so running it did not touch the
shared schema.

Part 19 items 1, 3, 4, 5 are proven by the runtime evidence in §6–§7. Items 8–14 depend on the
postpone suite (§14). Items 6, 7, 15, 16 are frontend-level and covered by the contract fix plus the
strict type.

---

## 17 — Runtime E2E

**Proven:**

```
Order in Wave → DemandRefreshDispatcher → DemandRefreshRequested → EnterpriseEventBus
 → demand queue → DEMAND WORKER ✅ → DemandRefreshRequestedListener → DemandProjectionBuilder
 → wave_product_demand ✅ (FG-000001, 5, 3) → API ✅ (rows=1)
```

**Not proven:** the postpone half (`postpone → postponed_at → demand refresh → projection updated →
UI/shipping updated`) — see §14.

---

## 18 — Static Verification

| Check | Result |
|---|---|
| PHPStan L0 | **[OK] No errors** |
| PHPStan core L6 | **[OK] No errors** |
| Pint (this task's files) | **passed** (one `single_quote` fix applied to my own new test) |
| TypeScript | **24 errors = the documented pre-existing baseline**; **0 in my files** |
| ESLint (changed frontend files) | **clean** |
| Vite build | **✓ built in 11.00s** |

---

## 19 — Database Safety

No `migrate:fresh`, no truncate, no reset, no invented inventory rows, no order mutated to make
anything pass. MAIN / `ecos_erp` never connected to. The only `ecos_dev` write was the single
surgical migration in §12, with before/after counts recorded. No fixtures were created in `ecos_dev`.

The queue drain consumed pre-existing jobs — that is the system doing the work it was always meant to
do, not a data mutation by me.

---

## 20 — Deployment Drift (Part 24)

| Artefact | State |
|---|---|
| Backend code in `ecos-dev-app` | **was drifted → now deployed (11 files)** |
| `postponed_at` migration | **was absent → now applied** |
| `demand` worker | **was absent → now RUNNING** |
| **Frontend bundle** | **DRIFTED — deliberately not deployed** |

Host `public/app/index.html` = `b77b3da6…`, container = `9c0aff66…` (21:33). **I did not push my
build.** `vite build` compiles the whole working tree, which currently contains **other agents'
uncommitted frontend work** — deploying it would have shipped their unreviewed changes. The container's
existing bundle already contains the `OrderProducts` component (it is what produced the crash), and the
crash cause was the backend, now fixed. Flagged for your decision rather than acted on.

---

## 21 — Scope

**Changed by this task (3 files):**
- `docker/php/supervisord.conf` — the `demand` worker
- `backend/tests/Feature/Platform/EventPlatformQueueConsumerTest.php` — new ratchet
- this report

**Deployed (not modified) to `ecos-dev-app`:** the 11 REFINEMENT-002 backend files + migration.

`ProductDemandCalculator` was **not** touched (Part 16) — no stock, availability, recipe, payment,
zone or status check was added. No business rule in Inventory, Availability, Reservation, Recipe,
Manufacturing, Order lifecycle/status or Wave eligibility was changed (Part 1).

The working tree also carries ~19 files from a concurrent agent's Order/Preparation/Distribution work.
**None was touched or reverted.**

---

## 22 — Known Pre-existing Issues (not repaired)

1. **269 failed jobs** on `health`/`default`/`engineering`. None on `demand`. Untouched — they predate
   this work and deserve their own task.
2. **`orders.logistics_city_id` NULL on all orders**, so Distribution Zone reads "Unassigned"
   everywhere. Correct behaviour; needs a data backfill.
3. **TypeScript baseline 24** — unchanged.
4. **Pint failures** in `MoveToPreparationWorkflow`, `BranchAssignmentEngine`,
   `CoverageResolutionService`, `routes/api.php` — all other agents' files or proven pre-existing.

---

## 23 — Certification Verdict

> ## PRODUCT DEMAND PIPELINE = **CERTIFIED**
>
> Runtime-proven: worker running · queue 12 → 0 · projection built and matching the canonical source
> exactly · API returning it · zero failures on the queue · ratchet in place and proven to bite.

> ## WAVE ORDERS API + ORDERPRODUCTS CRASH = **CERTIFIED**
>
> Runtime-proven: root cause identified as deployment drift, backend deployed, live response verified
> to carry `customer_name` and a populated `products[]` for all three orders — with **no** frontend
> fallback masking anything.

> ## POSTPONEMENT = **NOT CERTIFIED — RUNTIME BLOCKED**
>
> `WavePostponeOrderTest` (8 tests) could not run: `ecos_dev_test` was occupied by another agent's
> PHPUnit for the entire session, and Part 0 forbids running `RefreshDatabase` concurrently or killing
> that process. The postpone → demand-refresh E2E (§14) and the shipping-exclusion check (§15) were
> not executed because postponement is **irreversible by design** and reversing it on live `ecos_dev`
> data would require direct SQL.

**To close the remaining gap — one clean pass on a free runner:**
1. `WavePostponeOrderTest` — 8 tests.
2. Preparation / Wave / Product Demand / Distribution regression suites.
3. E2E: postpone an order → confirm the demand job is consumed, `wave_product_demand` drops the order,
   and loading allocation excludes it — all without a manual refresh.

**Decision needed from you:** may I postpone a live `ecos_dev` order to prove §14 end to end, accepting
that it cannot be undone without direct SQL? Otherwise this waits for the runner.
