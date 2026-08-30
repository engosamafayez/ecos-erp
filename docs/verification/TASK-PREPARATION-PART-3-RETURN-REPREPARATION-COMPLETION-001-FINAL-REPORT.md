# TASK-PREPARATION-PART-3-RETURN-REPREPARATION-COMPLETION-001 — Final Report

**Date:** 2026-08-21
**Environment:** DEV only (`ecos-dev-*`, database `ecos_dev`)
**Scope:** Preparation only. Distribution, Distributor Orders, Zones, Planning, Groups,
Vehicle Planning and Loading were **not touched**.
**Certification:** NOT CLAIMED. **No commit.**

---

## Status summary

| Area | Result |
|---|---|
| §1 Audit | **PASS** |
| §2 Postponed Orders | **PASS** (automated) |
| §3 Return to Preparation | **PASS** (automated) |
| §5 Re-preparation | **PASS** (automated) |
| §6 Product completion consistency | **PASS** (automated) |
| §7 Wave Completion fix | **PASS** (automated) · **NOT BROWSER VERIFIED** |
| §11 Tests A–S | **PASS** — 30 tests, 147 assertions |
| §14 Static gates | **PASS** |
| §12 Browser acceptance | **NOT BROWSER VERIFIED** (data cannot show it without a business write) |

---

## 1. Audit findings

### The Wave Completion defect is a stale cache, not a display bug

Two independent completion stacks exist, and the wave header reads a stale snapshot of the
wrong one:

| Layer | Source | Behaviour |
|---|---|---|
| Product row | `wave_product_demand` (`required_qty − prepared_qty`) | derived at read time — **live and correct** |
| Wave header | `preparation_waves.total_units_prepared / total_units_required` | **denormalised snapshot** |

`preparation_waves` has **no `completion_pct` column at all**; `PreparationWaveResource`
computes `prepared / required` from the snapshot columns.

`WaveDemandController::updatePrepared()` is a **leaf write** — it updates
`wave_product_demand` and returns. No event, no observer, no rebuild. The only code that
refreshes the wave snapshot from the canonical table is
`DemandProjectionBuilder::syncWaveHeader()`, which is private and reachable solely from a
demand-projection rebuild (goods receipt, membership change, `DemandRefreshRequested`).
**Recording preparation was not one of those triggers.**

### Evidence — three independent confirmations

1. **Timestamps.** Wave `PREP-202608-000003`: `wave_kpis.last_calculated_at = 23:20:19`,
   while the operator completed the product at `23:31:09` — eleven minutes later, never
   recomputed.
2. **The required/prepared asymmetry** — the decisive proof:

   | Wave | `total_units_required` vs product sum | `total_units_prepared` vs product sum |
   |---|---|---|
   | 000002 | 3.0 vs 3.0 — **in sync** | 0.0 vs 2.0 — **stale** |
   | 000003 | 2.0 vs 2.0 — **in sync** | 0.0 vs 2.0 — **stale** |

   Both columns are written by the same `syncWaveHeader()`. Membership changes keep Required
   current; preparation writes never fired it, so only Prepared froze. Nothing else produces
   that asymmetry.
3. **Live API.** `GET /waves/{id}` returned `completion_pct: 0, total_units_prepared: 0`
   while `GET .../product-demand` returned `required 2, prepared 2, remaining 0, pct 100`.

### Canonical source

**`wave_product_demand.prepared_qty / required_qty`** — the operator-owned figure.

This is not a new invention: `WaveKpiCalculator::calculate()` already computes exactly
`SUM(prepared) / SUM(required)` and already exports `_total_units_required` /
`_total_units_prepared` *"for wave header sync"*. `preparation_waves.total_units_prepared`
and `wave_kpis.completion_pct` are both derived caches of it.

### No contract conflict

No ADR or certified contract defines wave completion differently, no second lifecycle was
introduced, and no parallel eligibility engine was created. §8 was respected in full: the
Deficit Decisions contract from the previous task is untouched — Missing stays truthful,
Expected Incoming stays independent, Uncovered still drives the queue, and allow-negative
shortages still reach Deficit Decisions.

---

## 2. Return workflow

Uses the endpoint and service delivered in Part 2 — **no second endpoint, no new permission**
(`operations.preparation.update`), reusing `OrderAddedToWave`.

- **Collecting wave** → `postponed_at` cleared on the RETAINED membership row (UPDATE, never
  an insert), demand recomputed through the existing dispatcher.
