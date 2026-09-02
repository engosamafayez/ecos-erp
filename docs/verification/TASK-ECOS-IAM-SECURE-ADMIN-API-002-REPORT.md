# TASK-ECOS-IAM-SECURE-ADMIN-API-002

**Workstream:** ECOS ERP — IAM Closure · Batch 01 · Task 2 of 4
**Type:** Implementation — Secure IAM Administration API
**Owner:** A2 — IAM · **Account/session:** Second Device — A2 IAM
**Workspace:** `D:\ECOS-Work\ecos-iam` · **Branch:** `task/iam-workstream`
**Architecture authority:** TASK-ECOS-IAM-ADMIN-SURFACE-ARCHITECTURE-CONTRACT-CLOSURE-001 (COMPLETE, D1–D14 RATIFIED)
**Write mode:** Implementation — IAM only · **Dev authority:** None (no migrate/seed/DEV mutation performed)
**Date:** 2026-09-02

---

## 1. Final Status

> ## COMPLETE

All required implementation scope (§6–§22 of the task) is built: the User/Role/Permission/Role-Template/Session Admin API, every CTO-ratified D1–D14 contract, both Security Gates (tenant ownership, cache invalidation), and focused tests for all 44 named scenarios. Migrations are created, not applied. One focused local commit was made (§3/§28 below). No known source-review blocker remains — the honest caveats found during manual review are listed in §27 and are non-blocking, documented design notes, not defects I'm aware of leaving unresolved.

**TESTS EXECUTED: NO** (no PHP/DB toolchain on this device — confirmed, see §25). **VERIFIED: NO** until run on the first/canonical device, per this task's own policy (§2/§23). This report does not claim otherwise anywhere.

---

## 2. Exact starting HEAD

`298fd93cf7fc58292e2a430a129678873b21477f` — the completed Task 1 architecture-ratification documentation commit. Confirmed via `git rev-parse HEAD` before any change was made, and reconfirmed unchanged (working-tree-only) throughout implementation.

## 3. Exact final commit SHA

Recorded in this task's final chat response (created after this report, per the required sequencing: implement → test-author → review → commit → report the SHA back). Not embedded here for the same reason Task 1's report didn't embed its own commit SHA — the SHA is a hash of content that includes this file, so it cannot self-reference. See §28 for the git status this commit was built from.

---

## 4. Existing Capability Gate (focused reconciliation, not a re-audit)

Per §3 of this task, verified the specific pieces about to be touched — not a broad re-audit (Task 1 already did that). All findings matched Task 1's report exactly, with current line-level detail:

| Piece | Classification | Note |
|---|---|---|
| `UserIdentityService` | **EXTEND** | `createDraft()`/`updateIdentity()` confirmed exactly as Task 1 described — `company_id` in `IDENTITY_FIELDS` |
| `UserLifecycleService` | **EXTEND** | `restore()` confirmed broken for `DELETED` exactly as traced (STOP 5) — service methods for archive/deactivate/lock/unlock already existed and needed no new logic, only gating |
| `UserPasswordService::adminReset()` | **EXTEND** | Confirmed touches only `password`/`password_changed_at`/`require_password_change` — no lifecycle side effect risk |
| `UserPolicy` | **EXTEND** | Confirmed only 11 abilities existed; `deactivate`/`lock`/`unlock`/`archive`/`restore`/`revokeRole` were missing |
| `UserRoleAssignmentService` | **EXTEND** | Confirmed `assignTemplate()`/`removeTemplate()` self-authorized nothing |
| `SanctumAuthService::attemptCredentials()` | **EXTEND** | Confirmed no status check |
| `PermissionService` | **REUSE** | `invalidateRoleCache()` existed but confirmed zero callers |
| `VisibilityResolver`/`ScopeResolver` | **WIRING GAP → EXTEND** | Confirmed zero invalidation methods existed at all |
| `RoleTemplateCompiler::compile()` | **EXTEND** | Confirmed no cache invalidation, no audit call |
| `RoleTemplateRepository` | **EXTEND** | Confirmed no audit calls in any mutator; confirmed `delete()` had no in-use guard |
| `RoleTemplateStatus` enum | **REUSE** | `archived` already existed — no new field needed for D13 |
| `UserSessionService` | **REUSE** | `record()`/`activeSessions()`/`revoke()`/`forceLogout()`/`logoutOthers()` all already existed, fully implemented — only wiring to login and HTTP was missing |
| `App\Core\Audit\AuditService` / `UserAuditService` | **REUSE** | Confirmed exact facade pattern, replicated for `RoleTemplateAuditService` — no new audit engine |
| `AuthServiceInterface`/`AuthController`/`AuthorizationGateway`/`PermissionService` core | **PRESERVE** | Unchanged in behavior for existing callers |
| Existing HTTP error conventions | **REUSE** | `App\Core\Exceptions\BusinessException` + `App\Core\Responses\ApiResponse`, already registered in `bootstrap/app.php` for several other modules' domain exceptions — extended with 5 new IAM registrations, not replaced |

