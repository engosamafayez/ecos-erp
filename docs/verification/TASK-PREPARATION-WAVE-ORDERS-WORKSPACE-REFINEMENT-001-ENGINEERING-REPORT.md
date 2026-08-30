# TASK-PREPARATION-WAVE-ORDERS-WORKSPACE-REFINEMENT-001 — Engineering Report

**Date:** 2026-08-13 · **Branch:** `develop` · **HEAD:** `6149875b`
**Verdict:** **NOT CERTIFIED — STOPPED AT §31 / §32 GATES**
**Production code changed: NONE.** Database access: read-only, `ecos_dev` only.

---

## 1 — Scope

The task has two halves:

- **Cosmetic** (§1–3, §9–11, §26): rename the tab, remove three KPI cards, remove the operational
  summary row, drop Payment / Governorate / Created At.
- **Functional** (§7, §8, §12–24): Delivery Zone from the canonical Distribution relation, an order
  Products column, and a real **Postpone** domain operation.

The cosmetic half is unblocked. **The functional half hits four independent STOP conditions**, so
nothing was implemented — §31 instructs not to invent a solution, and shipping the cosmetic half alone
would leave a table missing two of its five specified columns and its primary action.

---

## 2 — Files Changed

**NONE.** No TS/TSX, PHP, route, migration, config or data. The only artefact is this report.

---

## 3 — STOP Conditions Encountered

### STOP 1 — Postponement cannot persist (§31 "التأجيل يتطلب اختراع جدول أو lifecycle جديد")

A domain mechanism to remove an order from a wave **does exist** and is well-formed:

```php
// WaveMembershipService::detachOrder() :139-166
$deleted = PreparationWaveOrder::where('preparation_wave_id', $wave->id)
    ->where('order_id', $orderId)
    ->delete();

if ($deleted > 0) {
    event(new OrderRemovedFromWave(...));
    $wave->decrement('orders_count');
    $this->demandDispatcher->dispatch($wave, 'order_removed', $actorId);
}
```

This satisfies a great deal of the brief on its own: it touches **only** the membership row (§16, §28),
never `orders`; it changes **no** `OrderStatus` (§24); it emits a domain event; and it dispatches a
demand refresh, which is the canonical route for §22.

**But it cannot express postponement.** The collector re-selects orders like this:

```php
// WaveMembershipService::attachEligibleOrders() :37-45
$orders = Order::where('company_id', $wave->company_id)
    ->where('assigned_warehouse_id', $wave->warehouse_id)
    ->whereIn('status', $config->eligible_order_statuses)
    ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
        ->from('preparation_wave_orders')
        ->whereColumn('preparation_wave_orders.order_id', 'orders.id'))
    ->get();
```

That `whereNotExists` is scoped by **neither wave, nor status, nor date**. So the moment `detachOrder`
deletes the row, the order becomes eligible again — and `wave:run-scheduler` runs **every minute**
(`* * * * * wave:run-scheduler`, confirmed in `artisan schedule:list`).

> **A postponed order would be re-attached to the same wave within 60 seconds.**

There is no `postponed_at`, no exclusion table, no "not before" marker anywhere in the Preparation
schema. Making postponement stick requires **inventing** a column, table or lifecycle — which §31
explicitly forbids. Shipping the button as-is would produce an action that appears to succeed and
silently reverts, which is worse than not shipping it.

This is also the §17 gate from the opposite direction: the brief says do not invent a re-entry policy,
and here the problem is that re-entry is **immediate and unconditional**, with nothing to suppress it.

### STOP 2 — No usable canonical Distribution Zone relation (§31.1)

§7 requires Delivery Zone to come from the **Distribution Zones canonical relationship**, and forbids
Governorate, City or free text.

What Preparation actually carries on `preparation_wave_orders`:

| Column | Type | Verdict |
|---|---|---|
| `delivery_zone_snapshot` | `varchar(100)` | **free text — forbidden by §7** |
| `zone_code_snapshot` | `varchar(20)` | free text |
| `master_zone_id` | `char(36)` | points at **`master_zones`**, a *different* system from `distribution_zones` |
| `governorate_snapshot`, `master_governorate_id` | — | forbidden by §7/§11 |

The canonical Distribution chain — certified in TASK-SHIPPING-DISTRIBUTION-API-COMPLETION-002 — is
`orders.logistics_city_id → logistics_cities.distribution_zone_id → distribution_zones.name`. It exists,
but it **has no data for these orders**:

```
ORD-00001  logistics_city_id = NULL   governorate = (arabic)  delivery_zone = NULL
ORD-00002  logistics_city_id = NULL   governorate = NULL      delivery_zone = NULL
ORD-00003  logistics_city_id = NULL   governorate = Cairo     delivery_zone = 'Maadi'
ORD-00004  logistics_city_id = NULL   governorate = Cairo     delivery_zone = 'Shubra'

logistics_cities : 211 rows, only 31 carry a distribution_zone_id
distribution_zones: 3 rows
```

**`logistics_city_id` is NULL on 100% of orders**, so the canonical relation resolves to nothing and
every row would render "Unassigned" — the column would be permanently empty.

The values you want to see (`Maadi`, `Shubra`) exist **only** in the free-text `delivery_zone` column,
which §7 forbids using. So the requirement cannot be met from either source: the permitted source has
no data, and the source with data is not permitted.

### STOP 3 — The API exposes neither Customer nor per-order Products (§6, §8)

`PreparationWaveResource:87-103` — the entire per-order payload:

