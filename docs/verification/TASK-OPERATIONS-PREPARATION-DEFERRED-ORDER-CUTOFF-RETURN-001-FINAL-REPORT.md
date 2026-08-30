# TASK-OPERATIONS-PREPARATION-DEFERRED-ORDER-CUTOFF-RETURN-001 — FINAL REPORT

**Status:** IMPLEMENTED / VERIFIED — repair proven live; one positive browser step blocked by an unrelated pre-existing rule
**Date:** 2026-08-22
**Branch:** `develop` — **NOT COMMITTED**
**Scope:** eligibility/lifecycle only. **No migration. No schema change. No new permission. No new state. No Order status change.**

> **Note on the brief.** The final instruction ends mid-sentence at «If successful: PREPARATION». I
> have not guessed the intended verdict wording; §20 states the outcome plainly instead. If a
> specific verdict string was intended, tell me and I will set it.

---

## 1. Executive Summary

**The cutoff was being used as a membership lock.** Three call sites required
`WaveStatus::Collecting` before a postponed order could be returned to preparation. The scheduler
moves a wave `Collecting → Preparing` the instant `intake_closes_at` passes — so an order that had
joined the wave **before** cutoff and was then parked for a shortage became unreturnable for the
rest of the day, even with the wave still open and preparation still running. Work the operator had
deliberately deferred until stock arrived was stranded until the next cycle.

**The schema already made the correct distinction; only the guard was wrong.** No new concept was
needed:

| Concept | Where it already lives |
|---|---|
| Wave membership | the `preparation_wave_orders` row itself |
| Screen presence | `postponed_at` (NULL = on the preparation screen) |
| **Cutoff** | `preparation_waves.intake_closes_at` → `Collecting → Preparing` |
| **Wave close** | `closeWave()` → terminal status **+ `released_at` stamped** |
| Membership ended | `released_at IS NOT NULL` (drives the `active_membership` generated column) |

**The change is three guards, all in the same direction:** from *"is intake still open"* to *"has
the wave ended"*, expressed with the existing `WaveStatus::isTerminal()` predicate. New admissions
are untouched — `attachOrder()` and `attachEligibleOrders()` keep their own `Collecting`-only
guards, which is what still refuses an order that never joined before cutoff.

**Gates: 160 backend tests / 501 assertions GREEN** across the new suite plus every Preparation,
Wave Engine and Distribution-eligibility regression suite. **PHPStan clean. `php -l` clean.
Frontend untouched, so no frontend gate is run or claimed.**

**Live proof of the repair.** On the real dev stack, with wave `PREP-202608-000005` moved past
cutoff through the real **Start Preparation** button, the deferred order's `return_blocked_reason`
came back as *"This order is no longer eligible for preparation."* — an **order-level** reason. The
old code short-circuited on the wave guard **before** that check could ever run and would have
returned *"This wave has closed intake."* The wave-level block is demonstrably gone.

**Two things reported honestly, not smoothed over:** the positive browser completion could not be
finished on live data because every order in the live wave has already moved to
`ready_for_dispatch` (§14), and the browser verification left ORD-00007 postponed in a state the
application itself will not reverse — a **postpone/return asymmetry** this task discovered but did
not fix (§15, §19).

---

## 2. Existing Preparation Lifecycle

Established by reading only (PART 1), before any edit:

```
createCollectingWave()  → status Collecting, stamps starts_at / intake_closes_at / ends_at
   ↓  scheduler: hasReachedIntakeCutoff(now)          ← intake_closes_at, "and nothing else"
status Preparing        → INTAKE CLOSED, preparation continues
   ↓  scheduler: hasReachedEnd(now)                   ← ends_at
closeWave()             → status Closed + released_at stamped on every unreleased row
   ↓
next cycle's attachEligibleOrders() collects the released order automatically  ← CARRY-OVER
```

`WaveStatus`: `Draft, Collecting, Planning, ShortageBlocked, Preparing` are `isActive()`;
`Completed, Cancelled, Closed` are `isTerminal()`. `Collecting → Preparing` is a one-way
transition (`canTransitionTo`).

