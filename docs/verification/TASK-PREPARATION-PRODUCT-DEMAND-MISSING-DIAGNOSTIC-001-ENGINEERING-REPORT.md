# TASK-PREPARATION-PRODUCT-DEMAND-MISSING-DIAGNOSTIC-001 — Engineering Report

**Date:** 2026-08-13 · **Branch:** `develop` · **HEAD:** `6149875b`
**Verdict:** **ROOT CAUSE PROVEN — INFRASTRUCTURE DEFECT, NOT A DATA OR UI DEFECT**
**Code changed: NONE.** Database access: read-only `SELECT`/`SHOW` on `ecos_dev`. No PHPUnit run (§20).

---

## 1 — Executive Summary

**The demand projection listener runs on a queue that has no worker. Twelve jobs are stranded in Redis.**

```
laravel_database_queues:demand            = 12 jobs waiting
laravel_database_queues:default           = 0
laravel_database_queues:health            = 0
laravel_database_queues:finance-posting   = 0
laravel_database_queues:engineering       = 0

supervisor workers:  queue=health,default · queue=finance-posting · queue=engineering
                     ^^^ no worker consumes `demand`
```

The data is entirely healthy: the wave has 3 orders, every order has line items. Nothing about
inventory, stock, recipes, zones, payment or order status is implicated. The chain breaks at exactly
one point — **event published → queue `demand` → no consumer → projection never built.**

This is the **same class of defect** as commit `6b02af60` ("give every dispatched queue a consumer"),
recurring for the `demand` queue.

**Blast radius is far wider than Product Demand: all 8 Enterprise Event Platform subscriptions run on
this one dead queue** (§7).

---

## 2 — Current Wave

| Field | Value |
|---|---|
| id | `019ff3a0-5be7-7218-960e-69403e24bf64` |
| wave_number | **PREP-202608-000001** |
| planning_date | 2026-08-12 |
| status | `preparing` |
| **orders_count** | **3** |
| products_count / lines_count / total_units_required | **0 / 0 / 0.0000** |
| created_at | 2026-08-12 01:40:00 |

The counters agree with the screen: 3 orders, 0 products.

---

## 3 — Orders Found (§3, §17)

All three memberships exist in `preparation_wave_orders`:

| Order | In Wave | Order status | Warehouse | Order lines | Qty |
|---|---|---|---|---|---|
| ORD-00001 | **YES** | `ready_for_dispatch` | assigned | **1** | 2.0000 |
| ORD-00003 | **YES** | `ready_for_dispatch` | assigned | **1** | 1.0000 |
| ORD-00004 | **YES** | `ready_for_dispatch` | assigned | **1** | 2.0000 |
| *(ORD-00002)* | not in wave | — | — | 1 | 1.0000 |

**Every order in the wave has line items.** This is therefore **not** an order-creation or data problem
(§3), and the diagnosis proceeds.

| Wave | wave_orders | preparation_wave_items | wave_product_demand | wave_kpis |
|---|---|---|---|---|
| PREP-202608-000001 | **3** | **0** | **0** | **0** |

---

## 4 — Generation Mechanism (§4, §5)

**Product Demand is a materialized read model, not a live computation.** That is the single most
important structural fact.

```
Order in wave
  → WaveMembershipService::attachOrder()/detachOrder()
      → DemandRefreshDispatcher::dispatch()
          → event DemandRefreshRequested
              → EventPlatformServiceProvider:155  Event::listen(... fn => $bus->publish($e))
                  → EnterpriseEventBus → QUEUE 'demand'          ◀── BREAK: no consumer
                      → DemandRefreshRequestedListener
                          → DemandProjectionBuilder
                              → ProductDemandCalculator  (reads preparation_wave_orders ⋈ order_lines)
                                  → DemandReadRepository::upsertProductDemand()
                                      → TABLE wave_product_demand
                                          → GET /preparation/waves/{id}/product-demand
                                              → DemandReadRepository::getProductDemand()
                                                  → Frontend
```

`ProductDemandCalculator` itself is correct — it reads `preparation_wave_orders` joined to
`order_lines` and `products`, with no stock, availability, recipe, zone or payment condition. Given
this wave it *would* produce the expected rows. **It is never invoked.**

`WaveDemandController::productDemand()` reads `wave_product_demand` (via
`DemandReadRepository::getProductDemand()`) — it does not compute. With the table empty it correctly
returns `[]`.

---

## 5 — Exact Break Point (§5, §16)

The wiring is **present and correct**:

```php
// EventPlatformServiceProvider.php:155
Event::listen(DemandRefreshRequested::class, fn (DemandRefreshRequested $e) => $bus->publish($e));

// EventPlatformServiceProvider.php:132
$bus->subscribe('preparation.wave.demand_refresh_requested', DemandRefreshRequestedListener::class,
    RetryPolicy::standard(), priority: 10, queue: 'demand');
```