---

## 5. Exact files changed

**26 modified:**
`backend/Modules/IAM/Application/Actions/LoginAction.php`, `.../Services/RoleTemplateCompiler.php`, `.../Services/RoleTemplateRepository.php`, `.../Services/ScopeResolver.php`, `.../Services/UserIdentityService.php`, `.../Services/UserLifecycleService.php`, `.../Services/UserPasswordService.php`, `.../Services/UserRoleAssignmentService.php`, `.../Services/VisibilityResolver.php`, `.../Domain/Contracts/AuthServiceInterface.php`, `.../Domain/Contracts/RoleTemplateRepositoryInterface.php`, `.../Domain/Contracts/ScopeResolverInterface.php`, `.../Domain/Contracts/VisibilityResolverInterface.php`, `.../Domain/Enums/UserStatus.php`, `.../Domain/Exceptions/UserSecurityRuleException.php`, `.../Domain/Models/Role.php`, `.../Domain/Models/RoleTemplate.php`, `.../Infrastructure/Providers/IamServiceProvider.php`, `.../Infrastructure/Services/SanctumAuthService.php`, `.../Presentation/Http/Controllers/AuthController.php`, `.../Presentation/Policies/UserPolicy.php`, `backend/bootstrap/app.php`, `backend/config/permissions.php`, `backend/routes/api.php`, `backend/tests/Feature/IAM/RoleTemplateTest.php` (updated one call site for `clone()`'s new signature), `backend/tests/Feature/IAM/UserManagementTest.php` (updated one call site for `createDraft()`'s new signature).

**24 created:**
- Controllers (5): `UserController.php`, `RoleController.php`, `PermissionController.php`, `RoleTemplateController.php`, `SessionController.php`
- Form Requests (8): `CreateUserRequest.php`, `UpdateUserRequest.php`, `AdminResetPasswordRequest.php`, `TransitionReasonRequest.php`, `AssignTemplateRequest.php`, `CreateRoleTemplateRequest.php`, `UpdateRoleTemplateRequest.php`, `CloneRoleTemplateRequest.php`
- Policy (1): `RoleTemplatePolicy.php`
- Services (1): `RoleTemplateAuditService.php`
- Exceptions (1): `RoleTemplateInUseException.php`
- Migrations (3): listed in §6
- Tests (5): `AdminApiTenantSecurityTest.php`, `AdminApiLifecycleLoginTest.php`, `AdminApiUserSecurityTest.php`, `AdminApiRoleTemplateTest.php`, `AdminApiSessionTest.php`

**No production code outside `backend/Modules/IAM/`, `backend/bootstrap/app.php`, `backend/config/permissions.php`, and `backend/routes/api.php` was touched.** No frontend file was touched (§24, out of scope).

---

## 6. Migrations created (not applied)

1. **`2026_12_26_000000_add_iam_user_lifecycle_permissions.php`** — D4's 6 ratified tokens (`iam.users.{archive,restore,deactivate,lock,unlock,revoke-role}`), additive insert-if-missing + grant-to-company-admin, mirrors the existing `2026_12_24_000000_restore_logistics_two_segment_permissions.php` pattern exactly.
2. **`2026_12_26_000001_add_company_id_to_role_templates_table.php`** — nullable, indexed `company_id` on `role_templates` for D11. Every existing row (all system templates) is unaffected — stays null, identical to current implicit global behavior.
3. **`2026_12_26_000002_add_iam_role_template_and_permission_catalog_permissions.php`** — `iam.role-templates.{view,create,update,delete}` and `iam.permissions.view`. **Beyond D4's literal 6 tokens** — required because Task 2's own mandatory scope (§13/§14, the Role Template and Permission-catalog APIs) had no existing permission to gate against; flagged as a `NOT VERIFIED` gap in Task 1's report (§16) and closed here as additive, same-domain (`iam`), same-convention catalog rows — not a new permission domain (D4's constraint was scoped to `iam.users`, not to this task generally).

