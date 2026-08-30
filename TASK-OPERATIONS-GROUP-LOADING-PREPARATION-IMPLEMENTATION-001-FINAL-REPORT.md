# TASK-OPERATIONS-GROUP-LOADING-PREPARATION-IMPLEMENTATION-001 — FINAL REPORT

**Date:** 2026-08-21
**Scope:** Group + Product Prepared — the warehouse-facing preparation layer for a Distribution Group.
**Commit status:** **NOT COMMITTED** (per instruction).

---

## 1. Executive Summary

The Distribution Group can now record what the warehouse has physically separated for it, and show what remains — without touching Preparation, Inventory, Orders, Vehicles or Actual Loading.

```
Required  — LIVE, canonical (productAggregation), never stored
Prepared  — declared by the operator, stored at (Group, Product), ABSOLUTE SET
Remaining — max(0, Required − Prepared), DERIVED by the server, never stored
Ceiling   — Prepared <= Required, recomputed INSIDE the transaction, under the Group's row lock
```

**One new table, one new route, one new service, one new model, no new permission, no new event, no new concurrency mechanism, no new synchronisation mechanism.**

### Verified live on real DG-001

| | Required | Prepared | Remaining |
|---|---|---|---|
| Honey Jar 250g (`FG-HONEY-250`) | 2 | **1** | **1** |
| تجربة التعليقات (`ECOS-FG-000001`) | 1 | **1** | **0** |

Set through the real UI editor, persisted across a full reload, and proven idempotent, ceiling-bounded and tenant-scoped through the live API.

### One gap found during verification, and closed

Removing a Zone made Required fall to 0 — and the retained Prepared rows **vanished from the response**, because the read model iterated the Required list. The records survived in storage but no operator could see them, which defeats the purpose of retaining them: the stock is physically on that Group's pallet. The read model now appends **prepared-only rows** with `Required 0` and a non-zero `over_prepared_qty`, so the situation is surfaced instead of being hidden behind a Remaining that is floored at zero.

Live proof, before and after the fix, on the same zone-removal:

```
BEFORE FIX   afterZoneRemoved: []                                  ← records invisible
AFTER  FIX   afterZoneRemoved: [{req 0, prep 1, rem 0, over 1},    ← visible, flagged
                                {req 0, prep 1, rem 0, over 1}]
```

### Boundaries held

No Preparation change. No Inventory mutation. No Order status write. No vehicle, driver, trip, dispatch or Actual Loading change. No Virtual Vehicle Planning revival. `capacity_orders` remains the only Group capacity constraint. **No STOP condition triggered.**

---

## 2. Approved Architecture

```
Preparation → ready_for_dispatch → Distribution Group → Loading Preparation
    → Vehicle + Driver → Review / Finalize → Actual Loading → Dispatch
      └──────────────── NOT IMPLEMENTED, NOT STARTED ────────────────┘
```

Built on the two completed slices: **LP-1** (the Required projection) and **LP-1.0** (the loading-eligibility repair, which is what makes `ready_for_dispatch` orders visible to their Group at all). Neither was rebuilt; both are consumed unchanged.

---

## 3. Group + Product Data Model

**`distribution_group_product_preparation`** — one row per `(Group, Product)`.

| Column | Type | Purpose |
|---|---|---|
| `id` | `char(36)` PK | uuid, `HasUuids` |
| `company_id` | `char(36)` | tenant |
| `distribution_window_id` | `char(36)` | retention / cleanup scope |
| `virtual_slot_id` | `char(36)` | **the Group — the owner** |
| `product_id` | `char(36)` | |
| `prepared_qty` | `decimal(12,4)` default 0 | **the one fact this table owns** |
| `last_recorded_by` | `bigint unsigned` null | durable actor stamp |
| `last_recorded_at` | `timestamp` null | |
| `created_at` / `updated_at` | `timestamp` | |

| Key | |
|---|---|
| `dist_group_prep_slot_product_unique` | `(virtual_slot_id, product_id)` — correctness, concurrency **and** idempotency guard in one index |
| `dist_group_prep_company_window_idx` | `(company_id, distribution_window_id)` |
| `dist_group_prep_product_idx` | `product_id` |

### Conventions followed — and where I deviated from my own earlier proposal

Before writing a line I ran a convention-discovery pass across schema, models, write paths, concurrency, frontend, i18n and tests, then **adversarially verified every claim against a second reading**. Every area came back with over-generalisations, and three of the proposed skeletons would not have compiled. Three findings changed the design away from what the LP-2 decision report had specified:

| Decision report said | What the module actually does | What I did |
|---|---|---|
| FKs: `virtual_slot_id → …restrictOnDelete`, `company_id → companies` | `2026_08_21_100000` states verbatim: *"NO FOREIGN KEYS, MATCHING THIS MODULE… adding one here would be a new convention rather than a followed one."* The whole 2026_08_11 wave declares zero FKs | **No FKs.** Trade-off stated in §25 |
| `CHECK (prepared_qty >= 0)` | Distribution has **zero** `ADD CONSTRAINT` statements; the trips migration records that CHECK ALTERs were deliberately removed for Schema-Builder-only DDL | **No CHECK.** Non-negativity enforced in validation + inside the lock |
| `decimal(18,4)` | Distribution quantities are `decimal(12,3)`; Preparation is `decimal(18,4)` | **`decimal(12,4)`** — see below |

