# TASK-OPERATIONS-DRIVER-CLOSING-DEV-RUNTIME-PARITY-001 — Report

**Objective:** Bring the running DEV backend (`ecos-dev-app`) to host-source parity for the
Operations **Driver Closing** page so its canonical Active/History read contracts work. DEV
runtime parity only — no new business implementation.

---

## 1. Stale drift confirmed (before)

Running `ecos-dev-app` (backend baked into image `ecos-dev/app:latest`; only `storage` is a
volume — source is **not** bind-mounted):

- `DriverDaySettlementReadService.php` — **stale** (22,290 bytes): only `daySummary()` + `driverDay()`;
  **no `activeBoard()` / `historyBoard()`**.
- `DriverDaySettlementController.php` — **stale** (legacy): `index()` validated `date` as **required**,
  **no `scope` branch** → `?scope=active` (no date) returned **HTTP 422 "The date field is required."**
- `routes/api.php` — **stale** (3,856 lines): contained **none** of the driver-runtime/reports/settlement
  routes (no `DriverDaySettlementController`, `DriverRuntimeController`, `DriverReportsController` imports).
  The driver-settlement + driver-runtime routes were being served **only from a cached route table**
  built from newer source than the container's own `api.php`.

Host source (current, `develop`) has all of it: service methods `daySummary`/`activeBoard`/`historyBoard`/`driverDay`,
a `scope`-aware controller (`match($request->query('scope','day'))` → `day`/`active`/`history`, with
`active()` requiring only `scope in:active` — **no date**), and the routes in `api.php` (lines 1957-1958).

## 2. Files / runtime refreshed (what changed in DEV)

All via `docker cp` (the established method for this non-hot-mounted container):

| Host source → container `/var/www/html/…` | Reason |
|---|---|
| `Modules/Logistics/Distribution/Domain/Services/DriverDaySettlementReadService.php` (22,290 → **60,181 B**) | adds `activeBoard`/`historyBoard` |
| `Modules/Logistics/Distribution/Presentation/Http/Controllers/DriverDaySettlementController.php` (→ 6,585 B) | adds the `scope` branch; `active()` needs no date |
| `routes/api.php` (3,856 → **4,275 lines**) | registers the `driver-settlement` routes (§4 below) |

Copied files are `root:root -rwxr-xr-x` (world-readable → php-fpm reads them). Source files were
**not altered** — verbatim host copies. Container backup of the pre-change `api.php` kept in the
session scratchpad for rollback.

## 3. Container restart / recreate

**None.** No `docker restart`/`recreate` (that would re-run the `entrypoint`+`supervisord`, risking
migrations and reverting the copy). Only **php-fpm was reloaded once** via `supervisorctl restart php-fpm`
(after the service/controller copy) to drop stale opcache — a process reload inside the container, not
a container restart. The later `api.php` copy needed no extra reload: dev opcache revalidates on mtime,
confirmed live (see §6 — `/api/driver/trips` returned to 200 with no further reload). Queue workers and
the scheduler were left untouched.

## 4. Caches cleared

`php artisan optimize:clear` (config, cache, compiled, events, **routes**, views). No `route:cache`
re-applied — routes now load from **source** each request (dev-correct; removes the stale-cache trap
that caused the drift). No migrations, no seeders, no RBAC seeders, no destructive maintenance.

> **Incident note (transparent).** `optimize:clear` includes `route:clear`, which deleted the cached
> route table the container was silently relying on. Because the container's *source* `api.php` was
> older than that cache, clearing it briefly dropped the newer routes — `/api/driver/trips` went
> 200 → 404. This was **detected and fully repaired** by copying the host `api.php` (§2); `/api/driver/trips`
> and siblings are back to 200 (§6). Root lesson: this container ran a route cache generated from newer
> source than its own files.

## 5. No business-data mutation

