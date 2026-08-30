# TASK-GOLIVE-IAM-ADMIN-IMPLEMENTATION-001 — Engineering Report
## IAM Administration — Backend Contract Survey

**Date:** 2026-08-09 · **Base:** `6149875b` · **No code, test or commit change.**

---

# ⛔ STOPPED AT PART 1 — the backend contract does not exist

**BUG-GL-002 is not a frontend gap. There is no IAM administration API to build a UI against.**

Part 1 is explicit: *"Do NOT invent endpoints. If an operation has no backend contract: mark it
**NOT AVAILABLE** rather than creating a fake UI action."*

**Every operation this task specifies is NOT AVAILABLE.**

---

# 1 — BACKEND CONTRACT SURVEY (Part 1)

## 1.1 Evidence

| Check | Result |
| --- | --- |
| IAM controllers | **1** — `Modules/IAM/Presentation/Http/Controllers/AuthController.php` (authentication only) |
| IAM route files | **0** — `find Modules/IAM -path "*outes*"` returns nothing |
| IAM controller imports in `routes/api.php` | **0** |
| User/role administration routes in `routes/api.php` | **0** — the only `permissions` matches are Meta connector (`:1063-1064`) and vehicle maintenance (`:2725`), both unrelated |

## 1.2 Operation-by-operation

| Operation | Backend contract |
| --- | --- |
| List users · search · filter | ❌ **NOT AVAILABLE** |
| Create user · edit user | ❌ **NOT AVAILABLE** |
| Activate / deactivate | ❌ **NOT AVAILABLE** |
| Assign role · assign company | ❌ **NOT AVAILABLE** |
| List roles · role detail · permissions view | ❌ **NOT AVAILABLE** |
| Create / edit role · assign permissions | ❌ **NOT AVAILABLE** |
| **Authenticate / `/auth/me`** | ✅ **AVAILABLE** — `AuthController` |

## 1.3 What *does* exist — and why this is recoverable

The **domain layer is built and tested**; only the HTTP surface is missing:

| Component | Status |
| --- | --- |
| `UserIdentityService`, `UserLifecycleService`, `UserRoleAssignmentService`, `UserOrganizationAssignmentService`, `UserInvitationService`, `UserSessionService` | ✅ Exist, exercised by `UserManagementTest` (86 tests) |
| `RoleTemplateCompiler`, `RoleTemplateRepositoryInterface` | ✅ Exist |
| `PermissionService`, `ScopeResolver`, `AuthorizationGateway` | ✅ Exist |
| `UserPolicy` + `Gate::policy(User::class, …)` | ✅ Registered (`IamServiceProvider:89`) |
| `iam.users.*` permission catalog | ✅ Defined in `config/permissions.php` |

**IAM-004 built the identity layer as services and never exposed it over HTTP.**

---

# 2 — WHY I DID NOT PROCEED

Parts 2–11 all presuppose a data source. With no endpoints there are exactly two ways to produce the
requested screens, and the task forbids both:

1. **Invent endpoints** — forbidden by Part 1, and it would mean designing an IAM security surface
   (authorization, tenant scoping, validation, policies) inside a task scoped to frontend work.
2. **Build UI with no data source** — a fake, forbidden by Part 1 and by the absolute rule.

**A partially built IAM administration screen is the single worst artifact this programme could
produce.** It is the one surface where an incomplete implementation creates real authorization risk,
and it would have to pass through the same certification standard everything else has met.

**Nothing was started, so nothing is left half-done.**

---

# 3 — WHAT THIS CHANGES ABOUT BUG-GL-002

| | Previous understanding | Actual |
| --- | --- | --- |
| Nature | Frontend gap — routes point to `ComingSoonPage` | **Missing backend module + frontend** |
| Scope | One workstream | **Two** — HTTP surface for IAM, then the workspaces |
| Risk | UI work | **Security-surface design** (authorization, tenant scoping, policy wiring) |

**The previous classification understated it.** `ComingSoonPage` is a symptom, not the cause.

---

# 4 — TENANT / RBAC SAFETY (Parts 5, 7, 12)

**No RBAC data was read for mutation, no role template reseeded, no role deleted, no permission
invented.** `ScopeResolver`, RC-6, D-8 and GD-1 untouched. Reservation, Recipe, Inventory,
Fulfillment, Orders, Procurement, FIFO and `allow_negative_stock` untouched — this task read
`routes/api.php` and the IAM module listing only.

**17/40 role templates:** ⏸️ **NOT RE-VERIFIED.** Without an administration surface the state cannot
be surfaced, and reseeding is forbidden.

---

# 5 — FINDINGS

| # | Finding | Severity |
| --- | --- | --- |
| **F1** | **No IAM administration HTTP surface exists** — 1 controller (auth), 0 routes | **High — reframes BUG-GL-002** |
| **F2** | The IAM **domain layer is complete and tested** (86 IAM tests); only the HTTP layer is absent | Positive — reduces risk |
| **F3** | Provisioning today is **out-of-band only** — services invoked directly, as this programme did throughout | Operational |
| **F4** | Role-template reconciliation **cannot be surfaced or resolved** without the surface | Medium |

---

# 6 — BUG-GL-002 FINAL STATUS

# ⏸️ **OPEN — not started, not accepted, not downgraded**

**Not closable in this task.** Success criterion *"Users: functional within existing backend
capabilities"* evaluates to **nothing is functional**, because the existing backend capability set at
the HTTP layer is empty.

---

# 7 — ENGINEERING RECOMMENDATION

Split into two sequential tasks:

| # | Task | Content |
| --- | --- | --- |
| **A** | **IAM HTTP surface** | Controllers, routes, FormRequests, Resources over the **existing** services. Reuse `UserPolicy`, `iam.users.*` and `RequirePermissionMiddleware` — **no new permission, no second RBAC engine**. Backend tests for authorization and tenant scoping |
| **B** | **IAM workspaces** | Users + Roles against the Task-A contract, using `UniversalDataGrid`/`SmartToolbar`/existing drawers, EN + AR, the 10 frontend tests from Part 9 |

**Task A is the security-sensitive one and deserves its own certification pass.**

## Pilot impact

**Not a Pilot blocker on current evidence.** OD-2 = PILOT means a single tenant, and provisioning
demonstrably works out-of-band. **It becomes mandatory before tenant #2**, when self-service user
administration stops being optional.

**Formal acceptance as Pilot debt remains an owner decision — I cannot accept business debt.**

---

# 8 — EXACT NEXT TASK

**`TASK-GOLIVE-IAM-HTTP-SURFACE-001`** — expose the existing IAM services over HTTP with policy and
tenant enforcement, backend tests only. Then `TASK-GOLIVE-IAM-WORKSPACES-001` for the UI.

---

**No endpoint invented. No fake UI action. No second IAM/RBAC engine. No RBAC data modified. No role
template reseeded. No unrelated module touched. No deployment, no release commit — as instructed.**
