# TASK-OPERATIONS-DISTRIBUTOR-ORDERS-PART-5-LIFECYCLE-GROUP-MANAGEMENT-001 — FINAL REPORT
## Lifecycle & Group Management — Preparation Eligibility Consumption

**Status:** **PARTIAL — one subsection implemented, tested (57/57) and browser-verified; five STOPPED and reported.**
**Date:** 2026-08-21
**Branch:** `develop` — **not committed**
**Execution model:** §26 followed — audit → smallest verifiable piece → tests → browser → STOP at the first contract blocker.

---

# 0. HEADLINE

## What was implemented

**Part 3 — Preparation → Distribution eligibility.** Distribution no longer decides eligibility from `orders.status` alone.

Live, before and after, on real data:

| | Before | After |
|---|---|---|
| Eligible orders | 4 | **2** |
| Zones | 2 (`Za`, `Zn`) | **1 (`Za`)** |
| Total value | EGP 1,143.66 | **EGP 226.00** |
| Group DG-001 | 4 orders · 5 products · EGP 1,143.66 | **2 orders · 2 products · EGP 226.00** |

ORD-00007 and ORD-00009 were **already postponed in Preparation** (`postponed_at` set on their active wave memberships) and were nonetheless sitting in the distribution pool, holding Zones, and counting toward a Distribution Group a warehouse was never going to prepare. They are now correctly absent, and the `Zn` tab disappeared with them because it holds no eligible work.

## What was STOPPED

| # | Subsection | Blocker |
|---|---|---|
| **S-1** | §15 / Part 1 — which Preparation cycle governs | **Structurally ambiguous.** Reported, not guessed |
| **S-2** | Part 2 — window carry-over | Depends on S-1, and cannot record its own provenance honestly |
| **S-3** | Part 8 — remove Zone from Group | Requires a **new API route** |
| **S-4** | Part 9 — empty Group semantics | **Unrepresentable** in the existing domain model |
| **S-5** | Parts 4/6/12/13 — address reconciliation, order & product presentation | Not started; sequenced behind S-1 per §26 |

**No migration. No new RBAC. No Preparation change. No `vehicle_plan*`. No `loading_*`.**

---

# 1. AUDIT

## 1.1 The canonical Preparation eligibility contract

Eligibility has **two halves**, both owned by Preparation, both documented in `WaveMembershipService`:

| Half | Rule | Source |
|---|---|---|
| Status | `OrderStatus::fulfilmentEligible()` = `in_progress`, `confirmed` | ADR-042; already consumed by Distribution since Part 1 |
| Cycle membership | **ACTIVE** = `released_at IS NULL` — *"not 'has ever been a member'"*. **POSTPONED** = `postponed_at IS NOT NULL`, and *"a postponed row is NOT released, so it still excludes the order: postponing must not be undone by the collector 60 seconds later"* | `preparation_wave_orders` |

Distribution was consuming only the first half. **That is the defect Part 3 closes.**

`uq_prep_wave_orders_company_order_active` on `(company_id, order_id, active_membership)` guarantees **at most one active membership per order**, so "is this order postponed?" has exactly one answer — which is why the fix stays correct even when several waves run at once.

**Reading `preparation_wave_orders` from another module is established practice, not a new pattern:** `Commerce\Orders\Application\Listeners\HandlePreparationWaveClosed` and `…Completed` already do it. Nothing here writes to Preparation.

## 1.2 Live state at audit time

| Order | Wave | `postponed_at` | `released_at` | Visible in Distribution (before) |
|---|---|---|---|---|
| ORD-00002 | PREP-…-000003 | — | — | yes ✓ |
| ORD-00006 | PREP-…-000003 | — | — | yes ✓ |
| ORD-00007 | PREP-…-000003 | **2026-08-20 22:36** | — | **yes ✗ wrong** |
| ORD-00009 | PREP-…-000003 | **2026-08-20 23:20** | — | **yes ✗ wrong** |

