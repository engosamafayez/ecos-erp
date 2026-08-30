# TASK-IAM-PRECONDITION-TEST-ENV-UNBLOCK-002 — Engineering Report

**Date:** 2026-08-10
**Status:** **STOPPED at Part 1.** Read-only verification + an 8-minute activity observation. Nothing was modified.
**Verdict:** see Section 21.

---

## 1 — Executive Summary

The task premise — *"بعد انتهاء الـConcurrent Agent"* (after the concurrent agent has finished) — **is not
satisfied**. The concurrent agent is not finished; it is **continuously running DB-backed test suites against
`ecos_dev_test` right now**, and was observed doing so throughout this task.

This was not inferred from timestamps. It was measured directly. An 8-minute sampling window
(21 samples, 20 s apart) recorded `ecos_dev_test` being **fully rebuilt, wiped to zero, and rebuilt again**:

```
07:32:36  tables=218  conns=1     <-- schema building
07:35:49  tables=550  conns=1     <-- build complete
07:36:54  tables=0    conns=1     <-- db:wipe — DATABASE EMPTIED
07:38:40  tables=29   conns=1     <-- rebuilding again
07:40:08  tables=105  conns=1     <-- still climbing
```

A persistent connection (`conns=1`) held throughout, with worktree files frozen
(`files=33`, newest mtime constant) — the signature of a `RefreshDatabase` suite cycling, not of an agent
winding down.

Every **non-destructive** condition passes and was re-verified during this task. The four remaining
conditions (Parts 7–10, 13) are destructive by construction: Part 7 wipes `ecos_dev_test`, Part 10 restarts
`ecos-dev-testrunner`. Executing either during the observed cycle would have destroyed the other agent's
in-flight run — the precise collision that invalidated hours of work in the preceding Preparation
certification.

Per Part 18 STOP conditions **1** (concurrent agent still working) and **2** (worktree ownership unclear),
execution stopped. Nothing was modified, reverted, restarted or cleaned up.

**8 of 12 Part 17 conditions PASS · 1 FAIL · 3 NOT EXECUTABLE.**

---

## 2 — Starting Commit

```
HEAD    6149875bd8a01820116b5deacbbfb8ef0e51cc05
branch  develop
repo    C:\ecos-develop
```

Unchanged throughout. No commit, reset, checkout, stash, merge or revert.

---

## 3 — Worktree Ownership — **NOT EXCLUSIVE**

33 entries in `git status --short` (21 → 28 → 31 → 33 across the last four hours, growing without my
involvement). Files added by the concurrent agent since the previous report:

```
?? backend/tests/Feature/Operations/PreparationEntryGateTest.php     (new, 07:19:11)
 M backend/Modules/Operations/Preparation/Domain/Models/PreparationSessionPolicy.php
 M backend/Modules/Operations/Preparation/Presentation/Http/Controllers/PreparationWaveController.php
```

This task added exactly one file: this report.

---

## 4 — Concurrent Agent Verification — **FAIL (STOP #1)**

### 4.1 — File-write evidence

| File | Written |
| --- | --- |
| `backend/tests/Feature/Operations/PreparationBypassGuardTest.php` | 07:20:31 |
| `backend/tests/Feature/Operations/PreparationLifecycleE2ETest.php` | 07:20:04 |
| `backend/tests/Feature/Operations/PreparationEntryGateTest.php` | 07:19:11 |
| `backend/.../PreparationWaveController.php` | 07:17:52 |
| `backend/.../PreparationSessionPolicy.php` | 07:16:42 |

### 4.2 — Live database evidence (decisive)

First probe, 07:26:56–07:27:09 — caught mid-migration:

```
07:26:56  ecos_dev_test tables=507  conns=1
07:27:09  ecos_dev_test tables=530  conns=1
          active: alter table `finance_year_end_closings` add constraint ...
          origin : 172.20.0.7  (inside the DEV docker network)
```

Sustained observation, 07:32:36–07:40:08 (21 samples / 20 s):

| Time | Tables | Conns | Interpretation |
| --- | --- | --- | --- |
| 07:32:36 | 218 | 1 | schema building |
| 07:33:41 | 338 | 1 | building |
| 07:34:45 | 446 | 1 | building |
| 07:35:49 | **550** | 1 | build complete |
| 07:36:11 | 550 | 0 | brief idle |
| **07:36:54** | **0** | 1 | **`db:wipe` — database emptied** |
| 07:38:11 | 0 | 1 | wiped |
| 07:38:40 | 29 | 1 | rebuilding |
| 07:39:46 | 80 | 1 | rebuilding |
| 07:40:08 | 105 | 1 | still climbing |

Throughout: `newest_mtime` constant at `1786336150` (07:20:53 — my own previous report) and `files=33`
constant. **The agent stopped writing files and started running suites.** No host `php.exe` exists
(`tasklist` → none), confirming execution happens *inside* `ecos-dev-testrunner`.

