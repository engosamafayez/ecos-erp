# T-6 — Test Runner Isolation

**Date:** 2026-08-15 · **Branch:** `develop`
**Scope:** test infrastructure only. No production business logic, no migration, no schema
change. `ecos_dev` was never touched — every operation targeted `ecos_dev_test`.

**Deliverable:** [`scripts/test-gate.sh`](scripts/test-gate.sh)

---

## 1. T-6 status

**IMPLEMENTED AND PROVEN — with one limitation that cannot be closed from a wrapper script**
(§4.1).

| Requirement | Status | Proof |
|---|---|---|
| Never run a destructive DB reset while another suite owns the DB | **MET** | Proof B |
| Detect active PHPUnit processes without relying on `ps` | **MET** | Proof B — `/proc` scan |
| Detect `RefreshDatabase` / `migrate:fresh` occupancy correctly | **MET** | Proof B — DDL detector fired |
| Prevent false "runner is free" results | **MET** | Proofs A, B, D, F |
| Prevent one agent dropping another agent's schema | **PARTIAL** | §4.1 — gated runs yes, ungated runs cannot be prevented from a wrapper |
| Prove the gate with an actual controlled run | **MET** | Proofs A–F |

---

## 2. The problem, stated precisely

`backend/tests/TestCase.php::setUp()` pins the test database in code — it writes
`DB_DATABASE` into `putenv`, `$_ENV` and `$_SERVER`, then resets Laravel's `Env` repository
singleton, all **before** `parent::setUp()` builds the app. Neither
`docker exec -e DB_DATABASE=…` nor a custom `phpunit.xml` with `<env force="true">` can
redirect a run; both were tried and both were overwritten. Every agent's suite therefore
lands on the same `ecos_dev_test` schema.

`RefreshDatabase` then runs `migrate:fresh` on first use, which **drops every table**. Two
agents running concurrently destroy each other's schema mid-run, and the victim sees
failures that look exactly like code defects.

**Measured cost, this session:** the same two suites reported **39 non-passing while
contended** and **18 when clean**. Reporting the contended figure would have invented 21
regressions. Separately, two WaveEngine runs died inside `RefreshDatabase::setUp` with
`Base table or view not found: ecos_dev_test.migrations doesn't exist` — the other session
had dropped the schema mid-run.

---

## 3. Isolation mechanism

Three independent detectors, because they answer different questions and no one of them is
sufficient alone.

### 3.1 Advisory lock — coordinates *gated* runs

A MySQL named lock, `ecos:testrunner:<db>`, held by a dedicated background client for
exactly as long as the suite runs. Chosen over a lockfile because the server releases it
automatically when the connection drops, so a crashed or killed run can never leave the gate
permanently shut.

Ownership is **proven, not assumed**: the holder prints its own `CONNECTION_ID()`, and the
gate then compares `IS_USED_LOCK()` against that id from a second connection. Checking only
that *a* lock is held would accept somebody else's lock as our own.

### 3.2 `/proc` scan — detects *ungated* runs

`ps` is not installed in the runner image, so `/proc` is the only route. Two details matter:

- **Matching is on token basename, never substring.** Each `/proc/PID/cmdline` is split on
  NUL and each token's basename compared against `phpunit`/`paratest`. A naive substring
  grep matches any shell whose script merely *mentions* the word — including this gate
  itself, which is how the first prototype reported a false positive against its own
  scanning shell.
- **Self and ancestors are excluded** by walking the PPID chain through `/proc/PID/stat`.

### 3.3 Database occupancy — catches the dangerous window

`information_schema.processlist` is inspected twice: once for any non-`Sleep` work on the
target schema, and once specifically for the `DROP TABLE` / `CREATE TABLE` / `ALTER TABLE`
signature of a `migrate:fresh` in flight. This is the check that catches the precise moment
when joining would be most destructive.

### 3.4 Behaviour

```
scripts/test-gate.sh <phpunit args>     acquire, run, release
scripts/test-gate.sh --status           report occupancy, run nothing
scripts/test-gate.sh --release          reap orphaned holders, then report
GATE_WAIT=600 scripts/test-gate.sh …    queue instead of refusing
```

Exit codes: `0` suite ran (its own code is propagated) · `70` busy, nothing run · `71`
lock could not be established.

---

## 4. Proof run

All six executed in `ecos-dev-testrunner` against `ecos_dev_test`.

### PROOF A — advisory lock detector, isolated

An external mysql session took the lock while nothing else ran:

```
phpunit/paratest processes : 0
active queries on schema   : 0
migrate:fresh DDL in flight: 0
advisory lock held by      : connection 67384
[GATE] BUSY — another gated run holds the advisory lock. Nothing was run.
GATE EXIT=70
```

### PROOF B — ungated run detected mid-`migrate:fresh` ⭐ the decisive one

A plain `php vendor/bin/phpunit` was launched exactly as another agent would, then the gate
was asked six seconds later. **All three detectors fired simultaneously:**

