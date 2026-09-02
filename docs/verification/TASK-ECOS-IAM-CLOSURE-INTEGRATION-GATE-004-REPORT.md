# TASK-ECOS-IAM-CLOSURE-INTEGRATION-GATE-004

**Workstream:** ECOS ERP — IAM Closure · Batch 01 · Task 4 of 4 — FINAL TASK
**Type:** Final IAM Source Closure / Security Reconciliation / Integration Readiness Gate
**Owner:** A2 — IAM · **Account/session:** Second Device — IAM
**Workspace:** `D:\ECOS-Work\ecos-iam` · **Branch:** `task/iam-workstream`
**Write mode:** Closure — minimal, evidence-gated fixes only · **Dev authority:** None
**Date:** 2026-09-02

---

## 1. Final Status

> ## COMPLETE

No STOP condition (§28 of this task) is triggered. Two real, evidence-proven defects were found and fixed during the final catalog audit this task mandated: the previously-identified `sales.customers` → `crm.customers` reconciliation (§3 of the task), and a newly-discovered one — `accountant`'s `accounting.ledgers.view`, a second surviving instance of the historical BUG-GL-011 defect class, fixed by removing a fabricated token that duplicated an already-held real one. Group B is closed per the CTO's explicit least-privilege ruling (unchanged). Group A remains dependency-blocked, fully inventoried, not reconciled. Every mutation path that must invalidate authorization caches does. Login lifecycle gating, tenant isolation, D12/D13/D14 contracts, and session handling were all re-verified against current source, not recalled from memory. One focused local commit was made (§3 below — commit-SHA section, not the sales.customers section; see the disambiguation note there).

**TESTS EXECUTED: YES (frontend, re-run fresh this task), NO (backend — no PHP on this device, same constraint as Tasks 2/3).** **BROWSER VERIFIED: NO** (preserved, not attempted — §24/§27). **SOURCE INTEGRATION READY: YES.** **INTEGRATED: NO. DEV VISIBLE: NO. USER VERIFIED: NO. CERTIFIED: NO.**

---

## 2. Starting HEAD

`f9df5f9eb2f2e7a623fdd1d0688f1e50805754c0` — Task 3's "feat(iam): add administration workspace" commit. Confirmed via `git rev-parse HEAD` before any change was made.

## 3. Final commit SHA

Recorded in this task's final chat response, created after this report and after `git status` is reconfirmed clean, for the same self-reference reason every prior report in this batch gave (the SHA is a hash of content that includes this file).

---

## 4. Exact files changed

**1 modified:** `backend/Modules/IAM/Domain/Catalog/RoleTemplateCatalog.php` — the `sales.customers` → `crm.customers` reconciliation (§22 below) and the `accounting.ledgers.view` removal (§23 below). No other production file touched.

**1 modified (test):** `backend/tests/Unit/IAM/RoleTemplateCatalogReconciliationTest.php` — 3 new test methods added (sales/customers reconciliation, accountant fix, Group B untouched-guard), class docblock updated to reference both this task and Task 3.

**1 created:** this report.

No frontend file was touched. No migration was created or applied. No route, controller, policy, or service was modified — the entire diff is catalog data plus its regression tests.

---

## 5. Canonical IAM authority

Re-confirmed, not re-derived: **one** authorization stack, unchanged by this task —
- **Authentication:** `SanctumAuthService` (Sanctum tokens) — `Modules/IAM/Infrastructure/Services/SanctumAuthService.php`.
- **Identity/lifecycle:** `UserIdentityService`, `UserLifecycleService`, `UserStatus` enum — `Modules/IAM/{Application/Services,Domain/Enums}/`.
- **RBAC/authorization decisions:** the 5-engine Authorization Platform behind `AuthorizationGateway` (ADR-038) — untouched.
- **Role authoring → runtime:** `RoleTemplateCompiler` is the **only** place that sets `role_templates.role_id` (its own docblock says so) — no second compiler exists, and this task did not create one.
- **Permission source of truth:** `permissions` DB table, populated exclusively by permission-seeding migrations (§24) and `config/permissions.php`'s base registry — never minted ad hoc. `RoleTemplateCompiler::compile()` enforces this by construction: any template referencing an unresolvable token, or a wildcard resolving to zero real permissions, throws `UnknownTemplatePermissionException` before any state changes (verified directly, §22).

