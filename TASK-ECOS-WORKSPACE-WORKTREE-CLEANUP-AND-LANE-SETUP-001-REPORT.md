# TASK-ECOS-WORKSPACE-WORKTREE-CLEANUP-AND-LANE-SETUP-001 — Audit Report

**ECOS Workspace / Worktree Audit → Cleanup Plan + Parallel Lane Setup**
Date: 2026-08-30 · Mode: **AUDIT ONLY (read-only)** · Status: **AWAITING CTO APPROVAL — nothing deleted, moved, pruned, checked out, or committed.**

> This report is discovery + classification + proposals only. No destructive git or filesystem
> command was run (no `reset`, `clean`, `stash`, `checkout`, `restore`, `branch -D`,
> `worktree remove`, `worktree prune`, `Remove-Item`, `rmdir`, `rm`). Phase 2 executes only what the
> CTO explicitly approves below.

---

## 0. Canonical topology

- **Canonical repository (owns `.git`):** `C:\Projects\ECOS-ERP` — remote `https://github.com/engosamafayez/ecos-erp.git`.
- **Primary working lane:** `C:\ecos-develop` (linked worktree, branch `develop`).
- **11 registered worktrees** of the canonical repo (1 main checkout + 10 linked). **None locked, none prunable, no missing directories** (`git worktree list --porcelain` shows no `locked`/`prunable` flags; every registered path exists on disk).
- **2 independent clones** (own `.git`, plus a `local` remote back to the canonical repo): `C:\ecos-day-settlement-codex`, `C:\Projects\ECOS-ERP-meta`.
- **2 non-git stray directories:** `C:\ecos-fincontrol` (vendor-only), `C:\ecos-develop ␠` (trailing-space garbage).
- **Active-process safety:** No host `node`/`php`/`vite`/`composer`/`git` process references any ECOS path (the dev stack runs in Docker containers `ecos-dev-app`/`ecos-dev-nginx`/`ecos-dev-testrunner`, not host CWDs). Active *session* usage is inferred from live uncommitted changes (see table).

---

## 1. Workspace Inventory & Classification

Classifications: **A** ACTIVE-KEEP · **B** IMPORTANT-UNMERGED-KEEP · **C** ARCHIVE-KEEP · **D** SAFE-CLEANUP-CANDIDATE · **E** UNKNOWN/NEEDS-REVIEW.

