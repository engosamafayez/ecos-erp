# TASK-TEST-ENV-DEV-PHPUNIT-ENABLE-001 — Engineering Report

**Date:** 2026-08-10
**Type:** Test-environment enablement. No production business logic modified. Nothing committed.
**Verdict:** Section 24.

---

## 1 — Executive Summary — **PASS**

DEV now has a working, isolated PHPUnit runtime. A dedicated engineering test runner
(`ecos-dev-testrunner`) was built from the repository's **existing authorised `engineering-dev` Docker
target** and added as a **separate service** — the production-target `ecos-dev-app` was not rebuilt, replaced
or altered, and MAIN was not touched in any way.

Three results matter:

1. **PHPUnit 11.5.55 runs in DEV** and resolves to `ecos_dev_test` at every layer — Laravel config, the live
   connection, and `SELECT DATABASE()` on the server all agree.
2. **The config-cache hazard is structurally removed for the runner**, not merely worked around. The
   entrypoint is bypassed, so no config cache is ever generated. Verified by restarting the container and
   confirming none reappeared — the one previous mitigation that depended on remembering to run
   `config:clear` is no longer load-bearing for tests.
3. **A real `RefreshDatabase` migration ran against `ecos_dev_test` while MAIN's `ecos_erp_test` sat
   unchanged at 550 tables / 25.50 MB**, and DEV's own cloned `ecos_dev` survived at 551 tables — proving the
   destructive operation landed on the test database and nowhere else.

One real existing Feature test (`ShiftApiTest`, 4/4) executed successfully end to end.

No STOP condition fired.

## 2 — Starting Commit — **PASS**

```
HEAD   : 6149875bd8a01820116b5deacbbfb8ef0e51cc05
branch : develop
```

## 3 — Git State — **PASS**

At start and at completion:

| Scope | Value |
| --- | --- |
| **Production business logic** (`backend/Modules`, `docs/adr`) | **4 files, +185/−18 — UNCHANGED** |
| Total tracked diff | 6 files, +198/−24 |

The 2-file delta beyond production logic is `backend/phpunit.xml` + `backend/tests/TestCase.php`, carried in
from the previously authorised TASK-ENV-DUAL-STACK-DEV-ISOLATION-001 §22 — **this task modified neither.**

The certified F4 + Option B implementation is untouched and byte-identical.

## 4 — MAIN Environment — **PASS (untouched)**

| Property | Value |
| --- | --- |
| Containers | `ecos-app` `ca9790dcb938`, `ecos-nginx` `2c37d4a48631`, `ecos-mysql` `e178b7bb20e2`, `ecos-redis` `b324f7dc7da6`, `ecos-mailpit` `77686673a0a6` |
| StartedAt | all `2026-08-09T19:52:5x` — unchanged throughout |
| Images | `ecos-erp/app:latest` `888321e571c3`, `ecos-erp/nginx:latest` `3cff5ac8577c` |
| Volumes | `ecos-erp_app-storage`, `ecos-erp_mysql-data`, `ecos-erp_redis-data`, `ecos-erp_ecos_node_modules` |
| Databases | `ecos_erp` (551 tables / 39.19 MB), `ecos_erp_test` (550 tables / 25.50 MB) |
| Rows | orders=2 users=3 products=3 companies=4 migrations=698 |

## 5 — DEV Environment — **PASS**

| Property | Value |
| --- | --- |
| Project | `ecos-dev` |
| Containers | `ecos-dev-app`, `ecos-dev-nginx`, `ecos-dev-mysql`, `ecos-dev-redis`, `ecos-dev-mailpit`, **`ecos-dev-testrunner` (new)** |
| Images | `ecos-dev/app` `98f97eda7c78`, `ecos-dev/nginx` `ff6fbdf788ba`, **`ecos-dev/testrunner` `e239ff54a6c6` (new)** |
| Volumes | `ecos-dev_app-storage`, `ecos-dev_mysql-data`, `ecos-dev_redis-data` |
| Databases | `ecos_dev` (551 tables, cloned), `ecos_dev_test` (test target) |