**On precision, specifically.** Neither module's default is right here. `order_lines.quantity` — the column Required is summed from, and the only quantity Prepared is ever compared against — is `decimal(12,4)`. Distribution's usual `decimal(12,3)` **cannot represent a 4-decimal Required**, which would leave the ceiling `Prepared <= Required` unsatisfiable for a fractional line. Preparation's `(18,4)` is another module's scale. Matching the compared column is the only choice that makes the ceiling reachable.

`unsignedDecimal` was considered for DB-level non-negativity and rejected: it appears **nowhere** in this codebase and is deprecated in MySQL 8.0.17+.

**What it deliberately does not store:** Required, Remaining, order, warehouse, `preparation_wave_id`, status. Each omission is justified in the migration docblock.

---

## 4. Prepared Source of Truth

**Two different facts. Never merged, never summed, never reconciled.**

| | Grain | Owner | Written by |
|---|---|---|---|
| **Preparation Prepared** | `(wave, product)` | the wave's preparation operator | `WaveDemandController::updatePrepared` — **untouched** |
| **Group Prepared** | `(Group, product)` | the Group's loading operator | `GroupPreparationService::record` — **new** |

```
Σ Prepared(group) over a wave's Groups  ≠  Prepared(wave)
```

— because a wave's orders need not all be grouped, and a Group's orders can span several waves.

**Not done, explicitly:** `wave_product_demand.prepared_qty` is not overwritten, not copied into, and not read as a Group figure. `preparation_wave_items.quantity_prepared` is not used or populated. `prepared_products_pool` is not written — it remains Actual Loading's input. **Value-level audit confirms all four `wave_product_demand` rows are byte-identical before and after** (§23).

---

## 5. Required Source

Unchanged and canonical: `DistributionAggregationService::productAggregation($window, null, $slot, $warehouse)`.

No second aggregation was created, none was added in React, no order-line summing was duplicated client-side, and Required is not stored.

`GroupPreparationService::requiredFor()` calls **that same method** and picks one product out of the result, rather than issuing a targeted single-product query. That is deliberate: a second query would be a second definition of Required and the two could drift. A Group holds a handful of products, so one aggregate read per write is the right price for that guarantee — and the test asserts the endpoint's Required is identical to the service's own output.

---

## 6. Remaining Derivation

```
remaining_qty      = max(0, Required − Prepared)     derived server-side
over_prepared_qty  = max(0, Prepared − Required)     derived server-side
```

**Never stored.** No `remaining_qty` column exists on the new table, and the known-inconsistent `wave_product_demand.remaining_qty` (live: required 3, prepared 2, stored remaining 0) is neither read nor repaired here.

**Why `over_prepared_qty` exists at all.** Remaining is floored at zero, so a Group whose Required *fell* under an already-recorded Prepared would otherwise read "0 — nothing to do" while its pallet holds more than the Group needs. A test corrupts the stored quantity directly and asserts the API still derives correctly, so the derivation cannot silently regress into a read.

---

## 7. Write Route

```
PUT /api/logistics/distribution/windows/{window}/slots/{slot}/preparation/{product}
```

| | |
|---|---|
| **Method** | `PUT` — the body IS the new value |
| **Body** | `{ "prepared_qty": number }` — `required`, `numeric`, `min:0` |
| **No `max:` rule** | deliberate: Required is live, so a controller-level ceiling would read it outside the lock |
| **No `exists:products,id`** | an existence rule on a table the actor may not own is a cross-tenant oracle — the same reason `warehouse_id` is a bare uuid at `:248` and checked tenant-scoped afterwards. A product the Group does not require is refused regardless of whether it exists |
| **Response** | the Group's whole refreshed Loading Preparation list, from the **same presenter** the GET uses |
| **Errors** | `422` over-ceiling / negative / not-required · `404` foreign window, slot or tenant |

**An existing endpoint was checked first, as required.** All eight existing Distribution mutations were reviewed; none carries a `(group, product, quantity)` triple and none can acquire one without changing its meaning. Overloading `PATCH /assignments/{a}/slot` — the closest in shape — would make an order-membership endpoint also write product quantities.

**One presenter, two callers.** `groupLoadingPreparation()` serves both the GET and the PUT echo, so a read and a write can never present a different idea of the same Group. (A first attempt re-invoked the controller action with `$request->merge()`; that was wrong — `$request->query()` reads the query bag, which `merge()` does not touch — and was replaced with the shared private presenter.)

---

## 8. Authorization

**`operations.preparation.update`. No new permission was created and no permission was granted to any user.**

Chosen on evidence from the live role matrix, not on which module owns the URL:

