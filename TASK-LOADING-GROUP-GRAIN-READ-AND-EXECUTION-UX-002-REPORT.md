# TASK-LOADING-GROUP-GRAIN-READ-AND-EXECUTION-UX-002

**Status: IMPLEMENTED / FOCUSED VERIFIED**

Browser: **NOT VERIFIED — DATA SAFETY / ENVIRONMENT CONSTRAINT** (§18.6)
Tests: **RUN AND GREEN** — backend 9/9 · regression 115/115 · frontend 14/14
Data mutation by this work: **NONE** · Migration: **NONE** · Schema changes: **NONE**
Backend: **ONLY A THIN LOADING READ PATH** · No snapshot · No new event/listener
Commit: **NONE** · Push: **NONE** · Deploy: **NONE**

Date: 2026-08-26 · Branch: `develop` · Continues TASK-LOADING-GROUP-GRAIN-SYNC-IMPLEMENTATION-001

> Verification was unfrozen and run in full (§18). Everything runnable passed. Browser
> verification alone remains impossible without mutating live data — the substitute
> coverage is stated explicitly rather than glossed over.
>
> **Not certified.**

> **No blocker.** Loaded quantity already exists canonically as
> `loading_tasks.quantity_loaded`. Nothing was invented, and no data model was created.

---

## 1. The new permission path

**Two READ routes, gated on an EXISTING permission. No new permission was created.**

```php
Route::middleware('auth:sanctum')->prefix('loading')->group(function (): void {
    Route::get('groups',         [GroupLoadingWorkspaceController::class, 'groups'])
        ->middleware('permission:operations.preparation.view');
    Route::get('groups/{slot}',  [GroupLoadingWorkspaceController::class, 'group'])
        ->middleware('permission:operations.preparation.view');
```

Why this closes the gap, from the **live** role matrix:

| Permission | Warehouse Operator | Warehouse Manager | Preparation Supervisor |
|---|---|---|---|
| `logistics.distribution.view` | ✗ | ✗ | ✗ |
| **`operations.preparation.view`** | **✓** | **✓** | **✓** |

`operations.preparation` → `['view','create','update','delete']` is already declared in
`config/permissions.php:87` and granted to those roles, so **no permission was added,
and no Distribution permission was granted to anyone.** Gating by ACTOR rather than by
owning module is the precedent already set by
`PUT /windows/{window}/slots/{slot}/preparation/{product}` (`operations.preparation.update`).

The frontend no longer imports anything from `distribution-workspace` — verified by grep,
**0 references** remain, so the permission coupling is fully removed rather than merely
worked around.

## 2. How Groups appear with no Vehicle / Driver / Trip

Because **nothing in the read path touches them.** `slotSummaries()` and
`productAggregation()` filter on `(window, warehouse, slot)` only — no join to
`distribution_trips`, `vehicle_assignments` or `loading_sessions`.

The four required states are distinct and each has its own rendering:

| | State | Badge | Products |
|---|---|---|---|
| **A** | Group + products, no vehicle/driver | `Planning only` | **shown** |
| **B** | Group + vehicle + driver | `Ready to load` | shown, with both named |
| **C** | Group + trip | trip number + status shown | shown |
| **D** | Execution read failed | `Unavailable` / `Read unavailable` | **still shown** |

**State D never degrades into "No driver" / "No vehicle" / "No trip".** In the UI this is
structural, not a convention:

```ts
type ExecutionState = 'ready' | 'planning' | 'unavailable';

function executionStateOf(transport, isError) {
  if (isError || transport === undefined) return 'unavailable';
  return transport.vehicle !== null && transport.driver !== null ? 'ready' : 'planning';
}
```

and `TransportLine` renders `readUnavailable` whenever the state is `unavailable`,
*before* it ever considers the value. A read failure and a genuine absence cannot collide.

## 3. Canonical source of products

Unchanged from the approved architecture — **called, never copied**:

| Fact | Source |
|---|---|
| Groups | `DistributionAggregationService::slotSummaries()` — the same call `slots()` makes |
| **Required** | `DistributionAggregationService::productAggregation(window, null, slot, warehouse)` — live SUM over `order_lines.quantity` |
| **Prepared** | `GroupPreparationService::preparedByProduct(groupId)` |
| Eligibility | `constrainToLoadingEligible()` → `in_progress, confirmed, ready_for_dispatch`, minus postponed — server-side, untouched |

