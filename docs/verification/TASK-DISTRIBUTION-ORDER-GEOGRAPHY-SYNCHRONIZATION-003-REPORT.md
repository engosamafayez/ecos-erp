# TASK-DISTRIBUTION-ORDER-GEOGRAPHY-SYNCHRONIZATION-003 — Engineering Report

**Verdict: IMPLEMENTED · VERIFIED · NOT CERTIFIED**

The task itself is complete and fully verified end to end. The verdict is
NOT CERTIFIED only because two **pre-existing, owner-retained** items remain open
in the module (§12, §13) — neither is caused by, nor related to, this work.

No commit. No deploy. Scope held to §3; nothing else was touched.

---

## 1. Executive summary

`orders.logistics_city_id` is the ONLY input to `OrderZoneResolver`, and before
this task **nothing wrote it on any edit path** — only `OrderCityBinder`'s
NULL-only sweep and a one-time migration backfill. Worse, the Orders grid could
not edit `city` at all: `PatchOrderRequest` accepted `area` and `governorate` but
not `city`.

So an operator changing an Order's location could only ever move free-text labels,
while the field that decides the Distribution zone kept its original value forever.
Distribution then faithfully rendered a stale city — correctly, from its own source
of truth.

This task closes the chain end to end:

```
Order address → city → logistics_city_id → OrderZoneResolver → Distribution zone
```

Proven on live data: ORD-00007 moved Maadi → Obour City across **all three
representations** in one operator action, with no stale value anywhere.

## 2. Root causes

**Three parallel, unconnected location vocabularies exist on `orders`:**

| Field | Written by | Read by | Catalog |
|---|---|---|---|
`delivery_zone` / `delivery_zone_id` | `PATCH /orders/{order}/zone` — free text | Orders workspace | none |
`area` | `PatchOrderRequest`, `UpdateOrderRequest` | Orders workspace | none |
`city` + `logistics_city_id` | `city` text only; the **id** had no runtime writer | **Distribution** | `logistics_cities` |

ORD-00007's row was the proof: `area = 'Obour City'`, `delivery_zone = 'Obour City'`,
but `city = 'Maadi'` and `logistics_city_id = 2` (Maadi → zone 7).

**Canonical source, established:**

- **`city` + `logistics_city_id` is canonical for zoning.** The id is identity, the
  text is display — the read model already states this. `logistics_cities` is the
  catalog.
- **`area` is a sub-city descriptor.** Free text, no catalog, nothing derives from it.
- **`delivery_zone` / `delivery_zone_id` is a display label.** Free text, no link to
  `distribution_zones`. **Not** canonical for distribution zoning. Left untouched.
- **`governorate`** narrows city resolution only; it never widens or overrides it.

**Two gaps, both closed:**

1. **No City field on the grid edit.** `PatchOrderRequest` had no `city` rule, so
   operators recorded city-level changes in `area` — which resolves to nothing.
   `'Obour City'` is an **exact match** for `logistics_cities` id 23 → zone 9, so
   the data was always resolvable; nothing resolved it.
2. **No writer of `logistics_city_id` on an edit path**, and no re-zone of an
   already-zoned assignment.

## 3. Changes implemented

**The boundary (§5).** Orders announces; it does not reach:

```
Orders          event(new OrderGeographyChanged)      ← references no other module
   │
Distribution    SyncOrderGeographyListener            ← owns the reaction
   ├── Geography      OrderCityBinder::rebindOrder()   ← owns text → city id
   ├── Distribution   OrderZoneResolver                ← owns city id → zone
   └── Distribution   ManualAssignmentService::changeOrderZone()  ← owns the assignment
```

Registered with `Event::listen` in the **subscriber's** provider, following the
existing `Modules\Logistics\Automation` pattern. Orders dispatches with the standard
`event()` helper, so the framework dispatcher delivers it. (The EnterpriseEventBus
caveat applies to Inventory events, not these.)

**Contracts explicitly NOT changed (§4, §6):**

- `OrderCityBinder::bindForCompany()` is still **NULL-only**. *"A later geography
  edit cannot silently move an Order that operators have already planned around"*
  governs the automatic sweep, and the sweep is untouched. The new `rebindOrder()`
  is a separate, explicit, single-Order path used only when an operator deliberately
  edits that Order.
- `reconcileUnzoned()` is still **NULL-zone-only** and is **not** used here — it
  cannot repair an Order that *has* a zone, which is exactly this case.
- `OrderCityResolver` is reused verbatim. One implementation of "what city is this
  text?", still exact-match, still refusing to guess.

**One signature widened.** `changeOrderZone(?int $zoneId)` — `null` now clears the
zone. That case was previously inexpressible and §9 requires it: a zone is *derived*
from `logistics_city_id`, so when a city resolves to nothing the stored zone is an
assertion nothing supports. No existing caller changes and the HTTP endpoint still
requires `zone_id`, so only the internal sync path can pass null.