- **Preparing / Frozen / Ended** → refused. The existing carry-over owns that case
  (`closeWave()` releases membership, the next cycle's `attachEligibleOrders()` collects it).
  No wave is forced open, no cutoff edited, no new carry-over invented.

Order status, order lines, inventory, reservations, ledger, GR, PO and Expected Incoming are
all untouched by a return.

---

## 3. Postponed Orders

Postponed orders remain **excluded from active demand**: `ProductDemandCalculator`,
`MaterialDemandCalculator` and `ShortageImpactAttributor` all filter
`whereNull('pwo.postponed_at')`, so a postponed order contributes nothing to demand, Missing
or Uncovered, and cannot create duplicate demand.

They are surfaced in a **separate `postponed_orders` list** — deliberately not merged into
the decision queue, so the uncovered calculation is unchanged by one figure. Each row carries
Order Number, Customer, Order Value, Payment Method, status, `can_return` and
`return_blocked_reason`.

**Eligibility is decided once, in the backend**, by `returnEligibility()` — used by both the
read (so the UI only offers a workable button) and the write (so the refusal is
authoritative). React duplicates none of it.

---

## 4. Re-preparation behaviour

No "returned order" special path exists — searched and confirmed. A returned order re-enters
through the normal flow: membership becomes active again (`postponed_at IS NULL`,
`released_at IS NULL`), the existing demand dispatcher recomputes required quantities, and
the order becomes collectable by the standard collector predicate.

---

## 5. Product completion

`Required / Prepared / Remaining / Progress` are derived from `wave_product_demand` at read
time and were already internally consistent. Completion is an explicit declaration
(`preparation_completed_at`), never inferred from `prepared >= required` — unchanged by this
task.

---

## 6. Wave Completion fix

**Backend.** Added `DemandProjectionBuilder::refreshWaveTotals()` — a thin public wrapper
over the existing private `refreshKpis()`, which recomputes `wave_kpis` and calls
`syncWaveHeader()`. Called from all three preparation writes: `updatePrepared`,
`completePreparation`, `uncompletePreparation`.

Deliberately **not** `buildFull()` / `buildForProducts()`: preparation progress changes no
demand, no material requirement and no readiness. This recomputes the aggregate only, reusing
the same calculator the rebuild uses — so there remains exactly **one** definition of wave
completion. The percentage is not "adjusted" anywhere; the canonical backend figure is what
was wrong, and that is what was fixed.

**Frontend (a second, compounding gap found in the audit).** `useUpdateProductPrepared`,
`useCompleteProductPreparation` and `useUncompleteProductPreparation` invalidated only the
`wave-demand` key and never `preparation-waves` — so even a corrected backend value would not
have been refetched. All three now invalidate the wave detail and list as well.

**Aggregation is quantity-weighted**, never an average of percentages — asserted explicitly
(see test R).

---

## 7. Tests — `tests/Feature/Operations/DemandEngine/DeficitDecisionsImpactTest.php`

**OK (30 tests, 147 assertions).** No existing test was removed and no assertion weakened.

| § | Case | Result |
|---|---|---|
| A | Postponed order excluded from active demand | PASS |
| B | Postponed order contributes nothing to Missing/Uncovered | PASS |
| C | Return succeeds on a Collecting wave when eligible | PASS |
| D | Return clears `postponed_at` | PASS |
| E | No duplicate membership (same row, UPDATE) | PASS |
| F | Returned order collectable again | PASS |
| G | Demand restored | PASS |
| H | Order status unchanged | PASS |
| I | Order lines unchanged | PASS |
| J | Return refused while material unavailable | PASS |
| K | Return refused when wave has left Collecting | PASS |
| L | Return refused for a non-postponed order | PASS |
| M | Permission guard rejects | PASS |
| N | No Inventory/Ledger/GR/PO/Expected-Incoming side effects | PASS |
| O | Required / Prepared / Remaining consistency | PASS |
| P | 100% completion produces Completed state | PASS |
| Q | Wave Completion matches authoritative prepared quantities | PASS |
| R | Multiple products aggregate by QUANTITY (9+1 units, small prepared → **10%**, not the 50% an average of percentages would give) | PASS |
| S | Completion persists across reads | PASS |

Plus the 6 payment-method cases and 11 deficit-impact cases from the previous parts.

---

## 8. Browser acceptance — **NOT BROWSER VERIFIED**

The corrected Wave Completion **cannot be observed on current data without a business-data
write**, and none was performed.

The fix recomputes the aggregate on the *next* preparation write. Every existing wave is
stale, so nothing displays the corrected value today:

| Wave | Product truth | Wave header |
|---|---|---|
| 000001 | no preparation at all (0/0) | 0% — trivially consistent, proves nothing |
| 000002 | prepared 2 of 3 | 0% — stale |
| 000003 | prepared 2 of 2 (100%) | 0% — stale |

Demonstrating it would have required re-recording `prepared_qty = 2` on wave 000003 — the
same value already stored, so no business figure would change, but still a real write. Per
owner instruction this was **not** performed, and no browser condition was manufactured.

Browser-verified in earlier rounds and unchanged here: Payment Method column
(**Cash on Delivery**), Deficit Decisions queue, Postponed Orders surfacing, and the
Preparation Workspace shell.

---

## 9. Data side effects

**None.** No business data was written during this task. Verified unchanged: `orders`,
`order_lines`, `inventory_items`, `stock_ledger_entries`, `goods_receipts`,
`purchase_order_lines`, `wave_expected_incoming`, and wave memberships.

Automated tests additionally assert that recording preparation causes no inventory, ledger,
goods-receipt, purchase-order or order-status change.

---

## 10. Regression results

Baseline for the `DemandEngine` suite before this task: **14 errors, 3 failures**, all
pre-existing:

- 14 × `MaterialDemandCalculatorTest` — `ArgumentCountError: Too few arguments to
  MaterialDemandCalculator::__construct()`. Another session added a constructor dependency
  without updating the test. Not touched by this task.
- 3 × `ProductDemandCalculatorTest` (×2, `prepared_qty` reads 0.0) and
  `FinishedGoodOwnReservationDemandTest` (×1).

These are **not** hidden and **not** claimed as fixed.

**Full sweep result (`tests/Feature/Operations/DemandEngine/`): 112 tests, 394 assertions,
14 errors, 3 failures.**

| Measurement point | Tests | Errors | Failures |
|---|---|---|---|
| Before the Deficit Impact task | 79 | 14 | 3 |
| After the Deficit Impact task | 93 | 14 | 3 |
| **After this task** | **112** | **14** | **3** |

Errors and failures are **identical at all three points** while the test count grew by 33.
**Zero regressions.** A grep for `DeficitDecisionsImpactTest`, `ShortageImpactAttributor` and
`refreshWaveTotals` across the entire failure output returns **0 matches**.

**Static gates:** ESLint **0 errors** · TypeScript **23 total = unchanged baseline, 0 in
changed files** · i18n **0 missing keys**, 0 invalid JSON · Vite build **green**.

---

## 11. Known limitations

1. **Existing stale waves do not self-heal.** The fix corrects the aggregate on the next
   preparation write (or demand rebuild). Waves 000002 and 000003 keep `total_units_prepared
   = 0` until then. No backfill was performed — that would be a business-data write.
2. **Wave Completion is not browser-verified** (§8).
3. **Return success path is not browser-verified** (carried from Part 2) — the queue emptied
   legitimately and restoring a candidate would require a business-data write.

---

## 12. STOP conditions / out-of-scope findings

No STOP condition was triggered: no contract conflict, no lifecycle conflict, no migration,
no new RBAC permission, no Distribution or Loading change, and no data fabrication.

**Logged, deliberately NOT fixed:**

1. **`CompleteWaveAction:132` — latent defect.** On manual wave completion it overwrites
   `total_units_prepared` with `SUM(preparation_wave_items.quantity_prepared)`, a *different,
   legacy* table that engine-created waves never populate. That would clobber the corrected
   value back toward 0. Owner directed this be left for a separate task.
2. **Authorization vocabulary split.** The same surfaces are gated twice under two different
   permission names — route middleware `operations.preparation.update` vs policy
   `preparation.wave.update` / `preparation.wave.view`. This produced three separate fixture
   403s during this task. Only **existing** permissions were granted in fixtures; none was
   created. Owner directed this be left out of scope.
3. **Postponement records no actor.** `preparation_wave_orders` has `added_by` but no
   `postponed_by`, `audit_logs` holds nothing for preparation entities, and postpone writes no
   order event. The actor of any postponement is therefore unknowable — which is why the
   provenance of ORD-00009's postponement could not be established.
4. **`DemandProjectionBuilder` incremental under-report** (carried) — the incremental path can
   under-report `required_qty` for materials shared across products outside the slice.

---

## Certification

**NOT CLAIMED.** Automated verification is complete and green (30 tests, 147 assertions), and
all static gates pass. The Wave Completion fix and the Part 2 Return success path remain
**NOT BROWSER VERIFIED**, because neither can be observed without a business-data write that
the owner has declined. No commit was made.