The event fires, the bus publishes, the subscription exists, the listener class exists. **The queue has
no consumer.**

Proof — the stranded jobs are real and are the Enterprise Event Platform's own handler:

```
LLEN laravel_database_queues:demand            → 12
ZCARD laravel_database_queues:demand:delayed   → 0
ZCARD laravel_database_queues:demand:reserved  → 0

LINDEX laravel_database_queues:demand -1
{"uuid":"617b0572-…","displayName":"Modules\\Platform\\EventPlatform\\Application\\Jobs\\HandleEnterpriseEventJob",
 "job":"Illuminate\\Queue\\CallQueuedHandler@call","maxTries":5,"backoff":"5,30,300,3600","timeout":30, …}
```

0 delayed and 0 reserved: the jobs are not failing, not retrying, not in flight. They are simply
sitting in the ready list because nothing is listening.

`docker/php/supervisord.conf` defines exactly three workers:

```
queue=finance-posting
queue=engineering
queue=health,default
```

**`demand` is absent.**

---

## 6 — API and Frontend (§8, §9)

**API is behaving correctly.** `GET /preparation/waves/{waveId}/product-demand` →
`WaveDemandController::productDemand()` → `getProductDemand()` → empty table → genuinely returns `[]`.
There is no wrong property, no over-filter, no status exclusion, no stage gate. The endpoint is a
faithful reporter of an empty projection.

**Frontend is behaving correctly.** The message is the static i18n string
`operations.json:172 → "No product demand data yet. Generate demand first."`, rendered on an
empty-data condition — **not** a failed query, not a shape mismatch, not a mapper bug.

So neither §8 nor §9 contains the defect. Both correctly report upstream emptiness.

---

## 7 — Blast Radius: every event-platform subscription is dead

All subscriptions in `EventPlatformServiceProvider` use `queue: 'demand'` — 9 occurrences, 8 distinct
listeners. **All are currently unreachable:**

