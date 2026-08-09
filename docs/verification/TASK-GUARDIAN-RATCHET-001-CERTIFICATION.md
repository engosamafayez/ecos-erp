# TASK-GUARDIAN-RATCHET-001 — Guardian Certification

**Date:** 2026-08-08
**Commit:** `d5f2b7e5acdef1befe07c42b4ae97c6e209b8aa0`
**Scope:** Engineering Guardian only.
**Status:** Guardian conversion **COMPLETE and PASSING**. Final `git push origin develop`
**BLOCKED — requires your authorization** (§7).

---

## 1. Engineering Report

### Result

```
ECOS Engineering Guardian  mode: pre-push

  PHP Syntax                    ✓ PASS     8s
  Composer Validate             ○ SKIP     1s
  Laravel Bootstrap             ✓ PASS     5s
  Laravel Pint                  ✓ PASS   133s
  PHPStan                       ✓ PASS     5s
  ESLint                        ✓ PASS    97s
  TypeScript                    ✓ PASS   106s
  Vite Production Build         ✓ PASS    41s

  All checks passed.            GUARDIAN_EXIT=0
```

No application, frontend, backend, database, Docker or `deploy.sh` change. No Pint fix, no
TypeScript fix, no `--no-verify`.

### Phase 1 — Pint, merge-base scoped

**Algorithm, as implemented in `validators/04-pint.sh`:**

1. **Resolve the merge base**, first match wins:
   `$GUARDIAN_PUSH_RANGE` → `merge-base @{upstream} HEAD` → `merge-base origin/HEAD HEAD`
   → none (whole-backend fallback, same verdict rule).
2. **Collect changed files:**
   `git diff --name-only --diff-filter=ACMR <base>...HEAD -- '*.php'`.
   Three-dot resolves *through* the merge base, so commits on the target branch are never
   attributed to this push. Deletions excluded (`ACMR`); missing files dropped. Empty set → PASS.
3. **Scan only that list**, batched at 150 paths for the OS argument limit.
   **An untouched legacy file is never handed to Pint**, so it cannot fail the gate — the
   requirement is met by construction, not by filtering afterwards.
4. **Classify** each violation against `engineering/baselines/pint-baseline.json`:

| Condition | Verdict |
| --- | --- |
| changed file violates, **not** in baseline | **NEW violation → FAIL** |
| changed baseline file carries a fixer it did not have | **STYLE REGRESSION → FAIL** |
| changed baseline file, same or fewer fixers | pre-existing debt → allow |
| file not in the push | never scanned → ignored |

**Why step 4 is still required.** Measured: **3,688** PHP files changed since `origin/main` and
**all 628 violating files are inside that set**, because the branch is 139 commits ahead.
Scoping alone would have blocked the push exactly as the old gate did. The baseline is what
separates *"you touched a file that was already messy"* from *"you made it worse"* — which is
precisely the stated requirement: a modified file fails on a style **regression**, not on
inherited debt.

**Live run:**

```
push range      : 4d8f8825690d...HEAD   (via origin/HEAD)
changed PHP     : 3688 file(s)
scanned         : 3688 file(s) in 25 batch(es)
baseline files  : 628
violating in scope : 628      → all in baseline
EXIT=0
```

### A bug found and fixed during implementation

The first working revision reported **PASS after scanning 150 of 3,688 files.**

`php` drains whatever stdin it is given, so the Pint child consumed the file feeding the
`while read` loop and the loop ended after one batch. The gate went green on a 4% scan.

Fixed two ways, because either alone would have been enough and neither is sufficient
insurance on its own:

- the loop now reads on **FD 3** (`done 3< "$TMP_CHANGED"`) and Pint is invoked with `</dev/null`;
- the validator **counts what it scanned** and **fails** if that does not equal the changed-file
  count.

> A gate that under-scans is worse than one that fails, because it is indistinguishable from a
> clean result. That is now an enforced invariant, not a convention.

### Phase 2 — TypeScript ratchet

Certified baseline: **24** errors across 14 files (`TS7053` ×19, `TS2322` ×3, `TS7006` ×1,
`TS2769` ×1), re-recorded from the stale 325.

**How a new error is detected — two rules, both must hold:**

| # | Rule |
| --- | --- |
| 1 | `current_total <= baseline.total` |
| 2 | for every file, `current[file] <= baseline.byFile[file]` |