**How the system knows an order entered a wave:** a `preparation_wave_orders` row exists for
`(preparation_wave_id, order_id)`. `uq_preparation_wave_orders_wave_order` makes that at most one
row per wave+order, so duplicate membership is structurally impossible.

**How it knows the order left the preparation screen:** `postponed_at IS NOT NULL`. The row is
retained deliberately — `postponeOrder()`'s docblock explains that `detachOrder()` would delete it
and the collector, which runs every minute, would re-attach the order within 60 seconds.

**How membership ends:** `released_at`, written **only** by `closeWave()`. The stored generated
column `active_membership = CASE WHEN released_at IS NULL THEN 1 ELSE NULL END` feeds
`uq_prep_wave_orders_company_order_active (company_id, order_id, active_membership)` — many
released rows, exactly one active.

---

## 3. Current Cutoff Behavior

`intake_closes_at` drives one transition and one only. `PreparationWave::hasReachedIntakeCutoff()`
carries the comment *"Collecting → Preparing is driven by this and nothing else."*

Its **correct** effect is to stop new admissions, enforced in two places that this task did **not**
touch:

- `attachEligibleOrders()` — `if ($wave->status !== WaveStatus::Collecting) return 0;`
- `attachOrder()` — `if ($wave->status !== WaveStatus::Collecting) return null;`

Its **incorrect** effect, now removed, was to also freeze existing members out of returning.

---

## 4. Current Deferred Behavior

`postponeOrder()` sets `postponed_at`, emits `OrderRemovedFromWave`, decrements `orders_count`, and
dispatches the demand recomputation. It writes nothing to `orders` — no status transition, no
cancellation. It is idempotent: a second call matches nothing and reports `false`.

The deferred list is served by `WaveDemandController::postponedOrders()`, which selects rows
`WHERE postponed_at IS NOT NULL AND released_at IS NULL` and attaches `can_return` +
`return_blocked_reason` per row — this is what the Return button renders from.

---

## 5. Current Wave Membership

Unchanged by this task, and re-verified live after it (§15):

- Postponing keeps the row. Returning clears one field on **the same row**.
- No `INSERT`, no `DELETE`-then-`INSERT`, no second row — the return is an `UPDATE`, and both unique
  indexes would reject a duplicate anyway.
- `preparation_wave_id` never changes; the order stays in the wave it joined.

---

## 6. Root Cause

**Three call sites, one mistake:** each treated `Collecting` as the condition for returning an
order, when `Collecting` is the condition for *admitting* one.

| # | Location | Effect before |
|---|---|---|
| 1 | `WaveMembershipService::returnPostponedOrder()` | `if ($wave->status !== WaveStatus::Collecting) return false;` — the service silently refused |
| 2 | `WaveDemandController::returnOrderToPreparation()` | 422 *"This wave has closed intake. The order will be collected by the next preparation wave."* |
| 3 | `WaveDemandController::postponedOrders()` | `$waveCollecting` forced `can_return = false`, so the UI hid/disabled the Return button |

Site 3 mattered as much as site 1: read and write agreed, so the screen never contradicted the
endpoint — they were **consistently wrong**, which is exactly why this survived.

The service docblock stated the reasoning explicitly — *"intake belongs to a Collecting wave, so a
wave that has moved on is refused here"* — which is true of intake and false of a member that had
already been admitted.

---

## 7. Implemented Change

Three guards, one predicate, no new concept:

```php
// before                                     // after
$wave->status !== WaveStatus::Collecting      $wave->status->isTerminal()
```

`isTerminal()` is the pre-existing domain predicate for `Completed | Cancelled | Closed`.

1. **`WaveMembershipService::returnPostponedOrder()`** — guard replaced; docblock rewritten to state
   the cutoff/close distinction and to record that new admissions remain refused elsewhere.
2. **`WaveDemandController::returnOrderToPreparation()`** — guard replaced; the 422 message now says
   *"This wave has closed."* rather than *"…closed intake."*, because intake is no longer the reason.
3. **`WaveDemandController::postponedOrders()`** — `$waveCollecting` became `$waveOpen`, with a
   comment requiring it to mirror the write path exactly so the screen can never offer a button the
   endpoint refuses.

Nothing else changed. The `released_at IS NULL` predicate already present in the return query is a
second, independent layer: a released row belongs to a finished cycle and can never be un-postponed.

