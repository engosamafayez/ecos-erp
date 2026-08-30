# TASK-OPERATIONS-DISTRIBUTOR-ORDERS-PART-5B-GROUP-WAREHOUSE-OWNERSHIP-001 — FINAL REPORT
## Distribution Group Warehouse Ownership

**Status:** **IMPLEMENTED + TESTED (121/121) + BROWSER VERIFIED (single-warehouse)**
**Date:** 2026-08-21 · **Branch:** `develop` · **Not committed**
**Verdict:** see §18 — **not** claimed CERTIFIED.

---

# 0. HEADLINE

The defect Part 5A proved is closed **in the data**, not in the UI.

```
before   distribution_virtual_slots  (company, window)            no owner
         dist_slot_zones_window_zone_unique  (window, zone)       warehouse-blind

after    distribution_virtual_slots.warehouse_id   CHAR(36) NOT NULL
         distribution_slot_zones.warehouse_id      CHAR(36) NOT NULL
         dist_slot_zones_window_wh_zone_unique  (window, warehouse, zone)
```

| Gate | Result |
|---|---|
| Part 5B suite | **17/17 PASS** |
| Full Distribution regression | **121/121, 712 assertions — PASS** |
| Frontend nav tests | **21/21 PASS** |
| TypeScript | **23 = pre-existing baseline**, none in touched files |
| Vite build | **clean** |
| Browser (single-warehouse) | **PASS** |
| Browser (multi-warehouse) | **NOT BROWSER VERIFIED** — §13 |

---

# 1. AUDIT (Part 1 — before code)

| # | Inspected | Finding |
|---|---|---|
| 1 | `distribution_virtual_slots` | `id, company_id, distribution_window_id, code, name, capacity_*` — **no warehouse** |
| 2 | `distribution_slot_zones` | `id, distribution_window_id, virtual_slot_id, distribution_zone_id` — **no warehouse** |
| 3 | `distribution_windows` | company + date. **Not touched** |
| 4 | `VirtualCapacitySlot`, `DistributionSlotZone` | plain models, no warehouse |
| 5 | Creation — `storeSlot` | `code` + optional name/capacities; **no owner** |
| 6 | Zone assignment — `assignZoneToSlot` | re-syncs the zone's orders into the slot, warehouse-blind |
| 7/8 | Read models — `slotSummaries` / `slotRollup` | Part 5A scoped the **orders reported**; the **group list** was unscoped |
| 9 | Uniqueness | `dist_slot_zones_window_zone_unique` **(window, zone)** — the defect's mechanism |
| 10 | Tenant scoping | `companyId()` fails closed; slots carry `company_id` |
| 11 | Warehouse context | `orders.assigned_warehouse_id`; frontend `OrganizationContext.activeWarehouseId` |
| 12 | Window relationship | slot → window (company-level), zone-link denormalises `distribution_window_id` **for uniqueness** |
| — | Foreign keys | **NONE** on either table — plain uuid + index is this module's convention |

**Minimum safe change:** a warehouse column on the Group **and** on the zone-link, plus a re-keyed uniqueness index. Nothing else.

---

# 2. CURRENT GROUP ARCHITECTURE (before)

A Group hung off a **company-level** Window with no warehouse. Because the zone-link was unique on `(window, zone)`, warehouse B's planner assigning a Zone **silently moved it out of** warehouse A's Group — no error, no trace, and A's totals simply dropped. Part 5A's read filtering hid the symptom; it could not fix ownership, because ownership did not exist.

---

# 3. WAREHOUSE OWNERSHIP DECISION

**Source of truth: `orders.assigned_warehouse_id`** — the same column `WaveMembershipService` matches on, so Preparation and Distribution agree by construction.

**Never inferred** from zone name, governorate, city, shipping company, latest wave, latest group, or the operator's current selection. On creation the warehouse is an **explicit required parameter**.

---

# 4. MIGRATION

`2026_08_21_100000_add_warehouse_ownership_to_distribution_groups.php` — additive, reversible, MySQL-compatible, no FK (matching the module), no `Blueprint::check()`, no partial index.

Sequence: add nullable → derive → **assert every group is owned** → `MODIFY … NOT NULL` → re-key the uniqueness index.

## Why two columns

`distribution_slot_zones.warehouse_id` is **denormalised, not duplicated**. MySQL cannot express a unique index that reaches through `virtual_slot_id` into the slot's warehouse — the identical reason the original migration denormalised `distribution_window_id` onto this table, stating it explicitly: *"purely so that uniqueness can be expressed at the database level. Without it the constraint would have to be enforced in application code, which is exactly where this class of rule goes wrong under concurrency."*

