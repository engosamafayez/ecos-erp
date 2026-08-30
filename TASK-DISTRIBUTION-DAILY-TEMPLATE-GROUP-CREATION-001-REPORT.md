# TASK-DISTRIBUTION-DAILY-TEMPLATE-GROUP-CREATION-001 — REPORT

**Status: STOPPED — OWNER DECISION REQUIRED**
Date: 2026-08-24 · Branch: `develop` · **No code changed. No migration created.**

Discovery was completed in full (PART 1). Implementation was not started, because
**four of the nine PART 18 STOP conditions are hit**, three of them proven with live data
rather than inferred. Each is reported below with the exact decision required.

Nothing was mutated: every query in this task was a `SELECT`.

---

## 1. Discovery

Canonical services and tables located and read:

| Concern | Canonical owner |
|---|---|
| Wave creation / lifecycle | `preparation_waves` (+ the Preparation wave services) |
| Window creation | `DistributionWindowService::windowFor()` / `resolveOrCreatePlanningWindow()` |
| Order collection into a Window | `DistributionCollectionService::collectForCompany()` |
| **Eligible orders** | `DistributionCollectionService::eligibleUnassignedOrders()` |
| Template → Group creation | `GroupTemplateService::applyToNewGroup()` |
| Group creation | `VirtualCapacitySlot` via that same service |
| Template → Zone ownership | `GroupTemplateService::zoneOwnership()` / `claimZones()` |
| Zone → Group attach (+ capacity) | `ManualAssignmentService::assignZoneToSlot()` |
| Group capacity guard | `GroupCapacityGuard::assertHasHeadroom()` |
| Operator-controlled creation | `POST /windows/{window}/slots` and `.../group-templates/{template}/apply` |

Tables inspected: `distribution_group_templates`, `distribution_group_template_zones`,
`distribution_virtual_slots`, `distribution_slot_zones`, `distribution_windows`,
`distribution_window_orders`, `preparation_waves`, `distribution_trips`.

**Note on naming:** there is no `distribution_groups` table. The Group is
`distribution_virtual_slots`.

---

## 2. Existing Group creation architecture

