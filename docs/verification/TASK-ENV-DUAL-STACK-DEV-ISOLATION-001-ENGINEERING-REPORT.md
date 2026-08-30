# TASK-ENV-DUAL-STACK-DEV-ISOLATION-001 — Engineering Report

**Date:** 2026-08-10
**Type:** Environment implementation + verification. No business/application code modified. Nothing committed.
**Verdict:** Section 20.

> **Continuation note.** This task was executed in two sessions. Section headings mark each item as
> **[BEFORE]** (completed before the continuation), **[CONT]** (completed during the continuation),
> **[NOT PERFORMED]** or **[BLOCKED]**.

---

## 1 — MAIN topology (preserved, untouched)

| Property | Value |
| --- | --- |
| Folder | `C:\Projects\ECOS-ERP` |
| Branch / HEAD | `platform-foundation` @ `46372413`, clean |
| Compose project | `ecos-erp` |
| Containers | `ecos-app`, `ecos-nginx`, `ecos-mysql`, `ecos-redis`, `ecos-mailpit` |
| Images | `ecos-erp/app:latest` (`888321e571c3`), `ecos-erp/nginx:latest` (`3cff5ac8577c`) |
| Ports | 80, 443, 3306, 6379, 1025, 8025 |
| Frontend | native Vite **PID 9300**, port **5173**, from `C:\Projects\ECOS-ERP\frontend` |
| Database | `ecos_erp`, `ecos_erp_test` |
| Volumes | `ecos-erp_app-storage`, `ecos-erp_mysql-data`, `ecos-erp_redis-data`, `ecos-erp_ecos_node_modules` |
| Backend build | `built_at 2026-08-08T00:04:00Z` |

## 2 — DEV topology (created)

| Property | Value |
| --- | --- |
| Folder | `C:\ecos-develop` |
| Branch / HEAD | `develop` @ `6149875b` |
| Compose project | **`ecos-dev`** |
| Containers | `ecos-dev-app`, `ecos-dev-nginx`, `ecos-dev-mysql`, `ecos-dev-redis`, `ecos-dev-mailpit` |
| Images | **`ecos-dev/app:latest`** (`98f97eda7c78`), **`ecos-dev/nginx:latest`** (`ff6fbdf788ba`) |
| Ports | 8081, 3316, 6381, 1026, 8026 |
| Frontend | native Vite **PID 952**, port **5174**, from `C:\ecos-develop\frontend` |
| Database | `ecos_dev`, `ecos_dev_test` |
| Volumes | `ecos-dev_app-storage`, `ecos-dev_mysql-data`, `ecos-dev_redis-data` |
| Network | `ecos-dev_ecos-network` |
| Backend build | `built_at 2026-08-10T00:05:15Z` |
| URLs | Frontend `http://localhost:5174/app/` · Backend `http://127.0.0.1:8081` · API `http://127.0.0.1:8081/api` |

## 3 — Containers **[BEFORE]**

All five DEV containers healthy. Note DEV nginx reports **healthy** while MAIN's reports *unhealthy* — MAIN's
baked healthcheck probes `https://127.0.0.1/api/health`, which cannot pass against an HTTP-only local vhost.
The DEV override re-points it at `http://`, which is why DEV's is green. MAIN's flag is cosmetic and
pre-existing; it was not touched.

## 4 — Images **[BEFORE]** — ISOLATED

```
ecos-dev/app:latest     98f97eda7c78   2026-08-10 03:05:15   <- new
ecos-dev/nginx:latest   ff6fbdf788ba   2026-08-10 03:05:18   <- new
ecos-erp/app:latest     888321e571c3   2026-08-08 03:25:21   <- UNCHANGED
ecos-erp/nginx:latest   3cff5ac8577c   2026-08-08 03:25:29   <- UNCHANGED
```

Build: `docker compose -p ecos-dev build --no-cache app nginx` → exit 0, 234.6 s. MAIN image IDs identical to
the pre-build baseline. This was verified **before** any container was started, because an image overwrite is
silent and only cheaply reversible at that point.

## 5 — Ports — NO OVERLAP

| Service | MAIN | DEV |
| --- | --- | --- |
| nginx | 80, 443 | **127.0.0.1:8081** |
| mysql | 127.0.0.1:3306 | **127.0.0.1:3316** |
| redis | 127.0.0.1:6379 | **127.0.0.1:6381** |
| mailpit | 1025 / 8025 | **1026 / 8026** |
| frontend | 5173 | **5174** |

