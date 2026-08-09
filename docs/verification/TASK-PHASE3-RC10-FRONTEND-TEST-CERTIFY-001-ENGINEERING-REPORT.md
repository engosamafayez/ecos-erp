# TASK-PHASE3-RC10-FRONTEND-TEST-CERTIFY-001 — Engineering Report

**Date:** 2026-08-09 · **Worktree:** `develop` @ `C:\ecos-develop` · Host PHP 8.4.22 · Vitest 4.1.10

# ✅ RC-10 = CERTIFIED · PHASE 3 = 8/8 CERTIFIED

| Gate | Result |
| --- | --- |
| Frontend refusal tests | ✅ **7 passed (7)** — 6 required + 1 extra |
| Backend RC-10 runtime | ✅ **`OK (40 tests, 203 assertions)`** |
| Guardian pre-push | ✅ **`GUARDIAN_EXIT=0`** (8/8) |
| TypeScript | ✅ **baseline 24 held** |
| ESLint · PHPStan · Vite | ✅ PASS |
| i18n / EN-AR / RTL | ✅ 0 missing, parity held |

---

# 1 — TEST INFRASTRUCTURE (Part 4)

Reused the existing stack, nothing new introduced: **Vitest + jsdom + @testing-library/react**,
`vitest.config.ts`, `src/test-setup.ts`, following the established component-test pattern from
`new-count-dialog.test.tsx` (`vi.hoisted` for shared refs, `vi.mock` factories, `userEvent`).

**One behaviour-neutral source change:** `WorkflowTab` was `export`ed so it can be rendered directly.
No logic altered.

---

# 2 — THE REAL PATH IS EXERCISED (Part 3)

Only the **mutation hook** is stubbed, so the test controls resolve/reject. The component's own
extraction and rendering are never bypassed:

```
axios error  ->  axios.isAxiosError()  ->  response.data.message
             ->  serverRefusalMessage()  ->  refusal state  ->  role="alert"
```

Tests construct a genuine `AxiosError` with a real `AxiosHeaders` response, so
`axios.isAxiosError()` evaluates for real. **The original defect — `transition.mutate()` with no
`onError` — is now permanently pinned: removing `onError` fails five of these tests.**

The selector-mode `t()` resolves against the **real** `en/orders.json` and `ar/orders.json` bundles,
so a renamed or missing key fails the test rather than silently rendering a path.

---

# 3 — THE SIX REQUIRED TESTS (Part 2)

| # | Test | Evidence |
| --- | --- | --- |
| **1** | Success path preserved | `onClose` called exactly once; **no alert rendered** |
| **2** | Backend refusal displayed | Alert contains `"Transition from [in_progress] to [delivered] is not allowed."` **verbatim**; `onClose` **not** called |
| **3** | Fallback only when no message | Response `data: {}` → alert shows `drawer.workflow.refusalFallback` from the real EN bundle; `onClose` not called |
| **4** | Order state not mutated | `order` prop still `in_progress`; the action is **still offered**; alert present (no success affordance) |
| **5** | Drawer remains usable | Action still **enabled** after refusal; a retry then succeeds — `mutate` called **twice**, proving no stuck loading or latched success state |
| **6** | EN / AR | AR fallback renders, is **non-empty**, and is **asserted different from EN** (so a copy-paste stub would fail) |

**Plus one extra:** a backend message renders **verbatim under AR**, proving the server's reason is
displayed rather than re-translated — the localization contract the implementation relies on.

```
Test Files  1 passed (1)
     Tests  7 passed (7)
```

---

# 4 — TWO CORRECTIONS DURING THE RUN

Both were **my** errors, fixed at source — no assertion weakened, nothing skipped:

1. **`getByTestId` throws when absent.** Test 4 originally probed a status-badge testid that does not
   exist. Rewritten to assert what actually proves the point: the order prop is unchanged and the
   action is still offered.
2. **ESLint `ecos-i18n/no-hardcoded-ui-strings`** flagged the fixture's `label:` string — twice,
   including my first replacement. Resolved by binding the label to a constant rather than a literal.
   **No suppression, no rule change.**

---

# 5 — FRONTEND SUITE REGRESSION

Full suite: **6 failed | 79 passed (85)**. All 6 failures are in
`new-count-dialog.test.tsx` — a file this task never touched.

**Proven PRE-EXISTING by control:**

| Run | Result |
| --- | --- |
| CURRENT (all frontend work applied) | **6 failed \| 4 passed** |
| PARENT (`git checkout -- frontend/`) | **6 failed \| 4 passed** |