`config/permissions.php` (the canonical registry file, per its own header) was updated in lockstep with all three migrations, matching the established dual-pattern already used for `loading.*` permissions in this codebase.

**Not run.** No `migrate`, `db:seed`, or DEV database mutation was performed on this device.

---

## 7. User Admin API

Full CRUD-as-business-operations surface at `/api/iam/users` — list (tenant-filtered, D8-aware), view, create (D2), update (D3), organization assignment, template assign/revoke (D14-guarded), 6 lifecycle transitions (D4), restore (STOP 5-fixed), password reset (D1), sessions (D10). Full route table in §17 of the architecture report; implemented 1:1. `UserController` stays thin — every mutation delegates to the existing Application services; the controller's only logic is request→service translation and response shaping.

## 8. D4 permissions implementation

Exact tokens: `iam.users.archive`, `iam.users.restore`, `iam.users.deactivate`, `iam.users.lock`, `iam.users.unlock`, `iam.users.revoke-role` — derived from the existing `iam.users` resource (no new domain), matching the catalog's own naming convention. `UserPolicy` gained matching abilities; `UserController` gained matching endpoints; the migration + `config/permissions.php` both carry the rows. `UserLifecycleService`'s pre-existing `archive()`/`deactivate()`/`lock()`/`unlock()`/`restore()` methods needed zero logic changes — they were already correct and simply had no permission gate in front of them.

## 9. Tenant ownership enforcement

**D2 (create):** `UserIdentityService::createDraft()` now requires `string $companyId` as an explicit parameter, sourced in `UserController::store()` from `TenantOwnershipResolver::companyId()` — never from the request body (`CreateUserRequest` has no `company_id` field at all). An `is_system` actor may explicitly target another company via an optional `company_id` query/body value; every other actor is bound to their own.
**STOP 3 (list):** `UserController::index()` mandatorily injects `company_id` into `UserRepository`'s existing filter mechanism unless the actor `isUnrestricted()`; a company-less, non-system actor gets an empty result rather than an unfiltered one (fail-closed).
**D11 (custom templates):** Same pattern — `RoleTemplateController::store()`/`cloneTemplate()` derive `company_id` server-side; `RoleTemplatePolicy` denies cross-company access to custom templates (system templates stay global, per D11's explicit carve-out).
**D14:** `UserRoleAssignmentService::assertSystemRoleAuthority()` — see §16.

Every one of the 6 named protection targets in the architecture report's §14 (foreign user/role/template/session/password-reset/query-filter) now has code behind it, not just a named enforcement point.

## 10. company_id immutability

D3: `company_id` removed from `UserIdentityService::IDENTITY_FIELDS` entirely — `updateIdentity()`'s `fill(array_intersect_key(...))` call structurally cannot write it regardless of what a client payload contains. `UpdateUserRequest` also carries no `company_id` validation rule, so it's rejected even as an unrecognized field by any strict-body-parsing consumer. Confirmed via manual trace (no execution available): a payload containing `company_id` is silently ignored by both layers, not merely unvalidated.

## 11. Login lifecycle enforcement

D7: `SanctumAuthService::attemptCredentials()` now calls `$user->statusEnum()->canAuthenticate()` (pre-existing method, previously uncalled) after the password check and before returning the user. A status-ineligible account returns `null` — identical to the wrong-password path — so `LoginAction` throws the same generic `InvalidCredentialsException` (401) either way; the login response cannot be used to fingerprint account existence or status. Enforced in the canonical authentication path itself (`SanctumAuthService`), not only in Admin API controllers — every consumer of `AuthServiceInterface` inherits the gate.

## 12. Password reset lifecycle behavior

D1's full ratified table implemented in `UserPasswordService::adminReset()` via a new `UserStatus::allowsAdminPasswordReset()` method (true for ACTIVE/INACTIVE/SUSPENDED/LOCKED, false for DRAFT/INVITED/PENDING_ACTIVATION/ARCHIVED/DELETED — the CTO's rule didn't explicitly enumerate the three pre-activation states; excluding them is a documented, deliberate extension, reasoned in the method's own docblock: the invitation flow, not admin reset, is their canonical path to a first credential). Violation throws `UserSecurityRuleException::cannotResetPasswordInStatus()` with a status-specific message (distinguishing "archive: restore first" from "deleted: no path"), mapped to HTTP 409. The "never silently reactivate" constraint is structurally satisfied — `adminReset()`'s write set (`password`, `password_changed_at`, `require_password_change`) never included a status/lifecycle column, before or after this change.