```
phpunit/paratest processes : 1
active queries on schema   : 1
migrate:fresh DDL in flight: 1
advisory lock held by      : nobody          ← ungated: no lock, as expected
[GATE] BUSY — an ungated phpunit process is running. Nothing was run; the schema was not touched.
GATE EXIT=70
=== ungated run still alive? === yes - still running, schema untouched by the gate
```

This is the exact collision that corrupted the earlier runs, caught at the exact moment it
matters.

### PROOF C — happy path

```
[GATE] acquired ecos:testrunner:ecos_dev_test (connection 67460) — running suite
OK (16 tests, 23 assertions)
[GATE] released ecos:testrunner:ecos_dev_test
```

### PROOF D — free after the run

`advisory lock held by : nobody` · `phpunit/paratest processes : 0` · exit 0.

### PROOF E — orphan reaped

`--release` found and killed the leaked holder; `IS_USED_LOCK` returned NULL afterwards.

### PROOF F — back-to-back gated runs do not self-block

Two consecutive gated runs both acquired, ran green and released, with no false BUSY between
them.

### 4.1 Three defects found in the gate itself while proving it

Recorded because a gate that has not been broken has not been tested.

| # | Defect | Consequence | Fix |
|---|---|---|---|
| 1 | `mysql` buffers `CONNECTION_ID()` in stdio when its output is a pipe, and the holder never exits | The id file stayed empty for ever, ownership could never be proven, and the gate **failed closed on a free database** | `--unbuffered`. Measured: `[]` buffered vs `[67414]` unbuffered |
| 2 | `LOCK_CONN=$(acquire_lock)` ran the function in a **command-substitution subshell**, so `HOLDER_PID` was set there and lost | The parent's cleanup trap had nothing to kill; a failed acquisition **leaked connection 67401**, holding the gate shut | call `acquire_lock` as a plain command; it writes the id to a file |
| 3 | The server frees a named lock only when it *notices* the connection has gone — not instantly | `--release` reported failure on a lock it had just freed; back-to-back gated runs saw a false BUSY | settle loops in `release_lock` (up to 6s) and `--release` (4s) |

---

## 5. Remaining limitations

**5.1 An ungated run still cannot be *prevented*, only detected.** The gate guarantees that
runs launched *through it* never collide, and it refuses to start while anything else is
working. It cannot stop another agent typing `php vendor/bin/phpunit` directly. Closing this
requires the lock to live **inside the harness**, which is a shared-behaviour change
affecting every agent's runs, so it is specified below rather than applied unilaterally:

```php
// backend/tests/TestCase.php — inside setUp(), BEFORE parent::setUp()
// Serialises every suite on the shared schema, gated or not.
$lock = 'ecos:testrunner:' . env('DB_DATABASE');
DB::selectOne('SELECT GET_LOCK(?, ?) AS ok', [$lock, (int) env('TEST_LOCK_WAIT', 900)])->ok
    or throw new RuntimeException("Test DB busy: another suite holds {$lock}.");
// released implicitly when the connection closes at end of process
```

This needs your go-ahead: it makes a second concurrent suite **wait** rather than corrupt,
which is correct, but will look like a hang to whoever is second, and it changes behaviour
for sessions other than mine.

**5.2 A per-worker database would remove the contention entirely** rather than serialising
it — the usual `ParaTest`/`TEST_TOKEN` pattern, e.g. `ecos_dev_test_<worker>`. That is a
larger change to `TestCase.php` and the migration bootstrap, and would make runs
parallel rather than queued. Recorded as the strategic fix; the lock is the tactical one.

**5.3 The gate is container-local.** The `/proc` scan sees only processes inside
`ecos-dev-testrunner`. A suite run from `ecos-dev-app` or the host would be caught by the
database detectors but not the process scan. Acceptable today because all suites run in the
testrunner, but it is an assumption, not a guarantee.

**5.4 `--release` is a blunt instrument.** It kills any process whose argv contains
`GET_LOCK('ecos:testrunner:<db>'`. That signature is specific enough that it cannot hit an
unrelated mysql session, but it will reap a *legitimately running* gated suite's holder if
invoked carelessly. It is a maintenance command, not part of the normal path.

**5.5 Not fixed here (out of T-6 scope, recorded in the matrix):** a full
`php artisan migrate` on `ecos_dev_test` still fails on a non-idempotent `timeline_events`
migration (**F-XC-02**), and host↔container parity still depends on a manual `docker cp`
(**F-XC-03**).

---

## 6. Compliance

`ecos_dev` untouched. No production business logic modified. One new file
(`scripts/test-gate.sh`); no existing file changed. The schema wipe visible during proving
was caused by *my own* deliberately-ungated proof run being killed — it is the damage the
gate exists to prevent, reproduced under control, and `RefreshDatabase` rebuilds it on the
next suite.
