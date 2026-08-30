# TASK-DISTRIBUTION-TEMPLATES-ZONE-EXCLUSIVITY-AND-DRIVER-RECOMMENDATIONS-001

**Status: IMPLEMENTED / VERIFIED** (backend + frontend + focused tests)
**Browser: NOT VERIFIED — AUTHENTICATION CONSTRAINT**
Date: 2026-08-24 · Branch: `develop` · Not committed, not deployed

**No migration was required.** The `ONE ZONE → ONE TEMPLATE` invariant is enforced with the
existing relationship, so the PART 18 STOP condition was not reached.

---

## 1. Canonical model as implemented

```
Template (reusable blueprint)
    ├── Zones            — exclusive per company
    ├── Maximum Orders   — order count only
    └── Recommended Drivers — informational, see §7
            ↓ Use Template
      New Group  →  independent SNAPSHOT
            ↓
   Operator assigns ONE Driver + ONE Vehicle
            ↓
         Loading
```

No Trip appears anywhere in this UX. The internal `distribution_trips` contract was left
exactly as it is and is not surfaced as an operator step.

---

## 2. Zone exclusivity — the invariant

Enforced in `GroupTemplateService::claimZones()`, called from **inside** the existing
transaction in both `create()` and `update()`.

**Why the lock is on the Zone row.** The thing two racing requests contend for is the
*Zone*, not either template — which may well be two different rows, so locking a template
cannot serialise them. `claimZones()` therefore takes
`DistributionZone::whereIn('id', $zoneIds)->lockForUpdate()->get()` and holds it to commit.
That satisfies PART 19 with no new engine and no new predicate.

**Archived templates do not own Zones.** `archive()` soft-deletes and deliberately keeps
its pivot rows so a restore is intact, so the pivot legitimately holds rows for templates
nobody can open. Counting those would strand a Zone forever. The live database already has
this exact shape — zone 7 sits in the archived `Morning Cairo v2` *and* in the active `Zq`.

---

## 3. Why the database cannot enforce it (PART 18 — reported, not worked around)

`dist_group_tpl_zone_unique` is on **(template_id, zone_id)**. It stops the same Zone
appearing twice *inside one* template and permits it in two different templates.

A DB-level key would have to be scoped **per company** (PART 17 explicitly allows another
company to use the same Zone), and `distribution_group_template_zones` carries **no
`company_id`**. Adding one, or a unique index on the zone alone, is a migration.

So: the invariant is enforced in the service today, and a DB-level guarantee is left to
you as an owner decision. **Exact migration, if you want belt-and-braces:** add
`company_id char(36)` to the pivot, backfill from the parent template, then a unique index
on `(company_id, distribution_zone_id)` — with the caveat that archived templates would
have to be excluded, which a plain unique index cannot express. That is precisely why the
service-level guard remains necessary either way, and why I did not treat the index as a
substitute.

**No backfill was needed.** I checked live data before enforcing: **zero violations** among
active templates.

---

## 4. Existing Zone visibility (PARTS 6, 15, 16)

`GET /group-templates` now returns `zone_ownership` alongside `data`, derived by
`GroupTemplateService::zoneOwnership()` — **the same method the write-path guard uses**. One
source for the rule and the screen, so the picker can never label a Zone free that the save
then refuses. No new endpoint.

The Zone selector splits into **Available** and **Used in another template**, the latter
showing *"Used in: Morning Cairo"* per Zone. Taken Zones are shown, not hidden: hiding them
would leave the operator unable to explain why a Zone visible on the Zones tab is absent
here.

---

## 5. Move, not duplication (PARTS 7, 8, 12)

Selecting a Zone owned elsewhere surfaces a confirmation naming the Zone, the template
losing it and the template gaining it, plus the warning that it changes template
configuration only. **Save is disabled until the operator ticks it.** Any change to the
selection resets that confirmation, so ticking a second owned Zone cannot ride in on the
first one's approval.

`move_zones: true` is sent **only** after confirmation. The server then detaches the Zone
from its previous owner before attaching it here — so it moves, never duplicates. Without
the flag the save is refused and the message names the owning template.

Before → after is exactly PART 8: `Morning Cairo [Maadi, Helwan, Obour]` /
`Evening Cairo [Giza, New Cairo]` becomes `Morning Cairo [Helwan, Obour]` /
`Evening Cairo [Maadi, Giza, New Cairo]`, with Maadi in exactly one.

