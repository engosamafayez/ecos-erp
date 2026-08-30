# TASK-ENV-API-CONNECTION-REPAIR-001 — Engineering Report

**Status:** RESOLVED — root cause proven from runtime evidence, minimal repair applied and verified end-to-end in a browser.

**Date:** 2026-08-12
**Worktree:** `C:\ecos-develop` (DEV stack, `ecos-dev` compose project)
**Branch:** `develop`

**One-line root cause:** The Vite dev server proxied `/api` to `http://127.0.0.1:8080`, a port nothing listens on. The DEV nginx is published on **8081**. Every API call died with `ECONNREFUSED`, which Vite surfaces to the browser as **HTTP 502**, and the login form correctly maps `>= 500` to "Server unavailable."

**The backend was healthy the entire time.** The IAM HTTP surface is present and working. No IAM work is required.

---

## 1. Symptom

`http://172.23.80.1:5173/app/login` renders correctly, but signing in displays:

> Server unavailable. Please try again later.

## 2. Docker State

`docker ps` — DEV stack containers, all healthy, none restarted during this task:

| Container | Status | Published ports |
|---|---|---|
| `ecos-dev-nginx` | Up 2 hours (healthy) | `127.0.0.1:8081->80/tcp` |
| `ecos-dev-app` | Up 2 hours (healthy) | `9000/tcp` (internal) |
| `ecos-dev-mysql` | Up 2 hours (healthy) | `127.0.0.1:3316->3306/tcp` |
| `ecos-dev-redis` | Up 2 hours (healthy) | `127.0.0.1:6381->6379/tcp` |
| `ecos-dev-testrunner` | Up About an hour | `9000/tcp` (internal) |
| `ecos-dev-mailpit` | Up 2 hours (healthy) | `127.0.0.1:1026->1025`, `127.0.0.1:8026->8025` |

Network: `ecos-dev_ecos-network`. The MAIN stack (`ecos-*`) was running alongside and was not touched.

**No container was restarted, recreated, or stopped.** The fault was never in Docker.

## 3. Frontend API Configuration

`frontend/.env` (local, gitignored):

```
VITE_API_URL=/api
```

`frontend/src/lib/env.ts` → `apiUrl: import.meta.env.VITE_API_URL ?? '/api'`
`frontend/src/lib/axios.ts` → `axios.create({ baseURL: env.apiUrl })`

So the browser issues **same-origin relative** requests to `/api/*` against the Vite dev server. The dev server is responsible for forwarding them.

`frontend/vite.config.ts` (before repair):

```ts
proxy: {
  '/api':     { target: process.env.BACKEND_URL ?? 'http://127.0.0.1:8080', ... },
  '/storage': { target: process.env.BACKEND_URL ?? 'http://127.0.0.1:8080', ... },
}
```

## 4. Actual Browser API Target

Determined from runtime, not from files alone:

- Vite runs **natively on Windows**, not in Docker — `node .../vite/bin/vite.js` (PID 26128), listening on `::` port 5173. There is no Vite service in `docker-compose.yml` or `docker-compose.override.yml`.
- Therefore `process.env.BACKEND_URL` is **unset**, and the hardcoded fallback is what is actually used.
- `docker-compose.override.yml` contains no `BACKEND_URL` key at all (grep returned no matches), confirming the fallback is the only live value on this path.

**Effective runtime chain:**
`browser → http://172.23.80.1:5173/api/* → Vite proxy → http://127.0.0.1:8080` ← dead end.

## 5. Host Connectivity

`Get-NetTCPConnection -State Listen`:

| Port | Listener |
|---|---|
| 5173 | `::` (Vite, PID 26128) |
| 8081 | `127.0.0.1` (Docker proxy, PID 8340) |
| **8080** | **nothing** |

HTTP probes from Windows:

| Target | Result |
|---|---|
| `http://127.0.0.1:8081/api/health` | **200 OK** — `{"status":"ok","database":true,"redis":true,"queue":true,"storage":true,"scheduler":true,"queue_workers":3}` |
| `http://172.23.80.1:5173/api/health` | **502 Bad Gateway** (Vite proxy) |
| `http://127.0.0.1:8080/api/health` | **Unable to connect to the remote server** |