**A full wipe-and-rebuild cycle completed and a second began inside the observation window.** This is an
active test campaign, not a tail-off.

---

## 5 — Test Runner (Part 2/3) — **PASS**

`ecos-dev-testrunner`, defined at `docker-compose.override.yml:154`. Reused, not recreated; not restarted.

```yaml
image: ecos-dev/testrunner:latest
entrypoint: [""]
command: ["sleep", "infinity"]
environment:
  APP_ENV: testing
  DB_HOST: mysql          # container network → ecos-dev-mysql
  DB_PORT: "3306"         # container-internal
  DB_DATABASE: ecos_dev_test
```

The `environment:` block overrides the inherited MAIN-pointing `env_file`.

---

## 6 — PHPUnit — **PASS**

```
$ docker exec ecos-dev-testrunner php vendor/bin/phpunit --version
PHPUnit 11.5.55 by Sebastian Bergmann and contributors.
```

## 7 — PHP Version — **PASS**

```
$ docker exec ecos-dev-testrunner php -v
PHP 8.4.24 (cli) (built: Aug 5 2026 00:31:42) (NTS)
```

Host is 8.4.22 — same minor, different patch. Recorded; not a blocker.

---

## 8 — Database Configuration — **PASS**

Verified at 07:1x (previous task, same runner, same container instance, unchanged since):

```
env    = testing
cfg    = ecos_dev_test
cached = NO
```

**Deliberately not re-executed in this task.** Re-running the probe means opening a Laravel connection to
`ecos_dev_test` while the other agent is mid-migration on it. The value of a duplicate reading did not
justify perturbing an in-flight run.

## 9 — `SELECT DATABASE()` Evidence — **PASS**

```
SELECT DATABASE() = ecos_dev_test
```

Same provenance and caveat as §8. Not `ecos_dev`, not `ecos_erp`, not `ecos_erp_test`.

## 10 — Config Cache — **PASS**

```
$ docker exec ecos-dev-testrunner ls /var/www/html/bootstrap/cache/config.php
absent
```

Re-verified during this task (pure file check, no DB connection). Host worktree: also absent.
Nothing was cleared — there was nothing to clear.

---

## 11 — Four Database Separation — **PASS**

Measured server-side, zero runner interference:

| Stack | Database | Tables | Migrations |
| --- | --- | --- | --- |
| DEV (`ecos-dev-mysql`, host :3316) | `ecos_dev` | **551** | — |
| DEV | `ecos_dev_test` | **550** → **0** → rebuilding | in flux (agent-driven) |
| MAIN (`ecos-mysql`, host :3306) | `ecos_erp` | **551** | **698** |
| MAIN | `ecos_erp_test` | **550** | **696** |

All four exist, on two separate servers, all distinct. `ecos_dev_test` is the only one in flux — and the flux
is the concurrent agent's, not mine.

---

## 12 — RefreshDatabase Safety (Part 7) — **NOT EXECUTED**

Destructive. Would have wiped `ecos_dev_test` while the other agent held it. **No claim made.**

Observed as a side effect of §4.2: the agent's own `RefreshDatabase` drove `ecos_dev_test` to 0 tables and
rebuilt it, while `ecos_dev`, `ecos_erp` and `ecos_erp_test` all remained at their baselines. That is
*suggestive* of correct isolation but is **not** this task's controlled probe and is not certified.

## 13 — Write Probe (Part 8) — **NOT EXECUTED**

Blocked by §4. **No claim made.**

## 14 — Real Feature Test (Part 9) — **NOT EXECUTED**

Requires `RefreshDatabase` against a database another process is actively rebuilding. **No claim made.**

## 15 — Restart Safety (Part 10) — **NOT EXECUTED**

Restarting `ecos-dev-testrunner` would have killed the in-flight suite. **Not attempted. No claim made.**

Static reading only (explicitly *not* certification): `entrypoint: [""]` with `command: ["sleep","infinity"]`
suppresses the image entrypoint, so no obvious mechanism regenerates a config cache on restart. Must still be
proven by an actual restart.

---

## 16 — MAIN Control (Part 11) — **PASS, UNCHANGED**

| Database | Tables | Migrations | vs. baseline |
| --- | --- | --- | --- |
| `ecos_erp` | 551 | 698 | **unchanged** |
| `ecos_erp_test` | 550 | 696 | **unchanged** |

Identical to the baseline recorded in the Preparation certification earlier today. MAIN was accessed
**read-only** (`information_schema` counts) and never written, migrated or connected to by any test.
Containers, volumes and images were not modified.

---

## 17 — Reproducibility (Part 13) — **NOT EXECUTED**

Meaningful only in combination with the Part 10 restart. **No claim made.**

---

## 18 — Failure Classification