| Role | `logistics.distribution.update` | `operations.preparation.update` |
|---|---|---|
| **Warehouse Operator** — separates the goods | **NO** | **yes** |
| Warehouse Manager | **NO** | yes |
| Preparation Supervisor | **NO** | yes |
| Branch Manager | **NO** | yes |
| **Driver** | **yes** | NO |
| Dispatcher | **yes** | NO |

Gating on the Distribution permission would have **locked out every role that performs this work and admitted two that do not** — including the Driver, whom the task explicitly excludes.

**Gating by actor rather than by owning module already has a precedent here:** `PUT preparation/waves/{w}/missing-materials/{m}/expected-incoming` is a *Preparation* route carrying `purchasing.expected_incoming.update`, for exactly this reason.

The CI guard (`tests/Feature/Security/WriteRouteAuthorizationTest`) asserts against the real route table that every write route is authorized; this route carries its permission from its first line and is not on the `ALLOWED` list.

---

## 9. Tenant / Warehouse Scope

Every read and write reaches the table **only through a Group that has already been tenant-resolved**. The model has no global scope and never scopes itself — all 17 Distribution models work this way, and the new one follows.

| Boundary | Enforcement |
|---|---|
| Tenant | `window()` → `where('company_id', $this->companyId($request))`, which aborts 403 on a null company |
| Group ↔ Window | `slot()` → `where('distribution_window_id', $window->id)` |
| Warehouse | taken from `$group->warehouse_id` (NOT NULL since Part 5B) — **never** from a request parameter, on either the read or the write |
| Probing | a foreign window/slot uuid returns **404**, never 403 and never an empty list |

**Verified live:** a foreign-tenant user gets 404 on both the GET and the PUT, and the write changes nothing.

---

## 10. Concurrency

```php
DB::transaction(function () {
    $locked   = VirtualCapacitySlot::query()->lockForUpdate()->findOrFail($group->id);  // 1. lock the GROUP
    $required = $this->requiredFor($locked, $productId);                                // 2. LIVE Required, inside the lock
    if ($prepared - $required > self::EPSILON) { throw ... }                            // 3. ceiling, fails closed
    $row->prepared_qty = $prepared; $row->save();                                       // 4. ABSOLUTE SET
});
```

**The Group is the lock target, not the preparation row.** The row may not exist yet, so locking it cannot serialise the *first* write for a product — two racing creates would both find nothing and one would die on the unique index. Locking the Group removes the create race and the ceiling race with one lock, and it is the pattern this codebase already uses for this exact shape: `CapacityLedgerService::reserve()` — *"Lock the slot: two concurrent reservations against the last order must not both succeed."*

**The cost, stated rather than hidden:** two operators preparing *different* products in the *same* Group serialise behind each other. The transaction is one aggregate read plus one row write, and a Group is worked by one warehouse team.

`EPSILON = 0.00005`, the same constant and role as `RecordProductDeliveryAction` and `LoadProductAction` — a float ceiling must not refuse a value equal to the ceiling.

**No new concurrency framework, no optimistic locking, no version column, no advisory locks.**

---

## 11. Idempotency

**Absolute set, not increment.** `prepared_qty = N`, never `+= N`.

```
set 1  → prepared 1, remaining 1
set 1  → prepared 1, remaining 1     ← replay: identical, one row
```

Verified live through the API and asserted by test. Combined with `unique(virtual_slot_id, product_id)`, a retried request can neither double-count nor create a second row.

**No idempotency keys were introduced.** None exists in this platform outside Finance/HR/EventPlatform, and none is needed — the same reasoning `RecordProductDeliveryAction` states for itself: *"replaying the same confirmation is a no-op rather than a double count."*

---

## 12. Group Membership Behavior

**Prepared is anchored to the mutable Group. No membership snapshot was created.**

| Event | Required | Prepared |
|---|---|---|
| Zone added | ↑ re-derived | unchanged |
| Zone removed | ↓ (may reach 0) | **unchanged, retained, and VISIBLE** as over-prepared |
| Zone moved | both Groups re-derive | unchanged in both |
| Order cancelled / postponed | ↓ re-derived, zero Distribution writes | unchanged |
| Order becomes eligible again | ↑ re-derived | unchanged |

**Never automatic:** no transfer between Groups, no recalculation from orders, no invented historical allocation, no destructive cleanup. Moving stock is a physical act and the system must not assert one that has not happened.

**Live proof on real DG-001** — remove zone 7, then restore it:

```
before   FG-HONEY-250 req 2 prep 1 rem 1 over 0   ECOS-FG-000001 req 1 prep 1 rem 0 over 0
removed  FG-HONEY-250 req 0 prep 1 rem 0 over 1   ECOS-FG-000001 req 0 prep 1 rem 0 over 1
restored FG-HONEY-250 req 2 prep 1 rem 1 over 0   ECOS-FG-000001 req 1 prep 1 rem 0 over 0
```

Prepared survived the round trip untouched, and DG-001 was returned to exactly 3 orders and 1 zone.

---

## 13. Capacity

**No capacity model was added and no capacity enforcement was implemented.** `capacity_orders` remains the only Group constraint, exactly as before.