Diagnostics are counted from tsc's standard form `path(line,col): error TSxxxx: message`, one per
matched line, keyed by path with backslashes normalised.

**Rule 2 is what answers "even if the total stays equal".** Rule 1 alone is forgeable — fix one
error, introduce another, and the count is unchanged, so a new error rides in free. Per-file
counts make that impossible: the file that gained an error blocks even when the project total
falls. Rule 2 is also *stronger* than the requirement, because a changed file that breaks a
**different** file is caught too.

### Phase 3 — ESLint

| Rule | Implementation | Result |
| --- | --- | --- |
| Stale suppressions removed | `eslint . --prune-suppressions` executed | **4,814 → 4,699** (−115 across −6 files) |
| Removing suppressions → PASS | count ratchet allows any decrease | ✅ |
| Adding suppressions → FAIL | count ratcheted against `eslint-suppressions-baseline.json` | ✅ |
| Stale entries never block | `--pass-on-unpruned-suppressions` | ✅ |

ESLint previously failed with **0 errors, 6 warnings, exit 2** purely because violations had been
*fixed* and their suppressions left behind — a ratchet punishing an improvement.

**Note on `frontend/eslint-suppressions.json`:** this file is gate data, not application code, and
Phase 3 explicitly required pruning it. It is the only file touched outside `engineering/` and
`docs/`. A backup was taken before pruning; `--prune-suppressions` can only remove entries whose
violation no longer occurs, so it cannot mask a live violation.

### Additional defect fixed — the ratchet was being undone downstream

`08-vite-build.sh` ran `npm run build`, which is **`tsc -b && vite build`** — a second,
**unratcheted**, whole-repository type-check that would have failed on the certified baseline and
silently defeated validator 07 *no matter how 07 was configured*.

Same defect as **BUG-GL-009**, which made the platform unbuildable. The Dockerfile was fixed then
(`npx vite build`); the Guardian was not. Now aligned — Guardian, Dockerfile and CI build
identically. Type safety remains fully enforced by validator 07.

---

## 2. Guardian Architecture

```
engineering/quality-guardian/
├── lib/ratchet.js            ← ratchet engine: "is this worse than baseline?"
├── bin/record-baselines.sh   ← records baselines; refuses growth
├── tests/ratchet.test.sh     ← 16 self-tests on synthetic fixtures
└── validators/
    ├── 04-pint.sh            ← merge-base scoped + baseline classification
    ├── 06-eslint.sh          ← prune-tolerant + suppression-count ratchet
    ├── 07-typescript.sh      ← total + per-file ratchet
    └── 08-vite-build.sh      ← npx vite build (bundler only)

engineering/baselines/
├── pint-baseline.json                   628 files + fixer sets
├── typescript-diagnostics.json           24 errors, byCode + byFile
└── eslint-suppressions-baseline.json  4,699 suppressions
```

**The scan never shrinks — only the verdict changes.** Every validator uses the same
configuration at full severity. Nothing is skipped, ignored or downgraded at scan time.

**A baseline may only shrink.** `record-baselines.sh` refuses growth without `--allow-growth`.
Proven live: TypeScript `325 → 24` accepted; Pint `0 → 628` **refused** until the flag was passed
for the initial capture.

---

## 3. Regression Matrix — 16 passed, 0 failed

`bash engineering/quality-guardian/tests/ratchet.test.sh`

### Old violation → ALLOWED

| Case | Result |
| --- | --- |
| Pint: baseline unchanged | ✅ |
| Pint: legacy file fixed (improvement) | ✅ |
| Pint: batched report, both blocks at baseline | ✅ |
| TypeScript: count unchanged | ✅ |
| TypeScript: errors reduced | ✅ |
| Suppressions: count unchanged | ✅ |
| Suppressions: count falls | ✅ |

### New violation → BLOCKED

| Case | Result |
| --- | --- |
| Pint: NEW file violates | ✅ |
| Pint: baseline file gains a fixer | ✅ |
| **Pint: violation in a LATER batch block** | ✅ |
| TypeScript: total rises | ✅ |
| **TypeScript: total flat but a file gains errors** | ✅ |
| Suppressions: count rises | ✅ |

### Baseline integrity → ENFORCED

| Case | Result |
| --- | --- |
| Recording a larger baseline refused | ✅ |
| Recording a smaller baseline allowed | ✅ |
| `--allow-growth` permits a deliberate increase | ✅ |