| # | Path | Repo / Type | Branch | Git status | Unique work? | Active? | Class | Recommended action | Risk |
|---|------|-------------|--------|------------|--------------|---------|-------|--------------------|------|
| 1 | `C:\ecos-develop` | canonical / **linked worktree** | `develop` (ahead 5 of origin) | **370 mod / 736 untr (1106)** | **YES — heavy live** | **YES (this + peer sessions)** | **A** | **KEEP — Lane A (primary)** | HIGH if touched |
| 2 | `C:\Projects\ECOS-ERP` | canonical / **main checkout** (owns `.git`) | `platform-foundation` | clean | Canonical repo root | Repo host | **A** | **KEEP — never delete** | CRITICAL |
| 3 | `C:\ecos-day-settlement-codex` | **independent clone** | `task/operational-day-settlement-codex-clone-001` | **13 mod / 12 untr (25)** | **YES — full Operational Day-Settlement feature + `TASK-…-DRIVER-CLOSING-001-REPORT.md`** | Likely (Codex; edited 2026-08-29) | **B** | **KEEP** | HIGH if touched |
| 4 | `…\.claude\worktrees\agent-aa9d4e09e755b9f79` | linked worktree | `worktree-agent-aa9d4e09…` @`5c42cf81` (2026-07-16) | **425 mod / 32 untr (457; +10028/−2835)** | **YES — large uncommitted logistics/config/distribution UI** | No (stale) | **B/E** | **KEEP → review diff before any removal** | HIGH |
| 5 | `…\.claude\worktrees\agent-ad776247f3c126fa2` | linked worktree | `worktree-agent-ad776…` @`e14b17a6` (2026-07-11) | 4 mod + **whole untracked `Modules/Operations/Distribution` backend** | **YES — untracked Distribution module** | No (stale) | **B/E** | **KEEP → review diff before any removal** | HIGH |
| 6 | `…\.claude\worktrees\agent-a4d5103fa6ec62b80` | linked worktree | `worktree-agent-a4d5103…` @`e0a09e18` (2026-07-06) | 45 mod (+1096/−278) — `use-*` hook refactor | **YES — uncommitted** | No (stale) | **E** | **KEEP → review diff** | MED |
| 7 | `…\.claude\worktrees\agent-a6b7ed00750ba193a` | linked worktree | `worktree-agent-a6b7ed…` @`e0a09e18` | 4 mod / 3 untr — incl. `add_company_id` migrations | **YES — uncommitted migrations** | No (stale) | **E** | **KEEP → review diff** | MED |
| 8 | `…\.claude\worktrees\agent-ac1ce5a5adb583e6e` | linked worktree | `worktree-agent-ac1ce5…` @`e0a09e18` | 5 mod / 2 untr | **YES — uncommitted** | No (stale) | **E** | **KEEP → review diff** | MED |
| 9 | `C:\Projects\ECOS-ERP-meta` | **independent clone** | `main` (= origin/main) | 1 staged (`docker-compose.yml`) | Minor (local config) | No (2026-07-20) | **C** | **KEEP FOR NOW (archive)** | LOW |
| 10 | `C:\ecos-bt` | linked worktree | `main` | clean | Holds canonical `main` | No | **C** | **KEEP (main reference)** | LOW |
| 11 | `C:\ecos-day-settlement` | linked worktree | `task/operational-day-settlement-codex-001` @ develop tip | clean | **None** (empty shell; real work is in #3) | No | **D** | **CLEANUP CANDIDATE (worktree) — keep branch ref; confirm lane** | LOW–MED |
| 12 | `…\.claude\worktrees\relaxed-perlman-ebc368` | linked worktree | `task/operational-day-settlement-driver-closing-001` @ develop tip | clean | **None** | No | **D** | **CLEANUP CANDIDATE (worktree) — keep branch ref** | LOW–MED |
| 13 | `…\.claude\worktrees\unruffled-nightingale-2bcee7` | linked worktree | **detached** @`e14b17a6` | clean | **None** | No | **D** | **CLEANUP CANDIDATE (worktree)** | LOW |
| 14 | `C:\ecos-fincontrol` | **non-git dir** | — | `backend\vendor` **symlink** only; no source/git/reports | **None** | No (2026-08-05) | **D** | **CLEANUP CANDIDATE (dir) — verify symlink target first** | LOW–MED |
| 15 | `C:\ecos-develop␠` (`ECOS-D~2`, trailing space) | **non-git stray** | — | empty (only an empty `c\` subdir); 0 files | **None** | No (2026-08-08) | **D** | **CLEANUP CANDIDATE (garbage dir)** | LOW |

### Notes on "unique work" proof
- **Every** `agent-*` worktree (#4–#8) has `unique-commits(develop..HEAD) = 0` — their **branch tips are already ancestors of `develop`** — but each carries **uncommitted working-tree changes**. Per the task's hard rule (*"ANY unique unmerged work → do NOT recommend deletion"*), all five are **excluded from the delete list** until their diffs are reviewed and either committed-to-branch or explicitly confirmed superseded.
- #3 `ecos-day-settlement-codex` holds a coherent, unmerged **Operational Day-Settlement** feature: `OperationalDaySettlementService/Query/Controller`, 3 migrations, feature tests, a frontend `operations/day-settlement/*` module, and an untracked `TASK-OPERATIONAL-DAY-SETTLEMENT-DRIVER-CLOSING-001-REPORT.md`. High-value — **must not be deleted.**
- #11–#13 are the only **worktrees with zero unique work** (clean, and either at `develop` tip or a released commit) — the genuine safe cleanup candidates.
- #14 `ecos-fincontrol` = a shell containing only `backend\vendor` (a reparse-point **symlink**, `d----l`); no `Modules/`, no `app/`, no `composer.json` at root, no `.git`, no reports → no unique source.
- #15 `C:\ecos-develop ` (trailing space, short name `ECOS-D~2`, distinct from the real `ECOS-D~1`) is **empty** — almost certainly created by a mis-quoted path; not a git worktree.

---

## 2. Git Worktree Audit (canonical repo)

`git worktree list --porcelain` (from `C:\ecos-develop`) — **11 entries**:

| Worktree | Branch | HEAD | State |
|----------|--------|------|-------|
| `C:/Projects/ECOS-ERP` | `platform-foundation` | `46372413` | main checkout, clean |
| `C:/ecos-bt` | `main` | `f0d7822a` | clean |
| `C:/ecos-develop` | `develop` | `abe4d10f` | **active, 1106 uncommitted** |
| `C:/ecos-day-settlement` | `task/…-codex-001` | `abe4d10f` | clean (candidate) |
| `…/agent-a4d5103…` | `worktree-agent-a4d5103…` | `e0a09e18` | 45 uncommitted |
| `…/agent-a6b7ed…` | `worktree-agent-a6b7ed…` | `e0a09e18` | 7 uncommitted |
| `…/agent-aa9d4e09…` | `worktree-agent-aa9d4e09…` | `5c42cf81` | 457 uncommitted |
| `…/agent-ac1ce5…` | `worktree-agent-ac1ce5…` | `e0a09e18` | 7 uncommitted |
| `…/agent-ad776…` | `worktree-agent-ad776…` | `e14b17a6` | uncommitted + untracked module |
| `…/relaxed-perlman-ebc368` | `task/…-driver-closing-001` | `abe4d10f` | clean (candidate) |
| `…/unruffled-nightingale-2bcee7` | detached | `e14b17a6` | clean (candidate) |

- **Active:** all 11 (every registered directory exists).
- **Locked:** none. **Prunable:** none. **Missing directories:** none. **Stale (old, low-activity):** the 5 `agent-*` + `relaxed-perlman` + `unruffled-nightingale` + `ecos-day-settlement` (but staleness ≠ deletable — see unique-work rule).
- `git worktree prune` was **not** run (not even `--dry-run`), per the task; prunability was read from the porcelain flags instead (none).

### Local branch inventory (for context — branches are NOT proposed for deletion here)
`develop`* (ahead 5), `main`+, `platform-foundation`+, `task/operational-day-settlement-codex-001`+, `task/operational-day-settlement-driver-closing-001`+, `worktree-agent-*`+ (5), plus unattached refs: `backup-current-state`, `backup/pre-push-2026-06-26`, `claude/compassionate-herschel-df8da8`, `claude/relaxed-perlman-ebc368`, `claude/sh-164341`, `claude/sleepy-chaum-eb40c7`, `claude/unruffled-nightingale-2bcee7`, `recovery/pre-db-investigation`. (`+` = checked out in another worktree.)

---

## 3. PROPOSED KEEP LIST (no action)

1. `C:\ecos-develop` — **Lane A primary**, active, 1106 uncommitted. (A)
2. `C:\Projects\ECOS-ERP` — canonical repo root; deleting it destroys `.git` and every worktree. (A)
3. `C:\ecos-day-settlement-codex` — unmerged Operational Day-Settlement feature + report. (B)
4. `…\agent-aa9d4e09e755b9f79` — 457 uncommitted (10k+ lines). (B/E)
5. `…\agent-ad776247f3c126fa2` — untracked Distribution backend. (B/E)
6. `…\agent-a4d5103fa6ec62b80`, `…\agent-a6b7ed00750ba193a`, `…\agent-ac1ce5a5adb583e6e` — smaller uncommitted diffs/migrations. (E — review, don't delete)
7. `C:\Projects\ECOS-ERP-meta` — Meta-integration clone, 1 staged config. (C)
8. `C:\ecos-bt` — canonical `main` checkout. (C)

## 4. PROPOSED DELETE LIST (candidates — **CTO approval required**, Phase 2 only)

| Candidate | Kind | Why safe | Removal method (Phase 2) | Residual risk |
|-----------|------|----------|--------------------------|---------------|
| `C:\ecos-develop␠` (trailing space, `ECOS-D~2`) | stray dir | empty, non-git, 0 files | `Remove-Item -LiteralPath '\\?\C:\ecos-develop \'` (needs `\\?\` for trailing space) | none (empty) |
| `C:\ecos-fincontrol` | non-git dir | vendor-symlink only, no source/git/reports | verify `backend\vendor` symlink target ≠ sole store, then remove the link+dir | LOW — regenerable via `composer install` |
| `unruffled-nightingale-2bcee7` | linked worktree | clean, detached at a released commit | `git worktree remove <path>` | none |
| `relaxed-perlman-ebc368` | linked worktree | clean at develop tip; **preserve branch** `task/…-driver-closing-001` | `git worktree remove <path>` (keep branch ref) | LOW — branch name maps to a completed task; confirm not an integration target |
| `C:\ecos-day-settlement` | linked worktree | clean at develop tip; **preserve branch** `task/…-codex-001`; real work is in #3 clone | `git worktree remove <path>` (keep branch ref) | LOW–MED — confirm the Day-Settlement lane does not need this worktree/branch |

> **Not on the delete list on purpose:** all five `agent-*` worktrees (uncommitted work) and both independent clones (`codex`, `meta`). They require review/keep, not deletion.

## 5. PROPOSED NEW CLONES (Phase 2 only — prefer **independent clones**, not linked worktrees)

> Rationale confirmed by this audit: the `.claude\worktrees\` graveyard (7 abandoned linked worktrees, 5 still holding uncommitted work) is exactly the linked-worktree instability the task warns about. New concurrent WRITE lanes should be independent clones — the `ecos-day-settlement-codex` clone is the working model.

| Lane | Path | Base branch | New branch | When |
|------|------|-------------|------------|------|
| **A — Primary / Driver App** | `C:\ecos-develop` (exists) | `develop` | `develop` | now — ONE writer only |
| **B — Distribution** | `C:\ecos-distribution` (new clone) | `develop` | `task/distribution-workstream` | on approval |
| **C — Mobile UX** | `C:\ecos-mobile` (new clone) | `develop` | `task/mobile-ux-workstream` | only when Mobile UX moves from design → implementation (currently read-only) |
| **D — Finance** | `C:\ecos-finance` (new clone) | **`platform-foundation`** (per branch-ownership convention — CTO to confirm) | `task/finance-gap-closure` | on approval |

## 6. PROPOSED BRANCHES

- `task/distribution-workstream` ← from `develop`
- `task/mobile-ux-workstream` ← from `develop`
- `task/finance-gap-closure` ← from `platform-foundation` *(confirm base with CTO; Finance/Exec has historically lived on `platform-foundation`)*

---

## 7. Open questions for the CTO (block Phase 2)

1. **Delete list** — approve all five candidates in §4, or a subset? (Trailing-space stray + `ecos-fincontrol` are the lowest-risk; the three clean worktrees need lane confirmation.)
2. **`agent-*` worktrees** — authorize a follow-up **review sub-task** to inspect each uncommitted diff and either commit-to-branch-and-archive or confirm-superseded-then-remove? (None are safe to delete blind.)
3. **New clone paths & branch names** in §5/§6 — approve as written?
4. **Lane D base branch** — `platform-foundation` or `develop` for `task/finance-gap-closure`?
5. **`ecos-fincontrol` symlink** — OK to remove the dir once its `backend\vendor` link target is confirmed to be a shared/regenerable store?

---

## 8. STOP

Audit complete. **No workspace, worktree, branch, or file was created, deleted, moved, pruned, reset, checked out, stashed, or committed.** Awaiting explicit CTO approval of the **KEEP list**, **DELETE list**, **NEW CLONE paths**, and **BRANCH names** before any Phase-2 execution.

IMPLEMENTATION STATUS: AUDIT COMPLETE — awaiting CTO approval; zero destructive actions taken.