LP-2 introduces no weight, no volume, no stops, no dimensions and no vehicle-fit calculation. The new table carries a product quantity and nothing that could be summed into a capacity figure — it is not, and must not become, a hidden vehicle-planning engine.

The Group context strip continues to show Max orders and Remaining capacity from the existing Group read model, unchanged.

---

## 14. Loading Preparation UI

**The existing LP-1 surface was extended. No new workspace, no new screen, no new page.**

```
Product │ SKU │ Required │ Prepared │ Remaining │ Unit
```

with the Group context strip unchanged above it (Warehouse · Zones · Orders · Max orders · Remaining capacity), all still from the existing `SlotSummary` — no Group figure is recomputed.

**The Prepared cell is the only editable control on the screen.** It follows the platform's existing inline-numeric precedent (`ExpectedIncomingCell`, `wave-missing-materials-page.tsx`) rather than inventing an interaction:

- `type="text"` + `inputMode="decimal"` — a native number spinner looks nothing like the rest of the app
- **explicit commit only** — the check button or Enter; **never onBlur**, so clicking into a cell and away can never save
- Escape or ✕ discards
- fixed-width wrapper in both modes, so switching cannot shift the column
- a pending spinner and a 2.5s "just saved" affirmation in the cell, so the operator sees *which* row saved

**One product at a time.** There is no "save all" — each commit is its own atomic absolute-set request, so a failure on one row cannot half-apply a batch.

`over_prepared_qty > 0` renders an amber **"Over-prepared by N"** line under Remaining. Verified live.

**i18n:** 9 new keys in the existing `logistics` namespace under `distributionWorkspace.loadingPreparation` — EN and AR, **23 keys each, verified at exact parity, zero EN-only, zero AR-only**. No new namespace, so no `namespaces.ts` / `i18n/types.ts` registration. The stale `preparedNote` — which said Prepared *cannot* be shown per Group — was replaced, since LP-2 makes it false.

---

## 15. React Query Synchronization

**No second synchronisation mechanism.** `useSetGroupPrepared` is the **eighth** mutation on the same root, invalidated the same way as the other seven:

```ts
onSuccess: () => qc.invalidateQueries({ queryKey: ['logistics-distribution-workspace'] })
```

| After | Refreshes |
|---|---|
| Add / Remove / Move Zone | Required — via the seven existing mutations, unchanged |
| Set Prepared | Prepared **and** Remaining — via the new eighth |

The PUT additionally returns the Group's whole refreshed list from the server's own presenter, so the writer's view is correct immediately rather than only after the refetch lands — and if Required moved under a stale client, that is the moment it finds out.

---

## 16. Preparation Boundary

**Not modified.** Untouched: `MoveToPreparationWorkflow`, the wave lifecycle, `wave_product_demand`, `preparation_wave_items`, `prepared_products_pool`, Preparation eligibility, wave completion, `refreshKpis`, and every Preparation action, service and event.

The only relationship is the approved one:

```
Preparation completes → order becomes ready_for_dispatch → the Group sees it (LP-1.0)
  → Group Loading Preparation records its OWN Prepared quantity
```

Value-level audit confirms every Preparation table is byte-identical (§23).

---

## 17. Inventory Boundary

**NON-MUTATING.** No stock deduction, no reservation, no stock movement, no FIFO layer, no `inventory_items` write, no reservation change, no available-quantity change.

LP-2 reads **no inventory table at all**. Reservation already happened upstream, per order, when `MoveToPreparationWorkflow` moved the order to `ready_for_dispatch`.

**Verified at value level, not just by row count:** all five `inventory_items` rows have byte-identical `on_hand_qty` and `reserved_qty` before and after, and `stock_ledger_entries` / `stock_movements` are unchanged.

---

## 18. Vehicle / Driver Boundary

**Nothing implemented.** No vehicle assignment, no driver assignment, no vehicle capacity, no trip creation, no dispatch. `vehicle_assignments`, `vehicle_plans` and `vehicle_plan_slots` remain at 0 rows.

The Group still carries no `vehicle_id` and no `driver_id` — the absence is what keeps it re-plannable.

---

## 19. Actual Loading Boundary

**Not modified.** `loading_sessions`, `loading_tasks`, `vehicle_assignment_id`, `allocation_records` and every Loading action are untouched, all still at 0 rows.

Actual Loading remains vehicle-anchored — `loading_tasks.vehicle_assignment_id` is a NOT NULL FK — and Group Loading Preparation remains the **pre-vehicle** warehouse operation. Nothing was forced into those tables.

### Virtual Vehicle Planning

Not revived, not referenced, not built upon. `vehicle_plan_slots` and the rest of the residue remain at 0 rows and untouched.

---

## 20. Tests

**61 tests, 550 assertions, 0 failures** — full detail in §20.3.

### 20.1 What was added, and the one test that had to be inverted

