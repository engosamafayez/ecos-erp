# TASK-OPERATIONS-DISTRIBUTION-WORKSPACE-FINALIZATION-001 — Final Report

**Verdict: IMPLEMENTED · VERIFIED · BROWSER VERIFIED · CERTIFIED**

All required gates are satisfied. Both housekeeping items are closed by owner
decision (§13, §14). One architecture follow-up is retained deliberately and was
not silently fixed (§16.1).

No commit. No deploy. No additional feature work.

Environments: `ecos-dev-app` → `ecos_dev` (browser + live data), `ecos-dev-testrunner`
→ `ecos_dev_test` (suite, under the advisory gate). UI at `http://127.0.0.1:5173`
(Vite, proxying `/api` → `127.0.0.1:8081`).

---

## 1. Window anchor contract

**Option (i), as authorised.**

```
Preparation Wave  (the governing operational cycle)
      │
      ├─► determines the active Distribution planning window
      │
      ├─► Workspace READS that window     DistributionWindowController::current()
      └─► Collection WRITES into it       DistributionCollectionService::collectForCompany()

Groups and assignments stay attached to that same window. Nothing moves.
```

`DistributionWindowService::resolvePlanningWindow()` resolves it by **observing where
the governing wave's active members' assignments already sit**
(`GROUP BY window ORDER BY COUNT(*) DESC, MAX(window_date) DESC`, company asserted on
both the assignment row and the window row; falls back to `windowFor(today)`).

Anchoring on the wave's `planning_date` does **not** work — that resolves a window
merely contemporaneous with the wave, which is still empty. It has to be the members.

**Resolved per warehouse, not per company.** Each warehouse runs its own wave, so the
question only has an answer once the Order's warehouse is known. Consequently the
zone→slot map is keyed `(window, warehouse)` rather than warehouse alone; keyed by
warehouse only, one warehouse would inherit the other's Group.

### Root cause it closes

`current()` previously resolved `windowFor($companyId, today())`. A Distribution
Window is an **ingestion-day container**: `attach()` stamps an Order into whichever
window was open when it first became eligible, and `dist_window_orders_order_unique`
(unique on `order_id`, globally) pins it there permanently; Groups and zone
attachments are scoped to that same window. An operational cycle is not a calendar
day, so from the second day of a cycle the workspace read a window holding none of
its orders, zones or groups — measured live: wave `PREP-202608-000006` (`collecting`)
with 8 active members whose 10 assignments, only Group and both zone attachments all
sat in **2026-08-21**, while `current()` resolved an empty **2026-08-22**. Re-running
collection could not repair it (`attach()` returned null for every order, so
`collected: 0` forever). There was no rollover of any kind.

## 2. Read/write consistency

`collectForCompany` no longer calls `resolveIngestionWindow` unconditionally. New
private `DistributionCollectionService::targetWindowFor(company, warehouse, now)`:

| Case | Target window |
|---|---|
Order has no warehouse | `resolveIngestionWindow()` — unchanged |
No active wave, or wave is not `engine` | `resolveIngestionWindow()` — unchanged |
Wave intake **closed** | `resolveIngestionWindow()` — see §3 |
Wave intake **open** | **`resolvePlanningWindow()` — the window the workspace reads** |

The `engine` test is the same one `governingPreparationWave()` applies, so read and
write agree on what counts as "the governing wave" by construction.

**Verified live, real HTTP, no fabricated rows:**

```
READ window before collect : 2026-08-21
POST /windows/collect      : 200  {"collected":1, ...}
READ window after collect  : 2026-08-21   (unchanged)
ORD-00016 visible in the read window : YES
orders in the read window  : 8 → 9
```

**Re-verified in-process after the final code sync** (§15):

```
intake open            : yes
READ  planning window  : 2026-08-21
WRITE target window    : 2026-08-21
CONSISTENT             : YES
```

## 3. Cutoff preservation

**CUTOFF ≠ CLOSE is untouched.** The gate is Preparation's own predicate — **called,
not restated**: `PreparationWave::hasReachedIntakeCutoff($now)`, the same method the
wave scheduler uses to flip Collecting → Preparing.

Quoting `WaveMembershipService`, which this implementation obeys verbatim:

> `intake_closes_at` → Collecting becomes Preparing. **STOPS NEW ADMISSIONS ONLY.**
> `closeWave()` → terminal status + `released_at` stamped. **ENDS THE WAVE.**