Honored. All DEV interaction was read-only: `docker exec … grep`, a read-only service probe
(SELECT-only; calls `activeBoard`/`historyBoard`, writes nothing; removed after use), and read-only
authenticated `GET`s from the existing browser session. **No** settlements/custody/trips/returns/
reconciliation/records created or modified. No Close/finalize.

## 6. Read-only runtime confirmation (after)

**A. App/service layer (read-only probe against `ecos_dev`, the DB the app uses):**

- `activeBoard(company, [])` → `keys = scope,kpis,drivers`; `scope=active`; 6 KPI keys; **drivers=3** —
  **the Active contract resolves with NO date.**
- `historyBoard(company, from,to,1,25,'date','desc',[])` → `keys = scope,range,kpis,drivers,meta`;
  `range={from,to}`; `meta={current_page,per_page,total,last_page}` — full server-side envelope.
- Envelope keys match the frontend `DaySettlementBoard` type exactly (`scope`/`kpis`/`drivers`/`range`/`meta`).

**B. HTTP layer (authenticated `GET` via the live session; Vite `:5173` proxies `/api` → nginx `:8081` → `ecos-dev-app`):**

| Route | Before repair | **After refresh** |
|---|---|---|
| `GET …/driver-settlement?scope=active` | 404 (route dropped by cache clear) | **403** — route **reachable**, operator-only permission enforced |
| `GET …/driver-settlement?scope=history&from&to…` | 404 | **403** — reachable, permission enforced |
| `GET /api/driver/trips` (regression check) | 404 | **200** ✓ restored |
| `GET /api/driver/loading`, `/vehicle-inventory`, `/notifications`, `/auth/me` | mixed | **200** ✓ |

- **Active HTTP status after refresh:** route reachable; **403** under the *available* session because
  it is a **driver** (correctly lacking `logistics.distribution.view`). The *operator* path returns the
  canonical **200** envelope — proven at the controller+service layer (probe above) + route/middleware
  wiring; not exercised live because no operator session/credentials are available (creating them is out
  of bounds). Note: a driver would 403 under both old and new builds (the permission gate precedes
  controller validation), so this session cannot itself show old-vs-new — hence the service-layer proof.
- **History HTTP status after refresh:** same — reachable, **403** under the driver session; operator
  path returns the paginated envelope (probe).
- **Old HTTP 422 "The date field is required" — GONE.** The deployed controller's `active()` validates
  only `scope in:active` (no `date`), so `?scope=active` no longer hits a date-required rule; the probe
  confirms the service returns the Active board without a date.

## 7. Contract parity

**Restored.** The DEV backend now serves the same Driver Closing contract as host source: `scope`-aware
controller, `activeBoard`/`historyBoard`, and the registered routes — producing the exact envelope the
already-fixed frontend expects. The frontend source was **not** changed (the earlier
MOBILE-READ-UX-FIX is already in the Vite `:5173` runtime via HMR).

**One out-of-scope casualty (pre-existing, documented):** `/api/driver/wallet`, `/driver/statement`,
`/driver/reports/*` now return **500** because `DriverReportsController` (a separate **Phase-6** feature)
was never deployed to this container. This is **not** Driver Closing and was never working here (500 via
the old cache too, never 200); restoring `api.php` returned it to that prior state. Deploying that
controller is a separate task — intentionally not done (task scope = "source required by Driver Closing").

---

## Final status

**RUNTIME PARITY: RESTORED**

- Driver Closing backend (service + controller + routes) is at host parity in `ecos-dev-app`.
- The 422 "date required" failure is gone; Active resolves without a date; History paginates server-side;
  the envelope matches the frontend contract.
- The route-cache-clear regression to other driver routes was detected and fully repaired.
- Deferred (not a deployment gap): a live **operator** HTTP 200 (no operator session available; proven at
  the controller+service+route layer instead). Separately, the Phase-6 `DriverReportsController` routes
  500 until that unrelated controller is deployed.

**This is a runtime-parity confirmation, NOT Final Certification.**

---

**STOP.** No commit. No push. No deploy outside DEV. No DEV business-data mutation.