**11 focused tests** appended to `DistributionGroupLoadingPreparationTest` — reusing its 14 existing fixture helpers rather than duplicating ~200 lines — plus 5 new helpers (`preparableGroup`, `setPrepared`, `storedPrepared`, `preparationRowCount`, `waveDemandFor`).

**16 of the 17 existing LP-1 / LP-1.0 tests are unmodified. One was inverted, exactly as predicted and approved.**

LP-1 test 6, `test_the_projection_never_reports_a_group_prepared_or_remaining_quantity`, asserted that **no** prepared or remaining figure appears on this payload under any name. That was correct while the only Prepared in existence was Preparation's `(wave, product)` number, which cannot be split across the Groups sharing a wave. **LP-2 is the approved decision to stop that being the last word** — it does not split the wave number, it records a separate Group-owned one.

This obsolescence was flagged in writing twice before any code was written — LP2-DECISION §2 (*"must be retired or inverted if LP-2 ships"*) and the LP-1.0 report §21.7 — so it is a predicted contract change, not a regression discovered late. The first run confirmed it precisely: **50 of 51 passed, and the single failure was that test and only that test.**

It was **inverted, not deleted**, because half of it is still true and still worth guarding. The replacement, `test_the_projection_reports_the_groups_own_prepared_and_never_the_waves`, now:

- seeds a **wave-level** demand row for the same product with a deliberately different value (`required 40, prepared 37`);
- asserts the Group payload reports its **own** Prepared — `0`, not the wave's `37` — and a Remaining derived from the *Group's* figures;
- asserts Preparation's vocabulary (`quantity_prepared`, `wave_prepared_qty`, `wave_required_qty`, `completion_pct`) never appears on a Group-scoped payload.

The conflation LP-1 refused to ship is therefore still guarded — more strongly than before, because there is now a wave number present for it to fail against.

### 20.2 The task's twenty required behaviours, mapped