Identical. Frontend restored afterwards and marker-verified: 8 changed files,
`serverRefusalMessage` ×2, `refusalFallback` present in **both** locales, Step 2's
`AvailabilityState` markers intact. **Not modified, not reclassified.**

---

# 6 — BACKEND RC-10 REGRESSION (Part 7)

```
Rc10LifecycleCertificationTest + V3TransitionResolutionTest
OK (40 tests, 203 assertions)
```

Covers D-10 regression, Dispatch → Delivered with FIFO consumption (10 → 8 on both layer and
on-hand), both warehouse gates, shortage → `AwaitingStock`, invalid transition, unauthorized,
cross-company, bulk, dedicated routes and audit behaviour. **No backend file was modified in this
task** (Part 5 honoured); this confirms it.

---

# 7 — VALIDATION (Part 6)

| Gate | Result |
| --- | --- |
| New frontend tests | ✅ 7/7 |
| **Guardian pre-push** | ✅ **`GUARDIAN_EXIT=0`** |
| TypeScript | ✅ **baseline 24** — ratchet confirmed no new errors |
| ESLint | ✅ PASS |
| Vite production build | ✅ PASS |
| PHPStan L0 / L6 | ✅ PASS (via Guardian) |
| i18n missing keys | ✅ **0** |
| EN/AR parity | ✅ Both bundles carry the two keys; asserted distinct |
| RTL | ✅ No directional classes |
| `--no-verify` · suppressions · Guardian edits · skipped tests | ✅ None |

---

# 8 — CERTIFICATION MATRIX (Part 8)

| Criterion | Result |
| --- | --- |
| Success transition | ✅ **PASS** — executed |
| Backend refusal message | ✅ **PASS** — executed |
| Missing-message fallback | ✅ **PASS** — executed |
| Order state unchanged | ✅ **PASS** — executed |
| Drawer remains usable | ✅ **PASS** — executed |
| EN/AR behaviour | ✅ **PASS** — executed |

**No criterion marked PASS without an executed test.**

---

# 9 — RC-10 FINAL RULE (Part 9)

| Criterion | Status |
| --- | --- |
| Six frontend tests PASS | ✅ 7/7 |
| Frontend regression | ✅ Only pre-existing failures, control-proven |
| D-10 CLOSED | ✅ |
| Backend regression | ✅ 40/40 |
| TypeScript 24 · ESLint · i18n 0 · EN/AR parity | ✅ |
| PHPStan · Guardian | ✅ |
| No new regression | ✅ |

# RC-10 = CERTIFIED

---

# 10 — PHASE 3 (Part 10)

| Step | Status |
| --- | --- |
| 1 — Availability State | ✅ **CERTIFIED** |
| 2 — Product Availability UI | ✅ **CERTIFIED** |
| 3 — Product Stats/List Reconciliation | ✅ **CERTIFIED** |
| 4 — Transition guards wired | ✅ **CERTIFIED** |
| 5 — Write path on V3 vocabulary | ✅ **CERTIFIED** |
| 6 — Read/write consistency | ✅ **CERTIFIED** |
| 7 — V2 vocabulary retired | ✅ **CERTIFIED** |
| 8 — `stock_status` write path closed | ✅ **CERTIFIED** |

# PHASE 3 = 8/8 CERTIFIED

---

# 11 — EXACT REMAINING GO-LIVE BLOCKERS

**None inside Phase 3.**

| # | Item | Owner | Note |
| --- | --- | --- | --- |
| 1 | **Tenant-2 gate** — GD-1 platform-wide classification, GD-2 governance, GD-4 exports, RC-1, RC-2, **D-9 `ScopeResolver`** | Owner | Deferred by **OD-2 = PILOT**. Must be technically enforced — RC-1 is invisible on a single-company system |
| 2 | **Production-admin audit** for any future production database | Ops | `ecos_erp` verified; re-run the documented query before any new instance |
| 3 | **Pre-existing defects, outside Phase 3** — 2 `OrderReservationLifecycleTest`, 3 `InventoryCountSessionTest`, 6 `new-count-dialog.test.tsx`; all control-proven pre-existing | Engineering | Backlog |
| 4 | *(Optional)* runtime-execute the 7 remaining dedicated routes | Engineering | 8/15 runtime PASS today |

---

**No UI redesign. No backend lifecycle change — zero backend files modified. D-10, PD-1, PD-2 not
reopened. Reservation, FIFO, inventory, tenant isolation and transition routing untouched. No
assertion weakened, no test skipped, no suppression, no `--no-verify`, no Guardian modification.**

**Final Go-Live Certification not started.**