The rendered DEV config publishes exactly `1026, 8026, 3316, 8081, 6381` — no MAIN port appears.

> **Design detail that mattered.** Compose *merges* sequences, so a plain `ports:` entry would have **appended**
> to the base list and DEV would have tried to bind `:80`/`:443`, colliding head-on with MAIN. The override uses
> the `!override` tag (Compose ≥ 2.24; host runs **v5.3.1**) to replace the lists instead. Same for the volume
> lists carrying the SSL and init-script mounts.

## 6 — Volumes — ISOLATED

```
ecos-dev_app-storage   ecos-dev_mysql-data   ecos-dev_redis-data          <- DEV
ecos-erp_app-storage   ecos-erp_mysql-data   ecos-erp_redis-data
ecos-erp_ecos_node_modules                                                <- MAIN, untouched
```

Project-name prefixing guarantees separation. MAIN's four volumes were never mounted by a DEV container.

## 7 — Networks

`ecos-dev_ecos-network` (bridge), created fresh. DEV's `mysql` hostname resolves to `172.20.0.4` **inside the
DEV network only**; it cannot reach MAIN's MySQL.

## 8 — Database isolation — VERIFIED

| Endpoint | Databases |
| --- | --- |
| MAIN `127.0.0.1:3306` | `ecos_erp`, `ecos_erp_test` |
| DEV `127.0.0.1:3316` | `ecos_dev`, `ecos_dev_test` |

`SELECT COUNT(*) … WHERE schema_name='ecos_erp'` on the DEV server returns **0** — the MAIN database name does
not exist in DEV at all. DEV Laravel resolves `db.name=ecos_dev`, `db.host=mysql`.

The base `docker/mysql/init/01_create_test_db.sql` hardcodes `CREATE DATABASE ecos_erp_test`; the override
**drops that init mount** deliberately so no database bearing a MAIN name is created inside the DEV server.
`ecos_dev_test` was created explicitly instead.

## 9 — MAIN baseline BEFORE

```
databases : ecos_erp, ecos_erp_test
ecos_erp  : tables=551   size_MB=39.19
rows      : orders=2  users=3  products=3  companies=4  migrations=698
containers: ecos-app ca9790dcb938 / ecos-nginx 2c37d4a48631 / ecos-mysql e178b7bb20e2
            ecos-redis b324f7dc7da6 / ecos-mailpit 77686673a0a6
```

## 10 — MAIN baseline AFTER **[CONT]** — IDENTICAL

```
databases : ecos_erp, ecos_erp_test
ecos_erp  : tables=551   size_MB=39.19
rows      : orders=2  users=3  products=3  companies=4  migrations=698
containers: all five IDs and StartedAt timestamps UNCHANGED
images    : ecos-erp/app 888321e571c3, ecos-erp/nginx 3cff5ac8577c UNCHANGED
MAIN Vite : PID 9300 still listening on 5173
```

**MAIN was not stopped, recreated, modified or written to at any point.**

## 11 — Clone verification **[CONT]** — PASS

Direction: **`ecos_erp` → `ecos_dev`**, one-way, read-only at source.

* Dump: `mysqldump --single-transaction --no-tablespaces --routines --triggers` executed against
  `ecos-mysql`, streamed to the host. `--single-transaction` takes no locks and performs no writes.
  Exit 0, **197,121 bytes**.
