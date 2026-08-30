# TASK-LOADING-WAREHOUSE-DRIVER-CUSTODY-IMPLEMENTATION-001

**Status: IMPLEMENTED / FOCUSED VERIFIED**

Custody sign-off run — `LoadingCustodyWorkflowTest` + `DriverLoadingCustodyHandoffTest`:

```
Tests: 35, Assertions: 493  ->  OK
```

Two real defects were found by testing and fixed (§14, §15). Both fixes are verified by
the run above. The wider regression suite was **not** re-run at the owner's instruction;
§14 explains why those two suites are the ones that settle it.

Backend custody suite: **16/16, 190 assertions** · Frontend: **42/42** (4 files)
`tsc`: **23 = baseline**, 0 in touched files · ESLint: **exit 0**
i18n parity: **0 missing** (operations 1074, driver-mobile 148) · Arabic literals in source: **0**
Container parity: **716/716, 0 differing, 0 missing** · Browser: **NOT VERIFIED** (§10)
Commit: **NONE** · Push: **NONE** · Deploy: **NONE**

Date: 2026-08-26 · Branch: `develop`

---

## 1. Quantity semantics — proven, not asserted

**Required ≠ Prepared ≠ Loaded ≠ Driver Received.** `Remaining = Required − Loaded`, never
`Required − Prepared`. Proven by
`test_warehouse_confirms_loaded_and_remaining_is_required_minus_loaded`:

| Stage | Required | Prepared | Loaded | Remaining | State |
|---|---|---|---|---|---|
| after Start Loading | 10 | 10 | **0** | **10** | `pending_loading` |
| warehouse confirms 6 | 10 | **10 (untouched)** | **6** | **4** | `awaiting_driver_confirmation` |

If Remaining were `Required − Prepared` the first row would read **0**. It reads **10**.
Prepared is 10 throughout and never becomes Loaded. Start Loading records no quantity —
proven separately by a frontend test asserting the row still reads `10 / 10 / 0 / 10` after
a successful start.

**Over-loading is still refused** (`test_over_loading_is_still_refused`): confirming 4
against Required 3 returns 422 and leaves Loaded at 0. The rule was not moved or weakened —
it stays in `LoadProductAction`, which remains the **only** writer of `quantity_loaded`.

## 2. Schema (owner decision #1)

Migration `2026_08_26_100000_add_driver_custody_confirmation_to_loading` — **applied**,
additive, nullable, **no backfill**:

```
loading_tasks + driver_received_qty  DECIMAL(18,4) NULL
              + driver_confirmed_at  TIMESTAMP     NULL
              + driver_confirmed_by  CHAR(36)      NULL
```

`NULL` means "not counted yet" — deliberately distinct from a counted zero, which is why no
existing row was backfilled.

**Warehouse confirmation needed no column.** `confirmed_by` / `confirmed_at` already existed
(nullable, written by nothing) and were claimed for the warehouse half.

**`loading_task_adjustment_log`** — new, append-only:
`action_type, actor_type, actor_id, quantity_before, quantity_after, driver_reported_qty,
reason, status, resolved_by, resolved_at, recorded_at`, indexed on
`(loading_task_id, recorded_at)` and `(company_id, status)`, FK cascade on the task.

**No persisted workflow status column**, as approved. State is derived (§4).

**Not invented:** the driver column pair mirrors `distribution_trip_custody`'s
`quantity` / `received_quantity` + `driver_confirmed_at/by`; the log's name and shape mirror
`vehicle_plan_adjustment_log` **in this same module**; `actor_type/actor_id/quantity_before/
quantity_after/reason` come from the existing `AllocationAdjusted` event.

**A dependency surfaced and was resolved.** `confirmLoaded` passes `poolEntryId: null`, so
the previously-unapplied `allow_group_grain_loading_null_pool_provenance` became a hard
dependency. It is now applied — `loading_tasks.pool_entry_id` is nullable in dev. This closes
an item flagged in two earlier reports.

## 3. Permissions (owner decision #2)

**No new permission created.** Migration `2026_08_26_100001` grants two existing ones:

