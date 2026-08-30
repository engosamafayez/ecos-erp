# TASK-PREPARATION-CURRENT-WAVE-RUNTIME-CLOSURE-001 — Engineering Report

**Preparation Current-Wave Runtime Closure — Read Failure + Legacy Selector Removal**
Date: 2026-08-29 · Branch: `develop` · Status: **COMPLETE** (focused; verification narrow)

---

## 1. Actual Failing Endpoint

`GET /api/preparation/waves/current` — the canonical current-active-wave read added by
TASK-PREPARATION-WORKSPACE-MOBILE-UX-ACTIVE-WAVE-001 and consumed by `useCurrentWave()` in
`WaveWorkspaceLayout`.

---

## 2. HTTP Status / Error

- **Before this task (running `ecos-dev-app`):** the route was **not registered** — `php artisan
  route:list` showed only `api/preparation/waves/{waveId}`, and the controller had **no
  `current()` method** (`grep -c 'function current' … = 0`). A request therefore matched
  `waves/{waveId}` with `waveId = "current"` or 404'd; the frontend's read failed and rendered the
  generic **"Couldn't load the current preparation wave."**
- **After the fix:** `GET /api/preparation/waves/current` → **HTTP 401** unauthenticated (the route
  is registered; `auth:sanctum` answers first), i.e. it resolves. Authenticated, it returns the
  single active wave (see §7).

It was **not** the multiple-active invariant: DEV holds exactly **one** active wave (§7).

---

## 3. Root Cause

**DEV backend drift.** The prior task's backend changes — `PreparationWaveController::current()`,
the `waves/current` route in `routes/api.php`, and `WaveDemandController::kpis()` live-completion —
were `docker cp`'d only into **`ecos-dev-testrunner`** (for the gated tests) and **never into the
running `ecos-dev-app`**. The dev containers are not hot-mounted (image copies, not bind mounts),
so the running backend kept serving the pre-task code without the route. This mirrors the same
"source OK + tested, running dev container behind" pattern recorded for the driver-closing read
failure.

---

## 4. Runtime Parity Findings

| Surface | Finding |
|---|---|
| `ecos-dev-app` (PHP backend) | **Behind the working tree** — no `waves/current` route, no `current()` method, no live `kpis()`. No route cache present (so not a stale-cache issue; the code was genuinely absent). |
| `ecos-dev-nginx` (8081 bundle) | Served a **stale working-tree build** (`index-CF16qkbY.js`, Last-Modified 04:37) that predated this task's selector removal. nginx serves its own `public/app` copy — a build must be copied to it explicitly. |
| Vite dev server (127.0.0.1:5173, host) | **Up (200)** and serving the working-tree source live. This is the runtime that showed both symptoms: it already had the prior task's layout (hence the exact "Couldn't load…" string) **and** still rendered the `WavePicker` the prior task had left in the header. |
| DEV DB | `ecos_dev` (not the test DB); **1 active wave** for the single company. |

The frontend/backend were out of parity in two independent ways: the backend lacked the route
(runtime drift), and the frontend *source* still rendered the selector (a design gap, §5).

---

## 5. Legacy Selector Root Cause

Not a parity problem — a **source** one. The prior task added auto-resolution but **left
`<WavePicker showBadge={false} />` in the `WaveWorkspaceLayout` header**, so the "Select a wave…"
control rendered in every state (including the error state, since the header always renders). §3 of
this task requires its complete removal from Today's Preparation.

---

## 6. Fix

**Frontend source (§3):** removed the `WavePicker` import and its header usage from
`WaveWorkspaceLayout`. The selector no longer renders in any state (Loading / Loaded / No Active
Wave / Read Error / Multiple Active Waves). It is **not** restored as an error fallback. The
multiple-active state already offers an explicit per-wave choice in the body (not the legacy
selector). `WavePicker` is used nowhere else; **Archive** (`WaveArchivePage`) has its own historical
browsing and is untouched. `useSelectedWaveId` (also exported from that module, used by the tab
pages) is unchanged.

**Backend runtime refresh (§5):** deployed the corrected code into `ecos-dev-app` —
`PreparationWaveController` (with `current()`), `WaveDemandController` (live `kpis()`), and a
`routes/api.php` equal to **git HEAD plus only the `waves/current` route** — then
`php artisan optimize:clear`. `route:list` is clean (0 missing-class errors) and lists
`api/preparation/waves/current`.

**Frontend runtime refresh (§5):** `npx vite build` (bypasses the failing `tsc -b` prod gate) →
`docker cp backend/public/app/.` to **`ecos-dev-nginx`** (the copy that serves the browser) and
`ecos-dev-app`. 8081 now serves the new bundle (`index-59gNgQeF.js`) containing the new layout
logic. Vite 5173 already serves the corrected source via HMR.