**Deliberately NOT changed:** `attachOrder()`, `attachEligibleOrders()`, `returnEligibility()`,
order status, Distribution eligibility, Loading eligibility, reservations, inventory, permissions.

---

## 8. Cutoff Semantics

**`CUTOFF = STOP NEW ADMISSIONS`. It is not a membership lock.** Now stated in three places so it
cannot be re-confused: the service docblock, the controller write-path comment, and the read-path
comment — plus the test suite's class docblock, which spells out both rules side by side.

An order that joined before cutoff remains a full member of the wave for the whole of the wave's
life. Cutoff changes what may *join*, never what is already *in*.

---

## 9. Wave Close Semantics

**`WAVE CLOSE = THE WAVE IS OVER`**, and it is a different event with a different mechanism:
`closeWave()` sets a terminal status **and** stamps `released_at` on every unreleased row, in one
transaction. After close:

- the return is refused — by the new guard, and independently by `released_at IS NULL`;
- the order does not go back to the old wave; it belongs to carry-over (§11).

`closeWave()` was **not modified**. Its own comment already documents that postponed members are
released uniformly because "this cycle has now ended for everyone."

---

## 10. Return Semantics

The return endpoint validates, in order — all pre-existing except the wave guard:

| Check | Mechanism |
|---|---|
| Tenant scope | `findWave($waveId, $request->user()->company_id)` → cross-tenant is 404 |
| Wave still open | **`! $wave->status->isTerminal()`** ← the change |
| Belongs to this wave, membership still active | row lookup on `(wave, order)` with `released_at IS NULL` |
| Currently deferred | `postponed_at IS NOT NULL` |
| Warehouse scope | structural — the wave carries one `warehouse_id`, and the order can only be returned through the wave that actually holds its row |
| Order still eligible | `returnEligibility()` — the wave engine's own `eligible_order_statuses` |
| Material available again | `returnEligibility()` — read from inventory, allow-negative never blocks |
| Not already active | the `UPDATE` matches nothing → `false` |
| Permission | route middleware `permission:operations.preparation.update` |

**New-wave-entry eligibility is not consulted anywhere in this path** (PART 5 satisfied). The return
is decided by membership + wave openness + the existing material/status requirements.

---

## 11. Carry-over

**The existing mechanism is used. None was created.**

`closeWave()` stamps `released_at`; the next cycle's `attachEligibleOrders()` collects the order
because its `whereNotExists` clause is qualified by `released_at IS NULL`.

This is not theoretical — the live database shows a full round trip that predates this task:

```
ORD-00009  wave …038 (PREP-…-000003)  postponed_at 2026-08-20 23:20:13
                                       released_at  2026-08-21 12:00:01   ← closeWave
ORD-00009  wave …431 (PREP-…-000004)  collected automatically
ORD-00009  wave …55f (PREP-…-000005)  collected automatically, active
```

Pinned by `test_7_a_closed_wave_releases_membership_so_the_next_wave_collects_the_order`, which also
asserts that the historical row is retained alongside the new one — history is never rewritten.

---

## 12. Permissions

**No permission was created, changed, or seeded.** Both endpoints keep
`permission:operations.preparation.update`, which the deferred screen already used.
`test_return_requires_the_preparation_update_permission` (pre-existing, unchanged) still proves a
role without it is refused with 403.

---

## 13. Tests

### 13.1 New suite — `WaveDeferredOrderCutoffReturnTest` (13 tests, 40 assertions)

Every required behaviour from PART 10:

