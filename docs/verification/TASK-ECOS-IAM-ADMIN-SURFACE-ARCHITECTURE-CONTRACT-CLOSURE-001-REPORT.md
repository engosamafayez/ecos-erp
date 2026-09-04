# TASK-ECOS-IAM-ADMIN-SURFACE-ARCHITECTURE-CONTRACT-CLOSURE-001

**Workstream:** ECOS ERP — IAM Closure · Batch 01 · Task 1 of 4
**Type:** Architecture & Design / Contract Closure — **FINAL RATIFICATION**
**Owner:** A2 — IAM
**Workspace:** `D:\ECOS-Work\ecos-iam` · **Branch:** `task/iam-workstream`
**Verified starting HEAD:** `16b0ec85df5774f03ccd6dca042528260d66c216`
**Write mode:** Architecture / contract documentation only — no implementation performed
**Date:** 2026-09-02 · **Revision:** Final — all 14 decisions ratified, Task 1 closed

**Status block:**

| Final Status | Architecture | All CTO decisions | Implementation | Task 2 | Integrated | Dev visible | User verified | Certified |
|---|---|---|---|---|---|---|---|---|
| COMPLETE | APPROVED | RATIFIED | NOT STARTED | ARCHITECTURALLY READY | NO | NO | NO | NO |

**Classification legend:** `CTO APPROVED` · `SECURITY HARD GATE` · `IMPLEMENTATION GAP` · `DEFERRED` · `CLOSED FINDING` · `TASK 2 REQUIREMENT` · `TASK 3 REQUIREMENT` · `TASK 4 REQUIREMENT` — plus the original §3 authority tags (`PRESERVE` / `REUSE` / `DO NOT REIMPLEMENT`), unchanged in meaning. As of this revision, `CTO DECISION REQUIRED` no longer applies to any item — all 14 decisions are ratified.

---

## 1. Final Status

> ## COMPLETE

All 14 CTO decisions (D1–D14) are now **CTO APPROVED**. Every CRITICAL tenant-escape STOP condition has an approved policy answer and every remaining business/operational decision (D4, D5, D6, D8, D9, D10, D12) is ratified with exact, specific rules — not defaults left for Task 2 to guess at. **Task 2 — Secure IAM Admin API is ARCHITECTURALLY READY.** No CTO business-policy hold remains. One implementation-sequencing gate stands inside Task 2 (cache-invalidation safety before broad role/permission/template mutation endpoints ship, §30.2) — this is engineering sequencing, not a release blocker, per explicit CTO instruction.

No implementation occurred in this or any prior revision of this task. This revision's only change is to this report file, plus the one documentation commit recorded in §29.

---

## 2. Existing Capability Gate

*(Unchanged from the original revision — reproduced for continuity.)*

**Inspected in full:** ADR-006, ADR-038, ADR-039, ADR-040, ADR-041; `docs/reports/TASK-IAM-002` through `TASK-IAM-005` engineering reports; `TASK-IAM-HTTP-SURFACE-001` (contract audit, 783 lines + engineering report, 340 lines); `TASK-GOLIVE-IAM-ADMIN-IMPLEMENTATION-001`; `TASK-IAM-PASSWORD-RESET-CONTRACT-001` (417 lines) + `-DOMAIN-OPERATION-001` (610 lines); `TASK-IAM-TENANT-AUTHORIZATION-BOUNDARY-001` (596 lines) + `-IMPLEMENTATION-001` (1114 lines); `TASK-IAM-TEMPLATE-RECONCILIATION-001`; `BUG-GL-011-closure.md`; GO-LIVE certification records. Plus direct reads of ~30 current source files and full `git log`/`git show` verification of every file both audits cited.

1. **What is already implemented?** A complete backend RBAC + 5-engine Authorization Platform + Role Template engine + User Management identity platform (ADR-006, 038, 039, 040), plus frontend authorization primitives (ADR-041), plus Sanctum login/logout.
2. **What is canonical?** `PermissionService`, `AuthorizationGateway`, `RoleTemplateCompiler`/`RoleCompositionService`, `UserLifecycleService`/`UserSecurityRules`, `TenantOwnershipResolver`, `App\Core\Audit\AuditService`, `AuthorizationProvider` + frontend hooks/components.
3. **What is backend-only (no HTTP/UI yet)?** The entire Role Template engine, the entire User Management platform, and the certified password-reset domain operation.
4. **What is a genuine Admin Surface gap?** The HTTP API and the frontend workspace, entirely — confirmed, only `AuthController.php` exists in `Presentation/Http/Controllers`.
5. **What is only a contract/policy gap?** As of this revision: nothing remains open at the policy level. Every item in this category from the original pass now has a ratified rule (§30) — what's left is purely implementation, tracked task-by-task in §31.
6. **What existing STOP condition remains valid?** 8 of 9 in full, 1 partially resolved at the code level — all four CRITICAL items now have ratified policy answers (§4).
7. **What earlier decision/assumption has become obsolete?** Unchanged from prior revisions — only the certified `UserPolicy.php` fix changed source; everything else in this task has been governance layered on top of unchanged code, now complete.