---

## 6. Snapshot contract (PART 9) — already held, now pinned

**No code was needed.** `applyToNewGroup()` reads `$template->zoneIds()` at apply time and
gives the Group its own `distribution_slot_zones` rows through the same
`assignZoneToSlot()` the Zones tab uses. There is no FK from Group back to template, so a
later template edit cannot be retroactive.

`test_a_group_keeps_its_zones_when_the_template_changes_later` proves it: a Group applied
with `[Maadi, Helwan]` still holds both after the template is shrunk to `[Helwan]`.

---

## 7. Recommended Drivers (PARTS 3, 20)

The section renders **"No recommendation available"** / **"لا توجد توصية متاحة"**, with the
note that drivers are chosen when the Group is assigned and are never stored on a template.

That is the honest answer, not a placeholder for missing work: there is **no
driver-performance or delivery-history service** anywhere in the repository, and
**`logistics_drivers` holds 0 rows**. Nothing exists to rank. Inventing an order would be
worse than showing none — a list labelled "best match" that is really alphabetical teaches
operators to trust a number that means nothing. No stars, percentages or rankings were
fabricated, and no scoring engine was created.

`test_a_template_stores_no_driver_or_vehicle` asserts against the live schema that no
driver or vehicle column exists, so adding one later trips deliberately.

*(Follow-up: the operator-selectable version of this list is reported separately in
`TASK-DISTRIBUTION-TEMPLATE-DRIVER-RECOMMENDATIONS-001-REPORT.md`, which stops on a
migration authorization.)*

---

## 8. Table UX (PART 14)

The templates table keeps Template / Zones / Max Orders / Actions and gains a **Recommended
drivers** column. The Zones cell now names the Zones under the count — a count alone cannot
be checked against anything.

---

## 9. Files changed

**Backend (2 files, no migration):**

| File | Change |
|---|---|
| `GroupTemplateService.php` | new `zoneOwnership()` + `claimZones()`; `create()`/`update()` take `$moveZones` and claim inside the transaction |
| `GroupTemplateController.php` | `move_zones` validation on store/update; `zone_ownership` in the index payload |

`apply()` was deliberately left untouched.

**Frontend (5 files):** `types/index.ts` (`ZoneTemplateOwnership`, `GroupTemplatesResult`,
`move_zones`) · `distribution-workspace-service.ts` · `distribution-templates-tab.tsx`
(split picker, Move confirmation, `RecommendedDrivers`, drivers column, zone names) ·
`en/logistics.json` · `ar/logistics.json` (14 keys each).

`ApplyForm`'s Zone picker was left alone — applying a template creates a **Group**, which
template ownership does not constrain — so the two new picker props are optional.

---

## 10. Tests — 18 / 18 green, 97 assertions

`--filter DistributionTemplateZoneExclusivityTest`

| PART 22 requirement | Covered by |
|---|---|
| 1, 2, 3 Template returns / create / edit persists Zones | `test_a_free_zone_can_be_added_to_a_template`, `test_a_template_can_keep_its_own_zones_on_edit` + the 10 pre-existing template tests |
| 4 Apply creates a Group with current Zones | pre-existing `test_applying_a_template_creates_a_group_with_its_configuration` |
| 5 Existing Group unchanged after edit | `test_a_group_keeps_its_zones_when_the_template_changes_later` |
| 6 Same Zone cannot belong to two Templates | `test_a_zone_cannot_belong_to_two_templates`, `test_an_edit_cannot_steal_a_zone_from_another_template` |
| 7 Available Zone selectable | `test_a_free_zone_can_be_added_to_a_template` |
| 8 Assigned Zone shows current Template | `test_the_template_list_reports_zone_ownership`, `test_the_refusal_names_the_template_that_owns_the_zone` |
| 9, 10 Move removes from old / adds to new | `test_a_confirmed_move_transfers_the_zone`, `test_a_confirmed_move_works_on_create` |
| 11 Move requires explicit confirmation | `test_an_unconfirmed_move_changes_nothing` |
| 12 Failed Move leaves original unchanged | `test_a_failed_move_rolls_back_the_whole_edit` |
| 13 Concurrency cannot duplicate ownership | `test_a_move_never_duplicates_the_zone` + the Zone row lock (§2) |
| 14 Recommendations are not assignments | `test_a_template_stores_no_driver_or_vehicle` |
| 15 Group Driver remains selectable | unchanged `assign-vehicle` path; apply writes no driver |
| 16 Zero-Zone template still valid | `test_a_template_with_no_zones_is_still_valid` |
| 17 Company scope enforced | `test_another_company_may_use_the_same_zone`, `test_the_ownership_map_is_company_scoped` |
| 18 Existing apply behaviour intact | `DistributionWorkspaceFinalizationTest` — **41 / 41 green**, 187 assertions |

