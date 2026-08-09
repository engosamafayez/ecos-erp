# TASK-PHASE3-RC10-UI-CLOSE-001 — Engineering Report

**Date:** 2026-08-09 · **Worktree:** `develop` @ `C:\ecos-develop` · Host PHP 8.4.22

| | |
| --- | --- |
| **UI refusal reason** | ✅ **IMPLEMENTED** |
| **RC-10** | ⚠️ **NOT CERTIFIED** — criteria 1–15 met except frontend tests (Part 6). §6 |
| **Guardian** | ✅ `GUARDIAN_EXIT=0` · TypeScript baseline **24** held · PHPStan ✅ |

---

# 1 — EXISTING REFUSAL CONTRACT (Part 1)

No new schema was invented. Both refusal shapes the backend already produces carry the reason on
**`message`**:

| Source | Shape |
| --- | --- |
| Routing table refusal (`FulfillmentController::transition`) | `422` · `{"message": "Transition from [in_progress] to [delivered] is not allowed."}` |
| Workflow guard failure (`WorkflowPreconditionException` → `bootstrap/app.php:114`) | `422` · `ApiResponse::error($e->getMessage(), 422)` → `{success, message, data, errors}` |
| Missing permission | `403` (middleware) |
| Cross-company / unknown order | `404` (route-model binding under the tenant scope) |

**`message` is the single field common to every shape** — verified against the certified runtime
suite, which asserts the exact 422 body.

---

# 2 — THE DEFECT FOUND

`order-detail-drawer.tsx` called:

```ts
transition.mutate({ id, targetStatus, reason }, { onSuccess: done });
```

**There was no `onError` at all**, and `useWorkflowMutation` defines none either. Every refusal was
**silently swallowed** — the drawer simply did nothing: no message, no toast, no state change. The
operator received no feedback whatsoever.

---

# 3 — IMPLEMENTATION (Parts 2, 3, 5)

**Three files. No new error architecture, no new backend contract.**

| File | Change |
| --- | --- |
| `order-detail-drawer.tsx` | `serverRefusalMessage()` helper matching the house pattern (`axios.isAxiosError` + `response?.data?.message`, as used by the warehouse / branch / supplier drawers); `refusal` state; `onError` on the transition mutation; a `role="alert"` block rendering the backend text verbatim |
| `en/orders.json` | `drawer.workflow.refusalTitle`, `drawer.workflow.refusalFallback` |
| `ar/orders.json` | Same two keys, Arabic |

**Behaviour on refusal:** `setRefusal(null)` before each attempt; on error the backend message is
stored and rendered; **`onSuccess` alone closes the drawer**, so a refusal leaves it open with the
unchanged order and the action still available. No optimistic mutation existed and none was added.

**Part 5 — no business logic in the UI.** The component contains no state, stock or warehouse
predicate. It requests the transition, receives the result, and renders success or the server's
reason. The only conditional added is `refusal !== null`.

**RTL safety:** the alert uses `rounded-md border p-3 text-sm` plus `mt-1` — no directional
(`ml-`/`pl-`/`left-`) classes, so it mirrors correctly.

**Localization:** two keys, EN and AR, added inside the existing `drawer.workflow` block in both
files; both parsed as valid JSON. The **backend reason is displayed verbatim, not re-translated** —
the fallback string is used only when the response genuinely carries no `message`.

---

# 4 — REFUSAL SCENARIOS (Part 4)

Each is already proven at the backend by the certified runtime suite; the drawer now surfaces all of
them through one path, because all four arrive as an axios error with `response.data.message` (or, for
403/404, the fallback).

| # | Scenario | Backend (certified) | UI |
| --- | --- | --- | --- |
| 1 | Invalid transition | 422 + exact reason | Reason displayed verbatim |
| 2 | Unauthorized | 403 | Refusal displayed (fallback if no body) |
| 3 | Warehouse refusal at dispatch | Refused, rolled back | Guard reason displayed |
| 4 | Stock-related refusal | Shortage diverts to `AwaitingStock` (success, not refusal); guard failures return 422 | Reason displayed when a 422 occurs |

> **Note on scenario 4:** the certified backend behaviour for insufficient stock is a **successful
> transition to `AwaitingStock`**, not a refusal. There is therefore no stock *refusal* to display on
> that path. Recorded rather than manufactured.

