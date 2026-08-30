# TASK-PREPARATION-WORKSPACE-MOBILE-UX-ACTIVE-WAVE-001 — Engineering Report

**Preparation Workspace — Active Wave Auto-Selection + Mobile Operational UX + Completion Consistency**
Date: 2026-08-29 · Branch: `develop` · Status: **COMPLETE** (verification narrow, per policy)

---

## 1. Executive Summary

An in-place enhancement of the existing Preparation Workspace — no second Preparation flow, no
new Wave authority, no change to the Wave lifecycle, reservation, recipe/BOM, MRP, eligibility or
shortage contracts. Four connected goals delivered:

1. **Auto-open the current active wave** — a new read-only `GET /preparation/waves/current`
   resolves the single canonical active wave; the workspace layout opens it automatically and
   re-resolves on refresh/re-entry from server state (never a stale wave id).
2. **Default to the Active tab** — Today's Preparation now opens directly on Active (product
   demand); the former dashboard is preserved as an explicit **Overview** tab.
3. **Mobile operational UX** — the four operational tabs (Active, Missing Materials, Deficit
   Decisions, Wave Orders) now render compact operational **cards on mobile** via the data grid's
   existing `renderMobileCard`; the desktop tables are untouched.
4. **Completion consistency** — the wave header now reads the **same live, quantity-weighted
   completion** the product rows derive from, so the header can no longer show 0% while products
   read complete.

The completion formula was already quantity-weighted in the canonical `WaveKpiCalculator`
(`SUM(prepared) / SUM(required)`); the defect was that the header consumed a **stored** snapshot
that could go stale, while the rows derived live. The fix makes the KPI endpoint compute live and
points the header at it — one canonical source.

**No parallel authority added. No DEV/live business data mutated. Nothing committed, pushed, or
deployed.**

---

## 2. Preparation Workspace Architecture Trace

```
PreparationWorkspaceLayout (top-level tabs: Today | Archive | Settings)
  └─ /wave-workspace  → WaveWorkspaceLayout (header + operational tab bar + <Outlet/>)
       ├─ index         → (redirect) → Active
       ├─ /products     → WaveProductDemandPage        (Active)
       ├─ /missing      → WaveMissingMaterialsPage      (Missing Materials)
       ├─ /wave-orders  → WaveOrdersPage                (Wave Orders)
       ├─ /deficit-decisions → DeficitDecisionsPage     (Deficit Decisions)
       ├─ /overview     → FulfillmentWaveWorkspacePage  (Overview — the former default)
       └─ /materials, /settings

Data: wave_id (URL) → usePreparationWave (GET /waves/{id})  → header identity
                    → useWaveKpis        (GET /waves/{id}/kpis) → header completion/missing (LIVE)
                    → useWaveProductDemand / MaterialDemand / MissingMaterials / DeficitDecisions / Orders
Money/qty authority: WaveKpiCalculator + wave_product_demand / wave_material_demand (canonical read models)
```

Wave selection was previously a manual `?wave_id=` written by `WavePicker`. It is now resolved
automatically by the layout from the canonical current-wave read.

---

## 3. Current Wave Authority

The active-membership contract is unchanged (`released_at IS NULL`; wave end governed by `ends_at`;
`WaveStatus::activeValues()` = non-terminal). There was **no** canonical "current wave" read — the
list endpoint (`GET /waves?lifecycle=active`) returns the active set, and the operator picked one.

New read-only **`GET /preparation/waves/current`** (`PreparationWaveController::current`) resolves it
canonically, reusing `WaveStatus::activeValues()` and the same `-planning_date` ordering the list
uses. It returns the three operational cases explicitly:

```json
{ "active_count": <n>, "wave": <PreparationWaveResource|null>, "waves": [{id,wave_number,planning_date,status}] }
```

Tenant-scoped to the acting company; opens/closes nothing; introduces no new status.

---

## 4. Previous Manual Selection Root Cause

The workspace never resolved a wave itself: every tab read `useSelectedWaveId()` (the `?wave_id=`
URL param), and that param was only ever set by the operator via `WavePicker`. With no id, each page
fell back to a "Select a wave…" empty state. There was no server round-trip to discover the current
operational wave, so a fresh visit, a refresh, or returning from another module always required a
manual pick.

---

## 5. Active Wave Auto-Resolution

`WaveWorkspaceLayout` now calls `useCurrentWave()` and reconciles it with the URL:

- The URL `wave_id` is honoured **only while it is genuinely among the active waves**. A stale id
  (its wave ended) is ignored, so Today's Preparation resolves the new current wave (§4).
- When exactly one wave is active and the URL has no valid active id, the layout writes that id to
  the URL (`replace`) — the single wave opens automatically, and every tab (which reads `?wave_id=`)
  works on refresh/re-entry.