---

## 3. Canonical IAM authority map

*(Unchanged.)*

| Authority | ADR | Classification | Notes |
|---|---|---|---|
| RBAC foundation | ADR-006 | **PRESERVE** | Foundational, untouched |
| 5-engine Authorization Platform | ADR-038 | **PRESERVE** | Sole decision surface |
| Role Template engine | ADR-039 | **REUSE** | Both defects now under fully ratified remediation contracts (§9, §30) |
| User Management / Identity platform | ADR-040 | **REUSE** | All defects now under fully ratified remediation contracts |
| Frontend authorization | ADR-041 | **PRESERVE** | No findings against it |
| Authentication (Sanctum) | — | **PRESERVE** | Login-status gap now a ratified `TASK 2 REQUIREMENT` (D7) |
| Audit | — | **REUSE** | Unchanged |
| `TenantOwnershipResolver` | ADR-007 (context) | **REUSE** | The real enforcement mechanism — §10 |
| `OwnsCompany` interface | ADR-007 | **LEGACY / NOT YET APPLICABLE** | Unchanged |
| `PermissionRegistry::sync()` | ADR-038 | **DO NOT REIMPLEMENT / DO NOT WIRE** | Unchanged |
| `ConfigAuditService`-style parallel audit tables | — | **DO NOT REIMPLEMENT** | Unchanged |

---

## 4. Previous STOP-condition reconciliation

*(Unchanged code-level findings, reproduced; resolution mapping now shows full closure.)*

**9 STOP conditions**, 4 labeled CRITICAL by the source audit (1, 2, 3, 9).

| # | STOP condition | Severity | Code-level status | Policy resolution |
|---|---|---|---|---|
| 1 | `company_id` client-suppliable on CREATE | CRITICAL | STILL VALID in code | **CTO APPROVED — D2.** `TASK 2 REQUIREMENT` to implement. |
| 2 | `company_id` client-suppliable on UPDATE | CRITICAL | STILL VALID in code | **CTO APPROVED — D3.** `TASK 2 REQUIREMENT` to implement. |
| 3 | No tenant scope on LIST | CRITICAL | STILL VALID in code | Never decision-gated — pure `TASK 2 REQUIREMENT`, reinforced by every ratified principle. |
| 4 | 6 permissions missing | BLOCKING | STILL VALID in code | **CTO APPROVED — D4**, exact tokens specified (§30.4). `TASK 2 REQUIREMENT`. |
| 5 | `restore()` broken for `DELETED` | BLOCKING | STILL VALID in code | Pure defect, no decision needed. `TASK 2 REQUIREMENT`, load-bearing for the ratified D1 rule. |
| 6 | No HTTP exception renderer | BLOCKING | STILL VALID in code | Resolved by **D6** (§30.4). `TASK 2 REQUIREMENT`. |
| 7 | Error envelope undefined | BLOCKING | STILL VALID in code | **CTO APPROVED — D6**, exact envelope specified (§30.4). `TASK 2 REQUIREMENT`. |
| 8 | Password-strength policy — no precedent | DECISION | STILL VALID in code | **CTO APPROVED — D5** (§30.4). `TASK 2 REQUIREMENT`. |
| 9 | Templates-only unenforced; `is_system` assignment unguarded | CRITICAL | Cross-company half closed; same-company half STILL VALID in code | Same-company half: **CTO APPROVED — D14.** `TASK 2 REQUIREMENT` to implement. |

**Zero STOP conditions remain awaiting a policy decision.** All nine map to either an already-closed finding, a ratified rule with implementation pending, or a pure code defect that never needed a decision. This is the complete, final reconciliation.

---

## 5. Previous business-decision reconciliation

**All 10 original business decisions (D1–D10), plus all 4 decisions surfaced by this architecture pass (D11–D14), are now CTO APPROVED.** Full ratified text for every one is in §30. This section preserves the original "why it was open" evidence trail per the standing instruction to preserve all source-grounded findings.

