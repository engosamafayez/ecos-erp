# TASK-IAM-PRECONDITION-TEST-ENV-UNBLOCK-001 — Engineering Report

**Date:** 2026-08-10
**Status:** **STOPPED at Part 1.** Read-only survey completed; no file, container, config or database was modified.
**Verdict:** see Section 21.

---

## 1 — Executive Summary

The isolated DEV test environment **appears to be already built and correctly wired** by a concurrent task.
Every *non-destructive* check in Part 18 passes: the runner exists, PHPUnit executes, there is no config
cache, and the runner resolves `SELECT DATABASE() = ecos_dev_test`.

However, **Part 1's concurrent-worktree gate fails outright**, which makes the certifying probes
(Parts 7–10) unsafe to execute. A second agent is **actively writing production code in this worktree right
now** — `PreparationWaveController.php` was modified at **07:17:52**, twenty-one seconds before the check at
07:18:13 — and it owns the two tasks that are this task's exact subject matter
(`TASK-ENV-DUAL-STACK-DEV-ISOLATION-001`, `TASK-TEST-ENV-DEV-PHPUNIT-ENABLE-001`).

Parts 7, 8, 9 and 10 are inherently destructive: they require `RefreshDatabase` (which wipes
`ecos_dev_test`), a write probe, a container restart, and a real feature test. Running any of them while
another agent may be executing its own suites against the **same** `ecos_dev_test` would produce exactly the
cross-process contention that invalidated hours of work in the preceding Preparation certification.

Per Part 1 — *"If another active task is modifying the same environment: DO NOT overwrite its work. STOP and
report"* — and Part 19 STOP conditions **1** and **12**, execution stopped. Nothing was reverted, overwritten
or worked around.

**6 of 14 Part 18 conditions pass, 1 fails, 7 could not be executed.** The environment is therefore
**NOT UNBLOCKED** — not because it is broken, but because it cannot be *proven* safe under live concurrency.
Part 18's closing rule is explicit: do not certify from static configuration alone.

---

## 2 — Starting Commit

```
HEAD    6149875bd8a01820116b5deacbbfb8ef0e51cc05
branch  develop
repo    C:\ecos-develop
```

---

## 3 — Worktree State

**31 entries** at start of this task (was 21 at 00:45, 28 at 02:00 — growing continuously without my
involvement). Files modified by the concurrent agent that are **in scope for this task**:

```
 M backend/phpunit.xml                                     <-- test harness
 M backend/tests/TestCase.php                              <-- test harness
 M backend/Modules/Operations/Preparation/Domain/Models/PreparationSessionPolicy.php
 M backend/Modules/Operations/Preparation/Presentation/Http/Controllers/PreparationWaveController.php
?? backend/tests/Feature/DevTestEnvironmentRefreshTest.php
?? backend/tests/Feature/DevTestEnvironmentSmokeTest.php
?? docs/verification/TASK-ENV-DUAL-STACK-DEV-ISOLATION-001-ENGINEERING-REPORT.md
?? docs/verification/TASK-TEST-ENV-DEV-PHPUNIT-ENABLE-001-ENGINEERING-REPORT.md
```

This task added exactly one file: this report. **Nothing else was created, modified, reverted or deleted.**

---

## 4 — Concurrent Process Check (Part 1) — **FAIL**

Write timestamps observed, against a clock reading of **07:18:13**:

| File | Last written | Age |
| --- | --- | --- |
| `Modules/Operations/Preparation/Presentation/Http/Controllers/PreparationWaveController.php` | **07:17:52** | **21 s** |
| `Modules/Operations/Preparation/Domain/Models/PreparationSessionPolicy.php` | **07:16:42** | **91 s** |
| `docs/verification/TASK-GOLIVE-PREPARATION-BATCH-B-FINAL-RUNTIME-CERTIFICATION-001-…md` | 06:52:39 | 26 m |
| `docs/verification/TASK-TEST-ENV-DEV-PHPUNIT-ENABLE-001-…md` | 06:07:39 | 71 m |
| `backend/tests/Feature/DevTestEnvironmentRefreshTest.php` | 05:47:44 | 91 m |
| `backend/tests/Feature/DevTestEnvironmentSmokeTest.php` | 05:47:17 | 91 m |
| `backend/phpunit.xml` | 05:34:30 | 104 m |
| `backend/tests/TestCase.php` | 05:34:19 | 104 m |

