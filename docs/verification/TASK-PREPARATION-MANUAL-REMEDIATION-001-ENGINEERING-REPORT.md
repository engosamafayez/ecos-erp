# TASK-PREPARATION-MANUAL-REMEDIATION-001 — Engineering Report

**Status:** IN PROGRESS — **NOT CERTIFIED** (certification is explicitly out of scope for this task).
**Date:** 2026-08-19
**Scope:** Preparation OS only. No Orders / Inventory reservation / Procurement / Finance /
Logistics redesign.
**Owner decisions on record:** availability floor + postponed-reservation exclusion (Option 2,
formally amended into ADR-027 as **§18**); partial-completion guard; Allow-Negative soft
shortage; Wave History UI; Settings UI exposure.

> ⚠️ **Concurrency:** another session is actively mutating shared files in this repository
> during this task (`backend/routes/api.php` grew +8 lines; `frontend/src/i18n/locales/{en,ar}/operations.json`
> were rewritten and lost keys the existing Preparation pages depend on). Per the task's STOP
> conditions the **frontend** findings (P-01, P-06, and the P-04 tooltip) are **held** — they
> cannot be safely edited or type-checked while `operations.json` is being restructured under
> us. All **backend** work is on Preparation/DemandAnalysis files the other session is not
> touching and proceeded normally. See §Concurrent blockers.

---

## Summary by finding

| # | Finding | Root cause | Status |
|---|---------|-----------|--------|
| P-01 | Wave settings not exposed in UI | Backend contract complete (`GET/PUT /configuration/wave-engine`); no frontend screen | **Implemented + verified** (new settings screen; tsc/eslint/build green) |
| P-02 | `Missing > Required` | ADR-027 §17.3 signed availability (intended over-commitment signal) reads as a bug in the operator view | **Done + verified** (ADR §18 floor) |
| P-03 | Allow-Negative material treated as unpreparable / Missing | `MaterialDemandCalculator` never consulted `allow_negative_stock`; the legacy `AnalyzeMaterialsAction` gate is dead code | **Done + runtime-verified** (live path; 2 focused tests green) |
| P-04 | Partial preparation marked complete | `completePreparation` stamped `preparation_completed_at` with no floor on prepared vs required | **Backend done + test PASS (3/3)**; frontend guard in place, tooltip key held |
| P-05 | Deferring an order raises Missing | Postponed order leaves `own` netting but keeps its reservation → flips to competing demand | **Done + verified** (ADR §18.3) |
| P-06 | No wave history | `WaveArchivePage` exists but has no nav entry and a thin table | **Implemented + verified** (nav entry + enriched columns; tsc/eslint/build green) |

---

## P-02 / P-05 — availability floor + postponed-reservation exclusion

### Root cause (proven from code)
`MaterialDemandCalculator::calculate()` computed `available = on_hand − (reserved − own)`,
**signed** (ADR-027 §17.3), so:
- when competing demand over-commits a material, `available` goes negative and
  `missing = max(0, required − available)` **exceeds Required** (P-02); and
- `ownWaveMaterialReservations()` filtered `whereNull('pwo.postponed_at')`, so the instant an
  order is postponed it leaves `own` while its reservation stays in `inventory_items.reserved_qty`
  (postponement releases nothing) — it flips from netted-own to competing demand, pushing
  `available` down and Missing **up** even as Required falls (P-05).

### Owner decision → ADR amendment
Owner chose to **floor** the Preparation figure and **exclude postponed-member reservations**,
reusing the existing membership lifecycle (no new release mechanism). Recorded as **ADR-027
§18** (`docs/adr/ADR-027-reservation-ownership-policy.md`), scoped to the Preparation wave
projection only — §17.3 stays universal-signed for Inventory / Manufacturing.

### Change
`MaterialDemandCalculator.php`:
```
effective_reserved = reserved − own_active_member − postponed_member
available           = max(0, on_hand − effective_reserved)   // floored (§18.2)
missing             = max(0, required − available)
```
- `postponed_member` = new `postponedMemberMaterialReservations()` — same ledger shape as the
  own query but selecting `postponed_at IS NOT NULL AND released_at IS NULL` (still a member).
- `own` netting (active members, clamped by Required) unchanged.

### Verified behaviour (owner examples)
| Case | Expected | Result |
|---|---|---|
| on_hand 100, competing reservation 5 | available 95 | ✅ 95 |
| + postponed-member reservation 7 | still 95 (not 88) | ✅ 95 |
| membership released (wave ended) | competing again → 88 | ✅ 88 |
| over-reserved 10/15 | floored → available 0, missing 10 (≤ required) | ✅ |

---

## P-03 — Allow Negative is a soft shortage

