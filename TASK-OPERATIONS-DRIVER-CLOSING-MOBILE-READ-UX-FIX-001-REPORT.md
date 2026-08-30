# TASK-OPERATIONS-DRIVER-CLOSING-MOBILE-READ-UX-FIX-001 — Report

**Surface:** Operations → Driver Closing / Driver Day Settlement (`تقفيل اليوم`)
**Scope:** Fix the mobile read-failure presentation and the History time-filter placement.
**Verification posture:** Narrow, root-cause-focused. **FINAL CERTIFICATION DEFERRED.**

---

## 1. Root Cause of the Read Failure (§1 — audited, not guessed)

The read failure was traced through the actual code path — frontend request → route → the **running
DEV container's own controller/service source** — not inferred from the UI.

**The request the page issues.** The workspace defaults to the **Active** board and calls, via
`useDriverSettlementBoard` → `driver-settlement-service.board()`:

```
GET /api/logistics/distribution/driver-settlement?scope=active            (default, no date)
GET /api/logistics/distribution/driver-settlement?scope=history&from=…&to=…   (History tab)
```

**What the running DEV backend actually serves.** Inside the live container `ecos-dev-app`:

- `…/Domain/Services/DriverDaySettlementReadService.php` defines **only** `daySummary()` (line 60)
  and `driverDay()` (line 106). It has **no `activeBoard()` and no `historyBoard()`**.
- `…/Presentation/Http/Controllers/DriverDaySettlementController.php` `index()` (lines 31–44) is the
  **legacy, single-shape** handler:

  ```php
  $validated = $request->validate([
      'date' => ['required', 'date_format:Y-m-d'],   // date is REQUIRED
      …
  ]);
  … $this->service->daySummary($this->companyId(), $validated['date'], [ … ]);
  ```

  There is **no `scope` branch** at all (`grep scope` in the container controller matches only the
  403 company-guard comment).

**Exact failing contract.** The stale controller ignores `?scope=active|history` and enforces
`date` as **required**. The Active request (and the History request) send **no `date`** → Laravel
form-validation rejects with:

- **HTTP `422 Unprocessable Entity`**
- Body: `{"message":"The date field is required.","errors":{"date":["The date field is required."]}}`

The frontend's TanStack Query turns any non-2xx into `isError` → the page renders
`driverSettlement.loadError` ("Couldn't load settlement data."), and — before this fix — the KPI
strip stayed stuck on its skeleton (see §2).

**Classification: environmental deployment drift, NOT a source contract defect.**

- The **host source** (`develop` worktree) `DriverDaySettlementReadService.php` has all four methods —
  `daySummary` (108), **`activeBoard` (141)**, **`historyBoard` (183)**, `driverDay` (255) — and the
  host controller branches on `scope` (`active`/`history`/`day`).
- The source response envelope keys — `scope` / `kpis` / `drivers` / `range` / `meta` — match the
  frontend `types/driver-settlement.ts` (`DaySettlementBoard`) **exactly**. No key/shape drift.
- The source contract is **test-covered**: `backend/tests/Feature/Logistics/DriverDaySettlementReadTest.php`
  proves `?scope=active` returns `scope:'active'` **without a date** (`test_active_scope_lists_open_custody_without_a_date_filter`),
  `?scope=history&from&to` paginates (`test_history_scope_filters_finalized_settlements_and_paginates`),
  and invalid ranges 422 (`test_history_requires_a_valid_range`).

The running container simply predates the `scope`-aware enhancement. **Resolving the runtime read
requires deploying the current backend to `ecos-dev-app`** (`docker cp` of
`Modules/Logistics/Distribution/**` + route/config cache clear) — which is **explicitly out of scope**
under this task's "Do NOT deploy" constraint. No source change was needed or made on the backend; the
read contract is already correct and certified in the repo.

---

## 2. Distinct Read States — Loading / Error / Empty / Loaded (§2)

**The defect.** `DaySettlementKpiCards` skeletoned on `loading || !kpis`. On a failed read TanStack
Query yields `isLoading=false, data=undefined` → `false || true` → the **skeleton rendered
indefinitely**. Worse, the skeleton sat *outside* the results area's `isError` branch, so the KPI
strip never reflected the failure — the reported "KPI cards stuck loading" symptom.

**The fix.** `DaySettlementKpiCards` now takes an explicit `error?: boolean` that **precedes**
loading, and the workspace passes `error={isError}`. The four states are now visually distinct:

| State | KPI strip | Results area |
|---|---|---|
| **Loading** | skeleton (`data-testid="kpi-loading"`) | spinner + `…loading` |
| **Error** | dashed "—" placeholder cards (`data-testid="kpi-error"`, `aria-label=loadError`) | `AlertTriangle` + `…loadError` + **Retry** |
| **Empty** (successful zero-row read) | real KPI strip (real counts, may be 0) | grid empty-state |
| **Loaded** | real KPI strip | data grid |