**Conclusion: another task is live.** The harness files themselves settled ~104 minutes ago, but the agent is
demonstrably still running and still writing production code. Its two environment reports
(`TASK-ENV-DUAL-STACK-DEV-ISOLATION-001`, `TASK-TEST-ENV-DEV-PHPUNIT-ENABLE-001`) show it **owns this exact
environment work**. Any change I made to `phpunit.xml`, `.env` or the compose files would collide with an
in-flight task.

No host PHP process was running at check time (`tasklist` → none), and DB connection counts were
`ecos-dev-mysql = 0`, `ecos-mysql = 1`. So the agent was idle *between* operations, not mid-suite — but an
idle moment is not a guarantee, and it resumed writing during this survey.

---

## 5 — Environment Topology (Part 2/3) — surveyed, read-only

Two complete, independent stacks:

| | MAIN stack | DEV stack |
| --- | --- | --- |
| App | `ecos-app` | `ecos-dev-app` |
| MySQL container | `ecos-mysql` | `ecos-dev-mysql` |
| Host port | **127.0.0.1:3306** | **127.0.0.1:3316** |
| Runtime DB | `ecos_erp` | `ecos_dev` |
| Test DB | `ecos_erp_test` | `ecos_dev_test` |
| Test runner | — | **`ecos-dev-testrunner`** |

All four databases are distinct and all four exist. The intended topology from Part 3
(`Test Runner → ecos-dev-mysql → ecos_dev_test`) **is in place**.

---

## 6 — PHPUnit Configuration

`backend/phpunit.xml` (as modified by the concurrent task):

```xml
<env name="DB_CONNECTION" value="mysql" force="true"/>
<env name="DB_DATABASE"   value="ecos_dev_test" force="true"/>
<env name="DB_HOST"       value="127.0.0.1"/>   <!-- NOT forced -->
<env name="DB_PORT"       value="3306"/>        <!-- NOT forced -->
```

`backend/tests/TestCase.php` forces `DB_DATABASE=ecos_dev_test` across `putenv`/`$_ENV`/`$_SERVER` and resets
the `Env` repository singleton.

---

## 7 — Laravel Configuration (host)

`backend/.env` **and** `backend/.env.testing` are identical on the relevant keys and still point at the
**MAIN** stack:

```
APP_ENV=testing
DB_HOST=127.0.0.1
DB_PORT=3306          <-- MAIN stack (ecos-mysql)
DB_DATABASE=ecos_erp_test
```

---

## 8 — Config Cache Analysis (Part 4)

| Location | `bootstrap/cache/config.php` | `configurationIsCached()` |
| --- | --- | --- |
| Host worktree | **absent** | n/a |
| `ecos-dev-testrunner` | **absent** | **NO** (verified at runtime) |

**No config-cache hazard is present in the test runner.** Nothing was cleared — there was nothing to clear.

---

## 9 — Test Runner (Parts 5/6)

Defined in `docker-compose.override.yml:154`, and it is a **purpose-built, isolated engineering runner** —
correctly reused rather than duplicated:

```yaml
image: ecos-dev/testrunner:latest
container_name: ecos-dev-testrunner
restart: "no"
entrypoint: [""]
command: ["sleep", "infinity"]
env_file: [./backend/.env]
environment:
  APP_ENV: testing
  DB_CONNECTION: mysql
  DB_HOST: mysql            # container-network → ecos-dev-mysql
  DB_PORT: "3306"           # container-internal port
  DB_DATABASE: ecos_dev_test
```