Plus `test_an_archived_template_releases_its_zones` and `test_a_move_mutates_no_runtime_data`
beyond the required list. **No certified test was modified.**

### Regression (PART 22 item 18)

`--filter DistributionWorkspaceFinalizationTest` → `Tests: 41, Assertions: 187` **OK**.

That suite carries the 10 pre-existing template tests — create, edit, archive, apply,
apply-with-overrides, apply-copies-no-runtime-state, company scoping, foreign-window
refusal, route permissions and per-company name uniqueness. All green, so threading
`$moveZones` through `create()`/`update()` and moving the exclusivity decision inside the
transaction changed no existing behaviour.

One correction during the run: my own test read the apply response as `id` when the endpoint
publishes `slot_id`. The test was wrong, not the endpoint; 17/18 → 18/18.

---

## 11. Static verification

| Check | Result |
|---|---|
| `php -l` (both backend files, test) | clean |
| Pint | **PASS** on both changed files (module-wide failures are pre-existing in `Trip*` / `GroupLoadingContextService`, untouched here) |
| PHPStan | **No errors** |
| ESLint (`distribution-workspace/`) | clean |
| `tsc --noEmit -p tsconfig.app.json` | **23 errors — the pre-existing baseline**, none in my files |
| i18n parity | **2267 / 2267**, 14 new keys, all translated |

---

## 12. Browser verification (PART 23)

**BROWSER NOT VERIFIED — AUTHENTICATION CONSTRAINT.**

The 21-step walkthrough needs an authenticated session, which means entering credentials —
something I do not do. No business data was fabricated to produce a screenshot.

Two of the 21 steps could not be demonstrated on live data regardless: creating a Group
from a template and then editing the template would mutate live Groups, which PART 24
forbids. Both are covered by tests instead
(`test_a_group_keeps_its_zones_when_the_template_changes_later`).

**Browser Verified is not claimed.**

---

## 13. Data safety (PART 24)

Nothing was mutated. All test writes ran in `ecos_dev_test`; every `ecos_dev` query was a
`SELECT`.

| Fact | Value |
|---|---|
| `orders` | 19 — unchanged |
| `distribution_zones` | 10 — unchanged |
| Templates | 4 active (5 incl. archived) — unchanged |
| `distribution_group_template_zones` | 8 — unchanged |
| Groups | 3, capacities `20, 20, 20` — unchanged |
| `distribution_slot_zones` | 3 — unchanged |
| Trips | 2, capacities `60, 60` — unchanged |
| Drivers / Vehicles | 0 / 0 |
| `loading_sessions` | 0 |

No automatic data migration, reassignment or cleanup. The template count changes only
through explicit operator action.

---

## 14. Untouched, as required (PART 21)

Map · Zones architecture · Group/Trip contracts · Group capacity · Overflow approval ·
Finalize · Loading · Preparation · Order lifecycle · Driver Loading · Driver Wave tasks.
No new Zones engine and no new Group engine. No conflict with any existing contract was
found.

---

## 15. Remaining gaps

1. **No DB-level uniqueness** for zone exclusivity (§3). Service-enforced under a row lock;
   a per-company index needs a migration and cannot express the archived-template
   exclusion, so the service guard stays regardless.
2. **Recommended Drivers is display-only** — the selectable version is blocked on a
   migration, reported separately.
3. **Browser walkthrough outstanding** (§12).
4. **Zones tab** still shows no owning-template column. `zone_ownership` is now available
   for it; PART 21 limited changes there to what was strictly necessary, and the Template
   picker carries the relationship, so it was left out rather than expanded into.

---

## Final Status

**IMPLEMENTED / VERIFIED** — 18/18 new focused tests green plus 41/41 on the existing
template suite, no migration required, live data untouched, both languages at parity. Browser verification outstanding for the reason in §12.

Not certified. No commit, no deploy. No other task started.