```
id · order_id · order_number · delivery_zone_snapshot · delivery_window_id ·
delivery_window_label · delivery_window_starts_at · delivery_window_ends_at ·
governorate_snapshot · zone_code_snapshot · shipping_cost_snapshot ·
preparation_priority · is_paid · added_at
```

- **No customer name** → §6 cannot be rendered.
- **No per-order line items** → §8 cannot be rendered. `wave_items` (`:106+`) exists but is a
  **wave-level aggregate** (product × total quantity across the whole wave), not "what is in *this*
  order". Deriving per-order products from it is impossible.

Adding these is a backend read-model change, which is outside the "UI/UX + postponement behaviour"
scope this task defines.

> Note the shape of the payload: it is built around `is_paid`, `governorate_snapshot`,
> `shipping_cost_snapshot` and `added_at` — precisely the fields §9–11 ask to remove — and carries
> none of the fields §6–8 ask to add. The requested table is close to the inverse of what the API
> currently serves.

### STOP 4 — Verification impossible (§32)

`ecos-dev-testrunner` has **2 PHPUnit processes** with an actively executing `ecos_dev_test`
connection, checked at the start of this task and again before this report.

§32 says STOP and wait, do not run `RefreshDatabase` in parallel, and do not kill another task's
process. **No suite was run and none was started.** No `migrate:fresh` was used.

---

## 4 — Postpone Semantics (as far as they could be established)

| Requirement | Status against existing domain |
|---|---|
| §13 removes order from current cycle | `detachOrder` does this — **but only until the next scheduler tick** |
| §14 real domain operation, not UI filtering | ✅ `detachOrder` + `OrderRemovedFromWave` event |
| §16 Order/history/products/totals preserved | ✅ only the membership row is deleted |
| §24 no `OrderStatus` change | ✅ `detachOrder` never touches status |
| §22 leaves current Product Demand | ✅ via `demandDispatcher->dispatch($wave, 'order_removed')` |
| §28 no `DELETE` on Order | ✅ deletes `preparation_wave_orders` only |
| §19 idempotent | ✅ returns `false` when 0 rows deleted |
| **§13/§15 postponement persists** | ❌ **re-attached within 60s** — STOP 1 |
| §23 leaves Shipping/Distribution aggregation | ⚠️ **unverified** — `OrderRemovedFromWave` has no Distribution consumer that I could confirm; §23's seam is unproven |

So roughly 7 of 9 requirements are already satisfied by existing code. The two that fail are the two
that define what "postpone" *means*.

---

## 5 — Order Lifecycle Safety

Confirmed safe in the existing mechanism: `detachOrder` performs no `orders` write of any kind — no
status change, no `cancelled`, no `awaiting_stock`, no `new`. §24 would be honoured automatically.

## 6 — Database Safety

No write of any kind. No migration, no seed, no `migrate:fresh`, no deletion. MAIN / `ecos_erp` never
connected to. `ecos_dev` unchanged.

## 7 — Tests / Runtime Verification / Regression

**None run** — STOP 4. No claim is made. The §29 matrix is unwritable until STOP 1–3 are resolved,
since 12 of its 20 items assert behaviour that does not yet exist.

---

## 8 — Decisions Required

**D-1 — How should postponement persist?** This is the blocking decision. `detachOrder` alone is
undone by the next scheduler tick. Options, none of which I may choose unilaterally:
- **(a)** Add a suppression marker (e.g. `preparation_wave_orders.postponed_at` retained instead of
  deleted, with `attachEligibleOrders` honouring it). Minimal and reuses the existing table — **my
  recommendation** — but it *is* a schema addition, which §31 lists as a stop.
- **(b)** Scope the collector's `whereNotExists` by wave/date so a detached order is not re-collected
  into the *same* wave. No schema change, but it alters certified collector behaviour.
- **(c)** Defer postponement entirely; ship only the cosmetic half.

**D-2 — Delivery Zone source.** The canonical relation has no data (`logistics_city_id` NULL on every
order) and the populated field is free text, which §7 forbids. Either backfill
`orders.logistics_city_id`, or grant an explicit exception to read `delivery_zone_snapshot` as an
interim, or accept a permanently "Unassigned" column.

**D-3 — Customer and Products columns.** Both require extending the wave read model. Authorise that
backend change, or drop the two columns from the target table.

**D-4 — Partial delivery?** The cosmetic half (§1–3, §9–11) is unblocked and independent. Ship it now
as a visible improvement, or hold everything until D-1–D-3 land? I did not ship it unilaterally,
because it would leave the table missing two of its five specified columns and its primary action.

---

## 9 — Final Certification

> # NOT CERTIFIED — STOPPED AT §31 / §32 GATES

Nothing was implemented and nothing was invented. Four STOP conditions were hit, three of them
functional and one environmental:

1. **Postponement cannot persist** — the collector re-attaches within 60 seconds; suppressing it
   requires inventing a column/table/lifecycle (§31).
2. **No usable canonical Distribution Zone** — `logistics_city_id` NULL on 100% of orders; the only
   populated field is free text, forbidden by §7 (§31.1).
3. **Customer and per-order Products are absent from the API** (§6, §8).
4. **Test runner occupied** — no suite run, no process killed (§32).

**The good news:** `detachOrder` already satisfies most of the postpone contract — event-driven, no
`Order` write, no status change, idempotent, and it triggers the canonical demand refresh. Only
*persistence* of the postponement is missing. Answer **D-1** and this becomes a contained change rather
than a redesign.

No ADR was modified. `ADR-027`, `ADR-010` and `ADR-015` were not touched, and no Wave lifecycle was
redefined.