## The uniqueness rule changes shape, not intent

| | Constraint | Meaning |
|---|---|---|
| before | `(window, zone)` | one Zone → one Group per **window** |
| after | `(window, warehouse, zone)` | one Zone → one Group per **window and warehouse** |

The old rule made a legitimate operation impossible: two warehouses both delivering into Maadi could not each plan Maadi. The new one still forbids what must stay forbidden — one Zone in two Groups **for the same warehouse**.

---

# 5. EXISTING GROUP BACKFILL

**The rule, stated in the migration before it runs:** a Group's warehouse is the **single distinct `orders.assigned_warehouse_id`** among the assignments pointing at it. One distinct value is an answer; **zero or several is not, and nothing is written**.

Live result:

```
DG-001  →  019f4e1c-2e1b-7269-bfbb-8a414cb07cab  (Main Warehouse)
zone 2  →  019f4e1c…       zone 7  →  019f4e1c…
```

DG-001 held orders from exactly one warehouse, so it resolved. **It was not deleted, not recreated, and the operator's selected warehouse was not used as a shortcut** — the value came from the Group's own orders.

`assertEveryGroupIsOwned()` throws by name if any Group remains unowned, rather than forcing NOT NULL over a guess. **PASS** — no group was unresolvable.

---

# 6. GROUP CREATION

`warehouse_id` is now **required** on `POST /windows/{w}/slots`, and the warehouse must belong to the acting company — otherwise **404**, not 403, so a warehouse outside the tenant is not confirmed to exist.

Frontend sends the **already-approved** `OrganizationContext.activeWarehouseId`. **No second warehouse-selection mechanism, no new selector.** With no warehouse selected the button is disabled and the panel says why — the request is never attempted.

---

# 7. ZONE ASSIGNMENT PROTECTION

Enforced **server-side, before any write**.

## The rule, and why the obvious version was wrong

My first implementation rejected any zone containing another warehouse's orders. **The tests caught that as too strict** — it forbade the exact case ownership exists to enable.

> **A Zone is geography, and geography is shared.** Two warehouses both delivering into Maadi is a normal state, not an error; each Group simply takes its own orders.

What is refused: **claiming a Zone that holds work for another warehouse and none for yours.** That attachment gains the Group nothing and takes a Zone out of another warehouse's reach, so it can only be a mistake.

Also warehouse-aware now:

| Path | Change |
|---|---|
| `assignZoneToSlot` | only the **owner's** orders follow the Zone in; keyed on `(window, warehouse, zone)` |
| `detachZone` | detaching from one warehouse's Group no longer empties the Zone out of another's |
| `slotMapForWindow` | takes a warehouse — an Order inherits **its own** warehouse's Group |
| `collectForCompany` | one memoised slot map per warehouse |
| `reconcileUnzoned` | re-zoning joins the Order's own warehouse's Group |
| `changeOrderZone`, `assignLateOrder` | resolve the destination Group by the Order's warehouse |

**Zone with no eligible orders:** allowed. Part 6 says not to infer a warehouse from nothing and to follow existing domain behaviour — the Group already names its own warehouse, so nothing is inferred.

---

# 8. READ MODEL CHANGES

The **group list itself** is filtered by ownership — a foreign Group does not appear at all, rather than appearing with zero orders. `warehouse_id` is exposed on every group payload. Totals (orders, products, value, paid/unpaid) inherit the Part 5A scoping and now sit under an owned Group. `assignZoneToSlot` echoes only the slot's **own** warehouse's groups.

---

# 9. INDEX / CONSTRAINT CHANGES

| Table | Before | After |
|---|---|---|
| `distribution_slot_zones` | `dist_slot_zones_window_zone_unique (window, zone)` | **dropped** |
| `distribution_slot_zones` | — | `dist_slot_zones_window_wh_zone_unique (window, warehouse, zone)` |
| `distribution_virtual_slots` | `dist_slots_company_window_idx` | unchanged, **plus** `dist_slots_company_window_wh_idx` |
| `dist_slots_window_code_unique` | `(window, code)` | **unchanged** — group codes stay unique per window |

A test asserts both the new index's columns and the **absence** of the old one.

---

# 10. TENANT ISOLATION

Unchanged and still fail-closed. `companyId()` aborts **403** for an actor with no company; a foreign window is **404**. New: a foreign **warehouse** on creation is **404**. Groups carry `company_id` as before, and another company sees none of them. **No permission was created or modified.**