- A failed read **no longer shows an indefinite skeleton** and **no longer collapses to zero cards
  masquerading as success** — it shows an unmistakable unavailable state.
- **Retry** is exposed in the results area and calls the existing `refetch()` read flow.
- **Mutation/Close actions stay unavailable on failure.** The workspace has no direct mutation; its
  only per-row action (Review) lives inside the grid, which is not rendered in the error branch. The
  **detail page** (where the Close/Approve CTA and finalize dialog live) already returns early on
  `isError || !data` with an error state (`AlertTriangle` + Retry + Back), so the Approve/finalize
  controls are never rendered on a failed read — already compliant; left unchanged.

---

## 3. Canonical Authorities Preserved (§3)

No settlement/reconciliation authority was touched. All figures remain server-derived rollups over
the canonical engines — `SettlementService::finalize`/`financialSummary`, `ReceiveVehicleReturnAction`,
`VehicleShiftReconciliation(+Line)`, and the custody/collection/damage/shortage sources. The audit
found the backend read contract **correct**, so no backend change was made (per §3, read/UX repair
only unless the audit proved a concrete backend read-contract defect — it did not). Finalization on
the detail page still calls the canonical per-trip `tripSettlementService.finalize` per trip.
Changes are **frontend presentation only**.

---

## 4. Active vs History Read Contract (§4)

Unchanged and already correct — preserved through the reorder:

- **Active** requests `{ scope: 'active', search }` — **no date/range** is sent. The board shows open
  custody, not a date-bounded set. (Backend `activeBoard` selects distinct open assignments; not
  date-filtered.)
- **History** requests `{ scope: 'history', from, to, page, per_page, sort, dir, search }` — closed
  settlements over a **server-side** range with pagination and sort.

The History date filter is **only** rendered in History scope, so it can never constrain the Active
board. Unit-pinned: the Active board request asserts `{ scope: 'active' }` and the absence of the
preset control (§10).

---

## 5. Mobile History Ordering (§5)

Required order — **Header → Active/History → Date Preset + Range → Search → KPIs → History Results** —
now holds. The filter block was moved to sit **directly under the tabs and above the KPI strip**, and
within it the **date controls lead**, Search follows:

```
Header + Active|History tabs
Filters:  [ Preset ] [ custom From/To ] [ from → to ]   ← History date range (leads)
          [ Search ]
KPIs strip                                              ← scoped by the filter above
History results (grid + server-side pagination)
```

The time filter **no longer appears below the KPI cards**. Unit-pinned via DOM order:
`preset` precedes `search`, and `search` precedes the KPI strip (§10).

Rationale for placing the filter above the KPIs: on History the **KPI counts are computed
server-side over the selected range** (`historyBoard` → `kpis($rangedRows)`), so the control that
determines the numbers must sit above the numbers it drives.

---

## 6. Mobile Active Ordering (§6)

Required order — **Header → Active/History → Search → KPIs → Active Results** — now holds. In Active
scope the date filter is not rendered, so the filter block collapses to just Search, yielding
`Header → tabs → Search → KPIs → results`. Unit-pinned: no preset control present; Search precedes
the KPI strip (§10).

---

## 7. Desktop Audit (§7)

Desktop was audited. The **only** structural change is the same filter-above-KPIs reorder; everything
else — the toolbar, header, the 6-column KPI grid, the data-grid columns, sort, and server-side
pagination — is untouched. This is a **single block move, not a redesign**.

The reorder was applied **uniformly** (all breakpoints) rather than mobile-only, deliberately: on the
History tab the date range *determines* the KPI values, so a filter sitting below those values is
backwards on desktop too. Moving it above corrects the information flow everywhere while leaving the
desktop layout otherwise identical. (If a strictly pixel-identical desktop is later preferred, the
block order can be made breakpoint-specific with a one-line `lg:order-*` pair; not done here because
it would preserve the very filter-below-its-own-output arrangement §5 calls out.)

---

## 8. Server-Side Presets Preserved (§8)

All eight presets — Today / This Week / This Month / Previous Month / This Year / Year-to-Date /
Previous Year / Custom — are unchanged (`HISTORY_PRESETS`), resolved by `historyRange()` into a
`{from,to}` window that is **sent to the server**. No React-side row filtering was introduced. A
preset change recomputes the range, resets `page` to 1, and issues a **new canonical request**.
Unit-pinned: switching preset from `this_month` to `previous_month` changes the server `from` and
keeps `scope:'history'`, `page:1` (§10).

---

## 9. Mobile Responsiveness (§9)

The filter row is now `flex flex-col gap-2 sm:flex-row sm:items-center sm:flex-wrap`: on mobile the
controls **stack full-width** (`w-full`), and from `sm` up they return to a wrapping inline row
(`sm:w-44` preset, `sm:w-36` date inputs, `sm:w-56` search). This removes horizontal overflow, keeps
the preset and date range readable, and the KPI grid (`grid-cols-2 sm:grid-cols-3 lg:grid-cols-6`)
and its error/loading variants are unchanged and do not clip. The error state is fully visible (KPI
"—" placeholders + a prominent results-area error with Retry).