(ORD-00002/6/7 also carry released memberships in the closed PREP-…-000002 — correctly ignored as history.)

---

# 2. WINDOW LIFECYCLE — **STOPPED (S-1)**

§15: *"If multiple concurrent Preparation Waves can exist and the current selection cannot be resolved unambiguously: STOP + REPORT. Do not choose 'latest started' merely as a guess."*

**They can, and it cannot.**

| Fact | Evidence |
|---|---|
| A wave is scoped to a **warehouse**, and optionally a brand and a channel | `preparation_waves.warehouse_id`, `.brand_id`, `.channel_id` |
| Nothing prevents several active waves at once | the only unique index is `uq_preparation_waves_company_wave_number` on `(company_id, wave_number)` — **no uniqueness on (company, warehouse, status)** |
| A Distribution Window is scoped to **company + date only** | `distribution_windows` has no `warehouse_id` |

So the wave→window relationship is **many-to-one and undefined**. Part 4 resolved it with `orderByDesc('starts_at')` — the *most recently started active wave* — which is exactly the guess §15 forbids. It happens to be unambiguous in the current data (one warehouse, one active wave) and would silently pick a winner the moment a second warehouse runs a wave.

**Not changed.** The Part 4 behaviour is left in place and is now explicitly labelled a guess rather than a rule.

### Decision required — D-P5-1

Which wave governs the Distribution cycle when several are active?

- (a) Distribution gains a warehouse dimension so a window maps to one wave — **requires a migration**;
- (b) the workspace shows a wave selector and the operator chooses;
- (c) a company-level "operational cycle" is defined independently of warehouse;
- (d) the cycle is presented as a range across all active waves.

Also confirmed unchanged, per §1: **no `ends_at`, no `timezone`, no `preparation_wave_id` was added to `distribution_windows`; no migration was written; the "boundaries frozen at creation" contract was not altered.**

---

# 3. PREPARATION ELIGIBILITY CONSUMPTION — **IMPLEMENTED**

## The one new class

`Modules\Logistics\Distribution\Domain\Services\PreparationEligibilityReader` — read-only, and the single place Distribution expresses the rule:

```php
// Out of the current cycle exactly when an ACTIVE membership is POSTPONED.
->whereNotExists(fn ($sub) => $sub
    ->from('preparation_wave_orders as pwo_elig')
    ->whereColumn('pwo_elig.order_id', 'orders.id')
    ->whereNull('pwo_elig.released_at')        // ACTIVE
    ->whereNotNull('pwo_elig.postponed_at'));  // ...and postponed
```

**An order with no membership stays eligible.** Not yet collected into a wave is *early*, not excluded — treating "no row" as ineligible would empty the pool every time a wave rolled, the opposite of the intent.

## Applied consistently

The predicate is applied to **every** aggregate, so the list and the totals can never disagree:

| Surface | Effect |
|---|---|
| `DistributionCollectionService::eligibleUnassignedOrders` | a postponed order is never collected |
| `DistributionAggregationService::orders()` | it leaves the visible pool |
| `zoneSummaries()` | it leaves the zone counts and value |
| `slotRollup()` / `slotOrderCounts()` | it leaves the Distribution Group totals |
| `productAggregation()` | it leaves the product demand |

**Hidden, not deleted.** The `distribution_window_orders` row survives, so resuming an order in Preparation restores it without a re-collection — and the audit trail of how it entered the window is preserved.

**Observed live, unprompted:** ORD-00007 was resumed in Preparation by another session during this task (`postponed_at` cleared). Distribution picked it up with no action on the distribution side — `Za` went from 2 orders / EGP 226.00 back to **3 orders / EGP 425.11**, and group DG-001 followed to 3 orders / 3 products / EGP 425.11. That is requirement **D** demonstrated on real data rather than in a fixture. ORD-00009 remains postponed and remains absent.

## Requirements A–E