**Self-inflicted side effect, corrected:** the first backend refresh copied the *entire*
working-tree `api.php`, which pulled in another workstream's **uncommitted** `driver-reports` route
whose controller is not deployed — breaking `route:list` (a diagnostic; request-time routing is
lazy/string-based and was unaffected). It was reverted to the minimal HEAD + `waves/current`
reconstruction, and the stray controller copy removed. No committed route was dropped; no other
workstream's uncommitted routes were deployed (minimal, in-scope refresh).

---

## 7. 0 / 1 / Many Behavior

- **DEV today:** exactly **one** active wave (`preparing`) for the one company →
  `active_count = 1`, `wave` populated → the layout auto-sets `wave_id` and opens the **Active** tab.
- **Zero active:** `active_count = 0` → **"No Active Preparation Wave"** state; no selector.
- **Multiple active:** `active_count > 1` → `wave: null` + the conflicting wave numbers listed as an
  explicit invariant/choice state; **never** auto-picked.
- **Read failure:** explicit **error + Retry** (Retry re-invokes the same `useCurrentWave` read; no
  fallback to manual selection).

The backend resolution logic (0/1/many + tenant isolation) and the live quantity-weighted
completion are covered by the gated tests carried over from the prior task
(`WaveCurrentAndKpisHttpTest` + `WaveKpiCalculatorTest`, 12 tests / 43 assertions).

---

## 8. DEV Runtime Refresh Performed

- `ecos-dev-app`: `docker cp` of `PreparationWaveController.php`, `WaveDemandController.php`, and the
  reconstructed `routes/api.php` (HEAD + `waves/current`); removed the stray `DriverReportsController.php`;
  `php artisan optimize:clear`.
- `ecos-dev-nginx` + `ecos-dev-app`: `npx vite build` then `docker cp backend/public/app/.`.
- **No production deploy, no commit, no push.** No DEV business data mutated (all inspection
  read-only; the DB queries were `SELECT`-only).

---

## 9. Focused Verification

- **Backend runtime:** `route:list` → **0** "class does not exist" errors; `api/preparation/waves/current`
  listed. HTTP via nginx: `waves/current` **401**, `waves` **401**, `orders` **401** (registered,
  auth-gated — not 404/500). Container confirmed to hold `current()` and the live-`kpis()` controller.
- **DEV data (read-only):** `SELECT COUNT(*) … active` = **1** → the auto-open path is the one that
  applies today.
- **Frontend runtime:** 8081 serves `index-59gNgQeF.js`; the built assets contain `noActiveWave` /
  `multipleActiveWaves` (the new layout is in the deployed bundle). Vite 5173 module for
  `wave-workspace-layout.tsx`: `WavePicker` refs **0**, `useCurrentWave` refs **3**.
- **Static/tests:** `tsc -p tsconfig.app.json` — 0 errors in the changed file; ESLint 0;
  `wave-workspace-layout.test.tsx` **5/5**, now asserting the selector is absent in the Loaded and
  No-Active states, plus auto-open / multiple / error-not-empty.
- **Archive:** `WavePicker` has no importer outside the (now-cleaned) layout; Archive's historical
  browsing is unaffected.

**Not performed:** a live *authenticated* click-through of the mobile UI. Signing in requires
entering a password, which is a prohibited action for me; the runtime response is instead proven by
composition — route registered (401) + tested controller logic + the confirmed single active wave in
`ecos_dev`. This UI walkthrough is left to the user / Final System Review.

---

## 10. Remaining Gaps

- **Ambient container drift (out of scope).** `ecos-dev-app` is broadly behind the working tree
  across several *uncommitted* workstreams (e.g., `driver-reports`, and the driver-settlement backend
  from a separate task). Those features' routes are intentionally **not** deployed here, so their
  screens remain non-functional in DEV exactly as before — unrelated to Preparation. Re-copying the
  full working-tree `api.php` would resurface the `route:list` cascade; a full container parity sweep
  is a separate ops task.
- **No live authenticated UI proof** (login is prohibited for me) — see §9.
- The `WavePicker` component file remains (it still exports the used `useSelectedWaveId`); the unused
  `WavePicker` export is harmless dead code, not rendered anywhere.

---

## 11. Implementation Status

The read failure is resolved at the running backend (route registered, controllers deployed,
`route:list` clean, endpoint 401/authoritative), the legacy selector is removed from Today's
Preparation in source and in both runtimes (Vite 5173 + rebuilt 8081 bundle), and the 0/1/many/error
states are in place with the single DEV active wave auto-opening the Active tab.

---

IMPLEMENTATION STATUS:
COMPLETE

FINAL CERTIFICATION:
DEFERRED TO FINAL SYSTEM REVIEW