The `environment:` block overrides the inherited `env_file`, so the MAIN-pointing `.env` values are
neutralised inside the container. Status: `Up About an hour`. No production image was altered and
`deploy.sh` was not touched.

---

## 10 — PHPUnit Availability (Part 13) — **PASS**

```
$ docker exec ecos-dev-testrunner php -v
PHP 8.4.24 (cli) (built: Aug 5 2026 00:31:42) (NTS)

$ docker exec ecos-dev-testrunner php vendor/bin/phpunit --version
PHPUnit 11.5.55 by Sebastian Bergmann and contributors.
```

Note: runner PHP is **8.4.24**; the host is **8.4.22**. Same minor version, different patch — recorded, not
a blocker.

---

## 11 — DB Identity Verification (Part 18.4/18.5) — **PASS**

Non-destructive probe executed inside the runner (`SELECT` only, no writes):

```
env    = testing
cfg    = ecos_dev_test
cached = NO
SELECT DATABASE() = ecos_dev_test
```

All four required identities confirmed distinct and correct.

---

## 12 — RefreshDatabase Probe (Part 7) — **NOT EXECUTED**

Destructive by definition. Blocked by the Part 1 failure. **No claim is made.**

## 13 — Write Probe (Part 8) — **NOT EXECUTED**

Blocked by the Part 1 failure. **No claim is made.**

---

## 14 — DEV Runtime Control (Part 12)

Baseline recorded read-only. **No "after" reading exists**, because no probe was run.

| Database | Tables |
| --- | --- |
| `ecos_dev` | **551** |
| `ecos_dev_test` | **550** |

---

## 15 — MAIN Runtime Control (Part 12)

| Database | Tables |
| --- | --- |
| `ecos_erp` | **551** |
| `ecos_erp_test` | **550** |

**MAIN was not touched by this task** — no write, no migration, no `RefreshDatabase`, no config change. The
only MAIN access was read-only `information_schema` counting. Since no destructive probe ran, MAIN safety is
**trivially preserved but not affirmatively proven** in the Part 7 sense.

---

## 16 — Restart Hazard (Part 10) — **NOT EXECUTED**

Restarting `ecos-dev-testrunner` would disturb a container belonging to an active concurrent task. Not
attempted. **No claim is made.**

Static reading only (not certification): `entrypoint: [""]` with `command: ["sleep","infinity"]` means the
image entrypoint is suppressed, so there is no obvious script that could regenerate a config cache on
restart. This must still be proven by an actual restart before certification.

---

## 17 — Real Feature Test (Part 9) — **NOT EXECUTED**

Requires `RefreshDatabase`. Blocked. **No claim is made.**

---

## 18 — Isolation Evidence — Part 18 scorecard

| # | Condition | Result |
| --- | --- | --- |
| 1 | Test runner exists | **PASS** |
| 2 | PHPUnit executes | **PASS** — 11.5.55 |
| 3 | PHP version correct | **PASS** — 8.4.24 |
| 4 | DB resolves to `ecos_dev_test` | **PASS** |
| 5 | `SELECT DATABASE()` = `ecos_dev_test` | **PASS** |
| 6 | `configurationIsCached()` = false | **PASS** |
| 7 | RefreshDatabase affects only `ecos_dev_test` | **NOT EXECUTED** |
| 8 | `ecos_dev` unchanged | **NOT PROVEN** (baseline only) |
| 9 | `ecos_erp` unchanged | **NOT PROVEN** (baseline only) |
| 10 | `ecos_erp_test` unchanged | **NOT PROVEN** (baseline only) |
| 11 | Write probe isolated | **NOT EXECUTED** |
| 12 | Restart does not redirect test DB | **NOT EXECUTED** |
| 13 | Real feature test passes | **NOT EXECUTED** |
| 14 | No concurrent worktree conflict | **FAIL** |