| # | Required behaviour | Test |
|---|---|---|
| 1 | Group + Product Prepared can be created | `..._is_created_once_then_updated_never_duplicated` |
| 2 | Existing record is updated, not duplicated | same — asserts row count stays 1 |
| 3 | Required comes from canonical aggregation | `..._returns_required_prepared_remaining_and_unit` — asserts identity with the service's own output |
| 4 | Prepared returned from Group storage | same |
| 5 | Remaining derived `max(0, R − P)` | same — **corrupts the stored value and asserts the API still derives** |
| 6 | Prepared cannot exceed live Required | `..._cannot_exceed_required_and_cannot_be_negative` |
| 7 | Ceiling checked inside the transaction/lock | `..._takes_a_row_lock_and_recomputes_required_inside_it` + `..._against_required_as_it_is_at_write_time` |
| 8 | Prepared cannot be negative | `..._cannot_exceed_required_and_cannot_be_negative` |
| 9 | Absolute-set request is idempotent | `..._is_created_once_then_updated_never_duplicated` |
| 10 | Concurrent writes cannot exceed Required | `..._two_sequential_writes_cannot_accumulate_past_required` (+ #7's lock assertion) |
| 11 | Foreign tenant cannot read | `..._can_neither_read_nor_write_group_prepared` → 404 |
| 12 | Foreign tenant cannot write | same → 404 |
| 13 | Foreign warehouse Group cannot be written | `..._cannot_be_written_through_this_one` |
| 14 | Membership change refreshes Required | `..._refreshes_required_and_never_moves_prepared` |
| 15 | Prepared not silently reassigned between Groups | same + the warehouse test's two-Group separation |
| 16 | Inventory untouched | `..._writes_nothing_outside_its_own_table` |
| 17 | Preparation tables untouched | same |
| 18 | Existing Group operations still work | membership test drives the real detach/attach endpoints |
| 19 | LP-1 Required behaviour intact | `..._is_unchanged_for_window_and_zone_reads` + the 17 pre-existing tests |
| 20 | Read returns Product/SKU/Required/Prepared/Remaining/Unit | `..._returns_required_prepared_remaining_and_unit` |

**On requirement 7 and 10, stated honestly.** PHPUnit cannot run two connections in true parallel, so "concurrent" is proven in two complementary ways rather than one simulated one: the lock test asserts on the **actual SQL issued** — that a `for update` is taken on `distribution_virtual_slots` and that `SUM(ol.quantity)` is recomputed within the same write — and the sequential test proves the observable guarantee that absolute-set cannot accumulate. The live-Required test proves the ceiling uses Required *at write time*, by reducing Required between two writes and watching the second be refused. I am not claiming a multi-connection race was simulated.

### 20.3 Results

```
tests/Feature/Logistics/DistributionGroupLoadingPreparationTest.php   (28: 8 LP-1 + 9 LP-1.0 + 11 LP-2)
tests/Feature/Logistics/DistributionCoreTest.php                      (23)
tests/Feature/Logistics/DistributionPreparationEligibilityTest.php    (10)

OK (61 tests, 550 assertions)        Time: 07:05
```

The two companion suites are not padding. `DistributionCoreTest` **owns** `productAggregation` — the method LP-2 now calls from inside a transaction — and `DistributionPreparationEligibilityTest` **owns the eligibility contract** that LP-1.0 extended and LP-2 depends on. Both green, both unmodified.

**Run history, stated in full rather than only the green one:**

| Run | Result |
|---|---|
| 1 — before the test-6 inversion | **51 tests, 469 assertions, 1 failure** — the single failure was LP-1 test 6 and nothing else, exactly as predicted in §20.1 |
| 2 — after inverting test 6 | **61 tests, 550 assertions, 0 failures** |

Every run went through `GATE_WAIT=2400 scripts/test-gate.sh`, never bare phpunit, because the test schema is pinned and contended — each queued behind another agent's `migrate:fresh` before acquiring the advisory lock. `config:clear` and `route:clear` were run in the container first, and every changed file was `docker cp`-ed in, so each run executed against the edited code rather than a stale image.

**No full-ERP regression was run, and none is claimed.** No fabricated business data: every fixture lives inside `RefreshDatabase` and is torn down with it.

---

## 21. Browser Verification

Real dev stack, real DG-001, real data, signed in with the repository's own documented local dev account. **No warehouse, Group, order, supplier, payment or inventory was created.**

| # | Check | Result |
|---|---|---|
| 1 | Open DG-001 | **PASS** |
| 2 | Open Loading Preparation | **PASS** |
| 3 | Existing Required products appear | **PASS** — Honey 2 pcs, ECOS-FG-000001 1 kg |
| 4 | Columns present | **PASS** — Product · SKU · Required · Prepared · Remaining · Unit |
| 5 | Set Prepared via the editor | **PASS** — opened the cell, typed `1`, clicked save |
| 6 | Prepared updates | **PASS** — 0 → 1 |
| 7 | Remaining updates | **PASS** — 1 → 0 |
| 8 | Reload | **PASS** |
| 9 | Persistence | **PASS** — identical after a full page reload |
| 10 | Change Group membership (existing operation) | **PASS** — zone 7 detached, then restored |
| 11 | Required refreshes | **PASS** — 2/1 → 0/0 → 2/1 |
| 12 | Prepared remains Group-scoped | **PASS** — survived the round trip; rendered as **"Over-prepared by 1"** while unrequired |
| 13 | Prepared > Required refused | **PASS** — UI guard blocks and toasts; API returns 422 *"Prepared quantity (99) cannot exceed the quantity this group requires (2)."* |
| 14 | Invalid-input feedback reaches the operator | **PASS** — toast observed via MutationObserver (it auto-dismisses) |
| 15 | No inventory mutation | **PASS** — §23 |
| 16 | No order status mutation | **PASS** — §23 |

### Classification — stated precisely, not upgraded

The browser pane in this environment **is not compositing** (viewport reports 0×0), so screenshots and coordinate clicks are unavailable and `innerText` is empty for laid-out elements. Every interaction above was therefore driven through the page's **real React handlers and the real network stack** — the actual editor `onClick`, the actual controlled-input `onChange` via the native setter, the actual save handler, the actual mutation — and read back from the live DOM via `textContent` and from `read_network_requests`.

**This is UI-handler and network verification, not human pixel-click acceptance.** I am not claiming the latter. Arabic rendering was likewise not visually confirmed; EN/AR key parity is verified programmatically (23/23) but the RTL layout of the new columns is **NOT browser-verified**.

---

## 22. Static Gates

Both backend and frontend files were touched, so both gate sets were run.

| Gate | Scope | Result |
|---|---|---|
| `php -l` | all 6 backend files | **No syntax errors** |
| PHPStan | model, service, controller, migration | **[OK] No errors** |
| Pint | model, service, migration, test file | **PASS — 4 files** |
| Pint | `DistributionWindowController` | 1 pre-existing import-order nit; **measured**: Pint applied to a copy and diffed → **0** of its changes touch an LP-2 line |
| ESLint | `src/features/logistics/distribution-workspace` | **0 problems** |
| TypeScript | `tsc -p tsconfig.app.json` | **23 errors — the unchanged pre-existing baseline; 0 in this feature** (verified by grep) |
| Vite build | app | **✓ built in 7.52s, exit 0** |
| EN/AR i18n parity | `distributionWorkspace.loadingPreparation` | **23 / 23, zero one-sided keys** |

**Full-repository cleanliness is not claimed.** `npm run verify` was deliberately not used as a gate: it is **already red at baseline** (3,316 hardcoded strings, 826 RTL-unsafe classes, coverage 79.62%) and could not have verified anything about this change.

---

## 23. Side Effects

Audited at **value level**, not merely by row count.

**Row counts — 21 tables, before vs after: IDENTICAL.**

```
orders 14 · order_lines 17 · preparation_waves 3 · preparation_wave_orders 11
wave_product_demand 4 · preparation_wave_items 0 · prepared_products_pool 0
preparation_inventory_reservations 0 · distribution_virtual_slots 1
distribution_slot_zones 1 · distribution_window_orders 9 · distribution_windows 2
distribution_zones 10 · vehicle_plans 0 · vehicle_plan_slots 0 · loading_sessions 0
loading_tasks 0 · vehicle_assignments 0 · inventory_items 5
stock_ledger_entries 24 · stock_movements 0
```

**Value-level diffs — all IDENTICAL:**

| Table | Checked |
|---|---|
| `inventory_items` | `on_hand_qty` and `reserved_qty` per product — unchanged |
| `wave_product_demand` | `required_qty`, `prepared_qty`, `remaining_qty`, `preparation_completed_at` per row — unchanged |
| `orders` | `status` and `reservation_status` per order — unchanged |
| DG-001 | 3 orders, 1 zone, warehouse — restored exactly after the membership test |

**The only intended changes:**

1. the new `distribution_group_product_preparation` table (schema), and
2. **two rows in it** — `FG-HONEY-250 → 1.0000`, `ECOS-FG-000001 → 1.0000`.

Nothing else in the audited set moved. **No unexpected mutation occurred, so no STOP condition triggered.**

---

## 24. Files Changed

**10 files: 3 new, 7 modified.** All previously-existing Distribution files were already untracked — the module is uncommitted work in progress.

### Backend (6)

| File | Change |
|---|---|
| `…/Migrations/2026_08_21_100001_create_distribution_group_product_preparation_table.php` | **NEW** — the one table |
| `…/Domain/Models/GroupProductPreparation.php` | **NEW** — the model |
| `…/Domain/Services/GroupPreparationService.php` | **NEW** — lock + live ceiling + absolute set |
| `…/Presentation/Http/Controllers/DistributionWindowController.php` | `+setGroupPreparation()`, `+groupLoadingPreparation()` presenter, `products()` extended for the `slot_id` case only |
| `routes/api.php` | **+1 route** with `operations.preparation.update` |
| `tests/Feature/Logistics/DistributionGroupLoadingPreparationTest.php` | **+11 tests, +5 helpers**; 16 of 17 existing unmodified, **1 inverted by approved contract change** (§20.1) |

### Frontend (5)

| File | Change |
|---|---|
| `…/types/index.ts` | `GroupRequiredProduct` gains `prepared_qty`, `remaining_qty`, `over_prepared_qty` |
| `…/services/distribution-workspace-service.ts` | `+setGroupPrepared()` |
| `…/hooks/use-distribution-workspace.ts` | `+useSetGroupPrepared()` — the 8th mutation on the existing root |
| `…/components/group-loading-preparation.tsx` | `+PreparedCell` editor, `+Prepared` and `+Remaining` columns, corrected header docblock |
| `i18n/locales/{en,ar}/logistics.json` | +9 keys each; `preparedNote` corrected |

**Zero migrations beyond the one. Zero new permissions. Zero new events. Zero new query-key roots.**

Neither `ecos-dev-app` nor `ecos-dev-testrunner` bind-mounts the source, so every changed file was `docker cp`-ed into both and `config:clear` / `route:clear` run — each verification above ran against the edited code, not a stale image.

---

## 25. Risks / Limitations

Stated rather than implied.

1. **No foreign keys on the new table**, following the module's explicit convention. There is therefore no DB-level bar on orphaning a row by deleting its Group. **No Group delete path exists today** (`storeSlot` has no `destroy` sibling), so nothing can currently produce that orphan — but if one is added it must refuse while prepared work exists, or re-home the rows.
2. **No CHECK constraint on `prepared_qty >= 0`**, following the module's zero-CHECK convention. Non-negativity is enforced by request validation and again inside the lock. A direct SQL write could still store a negative — the same exposure every other Distribution column already has.
3. **Per-Group lock granularity.** Two operators preparing *different* products in the *same* Group serialise. Accepted deliberately (§10); per-product locking would need a create-race retry loop.
4. **True multi-connection concurrency is not simulated** in the test suite (§20.2). The lock is asserted structurally, from the SQL actually issued.
5. **Browser verification is UI-handler/network level, not human pixel-click** (§21), because the pane is not compositing in this environment.
6. **Arabic RTL layout of the new columns is NOT browser-verified.** Key parity is programmatic only.
7. **`over_prepared_qty` is surfaced but nothing acts on it.** There is no reconciliation workflow, no "return to stock" action and no alert — the operator sees the number and resolves it physically. That is the approved scope; a reconciliation action would be a new decision.
8. **A Prepared record survives its Window indefinitely**, because Distribution Windows never close (the pre-existing BLOCKER-2). Prepared rows carry `distribution_window_id` precisely so a future retention sweep can find them, but no sweep exists.
9. **Three Distribution tests remain red for a pre-existing reason** — `DistributionReadModelApiTest:318,354,362` and `DistributionOrdersFilterApiTest:247` filter `?order_status=new`, a status the ADR-042 supersession retired on 2026-08-13. Proven pre-existing by control run during LP-1.0; not fixed here, and not caused by this work.
10. **`stock_ledger_entries` at 24 and `distribution_window_orders` at 9** are pre-existing rows from other work in this shared worktree.

---

## 26. STOP Conditions

All twenty were evaluated. **None triggered.**

| # | Condition | Status |
|---|---|---|
| 1 | Required cannot be safely read from canonical aggregation | **No** — `productAggregation` used verbatim |
| 2 | Prepared cannot be owned by Group + Product | **No** |
| 3 | The new table would duplicate another source of truth | **No** — every candidate disproved in the migration docblock; it stores neither Required, Remaining, order, inventory nor wave totals |
| 4 | Migration requires changing a certified Preparation contract | **No** |
| 5 | Preparation must be modified | **No** |
| 6 | Inventory must be modified | **No** |
| 7 | Reservation must be modified | **No** |
| 8 | Order status must be changed | **No** |
| 9 | Vehicle Planning must be revived | **No** |
| 10 | Actual Loading must be changed | **No** |
| 11 | A new permission is required | **No** — `operations.preparation.update` reused |
| 12 | Group ownership must change | **No** |
| 13 | Warehouse ownership must change | **No** |
| 14 | Tenant boundary cannot be preserved | **No** — 404 on foreign window/slot, verified live |
| 15 | Required cannot be locked/recomputed safely for the ceiling | **No** — recomputed inside the Group lock |
| 16 | `Prepared > Required` can occur under concurrency | **No** — absolute set + ceiling inside the lock |
| 17 | Remaining must be stored | **No** — derived, and a test corrupts storage to prove it |
| 18 | A second Prepared source of truth becomes necessary | **No** — Group Prepared and Preparation Prepared are different facts, never merged |
| 19 | Destructive cleanup of Groups/memberships/Prepared is required | **No** — nothing is deleted; orphans are retained and surfaced |
| 20 | A new architectural decision is required | **No** — the three convention deviations (§3) are conventions, not architecture, and each is documented |

---

## 27. Final Verdict

### **GROUP + LOADING PREPARATION — IMPLEMENTED / VERIFIED**

| Criterion | Status | Evidence |
|---|---|---|
| Group + Product Prepared model | **PASS** | one table, `unique(virtual_slot_id, product_id)`; created and updated in place, never duplicated |
| Required from canonical aggregation | **PASS** | `productAggregation` used verbatim; test asserts endpoint output identical to the service's own |
| Remaining derived, never stored | **PASS** | no column exists; a test corrupts stored Prepared and the API still derives correctly |
| Ceiling `Prepared <= Required`, inside the lock | **PASS** | SQL-level assertion that `for update` is taken on the Group **and** `SUM(ol.quantity)` recomputed in the same write; plus a live-Required test that reduces Required between writes |
| Absolute set / idempotent | **PASS** | replay writes the same number, one row; verified live and by test |
| Non-negative | **PASS** | validation + in-lock guard |
| Tenant / warehouse scope | **PASS** | 404 on foreign window or slot, read and write; warehouse taken from the Group, never the request |
| Authorization | **PASS** | `operations.preparation.update`, **no new permission**; chosen on the live role matrix |
| Group membership behaviour | **PASS** | Prepared never deleted, moved, recalculated or invented; retained records surfaced as over-prepared |
| Inventory untouched | **PASS** | value-level: `on_hand_qty` / `reserved_qty` byte-identical |
| Preparation untouched | **PASS** | value-level: all four `wave_product_demand` rows byte-identical |
| UI extends LP-1, no new workspace | **PASS** | same panel, Prepared editor + Remaining column added |
| Sync via the existing root | **PASS** | 8th mutation, same `invalidateQueries` |
| Focused tests | **PASS** | **61 tests, 550 assertions, 0 failures** |
| Static gates | **PASS** | PHPStan no errors · Pint pass, **0 new debt measured** · ESLint 0 · tsc 23 = unchanged baseline, 0 in-feature · Vite ✓ · i18n 23/23 |
| Side effects | **PASS** | 21 tables identical; only the new table and its 2 rows changed |
| STOP conditions | **NONE TRIGGERED** | all 20 evaluated, §26 |

### Browser classification — stated precisely, not upgraded

**UI-handler and network verified** against real DG-001: the Prepared editor opened, a value typed and saved through the real React handlers, Prepared and Remaining updated, persisted across a full reload, Group membership changed via the existing operation with Required refreshing and Prepared surviving untouched, over-ceiling refused with the server's own message, and the invalid-input toast observed.

**NOT human pixel-click acceptance** — the browser pane in this environment does not composite (viewport 0×0), so screenshots and coordinate clicks were unavailable. **Arabic RTL layout of the new columns is NOT browser-verified**; EN/AR key parity is programmatic only. Neither classification is upgraded.

### Explicitly NOT claimed

Vehicle Planning is not implemented · Vehicle Assignment is not implemented · Driver Assignment is not implemented · Actual Loading is not implemented · Dispatch is not implemented · full Distribution certification is not claimed · full ERP certification is not claimed · full-repository static cleanliness is not claimed · full-ERP regression was not run.

---

**STOPPING here.** Vehicle + Driver, Review / Finalize, Actual Loading and Dispatch remain unstarted and await separate approval. **Not committed.**