* Integrity gate **before** import: **551 `CREATE TABLE`** statements (matching MAIN's 551 tables), 93
  `INSERT` statements, `Dump completed` footer present. The modest byte size is explained — the schema is
  large but the data is small; the 39 MB figure is mostly InnoDB page/index allocation, not rows.
* Import: into **`ecos_dev` only**, exit 0.

| Metric | MAIN `ecos_erp` | DEV `ecos_dev` |
| --- | --- | --- |
| tables | 551 | **551** |
| orders | 2 | **2** |
| users | 3 | **3** |
| products | 3 | **3** |
| companies | 4 | **4** |
| migrations | 698 | **698** |

`ecos_dev_test` remains at 0 tables (clean, isolated). MAIN re-verified unchanged immediately after import.

## 12 — DEV frontend verification **[CONT]** — PASS

`http://localhost:5174/app/` → **HTTP 200**. Vite v8.0.16, PID 952, started from `C:\ecos-develop\frontend`
with `--port 5174` on the command line. **`vite.config.ts` was not modified.**

**Code-source proof (decisive).** Status codes alone are useless here because Vite's SPA fallback returns 200
with `index.html` for unknown paths. Comparing *content type* against files unique to each repository:

| Module | :5174 (DEV) | :5173 (MAIN) |
| --- | --- | --- |
| `src/features/finance/components/finance-panels.tsx` (**DEV-only**) | `text/javascript` — **module served** | `text/html` — fallback |
| `src/components/layout/header/search/search-command-dialog.tsx` (**MAIN-only**) | `text/html` — fallback | `text/javascript` — **module served** |

Each dev server serves **only** its own worktree.

**Backend provenance** corroborates independently: DEV `/api/health` reports `built_at 2026-08-10T00:05:15Z`
(today's build from `C:\ecos-develop`) versus MAIN's `2026-08-08T00:04:00Z`.

## 13 — DEV API verification **[CONT]** — PASS

`http://127.0.0.1:8081/api/health` → **HTTP 200**:

```json
{"status":"ok","database":true,"redis":true,"queue":true,"storage":true,
 "scheduler":true,"queue_workers":3,"built_at":"2026-08-10T00:05:15Z"}
```

Database, Redis, queue, storage and scheduler all report healthy against DEV's own services.

## 14 — DEV Vite proxy verification **[CONT]** — PASS

`http://127.0.0.1:5174/api/health` → **HTTP 200**, returning the same payload — **not 502**.

This is the exact failure that opened this whole investigation: MAIN's Vite proxies `/api` to
`process.env.BACKEND_URL ?? 'http://127.0.0.1:8080'`, and with no `BACKEND_URL` on the host and nothing
published on 8080, every API call 502'd. DEV supplies `BACKEND_URL=http://127.0.0.1:8081` at launch, so the
chain browser → Vite → nginx → PHP-FPM → MySQL is closed end to end.

## 15 — Login connectivity **[CONT]** — VERIFIED, no credentials used

| Request | Result |
| --- | --- |
| `GET /api/auth/login` via :5174 | **405** — route registered, wrong verb |
| `POST /api/auth/login` via :5174 (`Accept: application/json`, empty body) | **422 Unprocessable Content** |
| `POST /api/auth/login` direct to :8081 | **422 Unprocessable Content** |

422 proves the request traversed Vite → nginx → Laravel and reached **validation**. Rate-limit headers
(`x-ratelimit-limit: 10`) confirm the real middleware stack ran.

> A first probe without `Accept: application/json` returned 302 — that was the omitted header causing Laravel's
> HTML redirect, not a fault. Recorded because the raw 302 could otherwise be mistaken for a defect.

**LOGIN CONNECTIVITY VERIFIED — CREDENTIAL TEST NOT PERFORMED** (no credentials guessed, created or modified).

## 16 — Git status **[CONT]** — worktree preserved

```
HEAD   : 6149875bd8a01820116b5deacbbfb8ef0e51cc05     (unchanged)
branch : develop
diff   : 6 files changed, 198 insertions(+), 24 deletions(-)
```

Breakdown — the certified F4 + Option B work is provably untouched:

```
git diff --stat HEAD -- backend/Modules docs/adr
  4 files changed, 185 insertions(+), 18 deletions(-)      <- IDENTICAL to the original baseline
```

The delta (+13 / −6) is confined to the two test-config files authorised in §22:
`backend/phpunit.xml` and `backend/tests/TestCase.php`.

All prior certification work is intact: the 4 modified files, the 3 Recipe test files, the 2 Preparation E2E
test files and every verification report. **Nothing was committed.**

The two files created by this task — `docker-compose.override.yml` and `frontend/.env` — do **not** appear in
`git status`: both are gitignored (`.gitignore:23` and `.gitignore:16`).

## 17 — Warnings

1. **`APP_KEY` divergence (pre-existing).** DEV's `backend/.env` carries `base64:LjkRy…`, which matches the
   *running* MAIN container — but MAIN's own repo `.env` carries a **different** key (`base64:5DfL…`). This
   explains the 58 `DecryptException: The MAC is invalid` errors in MAIN's queue log: payloads encrypted under
   one key are being decrypted under another. Since `ecos_dev` is a clone of `ecos_erp`, **any encrypted column
   in the cloned data inherits the same mismatch.** Not introduced by this task; no key was changed.
2. **Host-side test tooling still points at MAIN's MySQL.** `backend/.env` has `DB_HOST=127.0.0.1`,
   `DB_PORT=3306`, `DB_DATABASE=ecos_erp_test` — that is **MAIN's MySQL container**. Every prior certification
   run used it. It does not touch `ecos_erp`, but it does write inside MAIN's MySQL volume. Left as found
   (modifying `backend/.env` is prohibited by this task).
3. **MAIN's Vite remains broken** — still proxying to the dead `:8080`. Untouched by explicit instruction.
4. **DEV app config was cached** (`configurationIsCached() = YES`) pinning `mysql.database = ecos_dev`. This
   was **cleared** in §22.2 because it silently defeats the forced test database. See §17.5.

### 17.5 — WARNING (operational): a regenerated config cache defeats the test-DB force

`bootstrap/cache/config.php` pins `database.*` at boot; once present, `config()` never re-reads `env()`, so
**both** `phpunit.xml`'s `force="true"` and `TestCase::setUp()`'s triple override become inert. The DEV
container's entrypoint generates that cache at start — it was found pinning **`ecos_dev`**, the cloned
database. Had a suite run in that state, `RefreshDatabase` would have wiped the clone.

The cache is currently cleared and resolution verified. **Any future `php artisan config:cache` (or a
container restart that regenerates it) reintroduces the hazard.** Rule: run `php artisan config:clear` inside
`ecos-dev-app` immediately before any test execution. A note to this effect was added to `phpunit.xml`.

This cannot reach MAIN under any circumstance — DEV's MySQL contains no `ecos_erp`.

### 17.6 — WARNING: PHPUnit is absent from `ecos-dev-app`

`ecos-dev-app` is built from the **production `app` target** (`composer install --no-dev`), so
`vendor/phpunit` does not exist and `vendor/bin` carries only carbon, php-parse, psysh and var-dump-server.
**No test suite can currently execute inside the DEV container.** Obtaining one requires building the
`engineering-dev` target (as MAIN's override does) — a rebuild, explicitly forbidden by this task.

Consequence: the requirement *"PHPUnit execution used for DEV certification must run inside `ecos-dev-app`"*
is **not yet operationally achievable**. The database-target resolution it depends on is proven correct
(§22.3), but the runner itself must be provisioned by a separate authorised task.

### 17.7 — WARNING: host-side `backend/.env` still points at MAIN's MySQL

`C:\ecos-develop\backend\.env` carries `DB_HOST=127.0.0.1`, `DB_PORT=3306` — **MAIN's MySQL container**. The
file is **untracked** (`backend/.gitignore:9`), so it is local-only and cannot propagate to MAIN or to the
repository; it was left unmodified per instruction (no scope expansion).

Net effect of §22 on this risk is an **improvement**: a host-side run now resolves to `ecos_dev_test`, which
does **not** exist in MAIN's MySQL, so it fails loudly instead of silently writing into MAIN's MySQL volume as
`ecos_erp_test` (which is what every prior certification run did). To run the suite from the host against DEV,
export `DB_HOST=127.0.0.1` and `DB_PORT=3316` — `phpunit.xml` leaves both un-forced precisely so a real
environment variable wins. **Recommended, not performed:** repoint `backend/.env` to `3316`.

## 18 — Deviations from the approved design

1. **MySQL init mount dropped for DEV** (not in the original design text). Necessary: the tracked init script
   hardcodes `CREATE DATABASE ecos_erp_test`, which would have placed a MAIN-named database inside the DEV
   server. `ecos_dev_test` is created explicitly instead. Strengthens isolation.
2. **`name: ecos-dev` set inside the override**, in addition to passing `-p ecos-dev`. Without it, a bare
   `docker compose up` in `C:\ecos-develop` would resolve to project `ecos-erp` and mount **MAIN's volumes**.
   Defence in depth.
3. **`!override` tags** on `ports`/`volumes` — required, not cosmetic (see §5).

## 19 — Not performed / blocked

| Item | Status | Reason |
| --- | --- | --- |
| MAIN repair (restore its Vite proxy) | **NOT PERFORMED** | Explicitly deferred. Requires either recreating MAIN's stack (destructive) or restarting PID 9300. Command prepared, not executed. |
| DEV `php artisan migrate` | **NOT PERFORMED** | Unnecessary — the clone delivered all 551 tables and all 698 migration rows. |
| DEV test-suite execution | **NOT PERFORMED** | Deliberately not run — see §22.4. |
| **DEV test-DB name resolution** | **RESOLVED in §22** | Was BLOCKED; authorised and corrected. |

### 19.1 — STOP CONDITION (fired, then authorised and RESOLVED — see §22)

Required: DEV test configuration must resolve to `ecos_dev_test`, never `ecos_erp_test`.
**Actual:** it resolves to `ecos_erp_test`, and this cannot be corrected within this task's rules.

Evidence, read from inside `ecos-dev-app`:

```
phpunit.xml            <env name="DB_DATABASE" value="ecos_erp_test" force="true"/>
tests/TestCase.php:33  putenv('DB_DATABASE=ecos_erp_test');
tests/TestCase.php:34  $_ENV['DB_DATABASE']    = 'ecos_erp_test';
tests/TestCase.php:35  $_SERVER['DB_DATABASE'] = 'ecos_erp_test';
```

Both files are **tracked**. `force="true"` and the triple `putenv`/`$_ENV`/`$_SERVER` override defeat every
environment-level redirection, by design — that hardening was added deliberately to stop a test run from
escaping onto the wrong database. Redirecting it to `ecos_dev_test` requires editing tracked files, which is
the explicit STOP condition *"tracked DEV production code must be modified to make the environment work."*

**Containment (why this is not a live danger):** `ecos_erp_test` does **not exist** in the DEV MySQL server
(verified, count = 0), and `ecos-dev-app` can only reach `ecos-dev-mysql` over `ecos-dev_ecos-network`. A test
run inside the DEV container therefore **fails loudly** rather than silently writing anywhere. It cannot reach
MAIN. `ecos_dev_test` exists and is isolated and empty, ready for use once the naming is authorised.

Options presented at the time: (a) authorise a minimal change to `phpunit.xml` + `tests/TestCase.php`;
(b) create `ecos_erp_test` inside DEV's MySQL and accept the confusing name; (c) accept as-is.

**Outcome: option (a) was authorised and executed. See §22.**

## 20 — Verdict

Every infrastructure objective was met and verified: independent projects, containers, images, volumes,
networks, ports, frontends and databases; a verified one-way clone; MAIN provably untouched at container,
image, volume and row level; and both environments serving simultaneously from their own source trees.

The sole outstanding blocker — DEV test-database resolution (§19.1) — was authorised as a scoped correction
and is now **resolved and verified inside `ecos-dev-app`** (§22): the effective target is `ecos_dev_test`, and
neither `ecos_erp` nor `ecos_erp_test` is reachable from the DEV connection.

All three certification conditions hold:

| Condition | Result |
| --- | --- |
| DEV test DB resolves to `ecos_dev_test` | **YES** — verified live (§22.3) |
| MAIN remains untouched | **YES** — containers, images, volumes, DB all identical to baseline |
| All previous verification remains valid | **YES** — re-run after the change (§22.5) |

# DEVIRONMENT DUAL-STACK CERTIFIED

Certified with two recorded operational warnings (§17.5, §17.6) which constrain *how* DEV tests must be run.
Neither affects isolation, and neither can reach MAIN.

## 21 — Attestations

* No business/application code modified. F4, Option B, Reservation, Preparation, Orders, Inventory and all
  existing certification changes are untouched and byte-identical — proven by
  `git diff --stat HEAD -- backend/Modules docs/adr` still reporting exactly **4 files, +185/−18**.
* The only tracked files changed are `backend/phpunit.xml` and `backend/tests/TestCase.php`, under the
  explicit authorisation recorded in §22. Both are test configuration, not production logic.
* `C:\Projects\ECOS-ERP` not modified in any way.
* MAIN containers never stopped or recreated; all five IDs and StartedAt timestamps unchanged.
* `ecos_erp` never written to — 551 tables / 39.19 MB / identical row counts before and after.
* No `docker compose` command run without `-p ecos-dev`; no `down -v`; no `volume prune`; no `system prune`.
* No MAIN image overwritten; no DEV container adopted a MAIN container.
* Data flowed **only** `ecos_erp → ecos_dev`. Never the reverse.
* No commit, reset, clean, stash, checkout, pull or merge.
* Only two files created, both gitignored: `docker-compose.override.yml`, `frontend/.env`.

---

## 22 — Final blocker correction **[AUTHORISED CONTINUATION]**

Scope authorised: the minimal DEV test-environment correction only. No rebuild, reclone, restart, recreate or
Docker infrastructure change was performed.

### 22.1 — The minimal change

Four literals and two comments, in two tracked files. **The protection mechanism itself is unchanged** —
`force="true"` is retained, and the triple `putenv` / `$_ENV` / `$_SERVER` override plus the `Env` repository
reset are all preserved verbatim. Only the *target name* changed.

```diff
backend/phpunit.xml
-        <env name="DB_DATABASE" value="ecos_erp_test" force="true"/>
+        <env name="DB_DATABASE" value="ecos_dev_test" force="true"/>

backend/tests/TestCase.php
-        putenv('DB_DATABASE=ecos_erp_test');
-        $_ENV['DB_DATABASE'] = 'ecos_erp_test';
-        $_SERVER['DB_DATABASE'] = 'ecos_erp_test';
+        putenv('DB_DATABASE=ecos_dev_test');
+        $_ENV['DB_DATABASE'] = 'ecos_dev_test';
+        $_SERVER['DB_DATABASE'] = 'ecos_dev_test';
```

Comments were amended for accuracy (the old text named `ecos_erp` as the database being protected; in this
worktree the runtime database is `ecos_dev`), and a note recording the config-cache hazard of §17.5 was added.

`ecos_erp_test` was **not** created inside DEV MySQL. MAIN's test configuration was **not** touched — its
`phpunit.xml` still reads `value="ecos_erp_test"`, and `C:\Projects\ECOS-ERP` reports **0** dirty entries.

### 22.2 — Container sync and the cache clear

Source is baked into the image (only `storage` is a volume), so the two files were placed into the running
container with `docker cp` — no rebuild, no restart, no recreate. `ecos-dev-app` uptime was continuous.

The config cache was then cleared inside `ecos-dev-app` only. This was **mandatory**: it pinned
`mysql.database = ecos_dev`, which would have overridden the forced test database entirely (§17.5). The DEV
app's effective runtime values are unchanged by the clear, because the compose `environment:` block supplies
the identical values live.

### 22.3 — Verification INSIDE `ecos-dev-app`

Performed with a read-only probe that reproduces the exact PHPUnit bootstrap sequence (apply `phpunit.xml`'s
forced env, apply `TestCase::setUp()`'s triple override, reset the `Env` singleton, boot Laravel, read the
effective configuration and open a real connection). It creates, migrates and drops nothing.

```
phpunit.xml forces DB_DATABASE = 'ecos_dev_test'
config cached           = no
effective app.env       = testing
effective db.host       = mysql
effective db.port       = 3306
effective DB_DATABASE   = ecos_dev_test
LIVE connection db      = ecos_dev_test
databases reachable     = ecos_dev, ecos_dev_test, information_schema, performance_schema
ecos_erp reachable      = NO
ecos_erp_test reachable = NO

RefreshDatabase would target: ecos_dev_test
RESULT: PASS — DEV tests resolve to ecos_dev_test
```

| Required check | Result |
| --- | --- |
| effective `DB_DATABASE` = `ecos_dev_test` | **PASS** |
| `DB_HOST` = `mysql` | **PASS** |
| database connection succeeds | **PASS** (live connection opened) |
| `RefreshDatabase` would target `ecos_dev_test` | **PASS** |
| `ecos_erp` not reachable through the DEV connection | **PASS** — absent from `SHOW DATABASES` |

### 22.4 — Full suite deliberately not run

Per instruction, only the minimal database-target verification was performed. Independently, the suite
*could* not have run inside the container anyway — PHPUnit is absent from the production-target image
(§17.6).

### 22.5 — Post-change regression checks

| Check | Result |
| --- | --- |
| DEV frontend `localhost:5174/app/` | **200** |
| DEV API `127.0.0.1:8081/api/health` | **200** |
| DEV Vite proxy `127.0.0.1:5174/api/health` | **200** |
| DEV containers | all 5 healthy, uptime continuous (not restarted) |
| MAIN containers | all 5 IDs + StartedAt **UNCHANGED** |
| MAIN database | 551 tables, 39.19 MB, orders=2 users=3 products=3 companies=4 migrations=698 — **identical** |
| MAIN databases list | `ecos_erp, ecos_erp_test` — unchanged |
| MAIN repo `C:\Projects\ECOS-ERP` | 0 dirty entries |
| F4 / Option B / ADR diff | exactly 4 files, +185/−18 — **unchanged** |