| Required | Test |
|---|---|
| 1, 2, 4 — joined before cutoff; deferrable; still a member | `test_1_a_deferred_order_remains_a_member_of_the_same_wave` |
| 3, 5, 8 — cutoff passes; deferred member returns | `test_2_a_deferred_member_can_return_after_cutoff_while_the_wave_is_open` |
| 6 — no duplicate membership | `test_3_returning_creates_no_duplicate_membership` (same row id, same wave, one active) |
| 7 — new order still refused after cutoff | `test_4_a_new_order_still_cannot_join_the_wave_after_cutoff`, `test_5_the_collector_still_admits_nobody_after_cutoff` |
| 9 — refused after wave close | `test_6_return_is_refused_once_the_wave_has_closed` (via the real `closeWave()`) |
| 10 — existing carry-over | `test_7_a_closed_wave_releases_membership_so_the_next_wave_collects_the_order` |
| 11 — foreign tenant | `test_8_a_foreign_tenant_cannot_return_another_companys_order` (HTTP, 404) |
| 12 — wrong warehouse | `test_9_an_order_cannot_be_returned_through_another_warehouses_wave` |
| 13 — no double return | `test_10_an_already_active_order_cannot_be_returned_twice` (incl. `orders_count`) |
| screen/write agreement | `test_11_the_deferred_list_still_offers_return_after_cutoff`, `test_12_the_return_endpoint_accepts_a_deferred_member_after_cutoff` |
| order untouched | `test_13_returning_does_not_mutate_the_order` |

### 13.2 One existing test rewritten, and why

`DeficitDecisionsImpactTest::test_return_is_refused_when_the_wave_has_left_collecting` **asserted the
defect**: it set the wave to `Preparing` and required a 422. It passed, and it was wrong.

It is now `test_return_is_refused_when_the_wave_has_closed` — same 422, same "nothing mutated"
guarantee, with the boundary moved to the event that actually ends membership. A companion
`test_return_is_allowed_after_intake_cutoff_while_the_wave_is_open` pins the repaired direction. The
reasoning is recorded in the test file itself. **No assertion was weakened.**

### 13.3 Regression — 14

```
OK (160 tests, 501 assertions)
```

`WaveDeferredOrderCutoffReturnTest` · `WavePostponeOrderTest` · `DeficitDecisionsImpactTest` ·
`tests/Feature/Operations/WaveEngine` (lifecycle, idempotency, operational cycle) ·
`PreparationWaveActionsTest` · `DistributionPreparationEligibilityTest`.

Distribution eligibility was included deliberately (PART 3) to prove it did not shift.
**Full ERP regression was not run**, per the brief, and no failure required it.

---

## 14. Browser Verification

Performed on the live dev stack (`127.0.0.1:5173` → `ecos-dev-app`, fix deployed by `docker cp`,
`config:clear` + `route:clear`). The browser pane was not compositing, so the live DOM was driven
directly and results read from the rendered page and the real API responses.

| Step | Result |
|---|---|
| 1–3 · Open Preparation Workspace, select `PREP-202608-000005`, wave loads `Collecting`, 8 orders | ✅ |
| 4 · Defer an order (ORD-00007) | ✅ 200, `postponed: true` |
| 5 · It appears in the deferred list | ✅ listed, **`can_return: true`**, no blocked reason |
| 6 · Take the wave past cutoff — real **Start Preparation** button in the UI | ✅ wave → `preparing`, `ends_at` still 13:00 (open) |
| 7 · **Return still offered after cutoff** | ✅ **the wave-level block is gone** — see below |
| 8–12 · Press Return, confirm order returns, refresh, same wave, no duplicate | ⚠️ **Not completed on live data** — see below |
| New order cannot enter after cutoff | ⚠️ Not exercisable live (no eligible order exists); covered by `test_4`/`test_5` |

**Step 7 is the decisive evidence, and it is conclusive.** With the wave `Preparing`, the deferred
row returned:

```
can_return          : false
return_blocked_reason: "This order is no longer eligible for preparation."   ← ORDER-level
```

Under the old code `postponedOrders()` short-circuited on `$waveCollecting` and returned
*"This wave has closed intake. The order will be collected by the next preparation wave."*,
**never calling `returnEligibility()` at all**. Observing an order-level reason on a `Preparing`
wave is only possible if the wave guard no longer fires. The repair is live.

**Why step 8 could not be completed, stated plainly.** The block is a *different, correct,
pre-existing rule*: every order in the live wave — all eight — has already advanced to
`ready_for_dispatch`, and the wave engine's `eligible_order_statuses` is `["in_progress","confirmed"]`.
Those orders are genuinely past preparation, so `returnEligibility()` refuses them on their order
status, which this task must not widen (PART 5). There is no live order in a state where a positive
return is legitimate. The positive path is covered end-to-end by `test_12` at the HTTP level and by
eleven further tests.