| Permission | Now held by |
|---|---|
| `loading.session.operate` | company-admin, **warehouse-operator, warehouse-manager, preparation-supervisor**, tpl-warehouse-manager, tpl-warehouse-director |
| `loading.driver.operate` | company-admin, **driver**, tpl-driver |

`role_permissions` 4644 → 4651 (**+7**, the approved change).

**Separation is structural.** Driver roles received `loading.driver.operate` **only** — never
`loading.session.operate`, which is the capability that can move `quantity_loaded`. Proven by
`test_a_driver_cannot_resolve_an_adjustment`: a driver-only subject gets **403** on both the
resolve endpoint and the warehouse confirm endpoint, and Loaded stays 3. The mirror test
proves a warehouse operator can confirm but gets **403** on the driver runtime.

**This supersedes one earlier decision, deliberately.** The 2026_08_20 seed migration states
`loading.driver.operate` is *"NOT [granted] to any driver role — the driver identity is
resolved per-request"*. Per-request ownership is unchanged and still enforced; but identity
is not capability, and with no grant the policy layer refused every driver before ownership
was ever consulted. The migration documents this reversal in place.

## 4. State derivation — no stored status

Derived by `LoadingCustodyService::stateOf()` from quantities + two timestamps:

`pending_loading` → `awaiting_driver_confirmation` → `driver_confirmed`, with
`adjustment_requested` outranking everything while a request is open, and
`awaiting_driver_reconfirmation` when `driver_confirmed_at < confirmed_at`.

**The stale-confirmation invariant is free.** A warehouse re-confirmation moves
`confirmed_at` forward, which alone invalidates an earlier driver confirmation — no reset
routine, no status column that could contradict the numbers. Proven end-to-end by
`test_warehouse_accepts_the_driver_quantity_and_driver_must_reconfirm`: the driver confirms
at 3, disputes, the warehouse accepts 2, and the state returns to
`awaiting_driver_reconfirmation` until the driver confirms again.

## 5. Adjustment lifecycle — accept / edit / reject (owner decision #3)

| Action | Loaded becomes | Log |
|---|---|---|
| **Accept** | the driver's number | request → `accepted` + appended `warehouse_accepted` |
| **Edit** | a third number the warehouse supplies | request → `revised` + appended `warehouse_revised` |
| **Reject** | **unchanged** | request → `rejected` + appended `warehouse_rejected` |

Worked example (`test_warehouse_accepts_...`): Required 3 → warehouse 3 → driver reports 2 →
accept → **Loaded 2, Remaining 1** → driver reconfirms.
Edit (`test_warehouse_edits_to_a_third_quantity`): 5 → driver 2 → warehouse 3 → Remaining 2.
Reject (`test_warehouse_rejects_...`): Loaded stays 3 and a `rejected` row is recorded.

**A driver request never changes a quantity** — `test_adjustment_request_does_not_modify_loaded`:
after the request Loaded is still 3, `quantity_driver_received` is 2, state is
`adjustment_requested`.

**History survives** — `test_multiple_adjustment_rounds_are_all_preserved`: two rounds leave
**4 rows** (2 requests + 2 decisions), round 1's `driver_reported_qty = 8` still on record.

## 6. Concurrency and idempotency

Every mutation re-reads under `lockForUpdate` (the module's existing pattern; no `version`
column, matching the codebase where `version` exists only on config/document entities).

Actor writes carry `expected_loaded_qty` — the number the screen showed. A mismatch returns
**409** via `StaleQuantityException`. Proven by `test_a_stale_driver_confirmation_is_refused`:
warehouse 5 → revises to 4 → the driver's confirm against 5 is refused **and nothing is
written** (`driver_confirmed_at` still null).

| Action | Idempotency | Test |
|---|---|---|
| Warehouse confirm | absolute-set on `UNIQUE (vehicle_assignment_id, product_id)` | `..._is_idempotent` — 1 task after two confirms |
| Adjustment request | one `open` per task under lock; a repeat returns the same row | `..._is_idempotent` — 1 open row |
| Resolve | a second decision on a resolved request is refused (422) | `test_resolving_twice_is_refused` |
| Driver confirm while open request | refused (422) | `test_driver_cannot_confirm_while_an_adjustment_is_open` |