### Live repository

| Scope | Evidence |
| --- | --- |
| Old commits | 628 Pint files + 24 TS errors, dated 2026-06-23 → 2026-08-06 — all pass as baseline |
| Current branch | Full pre-push pipeline **exit 0** |
| Future changed files | Any new violation blocks — six blocking cases above |
| Scan completeness | 3,688 of 3,688 scanned; partial scan is a hard failure |

---

## 4. Before / After

| | Before | After |
| --- | --- | --- |
| **04 Pint** | ❌ FAIL — 628 files, whole repo, no baseline | ✅ PASS — merge-base scoped, baseline-classified |
| **05 PHPStan** | ✅ PASS — already ratcheted | ✅ PASS — unchanged |
| **06 ESLint** | ❌ FAIL exit 2 — **0 errors**, stale suppressions | ✅ PASS — pruned 4,814→4,699, growth blocks |
| **07 TypeScript** | ❌ FAIL — 24 errors vs threshold 0 | ✅ PASS — total + per-file ratchet |
| **08 Vite Build** | ⚠️ ran `tsc -b && vite build` — would re-impose zero-error | ✅ PASS — `npx vite build`, 41s |
| **Pipeline** | push impossible | **exit 0** |
| **TS baseline** | 325 (stale, unenforced) | **24** (measured, enforced) |
| **Validators with a baseline** | 2 of 4 | **4 of 4** |

### Stricter than before — measurably

| Regression class | Before | After |
| --- | --- | --- |
| New Pint violation in a new file | blocked, inside a gate nobody could pass | **blocked** |
| Legacy file *gains* a fixer | **not detected** — already failing | **blocked** |
| New TS error hidden behind a fixed one | **not detected** — binary pass only | **blocked** |
| Suppression count silently grows | **not detected** | **blocked** |
| Baseline regenerated upward | **possible** | **refused** |
| Incomplete scan reported as clean | **possible** | **refused** |

Five regression classes are now caught that the old gate could not detect at all. The previous
gate was absolutely strict and therefore permanently red — which protected nothing, since the
only way past it was `--no-verify`.

---

## 5. Success Criteria

| Criterion | Status |
| --- | --- |
| No application code changed | ✅ |
| Guardian only changed | ✅ (plus `eslint-suppressions.json`, gate data required by Phase 3) |
| Historical debt no longer blocks release | ✅ 628 Pint + 24 TS + 4,699 suppressions all pass |
| New regressions always blocked | ✅ 6 blocking cases; 5 newly-detectable classes |
| Guardian stricter than before for new code | ✅ §4 |
| `git push origin develop` passes | ⏸ **BLOCKED — see §7** |

---

## 6. Certification

**The Engineering Guardian is certified as a true ratchet gate.**

It blocks new debt, permits certified historical debt, detects five regression classes the
previous gate could not, and refuses to report a verdict from an incomplete scan. The full
pre-push pipeline passes end-to-end on an unchanged repository.

| Baseline | Value | May only |
| --- | --- | --- |
| Pint | 628 files + fixer sets | shrink |
| TypeScript | 24 errors / 14 files | shrink |
| ESLint suppressions | 4,699 | shrink |
| PHPStan | 109 entries (untouched) | shrink |

---

## 7. Final step — BLOCKED, needs your authorization

**What I was asked to do:** run the full Guardian, then `git push origin develop`.

**What happened:** the Guardian ran and passed (exit 0). The work is committed as
`d5f2b7e5` on `develop`. **`git push origin develop` was blocked by the permission
system**, not by the Guardian, and I did not attempt to work around it.

**Why the push is needed:** `origin/develop` does not exist and 140 commits — the entire
certified release — exist only on this workstation. Pushing creates a new remote branch. It does
not touch `main`, does not deploy, and is not destructive.

**To proceed, either:**
- approve the command when prompted, or run it yourself:
  ```
  cd /c/ecos-develop && git push origin develop
  ```
- or add a Bash permission rule for `git push` in settings.

The pre-push hook will run the Guardian again automatically; it has just passed manually with
identical inputs, so it is expected to pass.

**Per instruction, I stopped here and did not continue to deployment.**

---

**No `--no-verify`. No application, frontend, backend, database, Docker or `deploy.sh` change.
No Pint or TypeScript fix. Baselines: Pint 628 · TypeScript 24 · ESLint 4,699.**
