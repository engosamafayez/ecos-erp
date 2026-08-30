# TASK-1-A — WINDOW RESOLUTION + GROUP CAPACITY UI — FINAL REPORT

**Date:** 2026-08-24 · **Branch:** `develop` · Authoritative. Supersedes
`TASK-1-A-WINDOW-RESOLUTION-CAPACITY-UI-REPORT.md`.

> # IMPLEMENTED · VERIFIED · BROWSER NOT VERIFIED — AUTHENTICATION CONSTRAINT
>
> **Not certified**, because browser verification is unavailable (§9).
> No commit. No deploy. No migration. No API contract break. No RBAC change.

---

## 1. H1 = Option B — approved and implemented

**Ruling applied:** a Preparation Wave is the **selector** for the current operational cycle
when one exists. It is **not** a prerequisite for reading Distribution. A Distribution Window
is independently valid and is not structurally owned by a wave.

### Resolution behaviour, exactly as built

`DistributionWindowService::resolvePlanningWindow()` — a READ that **never creates**:

| Condition | Result |
| --- | --- |
| Warehouse context **and** a governing wave resolves | The planning window anchored on that cycle's **active** wave membership |
| No resolvable wave (`$waveId === null`) | `currentWindow()` — the **existing** window, or null |
| Wave resolves but no assignment anchors it | `currentWindow()` — the **existing** window, or null |
| Anchor names a window this tenant cannot see | `currentWindow()` — the tenant's **own** existing window, or null |
| No existing window at all | `null` → the explicit unresolved state |

**"Fallback" means resolving an EXISTING window.** Verified mechanically: the method body
contains **three** `return $this->currentWindow(...)` calls and **zero** calls to
`windowFor()`. `currentWindow()` is the pre-existing non-creating read, documented
*"Returns null rather than creating one, for read paths that must not have side effects."*

Creation remains available only to the path that legitimately needs it — the collector —
through the explicit `resolveOrCreatePlanningWindow()`, used solely by
`DistributionCollectionService`. Both paths share one resolution rule; the create variant adds
creation and nothing else, so reader and writer cannot disagree about which window a cycle is
planning.

`POST /windows/collect` no longer creates a window merely to run its reconcile step; with no
window it reports `rezoned: 0`.

### Why a Preparation Wave is not a prerequisite

Three structural facts, each verified in this task:

1. **`distribution_windows` is keyed `(company_id, window_date)`** — there is no
   `preparation_wave_id` and no `warehouse_id` column, and no FK. The schema expresses no
   wave→window relationship at all.
2. **Ingestion consults no wave.** `resolveIngestionWindow()` uses today's window plus a
   date/clock cutoff (§16). Collection writes assignments without ever reading a wave.
3. **`resolvePlanningWindow()` picks among windows that already exist** — an anchor, not a
   parent.

Requiring a wave to read would therefore have asserted a dependency the data model does not
have. The earlier fail-closed-everywhere build did exactly that, and it failed 136 approved
tests.

---

## 2. Proof that reads do not create windows

Three independent proofs.

**Code:** zero `windowFor()` calls inside `resolvePlanningWindow`; the only creator reachable
from a read path was removed.

**Test:** `test_without_any_existing_window_the_read_resolves_nothing_and_creates_nothing`
asserts `distribution_windows` count is 0 before **and** after the read. Every unresolved-state
test also asserts a before/after count.

**Live, on real data (`ecos_dev`, GET only):**

```
windows BEFORE reads: 4
GET /windows/current                    -> no_planning_window / no_window_available
GET /windows/current?warehouse_id=…     -> resolved, window_date 2026-08-21
windows AFTER  reads: 4      ← unchanged
```

Today is 2026-08-24 and **no 2026-08-24 row was created**. Under the previous behaviour both
GETs would have minted one — which is exactly how the empty 08-22 and 08-23 windows found in
the audit came to exist.

---

## 3. Focused tests — 9/9 green, 69 assertions

`tests/Feature/Logistics/DistributionWindowResolutionAndCapacityTest.php`