| Event | Listener | Consequence today |
|---|---|---|
| `preparation.wave.demand_refresh_requested` | `DemandRefreshRequestedListener` | **Product/Material/Missing demand never projected** |
| `preparation.wave.order_added` | `OrderAddedToWaveListener` | attaching an order updates no demand |
| `preparation.wave.order_removed` | `OrderRemovedFromWaveListener` | removing/**postponing** updates no demand |
| `preparation.wave.created` | `WaveCreatedListener` | wave creation reactions dead |
| `preparation.wave.closed` | `WaveClosedListener` | wave closure reactions dead |
| `preparation.wave.order_moved_to_preparing` | `OrderMovedToPreparingListener` | dead |
| `manufacturing.production_job.completed` | `ManufacturingCompletedListener` | dead |
| `inventory.goods_receipt.completed` | `GoodsReceiptCompletedListener` | dead |

> **Cross-task consequence, disclosed.** TASK-PREPARATION-WAVE-ORDERS-WORKSPACE-REFINEMENT-002's
> `postponeOrder()` calls `demandDispatcher->dispatch(...)` to refresh demand. **That refresh depends on
> this same dead queue.** The postponement itself is synchronous and correct (the row is stamped and
> the aggregation queries filter it directly), but its demand *re-projection* will not run until the
> `demand` queue has a consumer. This is recorded here rather than left for someone to rediscover.

---

## 8 — What is NOT the cause (§10–§13, §19)

Each ruled out by evidence, not assumption:

| Hypothesis | Verdict |
|---|---|
| Orders missing from the wave | **NO** — 3 membership rows confirmed |
| Orders have no items | **NO** — every order has line items |
| Order status filtering (§10) | **NO** — `ProductDemandCalculator` has no status predicate |
| `postponed_at` exclusion (§11) | **NO** — the column does not yet exist in `ecos_dev`; the migration was never run |
| Reservation / inventory dependency (§12, §19) | **NO** — the calculator joins only `preparation_wave_orders`, `order_lines`, `products` |
| Stock / `allow_negative_stock` / recipe availability (§13) | **NO** — none appears in the demand query. **No contract violation found here** |
| Delivery zone / payment / governorate / shipping (§19) | **NO** — absent from the query |
| Frontend mapper or query failure (§9) | **NO** — correct empty-state on genuinely empty data |
| API filter or wrong property (§8) | **NO** — endpoint faithfully reads an empty table |

---

## 9 — Manual vs Automatic Generation (§6, §15) — CONTRACT IS CLEAR

**Automatic generation is the designed behaviour.** The architecture wires demand refresh to wave
membership events (`order_added`, `order_removed`, `demand_refresh_requested`, `wave.created`) — an
Order-driven projection exactly as the Preparation architecture intends.

A manual `POST waves/{waveId}/generate-demand` (`GenerateDemandAction`) also exists, but it is an
explicit *re-generate*, not the primary path — the automatic subscriptions would be pointless if a
button were the intended trigger.

**Therefore §15 applies: do not "fix" this by adding or promoting a button.** The UI string "Generate
demand first" is a hint written for the empty state; it is not evidence of a manual-only design. The
seam to repair is the missing queue consumer.

**No STOP condition is triggered** — the contract is determinable from source and no ADR conflict was
found.

---

## 10 — Regression Classification (§18)

**INFRASTRUCTURE / CONFIGURATION regression — not a code regression.**

No application code is wrong. `ProductDemandCalculator`, `DemandProjectionBuilder`,
`DemandReadRepository`, the controller and the frontend are all correct and would work unchanged the
moment the queue is consumed.

The precedent is documented: commit **`6b02af60` — "give every dispatched queue a consumer"** fixed
this exact class of defect for other queues. The `demand` queue was either never added to
`supervisord.conf` or was lost. Twelve stranded jobs show the publisher side has been working all
along.

Not caused by the recent Order/Fulfillment/Preparation work, and not caused by the postponement task —
both post-date the stranded jobs' mechanism.

---

## 11 — Recommended Minimal Fix (§15) — NOT APPLIED

**One supervisor program.** No application code, no schema, no workflow change, no new button:

```ini
[program:queue-demand]
command=php /var/www/html/artisan queue:work redis --queue=demand --sleep=3 --tries=3 --max-time=3600 --timeout=300
user=www-data
numprocs=1
autostart=true
autorestart=true
```

On start, the 12 stranded jobs drain and `wave_product_demand` populates for
`PREP-202608-000001` — no manual regeneration needed.

**Expected result once consumed**, from the real data:

| Product | Required | Orders |
|---|---|---|
| (ORD-00001's product) | 2 | 1 |
| (ORD-00003's product) | 1 | 1 |
| (ORD-00004's product) | 2 | 1 |

Grouped by `product_id`; identical products across orders would merge, per the calculator's
`groupBy('ol.product_id', …)`.

**Verification before declaring it fixed:** confirm `LLEN laravel_database_queues:demand` falls to 0,
`wave_product_demand` gains rows, and the screen populates **without** anyone pressing Generate Demand.

**Deliberately not done in this task** (§21 forbids changes): I did not add the worker, did not run
`queue:work` manually, did not drain or delete the queue, and did not touch `ecos_dev`.

---

## 12 — Dependencies on Other Domains

None. The fix is confined to queue infrastructure. It requires no change to Order lifecycle,
Reservation, Inventory, Wave lifecycle or any ADR — so **no STOP condition applies**.

Note the fix also revives Manufacturing-completed and Goods-receipt-completed reactions (§7), which is
desirable but widens the runtime surface — worth watching on first drain.

---

## 13 — Test Plan

1. Add the `demand` worker; assert `LLEN laravel_database_queues:demand` → 0.
2. Assert `wave_product_demand` has rows for `PREP-202608-000001` matching §11.
3. Feature test: attach an order to a wave, run the queue, assert demand rows appear — pinning the
   automatic contract so a regression cannot silently return to "press the button".
4. Guard test: assert every queue named in `EventPlatformServiceProvider` subscriptions has a
   corresponding `supervisord.conf` worker — this defect class recurs and deserves a ratchet.
5. Postpone interaction: after the queue is consumed, assert a postponed order's demand is removed on
   re-projection (§7 cross-task note).

**Not executed** — `ecos_dev_test` is occupied by another agent's PHPUnit (§20). No suite was run, no
process killed, no `migrate:fresh`.

---

## 14 — Certification Status

> # DIAGNOSTIC COMPLETE — ROOT CAUSE PROVEN. NO FIX APPLIED.

| Item | Status |
|---|---|
| Root cause | **PROVEN** — `demand` queue has no consumer; 12 jobs stranded |
| Layer | **Infrastructure / configuration** (`supervisord.conf`) |
| Application code | **correct as written** — no defect found |
| Data | **healthy** — 3 orders, all with line items |
| Regression class | infrastructure, same class as `6b02af60` |
| STOP conditions | **none triggered** |
| Code changed | **NONE** |
| `ecos_dev` | **unmodified** |

The break is at the seam, exactly as the Final Principle requires: Order ✅ → Order Items ✅ → In Wave
✅ → **Demand generation ❌ (queue not consumed)** → Product Demand ✗ → Preparation ✗ → Raw Material
Demand ✗. Repairing the consumer restores the whole chain without touching the Product Demand screen.