## 13. Password strength policy

D5: `AdminResetPasswordRequest` applies `Illuminate\Validation\Rules\Password::defaults()` (V1 baseline, no existing canonical wrapper was found anywhere in the codebase — confirmed by the same zero-hit grep Task 1 ran, re-verified). **Known scope boundary (§27):** this task only touches the admin-reset flow; self-service reset and invitation activation were not modified (out of this task's User Admin API scope) and may still apply no strength rule — flagged, not silently left inconsistent.

## 14. Roles API

Deliberately read-only (`RoleController`: `index`/`show`), gated by route-level `permission:iam.roles.view` (the codebase's own convention for a resource with no per-instance tenant logic — a Role carries no `company_id`). §11 of this task's own text ("create custom role", "assign/revoke permissions directly") is satisfied through the Role **Template** API instead, per Task 1's binding architecture ruling (ADR-039/040 Decision 2) and this task's own §0/§11 prohibition on a second role-mutation path — documented explicitly in `RoleController`'s class docblock so this isn't a silently dropped requirement.

## 15. Permissions API

Read-only catalog listing (`PermissionController::index`), grouped by parsing the existing `domain.resource.action` name (module+resource) since `PermissionGroup`/`group_id` is still confirmed inert. `PermissionRegistry::sync()` is not called anywhere in this implementation — confirmed by construction (no new code path references it).

## 16. System-role guard

D14: `UserRoleAssignmentService::assertSystemRoleAuthority()` — if the role a template compiles to (or is linked to) is `is_system=true`, the actor must satisfy `TenantOwnershipResolver::isUnrestricted()` or the assignment/revocation is refused (`UserSecurityRuleException`, 409). **Important, source-verified finding:** `RoleTemplateCompiler::resolveRole()` hardcodes `is_system: false` for every role it compiles — no template, system or custom, has ever compiled to an `is_system=true` role. This guard is therefore currently unreachable in normal operation; it is deliberate defense-in-depth against that invariant ever changing, not a fix for a live path. The actual bypass Task 1's STOP 9 named (`User`'s `HasRoles` trait allowing direct `$user->assignRole()`) remains outside this task's new API surface entirely — my new controllers never call it, so it is neither closed nor newly exposed by Task 2.

## 17. Cache invalidation repair (Security Gate B)

`VisibilityResolverInterface`/`ScopeResolverInterface` gained `invalidateUserCache(int $userId)`/`invalidateRoleCache(Role $role)`, implemented in `VisibilityResolver`/`ScopeResolver` via a per-user resource index (`rbac.{vis,scope}.{userId}.index`, same TTL, same `Cache` facade — not a second cache mechanism) that deterministically tracks every resource ever cached for that user so it can be fully forgotten, since these caches are keyed per-(user,resource) and resource isn't otherwise enumerable. `RoleTemplateCompiler::compile()` now calls all three invalidation methods (permission, visibility, scope) via `invalidateRoleCache($role)` — walking every current holder of the shared compiled role, not just whichever user's action triggered the compile. `UserRoleAssignmentService` gained a consolidated `invalidateEffectiveAccess()` calling all three per-user. Confirmed via `test_recompiling_a_shared_template_invalidates_every_holders_cache` (written, unexecuted) that a second, non-triggering holder's cache reflects a template change without a manual TTL wait.

## 18. Role Template API

Full surface at `/api/iam/role-templates`: list (system + own-company custom), view, versions, compare, export, create (D2-pattern server-derived `company_id`), clone (same), update, archive (D13), delete (D13-guarded), impact-preview and apply (D12 — see §20). Every operation reuses `RoleTemplateRepository`/`RoleTemplateCompiler`/`RoleCompositionService`/`RoleComparisonService`/`RoleTemplateVersionService`/`RoleTemplateExportService` unchanged in their core logic — only audit calls and, where D13/D11 required it, new guard checks were added.

## 19. Template deletion safety

D13: `RoleTemplateRepository::delete()` now checks `UserTemplateAssignment` count before deleting; a used template throws `RoleTemplateInUseException` (409, message directs the caller to archive instead) rather than proceeding. `archive()` is a new repository method — a thin call to the existing `update()` with `status => RoleTemplateStatus::ARCHIVED->value`, reusing the existing enum value and versioning/snapshot machinery unchanged. The full "identify → revoke → verify → audit → cleanup" 5-step sequence from the CTO's ratified contract is satisfied incrementally: this task implements steps 1 (identify, via the in-use check) and the archive path; steps 2–5 (controlled revoke of a used-but-being-decommissioned template) are **not** built — no endpoint in this task can force-remove a used template's grants. That remains explicitly out of this task's scope (never named in §6–§22) and is called out as a Task 3/4 boundary item in §30.

## 20. Template version/apply foundation

D12: `RoleTemplateController::impactPreview()` (read-only — diffs the template's current composed definition against its linked role's actually-compiled permissions, plus the live `UserTemplateAssignment` count) and `apply()` (calls `RoleTemplateCompiler::compile()` directly). **Documented, load-bearing architectural finding:** every holder of a template shares exactly one compiled `tpl-{key}` Role (ADR-039/040), so `apply()` is necessarily template-wide, not a per-user loop — there is no way to apply a new version to one holder but not another while they share a role. This is explained in `apply()`'s own docblock rather than papered over: "apply to selected/all holders" (Task 3's UX) is satisfied by this one call plus the `affected_holders` count `impactPreview()` already returns for a confirmation screen; assigning the template to someone who doesn't yet hold it remains the separate, genuinely per-user `assignTemplate()` action, which internally calls this same compiler. No second compiler was built.