| Required proof | Test |
| --- | --- |
| 1. No warehouse context does not create a window | `test_a_read_with_no_warehouse_does_not_fall_back_to_todays_window` |
| 2. No resolvable wave does not block valid reads | `test_a_missing_preparation_wave_does_not_block_a_valid_read` — asserts `preparation_waves` count is **0**, then asserts the read resolves the window holding the work |
| 3. Existing window can still be resolved | `test_an_existing_window_resolves_without_a_wave_or_a_warehouse` |
| 4. Warehouse + governing wave → correct cycle window | `test_the_correct_warehouse_resolves_the_window_holding_its_cycle` |
| 5. Missing window not created by a read | `test_without_any_existing_window_the_read_resolves_nothing_and_creates_nothing` (in the Finalization class) + count assertions throughout |
| 6. Capacity UI correct | `test_groups_expose_canonical_current_maximum_and_remaining` |
| 7. Trip capacity untouched at 60 | asserted in `test_reads_create_no_wave_no_group_and_no_membership_change` |
| 8. No Group membership changes | same test (membership map compared before/after) |
| 9. No new Preparation Wave | same test |
| 10. No new Distribution Window by reads | same test, plus §2 |

Also covered: remaining floors at 0 and is `null` (never `0`) when there is no maximum; a
second warehouse sees **none** of the first's groups or zone work even though the window row is
company-scoped by design.

---

## 4. Regression — baseline restored exactly

`--filter "Distribution|DistributorOrders"`

| Run | Tests | Failures |
| --- | --- | --- |
| Control (pre-TASK-1-A behaviour) | 314 | **25** pre-existing + 4 of my new tests correctly failing without the fix |
| Fail-closed-everywhere (rejected) | 314 | 161 |
| **Option B, final** | **316** | **25 — exactly the pre-existing baseline** |

**Final failures, all three classes pre-existing and untouched:**

| Class | Count | Verdict |
| --- | --- | --- |
| `DistributionModuleTest` | 22 | **PRE-EXISTING** — all 22 are `403 vs 200/201/422`; byte-identical set in the control run |
| `DistributionReadModelApiTest` | 2 | **PRE-EXISTING** |
| `DistributionOrdersFilterApiTest` | 1 | **PRE-EXISTING** |

**Zero introduced failures.** The 403s were attributed by extracting the owning test name for
every 403 in both runs and diffing — they belong entirely to `DistributionModuleTest` in both.
Not repaired, per instruction.

### Correction to my H1 memo

My memo predicted Option B would need "no baseline change (≈25)". Measured, raw Option B gave
**61**, not 25. The extra 36 were a single mechanism: fixtures that obtained a window as a
**side effect of a read** — the behaviour the ruling prohibits. Reaching the approved baseline
required repairing that plumbing (§5). I should have measured true Option B before predicting
it; the prediction was an estimate presented with more confidence than it had earned.

### What was changed in existing tests, and what was not

**Fixture plumbing only — 8 classes, zero assertion changes.** A shared idempotent
`ensureTodayWindow()` helper (a plain `distribution_windows` insert — no business logic, no
collection, no order/assignment writes) now provides the window that a read used to conjure.
Every existing assertion in those 8 classes is byte-for-byte unchanged.

**Two tests in `DistributionWorkspaceFinalizationTest` were updated because their subject *is*
the resolver contract this ruling restated:**

- `test_without_a_governing_wave_the_anchor_falls_back_to_today` — still asserts the fallback
  resolves today's window; now **also** asserts the read created nothing. Strictly stronger.