| | Requirement | Status |
|---|---|---|
| A | Preparation-eligible → appears | **PASS** |
| B | Postponed → does not appear in active Distribution | **PASS** |
| C | No longer eligible → disappears | **PASS** — but only after the fix in §13; the first implementation missed it and the test caught it |
| D | Eligible again → appears again | **PASS** |
| E | Returned to Preparation → re-evaluated | **PASS** (consumed only; the Return endpoint was not touched) |

---

# 4. CARRY-OVER — **STOPPED (S-2)**

## Audit of the existing mechanism

`POST /windows/{window}/late-orders` → `ManualAssignmentService::assignLateOrder()` already does the **movement** correctly: it moves the single existing assignment row to the target window, re-resolves Zone and Slot, and records `previous_window_id`. Because an Order holds exactly one assignment, it cannot duplicate.

It is, however, **operator-initiated, one order at a time**, and it asserts the target window still accepts manual assignment. It is the right *primitive*; it is not automatic carry-over.

## Why automatic carry-over is blocked

1. **It depends on S-1.** "Orders that belong to the new operational cycle" cannot be decided while "which cycle is current" is ambiguous.
2. **It cannot describe itself.** `DistributionAssignmentSource` has exactly three cases — `auto`, `manual_late`, `manual_move` — and the enum exists *"so that 'why is this Order here?' is answerable from the row itself."* An automatic sweep is none of the three; stamping `manual_late` on a system action would make the audit answer a lie. Adding a `carried_over` case needs **no migration** (the column is a plain `varchar(32)` with no CHECK), but it changes a certified enum and the values the read model emits — a contract decision, not an implementation detail.

### Decision required — D-P5-2

(a) add a `carried_over` case to `DistributionAssignmentSource` and sweep automatically inside `collect`; (b) keep carry-over operator-initiated and surface a "stranded orders" list in the workspace; (c) defer until D-P5-1 is settled.

**Nothing was implemented.** The current behaviour is unchanged: orders stay in the previous window until an operator moves them.

---

# 5. ADDRESS RECONCILIATION — **NOT STARTED (S-5)**

Audited, not implemented. Two behaviour changes would be needed, neither requiring schema:

| Gap | Detail |
|---|---|
| `OrderCityBinder` only binds `logistics_city_id IS NULL` | by design (Part 2: *"a manual correction must survive the next sweep"*). A **changed** city text is therefore never re-bound |
| `reconcileUnzoned()` only repairs `distribution_zone_id IS NULL` | an order already zoned never re-resolves, so Zone A → Zone B cannot happen |

Both are reversible read/write-side changes to Distribution's own services. They are sequenced after S-1 because a zone move must be scoped to the correct cycle. **Decision D-P5-3:** when a bound city changes, should the binder overwrite it — and how is that distinguished from the manual correction the Part 2 contract deliberately protects?

Manual Zone override is **not** blocked: `PATCH /assignments/{assignment}/zone` already exists and is routed, so §5's "existing manual override contract" is satisfied without invention.

---

# 6. ZONE RECONCILIATION — **NOT STARTED (S-5)**

Depends on §5 above. The invariant it must preserve already holds structurally: `distribution_window_orders.distribution_zone_id` is one column with one value, so an order cannot occupy two zones.

---

# 7. UNASSIGNED — **PASS (unchanged from Part 4)**

Permanently visible, verified at zero in the browser this session: `Unassigned (0)`. Reasons remain the Parts 1–3 derived values. No zone is guessed.

---

# 8. GROUP MANAGEMENT — **STOPPED (S-3, S-4)**

## S-3 — removing a Zone from a Group