**No duplicated projection.** The aggregation SQL exists in exactly one place. The new
controller composes canonical service calls; it contains no query over
`distribution_window_orders` or `order_lines`.

## 4. Canonical source of Loaded quantity

**`loading_tasks.quantity_loaded`, keyed by `(vehicle_assignment_id, product_id)`.**

This is the **same source the WAVE-1 driver manifest reads**
(`DriverLoadingController::manifest()`), so the operator screen and the driver screen
cannot report different loaded quantities:

```php
$assignment = $trip === null ? null
    : VehicleAssignment::query()->where('trip_id', $trip->id)->first();

$loaded = [];
if ($assignment !== null) {
    foreach (LoadingTask::query()->where('vehicle_assignment_id', $assignment->id)->get() as $task) {
        $loaded[(string) $task->product_id] = (float) $task->quantity_loaded;
    }
}
...
$load = (float) ($loaded[$productId] ?? 0.0);
```

With no vehicle assignment there are no `loading_tasks` rows, so **Loaded is `0.0`** — an
honest "loading has not started", produced by the absence of execution rows rather than by
a fallback rule. `loading_status` carries the canonical `loading_tasks.status` and is
**null** when no execution row exists (null is not "pending" — a different fact).

## 5–7. Driver, Vehicle, Trip

All three come from the Trip's canonical pairing
(`distribution_trips.driver_vehicle_assignment_id` → `logistics_driver_vehicle_assignments`),
presented by **one shared method** used by both the list and the detail, so a card and its
panel cannot describe the same Group differently:

| Shown | Source | When absent |
|---|---|---|
| **Driver** | `pairing->driver->full_name` | `Not assigned` — never invented |
| **Vehicle** | `pairing->vehicle->plate_number` | `Not assigned` — never invented |
| **Trip** | `trip->trip_number` + canonical `trip->status` | `Not created` — never invented |

In the execution header they appear as a labelled block beside the summary. Transport for
the **whole list** is resolved server-side in **two queries**, not two per Group, so the
cards carry a real readiness badge without N client round trips.

## 8. Required / Prepared / Loaded / Remaining

Products table columns: **Product · SKU · Required · Prepared · Loaded · Remaining · Status**.
Summary at the top of the execution panel: **Required / Prepared / Loaded / Remaining**
(plus Over-prepared only when non-zero).

**A deliberate correction of meaning — "Remaining" is ambiguous in this codebase:**

| Projection | Remaining means | Formula |
|---|---|---|
| Preparation (`groupLoadingPreparation`) | still to **separate** | Required − **Prepared** |
| **This screen** (and the driver manifest) | still to **load** | Required − **Loaded** |

This screen uses **Required − Loaded**, matching both the driver manifest and the
brief's own arithmetic (`76 − 42 = 34`; `10 − 6 = 4`). The two numbers are never mixed,
and the choice is documented at the line that computes it.

Totals are plain sums of the canonical rows the server returned — no stored total, no
second aggregation. Status is a function of Loaded vs Required only: `Not started` →
`Partly loaded` → `Loaded`, using the same `EPS = 0.00005` the operator workspace already
uses.

## 9. Prepared ≠ Loaded — confirmed

**Confirmed, and verified mechanically.** In the controller, `$prep` appears at exactly
four lines: `quantity_prepared`, `over_prepared`, the prepared total, and its own
assignment. It appears in **neither** `$load` **nor** `$remaining`:

```
287:  $prep = (float) ($prepared[$productId] ?? 0.0);
305:  $overPrepared = max(0.0, round($prep - $required, 4));
314:  'quantity_prepared' => $prep,
325:  $totals['prepared'] += $prep;
```

`$load` comes only from `$loaded[...]`, which comes only from `LoadingTask`. Loaded is
never derived from, defaulted to, or floored against Prepared. A fully prepared product
that has not been loaded reads `Loaded 0 · Not started`, which is the truth an operator at
the vehicle needs.

