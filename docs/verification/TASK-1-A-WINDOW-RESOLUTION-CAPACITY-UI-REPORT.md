# TASK-1-A — DISTRIBUTION WINDOW RESOLUTION + GROUP CAPACITY UI

**Date:** 2026-08-24 · **Branch:** `develop` · No commit. No deploy. No migration. No RBAC change.

> ## VERDICT
>
> **§3 GROUP CAPACITY UI — IMPLEMENTED / VERIFIED / BROWSER NOT VERIFIED**
> **§1 FAIL-CLOSED WINDOW RESOLUTION — IMPLEMENTED / VERIFIED IN ISOLATION / 🛑 BLOCKED**
>
> **NOT CERTIFIED.** §1 works exactly as specified and is proven on live data, but it
> contradicts a certified Distribution contract that 4 existing test classes assert
> explicitly. That is an architecture decision, not a fixture chore — see §H1. Per the
> STOP rule I have documented it rather than guessed.

---

## A. What changed

### §1 — Fail-closed window resolution

`DistributionWindowService::resolvePlanningWindow()` was a READ that answered an
unresolvable question with `windowFor(today)` — and `windowFor` **creates**. So a read with
no warehouse minted an empty calendar window, and the workspace rendered five tabs, five
KPIs and a status badge over a cycle nobody was planning.

It now returns `?DistributionWindow` and **fails closed** in all three branches (no wave,
no anchor, anchor names an invisible window). It never creates.

Collection legitimately needs to create on the first sweep of a new cycle, so that intent
moved to an explicit `resolveOrCreatePlanningWindow()` called only by
`DistributionCollectionService`. Both paths use the **same resolution rule** — the new
method only adds creation — so reader and writer cannot disagree about which window a cycle
is planning.

`GET /windows/current` now answers with a transport discriminator:

```json
{ "resolution": "no_planning_window",
  "resolution_reason": "no_warehouse_selected",
  "window": null, "zones": [], "slots": [] }
```

Three reasons, because they need three different operator actions:
`no_warehouse_selected` · `no_active_wave` · `no_window_for_cycle`.

**No new business lifecycle status, no DB column, no new source of truth.**
`DistributionWindowStatus` is untouched. `POST /windows/collect` no longer creates a window
purely to run its reconcile step; with no window it reports `rezoned: 0`.

### §2 — Warehouse scoping

Resolution remains company + warehouse + Preparation Wave, anchored on the wave's **active**
membership. Verified that a second warehouse cannot inherit the first's window. Preparation
wave lifecycle, cutoff semantics, carry-over, Group identity and Group→Trip identity are all
untouched.

### §3 — Group capacity presentation

The Group card now renders the three numbers the server already sent and the card previously
threw away:

```
Group capacity (orders)
Current: 7     Maximum: 20     Remaining: 13
```