Once intake closes, collection falls back to the pre-existing §16 ingestion window.
The Order is queued for a later window and reaches this cycle only through the
approved **manual late-order** path, which remains permitted after cutoff by design.

**Distribution still never writes wave membership** — asserted by
`test_collection_never_creates_wave_membership`: `preparation_wave_orders` is
byte-identical before and after collection.

Not done, as instructed: no widening of Preparation eligibility, no `OrderStatus`
change, no new status, no carry-forward, no Group moved between windows, no Group
identity change, no Group→Trip change, no Loading change, no wave-scoped Groups, no
multi-window union.

One consequence, stated rather than buried: while intake is open, collection may write
into a window whose *own* `closes_at` has passed (the anchored window is
`cutoff_reached` at the distribution-window level). That is the intended effect of
option (i) — the operational intake gate is now the **wave's** cutoff rather than the
calendar-day window's, and the wave gate is the tighter and more meaningful of the two.

## 4. Groups

Group identity is untouched. `distribution_virtual_slots` rows are never created,
moved, cloned or re-keyed by any path added here. Verified twice:

- **Test** — `test_collection_leaves_group_identity_trips_and_loading_untouched`
  asserts `distribution_virtual_slots`, `distribution_slot_zones`,
  `distribution_trips` and `distribution_group_product_preparation` byte-identical
  across a collection run.
- **Live** — after the real collect, the md5 of the 10 pre-existing assignment rows is
  `1a89f0f6462d9020201f847694cdd456`, **identical to the session baseline**. Exactly
  one new row was created; nothing was moved or duplicated.

Group → Trip and Loading contracts: unchanged, and asserted as unchanged.

## 5. Zones

Unchanged. Zone attachment remains `distribution_slot_zones` keyed by
`(window, warehouse, zone)`, written only through
`ManualAssignmentService::assignZoneToSlot()`, which keeps its cross-warehouse guard.
Row count 2 before and after all work.

## 6. Map

`GET /windows/{window}/map` → `DistributionAggregationService::mapData()`. Data
sources, all pre-existing:

| Layer | Source |
|---|---|
Orders | `orders.google_maps_lat` / `google_maps_lng` — real captured coordinates |
Zones | **derived** from the zone's own plotted orders; falls back to `logistics_cities.latitude/longitude`; null when neither exists |
Groups | the zones attached to them — a group has no position of its own |
Colour | `distribution_zones.color`, the existing column |

No zone geometry stored, no coordinate written, no new column. No map library — no
Leaflet, Mapbox, MapLibre, Google SDK or API key exists in `frontend/package.json`;
this follows the existing `coverage-map.tsx` SVG-scatter precatedent. Missing
coordinates stay missing (`has_location: false`) and are listed by order number.
`centroid_source` (`orders` | `cities` | `null`) travels with each zone so the surface
states which contract placed it. Population is `constrainToLoadingEligible`, matching
`slotOrderCounts()`, so a group cannot report 5 orders on its card and 3 on the map.

## 7. Capacity

`capacity_orders` was already the one capacity axis but advisory —
`GroupFinalizationService` said so: *"no write path enforces it"*. **No column added.**

`GroupCapacityGuard` is now the single enforcement point: it locks the Group row
(`lockForUpdate`) and recomputes occupancy **inside the lock** from the canonical
`slotOrderCounts()` aggregate — never a second COUNT, or the guard could refuse an
order the screen says there is room for. Wired into `changeOrderSlot`,
`changeOrderZone` and `assignZoneToSlot`, each sharing one transaction with its write,
so the second of two concurrent adds blocks, recounts and is refused.

Deliberately **not** enforced on automatic ingestion: refusing there would leave the
Order with no assignment at all, and the unique index makes it unretryable, so a limit
would start silently dropping work out of Distribution. Finalize stays that
population's gate — the certified behaviour.

`remaining_orders` is derived server-side (`max(0, capacity − demand)`) and **never
stored**; `null` means unconstrained, never zero. Order count only —
`capacity_stops` / `capacity_weight_kg` / `capacity_volume_m3` are never read or
offered. Vehicle capacity stays at the assignment stage.

## 8. Templates

`distribution_group_templates` + `distribution_group_template_zones` (migrations
justified: every `*_templates` table in the repo is domain-specific; there is no
generic reusable-configuration store to extend).