Existing DEV container IDs before and after adding the runner: `47b3ecb27845`, `da8ab3eb7108`,
`229bee67d811`, `4d71e4c8172b`, `b28f832f4363` — **all identical**. Only the new runner was created
(`--no-deps` used deliberately).

## 6 — Database Isolation — **PASS**

Read from the Laravel runtime, not from `.env`:

```
config('app.env')                                = testing
config('database.default')                       = mysql
config('database.connections.mysql.driver')      = mysql
config('database.connections.mysql.database')    = ecos_dev_test
DB::connection()->getDatabaseName()              = ecos_dev_test
SELECT DATABASE()                                = ecos_dev_test      <- server-side agreement
config('database.connections.mysql.host')        = mysql
config('database.connections.mysql.port')        = 3306
```

Reachability from the DEV test connection:

```
SHOW DATABASES -> ecos_dev, ecos_dev_test, information_schema, performance_schema
ecos_erp      : NOT REACHABLE
ecos_erp_test : NOT REACHABLE
```

MAIN side is unchanged and distinct: MAIN's own `phpunit.xml` still declares
`<env name="DB_DATABASE" value="ecos_erp_test" force="true"/>`, and `C:\Projects\ECOS-ERP` reports 0 dirty
entries. The two test databases live on **different MySQL servers, in different containers, on different
volumes, behind different ports** (3306 vs 3316) — logically and physically distinct.

> Note on Part 2's "verify from the MAIN application container": this was verified **statically** from MAIN's
> tracked `phpunit.xml` rather than by executing Laravel inside `ecos-app`, because MAIN's container is also a
> production build without PHPUnit, and booting a testing-env process inside it is outside this task's
> authority. Classified **PASS (static)** — see §23.3.

## 7 — Config Cache — **PASS (hazard structurally removed for the runner)**

The hazard is real and was re-confirmed at source: `docker/php/entrypoint.sh:149` runs
`php artisan config:cache` on **every** container start. A cached config pins `database.*` at boot, after
which `config()` never re-reads `env()` — which silently defeats both `phpunit.xml`'s `force="true"` and
`TestCase::setUp()`'s overrides. It was previously measured pinning `ecos_dev`, the cloned runtime database.

**Fix applied:** the runner sets `entrypoint: [""]` with `command: ["sleep","infinity"]`, so the entrypoint —
and therefore `config:cache` — never executes in that container.

Verified:

```
before any test : no config cache present
smoke test      : app()->configurationIsCached() === false   (asserted, not just observed)
after restart   : no config cache after restart — HAZARD STRUCTURALLY ABSENT
```

This is a structural removal for the **runner**. It is **not** a global fix: `ecos-dev-app` and MAIN's
containers still generate a config cache at start, by design. See §19 and §23.1.

## 8 — `phpunit.xml` — **PASS (guards intact)**

```xml
<env name="DB_CONNECTION" value="mysql" force="true"/>
<env name="DB_DATABASE" value="ecos_dev_test" force="true"/>
<env name="DB_HOST" value="127.0.0.1"/>
<env name="DB_PORT" value="3306"/>
```

`force="true"` is **retained**. `DB_HOST`/`DB_PORT` remain deliberately un-forced so a real environment
variable wins — which is exactly how the runner reaches `mysql:3306` on the DEV network. **Not modified by
this task.**

## 9 — `TestCase.php` — **PASS (guards intact)**

```php
putenv('DB_DATABASE=ecos_dev_test');
$_ENV['DB_DATABASE'] = 'ecos_dev_test';
$_SERVER['DB_DATABASE'] = 'ecos_dev_test';
// Env repository singleton reset via ReflectionProperty — preserved
```

All four protections preserved verbatim: `putenv`, `$_ENV`, `$_SERVER`, and the `Env` singleton reset.
Nothing weakened, nothing removed. **Not modified by this task.**

## 10 — Engineering Docker Target — **PASS (reused, not created)**

An authorised target already existed and was reused per Part 5:

```
docker/php/Dockerfile:367   FROM app AS engineering-dev
```

It layers Node 22, the Composer binary and a full `composer install` **with** dev dependencies on top of the
production `app` stage. Critically it is **additive** — the production stage is not altered, and
`deploy.sh` never references this stage. No production dependency was changed (STOP condition 6 did not
fire).