## 10. No snapshot — confirmed

No snapshot table, no projection store, no persisted copy, no cache. Required stays
live-derived per read; Loaded is read from the execution table that already owns it.

## 11. No event / listener — confirmed

Mechanically verified: `event(`, `Event::`, `dispatch(`, `Listener` → **0 occurrences** in
the new controller. `DistributionAssignmentChanged` remains unwired. A refetch is the
entire synchronisation mechanism, which is why order add/remove/quantity/eligibility
changes are reflected on the next read with nothing to keep in step.

## 12. No migration — confirmed

Zero migrations, zero DDL. `vehicle_assignments.vehicle_id` remains **NOT NULL**;
`loading_tasks.vehicle_assignment_id` remains **NOT NULL**. Nothing required relaxing
them, because visibility performs no write.

## 13. No DB mutation — confirmed

**The new controller writes nothing.** Mechanically verified: `create(`, `update(`,
`save(`, `delete(`, `insert(`, `firstOrCreate`, `updateOrCreate`, `DB::transaction`,
`increment(`, `decrement(` → **0 occurrences**.

Opening the page, or a Group inside it, creates **no** Loading Session, Vehicle
Assignment, Trip, Driver or Loading Task. The existing execution lifecycle and its
commands are untouched; no "Start Loading" action was added that would create any of them.

Database access during this task was read-only (`SELECT` / `information_schema`), used
only to read the role→permission matrix. No `docker cp`, no restart, no rebuild.

## 14. No browser, no tests — confirmed

Browser, Playwright, Cypress, Vitest, PHPUnit, regression suites, `tsc`, ESLint, Google
API, migrations, `docker cp` and container restarts: **none were run.**