This is the decisive triple: backend healthy, proxy broken, fallback port dead.

## 6. Nginx → App Connectivity

Executed **inside** `ecos-dev-nginx`:

```
wget -q -O- http://127.0.0.1/api/health
→ {"status":"ok", ... ,"database":true,"redis":true,"queue":true}
```

nginx vhost (`docker/nginx/local.conf`, mounted by the override):

```
listen 80 default_server;
server_name _;
root /var/www/html/public;
fastcgi_pass app:9000;
```

`nginx → PHP-FPM → Laravel` was fully functional and is not implicated.

## 7. Laravel Health

`php artisan about` inside `ecos-dev-app`:

- Laravel 12.62.0 / PHP 8.4.24
- Environment `production`, Debug OFF, URL `localhost:8081` — matches `docker-compose.override.yml` by design for the DEV app container
- Config / Events / Routes **CACHED**
- Drivers: Cache `redis`, Database `mysql`, Queue `redis`, Session `redis`

Laravel boots, config loads, database connects, Redis connects, routes load. No migrations were run.

## 8. API Route Check

`php artisan route:list --path=api/auth`:

```
POST      api/auth/login    → Modules\IAM\Presentation\...
POST      api/auth/logout   → Modules\IAM\Presentation\...
GET|HEAD  api/auth/me       → Modules\IAM\Presentation\...
```

The routes the frontend calls **exist and are registered**.

## 9. Login Endpoint Check

Direct against DEV nginx (`http://127.0.0.1:8081/api/auth/login`):

| Request | Response |
|---|---|
| invalid credentials | **401** `{"success":false,"message":"The provided credentials are incorrect.", ...}` |
| empty body | **422** with per-field `errors` |

A real, well-formed authentication response. **This is not an infrastructure failure and not a missing HTTP surface.**

## 10. CORS Check

**CORS is not involved and nothing was changed.**

`VITE_API_URL=/api` makes every API call **same-origin** — origin `http://172.23.80.1:5173` requesting path `/api/...` on that same origin. The browser never performs a cross-origin request, so no preflight and no `Access-Control-*` negotiation occurs. The Vite proxy is the component that crosses the origin boundary, server-side, where CORS does not apply.

## 11. Frontend Error Classification

`frontend/src/features/auth/components/login-form.tsx:110-133`:

```ts
if (!error.response)                  → serverUnavailable   // network failure
else if (status === 429)              → tooManyAttempts
else if (status === 403 || 423)       → accountDisabled
else if (status === 422)              → field error / validation
else if (status >= 500)               → serverUnavailable   // ← this branch fired
else if (data?.message)               → backend message     // 401 lands here
```

Vite returns **502** when its proxy target refuses the connection. 502 satisfies `status >= 500`, so the form displayed `auth.login.error.serverUnavailable`.

**The error classification is correct and was left unchanged.** The message was truthful — from the browser's vantage point the server genuinely was unreachable. Mapping 502 to anything else would have hidden a real infrastructure fault. The defect was the unreachable target, not the label.

## 12. Root Cause

`TASK-ENV-DUAL-STACK-DEV-ISOLATION-001` gave the DEV stack an independent identity and published its nginx on **`127.0.0.1:8081`** (`docker-compose.override.yml:90-91`). `frontend/.env` was updated to document that port. **`frontend/vite.config.ts` was not** — it kept the pre-isolation fallback `http://127.0.0.1:8080`.

Since Vite runs natively on Windows here, `BACKEND_URL` is never set, so that stale fallback is the live value. Nothing listens on 8080, so:

```
browser /api/auth/login
  → Vite proxy → 127.0.0.1:8080 → ECONNREFUSED
  → Vite returns 502 to the browser
  → login-form.tsx status >= 500 → "Server unavailable. Please try again later."
```

A single stale port number, three hops removed from where the symptom appeared.

## 13. Exact Repair

Changed the Vite proxy fallback target from port `8080` to port `8081` for both `/api` and `/storage`, and recorded why in a comment so the next isolation change does not re-strand it.