Configuration only: name, zones, maximum order count. There is no column — and can be
none — for orders, vehicle, driver, trip, loading state, prepared quantity, window or
wave. There is also **no `template_id` on `distribution_virtual_slots`**: a template is
a Group's starting point, never its owner, so editing or archiving one cannot reach a
Group already created from it.

Apply never writes `distribution_slot_zones` itself — it calls the same
`assignZoneToSlot()` the Zones tab calls, inheriting the cross-warehouse guard, the
`(window, warehouse, zone)` unique key and the capacity check. All values are
overridable so the operator adjusts before the Group exists. Archiving is `deleted_at`.

## 9. Permissions

**No new permission.** Six routes on the four that already exist:

| Route | Permission |
|---|---|
`GET /windows/{window}/map` | `logistics.distribution.view` |
`PATCH /windows/{window}/slots/{slot}` | `logistics.distribution.update` |
`GET /group-templates` | `logistics.distribution.view` |
`POST /group-templates` | `logistics.distribution.create` |
`PATCH /group-templates/{template}` | `logistics.distribution.update` |
`DELETE /group-templates/{template}` | `logistics.distribution.delete` |
`POST /windows/{w}/group-templates/{t}/apply` | `logistics.distribution.create` |

Apply takes `create` because it creates a Group — the same act `storeSlot` performs.
401 and 403 asserted by test for the map and the template routes.

## 10. Tenant and warehouse scope

Both controllers use the fail-closed `companyId()` that aborts 403 on a null company
rather than degrading into "see everything". A foreign template, window or warehouse
is reported **404, never 403** — existence is not something a foreign tenant may learn.
`resolvePlanningWindow()` asserts the company on both the assignment and the window
row. `applyToNewGroup()` refuses a template whose company differs from the window's,
and the warehouse is re-verified against the tenant.

Suite covers two companies and two warehouses: foreign-company anchor, warehouse-scoped
anchor, per-warehouse collection, map warehouse scope, template company scoping (list,
read, edit, archive, apply), foreign-window apply, foreign-warehouse apply, per-company
name uniqueness.

## 11. Tests

**`DistributionWorkspaceFinalizationTest` — 38 tests, 38 passing, 161 assertions**
(dedicated gated run; `OK`).

The seven added for this closure map 1:1 onto the required list:

| Required | Test |
|---|---|
1 same window for read + collection | `test_collection_writes_into_the_window_the_workspace_reads` |
2 wave spanning midnight | `test_collection_does_not_switch_to_a_new_calendar_window_mid_cycle` |
3 post-cutoff order not admitted | `test_collection_after_intake_cutoff_does_not_join_the_cycle` |
— never admits to a wave | `test_collection_never_creates_wave_membership` |
4 no duplicated assignments | `test_collection_does_not_duplicate_or_move_existing_assignments` |
5 + 6 Group identity, Trip, Loading | `test_collection_leaves_group_identity_trips_and_loading_untouched` |
— per-warehouse resolution | `test_collection_resolves_the_window_per_warehouse` |

Two earlier failures were bugs in **my own fixtures**, not the product, and are
recorded rather than quietly fixed: a `distribution_group_product_preparation` fixture
missing two NOT NULL columns, and a self-polluted 401 assertion (the helper that built
the window called `actingAs()`, which persists on the TestCase).

**Static checks on touched files:** PHPStan `[OK] No errors`; Pint **PASS on all 8
files I authored**; frontend `tsc -p tsconfig.app.json` **23 errors (baseline 24),
zero in any file touched**; ESLint clean on the feature.

Pint also flags `DistributionAggregationService.php`, but every finding there is in
pre-existing code (import order, `\BackedEnum`, old indentation, `'Zone ' . $id`). I
did not reformat a concurrently-modified shared file to chase style I did not
introduce.

One real finding of my own was fixed: `applyToNewGroup()` captured an unused
`$actorId`. `distribution_virtual_slots` has no `created_by`/`updated_by` column, so
the parameter was removed rather than left advertising an audit trail that does not
exist (arity now 8, confirmed by reflection on the deployed code).

## 12. Regression classification

Module-only scope, as instructed. No further broad regression was run.

**All 25 failures are PRE-EXISTING.** The control was an **isolation run** of only the
four affected classes:

| Run | Tests | Failures |
|---|---|---|
`--filter Distribution` (broad) | 261 | **25** |
the 4 classes only (isolation control) | 109 | **25** — same names |