---

## 10. Verification (narrow, §10)

**Unit (Vitest) — `driver-settlement-workspace-page.test.tsx`, 9/9 pass** (full driver-settlement
folder **14/14**, incl. the untouched detail page):

- **Root-cause symptom fixed:** on `isError`, the KPI strip renders `kpi-error` and **not**
  `kpi-loading`, and **not** the loaded strip — no stuck skeleton, no false zeros.
- **Loaded** shows the real KPI strip (no error/loading markers); **Empty** (zero-row success) shows
  the grid empty-state and **not** the error state — proving Error ≠ Empty.
- **Retry** on failure invokes the existing `refetch()` flow.
- **Loading** shows the skeleton (not the error state).
- **§6 Active:** no date filter present; request is `{scope:'active'}`; Search precedes KPIs.
- **§5 History:** preset precedes Search; Search precedes KPIs (DOM order).
- **§8 preset change:** a preset change issues a fresh `{scope:'history', page:1}` request with a
  changed server `from` — canonical server request, not client filtering.

**Static:** ESLint **clean** on all three touched files; **0 tsc errors** in touched files
(`tsc -p tsconfig.app.json`; the repo-wide baseline of 23 pre-existing errors is unrelated and
untouched).

**Backend read contract (documentary):** proven correct by the existing
`DriverDaySettlementReadTest.php` (active-without-date, history-paginated, range validation). Not
re-run here — the source is unchanged by this task and re-running would require syncing the
(separately drifted) testrunner container.

**Deferred (final review):** live mobile-viewport pass on a deployed build — the DEV nginx bundle and
`ecos-dev-app` are both stale, and no deploy is authorized. DOM order and responsive classes are
unit-proven; visual confirmation at `375px` is deferred.

---

## 11. Constraints Honored (§11)

- **No DEV business data mutated.** All DEV interaction was read-only inspection (`docker exec … grep`
  of container source; `docker ps`). No writes, no seeders, no endpoint mutation.
- **No backend/RBAC change.** The endpoint remains gated `logistics.distribution.view`.
- **No deploy.** No `docker cp` to `ecos-dev-app`/nginx; no build published. Changes live in source
  (Vite `:5173` reflects them via HMR; the nginx `:8081` bundle is unchanged).
- **No commit / push.**

---

## 12. Files Changed

| File | Change |
|---|---|
| `frontend/src/features/operations/driver-settlement/components/day-settlement-kpis.tsx` | Added `error?: boolean`; renders a distinct "unavailable" state (dashed "—" cards) that **precedes** loading, so a failed read never shows an indefinite skeleton or false zeros (§2). Added `data-testid` markers for the loading/error variants. |
| `frontend/src/features/operations/driver-settlement/pages/driver-settlement-workspace-page.tsx` | Moved the filter block **above** the KPI strip with the **date controls leading, Search following** (§5/§6); Active shows no date filter (§6); pass `error={isError}` to the KPI strip (§2); filter row stacks full-width on mobile, wraps inline from `sm` (§9). |
| `frontend/src/features/operations/driver-settlement/pages/driver-settlement-workspace-page.test.tsx` | Added coverage for the KPI error state (not skeleton/zeros), Retry, Active/History ordering, and preset→canonical-server-request; added deterministic Tabs/Select test doubles for jsdom. |

Backend source, routes, RBAC, canonical services, and the detail page are **unchanged**.

---

## 13. Status & Certification

**Implementation Status: COMPLETE (in-scope).**

- The read-failure **root cause is proven** (environmental deployment drift; exact `422 / "The date
  field is required."` from the stale `ecos-dev-app` build), and the **source read contract is already
  correct and test-covered**. Resolving the *runtime* read requires a backend deploy, which is
  **out of scope** per "Do NOT deploy" — documented for the operator.
- The **presentation defects are fixed in code**: the KPI strip now renders a distinct error state
  (no stuck skeleton, no false zeros) with Retry, and the mobile History time-filter now sits above
  Search and the KPIs, with Active carrying no date filter and the controls responsive on mobile.

**Residual (not code, requires a separate authorized action):** deploy the current
`Modules/Logistics/Distribution/**` to `ecos-dev-app` so the live Active/History reads succeed
(`docker cp … && php artisan route:clear && config:clear`). Until then the DEV runtime keeps
returning 422 — but the UI now fails **loudly and correctly** instead of hanging on a skeleton.

**FINAL CERTIFICATION: DEFERRED TO FINAL SYSTEM REVIEW.**

---

**STOP. Do NOT commit. Do NOT push. Do NOT deploy. Do NOT mutate DEV business data.**