`ManualAssignmentService::detachZone()` exists and is correct (it clears the slot from the zone's orders in the same transaction). **It has no route.** Exposing it creates a new API contract, which §8 and §23 both make a STOP condition. **Not added.**

## S-4 — empty Group semantics

A `distribution_virtual_slots` row has **no status column** — Part 4 established that "draft" is a literal because exactly one state exists. There is therefore **no way to represent "this group is empty and should not be planned"**, and §9 forbids inventing a status or deleting records to hide the problem.

### Decision required — D-P5-4

(a) an empty group is legitimate and simply shows zero — no change; (b) add a status/archived flag — **requires a migration**; (c) allow deletion — requires a DELETE route (new contract).

**Nothing was implemented, and no group was deleted.**

---

# 9. GROUP INVARIANTS — verified, unchanged

| # | Invariant | Held by |
|---|---|---|
| 1 | one Zone → at most one active Group per Window | unique index on `(distribution_window_id, distribution_zone_id)` |
| 2 | one Order → at most one active Group per Window | `virtual_slot_id` is one column with one value |
| 3 | moving a Zone does not duplicate orders | `assignZoneToSlot` updates in place inside a transaction |
| 4 | orders inherit Group membership from their Zone | unchanged |
| 5 | Zone changes reflected in Group membership | **blocked with S-5** |
| 6 | no Vehicle/Driver data | the columns do not exist |

Live confirmation after the eligibility fix: DG-001 still holds **2 zones** but now reports **2 orders / 2 products / EGP 226.00** — zone membership is independent of order eligibility, which is the correct semantic.

---

# 10–12. PRESENTATION — **NOT STARTED (S-5)**

Parts 12 (address de-duplication), 13 (interactive products) and 14 (Payment Method) were not touched. Payment Method remains the approved Part 4 implementation — **`Payment Method`, not Payment Status**, from the Orders source of truth. `OrderItemsPreview` (`features/orders/components/order-items-preview.tsx`) was identified as the reusable component for Part 13; it takes `lines: OrderLine[]`, which the Distribution read model does not yet carry — an additive read-model change, no schema.

---

# 13. TESTS

`backend/tests/Feature/Logistics/DistributionPreparationEligibilityTest.php` — new, 10 tests, all through the real router and database.

| §18 item | Test |
|---|---|
| 2 — eligible order appears | `test_a_preparation_eligible_order_appears_in_distribution` |
| 2 — no membership is still eligible | `test_an_order_with_no_wave_membership_is_still_eligible` |
| 3 — postponed not collected | `test_a_postponed_order_is_not_collected` |
| 3 — postponed leaves after collection | `test_an_already_collected_order_leaves_distribution_when_postponed` (also asserts the assignment row **survives**) |
| 4 — ineligible disappears | `test_an_order_whose_status_is_no_longer_eligible_disappears` |
| 5 — newly eligible reappears | `test_a_resumed_order_returns_to_distribution` |
| — released ≠ excluded | `test_a_released_membership_does_not_exclude_an_order` |
| 16 — totals stay correct | `test_zone_and_group_totals_exclude_postponed_orders` (zone **and** group rollup **and** the list agree) |
| 25 — tenant isolation | `test_postponement_in_one_company_does_not_affect_another` |
| 22,23,24 — blast radius | `test_distribution_never_writes_to_preparation` (wave row, membership row, order row, all vehicle/loading tables) |

**§18 items 6–15 and 17–21 are NOT covered** — they belong to the subsections that were stopped (S-2, S-5). No existing assertion was weakened.

## First gated run — a REAL defect was found

```
Tests: 57, Assertions: 305, Errors: 8, Failures: 1
```

**The failure was in the implementation, not the test.**
`test_an_order_whose_status_is_no_longer_eligible_disappears` failed because `excludePostponed()` covered only the **membership** half of eligibility on the read side. Collection filters status once and then never looks again, so an Order collected while `in_progress` and later **cancelled** kept its assignment row and stayed in the pool, in its Zone, and in its Group totals. **Requirement C was not actually met** — the test caught it.

**Fix:** `PreparationEligibilityReader::constrainToEligible()` applies **both** halves — ADR-042 status *and* cycle membership — and is now used at all five read-model call sites. `excludePostponed()` remains for the collection path, which filters status itself.

The 8 errors were a fixture bug: `preparation_wave_orders` carries **no `created_at`/`updated_at`** columns, and the fixture was inserting them.

A second fixture gap surfaced on the next run (`order_confirmed_at` and `added_by` are also NOT NULL with no default). Fixed by enumerating every required column rather than reacting to one error at a time.

## Confirmed result

```
Tests: 57, Assertions: 370   —   57 / 57 (100%)
```

Part 5 (10) + Part 4 (23) + Parts 1–3 (24). **No production code was changed to make the fixture pass** — only the test.

---

# 14. BROWSER ACCEPTANCE

| §19 | Item | Verdict |
|---|---|---|
| 1 | Distributor Orders opens | **PASS** |
| 2 | Displayed cycle = current Preparation cycle | **PASS** — `PREP-202608-000003 · 20:30 / 08:00 / 15:00 · Africa/Cairo` |
| 3 | Only one operational clock | **PASS** — the window's own times are not displayed |
| 4 | Eligible orders appear | **PASS** — ORD-00002, ORD-00006 |
| 5 | Postponed/ineligible do not appear | **PASS** — ORD-00007, ORD-00009 absent; the `Zn` tab disappeared with them |
| 13 | Unassigned visible at zero | **PASS** |
| 17 | Order appears exactly once in its Zone | **PASS** — `Za (2)` |
| 19 | Group totals | **PASS** — DG-001 → 2 orders / 2 products / EGP 226.00 |
| 20 | No Vehicle/Driver controls | **PASS** — inert text only |
| 6–11 | Address/City change reconciliation | **NOT VERIFIED** — subsection stopped (S-5) |
| 14,15 | Correcting geography moves an order out of Unassigned | **NOT VERIFIED** — stopped (S-5) |
| 21,22 | Products click-through | **NOT VERIFIED** — stopped (S-5) |
| 23,24 | Address de-duplication | **NOT VERIFIED** — stopped (S-5) |
| H | Window rollover carry-over | **NOT BROWSER VERIFIED** — a natural rollover is not available on demand, and §20 forbids fabricating one |

---

# 15. SIDE EFFECTS (§17)

Verified live after the change:

| Area | Result |
|---|---|
| Orders — status | **unchanged** (4 awaiting_payment · 1 awaiting_stock · 1 confirmed · 3 in_progress) |
| Orders — lines, payment, address | **unchanged** — this task performed no order write at all |
| `preparation_waves` | **unchanged** — 3 rows, boundaries and statuses identical |
| `preparation_wave_orders` | **unchanged** — read only; the `postponed_at` values pre-date this task |
| `vehicle_plan*` (4 tables) | **0 rows** |
| `loading_*`, `vehicle_assignments`, `allocation_records`, `vehicle_inventory_items` | **0 rows** |
| Inventory, stock ledger, goods receipts, purchase orders | **unchanged** |
| `distribution_*` | **no writes** — Part 5 is read-side only |

**This subsection wrote nothing to any table.** It changed which rows are *read*.

---

# 16. REGRESSION

| Gate | Baseline | Now | Delta |
|---|---|---|---|
| Frontend nav tests | 21/21 | **21/21** | none |
| TypeScript (`tsc -p tsconfig.app.json`) | 23 pre-existing | **23** | none |
| Vite build | clean | *not re-run — no frontend file changed in Part 5* | n/a |
| Backend Parts 1–4 | 47/47 | **47/47** (inside the 57) | none |
| Backend Part 5 | — | **10/10** | +10 |
| **Combined** | 47/47 | **57/57, 370 assertions** | **no regression** |

**ESLint and the i18n audit were not run** — no frontend file was modified by this subsection.

The first run reported 8 errors + 1 failure. The failure was a real implementation defect (§13); the 8 errors were an incomplete test fixture, fixed in two passes.

---

# 17. KNOWN GAPS

| # | Gap |
|---|---|
| G-1 | Which Preparation wave governs the cycle is a guess when several are active (S-1) |
| G-2 | No automatic carry-over; orders strand in the previous window until moved by hand (S-2) |
| G-3 | A changed order address is never re-bound or re-zoned (S-5) |
| G-4 | A zone cannot be removed from a group through the API (S-3) |
| G-5 | An empty group has no representation (S-4) |
| G-6 | Products are not click-through; the address still repeats customer name and phone (S-5) |
| G-7 | The workspace remains English-only (carried from Part 4) |

---

# 18. STOP CONDITIONS ENCOUNTERED (§23)

| Condition | Triggered by |
|---|---|
| Multiple Preparation Waves make the current cycle ambiguous | **S-1** |
| Carry-over cannot be implemented safely with the existing contract | **S-2** |
| A new API contract is required for core behaviour | **S-3** (detachZone route) |
| Group empty/delete semantics cannot be established from the existing domain model | **S-4** |
| Data must be fabricated to browser-verify | **rollover acceptance** — declared NOT BROWSER VERIFIED instead |

**Not triggered:** no migration was required for what was implemented; no RBAC change; no Preparation contract change; no Vehicle Planning schema change; no Loading contract change.

---

# 19. EXPLICIT CONFIRMATION — VEHICLE PLANNING WAS NOT TOUCHED

- No file under `Modules/Operations/Loading` was modified.
- `vehicle_plans`, `vehicle_plan_slots`, `vehicle_plan_slot_orders`, `vehicle_plan_adjustment_log` were neither read nor written; all four remain at **0 rows**.
- No `vehicle_id`, `driver_id` or `distribution_window_id` was added anywhere; no migration; no UUID↔bigint conversion.
- VP-1 is unchanged.
- No Approval, Finalize, Pending-Approval state, Dispatch Gate or Loading handoff was implemented.

---

# 20. EXPLICIT CONFIRMATION — PREPARATION WAS NOT MODIFIED

- **No file under `Modules/Operations/Preparation` was modified.**
- No Preparation UI file was modified.
- No Preparation endpoint was called, and the Return-to-Preparation endpoint was not touched.
- `preparation_waves` and `preparation_wave_orders` are **read only** — the new reader class issues a single `NOT EXISTS`, and a test asserts both tables are byte-identical before and after.
- The Preparation eligibility contract was **consumed**, never redefined: the status half comes from `OrderStatus::fulfilmentEligible()` and the membership half from Preparation's own `released_at` / `postponed_at` semantics, quoted in the code comments.
- Cross-module reading of `preparation_wave_orders` is pre-existing practice (`Commerce\Orders` wave listeners).

---

# 21. FILES CHANGED

## New (2)

| File | Purpose |
|---|---|
| `backend/Modules/Logistics/Distribution/Domain/Services/PreparationEligibilityReader.php` | the single place Distribution expresses Preparation's eligibility answer |
| `backend/tests/Feature/Logistics/DistributionPreparationEligibilityTest.php` | 10 tests |

## Modified (2)

| File | Change |
|---|---|
| `…/Distribution/Domain/Services/DistributionCollectionService.php` | postponed orders are never collected |
| `…/Distribution/Domain/Services/DistributionAggregationService.php` | the predicate applied to `orders()`, `zoneSummaries()`, `slotRollup()`, `slotOrderCounts()`, `productAggregation()` |

**No frontend file was changed.** The workspace reflects the fix because it renders what the API returns.

---

# 22. STOP

Part 3 of Part 5 is implemented and browser-verified. Five subsections are stopped and reported with four decisions required (**D-P5-1 … D-P5-4**).

**Not started:** Vehicle Planning · Virtual Vehicle · Vehicle/Driver Assignment · Approval · Finalize · Dispatch · Loading.

**Not committed.** Awaiting owner review of the four decisions before the next subsection.