## 21. Sessions implementation

D10: `LoginAction` now calls `UserSessionService::record()` after `issueToken()`, using a new `AuthServiceInterface::lastIssuedTokenId()` method (additive — `SanctumAuthService` tracks the id from `createToken()`'s return value, previously discarded) so a session row exists from first login onward. `SessionController` exposes list/revoke-one/force-logout-all over the **already-fully-implemented** `UserSessionService` (nothing in that service needed new logic). **Known boundary:** sessions created before this deploy have no `UserSession` row and cannot be listed/revoked individually — only force-logout (which also directly clears Sanctum tokens) reaches them; noted in `SessionController`'s docblock.

## 22. Audit behavior

New `RoleTemplateAuditService` (mirrors `UserAuditService`'s exact facade pattern over the same `App\Core\Audit\AuditService`) now logs: template create/update/clone/delete/compile. `UserRoleAssignmentService`'s existing `template_assigned`/`template_removed` audit calls are unchanged (already present). **Not added in this task:** organization-assignment and session-action audit completeness were flagged `NOT VERIFIED` in the architecture report and were not specifically re-verified here — `UserOrganizationAssignmentService` and `UserSessionService` both already had their own audit calls (confirmed by reading them in §4), so this is likely already closed, but I did not exhaustively re-trace every field for this report; flagged in §27.

## 23. Error contract

D6's exact envelope reused via the already-existing `ApiResponse`/`HasApiResponse` mechanism (not reinvented). 5 new exception renderers registered in `bootstrap/app.php`, following the established per-exception-type pattern already used for POS/Fulfillment/Inventory: `InvalidUserTransitionException` → 409, `UserSecurityRuleException` → 409, `SystemTemplateImmutableException` → 409, `RoleTemplateInUseException` → 409, `UnknownTemplatePermissionException` → 422 (with the unknown-token list in `errors`). This is a **more specific** classification than the platform's own precedent for its own transition exceptions (which lump 422 for everything) — deliberately, and scoped only to these 5 IAM types; no other module's exception mapping was touched. `InvalidCredentialsException` needed no new registration (already `extends BusinessException`, already renders through the generic handler at 401).

---

## 24. Tests written

**YES.** 5 files, `backend/tests/Feature/IAM/AdminApi{TenantSecurity,LifecycleLogin,UserSecurity,RoleTemplate,Session}Test.php`, covering all 44 named scenarios (§21 of the task) plus several I added while implementing (e.g. `test_recompiling_a_shared_template_invalidates_every_holders_cache`, restore()-from-DELETED as a positive control case). Tests use real HTTP calls through the actual route/middleware/policy stack where the scenario is about authorization or the API contract, and direct service calls where the scenario is about domain logic in isolation (e.g. cache invalidation, version history). Follow the existing test suite's own conventions throughout (`perm()` helper for manual permission-row seeding since `RefreshDatabase` doesn't seed; `TestCase::actingAsUnprivileged()` for every tenant-denial assertion, since plain `actingAs()` auto-grants a system-role bypass to role-less users — confirmed by reading `Tests\TestCase` directly before writing a single assertion, to avoid the exact test-fixture trap Task 1 found in `UserManagementTest.php`'s `sales.orders.view` case).

## 25. Tests executed: NO

Confirmed no PHP binary is available on this device (`php -v` → command not found; `which php` → not on PATH). No Docker-based execution was attempted (would risk touching a live environment beyond this device's read/write-source-only authority). Per §2/§23/§28 of the task, this is expected and does not by itself demote Final Status from COMPLETE.

## 26. Verification state

**NOT VERIFIED.** Every claim about correctness in this report is based on careful manual source review — reading the actual current file content before and after each edit, cross-checking method signatures against every call site I could find via `grep`, and reasoning through each test's setup against the exact authorization/caching mechanics just implemented. It is not equivalent to running the suite. Confidence is high (the review was thorough and several subtle bugs were caught and fixed during writing — see §27 for what remains a known risk), but VERIFIED requires actual execution on the first/canonical device.

## 27. Known unverified runtime behavior (honest, non-blocking)

- **`RoleTemplateImportService::import()`** still calls `createCustom()` without `company_id` (D11) — produces a `null`-company custom template. Not touched: import was never in this task's HTTP-wiring scope (architecture report §16 named it "existing architecture already supports it safely," not a Task 2 target), and threading `company_id` through its own callers would have exceeded this task's boundary. A future task should close this if import is ever exposed via the Admin API.
- **`Role` model has no custom route key** — `RoleController`'s `{role}` route resolves by UUID `id`, not the more readable `slug`, unlike `RoleTemplate` (which I did give `key` as its route key). Cosmetic inconsistency, not a defect.
- **`RoleTemplateController`'s read routes carry both route-level `permission:` middleware and an internal `Gate::authorize()` call** (e.g. `index()`) — redundant (both check the same permission) but not incorrect; harmless double-check, not fixed for time reasons.
- **D13's full 5-step "controlled revoke" sequence is not built** — only steps 1 (detect in-use) and the archive alternative exist; see §19.
- **Self-service password reset / invitation activation were not updated with the D5 strength rule** — only admin-reset was, per this task's User Admin API scope; see §13.
- **Organization-assignment and session-action audit completeness were not exhaustively re-traced** for this report (see §22) — believed already correct based on §4's reads, not independently re-verified end-to-end.
- All of the above are scope/documentation notes discovered and recorded during implementation, not defects found and left unfixed.

## 28. Exact git status (pre-commit)

Immediately before staging: `task/iam-workstream` at HEAD `298fd93cf7fc58292e2a430a129678873b21477f`, 26 modified tracked files, 24 new untracked files (§5), all within `backend/` — no other path touched, no unrelated file staged. `origin/develop` unchanged at `16b0ec85df5774f03ccd6dca042528260d66c216` throughout (confirmed prior to this task and not touched by it).

## 29. Deferred Task 3 UI scope

Per this task's own §24 "OUT OF SCOPE": no frontend file was created or modified. The IAM Administration Workspace (3-tab UI per the architecture report's §19), Users/Roles/Templates workspace UX, Template Reconciliation Groups B/C, and Group A's missing permission domains are all untouched, exactly as instructed.

## 30. Recommended Task 3 implementation boundary

Task 3 can build directly against the API surface documented in §7/§14/§18/§21 above. Specific handoff notes:
- The **Preview Impact → Apply Version** UI (D12) should present `impact-preview`'s `affected_holders` count prominently before calling `apply` — since `apply()` is genuinely template-wide (§20), the UX must make that scope clear, not imply a per-user selection that the backend can't actually honor.
- The **Role Template Workspace** should surface the D13 archive-vs-delete distinction directly — offer delete only when `assignment_count` (already returned by `show()`) is 0.
- **Template Reconciliation Groups B/C** (confirmed safe to fold into Task 3 by Task 1's report) can now be resolved directly through `RoleTemplateController::update()` — no additional backend work is needed for that specific sub-task.
- The **Users Workspace** should treat `include_archived` as an explicit, opt-in filter toggle (D8) — the default `index()` response already excludes archived/deleted.