- `useCurrentWave` has a short `staleTime` and refetches on window focus, so returning to the tab
  re-resolves from server state rather than trusting cached React state.

---

## 6. Zero Active Wave State

When `active_count === 0`, the layout renders an explicit **"No Active Preparation Wave"** state
(title + hint) instead of a meaningless wave selector, and the operational tab bar is hidden. No
create/open-wave workflow is invented — a new wave opens on the next operational cycle per the
existing engine.

---

## 7. Multiple Active Wave Invariant

When `active_count > 1`, the backend returns `wave: null` (it never silently picks one) plus the full
`waves[]` list. The layout renders a **safe "Multiple active waves"** state listing the conflicting
wave numbers as choices; selecting one sets `wave_id`. Nothing is auto-resolved and no business
corruption is "fixed" in React — the conflict is surfaced with its identifiers.

(Note: in a multi-warehouse company more than one wave can legitimately be active; this state lets
the operator choose rather than erroring, while still never silently picking.)

---

## 8. Archive Preservation

Historical/terminal waves remain fully browsable in the **Archive** tab (`WaveArchivePage`,
`lifecycle=archived`), unchanged. The auto-resolution applies only to Today's Preparation
(`WaveWorkspaceLayout`); it does not touch Archive.

---

## 9. Default Active Tab

The `/wave-workspace` index route now redirects to the Active tab (`/wave-workspace/products`),
preserving any `wave_id` on the URL, so Today's Preparation opens directly on Active (§8). Explicit
navigation to Missing Materials, Wave Orders, Deficit Decisions, Overview, Materials and Settings is
unchanged. The former default landing (the dashboard) is preserved as an explicit **Overview** tab —
nothing was lost.

---

## 10. Desktop Table Architecture

Every operational tab renders `UniversalDataGrid`, which already carries a responsive split:
`hidden lg:block` desktop table vs `block lg:hidden` mobile container. Desktop behaviour (columns,
sort, column-visibility, print, CSV) is entirely preserved — no desktop table was replaced.

---

## 11. Mobile Rendering Root Cause

`UniversalDataGrid` renders the mobile container only when a `renderMobileCard` prop is supplied; with
none, the `block lg:hidden` branch renders **nothing**. The four Preparation pages never passed
`renderMobileCard`, so on a narrow screen the desktop table was hidden and no card replaced it — the
operational data was effectively unusable on mobile. The fix supplies `renderMobileCard` per page;
the grid's own breakpoint keeps the desktop table for `lg+`.

---

## 12. Mobile Active Product UX

`ProductMobileCard` renders each product as an operational card: name + SKU, Required / Prepared /
Remaining, a completion bar, and the readiness badge. The inline **Prepared editor** and the primary
**Complete / Undo** control are kept in reach on the card — not behind an overflow menu (§11). To
avoid divergence (§18), the completion control was extracted into a shared `CompletionAction`
component used by **both** the desktop cell and the card, so both apply the identical P-04 /
waiting-material guards; the backend remains authoritative. Tapping the card opens the existing
related-orders drill-down.

---

## 13. Missing Materials Mobile UX

`MaterialMobileCard` renders material name + SKU, Required / Available / Missing / Uncovered, a
Covered/Uncovered status pill, and the **same editable Expected Incoming control** (`ExpectedIncomingCell`)
and the same related-orders drill as the desktop row. Expected Incoming remains planning-only.

---

## 14. Deficit Decisions Mobile UX

The affected-orders grid renders a decision-oriented card: **PROBLEM** (affected products) →
**IMPACT** (shortage quantity) → **REQUIRED DECISION** (Decide / "Continued" badge). The Decide
action opens the **existing decision dialog**, which uses the canonical
`continue-despite-shortage` / `postpone` endpoints — no second postpone/shortage authority. The
uncovered-material summary and postponed-orders (Return) surfaces are unchanged.

---

## 15. Wave Orders Mobile UX

`WaveOrdersPage` now renders an order card: order number, customer, delivery zone, the product
summary (reusing `OrderProducts` with its "+N more" popover), and the **same Postpone action** (same
confirmation dialog, same endpoint). Order eligibility and wave membership rules are untouched.

---

## 16. Search / Filter / Sort

The existing controls already render on mobile and were preserved with their canonical semantics:
Active's completion tabs (All / Not Started / In Progress / Complete) + search; Wave Orders' zone
tabs + search; Deficit's material-filter chips. These are page-local filters over the already
wave-scoped dataset — ownership was not changed. No backend filter semantics were invented.

---

## 17. Secondary Toolbar Handling