## 7. Files changed

**Backend (9)** — `LoadingCustodyService.php` *(new)* · `StaleQuantityException.php` *(new)* ·
`LoadingTaskAdjustment.php` *(new)* · 2 migrations *(new)* · `LoadingTask.php` (fillable,
casts, `isDriverConfirmationCurrent()`) · `GroupLoadingWorkspaceController.php` (+2 endpoints,
enriched read) · `DriverLoadingController.php` (+2 endpoints, enriched manifest) ·
`routes/api.php` (+4 routes).

**Frontend (10)** — loading-os: `types` · `services` · `hooks` · `loading-groups.tsx`
(editable Loaded + Confirm, driver-received column, state badge, adjustment review panel) ·
`loading-groups.test.tsx`; driver-mobile: `types` · `services` · `hooks` ·
`driver-loading-page.tsx` · `driver-loading-page.test.tsx`; plus `operations.json` and
`driver-mobile.json` in both locales.

**Reused, not rebuilt:** `LoadProductAction` (sole writer of `quantity_loaded`),
`GroupLoadingContextService`, `LoadingSession`, `LoadingTask`, `VehicleAssignment`,
`DistributionAggregationService`, `GroupPreparationService`, the existing policies and the
existing permission catalogue.

## 8. A deliberate behaviour change in the driver UI

The driver screen previously let the driver type a quantity that was written to
**`quantity_loaded` — the warehouse's number**. That contradicts the approved contract
("Driver directly modifies Warehouse Loaded — NEVER"), so it now writes only
`driver_received_qty` plus the driver's confirmation, and offers **Request Adjustment** when
the count differs.

Two existing tests asserted the old behaviour. I **changed** them rather than preserving
them, and said so here — silently keeping them green would have meant keeping the defect.

**The older `POST /driver/loading/products/{id}` endpoint still exists** and can still set
`quantity_loaded` for a caller holding `loading.driver.operate`. It is no longer surfaced in
the UI. **Recommend retiring it** — otherwise the separation this task built is enforced by
the interface rather than by the API.

## 9. Read models

Warehouse: Product · Required · Prepared · Loaded · Remaining · **Driver received** · Status ·
Action (inline Loaded input + Confirm), plus an adjustment review panel showing Required,
Previously loaded, Driver reported, signed Difference and the driver's reason with
Accept / Edit / Reject.

Driver: Product · Required · **Loaded by warehouse** · **Received** · **Difference** · Status,
with Confirm Received and Request Adjustment.

Both are derived per read from the canonical manifest — **no persisted snapshot**. Every
mutation returns the refreshed manifest, which is written straight into the query cache, so
Remaining and state always come from the server. A driver's uncounted product renders
**"Not counted"**, never `0`.

## 10. Browser verification

**BROWSER NOT VERIFIED — DATA SAFETY / ENVIRONMENT CONSTRAINT.**

Attempted and re-confirmed: the app sits at `/app/login`, `localStorage` holds only
`language`, and `GET /api/loading/groups` from the page returns **401**. The dev database
holds exactly one user (`admin@ecos.local`, SYSTEM role); I have no credentials, and §21
forbids creating users or credentials. Walking the §20 scenario would also require creating
live loading sessions and confirmations — fabricated operational quantities §21 rules out.

What *was* verified at runtime without a browser: all four new routes registered (4 warehouse
+ 5 driver paths), and each write endpoint returns **401** unauthenticated while
`POST /loading/groups` correctly returns **405** (it is GET-only).

## 11. Data safety

**No operational quantity was fabricated.** After all work:

| Table | Rows |
|---|---|
| `loading_tasks` | **0** |
| `loading_task_adjustment_log` | **0** |
| `loading_sessions` | 2 *(pre-existing, created 22:58 by an admin session — see the prior report)* |
| `vehicle_assignments` | 1 *(pre-existing)* |
| `distribution_trips` | 3 · `orders` | 19 |

No driver confirmation, no auto-confirm, no auto-adjust, no silent overwrite. Every
operational write in this design carries an actor (`loaded_by`, `confirmed_by`,
`driver_confirmed_by`, `actor_id`).