| # | Topic | Status | Evidence it was originally open (preserved) |
|---|---|---|---|
| D1 | Password reset vs. account lifecycle | **CTO APPROVED — §30.1** | `adminReset()` had zero status/trashed check |
| D2 | Company ownership on create | **CTO APPROVED — §30.1** | `UserIdentityService.php` unchanged, `company_id` mass-assignable |
| D3 | Company ownership on update | **CTO APPROVED — §30.1** | `IDENTITY_FIELDS` still includes `company_id` |
| D4 | 6 missing permissions | **CTO APPROVED — §30.4** | Catalog unchanged; operations exist, gates missing |
| D5 | Password strength policy | **CTO APPROVED — §30.4** | Zero `Rules\Password` usage anywhere |
| D6 | Error envelope / HTTP contract | **CTO APPROVED — §30.4** | No convention; `docs/07_API_Standards.md` confirmed empty |
| D7 | Login vs. account status | **CTO APPROVED — §30.1** | `canAuthenticate()` had zero callers |
| D8 | Archived users in default listing | **CTO APPROVED — §30.4** | No listing endpoint existed to have decided this |
| D9 | Global email uniqueness | **CTO APPROVED — §30.4** | No schema-migration commits since baseline |
| D10 | Session endpoints | **CTO APPROVED — §30.4** | `UserSessionService.record()` had zero callers |
| D11 | Custom template tenant scope | **CTO APPROVED — §30.1** | No `company_id` column found on `role_templates` in original pass |
| D12 | Template-edit resync strategy | **CTO APPROVED — §30.1 (strategic) + §30.4 (operational shape, finalized this revision)** | `update()` didn't resync existing holders |
| D13 | Template deletion safety | **CTO APPROVED — §30.2** | Deletion cascade orphans live access |
| D14 | Same-company `is_system` assignment boundary | **CTO APPROVED — §30.1** | `HasRoles` trait bypass, no same-company guard |

---

## 6. User Administration contract