`SmartToolbar` already renders secondary actions compactly and supports a per-action `hideOnMobile`
flag. Print and CSV export are desktop utilities and column-visibility applies only to the desktop
table (mobile shows cards), so on the phone toolbar Download/Print are marked `hideOnMobile` and the
Columns menu is wrapped `hidden lg:flex`. The desktop versions are untouched, and no operation that
is primary to preparation (prepare / complete / postpone / decide) is hidden — those live on each
card. The shared `SmartToolbar` component itself was not modified (kept the blast radius narrow).

---

## 18. Wave Completion Existing Calculation

Completion was **already quantity-weighted** and correct in the canonical source:
`WaveKpiCalculator::calculate()` computes `total_prepared = SUM(wave_product_demand.prepared_qty)`,
`total_required = SUM(required_qty)`, `completion_pct = total_prepared / total_required × 100`.
`PreparationWaveResource` and `PreparationWave::completionPct()` apply the same formula over the wave's
**stored** `total_units_*`. `DemandProjectionBuilder` syncs those stored totals on demand-generation
(`buildFull`) and on every Prepared/complete/uncomplete write (`refreshWaveTotals`). It was never a
naive average of per-product percentages.

---

## 19. Wave Completion Fix

The defect was **staleness**, not the formula: the header consumed the wave's **stored**
`completion_pct` (`usePreparationWave`), which is only re-synced on the demand-generation and
Prepared-write paths — so a change on another path (or a wave whose header snapshot predated its
demand) could leave the header at 0% while the product rows, derived live from `wave_product_demand`,
read complete.

`WaveDemandController::kpis()` now computes the KPI **live** at read time via the canonical
`WaveKpiCalculator` (instead of reading the stored `wave_kpis` row), and additionally returns
`total_units_required` / `total_units_prepared`. The header (`WaveWorkspaceLayout`) now reads
`completion_pct` from `useWaveKpis()` (live) rather than the wave's stored value. The stored totals
are still synced for the wave list and Archive; the workspace read simply never trusts a snapshot.

Verified: 10/10 + 0/5 → **66.67%** (quantity-weighted, not the 50% a naive average would give);
nothing prepared → **0%**; all prepared → **100%**.

---

## 20. Single Source of Completion Truth

Header completion, the `FulfillmentWaveWorkspacePage` (Overview) completion bar, and the per-product
rows now **all derive from `wave_product_demand`** at read time (header/Overview via the live
`kpis` endpoint; rows via `product-demand`). They cannot materially disagree, because there is one
computation over one source. No competing React calculation was added (§21).

---

## 21. Loading / Error / Empty Safety