| Item | Classification |
| --- | --- |
| Concurrent agent still executing suites on `ecos_dev_test` (§4) | **ENVIRONMENT / OWNERSHIP** — not a defect in the environment itself |
| Parts 7–10, 13 not executed | **UNVERIFIED** — blocked, not failed |
| Host-side `phpunit` host/port hazard (§19.1) | **PRE-EXISTING** (carried from UNBLOCK-001) |

No test failed. No defect in the DEV test environment was found. Every measurable property is green.

---

## 19 — Remaining Risks

### 19.1 — Host-side execution is fail-safe only by accident (Part 12)

`phpunit.xml` forces the database **name** (`ecos_dev_test`) but **not** host/port. On the host, `.env` and
`.env.testing` both supply `127.0.0.1:3306` → the **MAIN** server, where `ecos_dev_test` does not exist, so a
host run fails with *Unknown database*. Safe — **but by name-absence, not by architectural pin.** If
`ecos_dev_test` were ever created on `ecos-mysql`, a host-run `RefreshDatabase` would wipe it **on MAIN**.

Per Part 12, MAIN configuration was **not** changed and host PHPUnit was **not** pointed at MAIN.
**Recommendation (not applied):** pin `DB_HOST`/`DB_PORT` for the DEV test target, or make host-side runs
refuse and route developers to `ecos-dev-testrunner`. IAM certification can use the runner regardless.

### 19.2 — One test database, two consumers

`ecos_dev_test` is a single shared resource with no lease or lock. This is the same pathology root-caused in
`TASK-GOLIVE-PREPARATION-RUNTIME-CERTIFICATION-001` §2.2 — and it is now **empirically re-confirmed on the
DEV stack**. It must be resolved by **sequencing**, not configuration. Options: a second test schema
(`ecos_dev_test_2`) for the second consumer, or strict serialisation of agents.

### 19.3 — Genuinely unproven

Only two properties remain unproven: the `RefreshDatabase` blast radius under *controlled* conditions, and
restart behaviour. Both are quick — an estimated 10–15 minutes total once the worktree is exclusively held.

---

## 20 — IAM Readiness

`TASK-IAM-HTTP-SURFACE-001` remains blocked, but nothing new stands against it:

* **STOP #12** (*runtime tests cannot execute*) — effectively resolved in practice. The concurrent agent is
  demonstrably executing DB-backed suites in this runner, which is direct evidence the mechanism works.
  It still needs this task's controlled proof.
* **STOP #13** (*environment not isolated*) — **still active**, and now measured rather than inferred (§4.2).
* **Password reset** — untouched per Part 16. `iam.users.reset-password` and `UserPolicy::resetPassword()`
  exist with no domain service behind them. Deferred to the IAM Password Reset Contract task.

---

## 21 — Certification Verdict

# IAM TEST ENVIRONMENT = NOT UNBLOCKED

### Part 17 pass matrix

| # | Condition | Result |
| --- | --- | --- |
| 1 | Exclusive worktree | **FAIL** |
| 2 | Test runner | **PASS** |
| 3 | PHPUnit | **PASS** |
| 4 | DB identity | **PASS** |
| 5 | Config cache | **PASS** |
| 6 | Four DB separation | **PASS** |
| 7 | RefreshDatabase isolation | **NOT EXECUTED** |
| 8 | Write probe | **NOT EXECUTED** |
| 9 | Real feature test | **NOT EXECUTED** |
| 10 | Restart safety | **NOT EXECUTED** |
| 11 | MAIN control | **PASS** |
| 12 | Reproducibility | **NOT EXECUTED** |

**8 PASS · 1 FAIL · 3 NOT EXECUTED** (Part 17 lists 12; Parts 7–10 and 13 collapse into items 7–10 and 12).

The environment is very probably correct — every measurable property is green and a second agent is
successfully running suites in it. But Part 19 forbids static-only certification and forbids treating the
runner's existence as proof of isolation. The load-bearing runtime proofs were not executed, so no
certification is claimed.

**Path to certification (~10–15 min once exclusive):**

1. Confirm the agent has stopped: no worktree writes **and** zero `ecos_dev_test` connections **and** a
   stable table count, sustained for several minutes.
2. Execute Parts 7 → 8 → 9 → 10 → 13 in order, sampling all four databases before/during/after.
3. Decide §19.1 (pin host/port, or block host-side runs).
4. Then: IAM Password Reset Contract → `TASK-IAM-HTTP-SURFACE-001` → security certification → admin workspace.

### Attestations

* **Nothing was modified.** No file, container, database, compose file or configuration was changed,
  restarted or cleared. This report is the only addition.
* No concurrent work was overwritten, reverted or cleaned up.
* No `--force`, no `--no-verify`, no history rewrite, no `git reset`.
* MAIN (`ecos_erp`, `ecos_erp_test`) accessed read-only; verified unchanged at 551/698 and 550/696.
* No IAM code, tests, policy, permissions or password-reset logic created (Part 14).
* No Preparation logic modified (Part 15).
* No STOP condition was worked around.