---

# 11. TESTS

`DistributionGroupWarehouseOwnershipTest` — **17 tests**.

| Required | Test |
|---|---|
| 1 — ownership exists | `test_the_group_table_carries_warehouse_ownership` (both columns, NOT NULL) |
| 2 — deterministic backfill | verified live (§5); the rule is asserted by `test_a_created_group_records_its_warehouse` |
| 3 — creation requires warehouse | `test_a_group_cannot_be_created_without_a_warehouse` (422, no row) |
| 3 — foreign warehouse | `test_a_group_cannot_be_created_for_another_companys_warehouse` (404, no row) |
| 4 — read scoped | `test_a_group_is_invisible_to_another_warehouse` |
| 5 — totals scoped | `test_group_totals_count_only_the_owning_warehouses_orders` |
| 6 — orders cannot cross | same, plus `test_orders_with_no_warehouse_never_join_a_group` |
| 7 — no cross-warehouse membership | `test_a_zone_holding_another_warehouses_orders_is_refused` — **no partial write** asserted |
| 7 — the legitimate case | `test_two_warehouses_can_each_plan_the_same_zone_in_their_own_group` |
| 8 — same-warehouse uniqueness | `test_a_zone_cannot_belong_to_two_groups_of_the_same_warehouse` |
| 9 — index shape | `test_the_uniqueness_index_now_includes_the_warehouse` |
| 9 — company isolation | `test_another_company_never_sees_this_companys_groups`, `test_group_creation_is_refused_without_a_company_scope` |
| 12 — blast radius | `test_group_ownership_changes_mutate_no_other_domain` |

## Regression — three passes, and what each one found

| Pass | Result | Cause |
|---|---|---|
| 1 | 120 tests · 14 errors · 15 failures | earlier suites create Groups without `warehouse_id` — the deliberate contract change |
| 2 | 120 · 14 · 3 | **my guard was too strict** (§7), and 3 fixtures create slots directly through the model |
| 3 | **121 · 0 · 0** | **PASS** |

**Between passes 2 and 3 the fault was mine, not the tests':** I patched those three fixtures with *freshly created* warehouses while their orders had none, or a different one — so nothing joined the slots and nine aggregation assertions read 0. Corrected by making each fixture's orders and Groups share one warehouse, which is also what real data looks like.

**One test's expectation was genuinely obsolete.** `DistributionWarehouseScopedReadsTest::test_distribution_groups_report_only_the_scoped_warehouses_orders` was Part 5A's *record of the gap* — it asserted both warehouses see the shared Group. Part 5B closes that, so it now asserts the owned reality: **B cannot see the Group at all**, and only A's order is inside. The comment records what it used to prove.

**No assertion was weakened, and no production code was changed to make a test pass.**

## Certified boundary contract — checked, not assumed

`DistributionWarehouseBoundaryTest` asserts *"Distribution must not own a Warehouse column."* I verified **which table** it guards before touching anything: `distribution_window_orders` — which was **not** modified. That contract protects against Distribution **duplicating the Order's** warehouse; Part 5B adds ownership to the **Group**, Distribution's own planning object. Different thing, no conflict, contract intact.

---

# 12. REGRESSION RESULTS

```
Tests: 121, Assertions: 712   —   121 / 121 (100%)
```

Part 5B (17) · Part 5A (20) · Part 5 eligibility (10) · Part 4 groups (23) · Parts 1–3 (24) · `DistributionWindowApiTest` · `DistributionCoreTest` · `DistributionWarehouseBoundaryTest`.

| Gate | Baseline | Now | Delta |
|---|---|---|---|
| Backend Distribution | 106/106 | **121/121** | **+15, no regression** |
| Frontend nav tests | 21/21 | **21/21** | none |
| TypeScript | 23 pre-existing | **23** | none in touched files |
| Vite build | clean | **clean** | none |

**Pre-existing, unrelated, reported separately:** the 32 PHPUnit deprecations appear in every suite on this branch and predate this work. ESLint / PHPStan / Pint were **not run** — not configured as gates in this session's tooling.

---

# 13. BROWSER ACCEPTANCE

Live, authenticated, real data. **No data fabricated.**