`WaveWorkspaceLayout` distinguishes: **Loading** (resolving the current wave), **Error** (explicit
"couldn't load the current wave" + Retry — never a false empty), **Empty** ("No Active Preparation
Wave"), and **Loaded** (tabs + content). The per-tab pages keep their own Loading / Empty states.
A failed current-wave read renders the error state, and the mutation-bearing tabs are not shown until
a valid wave is resolved, so no mutation action is offered against a failed read.

---

## 22. RBAC / Mutation Guards

No RBAC catalogue change. The new `current` read reuses the wave `viewAny` authorization + the
`modules.preparation_os` feature guard, exactly like the existing list/show. `kpis` is unchanged in
gating. Every mutation (Prepared, complete/uncomplete, continue-despite-shortage, postpone, return,
expected-incoming) is untouched and remains behind its existing `operations.preparation.update` /
`purchasing.expected_incoming.update` permission and its server-side guards; the mobile cards reuse
those same canonical endpoints and guards.

---

## 23. Backend Changes

- `PreparationWaveController::current()` — new read-only current-active-wave resolver + route
  `GET /preparation/waves/current` (registered **before** `waves/{waveId}` so "current" is not
  captured as an id).
- `WaveDemandController::kpis()` — computes the KPI live via the canonical `WaveKpiCalculator`
  (single source of completion truth); response additionally carries `total_units_required` /
  `total_units_prepared`. No stored-snapshot dependency for the numbers.

No new tables, migrations, models, events, or write paths. No change to `WaveKpiCalculator`,
`DemandProjectionBuilder`, the wave lifecycle, reservation, recipe/BOM, MRP, eligibility or shortage
authorities.

---

## 24. Frontend Changes

- `types/preparation.ts` — `CurrentWaveResponse`; `WaveKpiReadModel` extended with
  `total_units_required` / `total_units_prepared`.
- `services/preparation-service.ts` + `hooks/use-preparation.ts` — `getCurrentWave()` / `useCurrentWave()`.
- `components/wave-workspace-layout.tsx` — current-wave auto-resolution; No-Active / Multiple /
  Loading / Error states; header completion sourced from the live KPI; Overview tab entry.
- `pages/wave-product-demand-page.tsx` — `ProductMobileCard` + shared `CompletionAction`;
  desktop-only Print/CSV/Columns.
- `pages/wave-missing-materials-page.tsx` — `MaterialMobileCard`; desktop-only Print/CSV.
- `pages/deficit-decisions-page.tsx` — decision-oriented mobile card.
- `pages/wave-orders-page.tsx` — order mobile card; desktop-only Columns.
- `router/router.ts` — index → Active redirect (preserving `wave_id`); Overview route.
- `router/routes.ts` — `waveOverview`.
- `i18n/locales/en|ar/operations.json` — new `wave.workspace.*` (resolving/error/retry/noActiveWave/
  multipleActiveWaves/tabs.overview) and `wave.missingMaterials.coverageCovered|coverageUncovered`
  (EN/AR parity maintained; `columns.available` already existed).

---

## 25. Files Changed

**Backend**
- `backend/Modules/Operations/Preparation/Presentation/Http/Controllers/PreparationWaveController.php`
- `backend/Modules/Operations/DemandAnalysis/Presentation/Http/Controllers/WaveDemandController.php`
- `backend/routes/api.php`
- `backend/tests/Feature/Operations/WaveCurrentAndKpisHttpTest.php` *(new)*

**Frontend**
- `frontend/src/features/operations/types/preparation.ts`
- `frontend/src/features/operations/services/preparation-service.ts`
- `frontend/src/features/operations/hooks/use-preparation.ts`
- `frontend/src/features/operations/components/wave-workspace-layout.tsx`
- `frontend/src/features/operations/pages/wave-product-demand-page.tsx`
- `frontend/src/features/operations/pages/wave-missing-materials-page.tsx`
- `frontend/src/features/operations/pages/deficit-decisions-page.tsx`
- `frontend/src/features/operations/pages/wave-orders-page.tsx`
- `frontend/src/router/router.ts`
- `frontend/src/router/routes.ts`
- `frontend/src/i18n/locales/en/operations.json`
- `frontend/src/i18n/locales/ar/operations.json`
- `frontend/src/features/operations/components/wave-workspace-layout.test.tsx` *(new)*

---

## 26. Focused Verification

- **Backend:** `php -l` clean; **PHPStan clean** (both controllers, project config);
  **12 tests / 43 assertions OK** through the isolated gate (`GATE_WAIT=2400`), route cache cleared:
  - `WaveCurrentAndKpisHttpTest` (new, 7): current → single active auto-resolves; no active →
    `active_count 0`, `wave: null`; multiple active → `active_count 2`, `wave: null`, both ids listed
    (no silent pick); tenant isolation; live completion 10/10+0/5 = **66.67%** with `total_units_*`;
    0% when nothing prepared; 100% when all prepared. (§28 A, D)
  - `WaveKpiCalculatorTest` (existing, 5): quantity-weighted completion + counts — re-run green,
    confirming the source the live endpoint now delegates to.
- **Frontend:** `tsc -p tsconfig.app.json` — **0 errors in the feature** (pre-existing baseline
  errors in unrelated files untouched, per the ratchet rule); **ESLint 0**;
  `wave-workspace-layout.test.tsx` — **5/5** covering auto-open (§28 A/B), No-Active (§5), Multiple
  (§6), Error-not-Empty (§28 E). i18n EN/AR parity maintained for all new keys.
- No DEV/demo/live business data was created or mutated; backend assertions run on the isolated
  `RefreshDatabase` schema only.

---

## 27. Deferred Verification

Per current project policy: **no broad regression, no browser certification cycle, no module
certification** — deferred to Final System Review. Specifically deferred:

- Per-page mobile-card render tests (§28 C) and the index→Active redirect at runtime — statically
  verified via tsc + the layout resolution test; browser/visual confirmation deferred.
- Full Preparation feature suite and cross-tab regression.

---

## 28. Remaining Gaps

- **Multiple-active waves in multi-warehouse companies** is treated as a "choose one" state rather
  than an error, since more than one active wave can be legitimate across warehouses; a per-warehouse
  current-wave scope is a possible future refinement (the workspace has no warehouse selector today).
- Search / filter on mobile reuse the existing page-local controls; a dedicated mobile filter sheet
  was intentionally not built (no new filter semantics; §16).
- The stored `wave_kpis` / `total_units_*` snapshots still exist for the wave list and Archive; the
  workspace no longer depends on them for completion, but a background path that changes Required
  without a Prepared write can still leave those *snapshots* briefly behind (the live workspace read
  is unaffected). Out of scope here.
- Deferred design questions explicitly out of scope and untouched: the postpone/return symmetry
  (`!isTerminal()`) contract (§23), and reservation/recipe/MRP/eligibility/shortage authorities (§22).

---

## 29. Implementation Status

All four goals are implemented and narrowly verified; the desktop experience is preserved and no
canonical authority was duplicated or altered.

---

IMPLEMENTATION STATUS:
COMPLETE

FINAL CERTIFICATION:
DEFERRED TO FINAL SYSTEM REVIEW