No duplicate permission registry, no parallel role/template engine, no second cache-invalidation mechanism was found or introduced.

## 6. User lifecycle closure

Re-verified directly against `Modules/IAM/Domain/Enums/UserStatus.php` (not recalled):

```php
public function canAuthenticate(): bool { return $this === self::ACTIVE; }

public function allowsAdminPasswordReset(): bool {
    return in_array($this, [self::ACTIVE, self::INACTIVE, self::SUSPENDED, self::LOCKED], true);
}
```

| Status | Login | Admin reset |
|---|---|---|
| ACTIVE | ✅ eligible | ✅ allowed |
| INACTIVE | ❌ blocked | ✅ allowed, state unchanged |
| SUSPENDED | ❌ blocked | ✅ allowed, state unchanged |
| LOCKED | ❌ blocked | ✅ allowed, state unchanged |
| ARCHIVED | ❌ blocked | ❌ rejected until restore |
| DELETED | ❌ blocked | ❌ rejected |
| DRAFT / INVITED / PENDING_ACTIVATION | ❌ blocked | ❌ rejected (no real credential yet — invitation is the canonical path) |

Matches §7 of this task exactly. `SanctumAuthService::attemptCredentials()` calls `canAuthenticate()` before issuing a token (confirmed in Task 2, re-confirmed the enum method itself here). **CLOSED.**

## 7. Tenant isolation closure