**Audit honesty.** The re-zone is stamped `manual_move` — `DistributionAssignmentSource`
has three cases and none means "the address changed"; inventing a fourth would change
an approved enum. The truth is carried in the reason string:
`"City changed from [Maadi] to [Obour City]; zone re-resolved."`

**Never fails the operator's edit.** The address change is already committed when the
listener runs. A Distribution-side problem (closed window, group at capacity) is
logged and swallowed rather than rolling back or appearing to reject an edit that
succeeded.

## 4. Files changed

| File | Change |
|---|---|
`Modules/Commerce/Orders/Domain/Events/OrderGeographyChanged.php` | **NEW** — the boundary event |
`Modules/Commerce/Orders/Presentation/Http/Requests/PatchOrderRequest.php` | **+ `city`** — the missing operator capability |
`Modules/Commerce/Orders/Application/Actions/PatchOrderAction.php` | dispatches on a real city/governorate change, in **both** branches (status+fields, and fields-only) |
`Modules/Commerce/Orders/Application/Actions/UpdateOrderAction.php` | dispatches from the existing audit diff |
`Modules/Logistics/Geography/Domain/Services/OrderCityBinder.php` | **+ `rebindOrder()`** — explicit single-order re-resolve; sweep untouched |
`Modules/Logistics/Distribution/Application/Listeners/SyncOrderGeographyListener.php` | **NEW** — owns the reaction |
`Modules/Logistics/Distribution/Infrastructure/Providers/LogisticsDistributionServiceProvider.php` | registers the listener |
`Modules/Logistics/Distribution/Domain/Services/ManualAssignmentService.php` | `changeOrderZone(?int $zoneId)` + explicit null guard |
`tests/Feature/Logistics/DistributionOrderGeographySyncTest.php` | **NEW** — 12 tests |

No migration. No schema change. No frontend change. No new source of truth.

## 5. Tests — 12/12, 65 assertions

| § | Test | Result |
|---|---|---|
7 | `test_changing_city_moves_the_logistics_city_and_the_distribution_zone` | ✅ |
8 | `test_changing_the_city_back_returns_the_logistics_city_and_zone` | ✅ |
9 | `test_an_unmatched_city_clears_the_binding_and_guesses_nothing` | ✅ |
9 | `test_an_order_with_no_city_stays_unassigned` (the ORD-00001 shape) | ✅ |
9 | `test_a_city_with_no_zone_resolves_the_city_but_not_a_zone` | ✅ |
10 | `test_the_background_binder_still_refuses_to_rebind_a_bound_order` | ✅ |
10 | `test_reconcile_unzoned_still_refuses_to_move_a_zoned_order` | ✅ |
10 | `test_no_unrelated_order_changes` | ✅ |
10 | `test_editing_an_unrelated_field_does_not_rezone` | ✅ |
10 | `test_resending_the_same_city_is_a_no_op` | ✅ |
— | `test_an_uncollected_order_only_updates_its_city_binding` | ✅ |
— | `test_rebind_refuses_an_order_outside_the_company` | ✅ |

**Three failures during development were my own, and are recorded rather than
hidden:**

1. Used `PATCH /orders/{id}` — that is the FULL update (`UpdateOrderRequest`,
   requiring customer_id/order_date/status/lines). The grid's inline edit is
   `PATCH /orders/{id}/quick-update`. Test-fixture bug.
2. **A real bug in my own code**, caught by the suite: `PatchOrderAction` carries
   `$actorId` as a **string**, so the `?int` parameter threw a `TypeError` (HTTP 500).
   Fixed by accepting `int|string|null` and casting at the event boundary.
3. Asserted `$row['address']['city']`; the read model's key is `shipping_address`.
   Test-fixture bug — corrected to assert `city_id`, `city_name` **and**
   `shipping_address.city`.

**Static gates:** PHPStan `[OK] No errors`; Pint **PASS on all 8 changed files**.
`tsc` not applicable — this task changed no frontend (still 23 / baseline 24, none
mine, from earlier work).

## 6. Regression — bounded, as instructed

`--filter "OrderGpsPersistenceTest|OrderPaymentContractImplementation002Test|OrderPaymentMethodAndSettlementContractTest|DistributionOrderGeographySyncTest|DistributionWorkspaceFinalizationTest"`

**96 tests, 388 assertions, 0 failures.**

The three Orders suites were chosen because they are the only suites that exercise
`PatchOrderAction` / `UpdateOrderAction` — the two core actions this task modified.
Both Distribution suites are included. No broad ERP regression was run.

## 7. Browser verification — live data, all three representations (§11, §12)

Real operator workflow: `PATCH /orders/{id}/quick-update` with
`{city: 'Obour City', governorate: 'Cairo'}` → **200**.