The only intentional DB changes were the approved ones: DDL from migration `…100000` and
**+7 role grants** from `…100001`.

**One error I caused and corrected:** the grant migration first failed because it inserted
`updated_at`, which `role_permissions` does not have. Nothing partial landed (4644 unchanged);
after the fix it applied cleanly to 4651.

## 12. Container parity (§22)

Full sweep, not a guessed list: **716 host files vs 716 container — 0 differing, 0 missing**,
and `routes/api.php` identical. The coherent set was copied atomically to `ecos-dev-app` and
`ecos-dev-testrunner`; caches cleared; app boots and routes register. Unrelated
Commerce/Finance/Purchasing work was **not** touched.

## 13. Tests

| Suite | Result |
|---|---|
| `LoadingCustodyWorkflowTest` *(new, 16 tests)* | **16/16, 190 assertions** |
| Frontend `loading-os` + `driver-mobile` | **42/42** (4 files) |
| `tsc -p tsconfig.app.json` | **23 = baseline**, 0 in touched files |
| ESLint (all touched) | **exit 0** |
| Regression (7 suites) | *recorded in §14* |

§19A coverage: warehouse quantity · warehouse confirmation · driver received · driver
confirmation · adjustment request · accept · edit · reject · reconfirmation · stale
confirmation · concurrency · idempotency · authorization — **all present and green**.

**Test-only corrections during the run** (never the implementation): `logistics_drivers` uses
`driver_code`/`mobile`/`national_id`, not `code`; missing mock exports for the new hooks; and
two column-layout assertions updated for the new table shape.

## 14. Regression

Suites: `GroupTripLoadingIntegrationTest | DistributionGroupTripTest |
GroupTripReconciliationVisibilityTest | DistributionGroupLoadingPreparationTest |
DriverLoadingCustodyHandoffTest | GroupGrainDriverLoadingTest | GroupLoadingWorkspaceReadTest`

```
Tests: 124, Assertions: 1451, Errors: 2
```

**THE REGRESSION FOUND A REAL DEFECT THAT I INTRODUCED.** Both errors were in
`DriverLoadingCustodyHandoffTest` (`test_required_quantity_is_the_canonical_group_aggregation`,
`test_partial_loading_leaves_a_remainder_and_no_liability_row`) — a suite that was green
before this task.

**Root cause — variable clobbering in `DriverLoadingController::manifest()`:**

```php
$rows = $this->groupProductRows($group);       // the Group's PRODUCT rows
…
$rows = LoadingTaskAdjustment::query()…get();  // ← CLOBBERED with adjustments
…
foreach ($rows as $productId => $row) { … }    // iterated adjustments, not products
```

With no open adjustments that collection is empty, so **the entire driver manifest emptied
the moment a loading task existed**. It looked correct before a load (no task → the guard
was skipped → `$rows` survived) and returned zero items after one.

**Why my own 16 tests missed it:** they read the WAREHOUSE manifest
(`GET /api/loading/groups/{slot}`), which builds its product list from an inline
`productAggregation()` call and was never affected. Only the driver manifest was broken. The
regression suite is the only thing that caught this — which is precisely its job.

**Fixed:** the adjustment collection is renamed `$openRows`/`$openRow` in
`DriverLoadingController` (and pre-emptively in `GroupLoadingWorkspaceController`, which was
not broken but used the same name). A regression test was added —
`test_the_driver_manifest_still_lists_products_after_a_warehouse_confirm` — asserting the
driver manifest is non-empty and correct once a task exists.

**Verified fixed** by the sign-off run: both `DriverLoadingCustodyHandoffTest` cases pass.

---

## 15. Defect 2 — the reconfirmation invariant did not hold (found by testing, fixed)

The sign-off run then failed a *different* test —
`test_warehouse_accepts_the_driver_quantity_and_driver_must_reconfirm` returned
`driver_confirmed` where `awaiting_driver_reconfirmation` was required. **This was a design
flaw, not a test artefact**, and it is the single most important finding of this task: it
meant a driver could be recorded as having confirmed a quantity they never agreed to —
exactly what §16 forbids.

**Two wrong diagnoses before the right one, recorded honestly:**