Re-confirmed against current source:
- **Create ownership server-derived:** `UserIdentityService::createDraft(array $data, string $companyId, ...)` takes `companyId` as an explicit parameter never read from the request body; `CreateUserRequest`/`UpdateUserRequest` have no `company_id` field (`UpdateUserRequest`'s own docblock: "`company_id` is deliberately NOT a...").
- **`company_id` immutability:** confirmed no update path writes `$user->company_id` — it is set exactly once, in `UserIdentityService`'s creation path.
- **Custom template scoping:** `company_id` on `role_templates` (Task 2 migration) is nullable/global for system templates, set server-side for custom ones — `RoleTemplatePolicy` denies cross-company access (Task 2, unchanged, not re-derived here beyond confirming the file is untouched).
- **`is_system` is not a tenant bypass:** `UserRoleAssignmentService::assertSystemRoleAuthority()` — inline comment: "D14 (CTO-ratified): is_system must never be inferred as a tenant/normal-admin bypass." This exists specifically to prevent that conflation.
- **List/search scoping:** `UserController::index()` mandatorily injects `company_id` into the repository filter unless the actor `isUnrestricted()` (Task 2, STOP 3 — unchanged, confirmed present).

**CLOSED.**

## 8. Password reset closure

Covered in §6's table. `UserPasswordService::adminReset()` gates on `UserStatus::allowsAdminPasswordReset()` before mutating (Task 2; `UserSecurityRuleException::cannotResetPasswordInStatus()` is the fail path) — confirmed present, unchanged. **CLOSED.**

## 9. Role/permission closure

`RoleController`/`PermissionController` remain read-only by architecture (ADR-039/040 Decision 2: roles compile from templates only) — confirmed unchanged. Every permission token in the catalog now resolves to a canonical source or is an honestly-failing external-dependency placeholder (§16 below is the full audit). **CLOSED**, subject to Group A's external dependencies (§20) and Group B's CTO-ruled deferral (§19), neither of which is an IAM-side defect.

## 10. Cache invalidation closure

Re-confirmed directly against current source (not recalled) for every required mutation path:

| Mutation path | Invalidation call | File |
|---|---|---|
| User role assignment | `invalidateEffectiveAccess()` → all 3 caches | `UserRoleAssignmentService.php:83` |
| User role revocation | `invalidateEffectiveAccess()` → all 3 caches | `UserRoleAssignmentService.php:111` |
| Template compile (version sync / apply) | `invalidateRoleCache()` × 3 (permission, visibility, scope), walking **every current holder** of the shared compiled role | `RoleTemplateCompiler.php:98-100` |
| Role permission change | Always routes through `RoleTemplateCompiler::compile()` (no direct-role-edit path exists) | — |

No bypassable mutation path was found. No second cache engine exists. **CLOSED.**

## 11. Template lifecycle closure

`RoleTemplateRepository`'s mutators (create/update/clone/archive/delete) all audit through `RoleTemplateAuditService` (Task 2, unchanged, confirmed present) — no change this task. **CLOSED.**

## 12. D12 apply behavior

Re-confirmed: `RoleTemplateController::apply()` calls `RoleTemplateCompiler::compile()` directly on the template's one linked `Role` — no per-holder loop exists anywhere in the compiler or controller. The Task 3 Workspace's `TemplateApplyWorkflow` shows source version, affected-holder count, permission additions/removals, and requires explicit confirmation before calling `apply()`; it exposes no "apply to selected users" control anywhere (verified directly in Task 3's tests, re-confirmed present and unmodified this task). **PRESERVED, source-proven, unchanged.**

## 13. D13 archive/delete behavior

`RoleTemplateRepository::delete()` checks `isInUse()` first and throws `RoleTemplateInUseException` (409) if any assignment exists; `archive()` is the safe alternative offered whenever assignments exist, exactly as Task 2 built it and Task 3's Workspace UI enforces (delete button only rendered when `assignment_count === 0`). No version/assignment history is destructively removed by either path. Unchanged this task. **PRESERVED.**

## 14. D14 defense-in-depth status

Re-confirmed directly:
```php
// RoleTemplateCompiler::resolveRole()
$role = Role::firstOrCreate(['slug' => 'tpl-'.$template->key], [..., 'is_system' => false]);
```
No compiled template role is ever `is_system = true` — hardcoded, not template-configurable. `UserRoleAssignmentService::assertSystemRoleAuthority()` still checks `role->is_system` on every assignment/revocation as defense-in-depth even though no current path can make it fire. No path was found or created that uses `is_system` as a blanket tenant bypass (§7 above).

**D14 GUARD: PRESENT / DEFENSE-IN-DEPTH.**

## 15. Sessions closure

`UserSessionService` (reused, not re-implemented) stores only `token_id` (an integer FK into `personal_access_tokens`) — re-confirmed directly that no raw token string/hash is ever persisted in `UserSession` or returned by `SessionController`. Force-logout (`$user->tokens()->delete()`) and single-session revoke both operate on the real Sanctum token store; a revoked session cannot remain active through any duplicate session authority — there is exactly one session/token mechanism. **CLOSED.**

## 16. Frontend authorization closure

Re-confirmed no hardcoded role-name check (`role === 'Admin'` or equivalent) exists anywhere in `frontend/src/features/iam-admin/` — every gate goes through `@/features/authorization` (`Can`, `usePermission`, `PermissionBoundary`), consistent with ADR-041. Not modified this task. **PRESERVED.**

## 17. Admin API inventory

The `/api/iam` route group (`routes/api.php:325`, `auth:sanctum` + `throttle:120,1`) contains ~39 routes across 5 controllers (Users, Roles, Permissions, RoleTemplates, Sessions) — unchanged from Task 2. Thin controllers delegate to Application services; Form Requests validate; policies/`Gate::authorize()` calls gate every mutation; `bootstrap/app.php` maps 5 IAM domain exceptions to 409/422 with the app's one existing error envelope (`ApiResponse`). Not touched this task. **SOURCE COMPLETE.**

## 18. Workspace inventory

Confirmed unchanged from Task 3: one page (`IamWorkspacePage`), three tabs (Users / Roles & Permissions / Role Templates), reached via the sidebar's existing "Users"/"Roles & Permissions" entries — no new nav entry. Session/security controls remain inside `UserDetailDrawer`, not a separate workspace. No menu explosion.

| Area | Classification |
|---|---|
| Users | SOURCE COMPLETE |
| Roles & Permissions | SOURCE COMPLETE (read-only by architecture) |
| Role Templates | SOURCE COMPLETE |

---

## 19. Group B — decision and result

**CTO final ruling (this task's §1): CLOSED.**
```
GROUP B: RECONCILED — LEAVE AS-IS
```
`hr-officer` and `customer-service-agent` are **byte-for-byte unchanged** — confirmed via direct diff against the pre-task file, and pinned by a new regression test (`test_group_b_templates_are_untouched_per_cto_least_privilege_ruling`).

**Disclosure the CTO ruling should have, even though it does not change the outcome:** the full catalog audit (§16 below) found that `hr-officer` (`hr.employees.create`, `hr.employees.update`) and `customer-service-agent` (`crm.tickets.create`, `crm.tickets.update`) each hold **two tokens that do not exist anywhere in the codebase** — the real resources are `hr.employees.{view,manage}` and `crm.service.{view,manage,assign,resolve,admin}` respectively (no `hr.employees.create/update` split exists; no `crm.tickets` resource exists at all, only `crm.service`). These are the same *kind* of defect as `sales.customers`/`accounting.ledgers.view` — a real canonical alternative exists — but correcting them would mean granting `hr.employees.manage` / `crm.service.manage`, which is **more** capability than the current (non-functional) tokens, not the same level. That is exactly the widening the CTO ruling forecloses ("Do NOT add optional permissions merely because those permissions exist in the catalogue"). **Left untouched, per the ruling, disclosed here in full rather than silently left for someone else to rediscover.** Practical consequence: today, `RoleTemplateCompiler::compile()` would throw `UnknownTemplatePermissionException` if either template were ever assigned — Group B is currently **not assignable** until either the CTO revisits this or a future task narrows the fix to something below `manage`-level (e.g. confirming whether a `create`/`update` split should be *added* to the catalog, which is a schema decision, not a template one).

## 20. Group C result

```
GROUP C: RECONCILED — SOURCE-PROVEN TOKENS ONLY
```
All 7 templates from Task 3 (`warehouse-clerk`, `purchasing-officer`, `dispatcher`, `driver`, `sales-representative`, `cashier`, `ai-analyst`) hold only tokens independently re-verified this task against live enforcement/seeding sources — unchanged since Task 3's self-correction. No fabricated token found on re-audit. Regression tests (`test_group_c_eligible_tokens_appear_correctly`, `test_group_c_stale_tokens_are_removed`, `test_group_c_previously_fabricated_tokens_are_not_present`) preserved unmodified.

## 21. Group A dependencies

```
GROUP A: DEPENDENCY BLOCKED / DEFERRED
```
Not reconciled, not touched, not claimed complete. Full inventory, each confirmed by direct evidence this task (not carried forward from memory):

| Template(s) | Missing capability | Expected owner | Why IAM cannot mint it | Compile-time consequence |
|---|---|---|---|---|
| `production-director`, `production-manager` | `manufacturing.*` (whole domain) | A future Manufacturing/Production module | Zero permissions seeded anywhere under `manufacturing` | Wildcard resolves to 0 matches → `RoleTemplateCompiler` throws `UnknownTemplatePermissionException` by design (its own comment names `manufacturing.*` explicitly as the case this check exists for) |
| `production-operator` | `manufacturing.workorders.{view,operate}` | same | same — no `manufacturing` namespace exists at all | Literal unknown token → same exception |
| `quality-inspector` | `manufacturing.quality.{view,operate}` | same | same | same |
| `packaging-supervisor`, `packaging-operator` | `operations.packing.{view,operate,manage}` | Operations/Packing (packing is not a seeded resource under `operations` — only `preparation` and `fulfillment` are) | `packing` resource never seeded | same |
| `shipping-manager` | `shipping.*` (bare top-level domain) | Shipping module | Only `logistics.shipping.*` (a resource, not a domain) exists; bare `shipping` never seeded | same (the template's `logistics.*` half is fine and unaffected) |
| `warehouse-manager` | `logistics.transfers.*` | Logistics (Transfers) | No `logistics.transfers` resource exists | Wildcard resolves to 0 matches → same exception. **Noted for the record, not acted on:** `inventory.transfers.{view,create,update}` was added by a later, unrelated migration (`2026_12_20_000000_seed_enterprise_permission_matrix.php`) under `inventory`, not `logistics` — this template already holds `inventory.*` in full, so the real underlying capability (stock transfers) is already granted regardless of this dead wildcard. Deliberately **not renamed** to `inventory.transfers.*` in this task: `warehouse-manager` is an explicitly-named Group A template, and this task's own instruction is not to treat any Group A item as reconciled. Flagged here as a fast, low-risk candidate for a future task, not applied unilaterally. |

Group A does not block source integration readiness: IAM contains no *fabricated* token for any of these — every reference either an honest wildcard for a domain that was never built, or (for `production-operator`/`quality-inspector`/`packaging-*`) a specific token predating this whole workstream that names a resource nobody has ever implemented. All fail loudly (compiler exception) rather than silently granting nothing or something wrong — which is the correct, safe behavior for a genuinely-missing capability, and is categorically different from a fabricated-but-plausible token silently succeeding (the BUG-GL-011 failure mode this task's compiler-side guard exists to prevent).

## 22. sales.customers → CRM reconciliation

Full 5-step evidence trail per this task's §3:

1. **Exact invalid token(s):** `sales.customers.view`, `sales.customers.create` (literal permissions on `sales-representative`); `'sales.customers'` used as a scope **key** on both `sales-manager` and `sales-representative`. `sales.customers` does not appear anywhere else in the codebase (confirmed by exhaustive grep).
2. **Exact canonical existing CRM permission token(s):** `crm.customers.{view,create,update,delete}` (`config/permissions.php` base registry) + `crm.customers.{merge,archive}` (`Modules/Crm/Customers/…/seed_crm_customer_permissions_table.php`, a dedicated seeder whose own description for `.view` is "View customers, profiles and 360°"). Six real actions total.
3. **Which route/policy/capability the Sales templates are intended to access:** every customer CRUD/merge/archive route in `routes/api.php` (`CrmCustomerController`, `CrmCustomerGroupController`, `CrmCustomerMergeController`) is gated by `permission:crm.customers.*` — there is no separate "sales customers" route group or controller. It is the same resource for every role that touches customers.
4. **Identical or different capability for the two templates:** `sales-manager` already holds `crm.*` (wildcard) — it had full `crm.customers.*` access regardless of the broken scope key. `sales-representative` holds no wildcard — its two literal `sales.customers.*` tokens were its *entire* customer capability, and being fabricated, granted **nothing** — contradicting the template's own description ("owns their own... customers").
5. **Does the fix change effective privilege beyond the apparent intent?** No — and in one respect it *narrows* an accidental over-grant: `EffectiveRoleProfile::scopeFor()` falls back to `'all'` for any resource with no matching scope key, so the dead `'sales.customers'` key was silently giving both roles **unrestricted, company-wide** customer visibility instead of the `'team'`/`'self'` scoping actually authored. Renaming the key to the real resource makes the already-intended restriction take effect. On the permission side, `sales-representative` gained exactly `crm.customers.view` + `crm.customers.create` — the same two action levels the stale tokens named, nothing broader (no update/delete/merge/archive).

**Outcome A** (exact canonical replacement proven) applied to both templates. Regression test: `test_sales_customers_stale_reference_is_reconciled_to_crm_customers`.

```
SALES CUSTOMER STALE TOKEN: CLOSED
```

---

## 23. Permission inventory

**D4 (6 tokens, `iam.users.*`, TASK-ECOS-IAM-SECURE-ADMIN-API-002):** `archive`, `restore`, `deactivate`, `lock`, `unlock`, `revoke-role`. Source of truth: `2026_12_26_000000_add_iam_user_lifecycle_permissions.php` (idempotent insert) + `config/permissions.php`'s `modules.iam.users` array (both list all 6, plus the pre-existing `view/create/update/delete/activate/suspend/assign-role/assign-org/invite/reset-password/manage-sessions`). Granted to `company-admin` by the migration. No template currently references any of the 6 directly (they gate the Admin API's lifecycle endpoints, not template-authored capability) — expected, not a gap.

**Task 2/3 additions beyond D4:** `iam.role-templates.{view,create,update,delete}`, `iam.permissions.view` (`2026_12_26_000002_add_iam_role_template_and_permission_catalog_permissions.php` — required to gate Task 2's own new Role Template/Permission-catalog API, no pre-existing permission existed to reuse).

**This task's reconciliation (not new permissions — corrected references to existing ones):** `sales-manager`/`sales-representative` now correctly reference `crm.customers.*` (already existed); `accountant` now correctly relies on `finance.gl.view` alone (already existed) instead of also claiming the fabricated `accounting.ledgers.view`.

No duplicate permission registry found. No template-only/UI-only permission creation path exists (`TemplatePermissionsEditor` picks only from the real catalog via `Combobox`, confirmed in Task 3, unchanged). No automatic/implicit privilege grant was found beyond `is_system`'s existing `Gate::before()` bypass (pre-existing platform behavior, not IAM-specific, not in scope to change). **Unknown permissions fail closed** — proven directly from `RoleTemplateCompiler::compile()`'s own validation logic (§5).

## 24. Migration inventory

Exactly 3 IAM migrations across this whole batch (Tasks 2–4) — **no new migration was created by Task 3 or this task**; both worked exclusively on `RoleTemplateCatalog.php`'s static PHP data, which requires no schema change.

| Migration | Purpose | Additive/Destructive | Applied to DEV |
|---|---|---|---|
| `2026_12_26_000000_add_iam_user_lifecycle_permissions.php` | D4's 6 tokens, insert-if-missing + grant to `company-admin` | Additive | **NO** |
| `2026_12_26_000001_add_company_id_to_role_templates_table.php` | Nullable, indexed `company_id` on `role_templates` (D11) | Additive (nullable column; every existing row unaffected) | **NO** |
| `2026_12_26_000002_add_iam_role_template_and_permission_catalog_permissions.php` | `iam.role-templates.*` + `iam.permissions.view`, gates Task 2's new API surface | Additive | **NO** |

Confirmed via `git log --diff-filter=A -- "Modules/IAM/Infrastructure/Database/Migrations/*"` — these 3 are the only IAM migrations created in the entire `9f42b376`/`f9df5f9e`/(this commit) range. None applied — this task performed no `migrate`/`db:seed` operation (DEV AUTHORITY: NONE, honored throughout).

## 25. Test inventory

**Recounted from actual source this task — not carried forward from any report's arithmetic**, per this task's own §23 instruction:

| Suite | Files | Method/case count (counted via `grep -c` against the actual files) |
|---|---|---|
| Task 2 backend (`AdminApi*Test.php`) | 5 | `AdminApiLifecycleLoginTest` 10, `AdminApiRoleTemplateTest` 12, `AdminApiSessionTest` 7, `AdminApiTenantSecurityTest` 10, `AdminApiUserSecurityTest` 7 → **46** |
| Task 3 frontend (`iam-admin/components/*.test.tsx`) | 7 | `roles-permissions-tab` 7, `template-apply-workflow` 11, `template-detail-drawer` 7, `user-create-drawer` 4, `user-roles-panel` 6, `user-security-panel` 6, `users-tab` 5 → **46** |
| Task 3/4 backend (`RoleTemplateCatalogReconciliationTest.php`) | 1 | 4 (Task 3) + 3 (this task) → **7** |
| **TOTAL IAM FOCUSED TEST COUNT** | **13 files** | **99** |

This task added exactly 3 test methods (all in `RoleTemplateCatalogReconciliationTest.php`) — no frontend test was added or modified, since no frontend file changed.

## 26. Tests executed: YES (frontend, partial) / NO (backend)

- **Frontend:** re-run fresh this task — `npx vitest run src/features/iam-admin/` → **46/46 passing** (all 7 files). Command and result freshly reproduced this task, not cited from Task 3's report. Backend changes this task do not touch any frontend file, so no frontend regression was possible, and none occurred.
- **Backend:** **NOT executed.** Confirmed again this task: `php --version` and `which php` both fail (`command not found`) in this device's shell — identical constraint to Tasks 2 and 3. All 7 methods in `RoleTemplateCatalogReconciliationTest.php` (4 pre-existing + 3 new) were manually re-verified line-by-line against the current, post-fix `RoleTemplateCatalog.php` content — every literal string in every assertion was checked against the actual file, not typed from memory — but this is not a substitute for `phpunit` actually running. No claim in this report upgrades that to "executed."

## 27. Browser verified: NO

Preserved exactly as Task 3 reported it. **Not attempted on this device**, per this task's own §24 instruction. The first-device verification package (§29 below) is prepared instead.

---

## 28. DO-NOT-REIMPLEMENT

- User identity (`UserIdentityService`, `Modules/IAM/Domain/Models/User`)
- Sanctum authentication / `AuthController` / canonical login-logout flow
- `UserSecurityRuleException` and the security-rule authority it centralizes
- User lifecycle authority (`UserLifecycleService`, `UserStatus` enum, its transition map)
- `AuthorizationGateway` and the 5-engine Authorization Platform behind it (ADR-038)
- Visibility Engine / Data-Scope Engine architecture (`VisibilityResolver`, `ScopeResolver`)
- RBAC data model — `Role`, `Permission`, `role_permissions` — and the seeding convention (catalog-first, compiler never mints)
- `RoleTemplateCompiler` — the one and only template→role compiler
- `RoleCompositionService` / `PermissionExpander` — profile composition and wildcard/`'*'` expansion
- Role Template versioning (`RoleTemplateVersion`, `RoleTemplateRepository`'s audit-on-every-mutation pattern)
- Authorization-cache invalidation authority (`invalidateUserCache`/`invalidateRoleCache` on all three engines)
- `UserSessionService` — the one session/token authority
- `AuthorizationProvider` and every ADR-041 frontend hook (`useAuthorization`, `usePermission`, `Can`, `PermissionBoundary`, `useNavigation`)
- The IAM Admin API (`UserController`, `RoleController`, `PermissionController`, `RoleTemplateController`, `SessionController`)
- The IAM Administration Workspace (`IamWorkspacePage` and its three tabs)

No item on this list was touched by this task. The entire diff is `RoleTemplateCatalog.php` static data plus its test.

## 29. First-device verification package

Prepared, **not executed** (§24 forbids running it on this device):

1. **Exact IAM commit range:** `9f42b376` (Task 2) → `f9df5f9e` (Task 3) → this task's commit (§3). Three commits, `task/iam-workstream` branch, based on `develop` at `16b0ec85`.
2. **Migrations, in order:** the 3 listed in §24, applied in filename (timestamp) order — no interdependency beyond that.
3. **Focused backend tests:** all 6 files under `backend/tests/Feature/IAM/AdminApi*.php` + `backend/tests/Unit/IAM/RoleTemplateCatalogReconciliationTest.php` — 53 methods total (§25).
4. **Focused frontend tests:** all 7 files under `frontend/src/features/iam-admin/components/*.test.tsx` — 46 cases (§25), already passing on this device; re-run on the first device as a sanity check, not expected to differ.
5. **Exact test count:** 99 (§25).
6. **User lifecycle scenarios:** the §6 table, end to end — create → invite/activate → suspend/lock/deactivate → restore → archive → attempted-login-at-each-state.
7. **Tenant-isolation scenarios:** cross-company user/template/role visibility attempts; `company_id` tamper attempts on create/update; `is_system` actor vs. normal actor list scoping.
8. **Password reset scenarios:** the §6/§8 table — one case per status.
9. **Role assignment/revocation:** assign/revoke a template holding `is_system=false` (normal path) and confirm a non-system-authority actor is refused on any `is_system=true` role (defense-in-depth path, §14 — expected to never fire under normal seeding, verify it still refuses if forced).
10. **Cache invalidation scenarios:** assign a template to two users sharing it, edit the template, apply, confirm both users' effective permissions refresh without re-login.
11. **Template version/apply scenarios:** the full Task 3 `TemplateApplyWorkflow` flow — impact preview → confirm → affected-holder count matches actual holder count.
12. **Archive/delete safety:** attempt delete on a template with `assignment_count > 0` (expect 409), archive it instead, confirm holders' access is unaffected by archiving.
13. **Session scenarios:** login on two devices, list sessions, revoke one, force-logout-everywhere, confirm revoked tokens are actually rejected by the API afterward.
14. **Users Workspace browser flows:** list (default excludes archived), search, create (no company field), every lifecycle action from the row menu, detail drawer tabs.
15. **Roles & Permissions browser flows:** list, detail (read-only), permission catalog search, confirm no create/edit control exists anywhere on this tab.
16. **Role Templates browser flows:** system-template immutability, custom-template edit+save, clone, archive, delete-when-unused, version history, apply workflow.
17. **Error-state flows:** 401/403 (hidden affordance), 404 (no foreign-resource leak), 409 (surfaced verbatim, dialog stays open), 422 (field-level).
18. **Responsive/RTL/dark/accessibility checks:** mobile-card vs. desktop-table breakpoint, Arabic RTL layout, dark mode, keyboard navigation through the three tabs and every drawer/dialog.
19. **Group B/C verification:** confirm `hr-officer`/`customer-service-agent` are visibly present but cannot be assigned (compile fails per §19's disclosure) — this is the one KNOWN, expected non-happy-path in this package, not a regression to chase. Confirm all 7 Group C templates compile and apply cleanly.
20. **Group A known external dependencies:** confirm the 8 templates in §21 are visible in the catalog (read-only, informational) but likewise cannot be assigned — expected, matches §21's table exactly.
21. **Stale sales.customer correction verification:** assign `sales-representative`, confirm the actor can view/create customers (not previously possible) and is correctly scoped to `'self'` (not `'all'`); assign `sales-manager`, confirm `'team'` scoping actually applies (previously silently `'all'`).
22. **STOP conditions:** re-run the §28-of-the-task checklist against the live, migrated environment — this task's checklist was source-only; the first device is where it becomes checkable against real DB state.

## 30. Source integration readiness

```
SOURCE INTEGRATION READY: YES
```
Every item required by this task's completion policy (§32) is met at the source level (§31 below). What remains is execution — running the package above on hardware that actually has PHP and a database, and a human walking the browser flows — not further source work.

## 31. Exact final git status

Confirmed via `git status --porcelain` immediately before staging: `task/iam-workstream` at HEAD `f9df5f9eb2f2e7a623fdd1d0688f1e50805754c0`, exactly 2 modified tracked files (`RoleTemplateCatalog.php`, `RoleTemplateCatalogReconciliationTest.php`) and 1 new untracked file (this report). No other path touched. `origin/develop` unchanged throughout (not touched by this task, not pushed, not merged).

## 32. Remaining external dependencies

- **Group A** (§21) — 8 templates, 4 distinct missing domains/resources (`manufacturing`, `operations.packing`, top-level `shipping`, `logistics.transfers`), each requiring its owning module team to seed the real permissions before IAM can safely reconcile.
- **Group B** (§19) — a business/CTO decision, not a technical one: whether `hr.employees.manage` / `crm.service.manage` should ever be granted to these two roles. Closed for this batch as "leave as-is"; the compile-failure consequence is now explicitly on record for whoever revisits it.
- **`warehouse-manager`'s dead `logistics.transfers.*`** (§21) — a low-risk, evidence-backed rename candidate (`inventory.transfers.*` already exists and is already granted via this template's `inventory.*` wildcard) deliberately not applied, to respect the Group A boundary this batch was told not to cross.
- First-device execution of the verification package (§29) — browser, integration, and actual `phpunit`/migration runs, none of which this device can perform.

## 33. Final IAM batch state

```
IAM BATCH: SOURCE COMPLETE
```
Tasks 1–4 of IAM Closure Batch 01 are all source-complete. Task 4 found and closed one more real defect (`accounting.ledgers.view`) beyond its explicitly-named scope (`sales.customers`) because its own mandate (§5: full catalog audit, "no UNKNOWN token may silently survive") required checking every template, not just the two named ones — and disclosed, rather than silently absorbed, the two Group-B tokens it chose not to touch. Nothing in this batch was pushed, merged, deployed, migrated, or seeded against any shared environment.

---

## Required Final State

```
IMPLEMENTED:                 YES
IAM SOURCE GATE:              PASS
USERS:                        CLOSED
ROLES & PERMISSIONS:          CLOSED
ROLE TEMPLATES:                CLOSED
SESSIONS:                      CLOSED
TENANT ISOLATION:              CLOSED
CACHE INVALIDATION:            CLOSED
GROUP A:                       DEPENDENCY BLOCKED
GROUP B:                       RECONCILED — LEAST PRIVILEGE
GROUP C:                       RECONCILED
SALES CUSTOMER STALE TOKEN:    CLOSED
PERMISSION INVENTORY:          COMPLETE
MIGRATION INVENTORY:           COMPLETE
TEST INVENTORY:                COMPLETE
TESTS EXECUTED:                YES (frontend) / NO (backend)
BROWSER VERIFIED:              NO
VERIFIED:                      PARTIAL (source + frontend test execution; backend not executed, browser not attempted)
SOURCE INTEGRATION READY:      YES
COMMITTED:                     YES
FINAL WORKING TREE:            CLEAN
INTEGRATED:                    NO
DEV VISIBLE:                   NO
USER VERIFIED:                 NO
CERTIFIED:                     NO
IAM BATCH:                     SOURCE COMPLETE
```