**6 PASS · 1 FAIL · 7 NOT EXECUTED.**

---

## 19 — Remaining Environment Risks

### 19.1 — Host-side execution is fail-safe only by accident (Part 11)

`phpunit.xml` forces the database **name** but **not** the host/port. On the host, `.env` supplies
`127.0.0.1:3306` → the **MAIN** server. A host-run `phpunit` therefore asks **`ecos-mysql`** for
**`ecos_dev_test`**, which does not exist there:

```
$ docker exec ecos-mysql mysql -N -B -e "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA
                                         WHERE SCHEMA_NAME IN ('ecos_dev_test','ecos_erp_test');"
ecos_erp_test
```

It fails with *Unknown database* — which is safe, **but the safety comes from a name that happens not to
exist on the MAIN server, not from any architectural pin.** If `ecos_dev_test` were ever created on
`ecos-mysql`, a host-run `RefreshDatabase` would wipe it **on the MAIN server**. This is precisely the class
of fail-open the task exists to eliminate.

**Recommendation (not applied — out of scope while Part 1 fails):** pin `DB_HOST`/`DB_PORT` for the DEV test
target the same way `DB_DATABASE` is pinned, or make host-side execution refuse to run and route developers
to `ecos-dev-testrunner`. Per Part 11, host runtime configuration was **not** silently changed, and host
PHPUnit was **not** forced onto MAIN's database.

### 19.2 — Two agents, one test database

`ecos_dev_test` is a single shared resource. Two concurrent certification processes sharing one test database
already caused hours of false signal in the preceding Preparation task. This must be resolved by sequencing,
not by configuration.

### 19.3 — Documented but unverified

Restart behaviour (§16) and the `RefreshDatabase` blast radius (§12) remain the two genuinely unproven
properties. Both are quick to establish once the worktree is exclusively held.

---

## 20 — IAM Readiness

`TASK-IAM-HTTP-SURFACE-001` remains blocked, but the picture has **improved materially**: its STOP #12
(*runtime tests cannot execute*) is likely already resolved by the concurrent task's runner, since
`ecos-dev-testrunner` executes PHPUnit against `ecos_dev_test` with no config cache. What remains is to prove
it with the destructive probes.

IAM's other two blockers are untouched and out of scope here, as instructed:

* **STOP #13** — worktree isolation (this task's §4).
* **Password reset** — `iam.users.reset-password` and `UserPolicy::resetPassword()` exist with **no domain
  service** behind them. Per Part 17, deliberately left unresolved for the separate IAM contract decision.

---

## 21 — Certification Verdict

# IAM TEST ENVIRONMENT = NOT UNBLOCKED

The environment is **probably correct** — every static and read-only signal is green. But Part 18 requires
seven runtime conditions that could not be executed, and condition 14 (no concurrent worktree conflict)
**fails outright**. Part 18's closing rule forbids certifying from static configuration alone, so no
certification is claimed.

**Path to certification — likely a short run once the worktree is exclusively held:**

1. Confirm the concurrent agent has finished (no writes; no connections to `ecos_dev_test`).
2. Execute Parts 7–10 in order: RefreshDatabase probe → write probe → restart hazard → real feature test,
   recording before/during/after table counts for all four databases.
3. Decide on §19.1 (pin host/port, or block host-side execution).
4. Re-certify, then resume `TASK-IAM-HTTP-SURFACE-001` after the password-reset contract decision.

### Attestations

* **Nothing was modified.** No file, container, database, config or compose file was changed. This report is
  the only addition.
* No concurrent work was overwritten, reverted or cleaned up.
* No `--force`, no `--no-verify`, no history rewrite, no `git reset`.
* MAIN (`ecos_erp`) was accessed read-only and is untouched.
* No IAM controllers, routes, resources, requests, tests, services or password-reset logic were created (Part 15).
* No Preparation logic was modified (Part 16).
* No STOP condition was worked around.