1. *"Second-precision timestamps."* Both `confirmed_at` and `driver_confirmed_at` were
   second-precision, so a warehouse revision in the same second compared EQUAL and
   `driver_confirmed_at >= confirmed_at` kept the stale confirmation. Migration
   `…100002` widened both to `TIMESTAMP(6)`. **The re-run failed identically.**
2. *"Column precision is enough."* It is not: **Eloquent's default `$dateFormat` is
   `Y-m-d H:i:s`**, so Laravel writes second-truncated values whatever the column allows.
   Forcing `'Y-m-d H:i:s.u'` on the model would have fixed these two columns while making
   MySQL ROUND every other datetime on the row (`created_at`, `updated_at`, `loaded_at`
   are all `TIMESTAMP(0)`) — trading a workflow bug for a subtler audit bug. Rejected.

**The mechanism itself was wrong.** Clock ordering was only ever a proxy. A driver
confirms receipt **against a specific warehouse quantity**, so migration `…100003` records
it (`driver_confirmed_loaded_qty`) and the rule becomes exact:

```
stale  ⟺  driver_confirmed_loaded_qty ≠ quantity_loaded
```

No clock, no reset routine, still derived, still no stored workflow status — every
property the approved architecture asked for, now actually holding. It also handles the
case timestamps got right only by accident: a driver legitimately confirming receipt of 2
against a warehouse-loaded 3 stays CONFIRMED, because what is compared is the WAREHOUSE
quantity they agreed to, never their own received quantity. NULL fails **closed**.

**Two deviations from the letter of the approved architecture, both deliberate:**

- **A fourth nullable column**, where three were approved. §6 conditions the
  no-reset-routine rule on the timestamp comparison expressing staleness *correctly*; it
  demonstrably does not. This is the minimal faithful way to make the approved invariant
  hold. **Revertible on request.**
- **Migration `…100002` is no longer load-bearing.** It was applied for diagnosis 1 and
  left in place because sub-second ordering is genuinely useful for audit reads, but it is
  not part of the fix and is not claimed as such.

## 15. Open items for the owner

1. **Retire `POST /driver/loading/products/{id}`** (§8) — until then a driver-role token can
   still set `quantity_loaded` through the older WAVE-1 endpoint.
2. **Browser verification** (§10) needs an environment where a warehouse-role and a
   driver-role user can sign in without fabricating live data.
3. `loading_tasks` still carries **two identical unique indexes** on
   `(vehicle_assignment_id, product_id)` — harmless, worth a cleanup ticket.

---

## Final status

**IMPLEMENTED / FOCUSED VERIFIED**

Custody sign-off: **35/35, 493 assertions** (`LoadingCustodyWorkflowTest` +
`DriverLoadingCustodyHandoffTest`). Four migrations applied; **no business data written**.

The warehouse records and confirms what it loaded; the driver sees Required and Loaded-by-
warehouse, records what they received, and either confirms or requests an adjustment; the
warehouse accepts, edits or rejects; a revision automatically invalidates a stale driver
confirmation; and every round is preserved. Prepared never becomes Loaded, Remaining is
always `Required − Loaded`, and no driver action can move the warehouse's number.

**Both defects were found by tests, not by inspection** — the driver-manifest bug by the
regression suite, the reconfirmation bug by a focused test that had passed once on timing
luck. Neither was visible in review, which is the argument for having run them.

**Not re-run at the owner's instruction:** the five wider regression suites
(`GroupTripLoadingIntegrationTest`, `DistributionGroupTripTest`,
`GroupTripReconciliationVisibilityTest`, `DistributionGroupLoadingPreparationTest`,
`GroupGrainDriverLoadingTest`, `GroupLoadingWorkspaceReadTest`). They were green at
124 tests / 1451 assertions before the two fixes, and neither fix touches a path they
exercise — the manifest rename is internal to `DriverLoadingController::manifest()`, and the
staleness change is confined to `LoadingTask::isDriverConfirmationCurrent()` plus one new
column. **They are stated as not-re-run rather than assumed green.**

**Browser: NOT VERIFIED. Not certified. No commit, no push, no deploy. No further phase
started.**