---

## 15. Side Effects

Before/after comparison of every table named in PART 12:

| Table | Baseline | After | Δ |
|---|---|---|---|
| `preparation_waves` | 5 | 5 | **no new wave** |
| `preparation_wave_orders` | 27 | 27 | **no new membership, none deleted** |
| `wave_product_demand` | 8 | 8 | — |
| `prepared_products_pool` | 0 | 0 | — |
| `orders` | 15 | 15 | — |
| `order_lines` | 18 | 18 | — |
| `preparation_inventory_reservations` | 0 | 0 | **no reservation/inventory mutation** |

Duplicate-active-membership check — `GROUP BY order_id HAVING COUNT(*) > 1 WHERE released_at IS NULL`
— returns **zero rows**. ORD-00007's row is the **same row** (`added_at 2026-08-21 17:30:01`
unchanged); one field moved on it.

**Three declared changes, all from the browser verification, none from the code change:**

1. `PREP-202608-000005` status `collecting → preparing` — made through the real **Start Preparation**
   operator action, the same transition the scheduler performs at 05:00 today anyway. One-way by
   `canTransitionTo`.
2. That wave's `orders_count` 8 → 7 — the postponement's own symmetric decrement.
3. **ORD-00007 is left deferred** (`postponed_at 2026-08-21 22:47:19`) and the application will not
   reverse it (§19). Impact is nil: the order is `ready_for_dispatch`, already past preparation, and
   `closeWave()` at 13:00 today stamps `released_at` on the row, which retires the flag to history
   exactly as it did for ORD-00009 in wave 3.

No Distribution, Loading, trip, vehicle or driver record was touched.

---

## 16. Static Gates

| Gate | Result |
|---|---|
| Focused PHPUnit | ✅ **160 tests / 501 assertions GREEN** |
| PHPStan (L0, both touched files) | ✅ **`[OK] No errors`** |
| `php -l` (all touched files) | ✅ clean |
| Pint | ⚠️ **3 findings in `WaveDemandController.php`, all pre-existing and none mine** |
| Frontend gates | **Not run and not claimed — no frontend file was touched** |

**Pint provenance, proven rather than asserted.** The three findings are two `ordered_imports`
(`Illuminate\Http\Request` vs `Illuminate\Support\Carbon`; `WaveMembershipService` vs
`PreparationWave`) and one `fully_qualified_strict_types` (`\Illuminate\Support\Carbon::parse`).
`git show HEAD:…WaveDemandController.php` contains **neither** the `Carbon` nor the
`WaveMembershipService` import — both were introduced by other sessions' uncommitted work on this
shared file, as was the `Carbon::parse` call. My only import action was *removing* the now-unused
`WaveStatus` line. I deliberately did **not** auto-format the file: it is under concurrent edit, and
reformatting another session's in-flight work is the collision risk, not the fix. My own added lines
are Pint-clean.

---

## 17. STOP Conditions

**None triggered.** Each checked against the code, not assumed:

| # | Condition | Verdict |
|---|---|---|
| 1 | Return requires creating a new membership | **No** — it is an `UPDATE` clearing one field; both unique indexes forbid a second row |
| 2 | Schema cannot distinguish membership from screen presence | **No** — `released_at` vs `postponed_at`, with `active_membership` generated from the former |
| 3 | Wave close needs a new carry-over contract | **No** — `closeWave()` already stamps `released_at`; verified live on ORD-00009 |
| 4 | Requires changing Order status | **No** — `test_13` asserts the order is untouched |
| 5 | Requires changing Distribution eligibility | **No** — `DistributionPreparationEligibilityTest` green, unchanged |
| 6 | Requires changing Loading eligibility | **No** — not touched |
| 7 | Requires a new permission | **No** — `operations.preparation.update` reused |
| 8 | Requires a migration | **No** |
| 9 | Requires modifying reservations/inventory | **No** — counts unchanged; `test_13` asserts it |
| 10 | Existing lifecycle contradicts the business rule | **No** — it already models cutoff and close separately; only the guard conflated them |
| 11 | Cannot distinguish Cutoff from Wave Close safely | **No** — `intake_closes_at` vs `ends_at`/`closeWave()`, two distinct mechanisms |