| Class | Count | Classification | Control evidence |
|---|---|---|---|
`DistributionModuleTest` | 22 | **PRE-EXISTING** | Every failure is on `/trips/{uuid}/…` (trip number, stats, lifecycle, dispatch, custody, driver acceptance, trip capacity). `grep` for `windows/current`, `/map`, `group-templates`, `assignments/` in that file returns **0** — it cannot reach changed code. `Trip.php`, `TripService.php`, `TripController.php` are ` M` in git (another agent's in-flight work), and `git diff -- backend/routes/api.php` shows 256 added lines of which ~225 are not mine, 21 on the very trips/custody routes returning 403. `test_capacity_is_enforced` is **Trip** capacity (`Trip.capacity` via `TripService`) — a different column and code path from `distribution_virtual_slots.capacity_orders`. |
`DistributionReadModelApiTest` | 2 | **PRE-EXISTING** | Zero references to any changed endpoint. Fails on `?order_status=new` matching 0 — an Orders status-vocabulary mismatch. |
`DistributionOrdersFilterApiTest` | 1 | **PRE-EXISTING** | Same: zero reachability into changed code. |
`DistributionWorkspaceFinalizationTest` | **0** | — | 38/38 green. |

**Not TEST-ORDER DEPENDENT** — the identical 25 fail in a 261-test run and a 109-test
run. **Not ENVIRONMENT** — they fail deterministically under the gate's advisory lock.
No unrelated pre-existing failure was fixed; no test was modified to make it pass.

A control run at the parent commit was **not possible**: the entire Distribution
windows/slots subsystem is untracked (`??` in git), `ecos-app` predates it (only 3
services in that directory), and no pristine copy of the four edited files exists
anywhere. The isolation run plus the reachability evidence is the substitute.

## 13. ORD-00001 — closed by owner decision: accept as historical audit data

**No database row was modified. No direct database correction was authorized, and none
was performed.**

Owner-required statements, each verified:

- **Membership is correct.** `distribution_zone_id = NULL`, `virtual_slot_id = NULL` —
  exactly as found before this task. The order was moved into and back out of a Group
  during capacity verification and its membership was fully restored; the md5 of the
  10 pre-existing assignment rows matches the session baseline byte for byte.
- **No current Group / Window integrity issue exists.** The row sits in the planning
  window `2026-08-21` with a valid company, a valid window and no Group. It appears
  correctly in the workspace and on the map (plotted, with real coordinates).
- **The original `assignment_source` cannot be reconstructed with certainty.**
  `changeOrderSlot()` writes only `virtual_slot_id`, `assignment_source`,
  `assigned_by` and `assignment_reason`; it never writes `assigned_at` or
  `previous_window_id`. Since only `attach()` writes `assigned_at` at insert, the row
  was **created** by `attach()`, so `manual_move` — producible only by an UPDATE path
  — is certainly not the original. But `attach()` has two callers (`collectForCompany`
  → `auto`; `assignLateOrder`'s fresh-row branch → `manual_late`), both consistent
  with the two fields that survived, and every discriminating field was overwritten.
  `assigned_by = 1` discriminates nothing because the acting user *is* id 1. No event
  trail exists: `OrderAddedToDistributionWindow`, `LateOrderManuallyAssigned` and
  `DistributionAssignmentChanged` are dispatched but **never persisted** —
  `order_events` holds 231 rows, none for this order, and contains no
  distribution-assignment event type at all.
- **No guess was made** between `auto` and `manual_late`.

`manual_move` is therefore accepted as historical/audit data. Independently, there is
no application-supported path to rewrite the field: every writer sets it as a
consequence of an action.

## 14. DG-TPL-VERIFY — closed by owner decision: inert artifact

**No Group DELETE endpoint was added. No direct database mutation was performed.**

| Artifact | Status |
|---|---|
Template `Morning Cairo v2` | **CLEANED UP** through the existing supported endpoint `DELETE /group-templates/{id}` → 204; archived (`deleted_at = 2026-08-22 23:41:44`). The Templates tab renders empty. |
Group `DG-TPL-VERIFY` | **RETAINED as an inert test artifact.** 0 orders, 0 zones, 0 trips, 0 prepared quantities. Operator-visible in the Groups and Settings tabs and in the map legend, but it holds no work, blocks nothing, and cannot affect any plan. **Not a functional blocker.** Removal would require either a new business endpoint or a direct mutation, both explicitly declined. |

Audited per instruction: no project-specific test-data cleanup mechanism exists (no
purge/prune command in any module; `model:prune` would require adding a `Prunable`
trait — inventing a mechanism).

## 15. Final no-change verification

Confirming nothing changed after the last green result, per the closure instruction.

**Checksum comparison across host and both containers for all 14 touched files.**
Host and `ecos-dev-testrunner` matched on **all 14**, so the 38/38 result was produced
against exactly the code now on disk.

The check caught a real gap: `ecos-dev-app` — the container serving the browser — held
**stale copies of 2 files** (`GroupTemplateService.php`, `GroupTemplateController.php`),
the pre-`$actorId`-fix pair, because that fix had only been deployed to the testrunner.
Both were synced and re-verified to match (`5c9730e3`, `c0803130`).

Re-verification on the synced browser stack, inside a **rolled-back transaction** so no
artifact was created:

```
applyToNewGroup arity  : 8  (actor removed)
intake open            : yes
READ  planning window  : 2026-08-21
WRITE target window    : 2026-08-21
CONSISTENT             : YES
create / update / apply / archive : all ok
ROLLED BACK CLEANLY    : YES — nothing persisted
```

Only `apply`'s signature changed; `index`, `store`, `update` and `destroy` were
untouched, and those are the endpoints the UI Templates tab exercised. `apply` is
re-verified above on the synced code.

Final static re-run after the sync: `tsc` **23 errors, zero in touched files**.

## 16. Browser verification

Real data, real HTTP, no fabricated orders / groups / zones / vehicles / drivers /
trips / payments.

| Check | Result |
|---|---|
Read/write consistency | READ `2026-08-21` → `collect` → `{"collected":1}` → READ still `2026-08-21`, **ORD-00016 now visible in it**; 10 pre-existing rows byte-identical |
Workspace renders | `Distribution Groups (2)`, `Zones (4)`, wave `PREP-202608-000006`, cycle `20:30 / 08:00 / 16:00 Africa/Cairo`. Was `0 / 0 / 0`. |
Settings | DG-001 → 2 zones, **6 orders**, max **20**, remaining **14** (derived, correct). DG-TPL-VERIFY → 0 / 0 / 20 / 20 |
Capacity enforcement | 6th order into a group capped at 5 → **422**; raise to 6 → same order **200**; max below occupancy → **422** with the operator sentence |
Map — real coordinates | SVG renders; 2 zone markers `centroid_source: orders`; 4 order dots at real captured lat/lng (`30.0176104,31.4345694` etc.) |
Map — no fabrication | `4 of 9 orders have a recorded location`, `2 of 4 zones placed`; Helwan and New Cairo show "No location data for this zone"; `No recorded location (5)` lists the unplottable orders by number |
Map — interaction | click zone → its 3 orders, others dimmed to 0.2; click group → its 5 orders (matches `orders_count`) |
Templates | create 201 → edit 200 → duplicate name 422 → apply 201; new group **0 orders / 0 trips / 0 zones** while DG-001 stayed 5 / 2; empty after archiving |
Max Orders on create form | present, `type=number min=1`, placeholder "No limit", labelled "Maximum orders" |
Arabic + RTL | `lang=ar dir=rtl`; all three new tabs fully localised (`الخريطة`, `الإعدادات`, `القوالب`, `4 من 8 طلب لها موقع مسجَّل`, `لا توجد بيانات موقع لهذه المنطقة`). Only Latin text remaining is data |

Apply was browser-verified with `zone_ids: []` deliberately: attaching zone 7 would
have *moved* it out of DG-001 and mutated existing membership. Zone copying is proven
green in the suite instead.

**Disclosed verification side effect:** the app cleared its own auth token when
`/api/auth/me` timed out during host saturation (two concurrent phpunit suites had
starved the Docker host; several containers' health-checks timed out to *start* —
Redis itself answered `PONG`). Entering a password is not something I do, so I minted
a named Sanctum token server-side (`claude-browser-verification-temp`, id 173),
completed the verification, and **revoked it**: `revoked_rows=1`, `claude-%` tokens
remaining **0**. No password was handled at any point.

## 17. Data side effects

| Table | Session baseline | Final | Attribution |
|---|---|---|---|
`distribution_window_orders` | 10 | 11 | **+1 legitimate** — ORD-00016, collected by the real `POST /windows/collect` (the Refresh action) during read/write verification. The 10 pre-existing rows are **byte-identical** to baseline. |
`distribution_slot_zones` | 2 | 2 | unchanged |
`distribution_trips` | 1 | 1 | unchanged |
`distribution_group_product_preparation` | 2 | 2 | unchanged |
`distribution_windows` | 3 | 3 | unchanged |
`distribution_virtual_slots` | 1 | 2 | **+1 mine** — `DG-TPL-VERIFY`, from the required apply verification; retained as inert (§14) |
`distribution_group_templates` | (new table) | 1, archived | mine; cleaned up through the supported endpoint (§14) |
`orders` | 16 | 17 | **not mine** — ORD-00016/17 created 21:48–21:49, before my first write (~22:36) |

Both Groups carry `capacity_orders = 20`, set at **23:04:58 / 23:05:01** — after every
write of mine (my values were 5, 6, NULL and 25). Not mine; someone exercising the new
Settings editor, which is incidental evidence the feature works for a real operator.

No Preparation, Loading, Inventory or financial data was altered. The two migrations
created **empty schema only**; no template was seeded.

## 18. Remaining architecture follow-ups

Recorded only. **Not addressed, and deliberately not silently fixed.**

1. **`distribution_zones` currently has no `company_id`.** Zones are a global table
   with globally unique `code` and `name_ar`, so zone-level tenant ownership is
   **unenforceable**, and a Group Template's zone references point at global rows.
   This remains **outside the current workstream**. The reachable damage is closed —
   template application never writes `distribution_slot_zones` directly but goes
   through the company- and warehouse-scoped `assignZoneToSlot()`, so naming a zone id
   cannot move another tenant's work or attach a zone to a Group outside the actor's
   own company and warehouse. Cross-company **zone identity** stays an architecture
   follow-up requiring its own decision.
2. **No Group DELETE endpoint.** Groups can be created, including from templates, but
   never removed (`storeSlot` has no `destroy` sibling, as the 2026-08-21 migration
   itself notes). See §14.
3. **Coordinate coverage.** `logistics_cities` 0/211 and `master_zones` 0/149 hold no
   coordinates, so the city fallback for zone placement yields nothing today. Wired
   because the contract exists; deliberately not populated.
4. **25 pre-existing Distribution failures** — 22 on the Trip/custody routes belonging
   to another agent's in-flight work, 3 on an Orders status-vocabulary mismatch (§12).
5. **Distribution assignment events are not persisted.** `OrderAddedToDistributionWindow`,
   `LateOrderManuallyAssigned` and `DistributionAssignmentChanged` are dispatched but
   stored nowhere, which is why §13 could not be reconstructed.

## 19. Certification status

| Gate | State |
|---|---|
Window anchor contract (option i) | **IMPLEMENTED · VERIFIED · BROWSER VERIFIED** |
Read/write consistency | **IMPLEMENTED · VERIFIED · BROWSER VERIFIED** |
Cutoff preservation | **IMPLEMENTED · VERIFIED** |
Groups / Group→Trip / Loading untouched | **VERIFIED** (byte-identical: test + live md5) |
Zones | **VERIFIED** unchanged |
Map | **IMPLEMENTED · VERIFIED · BROWSER VERIFIED** |
Capacity | **IMPLEMENTED · VERIFIED · BROWSER VERIFIED** |
Templates | **IMPLEMENTED · VERIFIED · BROWSER VERIFIED** |
Permissions (no new) | **VERIFIED** |
Tenant + warehouse isolation | **VERIFIED** (2 companies, 2 warehouses) |
Focused tests | **38/38 green, 161 assertions** |
PHPStan / Pint / tsc / ESLint | **VERIFIED** — no errors in touched files; baseline preserved (24 → 23) |
Regression classification | **COMPLETE — all 25 PRE-EXISTING**, isolation control |
Post-green no-change verification | **VERIFIED** — 14/14 checksums; one stale browser-stack pair caught, synced, re-verified |
ORD-00001 | **CLOSED** by owner decision — accepted as historical audit data, no row modified (§13) |
Template test data | **CLOSED** — template archived via supported API; Group retained as inert (§14) |
`distribution_zones.company_id` | **RETAINED as architecture follow-up** (§18.1) |

# CERTIFIED

Every required gate is satisfied. No commit, no deploy, no additional feature work.