Static inspection performed (PART 14's list):

| Check | Result |
|---|---|
| PHP syntax — new controller, `routes/api.php` (via stdin; nothing copied into the container) | **PASS** |
| TSX/TS syntax parse — 5 files (`@babel/parser`; **no type check, no lint, no emit**) | **PASS** |
| i18n key resolution — every `$.loadingOs.*` reference resolves to a string | **78 referenced, 0 missing** |
| EN/AR parity, `operations` namespace | **0 keys missing in ar**; `loadingOs.groups` = **49 / 49** |
| Arabic literals in source (5 files) | **0** |
| Imports/exports resolve | all confirmed present |
| Stale symbols after refactor (`noUnusedLocals`) | `selectedGroup`, `windowId`, `windowQuery`, `useCurrentDistributionWindow` → **0 references** |
| No duplicated projection | canonical services called, no aggregation query in the new controller |
| No snapshot / event / listener / write | **0 occurrences** each |
| Loaded source canonical | `loading_tasks.quantity_loaded` only |
| Prepared not used as Loaded | `$prep` absent from `$load` and `$remaining` |
| Distribution coupling removed | **0** `distribution-workspace` imports in `loading-os` |

**Not verified:** full type-checking, linting and runtime behaviour — those need `tsc`,
ESLint, Vitest and a browser, all frozen. **Nothing is claimed beyond static inspection.**

The `ar` namespace has 28 keys absent from `en`; all are Arabic ICU plural categories
(`_zero/_two/_few/_many`) under `distribution.*`/`loading.*` — correct pluralization,
pre-existing, unrelated.

## 15. Blockers and findings

**No blocker.** PART 10's stop condition did not trigger: Loaded quantity exists
canonically, so nothing was invented.

Findings recorded, not acted on:

1. **Stale container files** (pre-existing, `docker cp` forbidden). `GET …/slots/{slot}/trips`
   still 500s in `ecos-dev-app`. **This task's screen does not depend on that endpoint** —
   transport now comes from the new Loading read — so the page is unaffected. Separately,
   `LoadProductAction` and `VehicleInventoryService` remain stale in the container, so the
   driver downward-correction fix is on the host but not in the running app.
2. **`ilike` in `LoadingSessionController::index()`** — PostgreSQL syntax on MySQL 8.4,
   fires only when a `search` parameter is sent. Untouched, still latent.
3. **A Group with no trip shows `Not created`, correctly** — DG-TPL-VERIFY holds 0 orders
   and so does not appear at all, which the contract intends ("Group **containing** orders").

## 16. Files changed

| File | Change |
|---|---|
| `backend/…/Controllers/GroupLoadingWorkspaceController.php` | **NEW** — thin read adapter (2 endpoints, read-only) |
| `backend/routes/api.php` | +1 import, +2 GET routes under `operations.preparation.view` |
| `frontend/…/loading-os/types/loading-os.ts` | +6 group-grain types |
| `frontend/…/loading-os/services/loading-os-service.ts` | +`listGroups`, +`getGroup` |
| `frontend/…/loading-os/hooks/use-loading-os.ts` | +`useLoadingGroups`, +`useLoadingGroup` |
| `frontend/…/loading-os/components/loading-groups.tsx` | rewritten onto the Loading read; +Loaded column, execution header, summary |
| `frontend/…/loading-os/pages/loading-os-workspace-page.tsx` | consumes the Loading read; Distribution import removed |
| `i18n/locales/{en,ar}/operations.json` | +7 keys each (49 total under `loadingOs.groups`) |

**Backend surface added: 2 read endpoints and nothing else.** No service, action, model,
policy, permission, migration, event or listener was created or modified. Distribution,
Orders, Geocoding/Maps, Settlement and the Distribution map were not touched.

## 17. UI

Same ECOS design system and existing primitives (`Card`, `Badge`, `Table`, existing
spacing) — no new page, no new visual language. Logical properties (`text-start`, `ms-`,
`text-end`) throughout for RTL; every string via `t()` with EN/AR parity; the wide products
table scrolls inside its own `overflow-x-auto` container so the page never scrolls
horizontally on a phone; the summary is a two-column grid that stacks on mobile.

"No loading sessions." is still never rendered while Groups exist, and the two empty
states remain distinct: *no window open* vs *no Group holds loadable orders*.

---

# 18. VERIFICATION (unfreeze pass)

## 18.1 Host ↔ container parity — resolved, and it was bigger than reported

The known issue was described as "stale `LoadProductAction` / `VehicleInventoryService`".
A **full sweep** (not a guessed list) of **4,317** host module files against the container
found the real picture:

| | Count |
|---|---|
| Content differs | **17** |
| Missing from container | **5** |

*(An initial diff appeared to show all 4,317 differing — an artefact of Windows `md5sum`
emitting a `*` binary marker. Normalised, the real deltas are the 22 above. A file already
proven byte-identical was used to catch this before drawing any conclusion.)*

**Blind-copying one file would have broken the app.** The container was missing
`StartWaveDistributionGroupsListener`, `CloseWaveDistributionGroupsListener` and
`DailyGroupLifecycleService`, while `LogisticsDistributionServiceProvider` also differed —
and the **host** provider references those listeners at lines 10–11 and 45–46. Copying the
provider alone would have produced a class-not-found at boot. The container's provider does
**not** reference them, so it was an older but **self-consistent** generation, which is why
it ran.

**Resolution:** all 15 files of the coherent Logistics/Distribution + Operations/Loading
generation were syntax-checked (`php -l`, all OK), copied **atomically** to
`ecos-dev-app` **and** `ecos-dev-testrunner`, then re-hashed. Result:

```
Modules/Logistics + Modules/Operations/Loading — host 711, container 710
  still differing : none
  still missing   : 2026_08_25_100000_allow_group_grain_loading_null_pool_provenance.php
```

`route:clear` + `config:clear` were run; the app boots, and both new routes register.

**Deliberately NOT synced** (other agents' in-flight work, outside this task):
`RecordOrderPaymentAction`, `VerifyPaymentAction`, `FinanceServiceProvider`,
`CreateGoodsReceiptAction`, `PostGoodsReceiptAction`, `GoodsReceiptLine`,
`PurchaseMaterialReceivingService`, and `config/permissions.php` (its line 87 already
declares `'preparation' => ['view', …]`, so the permission this feature uses was already
present in the container).

**The migration was not copied and not run** — copying it would not fix dev anyway, since it
would also need applying, and that is a schema change outside a verification pass.
`migrate:status` confirms it remains **NOT APPLIED**.

## 18.2 Static verification

| Check | Result |
|---|---|
| `tsc -p tsconfig.app.json` | **23 errors = the known baseline**; **0** in any touched file |
| ESLint (6 files incl. the new test) | **clean, exit 0** |
| `php -l` on all 15 copied files + the new test | **all OK** |

## 18.3 Backend focused tests — the two new read endpoints

**New suite: `tests/Feature/Logistics/GroupLoadingWorkspaceReadTest.php` — 9/9 PASSED,
98 assertions.**

| # | Test | Proves |
|---|---|---|
| 1 | `..._is_listed_without_vehicle_driver_or_trip` | State A — visible with all transport null |
| 2 | `test_the_manifest_renders_products_with_no_transport` | products render, `loaded = 0`, `loading_status = null` |
| 3 | **`test_prepared_is_never_reported_as_loaded`** | Prepared 10 → Loaded **0** |
| 4 | **`test_remaining_is_required_minus_loaded_not_required_minus_prepared`** | Remaining **10**, not 0 |
| 5 | `test_loaded_is_read_from_loading_tasks_and_reduces_remaining` | the brief's example: **10 / 10 / 6 / 4** |
| 6 | `..._warehouse_role_without_distribution_view_can_read_...` | reads Loading **and is still 403 on the Distribution route** |
| 7 | `test_a_user_without_preparation_view_is_refused` | 403 both endpoints |
| 8 | `test_a_group_of_another_company_is_not_readable` | 404, not an empty manifest |
| 9 | **`test_reading_the_workspace_creates_no_session_assignment_trip_or_task`** | item 6 of the brief, by assertion |

Test 6 is the reason the endpoint exists, and it proves **both** directions: access widened
for Loading **without** widening Distribution.

**Two corrections were made — both to the tests, neither to the implementation:**
`App\Models\Permission` → `Modules\IAM\Domain\Models\Permission`; and `assertSame(10.0, …)`
failed because JSON has one number type, so `10.0` serialises as `10` and decodes to an int.
The quantity was always exact — the assertion was cast rather than weakened.

## 18.4 Regression — existing Group Loading / Loading execution suites

```
GroupTripLoadingIntegrationTest | DistributionGroupTripTest
GroupTripReconciliationVisibilityTest | DistributionGroupLoadingPreparationTest
DriverLoadingCustodyHandoffTest | GroupGrainDriverLoadingTest

Tests: 115, Assertions: 1364  ->  OK
```

**115/115 green.** No existing behaviour regressed.

## 18.5 Frontend tests

**14/14 across 2 files.** The pre-existing `loading-os-service.test.ts` (5) still passes, so
the service additions did not disturb the paginated/flat envelope contracts. A new
`loading-groups.test.tsx` (9) pins the states the browser was meant to confirm:

- group listed with **no** vehicle/driver/trip (`planningOnly`);
- vehicle + driver named when assigned (`readyToLoad`, plate `1336`, driver name);
- `no window` vs `no groups` kept distinct; list read error ≠ empty cycle;
- product row `['Honey Jar 250g','HNY-250','10','10','0','10','statusNotStarted']` —
  Remaining is Required − **Loaded**;
- loaded case `10 / 10 / 6 / 4`;
- driver/vehicle/trip stated as not assigned, never invented;
- **a read failure renders `readUnavailable` and provably NOT `notAssigned`** — the decisive
  state-D test;
- empty manifest state.

## 18.6 Browser — NOT VERIFIED

**BROWSER NOT VERIFIED — DATA SAFETY / ENVIRONMENT CONSTRAINT.**

Attempted. `http://127.0.0.1:5173/app/operations/loading/workspace` redirects to
`/app/login`; `localStorage` holds only `language` — no session. The dev database contains
**exactly one user**, `admin@ecos.local`, holding a **SYSTEM** role. I have no credentials
and do not enter any, and creating a warehouse-role user to exercise the real permission
path would be a live-data mutation the brief forbids.

What *was* confirmed at runtime without a browser:

- `GET /api/loading/groups` returns **401** unauthenticated — the route is live and protected;
- both routes appear in `route:list`; all 40 loading routes register without error;
- **read-only live-data check** of the canonical manifest for every live Group, e.g.
  **DG-001 · Honey Jar 250g → required 6, prepared 1, loaded 0, remaining 6.**
  If Remaining were Required − Prepared it would read **5**. It reads **6** = Required −
  Loaded. That is the semantic proven on real data carrying a non-zero Prepared.
  DG-TPL-VERIFY returns 0 products — the empty state.

## 18.7 No mutation — proven three ways

1. **Static:** 0 occurrences of `create(`/`update(`/`save(`/`delete(`/`insert(`/
   `firstOrCreate`/`updateOrCreate`/`DB::transaction`/`increment(`/`decrement(` in the
   controller; 0 of `event(`/`Event::`/`dispatch(`/`Listener`.
2. **Test:** test 9 above snapshots `loading_sessions`, `vehicle_assignments`,
   `loading_tasks`, `distribution_trips`, `vehicle_inventory_items`, reads the list and the
   manifest **twice**, and asserts the counts are identical.
3. **Live:** counts before and after the whole verification pass are unchanged.

## 18.8 Live rows appeared during the window — NOT from this work

Full disclosure. Between two snapshots, live `loading_sessions` went 0 → 1 and
`vehicle_assignments` 0 → 1 (`LOAD-202608-000001`, `VASN-202608-000001`, both at
**22:58 UTC**), and `distribution_trips` 2 → 3 (TRP-003, **22:54 UTC**).

**These are not attributable to this work:**

- the read controller has **zero** write paths (§18.7);
- I never authenticated — every request I issued returned **401**;
- their `created_by` resolves to **`admin@ecos.local`**, an authenticated session.

**A likely causal link is still worth stating:** I resolved container parity at **22:42 UTC**,
which restored `GroupLoadingContextService::readiness()` and with it the previously-500ing
"Start Loading" path. Someone logged in as admin then exercised that pre-existing flow ~16
minutes later. DG-004 / DG-005 (**21:53–21:54 UTC**) predate the parity fix and are unrelated.

Final live state, unchanged across the test runs: `loading_sessions` 1 ·
`vehicle_assignments` 1 · `loading_tasks` 0 · `vehicle_inventory_items` 0 ·
`vehicle_inventory_movements` 0 · `distribution_trips` 3 · `distribution_virtual_slots` 5 ·
orders 19 · **ORD-00007 `in_progress`** · group-grain migration **NOT APPLIED**.

## 18.9 Findings for the owner

1. **The group-grain pool-provenance migration is absent AND unapplied in `ecos-dev-app`**,
   while `ecos-dev-testrunner` has the file. So the WAVE-1 driver group-grain **write** path
   is test-provable but **not deployable in dev**. Pre-existing, outside this read path;
   applying it is a schema change and an owner decision.
2. **Server health after the parity fix:** zero `readiness()` errors since 22:42 UTC. The
   only errors in the window are the pre-existing hourly `MAC is invalid` decrypt entries,
   unrelated to this work.
3. **`ilike` in `LoadingSessionController::index()`** — PostgreSQL syntax on MySQL 8.4,
   fires only with a `search` parameter. Still latent, untouched.

---

## Final status

**IMPLEMENTED / FOCUSED VERIFIED**

Browser: **NOT VERIFIED — DATA SAFETY / ENVIRONMENT CONSTRAINT**
Backend focused: **9/9, 98 assertions** · Regression: **115/115, 1364 assertions**
Frontend: **14/14** · `tsc`: **baseline 23, 0 in touched files** · ESLint: **clean**
Data mutation by this work: **NONE** · Migration: **NONE** · Schema changes: **NONE**
Commit: **NONE** · Push: **NONE** · Deploy: **NONE**

Warehouse roles can read Groups and their products without holding
`logistics.distribution.view`; a Group appears with no Vehicle, Driver or Trip; where
transport exists it is shown by name; each product carries its actual loaded quantity from
the canonical execution source, kept strictly separate from Prepared; and Remaining is
remaining-to-load.

**Not certified.** Browser confirmation of the rendered screen remains outstanding and needs
an environment where a warehouse-role user can sign in without mutating live data.

**Stopping here. Phase 2 not started.**