---

## 18. Files Changed

| # | File | Change |
|---|---|---|
| 1 | `Modules/Operations/Preparation/Application/Services/WaveEngine/WaveMembershipService.php` | `returnPostponedOrder()` guard → `isTerminal()`; docblock states the cutoff/close distinction |
| 2 | `Modules/Operations/DemandAnalysis/Presentation/Http/Controllers/WaveDemandController.php` | write-path guard → `isTerminal()` + message; read-path `$waveCollecting` → `$waveOpen`; unused `WaveStatus` import removed |
| 3 | `tests/Feature/Operations/WaveDeferredOrderCutoffReturnTest.php` | **NEW** — 13 tests |
| 4 | `tests/Feature/Operations/DemandEngine/DeficitDecisionsImpactTest.php` | one test rewritten to the approved contract + one added (§13.2) |

**No migration. No schema change. No config change. No permission change. No frontend file.**

---

## 19. Risks / Limitations

1. **NEW FINDING — postpone/return asymmetry (not fixed, out of scope).** `postponeOrder()` applies
   **no** eligibility check; `returnOrderToPreparation()` applies `returnEligibility()`. An order can
   therefore be parked and then refused re-entry by a rule that did not apply when it was parked.
   This is not caused by this task's change, but this task's browser verification produced a live
   instance of it: **ORD-00007 is deferred and the application will not return it.** It self-heals at
   wave close (§15) and has no operational impact, since the order is already `ready_for_dispatch`.
   Worth its own decision: either postpone should apply the same eligibility test, or return should
   distinguish "no longer eligible" from "cannot be un-parked".
2. **Positive browser completion not performed** on live data — no live order is in an eligible
   status (§14). Covered by tests.
3. **The wave was advanced past cutoff manually** for verification, via the real operator action
   (§15). One-way, ~3.5 hours ahead of the scheduler.
4. **`Draft` and `Planning` waves now also permit a return.** Both are non-terminal, so `isTerminal()`
   admits them. Neither can hold a postponed order in practice (a wave collects orders only while
   `Collecting`, and postponement requires membership), so this is theoretical — but it is a wider
   set than `Collecting` alone, and it is stated rather than hidden.
5. **Pint remains red on the shared controller** for pre-existing reasons (§16).
6. **Not committed. Not deployed to production.** Deployed to `ecos-dev-app` for verification only.

---

## 20. Final Verdict

# CUTOFF NO LONGER LOCKS EXISTING WAVE MEMBERS — IMPLEMENTED / VERIFIED

An order that entered a preparation wave before cutoff and was deferred for a shortage can now be
returned to preparation for as long as that wave remains open. An order that never joined before
cutoff still cannot enter it. Wave close still ends membership, and the existing carry-over still
owns what happens next.

| Claim | Status |
|---|---|
| Deferred member returns after cutoff, wave open | ✅ **IMPLEMENTED / VERIFIED** (tests) · ✅ wave-level block proven gone on live data |
| New order refused after cutoff | ✅ **VERIFIED** — admission guards untouched |
| Membership stable, no duplicates, no new wave | ✅ **VERIFIED** in tests and in the live side-effect audit |
| Refused after wave close; carry-over owns it | ✅ **VERIFIED** via the real `closeWave()` |
| Tenant / warehouse / permission scope | ✅ **VERIFIED** |
| No Order, reservation, inventory or Distribution mutation | ✅ **VERIFIED** |
| Backend static gates | ✅ PHPStan + `php -l`; ⚠️ Pint red for proven pre-existing reasons |
| Frontend gates | **N/A — no frontend file touched** |
| Positive return completed in the browser | ⚠️ **NOT COMPLETED** — blocked by an unrelated pre-existing rule (§14) |
| Postpone/return asymmetry | 🔎 **NEW FINDING, reported, not fixed** (§19.1) |
| Full ERP regression | ❌ **NOT RUN** — not required, none failed |

**NOT COMMITTED. NO MIGRATION. NO SCHEMA CHANGE. NO NEW PERMISSION. NO NEW STATE. NO ORDER STATUS
CHANGED. NO INVENTORY OR RESERVATION MUTATION.**

**STOP — awaiting review, plus a decision on §19.1.**