*(Unchanged structurally; every row's authorization/tenant column now cites a fully ratified rule. Full table reproduced.)*

| Operation | Canonical service | Required authorization | Tenant boundary | Audit | HTTP semantics |
|---|---|---|---|---|---|
| List/search users | `UserRepository::query()` | `iam.users.view` | Mandatory `company_id` filter — `TASK 2 REQUIREMENT` (STOP 3) | read | `GET /iam/users` |
| View user | `UserRepository` + `UserProfileService` | `iam.users.view` | `ownsTarget()` | read | `GET /iam/users/{id}` |
| Create / invite | `UserIdentityService::createDraft()` + `UserInvitationService` | `iam.users.create`, `iam.users.invite` | Server-derived `company_id` — **D2 approved** | `UserAuditService` | `POST /iam/users` |
| Edit identity/profile | `UserIdentityService::updateIdentity()` | `iam.users.update` | `ownsTarget()` + non-writable `company_id` — **D3 approved** | `UserAuditService` | `PATCH /iam/users/{id}` |
| Organization assignment | `UserOrganizationAssignmentService` | `iam.users.assign-org` | `ownsTarget()` | **NOT VERIFIED**, `TASK 2 REQUIREMENT` to confirm | `PUT /iam/users/{id}/organization` |
| Role assignment (via template only) | `UserRoleAssignmentService` | `iam.users.assign-role` (self-authorizing) | `ownsTarget()` + same-company `is_system` guard — **D14 approved** | `IMPLEMENTATION GAP` — add audit call | `PUT/DELETE /iam/users/{id}/templates/{key}` |
| Lifecycle transitions | `UserLifecycleService::transition()` | `iam.users.{archive,deactivate,lock,unlock}` — **D4 approved, exact tokens §30.4** | `ownsTarget()` | Exists | `POST /iam/users/{id}/transition` |
| Restore | `UserLifecycleService::restore()` | `iam.users.restore` — **D4 approved** | `ownsTarget()` | Exists | `POST /iam/users/{id}/restore` — `TASK 2 REQUIREMENT`: fix STOP 5 first |
| Password reset | `UserPasswordService::adminReset()` | `iam.users.reset-password` (exists) | `ownsTarget()` | Exists | Full ratified D1 lifecycle gate (§12/§30.1) |
| Sessions | `UserSessionService` | `iam.users.manage-sessions` (exists) | `ownsTarget()` | `TASK 2 REQUIREMENT` | **D10 approved** — record/list/revoke-selected/revoke-all, §30.4 |

---

## 7. Role Administration contract

*(Unchanged.)*

| Capability | Classification | Detail |
|---|---|---|
| List / inspect roles | **REUSE** | Unchanged |
| Create/edit role directly, assign users directly | **DO NOT REIMPLEMENT** | Violates ADR-039/040 |
| Clone role | **OUT OF SCOPE for Roles surface** | Template operation |
| Delete role | **DEFER** | No code path exists |
| System-role protection | **PRESERVE** | Admin API routes exclusively through `RoleTemplateRepository`/`RoleTemplateCompiler` |
| Cache invalidation on role change | **SECURITY / CONSISTENCY GATE — §30.2** | `TASK 2 REQUIREMENT`, sequencing gate before mutation endpoints ship |

---

## 8. Permission Administration contract

*(Unchanged — Recommendation B stands.)*

- **Permission catalog authority:** migrations + `RbacSeeder` + `config/permissions.php` only. `PermissionRegistry::sync()` stays dormant. **[DO NOT REIMPLEMENT / DO NOT WIRE]**
- **Grouping:** `PermissionGroup` enum still inert; group by `domain.resource.action` prefix near-term.
- **Unknown-token behavior:** fail-closed compiler behavior intact; UI must surface it inline.
- **`sales.orders.view`** — `CLOSED FINDING`, ratified §30.3.

---

## 9. Role Template contract

*(Structurally unchanged; D12 and D13 rows now carry fully finalized, ratified detail from §30.1/§30.2/§30.4.)*

| Capability | Classification | Detail |
|---|---|---|
| List / inspect / version history / compare / export | **REUSE — safe to build first** | No defects found |
| Clone system → custom | **REUSE** | Unchanged |
| Create / edit custom template | **REUSE — full propagation contract now CTO APPROVED, §30.1 + §30.4** | Editing creates/updates a versioned definition; existing holders never change silently. Canonical atomic unit is **one user/template assignment**; "Apply to selected" and "Apply to all current holders" are batch orchestration over that same canonical action — no second compiler or propagation engine. Impact preview minimum fields: target version, affected-holders count, permission additions, permission removals, holders remaining on the older version. Application is auditable. Version history is never destructively rewritten; rollback means explicitly applying a previous valid version. `TASK 3 REQUIREMENT` for the UI (Preview Impact → Apply Version / Sync Assignment, with progress/result reporting for batch application); the underlying per-assignment apply already exists (`RoleTemplateCompiler`/`RoleCompositionService`, via `assignTemplate()`) — only the batch orchestration and impact-preview query are new, additive service-layer work. |
| Compile / apply (=assign) | **REUSE** | Fail-closed preserved |
| Delete template | **CTO APPROVED — hard security contract, §30.2** | No raw hard-delete for a used template. Lifecycle: `ACTIVE → ARCHIVED` (existing `RoleTemplateStatus.archived`, no new field). Terminal cleanup of a template proven never used must prove: no live assignments, no live compiled-role access, no orphan `user_roles`, audit preservation — before deletion is ever permitted. `TASK 3 REQUIREMENT` (lifecycle UI) with a `TASK 2 REQUIREMENT` dependency (the underlying assignment/grant-inspection query). |
| System-template immutability | **PRESERVE** | Admin API exclusively uses `RoleTemplateRepository` |
| Tenant scoping of custom templates | **CTO APPROVED — D11, §30.1** | Custom templates are tenant-scoped across listing, reading, cloning, assigning, editing, archiving/deleting, and version-applying; system templates remain global. `TASK 2/3 REQUIREMENT`: likely needs an additive `company_id` column on `role_templates` plus the same tenant check `User` operations use. |

---

## 10. Tenant-boundary architecture

*(Unchanged mechanism. All six named cross-tenant cases now closed at the policy level — reproduced complete.)*

| Case | Status | Mechanism |
|---|---|---|
| Foreign-user modification | **CTO APPROVED — D3** | `ownsTarget()` + non-writable `company_id`, `TASK 2 REQUIREMENT` |
| Foreign-role access | Not applicable — roles inherit their template's scope | — |
| Foreign-template access | **CTO APPROVED — D11** | Tenant check on all custom-template operations, `TASK 2/3 REQUIREMENT` |
| Foreign password reset | Already closed | `ownsTarget()`, certified |
| Cross-company / same-company role assignment | **CTO APPROVED — D14** | Guard against inferring bypass authority from `is_system=true`, `TASK 2 REQUIREMENT` |
| Query-filter bypass | Pure `TASK 2 REQUIREMENT` (STOP 3), never decision-gated | Mandatory filter at every list/index call site |

`TenantOwnershipResolver` remains the real enforcement mechanism throughout (not `OwnsCompany`, which still has zero implementers); `User` still has no automatic global scope, so every operation above needs an explicit, individually-reasoned check.

---

## 11. Authorization sequence

*(Unchanged.)*

```
1. Authenticate (Sanctum)
2. Resolve actor + tenant context   — TenantOwnershipResolver::companyId() / isUnrestricted()
3. Resolve target entity            — load the user/role/template by id
4. Gate::authorize(ability, target) — Policy method: permission check AND ownsTarget() together
     (list/query: mandatory company_id filter instead of a target check)
5. Domain invariants                — UserSecurityRules, UserLifecycleService transitions,
                                       RoleTemplateCompiler fail-closed validation
6. Mutation                         — the canonical Application Service
7. Cache invalidation                — SECURITY / CONSISTENCY GATE, §30.2, not optional
8. Audit                            — UserAuditService / AuditService facade
```

---

## 12. Password-reset / account-lifecycle decision — CTO APPROVED, FINAL

*(Unchanged from the second revision — ratified rule stands, reproduced in full.)*

| Target status | Password reset | Constraints |
|---|---|---|
| **ACTIVE** | Allowed | Subject to canonical authorization and audit (already certified) |
| **SUSPENDED / LOCKED** | May be allowed where current authority permits | Must **not** reactivate, unlock, or change lifecycle status; login stays blocked per canonical status regardless of the new password |
| **ARCHIVED** | Not directly allowed | Restore via canonical lifecycle authority first; reset only after valid restoration |
| **DELETED / soft-deleted** | Rejected | No path |
| **Global constraint** | Password reset must never silently restore or reactivate a user | Applies throughout |

`UserPasswordService::adminReset()` today touches only `password`/`password_changed_at`/`require_password_change` — the "never silently reactivate" constraint is already satisfied by the existing code's footprint; what's missing is the permit/block gate itself (`TASK 2 REQUIREMENT`). The `DELETED` path depends on the STOP 5 `restore()` fix landing first.

---

## 13. Template Reconciliation classification

*(Unchanged.)* Group A: `DEFER`, dependency-blocked. Groups B+C: `TASK 3 REQUIREMENT`, confirmed safe to fold in rather than spin up a separate task.

---

## 14. Orphan `tpl-*` roles recommendation

*(Unchanged.)* 39 orphan roles, confirmed historical residue, safe, non-referenced. `DEFERRED` to Task 4 as a reviewed, dry-run-first DBA script.

---

## 15. Unknown-template-permission finding (`sales.orders.view`)

**`CLOSED FINDING`**, ratified §30.3. Confirmed a test-fixture seeding gap, not a catalog defect. No production action required.

---

## 16. `/roles` read-API gate decision

*(Unchanged, tenant-rule clarification from D11 stands.)* `iam.roles.view` exists; `iam.permissions.view`/`iam.role-templates.view`-shaped entries are `TASK 2 REQUIREMENT` to confirm/add. Tenant rule: custom-template reads need the D11 check; system-template/role reads don't, since system templates remain global.

---

## 17. Audit / activity contract

*(Unchanged mechanism, now explicitly a `TASK 2/3 REQUIREMENT` per the ratified "full audit trail required for security-sensitive IAM mutations" instruction — not a soft recommendation.)*

| Action | Audit status |
|---|---|
| User creation/invitation | **REUSE** — wired |
| Lifecycle changes | **REUSE** — wired |
| Role assignment | `IMPLEMENTATION GAP` — `TASK 2 REQUIREMENT` |
| Permission/template changes (incl. version-apply/sync, §30.4) | `IMPLEMENTATION GAP` — `TASK 2/3 REQUIREMENT` |
| Password/security admin actions | **REUSE** — confirmed wired |
| Organizational reassignment | **NOT VERIFIED** — `TASK 2 REQUIREMENT` |
| Session actions (record/revoke) | `IMPLEMENTATION GAP` — `TASK 2 REQUIREMENT`, per D10 (§30.4) |
| Role/template creation/modification | `IMPLEMENTATION GAP` — `TASK 2/3 REQUIREMENT` |

Reuse exactly one mechanism throughout: `App\Core\Audit\AuditService` via a thin entity-scoped facade (the `UserAuditService` pattern) — never fork a parallel table (four already exist elsewhere outside core `audit_logs`; IAM must not become a fifth).

---

## 18. Proposed future HTTP API contract

*(Structurally unchanged; error semantics now the ratified D6 envelope, §30.4.)*

### USERS
| Operation | Authorization | Tenant rule | Conflict/failure semantics | Reuses |
|---|---|---|---|---|
| `GET /iam/users` | `iam.users.view` | Mandatory company filter | — | `UserRepository` |
| `GET /iam/users/{id}` | `iam.users.view` | `ownsTarget()` | 404 if cross-tenant | `UserRepository` |
| `POST /iam/users` (invite) | `iam.users.create`+`invite` | Server-derived `company_id` — **D2** | 409 duplicate identity (D9: global uniqueness kept) | `UserIdentityService`, `UserInvitationService` |
| `PATCH /iam/users/{id}` | `iam.users.update` | `ownsTarget()`, non-writable `company_id` — **D3** | 422 validation | `UserIdentityService` |
| `PUT /iam/users/{id}/organization` | `iam.users.assign-org` | `ownsTarget()` | — | `UserOrganizationAssignmentService` |
| `PUT/DELETE /iam/users/{id}/templates/{key}` | `iam.users.assign-role` (self-authorizing) | `ownsTarget()` + `is_system` guard — **D14** | 422 unresolved template | `UserRoleAssignmentService` |
| `POST /iam/users/{id}/transition` | `iam.users.{archive,deactivate,lock,unlock}` — **D4, §30.4** | `ownsTarget()` | 422 `InvalidUserTransitionException` | `UserLifecycleService` |
| `POST /iam/users/{id}/restore` | `iam.users.restore` — **D4** | `ownsTarget()` | Blocked until STOP 5 fixed | `UserLifecycleService::restore()` |
| `POST /iam/users/{id}/reset-password` | `iam.users.reset-password` | `ownsTarget()` | 422 if status excluded — **D1, §12** | `UserPasswordService` |
| `GET /iam/users/{id}/sessions`, `DELETE .../sessions/{id\|*}` | `iam.users.manage-sessions` — **D10, §30.4** | `ownsTarget()` | — | `UserSessionService` |

### ROLES / PERMISSIONS / ROLE TEMPLATES

Unchanged shape from the prior revision (§7–§9 govern). Error responses across all resource groups use the ratified envelope and status-code contract (§30.4): 400/401/403/404/409/422 as specified, no raw exception traces.

Every mutating row requires the audit call (§17) and cache-invalidation step (§7/§11/§30.2 — a hard sequencing gate, not optional) before it ships.

---

## 19–23. UI information architecture, workspace UX contracts, responsiveness/accessibility

**Unchanged.** Single workspace page with `?tab=` navigation (§19), field/flow contracts (§20–22), reuse-don't-invent responsive/RTL/dark-mode guidance (§23). §9's now-fully-specified template lifecycle (Active/Archived) and version-apply/sync flow compose directly into §22's workspace contract — an archived template reads as such, and "Apply to selected / Apply to all" with progress reporting is now a named, specified action for that screen.

---

## 24. ADR-038 → ADR-041 CTO-ratification recommendations

*(Unchanged.)*

| ADR | Recommendation | Basis |
|---|---|---|
| ADR-038 | (C) Targeted follow-up — cache-invalidation completeness a named, mandated gate | §7, §30.2 |
| ADR-039 | (C) Targeted follow-up — both defects now fully ratified remediation contracts; ratify once implemented | §9 |
| ADR-040 | (C), closer to (B) — all CRITICAL/BLOCKING items now have fully ratified plans | §4 |
| ADR-041 | (A) Formal ratification | Zero findings against it |

---

## 25. DO-NOT-REIMPLEMENT list

*(Unchanged, reproduced.)*

- `AuthorizationGateway` / `PermissionService` / the 5-engine Authorization Platform
- `RoleTemplateCompiler` / `RoleCompositionService` — including for version-apply/sync (§9, §30.4): reuse, never build a second compiler or propagation engine
- `TenantOwnershipResolver`
- `App\Core\Audit\AuditService` — extend via facade, never fork
- Sanctum / `AuthController` / `LoginAction` / `LogoutAction` — including for session wiring (D10): extend, don't replace
- `AuthorizationProvider` and ADR-041 frontend primitives
- `PermissionRegistry::sync()` — stays dormant
- Direct `Role`/`RoleTemplate` mutation outside `RoleTemplateRepository`
- A second/custom password-strength engine — use `Password::defaults()` (D5, §30.4)
- A platform-wide API-standard project scoped inside IAM Closure — out of scope (D6, §30.4)

---

## 26. CTO Decision Matrix — FINAL

**All 14 decisions are CTO APPROVED. None remain CTO DECISION REQUIRED.** Full ratified text is in §30 (§30.1 for D1/D2/D3/D7/D11/D14, §30.2 for D13 + cache invalidation, §30.3 for the closed `sales.orders.view` finding, §30.4 for D4/D5/D6/D8/D9/D10/D12). This matrix separates the **architecture decision** (now closed, for every row) from the **implementation gate** (open, tracked by task) per explicit instruction not to conflate the two.

| ID | Topic | Decision status | Implementation gate | Gated task |
|---|---|---|---|---|
| D1 | Password reset vs. account lifecycle | CTO APPROVED | Status-aware guard in `adminReset()`; depends on STOP 5 fix for the `DELETED` path | Task 2 |
| D2 | Company ownership on create | CTO APPROVED | Server-derive `company_id` in `UserIdentityService::createDraft()` | Task 2 |
| D3 | Company ownership on update | CTO APPROVED | Remove `company_id` from `IDENTITY_FIELDS` | Task 2 |
| D4 | 6 missing permissions | CTO APPROVED, exact tokens specified | Add catalog rows + gate the 6 endpoints | Task 2 |
| D5 | Password strength policy | CTO APPROVED, `Password::defaults()` V1 | Apply uniformly across reset/self-service/activation | Task 2 |
| D6 | Error envelope / HTTP contract | CTO APPROVED, exact envelope + status codes specified | Build the exception renderer | Task 2 |
| D7 | Login vs. account status | CTO APPROVED | Wire `canAuthenticate()` into login | Task 2 |
| D8 | Archived users in default listing | CTO APPROVED, excluded by default | Build the listing filter | Task 2 |
| D9 | Global email uniqueness | CTO APPROVED, keep as-is, no migration | None — status quo requires no action | — |
| D10 | Session management | CTO APPROVED, wire existing model | Record on login, list, revoke-selected, revoke-all | Task 2 |
| D11 | Custom template tenant scope | CTO APPROVED | Add `company_id` to `role_templates` + tenant checks | Task 2/3 |
| D12 | Template version apply/sync | CTO APPROVED, full operational contract specified | Batch orchestration + impact-preview UI over existing per-assignment `compile()` | Task 3 (UI) / Task 2 (query support) |
| D13 | Template deletion safety | CTO APPROVED, lifecycle contract specified | `ACTIVE→ARCHIVED` flow + terminal-cleanup integrity proof | Task 3 |
| D14 | Same-company `is_system` assignment boundary | CTO APPROVED | Guard + self-authorizing `assignTemplate()`/`removeTemplate()` | Task 2 |

---

## 27. Exact proposed Tasks 2–4

**Task 2 — Secure IAM Admin API.** Architecturally unblocked in full (§31). Implements: server-derived/immutable `company_id` (D2/D3), mandatory list-scoping (STOP 3), the D4 permission catalog additions and their 6 endpoints, the D6 error envelope, D5 password policy, D7 login-status gate, D14 same-company guard, D10 session wiring, STOP 5 `restore()` fix, and the D1 password-reset lifecycle gate. Sequences the cache-invalidation fix (§30.2) before any role/permission/template mutation endpoint ships.

**Task 3 — IAM Administration Workspace.** 3-tab frontend + Template Reconciliation Groups B/C (confirmed safe to fold in). Implements the D11 tenant-scoped template UI, the D12 Preview-Impact/Apply-Version/Sync-Assignment flow with progress reporting, and the D13 Active→Archived template lifecycle UI.

**Task 4 — IAM Closure & Integration Gate.** Unchanged: final verification, full regression/browser certification, ADR-038→041 ratification decision, orphan `tpl-*` cleanup, final go/no-go.

---

## 28. Exact files created/changed

**Modified:** `docs/verification/TASK-ECOS-IAM-ADMIN-SURFACE-ARCHITECTURE-CONTRACT-CLOSURE-001-REPORT.md` — this file, third and final revision of the same document, same task, no new file.

**Changed otherwise:** none. No controllers, routes, frontend pages, migrations, or production IAM domain code were modified at any point across this task's three revisions.

---

## 29. Git status at completion

See the commit record following this section for the exact SHA of the one focused documentation commit this finalization produced (workspace/branch/HEAD reconfirmed immediately before staging — branch `task/iam-workstream`, HEAD `16b0ec85df5774f03ccd6dca042528260d66c216`, working tree otherwise clean, matching every prior revision's check). Reported in the task's final chat response per instruction, not duplicated here to avoid the file needing to describe a commit it is itself part of.

---

## 30. CTO Architecture Locks — complete, final

### 30.1 Tenant, ownership, and lifecycle locks (D1, D2, D3, D7, D11, D14, D12 strategic direction)

*(Ratified in the second revision, unchanged, reproduced.)*

- **D2:** `company_id`/tenant ownership is server-derived. Client must not select arbitrary company ownership on IAM Admin create. Flow: Authenticate Actor → Resolve canonical company/tenant → Authorization/Data Scope → Server assigns ownership.
- **D3:** `company_id` is not writable through normal IAM Admin update operations. Any legitimate company-transfer workflow requires its own explicit domain contract.
- **D7:** Authentication must enforce canonical account lifecycle/status. Non-login-eligible users must not authenticate merely because credentials are valid. Use `UserStatus::canAuthenticate()` — no second authentication status system.
- **D11:** Custom Role Templates are tenant/company scoped; system templates may remain canonical/shared. Isolation applies to listing, reading, cloning/customizing, assigning, editing, archiving/deleting, and applying template versions. No possession of a template ID grants access.
- **D14:** `is_system` must not become a tenant-bypass shortcut. Assignment remains subject to authorization, tenant boundary, data scope, and protected-role rules. Global/system authority must be explicit in canonical architecture, never inferred from `is_system=true` alone.
- **D1:** full rule in §12.
- **D12 (strategic direction):** editing a template never silently alters existing holders; propagation requires an explicit action; reuse `RoleTemplateCompiler`/`RoleCompositionService`, never a second compiler. Operational shape finalized in §30.4.

### 30.2 Structural security gates (D13, cache invalidation)

*(Ratified in the second revision, unchanged, reproduced.)*

- **D13:** No raw hard-delete for a used Role Template. Preferred lifecycle `ACTIVE → ARCHIVED/DISABLED` (maps to existing `RoleTemplateStatus.archived`). If access must be removed: identify affected assignments/grants → controlled revoke/reconciliation → verify no orphaned live access → preserve audit trail → only then any terminal cleanup the final domain contract allows. A never-used template may be safely deletable only once implementation proves that integrity. Deleting a template must never leave live, ungoverned access.
- **Cache invalidation:** reclassified as a security/consistency gate, not an optional tradeoff. `invalidateRoleCache()` dead, Visibility/Scope invalidation absent. Reconcile role authorization cache, Visibility cache, Data Scope cache, and any other `AuthorizationGateway`-dependent cached decision affected by mutations. Reuse canonical cache infrastructure — no second cache engine. Mutation endpoints must not ship with indefinitely stale effective permissions. Gates role/permission/template mutation endpoints specifically within Task 2 (§31) — not Task 2's start.

### 30.3 Closed findings

- **`sales.orders.view`:** closed as architecture gap — a test-fixture/seeding deficiency, not a catalog defect. No production action required.

### 30.4 Final operational decisions (D4, D5, D6, D8, D9, D10, D12 operational shape) — ratified this revision

- **D4 — Missing admin permissions, CTO APPROVED.** Add six additive permission tokens under the existing `iam.users` domain, consistent with the catalog's own `domain.resource.action`/kebab-case-action convention (matching existing entries like `assign-role`, `reset-password`, `manage-sessions`). Derived from existing IAM resources/actions, no new domain invented:
  - `iam.users.archive`
  - `iam.users.restore`
  - `iam.users.deactivate`
  - `iam.users.lock`
  - `iam.users.unlock`
  - `iam.users.revoke-role`

  The corresponding six endpoints release for Task 2 once these are added to `config/permissions.php`/`RbacSeeder` and enforced.

- **D5 — Password strength, CTO APPROVED.** V1 baseline: Laravel's `Illuminate\Validation\Rules\Password::defaults()`. No existing canonical project wrapper was found in this pass (zero `Rules\Password` usage anywhere) — use the framework default directly. One rule, applied consistently across admin password reset, self-service reset, and invitation/account activation. No custom enterprise password engine in this closure. Future strengthening happens centrally, in one place.

- **D6 — Error contract, CTO APPROVED.** One IAM Admin error envelope, aligned to existing Laravel/application exception conventions — not a platform-wide API-standard project (that remains separately scoped; `docs/07_API_Standards.md` stays empty/out of scope here). Minimum envelope: machine-readable error code, human-readable message, validation details where applicable. Canonical HTTP semantics: `400` malformed/domain-invalid request, `401` unauthenticated, `403` unauthorized/scope denied, `404` resource not visible/found within the authorized boundary, `409` state/conflict violation, `422` validation failure. Never expose raw exception traces. If a sibling module's convention is demonstrably suitable, reuse its shape rather than adding gratuitous inconsistency — no such convention was confirmed to exist in this pass, so IAM's shape (as specified here) is the default.

- **D8 — Archived users in listing, CTO APPROVED.** Excluded from the default active listing. The Workspace/API must offer an explicit status/filter mechanism to include or view archived users. Archived users are not invisible historical records; no hidden-deletion semantics.

- **D9 — Email uniqueness, CTO APPROVED.** Preserve current global uniqueness. No repartitioning per company, no migration, no deduplication work. A future business need for same-email identities across companies would require its own separate architecture decision — out of scope here.

- **D10 — Session management, CTO APPROVED.** Wire the existing Sanctum/session model rather than building a second one. Task 2 may implement: session recording during canonical login, authorized session listing, revoke-selected-session, and revoke-others/force-logout where existing security architecture supports it. All session actions must remain tenant-safe, user-safe, audited, and authorization-controlled. Sanctum remains the canonical authentication authority throughout.

- **D12 — Template version apply/sync, operational shape, CTO APPROVED (finalizes the strategic direction from §30.1).** Editing a template creates/updates a versioned definition without silently altering existing holders. Propagation requires the explicit **Preview Impact → Apply Version / Sync Assignment** flow. Canonical atomic unit: **one user/template assignment**. The same canonical per-assignment action is reused for one user, selected users, or all current holders — "Apply to all" is batch orchestration over that one canonical behavior, not a new mechanism. No second compiler or propagation engine — reuse `RoleTemplateCompiler`/`RoleCompositionService`. Task 3's UI exposes **Apply to selected** and **Apply to all current holders**, each with progress/result reporting. Impact preview supports, at minimum: target version, affected-holders count, permission additions, permission removals, holders remaining on the older version. Successful application is auditable. Version history is never destructively rewritten; rollback/reversion means explicitly applying a previous valid version, never deleting history.

---

## 31. Task 2 Release Readiness — FINAL

> ### TASK 2 — SECURE IAM ADMIN API: **ARCHITECTURALLY READY**

**No remaining CTO business-policy hold.** All 14 decisions are ratified with specific, exact rules (§30) — nothing in Task 2's scope is waiting on a judgment call. D4 (6 permissions) is no longer a scoped exception as in the prior revision: it's approved with exact token names (§30.4), so it's implementation work, not a hold.

**One implementation-sequencing gate remains, by explicit CTO instruction, and is not a reason to withhold Task 2's release:** cache-invalidation safety (§30.2) must be completed **before** Task 2 enables broad role/permission/template mutation endpoints specifically. User-identity operations, all read endpoints, and the newly-specified lifecycle/session/password-reset operations are not gated by this — only the role/permission/template mutation slice is, and only until that slice ships, not before Task 2 starts.

Task 2 may begin as soon as it is opened. Verified immediately before this finalization: HEAD unchanged at `16b0ec85df5774f03ccd6dca042528260d66c216`.