| # | Item | Verdict |
|---|---|---|
| 1 | Existing Group displays its warehouse | **PASS** — `Warehouse: Main Warehouse · aligned to PREP-202608-000003` |
| 2 | Existing orders remain in the same Group | **PASS** — 3 orders, unchanged |
| 3 | Group totals remain correct | **PASS** — 3 orders · 3 products · EGP 425.11, identical to pre-migration |
| 4 | Reload preserves ownership | **PASS** |
| 5 | Active warehouse context respected | **PASS** — cleared → creation blocked with *"Select a warehouse first — a distribution group belongs to exactly one."*; restored → normal |
| — | Cross-warehouse case on real data | **NOT BROWSER VERIFIED** |

> **MULTI-WAREHOUSE BROWSER ACCEPTANCE: NOT BROWSER VERIFIED.** One warehouse exists in live data and Part 12 forbids creating a second. **The automated tests carry the cross-warehouse proof** — including the refusal case with no partial write, and two warehouses each planning the same zone.

The warehouse-context clear/restore was a reversible UI preference (`ecos:activeWarehouseId`), captured before and restored after. No business data was touched.

---

# 14. SIDE EFFECTS

| Area | Result |
|---|---|
| `orders` | **unchanged** — 4 awaiting_payment · 1 awaiting_stock · 1 confirmed · 3 in_progress |
| `order_lines` | **unchanged** — 10 |
| `preparation_waves` | **unchanged** — 3 rows, statuses identical |
| `preparation_wave_orders` | **unchanged** — 7 rows, 1 postponed |
| `distribution_zones` | **unchanged** — 10 |
| `distribution_windows` | **unchanged** |
| `vehicle_plan*` (4 tables) | **0 rows** |
| `loading_*`, `vehicle_assignments`, `allocation_records` | **0 rows** |

**Only the approved persistence changed:** `warehouse_id` on the two Group tables, its indexes, and the re-keyed uniqueness constraint. **No order quantity changed.**

---

# 15. KNOWN LIMITATIONS

| # | Limitation |
|---|---|
| L-1 | **A Group with no orders cannot be backfilled** — it has no derivable owner. None existed; the migration throws by name rather than guessing if one ever does |
| L-2 | Within one warehouse, re-assigning a Zone still **moves** it between Groups rather than being refused. Unchanged behaviour, and the UI does not offer it |
| L-3 | Carried from Part 5A: the header switcher displays a warehouse it never persisted, so the workspace correctly reports "no warehouse selected" until the operator clicks once |
| L-4 | Multi-warehouse behaviour is **test-proven, not browser-proven** (§13) |
| L-5 | Group **capacities** remain NULL — vehicle capacity is a later phase |
| L-6 | The workspace is English-only (carried from Part 4) |

---

# 16. DEFERRED GROUP MANAGEMENT WORK

**Not implemented, as instructed:** Group Management UX · Add / Remove / Move Zone UI · Group merge · Group split · order-level manual movement · Vehicle Planning · Virtual Vehicle · Vehicle assignment · Driver assignment · Approval · Finalize · Loading · Distribution Window carry-over · the "No Warehouse" bucket.

`detachZone` still has **no route** — exposing it remains a new API contract and a later Part.

---

# 17. ROLLBACK

`php artisan migrate:rollback` reverses it: the new unique index and both `warehouse_id` columns are dropped, and the original `(window, zone)` constraint is restored. **No Group, Zone link, Order or quantity is deleted** — only ownership metadata this migration itself derived.

Application code would need to be reverted alongside it: creation would again accept a Group without an owner, and the cross-warehouse guard would be gone.

---

# 18. FINAL VERDICT

| Item | Verdict |
|---|---|
| Ownership enforced in the database | **PASS** |
| Existing Group backfilled deterministically | **PASS** |
| Creation requires an explicit warehouse | **PASS** |
| Cross-warehouse attachment refused server-side, no partial write | **PASS** |
| Two warehouses can each plan the same Zone | **PASS** |
| Read models scoped by ownership | **PASS** |
| Uniqueness re-keyed and old constraint removed | **PASS** |
| Tenant isolation fail-closed | **PASS** |
| Backend regression 121/121 | **PASS** |
| Frontend gates | **PASS** |
| Single-warehouse browser acceptance | **PASS** |
| **Multi-warehouse browser acceptance** | **NOT BROWSER VERIFIED** |
| Group Management UX, Vehicle Planning, Loading, Approval, Finalize | **OUT OF SCOPE** |
| Blockers | **NONE** |

> **NOT CERTIFIED.** Every gate that could be run is green, but multi-warehouse browser acceptance is unavailable in live data and Part 12 forbids fabricating it. Certification should follow a second real warehouse.

**Not committed. Part 5C not started.**