- `test_anchor_never_resolves_a_foreign_companys_window` — company B now has its own window
  fixture, and the tenancy assertion (B never receives A's window) is unchanged and stronger.

One test was **added** there: `test_without_any_existing_window_the_read_resolves_nothing_and_creates_nothing`.

**Not done:** no certified test was rewritten to accommodate the change, no eligibility
predicate, ingestion rule, cutoff, carry-over, Group identity or Group→Trip relationship was
touched, and no Preparation Wave was created in any fixture that did not already have one.

---

## 5. Files changed

**Backend (3)** — `DistributionWindowService` (non-creating read + explicit create variant) ·
`DistributionCollectionService` (calls the create variant) · `DistributionWindowController`
(`resolution`/`resolution_reason`; `collect()` no longer creates to reconcile).

**Frontend (5)** — `types/index.ts` (`WindowResolution`, single-value `WindowResolutionReason`,
nullable `window`) · `distribution-workspace-page.tsx` (`UnresolvedWindow` + render branch) ·
`distribution-groups-panel.tsx` (capacity triplet) · `en/logistics.json` + `ar/logistics.json`
(10 net new keys).

**Tests** — 1 new class (9 tests); 8 classes gained fixture plumbing; 2 resolver-contract tests
updated; 1 test added.

**Static gates:** Pint PASS on all 12 touched PHP files · PHPStan `[OK] No errors` · ESLint
clean · `tsc -p tsconfig.app.json` **23 errors, identical to the pre-change baseline, none in
any file I touched** · i18n en/ar parity exact (2113/2113), all Arabic values in Arabic script.

---

## 6. Group capacity UI

Rendered from the server's canonical fields, never recomputed:

```
Group capacity (orders)
Current: 7     Maximum: 20     Remaining: 13
```

**Current is `demand_orders`**, not `orders_count`. The backend maintains both deliberately
(`DistributionAggregationService`: *"demand_orders below keeps its original meaning and its
original source, for the capacity maths"*) and derives `remaining_orders = capacity_orders −
demand_orders` from the former. Pairing `orders_count` with `remaining_orders` would combine two
different aggregates and let the row fail to add up.

`remaining_orders` is rendered **as received**, so the card and the row-locked write guard
cannot disagree. A `null` maximum renders "No maximum" and `null` remaining renders "Unlimited"
— never `0`, which would read as "full". Over-capacity reuses the existing
`settings.overCapacity` key rather than a duplicate. No new capacity field, no second capacity
engine, no capacity value changed.

---

## 7. Trip capacity — untouched

`distribution_trips.capacity` remains the schema default. Live: **`60,60`** on both trips,
before and after. Asserted in the focused suite. The Trip panel keeps its own "Trip capacity"
label; the Group block is captioned "Group capacity (orders)", so the two numbers are visually
distinct without either value changing. Reconciliation remains a separate future task.

---

## 8. UI behaviour

| Situation | Behaviour |
| --- | --- |
| No warehouse selected | Breadcrumbs + title + one explicit card: **"No distribution window — Select a warehouse to continue."** Tabs, KPI row and status badge withheld. A client-side check on `activeWarehouseId`, because the endpoint still serves the company-wide read it always has and cannot answer a client context question. |
| Warehouse selected, existing window, **no active wave** | **The real Distribution data renders** — groups, zones, orders, capacity. This is the ruling's core requirement: the operator must never read "no active Preparation Wave" as "there are no Distribution orders." |
| Warehouse selected, governing wave present | The wave's operational cycle selects the planning window (live: 2026-08-21, not today's 08-24). |
| No existing window at all | The explicit unresolved state — *"No active distribution window is available for this warehouse."* |

No fake empty operational board in any case. No new lifecycle state: `DistributionWindowStatus`
is untouched and `resolution` is a transport discriminator only.

---

## 9. Browser verification

> ### BROWSER NOT VERIFIED — AUTHENTICATION CONSTRAINT

The UI (Vite, `:5173`) requires an interactive login. Credentials were not entered and no
workaround was attempted. No test orders created, no payment data touched, no protected order
mutated, no wave or window fabricated.

Verified instead through the real HTTP stack against real existing data, GET only — see §2 and
§6. All four required behaviours were observed live: unresolved without a warehouse, the correct
cycle window with one, the exact capacity triplet, and **no window created by any read**.

---

## 10. Side-effect audit

| Check | Result |
| --- | --- |
| Orders / groups / assignments / trips | **19 / 3 / 13 / 2** — unchanged |
| `distribution_windows` | **4 → 4** — no window created by any read |
| Trip capacity | `60, 60` — unchanged |
| Group membership, zone membership | unchanged (asserted before/after in the focused suite) |
| Order status, payment method, payment proof, warehouse assignment | untouched |
| Preparation waves created | **0** in live data; none added to any fixture that lacked one |
| Fabricated business data | none |
| Live writes | one Sanctum token for read-only verification, **revoked**; container-only control and variant patches, **reverted** — repo and containers verified identical |

---

## 11. Remaining known issues

**Out of scope by instruction, unchanged:** Trip reconciliation · Trip capacity (60, underived)
· `released_at` demand calculation · Loading Preparation extraction · Map · Templates ·
Geography editing.

**Observed, not touched:** the Group card header still renders "Vehicle / Driver: Not assigned"
unconditionally while `GroupTripPanel` below shows the real pairing · `distribution_virtual_slots`
still carries `capacity_stops` / `capacity_weight_kg` / `capacity_volume_m3` and `storeSlot`
still accepts them (I added no axis and read none) · 25 pre-existing Distribution failures
remain, per "do not repair unrelated pre-existing failures".

---

> # IMPLEMENTED · VERIFIED · BROWSER NOT VERIFIED — AUTHENTICATION CONSTRAINT
>
> H1 = Option B applied. Reads never create a window. A Preparation Wave selects the cycle and
> does not gate Distribution. Regression restored to the exact 25-failure pre-existing baseline
> with zero introduced. **Not certified** while browser verification is unavailable.
>
> No commit. No deploy.