`GroupTemplateService::applyToNewGroup()` is the canonical path and **can be reused**
(STOP #3 is *not* hit):

1. Refuses cross-company application.
2. Reads the template's **current** `zoneIds()` and `capacity_orders` at apply time.
3. Creates the `VirtualCapacitySlot` with `company_id`, `distribution_window_id`,
   `warehouse_id`, `code`, `name`, `capacity_orders`.
4. Attaches each zone via `ManualAssignmentService::assignZoneToSlot()` — the same attach
   the Zones tab uses, with all of its guards.

It is triggered by an operator, and requires two operator-supplied inputs: `warehouse_id`
and `code`.

---

## 3. Existing Wave / Preparation architecture

| Entity | Uniqueness | Scope |
|---|---|---|
| `preparation_waves` | `(company_id, warehouse_id, planning_date, wave_type)` + `starts_at` / `intake_closes_at` / `ends_at` | **per warehouse** |
| `distribution_windows` | `(company_id, window_date)` — enforced unique | **per company** |

`resolveOrCreatePlanningWindow($companyId, $waveId, $warehouseId, $now)` accepts a wave id
and uses it **only to resolve** an existing anchor. When it has to create, it falls through
to `windowFor($companyId, $date, $now)`, which keys on the **calendar date alone**.

**The wave id is never persisted on the window.** `distribution_windows` has no
`preparation_wave_id` column.

---

## 4. Template → Zone source of truth

`distribution_group_template_zones`, read through `GroupTemplateService::zoneOwnership()`,
with exclusivity enforced by `claimZones()` under a zone row lock. Reused as-is; nothing
here needs a second engine (PART 7 satisfied).

---

## 5. Eligible Order source of truth

`DistributionCollectionService::eligibleUnassignedOrders($companyId)` — one canonical
method, already the input to the collector's candidate loop.

**STOP #4 is NOT hit.** Eligibility is unambiguous and reusable, and no new definition
would be needed.

---

## 6. STOP conditions hit

### STOP #1 and #5 — "one Group per Template per Wave" needs a migration

**No table anywhere links a Group to the Template it came from.** An
`information_schema` sweep for every column matching `%template%` or `%virtual_slot%`
returns:

```
distribution_group_product_preparation   virtual_slot_id
distribution_group_template_zones        distribution_group_template_id
distribution_slot_zones                  virtual_slot_id
distribution_trips                       virtual_slot_id
distribution_window_orders               virtual_slot_id
```

Not one row joins the two. `distribution_virtual_slots` has no `template_id`, and the
`applied_from_template_id` in the apply response is echoed from the request URL — it is
**never stored**.

So the question "does Template A already have a Group in this Wave?" — the question
PARTS 5 and 9 are built on — **cannot be asked of the current schema**. Inferring it from
the Group's `code` or `name` string would be a convention, not an identity, and would break
the first time an operator renamed a group. That is not "guaranteed safely" (STOP #5).

**Minimum migration required** (not created — awaiting authorization):

```php
Schema::table('distribution_virtual_slots', function (Blueprint $table): void {
    // Provenance, NOT a live reference: nullable, never joined for configuration, and
    // deliberately not a foreign key — matching every existing Distribution migration.
    $table->char('created_from_template_id', 36)->nullable()->after('code');
    $table->index(
        ['distribution_window_id', 'created_from_template_id'],
        'dist_slot_window_template_idx',
    );
});
```

This satisfies PART 3's "no live FK/reference from Group back to Template" in substance —
it records *which blueprint stamped this instance*, is never read to derive the Group's
configuration, and so cannot make a later Template edit retroactive. Whether that
distinction is acceptable is your call, because the literal wording forbids a reference of
any kind.

A per-wave unique index is **not** proposed, for the reason in STOP #2.

### STOP #2 — a Window cannot identify a Wave

A Group belongs to a **Window**. A Window is `(company, calendar day)`. A Wave is
`(company, **warehouse**, planning date, type)`.

This is not theoretical. Live data:

```
2026-08-19  engine  closed
2026-08-20  engine  closed     <-- three waves,
2026-08-20  engine  closed     <-- same company,
2026-08-20  engine  closed     <-- same warehouse, same day
2026-08-21  engine  closed
2026-08-22  engine  closed
2026-08-23  engine  closed
2026-08-24  engine  collecting
```

Three engine waves on 2026-08-20 shared **one** window row, because the unique key permits
only one per company per day. Any Group created that day is attributable to the day, not to
a wave. `DistributionCollectionService` already resolves a window *per warehouse*
(`$windowFor($warehouseId)`) and they all converge on the same row.

So "today's Group for this Wave" is currently expressible only as "today's Group for this
company-day". **Whether that is the intended granularity is an owner decision**, and it
changes what the migration in STOP #1 should key on.

### STOP #7 — capacity semantics conflict with the approved rule

PART 6 requires: Template capacity 20, 27 eligible orders → **one Group holding 27**, no
split, and *"DO NOT change the existing capacity/headroom contract."*

Those three cannot hold together today. `applyToNewGroup()` copies the Template's
`capacity_orders` (20) onto the new Group, then attaches zones through
`assignZoneToSlot()`, which does:

```php
$incoming = $this->eligibleZoneArrivals($window, $zoneId, $slot);
$this->capacity->assertHasHeadroom($slot, $incoming);
```

At order 21 that **throws**. The existing contract does not split — it *refuses*. So
automatic creation of the 27-order Group is blocked by the very contract PART 6 says not to
change.

Three resolutions, none of which I should pick for you:

| Option | Cost |
|---|---|
| Create the Group with `capacity_orders = NULL` | Loses the planning threshold PART 3 says to preserve |
| Auto-record an overflow approval at creation | The system would be self-approving what TASK-1-B-A2 established as an explicit operator decision |
| Exempt automatic creation from the headroom guard | *Is* changing the capacity/headroom contract, which PART 6 forbids |

### STOP #9 — a migration is required

Yes, per STOP #1. None was created.

---

## 7. An additional ambiguity not in the STOP list

**Templates carry no warehouse; Groups require one.** `distribution_virtual_slots.warehouse_id`
is `NOT NULL`, and `distribution_group_templates` has no warehouse column — the operator
supplies it on apply.

Automatic creation has nobody to ask. It would have to *choose* a warehouse, and a Template
whose zones span two warehouses has no single correct answer. Since a Wave is already
per-warehouse, the natural reading is "one Group per Template **per warehouse** per wave" —
but that is a change to the stated contract, so it is raised rather than assumed.

---

## 8. STOP conditions NOT hit

| # | Condition | Finding |
|---|---|---|
| 3 | Canonical creation cannot be reused | **Not hit** — `applyToNewGroup()` is reusable |
| 4 | Eligibility not determinable | **Not hit** — `eligibleUnassignedOrders()` is canonical |
| 6 | Would need a competing engine | **Not hit** — creation, eligibility, zone ownership and assignment all exist |
| 8 | Would need Driver Loading/Delivery changes | **Not hit** — nothing in this task reaches them |

So the blockers are **identity and capacity**, not missing machinery. Once the decisions in
§6 are made, the implementation is small: a scheduled/lazy caller that asks
`eligibleUnassignedOrders()` which current Templates have work, then calls the existing
`applyToNewGroup()` once per Template — no new engine.

---

## 9. Lazy creation (PART 9) — assessment

Every step except one is available today: find the current wave, read the current template
zones, confirm the order's zone, assign via the canonical path. The missing step is step 5,
*"if no Group exists for Template + Wave"* — which is exactly the identity question STOP #1
blocks. Lazy creation is therefore blocked by the same decision, not by a separate one.

---

## 10. Previous-Wave isolation (PART 10) — assessment

Partially satisfied already: a Group carries `distribution_window_id`, so a Group created
today cannot be read as belonging to yesterday's window. Yesterday's Groups would be
untouched.

The gap is *within* a day, per STOP #2: several waves share one window, so "not reusing the
previous Wave's Group" is only guaranteed across days, not across waves on the same day.

**No Group was deleted, archived, closed or re-statused.** Group closing was not
implemented — that is TASK-DISTRIBUTION-DAILY-GROUP-WAVE-CLOSE-002, which was not started.

---

## 11. Tests

**None written.** PART 15's twelve tests all assert behaviour of an implementation that is
blocked. Writing them now would mean either testing nothing or encoding a guess at the
identity and capacity decisions — and they would have to be rewritten once you decide.

Tests 9, 10 and 12 are already covered by existing green suites:
`DistributionTemplateZoneExclusivityTest` (18/18) and
`DistributionWorkspaceFinalizationTest` (41/41), both verified earlier today.

---

## 12. Regression results

No code was changed, so no regression was introduced. For reference, the suites PART 16
names were run earlier today against the current tree:

| Suite | Result |
|---|---|
| `DistributionTemplateZoneExclusivityTest` | 18 / 18 |
| `DistributionWorkspaceFinalizationTest` | 41 / 41 |
| `DistributionBatchMoveTest` | 22 / 22 |
| `DistributionGroupTripTest` | 12 / 12 |
| `GroupTripLoadingIntegrationTest` | 10 / 10 |
| `GroupTripReconciliationVisibilityTest` | 32 / 32 |

No certified test was modified.

---

## 13. Browser verification

**BROWSER MUTATION VERIFICATION — BLOCKED BY DATA SAFETY.**

The six checks in PART 17 all require a Group to be created, which is the mutation this task
did not perform. Read-only observation adds nothing beyond the schema facts above, and
fabricating Orders, Waves or Groups to demonstrate creation is explicitly forbidden. No live
data was created or altered.

---

## 14. Data safety snapshot (PART 14)

Read-only. Identical before and after, because nothing was written.

| Table | Rows |
|---|---|
| orders | 19 |
| preparation_waves | 8 (1 `collecting`) |
| distribution_windows | 4 |
| groups (`distribution_virtual_slots`) | 3, capacities `20, 20, 20` |
| group zone rows (`distribution_slot_zones`) | 3 |
| templates | 5 |
| template zone rows | 8 |
| window_orders | 13 |

---

## 15. Files changed

**None.** Only this report was created.

---

## 16. Remaining owner decisions

1. **Group → Template provenance.** Authorize the nullable
   `created_from_template_id` column in §6, or reject it and state how "the Group for this
   Template" should be identified instead.
2. **Wave granularity.** Should a Group be one per Template per **calendar day** (what the
   schema supports today), or genuinely per **Wave**? The latter needs the window/wave
   relationship addressed, since three waves shared one window on 2026-08-20.
3. **Warehouse selection.** How should automatic creation pick the Group's warehouse, given
   Templates carry none? "Per Template per warehouse per wave" is the natural reading but
   changes the stated contract.
4. **Capacity.** Which of the three options in STOP #7 do you want? All three trade against
   something PART 3 or PART 6 asks to preserve.

Once 1–4 are settled the implementation reuses existing services throughout and needs no
new engine.

---

## 17. Final status

**STOPPED — OWNER DECISION REQUIRED**

Discovery complete. No second Group creation engine was created, no migration was created,
no Group was created or mutated, no Driver or Vehicle was assigned, no Trip was created or
split, and Group closing was not implemented. Zone exclusivity, Map geometry, Zone Orders
panels, Driver recommendations, Driver Loading, Driver Delivery and Trip architecture were
all untouched (PART 19).

TASK-DISTRIBUTION-DAILY-GROUP-WAVE-CLOSE-002 was not started. Wave 3 was not started.