The runner service was added to `docker-compose.override.yml` (gitignored, local-only), so no tracked Docker
file was modified.

## 11 — PHPUnit Availability — **PASS**

```
/var/www/html/vendor/bin/phpunit  -> EXECUTABLE
PHPUnit 11.5.55 by Sebastian Bergmann and contributors.
PHP 8.4.24 (cli)
Composer version 2.10.2
```

Build: `docker compose -p ecos-dev build test-runner` → exit 0, 156.6 s. Image `ecos-dev/testrunner:latest`
= `e239ff54a6c6`. MAIN images verified unchanged during and after the build.

## 12 — Database Creation — **PASS (no action needed)**

`ecos_dev_test` already existed from the prior task and was **not** recreated. It was confirmed to be a DEV
database (present on `ecos-dev-mysql`, absent from MAIN's server) before use.

`ecos_erp_test` was **not** modified, dropped or truncated — verified identical at 550 tables / 25.50 MB
before and after all execution.

## 13 — Migration Safety — **PASS**

Part 8's precondition was satisfied before any migration ran: the smoke test proved
`config(...) === 'ecos_dev_test'` **first**, and only then was `RefreshDatabase` permitted to execute. No
`migrate:fresh`, `db:wipe` or `RefreshDatabase` was run before the target was proven.

## 14 — Smoke Test — **PASS**

`tests/Feature/DevTestEnvironmentSmokeTest.php` — 3 tests, 18 assertions, 0.256 s.

| Required assertion | Result |
| --- | --- |
| Laravel environment is `testing` | **PASS** |
| Database driver is `mysql` | **PASS** |
| Database name resolves to `ecos_dev_test` | **PASS** (config, live connection *and* `SELECT DATABASE()`) |
| A database connection succeeds | **PASS** |
| Test database is NOT `ecos_erp_test` | **PASS** (also asserted against `ecos_erp` and `ecos_dev`) |
| A harmless `SELECT` executes | **PASS** (`SELECT 1+1` → 2) |

Values are read from the runtime and asserted; no result is hardcoded. The test additionally asserts
`configurationIsCached() === false`, so a regenerated cache would fail the suite loudly rather than silently
redirect it.

## 15 — Write Safety Probe — **PASS**

A `TEMPORARY` table was created, one row written, read back, and the server was asked where the write
landed:

```
DEV TEST ENV write probe: 1 row written and read back in ecos_dev_test
```

Cleanup verified: the probe table has no persistent residue (`information_schema` count = 0). A temporary
table is session-scoped, so nothing survives the connection. MAIN was not written to — it is not reachable
from this connection at all.

## 16 — RefreshDatabase Probe — **PASS**

`tests/Feature/DevTestEnvironmentRefreshTest.php` — 1 test, 6 assertions, 418.5 s.

```
SELECT DATABASE()       = ecos_dev_test
live connection         = ecos_dev_test
tables in ecos_dev_test = 550
migrations applied      = 696
```

Isolation measured **during** the run: `ecos_dev_test` climbed from 0 → 40 → 47 → 550 tables while MAIN's
`ecos_erp_test` held at exactly 550 the entire time.

After the run:

| Database | Result |
| --- | --- |
| DEV `ecos_dev_test` | rebuilt — 550 tables |
| DEV `ecos_dev` (the clone) | **untouched — still 551 tables** |
| MAIN `ecos_erp_test` | **unchanged — 550 tables / 25.50 MB** |
| MAIN `ecos_erp` | **unchanged — 551 tables / 39.19 MB** |

That `ecos_dev` survived is the decisive evidence: the most destructive operation available struck the test
database only, not the runtime database on the very same server.

## 17 — Real Feature Test — **PASS**

`tests/Feature/POS/Api/ShiftApiTest.php` — **4/4 passed**, 9 assertions, 387.1 s, exit 0.

```
✔ Open shift returns 201 with shift data
✔ Open shift validates required fields
✔ Get shift returns shift data
✔ Get shift returns 404 when not found
```

Chosen deliberately: small, `RefreshDatabase`-backed, and neutral — POS is outside Preparation, outside the
Phase 3 set, and untouched by F4/Option B. This proves the whole chain: PHPUnit → Laravel → DEV container →
DEV test DB → application → cleanup.

## 18 — MAIN Control — **PASS**

Compared against the baseline recorded before execution:

| Metric | Baseline | After all execution |
| --- | --- | --- |
| databases | `ecos_erp, ecos_erp_test` | **identical** |
| `ecos_erp` tables / size | 551 / 39.19 MB | **identical** |
| `ecos_erp_test` tables / size | 550 / 25.50 MB | **identical** |
| rows | orders=2 users=3 products=3 companies=4 | **identical** |
| migrations | 698 | **identical** |
| containers | 5 IDs + StartedAt | **identical** |
| images | `888321e571c3`, `3cff5ac8577c` | **identical** |

No unintended MAIN change. No STOP condition fired.

## 19 — Container Restart Behavior — **PASS (verified, scoped honestly)**

Test performed on the runner: restarted (`StartedAt` `02:49:40` → `03:05:15`), then checked for a
regenerated cache.

```
no config cache after restart — HAZARD STRUCTURALLY ABSENT
```

Resolution durability re-verified after the restart: the smoke test passed again, 3/3, still resolving
`ecos_dev_test`.

**Scope of the claim, stated precisely.** The hazard is removed **for `ecos-dev-testrunner` only**, because
its entrypoint is bypassed. `ecos-dev-app` and all MAIN containers still run `config:cache` at start by
design; `ecos-dev-app` was deliberately **not** restarted (unnecessary, and it serves 8081). The hazard is
therefore **not** solved globally, and is not claimed to be.

**Operational rule (now largely automatic for tests):**

1. Start / ensure `ecos-dev-testrunner` is running.
2. Verify the runtime DB target — run `DevTestEnvironmentSmokeTest` (0.3 s); it asserts both the database
   name and the absence of a config cache.
3. Then execute PHPUnit.

Step 2 is a genuine gate, not a formality: it fails loudly if a cache ever appears or the target drifts.

## 20 — Host Environment — **PASS (unchanged, documented)**

`C:\ecos-develop\backend\.env` is **untracked** (`backend/.gitignore:9`), local-only, and was **not modified,
not removed, not committed**.

Current values: `DB_HOST=127.0.0.1`, `DB_PORT=3306`, `DB_DATABASE=ecos_erp_test`.

Host-side safety was verified read-only rather than by execution: **`ecos_dev_test` does not exist in MAIN's
MySQL (count = 0)**. Because `phpunit.xml` now forces `ecos_dev_test`, a host-side run against the default
`127.0.0.1:3306` resolves to a database that is absent there and **fails loudly, writing nothing** — strictly
safer than the previous behaviour, where host runs wrote into MAIN's MySQL volume as `ecos_erp_test`.

**To run the suite from the host against DEV**, export `DB_PORT=3316` (DEV MySQL is published at
`127.0.0.1:3316`). `phpunit.xml` leaves host and port un-forced precisely so a real environment variable
wins. MAIN connection settings were **not** altered to make host tests pass.

## 21 — Changes

| Change | Category | Tracked? |
| --- | --- | --- |
| `docker-compose.override.yml` — added `test-runner` service | engineering test infrastructure | **No** (`.gitignore:23`) |
| `backend/tests/Feature/DevTestEnvironmentSmokeTest.php` | engineering test infrastructure | new, untracked |
| `backend/tests/Feature/DevTestEnvironmentRefreshTest.php` | engineering test infrastructure | new, untracked |
| `docs/verification/TASK-TEST-ENV-DEV-PHPUNIT-ENABLE-001-ENGINEERING-REPORT.md` | documentation | new, untracked |

**Production business logic changes by this task: NONE.** `backend/Modules` and `docs/adr` remain at exactly
4 files, +185/−18 — the pre-existing F4 + Option B work, untouched.

`backend/phpunit.xml` and `backend/tests/TestCase.php` appear as modified in `git status`, but those edits
were made under the prior task's authorisation (TASK-ENV-DUAL-STACK-DEV-ISOLATION-001 §22) and were **not**
altered here.

## 22 — Validation — **PASS**

Per Part 19, only minimal checks scoped to files this task added:

```
php vendor/bin/pint --test tests/Feature/DevTestEnvironmentSmokeTest.php \
                           tests/Feature/DevTestEnvironmentRefreshTest.php
{"tool":"pint","result":"passed"}
```

No new Pint debt. Full Guardian/PHPStan deliberately **not** run — out of scope, and unrelated pre-existing
Guardian failures were not touched. `--no-verify` was not used.

## 23 — Remaining Warnings

### 23.1 — The config-cache hazard persists outside the runner
`ecos-dev-app` and MAIN containers regenerate a config cache on every start (`entrypoint.sh:149`). Harmless
for serving, but **never run a DB-backed test inside `ecos-dev-app`** — it would resolve to the cached
`ecos_dev` (the clone) and `RefreshDatabase` would destroy it. Use `ecos-dev-testrunner`.

### 23.2 — Runner source is baked, not bind-mounted
The runner image contains the worktree as of build time. Source edits require either a rebuild
(`docker compose -p ecos-dev build test-runner`, cached and fast) or `docker cp`. This is deliberate: a bind
mount would shadow the container's Linux `vendor/` with the host's Windows-built one. The two new test files
were `docker cp`'d for this run because they were authored after the build started.

### 23.3 — MAIN runtime DB target verified statically, not by execution
Part 2 asked for runtime verification from the MAIN container. MAIN's image is also a production build with
no PHPUnit, and booting a testing-env process there exceeds this task's authority. MAIN's target was
confirmed from its tracked `phpunit.xml` (`ecos_erp_test`, `force="true"`) plus a clean MAIN repo. Classified
**PASS (static)** rather than PASS (runtime) — the distinction is recorded rather than blurred.

### 23.4 — `APP_KEY` divergence (pre-existing, unchanged)
DEV's `backend/.env` `APP_KEY` matches the running MAIN container but differs from MAIN's repo `.env`. This
is the source of MAIN's `DecryptException: The MAC is invalid` queue errors, and `ecos_dev` inherits it via
the clone. Not introduced or altered here.

### 23.5 — Full suite not run
Per Part 17, no full suite, RC-10, Phase 3, Preparation Batch B or Go-Live certification was executed. Test
**failures were not interpreted**; the two runs performed were green.

## 24 — Final Verdict

| Success criterion | Result |
| --- | --- |
| PHPUnit exists in the engineering DEV runner | **PASS** — 11.5.55 |
| Laravel resolves `DB_DATABASE = ecos_dev_test` | **PASS** — config + live + server-side |
| MAIN remains `ecos_erp_test` | **PASS** — unchanged, `force="true"` intact |
| `RefreshDatabase` affects only `ecos_dev_test` | **PASS** — MAIN and `ecos_dev` both survived |
| One real DB-backed Feature test executes | **PASS** — `ShiftApiTest` 4/4 |
| MAIN remains unchanged | **PASS** — containers, images, volumes, rows all identical |
| No production business logic modified | **PASS** — `backend/Modules` + `docs/adr` at 4 files, +185/−18 |

No STOP condition fired.

# DEV TEST ENVIRONMENT = CERTIFIED

TASK-GOLIVE-PREPARATION-BATCH-B-E2E-CERTIFICATION-001 may now be executed against
`ecos-dev-testrunner`. Not started — this task stops here per the final rule.

## 25 — Attestations

* No production business logic modified. F4, Option B, Reservation, Preparation, Recipe and Inventory
  behaviour untouched — proven by `git diff --stat HEAD -- backend/Modules docs/adr` reporting exactly
  **4 files, +185/−18**.
* `docker/php/Dockerfile` **not** modified; the existing `engineering-dev` target was reused.
* Production Docker target unchanged; no production dependency altered.
* MAIN containers never stopped, restarted or recreated. Only `ecos-dev-testrunner` was restarted.
* MAIN database never written to; `ecos_erp_test` never modified, dropped or truncated.
* No Batch B, no full suite, no Go-Live, no release commit. **Nothing committed.**
