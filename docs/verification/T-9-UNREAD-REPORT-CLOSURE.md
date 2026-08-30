# T-9 — Unread Report Closure

**Date:** 2026-08-15 · **Mode:** read-only audit
**Status:** **PARTIAL — 1 of ~46 closed in substance, and it was the one you named**

---

## 1. Honest coverage statement

You directed T-9 to start with `TASK-UAT-006-sales-orders` because it carries **NO-GO** and
sits inside Orders. That report is now closed in substance, its four findings extracted, and
each one's **current** state determined against today's code — which is the part that
matters, because the report is dated 2026-08-08 and predates ADR-042, ADR-027 and the
Preparation work.

Doing that properly surfaced a **new defect that no report records** (§3). It also consumed
the session budget that the remaining ~45 reports need.

**T-9 remains open.** The remaining reports are listed in `MASTER-FINDINGS-MATRIX.md §7`,
grouped so the work can be split. Claiming Phase 0 complete now would be exactly the
"assumed" status your rules forbid.

---

## 2. TASK-UAT-006-sales-orders — closed

**Verdict in report:** NO-GO (2026-08-08, UI-only campaign, no SQL/Tinker/mutation).
**Headline:** *"the strongest module audited … but it advertises inventory it cannot honour."*

| ID | Finding | P | State **today** | Evidence | Classification |
|---|---|---|---|---|---|
| **UAT6-001** | An order can be advanced toward shipping with **no reservation and no warehouse** — `Mark Ready` offered as primary while the same order's Inventory tab reads `Not Reserved`, `Assigned Warehouse: —` | **P0** | **Backend half FIXED.** `MoveToPreparationWorkflow` now guards: `assigned_warehouse_id === null` returns early without transitioning (`:88-104`); `AwaitingStock` result does not transition (`:110-140`); terminal reservation states and unapproved partial reservations throw `WorkflowPreconditionException` (`:43,56,66`). **UI half NOT fully fixed — see F-ORD-14** | CODE-VERIFIED | **FIXED (backend) + STILL OPEN (UI)** |
| **UAT6-002** | Orders carry **no tax**. `Tax N/A`, `Products Total = Grand Total`. Campaign 1 found no tax configuration screen anywhere | **P1** | **Not re-verified.** No tax work appears in any report since | — | **PRE-EXISTING — PRODUCT DECISION REQUIRED** |
| **UAT6-003** | **No Quotations, Order Approval, Returns workflow or Reports.** `RETURNED` exists as a status the system can display but cannot reach | **P1** | **Not re-verified.** Effort XL; no report claims delivery | — | **OUT OF SCOPE — MISSING CAPABILITY (roadmap)** |
| **UAT6-004** | Order search requires **Enter**, no debounce feedback | **P3** | Not re-verified | — | **PRE-EXISTING — DEFER** |

### 2.1 Why UAT6-001 could not simply be marked fixed

The backend guard is real and I read it. But UAT6-001 is a *UI* finding — it is about what
the operator is offered and told. Verifying the backend only would have closed a P0 on half
the evidence. Checking the other half is what produced §3.

### 2.2 Scope limits the report itself declares

`No order was created and no transition executed.` Therefore **allocation, fulfilment,
partial fulfilment, cancellation, returns, order-driven notifications and order tenant
isolation are UNVERIFIED** by that campaign, and remain so. These are not new findings; they
are declared gaps that must not be read as passes.

---

## 3. NEW finding discovered while closing UAT-006

### F-ORD-14 — the transition-refusal fix reached one of four surfaces · **S1**

`frontend/src/features/orders/components/workflow-tab-refusal.test.tsx` documents the defect
and its fix:

> *"The original defect: `transition.mutate(..., { onSuccess: done })` carried no `onError`,
> so every backend refusal was silently swallowed — the drawer did nothing and the operator
> got no feedback."*

That fix landed in **`order-detail-drawer.tsx:1423`** (the `WorkflowTab`), with a good
comment and a certification test. **Three sibling surfaces were never fixed:**

| Surface | Line | Handling |
|---|---|---|
| `order-detail-drawer.tsx` (`WorkflowTab`) | 1423 | ✅ `onError` → renders refusal, keeps drawer open |
| `order-workflow-actions-panel.tsx` | 97 | ❌ `{ onSuccess: done }` — **no `onError`** |
| `smart-status-selector.tsx` | 92-101 | ❌ `{ onSuccess: … }` — **no `onError`** |
| `order-detail-page.tsx` | 1346 | ❌ `transition.mutate({ id, targetStatus })` — **no callbacks at all** |

The certification test renders `WorkflowTab` only (`:45,73`), so its green result says
nothing about the other three.

**Why this is severity S1 and not cosmetic.** It is the *other half of UAT6-001*. The backend
now correctly refuses `Mark Ready` on an unreserved, unwarehoused order — and on three of
four surfaces that refusal is **invisible**: the operator clicks, nothing happens, no error,
no explanation. That is arguably worse than the original finding, where at least the action
appeared to work. It also means the RC-10 pattern ("orchestration without enforcement") is
now "enforcement without feedback" on those surfaces.

**Not fixed here**, per your instruction to record rather than opportunistically repair.
Assigned to **T-4** (UI/API parity repairs), which already exists.

---

## 4. Reports closed / remaining

| | Count |
|---|---|
| Closed in substance this session | **1** (`TASK-UAT-006-sales-orders`) |
| Previously closed (matrix v1) | 24 |
| **Remaining unread** | **~45** |

Remaining set unchanged from `MASTER-FINDINGS-MATRIX.md §7`, minus UAT-006. Recommended next
order, highest expected yield first:

1. `TASK-UAT-005-inventory`, `TASK-UAT-008-crm`, `TASK-UAT-003-procurement` — same campaign
   format as UAT-006, so same density of concrete P0/P1 findings
2. Pricing/Cost (8) — wholly uninvestigated domain
3. Recipe/BOM (7) — feeds Products and Preparation
4. GOLIVE-PREPARATION (9) — likely largely superseded by CROSS-DAY-TRANSITION-002; cheap to
   close but must be *proven* superseded, not assumed
5. Customers (5), Inventory (5)

---

## 5. Compliance

Read-only. No production code, schema, data or configuration modified. No repair executed.
No existing task duplicated — F-ORD-14 was assigned to the existing **T-4** rather than
creating a new task.