```ts
target: process.env.BACKEND_URL ?? 'http://127.0.0.1:8081',
```

`BACKEND_URL` still takes precedence, so the in-Docker path (`http://nginx`) is unaffected.

Nothing else was modified. No container was restarted — Vite watches its own config file and restarted itself.

## 14. Files Changed

| File | Change |
|---|---|
| `frontend/vite.config.ts` | 2 functional lines (proxy target `8080` → `8081`), plus 6 comment lines |

`git diff --stat` — 1 file changed, 8 insertions(+), 3 deletions(-).

**No** backend file, IAM file, policy, migration, schema, route, or CORS config was touched.

## 15. Runtime Verification

All checks performed against the real browser-facing URL after the repair:

| # | Check | Result |
|---|---|---|
| 1 | Docker containers healthy | PASS — unchanged, none restarted |
| 2 | Laravel boots | PASS — `artisan about` clean |
| 3 | API endpoint responds | PASS — `172.23.80.1:5173/api/health` → **200**, `database/redis/queue/storage/scheduler` all `true` |
| 4 | Frontend reaches API | PASS — 502 → 200 |
| 5 | Login request reaches Laravel | PASS — browser network log: `POST http://172.23.80.1:5173/api/auth/login → 401 Unauthorized` |
| 6 | Expected auth response | PASS — 401 for bad credentials, 422 with field errors for empty body |
| 7 | No false "Server unavailable" | PASS — login page now displays **"The provided credentials are incorrect."** |
| 8 | `/storage` proxy | PASS — returns 404 *from Laravel* (reached backend) instead of 502 |

Browser verification was performed by loading `http://172.23.80.1:5173/app/login`, submitting a deliberately invalid credential, and reading both the rendered error text and the network log.

## 16. Database Safety

`php artisan db:show` on `ecos-dev-app`:

```
Connection  mysql
Database    ecos_dev      ← correct runtime database
Host        mysql
Tables      1105          Total Size 73.14 MB
```

- Runtime database is **`ecos_dev`**, as required — not `ecos_dev_test`, not `ecos_erp_test`.
- **No migration, seed, reset, or `migrate:fresh` was run.**
- No write of any kind was issued. The only DB traffic was `artisan db:show` and the read-only login lookups Laravel performed for the two probe requests.
- MAIN / `ecos_erp` was never connected to and remains untouched.
- `docker compose down`, `down -v`, and `system prune` were not used.

## 17. Regression Check

- `npx tsc -p tsconfig.node.json --noEmit` (the project that type-checks `vite.config.ts`) → **exit 0**.
- `git diff` scope confirmed to be a single file.
- The in-Docker path is preserved: `BACKEND_URL` still overrides the fallback, so a containerised Vite continues to target `http://nginx`.
- No pre-existing unrelated failures were touched. The uncommitted work in this worktree was not modified.
- `ecos-nginx` (MAIN stack) reports `unhealthy` — this is pre-existing, documented in `docker-compose.override.yml:80-82` (its baked healthcheck probes `https://` against an HTTP-only vhost), unrelated to this task, and out of scope.

## 18. Remaining Blockers

**None for API connectivity.** Frontend ↔ backend is healthy end-to-end.

Notes for follow-up, not blockers:

1. **No credential was successfully authenticated**, because this task was not given a valid DEV account. Verification proved the full request path and the server's authentication *response* contract (401 / 422), which is what "connectivity healthy" requires. Confirming a successful 200 login and session establishment needs a known-good `ecos_dev` user.
2. **The fallback remains a hardcoded constant.** It is correct for this worktree today, but `vite.config.ts` is tracked while the port that makes it correct lives in a gitignored override. A future stack-isolation change can strand it again. Making the dev server read `BACKEND_URL` from a local env file (via `loadEnv`) would remove the class of failure — deliberately out of scope for a minimal repair.
3. `TASK-IAM-HTTP-SURFACE-001` is **not blocked by, and not needed for, this issue.** The IAM HTTP surface (`POST api/auth/login`, `POST api/auth/logout`, `GET api/auth/me`) is present and returning correct responses.