---

# 5 — BACKEND REGRESSION (Part 8)

```
Rc10LifecycleCertificationTest + V3TransitionResolutionTest
........................................                          40 / 40 (100%)
OK (40 tests, 203 assertions)
```

Covers D-10 regression, Dispatch → Delivered with FIFO consumption, both warehouse gates, shortage,
invalid transition, unauthorized, cross-company, bulk, dedicated routes and audit behaviour.
**No backend regression from the UI-only change** — as expected, since no backend file was touched.

---

# 6 — ⚠️ WHAT IS NOT DONE — Part 6 (frontend tests)

**No frontend tests were added.** Part 6 requires six cases (success path, refusal displayed,
fallback only when no structured reason, no false state change, drawer usable, EN/AR rendering).

**This is not a stop condition — it is unfinished work, and I am stating it plainly rather than
certifying around it.** Part 10 criterion 1 ("UI refusal reason is implemented") is met; criteria 2–7
are implemented and statically verified but **not proven by an executed frontend test**.

The programme's own standard — established across RC-6, D-8 and RC-10 — is that implementation plus
static validation is **not** certification. Applying that standard to my own work here:

# RC-10 = NOT CERTIFIED

---

# 7 — VALIDATION (Part 7)

| Gate | Result |
| --- | --- |
| **Guardian pre-push** | ✅ **`GUARDIAN_EXIT=0`** (8/8) |
| TypeScript | ✅ **baseline 24 held** — the ratchet confirmed no new errors |
| ESLint | ✅ PASS |
| Vite production build | ✅ PASS |
| PHPStan L0 / L6 | ✅ PASS (via Guardian) |
| i18n | ✅ **+2 keys in EN and AR** — parity held, both files valid JSON, **0 missing** |
| RTL | ✅ No directional classes added |
| `--no-verify` · suppressions · Guardian edits · baseline normalization | ✅ None |

---

# 8 — PRE-EXISTING FAILURES (Part 9)

Unchanged and untouched, both previously proven by parent-commit control:

- **2** `OrderReservationLifecycleTest` failures
- **3** `InventoryCountSessionTest` failures

Not reclassified, not modified.

---

# 9 — RC-10 CERTIFICATION (Part 10)

| # | Criterion | Status |
| --- | --- | --- |
| 1 | UI refusal reason implemented | ✅ |
| 2–5 | Invalid / unauthorized / warehouse / stock refusals display | ⚠️ Implemented; **not test-proven** |
| 6 | Order state not falsely mutated | ⚠️ Implemented (`onSuccess`-only close); **not test-proven** |
| 7 | Drawer remains usable | ⚠️ Implemented; **not test-proven** |
| 8 | TypeScript baseline 24 | ✅ |
| 9 | ESLint | ✅ |
| 10 | i18n missing keys 0 | ✅ |
| 11 | PHPStan | ✅ |
| 12 | Guardian | ✅ |
| 13 | D-10 regression green | ✅ |
| 14 | Dispatch → Delivered green | ✅ |
| 15 | No new backend regression | ✅ |

**11 of 15 fully met; 4 implemented but unproven.**

---

# 10 — PHASE 3 STATUS

**Certified: 4 / 8** (Steps 1, 2, 3, 8). Steps 4–7 implemented with 40/40 backend runtime green.

**Phase 3 is NOT 8/8. Final Go-Live Certification must not begin.**

---

# 11 — EXACT REMAINING WORK

| # | Item | Size |
| --- | --- | --- |
| **1** | **Part 6 frontend tests** — 6 cases against `WorkflowTab` using the existing frontend testing architecture. **The only RC-10 blocker.** | Small |
| 2 | *(Optional)* runtime-execute the 7 remaining dedicated routes | Medium |
| 3 | *(Backlog, outside Phase 3)* 2 reservation + 3 inventory-count pre-existing defects | Engineering |

---

**D-10, PD-1, PD-2 not reopened. Fulfillment engine, reservation, FIFO and tenant isolation
untouched — no backend file was modified in this task. No new error-handling architecture, no new
backend contract, no suppression, no `--no-verify`. Final Go-Live Certification not started.**