| Representation | Before | After |
|---|---|---|
**Orders display** — `city` / `governorate` / `area` | Maadi / Cairo / Obour City | **Obour City** / Cairo / Obour City |
**Database** — `orders.city` | `Maadi` | **`Obour City`** |
**Database** — `orders.logistics_city_id` | `2` (Maadi) | **`23`** (Obour City) |
**Distribution** — `city_name` | Maadi | **Obour City** |
**Distribution** — `city_text` | Maadi | **Obour City** |
**Distribution** — `shipping_address.city` | Maadi | **Obour City** |
**Distribution** — `zone_id` / `zone_name` | `7` / Maadi | **`9` / Obour** |

Assignment audit after the change:
`source = manual_move`,
`reason = "City changed from [Maadi] to [Obour City]; zone re-resolved."`

**No stale Maadi value remains anywhere.**

Every other order's `city` and `logistics_city_id` were verified unchanged
(18 orders listed and compared).

## 8. Business-data side effects

1. **ORD-00007 was corrected**, not patched as a special case — the same code path
   any order now takes. `city` Maadi → Obour City, `logistics_city_id` 2 → 23, zone
   7 → 9. This is a **deliberate data correction**: `area` and `delivery_zone`
   already said Obour City, so Maadi was the stale value.
2. **ORD-00007 left DG-001.** Its `virtual_slot_id` is now NULL because DG-001
   covers zones 2 and 7; zone 9 (Obour) is attached to no group. This is correct
   behaviour — an order cannot remain in a group whose zones no longer include it —
   and it is the one visible operational consequence. If Obour should be planned,
   attach zone 9 to a group from the Zones tab.
3. Nothing else changed. No Preparation, Loading, Inventory, Trip, Vehicle, Driver,
   Template, Map, capacity or payment data was touched.
4. Verification tokens: one Sanctum token minted server-side and **revoked**
   (`claude-%` remaining: 0). No password handled.

## 9. Scope discipline (§13)

Not touched: Map, Loading Preparation, Templates, Capacity, Payment, Vehicle/Driver.
No dependency on any of them was discovered, so no STOP was required.

The STOP rule did not trigger: the fix required **no** change to an approved
architectural contract. Both constraining contracts (the NULL-only sweep, the
NULL-zone-only reconcile) were routed *around* rather than through, and each is
asserted intact by a regression test.

## 10. Known limitations

1. **`delivery_zone` / `delivery_zone_id` remain free-text display labels**, unlinked
   to `distribution_zones`. Out of scope here. They are now the only location fields
   an operator can edit that derive nothing — a candidate for a future task to either
   link them to the zone catalog or retire them.
2. **`area` still resolves to nothing.** By design: it is a sub-city descriptor with
   no catalog. Operators who put a city name in `area` will still see no zone change
   — but they can now edit `city` directly, which is the actual fix.
3. **A re-zone is stamped `manual_move`**, because no enum case means "address
   changed". The reason string carries the detail.
4. **The frontend Orders grid must expose the new `city` field** for operators to use
   it without the API. The backend accepts it now; wiring the grid control was not in
   this task's scope (§13 forbade unrelated UI work). Verified via the real endpoint.

## 11. Remaining architecture follow-up (retained, not fixed)

**`distribution_zones` has no `company_id`.** Zone-level tenant ownership remains
unenforceable. Explicitly retained by the previous certified decision and **not
silently fixed** here.

## 12. Pre-existing failures (unrelated, previously classified)

The 25 pre-existing Distribution failures classified in the prior task remain open:
22 in `DistributionModuleTest` (Trip/custody routes belonging to another agent's
in-flight work) and 3 filter tests failing on an Orders status-vocabulary mismatch.
They were **not re-run** here — a broad regression was explicitly out of scope — and
none is caused by or related to this task.

## 13. Certification status

| Gate | State |
|---|---|
Canonical source identified for all four fields | **VERIFIED** |
Explicit operator re-resolve on City/Governorate change | **IMPLEMENTED · VERIFIED** |
`OrderCityBinder` NULL-only sweep unchanged | **VERIFIED** by regression test |
`reconcileUnzoned()` NULL-zone-only unchanged | **VERIFIED** by regression test |
Orders does not reach into Distribution | **VERIFIED** — event boundary |
§7 forward (Maadi → Obour City) | **VERIFIED · BROWSER VERIFIED** |
§8 reverse (Obour City → Maadi) | **VERIFIED** |
§9 unmatched city guesses nothing | **VERIFIED** |
§10 no unrelated order moves | **VERIFIED** |
§12 all three representations agree | **BROWSER VERIFIED** |
Focused tests | **12/12, 65 assertions** |
Bounded regression | **96/96, 388 assertions** |
PHPStan / Pint | **clean on all changed files** |
`distribution_zones.company_id` | **RETAINED follow-up** (§11) |
Pre-existing Distribution failures | **OPEN, unrelated** (§12) |

**IMPLEMENTED · VERIFIED · NOT CERTIFIED** — every gate belonging to this task
passes; the two open items are pre-existing and owner-retained. No commit, no deploy.
