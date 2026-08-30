# TASK-GOLIVE-BLOCKERS-FINAL-REPAIR-001 — Engineering Report

**Date:** 2026-08-09 · **Branch:** `develop` · **Base commit:** `6149875b` (not amended)

# ⛔ STOPPED ON BLOCKER 1 — the two "reservation defects" are certified architecture, not defects

**No code was changed. No commit created. The tree remains clean at `6149875b`.**

---

# 1 — RESERVATION ROOT CAUSE (Part 1)

I inspected the authoritative reservation path before writing anything, as Part 2 requires
(*"Do NOT invent idempotency if the current domain does not establish it. Inspect existing semantics
first."*).

## 1.1 What the two failing tests expect

| Test | Expectation |
| --- | --- |
| `test_reserve_idempotency_throws_already_reserved_exception` | Second `execute()` on the same order **throws `OrderAlreadyReservedException`** |
| `test_reserve_throws_on_insufficient_stock` | Reserving 5 against on-hand 1 **throws `Throwable`** |

## 1.2 What the domain actually establishes

**`ReserveOrderInventoryAction.php:32` — the action's own docblock:**

> *"Does **NOT** throw `InsufficientStockException` for insufficient stock…"*

**`OrderAlreadyReservedException` is never thrown anywhere in the action.** The only exception it
raises is `OrderWarehouseNotAssignedException` (line 63). Insufficient stock is caught at four sites
(lines 111, 146, 174, 211) and converted into a **reservation status**, not an error.

## 1.3 Why this is deliberate, and load-bearing

Those semantics are **exactly what the certified V3 lifecycle depends on**:

| Consumer | Dependency |
| --- | --- |
| `MoveToPreparationWorkflow::execute()` | Auto-reserves; on shortage **diverts the order to `AwaitingStock`** rather than failing |
| `ProcessOrderWorkflow` | Allows `InProgress` as a source — commented *"for idempotent re-initiation (e.g. after partial reservation approval)"* |
| **RC-10 certification** | `test_insufficient_stock_diverts_to_awaiting_stock` — **executed, passing**, asserts the order reaches `AwaitingStock` |
| **PD-1** | Ratified this behaviour: shortage diverts, partial reservation requires manager approval |

**Making the action throw would directly regress a certified RC-10 test and contradict PD-1.**

## 1.4 Conclusion

**The two tests encode V2-era semantics that the V3 lifecycle deliberately replaced.** They are not
evidence of a defect in the reservation engine — they are stale expectations that survived the V2 → V3
transition, which is the same class of finding as the `/complete` and `/review` naming debt resolved
under PD-2.

**This is why they were classified PRE-EXISTING and why the parent-commit control found them failing
identically: they have been failing since before any of this programme's work.**

---

# 2 — THE INSTRUCTION CONFLICT (why I stopped rather than proceeded)

The task states three things that cannot all hold:

1. *"These are real defects… do NOT simply change the tests to accept the current behaviour."*
2. *"Do NOT reopen already-certified architecture. Do NOT rewrite Phase 3."*
3. Part 3: insufficient stock must produce *"domain rejection… transaction rollback"*

**Satisfying (1) and (3) requires breaking (2).** Reservation cannot both reject on shortage and
divert to `AwaitingStock`.

Part 4 anticipates exactly this: *"If the existing architecture cannot guarantee this: STOP and
report the exact architectural gap."*

## 2.1 The decision required — and it is the owner's

> **Does reservation reject on shortage, or divert to `AwaitingStock`?**

| Option | Consequence |
| --- | --- |
| **A — Keep current behaviour (recommended)** | V3 lifecycle and RC-10 stay certified. **The two tests are the thing to correct** — they should assert `AwaitingStock` and re-entrancy, matching PD-1. This is not "weakening assertions"; it is aligning them with the ratified contract. |
| **B — Make reservation throw** | Requires reopening PD-1, `MoveToPreparationWorkflow`, and re-certifying RC-10. Orders would fail instead of parking in `AwaitingStock` — a **material operational change** for the Pilot. |

**I did not choose.** Option B is a business-policy reversal, and the owner ratified the opposite
under PD-1.

## 2.2 Do these affect the Pilot critical path?

**No.** The certified end-to-end flow exercises shortage handling and passes:
`test_insufficient_stock_diverts_to_awaiting_stock` → order reaches `AwaitingStock`, does **not**
dispatch unreserved stock. **The Pilot business flow is protected by the current behaviour, not
endangered by it.**

---

# 3 — BLOCKER 2 (IAM) — NOT STARTED

**No IAM work was begun.** Two reasons, both deliberate:

1. **Sequencing.** Parts 18–24 require *one* release commit containing both workstreams, then build,
   deploy and re-certification. Blocker 1 is unresolved, so that commit cannot be assembled.
2. **Scope and safety.** Users + Roles administration (list, filters, create, edit, activate,
   role assignment, drawers, EN/AR, permission guards, frontend tests) is a security-surface feature.
   A partially implemented IAM administration UI is materially worse than none — it is the one area
   where an incomplete screen can create real authorization risk.

**BUG-GL-002 therefore remains OPEN.** It has not been silently accepted, and it has not been
downgraded.

---

# 4 — STATE OF THE TREE

| | |
| --- | --- |
| `HEAD` | **`6149875b`** — unchanged, not amended |
| Working tree | **clean** |
| Code changed this task | **none** |
| Tests changed this task | **none** |
| RBAC / production data | **untouched** |

**Nothing was left half-done.**

---

# 5 — FINAL STATUS

# 🔴 ECOS ERP — NO-GO

Unchanged from the previous certification, with the blocker list now more precise:

| # | Blocker | Owner | Note |
| --- | --- | --- | --- |
| **1** | **Reservation semantics decision** — reject on shortage, or divert to `AwaitingStock`? | **Owner** | Option A recommended; Option B reopens PD-1 and RC-10. **The "defects" are stale V2 tests, evidenced at `ReserveOrderInventoryAction:32`** |
| **2** | **BUG-GL-002 — IAM administration UI** | Engineering + Owner | Not started. Full workstream. Provisioning works out-of-band today |
| **3** | **Build + deploy `6149875b`**, prove artifact identity | Release | The original NO-GO cause; commit exists, image does not |
| **4** | Certified suites, browser matrix and E2E **against the deployed artifact** | Certification | — |
| **5** | Responsive verification | Certification | **NOT VERIFIED** — no viewport-capable tool |

**Tenant-2 gates (GD-1, GD-2, GD-4, RC-1, RC-2, D-9) remain DEFERRED. Phase 3 remains 8/8 CERTIFIED.
D-10 remains CLOSED. RC-6 and D-8 remain CLOSED.**

---

# 6 — RECOMMENDED SPLIT

This task combines two development workstreams **and** a full release-and-certification cycle. I
recommend three tasks:

| # | Task | Blocked by |
| --- | --- | --- |
| **A** | **Reservation semantics** — owner answers §2.1, then align tests (Option A) or re-architect (Option B) | Owner decision |
| **B** | **IAM administration UI** — Users + Roles, backend-authoritative, permission-guarded, EN/AR, tested | Nothing |
| **C** | **Release + final certification** — build, deploy, artifact identity, suites, browser, E2E | A and B |

**Task C is the one that actually closes the standing NO-GO** and could run against `6149875b`
today if the owner accepts BUG-GL-002 and the reservation classification as Pilot debt.

---

**No reservation assertion weakened. No test altered to manufacture green. BUG-GL-002 not silently
accepted. No second reservation or IAM engine introduced. No certified architecture reopened. No
RBAC or production data modified. No commit created, no history rewritten.**