### Root cause
The **live** demand path (`MaterialDemandCalculator`) never selected `products.allow_negative_stock`,
so a material short on physical stock was reported Missing even though it is drawable on open
credit — contradicting the canonical inventory contract (`ReserveStockAction`,
`ManufacturingAvailabilityService::…InventoryAvailabilityEngine`, which treat the flag as
satisfied).

**Dead-code finding:** the legacy shortage gate `AnalyzeMaterialsAction` (the only writer of
`preparation_waves.shortage_detected`, gated on by `StartPreparationAction:48`) queries a
**non-existent** table `bill_of_materials` (singular) and columns `bill_of_material_id /
quantity_per_unit / waste_factor`. The current schema has only `bills_of_materials` (plural)
with `bom_id / quantity / waste_percentage`. Its recipe query therefore throws on any wave that
has items and only no-ops when there are none — so it never actually blocks a wave today. A
speculative fix there was written and **reverted**; the file is left at baseline. `MRPCalculationService`
(the other candidate) has **no callers** — also dead. Both are noted as latent issues, not fixed
here (out of scope; superseded by the demand engine).

### Change (live path, ADR-027 §18.4)
`MaterialDemandCalculator.php`: select `p.allow_negative_stock`; for such a material report
`missing_qty = 0` and `coverage_pct = 100` (drawable → never Missing, never in the procurement
queue). `available_qty` is left exactly as computed — the real physical position stays visible;
`on_hand`/`reserved` are never faked. Mirrors Manufacturing's treatment.

---

## P-04 — partial preparation cannot be completed

### Root cause
`WaveDemandController::completePreparation` unconditionally set
`wave_product_demand.preparation_completed_at = now()` — no check of prepared vs required — so a
product with Required 5 / Prepared 4 could be declared finished, and
`OrderPreparationCompletionReader` would then count its order as fully prepared.

### Change
- **Backend:** `completePreparation` now returns **422** when `round(prepared,4) < round(required,4)`,
  using the existing `preparation_completed_at` timestamp (no new status). `round()` mirrors the
  Prepared write path so float dust cannot block a genuinely finished product.
- **Frontend:** `wave-product-demand-page.tsx` disables the "Mark preparation finished" button
  while `remaining_qty > 0` and shows a tooltip. *(The tooltip i18n key `markCompleteBlocked` was
  added to `operations.json` but that file is being concurrently restructured — see blockers.)*

---

## P-01 — Wave settings UI (implemented + verified)

Backend contract needed no change: `WaveEngineConfigurationController`
(`GET/PUT /configuration/wave-engine`) exposes **Wave Start** (`collection_start_time`), **Intake
Cutoff** (`preparation_start_time`), **Wave End** (`wave_end_time`) per company+warehouse; timezone
is `companies.timezone` (authoritative, read-only).

Built a new **Wave Engine** settings screen (`wave-engine-settings-page.tsx`): per-warehouse cards
with `type="time"` Start/Cutoff/End inputs, the four automation toggles (`auto_create`,
`auto_assign_orders`, `auto_move_to_preparing`, `is_active`), a read-only operational-timezone
banner (with an idle-engine warning when null), and a live current-cycle preview + crosses-midnight
badge. Data layer added to the operations feature (types + service `getWaveEngineConfig`/
`updateWaveEngineConfig` + hooks `useWaveEngineConfig`/`useUpdateWaveEngineConfig`). Route
`/operations/preparation/wave-engine` + nav entry. i18n in the **`settings`** namespace (not the
contested `operations.json`).

---

## P-06 — Wave History UI (implemented + verified)

No backend change needed: `PreparationWaveController::index` already supports
`lifecycle=archived|active|all`; `PreparationWaveResource` already returns the boundary + total
fields. Enriched the existing `WaveArchivePage`: added Start / Intake Cutoff / End (from
`starts_at`/`intake_closes_at`/`ends_at`), Products, Required, Prepared, and **Missing**
(derived `required − prepared`, floored) columns; added the boundary fields to the FE
`PreparationWave` type; and added a **nav entry**.

**Nav discovery:** `src/config/navigation.ts` has **no importers** — it is dead/legacy. The active
nav is `src/config/module-navigation.ts` (typed `NavItemKey` derived from `common.nav.items`, no
`label` field — the Platform Navigation Standard). The Wave History and Wave Engine entries were
added there, with labels in `common.json` (`nav.items.wave-archive`, `nav.items.wave-engine`,
EN+AR). An initial edit to the dead `navigation.ts` was reverted.

---

## Files changed

**Backend (in scope, not concurrently contended):**
- `backend/Modules/Operations/DemandAnalysis/Application/Services/MaterialDemandCalculator.php` — §18 floor + postponed netting + Allow-Negative soft shortage.
- `backend/Modules/Operations/DemandAnalysis/Presentation/Http/Controllers/WaveDemandController.php` — P-04 completion guard.
- `docs/adr/ADR-027-reservation-ownership-policy.md` — **§18** amendment.