**Current is `demand_orders`, not `orders_count`.** The backend keeps both on purpose
(`DistributionAggregationService`: *"demand_orders below keeps its original meaning and its
original source, for the capacity maths"*) and derives `remaining_orders` from the former.
Pairing `orders_count` with `remaining_orders` would put two different aggregates in one sum
and let the row fail to add up.

`remaining_orders` is rendered **as received** and never recomputed client-side, so the card
and the row-locked write guard cannot disagree. `null` maximum renders "No maximum" and
`null` remaining renders "Unlimited" — never `0`, which would read as "full". Over-capacity
reuses the existing `settings.overCapacity` key rather than minting a second one.

### §4 — Trip capacity: untouched

No backend value changed. `distribution_trips.capacity` is still 60 on both live trips. The
Trip panel already labels its own field "Trip capacity"; the Group block is now captioned
"Group capacity (orders)", so the two numbers are visually distinct. Reconciliation remains a
separate future task.

---

## B. Files changed

**Backend (3)**

| File | Change |
| --- | --- |
| `Distribution/Domain/Services/DistributionWindowService.php` | `resolvePlanningWindow` → `?DistributionWindow`, fail-closed ×3; new `resolveOrCreatePlanningWindow` |
| `Distribution/Domain/Services/DistributionCollectionService.php` | collector calls the explicit create variant |
| `Distribution/Presentation/Http/Controllers/DistributionWindowController.php` | `resolution`/`resolution_reason` payload + 5 transport constants; `collect()` no longer creates to reconcile |

**Frontend (5)**

| File | Change |
| --- | --- |
| `distribution-workspace/types/index.ts` | `WindowResolution`, `WindowResolutionReason`; `window` now nullable |
| `distribution-workspace/pages/distribution-workspace-page.tsx` | `UnresolvedWindow` component + render branch withholding tabs/KPIs |
| `distribution-workspace/components/distribution-groups-panel.tsx` | capacity triplet + captioned block + over-capacity line |
| `i18n/locales/en/logistics.json` · `ar/logistics.json` | 12 new keys (`unresolved.*`, `capacity.*`) |

**Tests (1 new)** — `tests/Feature/Logistics/DistributionWindowResolutionAndCapacityTest.php` (8 tests).

No migration. No new endpoint. No permission or RBAC change.

---

## C. Tests

All 8 required cases, **8/8 green, 63 assertions**:

| # | Required case | Test |
| --- | --- | --- |
| 1 | No warehouse → no silent fallback | `test_a_read_with_no_warehouse_does_not_fall_back_to_todays_window` (also asserts **no window row created**) |
| 2 | Correct warehouse → correct window | `test_the_correct_warehouse_resolves_the_window_holding_its_cycle` |
| 3 | Different warehouse cannot inherit | `test_a_second_warehouse_cannot_inherit_the_first_warehouses_window` |
| 4 | Canonical current/max/remaining | `test_groups_expose_canonical_current_maximum_and_remaining` |
| 5 | Remaining derived correctly | `test_remaining_is_derived_floored_and_null_when_unbounded` (floors at 0; null ≠ 0) |
| 6 | Group membership unchanged | `test_reads_create_no_wave_no_group_and_no_membership_change` |
| 7 | No new Preparation Wave | same test |
| 8 | No new Distribution Group | same test |

Plus two reason-discrimination rows (`no_active_wave`, `no_window_for_cycle`), because a
single collapsed message would tell the operator to do the wrong thing half the time.

**Static gates:** Pint PASS (3 backend + 1 test file) · PHPStan `[OK] No errors` ·
ESLint clean on all 3 changed frontend files · `tsc -p tsconfig.app.json`: **0 errors in any
file I touched** (23 repo-wide errors are pre-existing index-signature debt in
Admin/HR/Marketing/Orders/StockLedger/Engineering/Dispatch — all 13 files last modified
08-05…08-23, none by me).

**i18n:** en/ar parity exact at **2115/2115** keys; all 12 new Arabic values verified in
Arabic script (not English copies).

---

## D. Regression — measured against a control, not assumed

`--filter "Distribution|DistributorOrders"`, 314 tests.

| Run | Result |
| --- | --- |
| **Control** (fail-open restored in the container) | **29 failures** |
| **With TASK-1-A** | **161 failures** (71 errors + 90 failures) |

**Control breakdown — the true baseline:**

| Class | Count | Verdict |
| --- | --- | --- |
| `DistributionModuleTest` | 22 | **PRE-EXISTING — not mine** |
| `DistributionReadModelApiTest` | 2 | **PRE-EXISTING — not mine** |
| `DistributionOrdersFilterApiTest` | 1 | **PRE-EXISTING — not mine** |
| `DistributionWindowResolutionAndCapacityTest` | 4 | **my new tests, correctly failing without the fix** — the differential proof |

So the baseline is **25 pre-existing failures**, and TASK-1-A **introduces ~136**, in 13
classes. Every one has the same mechanism: the fixture resolves the window through
`GET /windows/current` and there is no active Preparation Wave to anchor on, so the read is
now (correctly) unresolved and the derived `window.id` is null → `TypeError: Return value
must be of type string, null returned`, then cascading 404s on `/windows//slots`.

I also measured a **narrower variant** (fail closed only on `waveId === null`, other branches
returning today's *existing* window via the non-creating `currentWindow()`): **162 failures** —
no better. That rules out the anchor branches and localises the entire regression to the
no-resolvable-wave case, which is precisely what §1 asked me to change.

---

## E. UI behaviour before / after

| | Before | After |
| --- | --- | --- |
| No warehouse selected | Five tabs, 5 KPIs and a window status badge render over a freshly **created** empty calendar window. One small amber "select a warehouse" line above a board that looks complete. | Breadcrumbs + title + a single explicit card: **"No distribution window — Select a warehouse to continue."** Tabs, KPIs and badge withheld. Nothing created. |
| Warehouse with no active wave | Same empty authoritative board | *"No active preparation wave for this warehouse, so there is no distribution cycle to plan."* |
| Cycle not yet collected | Same empty authoritative board | *"This cycle has not been collected into a distribution window yet. Use Refresh to run collection."* |
| Group card capacity | Not rendered at all — capacity/remaining/utilisation/over-capacity were all sent by the server and dropped | `Group capacity (orders) · Current 7 · Maximum 20 · Remaining 13`, plus an over-capacity line when applicable |
| Group vs Trip capacity | Group capacity absent; Trip showed a bare `60` | Group block captioned "Group capacity (orders)"; Trip keeps its own "Trip capacity" label — visually distinct, backend unchanged |

---

## F. Browser verification — **BROWSER NOT VERIFIED**

The UI (Vite, `:5173`) requires an interactive login and I do not enter credentials. No data
was fabricated and no credentials were used.

**What I verified instead — real HTTP against live `ecos_dev`, GET only:**

```
A) GET /windows/current                       (no warehouse)
   resolution      : no_planning_window
   reason          : no_warehouse_selected
   window          : null
   zones/slots     : 0 / 0

B) GET /windows/current?warehouse_id=019f4e1c-2e1b…
   resolution      : resolved
   window_date     : 2026-08-21          ← the cycle's window, NOT today (08-24)
   DG-001         current=7   maximum=20   remaining=13
   DG-003         current=1   maximum=20   remaining=19
   DG-TPL-VERIFY  current=0   maximum=20   remaining=20
```

**The core fix, proven on live data:** `distribution_windows` still holds exactly **4** rows
(08-20…08-23) with **no 2026-08-24 row**. Under the old behaviour those two GETs — one of
them unscoped — would have minted today's window. The read side-effect is gone.

`DG-001 current=7 maximum=20 remaining=13` is also the live confirmation of §3 in the exact
shape requested.

---

## G. Side-effect audit

| Check | Result |
| --- | --- |
| Orders / groups / assignments / trips | **19 / 3 / 13 / 2** — all unchanged |
| `distribution_windows` | **4**, unchanged; **no new window created by any read** |
| Order status, payment method, payment proof, warehouse assignment | untouched |
| Group membership, trip membership, vehicle, driver | untouched |
| Capacity values | untouched (`capacity_orders` = 20 on all three groups, as before) |
| Fabricated business data | none |
| Live writes made | one Sanctum token for read-only verification, **revoked**; container-only control/variant patches, **reverted** (verified: 3 `return null;` present, strict version restored) |

Tests run against `ecos_dev_test` under `RefreshDatabase`; no live-data test writes.

---

## H. Remaining known issues

### H1 🛑 BLOCKING — §1 contradicts a certified Distribution contract

Four test classes run Distribution **with zero Preparation Waves, by design**, and two assert
it explicitly:

| Class | Failures | Wave inserts | Asserts waves untouched |
| --- | --- | --- | --- |
| `DistributionGroupManagementTest` | 17 | **0** | yes |
| `DistributionGroupWarehouseOwnershipTest` | 12 | **0** | **yes — `assertSame(0, count('preparation_waves'))`** |
| `DistributionGroupTripTest` | 12 | **0** | no |
| `DistributorOrdersAddressBindingTest` | 9 | **0** | no |

`DistributionGroupWarehouseOwnershipTest` asserts `preparation_waves` count is **0** with the
message *"must remain untouched"* — that assertion is the test's whole purpose ("group
management mutates no other domain"). **Adding a wave to make it resolve would destroy the
assertion it exists to make.** These ~50 tests cannot be repaired by fixtures.

The remaining ~86 failures (9 classes that *do* model a wave but read unscoped, or create no
wave *membership* for the anchor) look fixture-fixable by threading the warehouse and
attaching membership.

**The decision:** does the approved contract now require an active engine Preparation Wave
before Distribution can be read at all?

- **Option A — accept it.** Consistent with the approved chain (Distribution operates *after*
  Preparation) and with the §1 requirement as written. Cost: ~136 tests to rework, including
  rewriting the intent of 2 certified classes. This is a **cliff** in the
  ratchet-never-cliff sense: it fails the approved baseline.
- **Option B — narrow §1 to the presentation boundary.** Keep the service fail-closed for the
  *workspace read* (which always has a warehouse in context) but let the endpoint keep serving
  a company-wide read when no warehouse is supplied. Preserves the baseline; leaves the
  unscoped read able to show a calendar window — mitigated because it can no longer *create*
  one.
- **Option C — keep fail-closed, and treat the 4 no-wave classes as the contract to amend**,
  deciding deliberately that Distribution-without-Preparation is no longer supported.

I have implemented §1 as literally specified (Option A behaviour) and left it in the tree.
Reverting is three `return null;` lines plus two caller guards. I did not rewrite the
certified suites, because which contract wins is the owner's call.

### H2 — Not in scope, unchanged (as instructed)

Trip reconciliation · Trip capacity (`distribution_trips.capacity` = 60, underived) ·
`released_at` demand calculation · Loading Preparation extraction · Map · Templates ·
Geography editing.

### H3 — Observed, not touched

- The Group card header still renders "Vehicle: Not assigned / Driver: Not assigned"
  unconditionally while `GroupTripPanel` below it shows the real pairing. Out of §1–§4 scope.
- `distribution_virtual_slots` still carries `capacity_stops` / `capacity_weight_kg` /
  `capacity_volume_m3`, and `storeSlot` still accepts them. I added no capacity axis and read
  none of them.
- 25 pre-existing Distribution failures (§D) remain untouched, per "do not modify unrelated
  failing tests".

---

> **STATUS: §3 IMPLEMENTED / VERIFIED · §1 IMPLEMENTED / BLOCKED ON H1 · BROWSER NOT VERIFIED · NOT CERTIFIED**