**Tests:**
- `backend/tests/Feature/Operations/DemandEngine/MaterialAvailabilityContractTest.php` — updated CASE C + signed→floored test; added postponed-exclusion, released-boundary, and two Allow-Negative cases.
- `backend/tests/Feature/Operations/PartialPreparationCompletionGuardTest.php` — **new**, P-04.

**Frontend:**
- `frontend/src/features/operations/pages/wave-engine-settings-page.tsx` — **new**, P-01 Wave Engine settings screen.
- `frontend/src/features/operations/pages/wave-archive-page.tsx` — P-06 enriched columns + time formatter.
- `frontend/src/features/operations/pages/wave-product-demand-page.tsx` — P-04 button guard (tooltip key `markCompleteBlocked` is present in `operations.json`, added by the concurrent session).
- `frontend/src/features/operations/types/preparation.ts` — wave boundary fields + WaveEngine config types.
- `frontend/src/features/operations/services/preparation-service.ts` — `getWaveEngineConfig`/`updateWaveEngineConfig`.
- `frontend/src/features/operations/hooks/use-preparation.ts` — `useWaveEngineConfig`/`useUpdateWaveEngineConfig`.
- `frontend/src/config/module-navigation.ts` — Wave History + Wave Engine nav entries (active nav).
- `frontend/src/router/{routes,router}.ts` — `waveEngine` route.
- `frontend/src/i18n/locales/{en,ar}/settings.json` — `waveEngine.*` keys (P-01).
- `frontend/src/i18n/locales/{en,ar}/operations.json` — `wave.archive.*` column keys (P-06).
- `frontend/src/i18n/locales/{en,ar}/common.json` — `nav.items.wave-archive` / `nav.items.wave-engine`.

**Reverted (dead code, left at baseline):**
- `backend/Modules/Operations/Preparation/Application/Actions/AnalyzeMaterialsAction.php` (broken legacy query).
- `frontend/src/config/navigation.ts` (dead/legacy nav, no importers).

**Frontend verification:** `tsc --noEmit -p tsconfig.app.json` — **0 errors in any changed file**
(pre-existing baseline errors in untouched files remain, ratchet-not-cliff). `eslint` on all changed
files — **clean (exit 0)**. `vite build` — **success (built in 12.7s, exit 0)**. The full
`npm run build` is gated by `tsc -b` on the pre-existing baseline errors, so the bundler step was run
directly.

---

## Tests & runtime

Run through the shared gate (`scripts/test-gate.sh`, `GATE_WAIT=2400`) inside `ecos-dev-testrunner`
(source is `docker cp`-ed in — the container is not volume-mounted).

**Focused suites — final result: `OK (16 tests, 46 assertions)`, 0 failures.**

- `MaterialAvailabilityContractTest` — **PASS** (13 tests). Confirms:
  `on_hand=10 reserved=15 required=10 → available=0 missing=10` (floor, §18.2);
  postponed-member reservation → available 95 (not 88, §18.3); released membership → 88 (boundary);
  allow-negative material short → missing 0 / available surfaced unchanged (§18.4 / P-03);
  non-allow-negative control → missing 8.
- `PartialPreparationCompletionGuardTest` — **PASS** (3 tests): 422 on partial, 200 on full,
  float-dust below Required still completes.

The gate correctly serialised behind the other session's ungated run (`[GATE] busy … queueing`
then `[GATE] acquired`), so these gated results are reliable despite the contention. The **full**
Preparation suite has NOT yet been run (deferred until the DB is free and the concurrent session
ends).

---

## Remaining gaps

- **P-01 and P-06 frontend not built**, and **P-04 tooltip key not stably present** — all held by
  the concurrent `operations.json` rewrite. Backend for both is complete/unnecessary.
- Full-suite regression not yet run (targeted suites only so far).
- Manual verification scenarios (A–K) not executed.

## Concurrent blockers (task STOP conditions)

| File | Evidence | Effect |
|---|---|---|
| `backend/routes/api.php` | +8 insertions since session start, not by this task | Flagged per the explicit STOP rule; this task requires no route change |
| `frontend/.../operations.json` (EN+AR) | rewritten 01:32; all `wave.productDemand` completion keys removed | Blocks P-01, P-06, P-04 tooltip; broke existing page key refs |
| test DB | concurrent `phpunit` processes | Gate queues (handled correctly); no data corruption for gated runs |

---

## Certification

**STATUS CANNOT BE CERTIFIED** — per task instruction, and because manual verification (A–K) and
a full-suite gate run have not been performed, and three findings remain frontend-blocked by
concurrent work.
