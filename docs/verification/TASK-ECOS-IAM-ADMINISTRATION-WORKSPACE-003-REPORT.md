# TASK-ECOS-IAM-ADMINISTRATION-WORKSPACE-003

**Workstream:** ECOS ERP — IAM Closure · Batch 01 · Task 3 of 4
**Type:** Implementation — IAM Administration Workspace (frontend) + Group B/C Template Reconciliation
**Owner:** A2 — IAM · **Account/session:** Second Device — A2 IAM
**Workspace:** `D:\ECOS-Work\ecos-iam` · **Branch:** `task/iam-workstream`
**Backend authority:** TASK-ECOS-IAM-SECURE-ADMIN-API-002 (COMPLETE, commit `9f42b37682d97f461209496fab36bb2d2e5d61e6`)
**Write mode:** Implementation — IAM frontend + minimal, evidence-gated backend catalog reconciliation · **Dev authority:** None
**Date:** 2026-09-02

---

## 1. Final Status

> ## COMPLETE

The IAM Administration Workspace is built: one page, three tabs (Users / Roles & Permissions / Role Templates), reached through the sidebar's existing "Users" and "Roles & Permissions" nav items with no new top-level nav entry. All lifecycle, password-reset, role-assignment, session-management, and template-apply UX contracts from the task are implemented against the real TASK-ECOS-IAM-SECURE-ADMIN-API-002 API, using only ADR-041 authorization primitives and the existing CRUD kit. D12's template-wide apply ruling is presented literally — no per-user selection UI exists anywhere, because none is backed by the API. Group C reconciliation (7 templates) is applied with evidence; Group B (2 templates) is confirmed-real-but-deferred, unchanged; Group A (8 templates) is confirmed untouched, dependency-blocked. 34 named test scenarios are written; unlike Task 2, **they were actually executed on this device** (Node/npm are available here, unlike PHP) — all 46 frontend test cases pass, and every `iam-admin` file (34 files: 17 components + 7 test files + 10 types/services/hooks/page) compiles clean under `tsc -b --noEmit`. Running that check across the **whole** project (not just this task's files) surfaces ~19 pre-existing type errors in files this task never touched (`configuration-os-page.tsx`, `manual-order-form.tsx`, `movement-type-badge.tsx`, and others — full list in §23); none are in `iam-admin` and none were introduced by this task, confirmed via `git status` on each affected directory. The 3 backend reconciliation scenarios are written as a pure PHPUnit unit test but **not executed** (no PHP on this device — same constraint as Task 2). One focused local commit was made (§3).

**§16 self-correction, disclosed prominently rather than buried:** while independently re-verifying the Group C evidence for this task, I discovered that 2 of the 7 Group C templates I had reconciled earlier in this same session (`cashier`, `ai-analyst`) contained **fabricated permission tokens** — plausible-looking dot-paths that do not exist anywhere in the codebase, including one citation of a migration as "evidence" that on direct inspection does not contain the cited tokens. This was caught and fully corrected **before this commit** through direct grep/read verification against route middleware, Policy `hasPermissionTo()` calls, and permission-seeding migrations — see §16 for the complete trail. No fabricated token reached a commit at any point.

**TESTS EXECUTED: YES (frontend), NO (backend).** **VERIFIED: PARTIAL** — frontend is machine-verified (typecheck + full test run, see §22/§23); backend reconciliation is manually verified via direct source grep (§16) but not test-executed, and the workspace has not been opened in a real browser (§24).

---

## 2. Exact starting HEAD

`9f42b37682d97f461209496fab36bb2d2e5d61e6` — Task 2's "feat(iam): add secure administration api" commit. Confirmed via `git rev-parse HEAD` and `git log --oneline -3`.

## 3. Exact final commit SHA

Recorded in this task's final chat response, created after this report and after `git status` is reconfirmed clean of anything unexpected (§25), for the same self-reference reason Task 1 and Task 2's reports gave.

---

## 4. Existing Frontend Capability Gate (focused reconciliation, not a re-audit)

Per the task's mandate to inspect actual current frontend source rather than rely on stale documentation, the following were read directly before writing any code:

| Piece | Classification | Note |
|---|---|---|
| `ROUTES.users` / `ROUTES.roles` | **REUSE (redirect target)** | Confirmed both already existed as real sidebar nav targets rendering `ComingSoonPage` via `moduleRoutes` in `router.ts` — no new nav entry was needed, only redirecting these two existing entries to the real workspace |
| `@/features/authorization` (`Can`, `usePermission`, `PermissionBoundary`, `useAuthorization`) | **REUSE** | Confirmed exact exports and signatures in `index.ts`/`use-authorization.ts`/`components/can.tsx`/`guards/require-permission.tsx` before using any of them — no new permission store, no hardcoded role check anywhere in this task's code |
| `@/components/crud` (EntityTable, EntityDrawer, ConfirmDialog, PageHeader, SearchInput, Pagination, EmptyState/ErrorState/LoadingState, ActionMenu, Combobox) | **REUSE** | Read every primitive's actual props/behavior (not assumed) — e.g. `EntityTable` renders both a mobile-card and a desktop-table branch simultaneously in the DOM (Tailwind-class-gated, not JS-gated), which shaped how the tests query for text (§21) |
| `StatusBadge`/`StatusVariant` (shared crud kit) | **NOT EXTENDED** | Confirmed `StatusVariant` only models `active/inactive/pending/archived` — User has 9 lifecycle states (`UserLifecycleStatus`). Built a local, IAM-scoped `UserStatusBadge` reusing the same Badge+dot visual language instead of widening the shared contract for every other module |
| `RoleTemplateController::apply()` (Task 2, backend) | **REUSE, VERIFIED TEMPLATE-WIDE** | Confirmed it calls `RoleTemplateCompiler::compile()` directly on the template's single compiled `Role` — no per-holder loop exists. This is what D12's ruling is built on (§10) |
| i18n selector-mode `t($ => $.path)` + dynamic bracket-key selectors (`$.foo[variable]`) | **REUSE, VERIFIED PROVEN** | Confirmed this exact dynamic-key pattern is already used extensively (finance, driver-mobile, driver-settlement — dozens of call sites) before using it in `users-tab.tsx`'s lifecycle-confirmation dialog |
| `frontend/src/i18n/types.ts` | **EXTENDED, REQUIRED** | Despite `namespaces.ts`'s own comment calling per-file typing "optional," omitting the new `iam-admin` entry from `CustomTypeOptions.resources` would leave every `t($ => $.foo)` call in the new namespace without compiler key-safety, contradicting the file's own stated purpose. Added in alphabetical position, matching the existing pattern exactly |

No second role engine, authentication authority, permission store, or parallel auth context was created anywhere in this task.

---

## 5. Exact files changed

**7 modified (tracked):**
`backend/Modules/IAM/Domain/Catalog/RoleTemplateCatalog.php` (Group B/C reconciliation, §16), `frontend/src/i18n/locales/{ar,en}/common.json` (added `common.close`, previously missing), `frontend/src/i18n/namespaces.ts` (registered `iam-admin`), `frontend/src/i18n/types.ts` (registered the `iam-admin` type import, §4), `frontend/src/router/router.ts` (registered `IamWorkspacePage` at 3 routes, removed `ROUTES.users`/`ROUTES.roles` from the `ComingSoonPage` list), `frontend/src/router/routes.ts` (added `roleTemplates` route constant).

**37 created**, all under `frontend/src/features/iam-admin/` (34 files) plus 2 locale files and 1 backend test — exact counts confirmed against `git status` immediately before staging, not just recalled:
- **Types (3):** `types/{user,role,role-template}.ts`
- **Services (3):** `services/{users,roles,role-templates}-service.ts`
- **Hooks (3):** `hooks/{use-users,use-roles,use-role-templates}.ts`
- **Page (1):** `pages/iam-workspace-page.tsx`
- **Components (17):** `user-status-badge.tsx`, `user-form-schema.ts`, `user-create-drawer.tsx`, `users-tab.tsx`, `user-detail-drawer.tsx`, `user-roles-panel.tsx`, `user-organization-panel.tsx`, `user-security-panel.tsx`, `role-detail-drawer.tsx`, `permission-catalog-browser.tsx`, `roles-permissions-tab.tsx`, `template-permissions-editor.tsx`, `template-create-drawer.tsx`, `template-apply-workflow.tsx`, `template-detail-drawer.tsx`, `template-clone-dialog.tsx`, `role-templates-tab.tsx`
- **Tests (7):** `users-tab.test.tsx`, `user-create-drawer.test.tsx`, `user-security-panel.test.tsx`, `user-roles-panel.test.tsx`, `roles-permissions-tab.test.tsx`, `template-detail-drawer.test.tsx`, `template-apply-workflow.test.tsx`
- **Locales (2):** `i18n/locales/{en,ar}/iam-admin.json`
- **Backend test (1):** `backend/tests/Unit/IAM/RoleTemplateCatalogReconciliationTest.php`

**No production file outside `frontend/src/features/iam-admin/`, the 6 listed integration points, and `RoleTemplateCatalog.php` was touched.** No new authorization engine, compiler, or per-user compiled-role architecture was created anywhere.

---

## 6. Users Workspace — list, lifecycle, password reset, role assignment

`users-tab.tsx`: `EntityTable`-backed list, D8-compliant (archived/deleted excluded unless the `includeArchived` checkbox is explicitly checked — verified by asserting the exact query params sent, §21 scenario 1/2). Row actions show only lifecycle transitions valid for the row's **current** status (`availableActions()`, §21 scenario 4) — this is convenience only; `UserLifecycleService`'s transition map remains the authoritative gate and a stale/invalid choice still gets rejected server-side with the exact message surfaced (§21 scenario 32/409). `UserCreateDrawer` has no company field anywhere (D2/D3 — ownership is always server-derived) and maps 422 `errors` to the matching field (§21 scenario 33). Session management and password reset are folded into `UserDetailDrawer`'s **Security** tab, not a separate top-level workspace, matching the task's explicit instruction — see §7.

## 7. Session management (folded into User detail)

`UserSecurityPanel`: reset-password is a dedicated dialog, disabled with an explanatory note for `archived` (requires restore first, D1) and `deleted` (unavailable) statuses, and shows a non-blocking note for `suspended`/`locked` that resetting **does not** reactivate or unlock the account (§21 scenario 5/6 — a reset call never touches a lifecycle endpoint; verified the mutation only ever calls `resetPassword`). Sessions list, per-session revoke, and force-logout-everywhere all call the real Task 2 session endpoints and refetch through React Query's normal invalidation — no client-side list splicing (§21 scenario 10).

## 8. Roles & Permissions Workspace (read-only by architecture)

The task's literal §10 wording ("create custom role," "add/remove permissions") is satisfied through the Role Templates workspace, not here — `RoleController`'s own docblock and ADR-039/040 Decision 2 (roles compile from Role Templates only) make a directly-editable Roles surface a canonical-architecture violation. `roles-permissions-tab.tsx` is fully read-only: the list opens a read-only `RoleDetailDrawer` showing compiled permissions, with a "managed by template ⟶" pointer for template-linked roles that navigates into Role Templates instead of offering an edit affordance (§21 scenario 12). This holds identically for system and custom roles — there is no differential edit path for either (§21 scenario 14). The Permission catalog sub-tab (`permission-catalog-browser.tsx`) is read-only reference material with a search filter and no assignment control of any kind (§21 scenario 13/15) — assignment happens exclusively by editing a Role Template's definition (§9).

## 9. Role Templates Workspace

`role-templates-tab.tsx` + `template-detail-drawer.tsx` + `template-create-drawer.tsx` + `template-clone-dialog.tsx` + `template-permissions-editor.tsx`. System templates render `roleTemplates.detail.systemImmutable`, the whole definition `<fieldset disabled>`, and no Save/Archive/Delete controls (§21 scenario 17). Custom templates are editable; Save calls `RoleTemplateController::update()` directly and never implicitly applies the new definition — Apply is a separate, explicit action, per §10 below (§21 scenario 18). Permissions are picked only from the real catalog via `TemplatePermissionsEditor`'s `Combobox` — never free-typed — so a template can only ever reference a token that genuinely exists in the UI layer too (the compiler's `UnknownTemplatePermissionException` remains the backstop).

## 10. D12 template-wide apply UX (CTO ruling, presented literally)

CTO ruling (this task's §15, reproduced verbatim in the task spec): every holder of a template shares exactly one compiled `Role`; apply is necessarily template-wide. `TemplateApplyWorkflow` fetches the real impact preview the moment it opens, before any apply call can happen (§21 scenario 20), and shows `affected_holders` prominently in both a dedicated amber scope-warning banner and the stat grid (§21 scenario 22) — the confirm button itself is labeled "Apply to all current holders," not a generic "Confirm." **There is no user-selection UI anywhere in this component or its siblings** — no listbox, no checkboxes, no "selected users" affordance of any kind (§21 scenario 24, asserted directly by querying for their absence). Apply only fires on the explicit confirm click (§21 scenario 23); a successful apply invalidates both the `iam-role-templates` and `iam-users` React Query families (the task's own "refresh through current frontend patterns" cache rule — §13 below — §21 scenario 25) and shows the affected-holder count in the success confirmation. `RoleTemplateController::apply()` was independently re-confirmed (§4) to call the compiler directly with no per-holder loop — the UI's framing is not a guess, it mirrors what the backend actually does.

## 11. Impact preview fields

`TemplateImpactPreview`'s 5 fields are all rendered: `template_version` (current-version stat), `affected_holders` (scope warning + stat), `permission_additions`/`permission_removals` (green `+`/red `−` badge lists, with an explicit "None" placeholder when either is empty rather than a blank area — §21 scenario 21).

## 12. Archive vs. delete behavior

Archive is offered for every non-system template regardless of assignment count (§21 scenario 19). Delete is offered **only** when the backend's own `assignment_count` is already 0 — `template.assignment_count === 0 ? <Can permission="iam.role-templates.delete"><Button>...` — never a raw delete affordance for a template that might still be in use; the backend's own `RoleTemplateInUseException` (409) remains the authoritative backstop, this is just honest UI framing of what the API already enforces. Archiving calls `roleTemplatesService.archive()`, never `destroy()` (§21 scenario 19, asserted directly).

## 13. Cache-invalidation UX (§12 of the task)

No browser-side cache clearing, no optimistic fake success, and no parallel long-lived cache anywhere in this task's code — every mutation hook (`use-users.ts`, `use-role-templates.ts`) resolves through React Query's `invalidateQueries` + refetch, the app's one existing cache-invalidation pattern. One genuine bug in this exact area was found and fixed while writing tests: `useInvalidateUser()` called `invalidateQueries` twice with overlapping key prefixes (`[USERS_KEY]` already prefix-matches `[USERS_KEY, 'detail', id]` under React Query's default matching), causing a real, observable duplicate refetch on every user mutation. Simplified to the one broad call; the duplicate-refetch behavior was reproduced and then fixed under test (§21 scenario 16).

## 14. Authorization / navigation behavior

Every tab's visibility **and** its content are gated by the same permission check (`iam.users.view`/`iam.roles.view`/`iam.role-templates.view`) via `usePermission().can()` for the tab strip and `<PermissionBoundary fallback={<NoAccess/>}>` for the content — a user who reaches the page via a stale link but lacks the permission sees an explicit "no access" panel, not a broken/empty tab. No hardcoded role check exists anywhere in this task's code — every gate goes through `@/features/authorization`. Tab state is driven by the URL path (`ROUTES.users`/`ROUTES.roles`/`ROUTES.roleTemplates`), not a query param, matching this app's existing route-per-section convention; three tabs don't need the hub+`?tab=` layering Configuration OS uses for its 14 categories. No new sidebar entry was added — Role Templates has no nav entry of its own by design, reached via the in-page tab or a "managed by template" link from Roles.

## 15. Tenant / foreign-resource behavior

`UserRolesPanel`'s assignable-template list adds no client-side filtering of its own on top of the already tenant-scoped `/iam/role-templates` response — inventing a second filter risks silently disagreeing with the server's own boundary. Verified by asserting the panel renders **exactly** what the mocked service returns, nothing more and nothing less (§21 scenario 9). A 404 on any detail read renders `ErrorState`, never a blank or partially-populated drawer that could look like a resource which doesn't belong to the actor (§21 scenario 31).

---

## 16. Group B/C Template Reconciliation

Per the task's explicit instruction, this reused Task 1's existing Group A/B/C/D classification (`TASK-IAM-TEMPLATE-RECONCILIATION-001`) — **no new audit of the 40-template catalog was started.**

### Group C — applied (7 templates)

Each fix replaces a stale/nonexistent token with a real one, verified against live enforcement (route middleware, Policy `hasPermissionTo()` calls) or a dedicated permission-seeding migration, with the evidence trail written inline in `RoleTemplateCatalog.php`:

| Template | Stale token(s) | Real replacement | Evidence |
|---|---|---|---|
| `warehouse-clerk` | `operations.preparation.operate` | `.create` + `.update` (delete deliberately withheld) | `routes/api.php` — `operations.preparation.{view,create,update,delete}` used extensively as real middleware |
| `purchasing-officer` | `purchasing.purchases.update` | `.review` + `.select_supplier` | `config/permissions.php` canonical registry (line 62) + an existing legacy `role_permissions` grant holding the exact same pair |
| `dispatcher` | `logistics.dispatch.{view,operate}` | `dispatch.{monitoring.view,audit.view,queue.manage,session.manage}` | `Logistics/Dispatch/…/seed_phase3_permissions.php` — exact string match |
| `driver` | `logistics.deliveries.{view,operate}` | `delivery.{analytics.view,pod.capture,cod.collect,return.manage}`, with 4 exact-key scope entries | `Logistics/Delivery/…/seed_delivery_permissions.php` — exact string match. Scopes: `EffectiveRoleProfile::scopeFor()` does an **exact** resource-key lookup, not a prefix match — a single `'delivery' => 'self'` would have silently left the driver unscoped (defaulting to `'all'`); verified directly against the resolver before writing the 4-entry form |
| `sales-representative` | `crm.leads.create` | `crm.sales.manage` + `crm.sales.convert` (added to the already-correct `crm.sales.view`) | `Crm/Sales/…/seed_crm_sales_permissions_table.php` — the CRM Sales domain has no separate "leads" resource, only `crm.sales.{view,manage,convert}` |
| `cashier` | `pos.sessions.{view,operate}`, `pos.sales.create` | `pos.terminal.{view,operate}` only | `config/permissions.php` — `modules.pos` has exactly one resource (`terminal`); confirmed no POS module migration seeds any permission (all schema-only); confirmed the entire `/pos` route group is gated by the single `pos.terminal.operate` middleware — no finer-grained token exists for any POS sub-resource |
| `ai-analyst` | `bae.view`, `claude_bridge.view`, `engineering.view` | `bae.attribution.view`, `claude_bridge.platform.view`, `engineering.platform.view` | `config/permissions.php` — each module's real `modules.{bae,claude_bridge,engineering}` entry is exactly one `platform`/`attribution` resource with `view`/`manage` |

### Self-correction (disclosed, not buried)

While re-verifying this table's evidence for this report, I found that the `cashier` and `ai-analyst` rows above were **not** what an earlier pass at this same reconciliation (earlier in this task's session, before this commit) had actually written. That earlier pass had:
- Invented `pos.shifts.*`, `pos.carts.*`, `pos.payments.*` for `cashier` — none of these exist anywhere in the codebase (`modules.pos` has only `terminal`; every POS migration is schema-only).
- Invented 11 of 14 tokens for `ai-analyst` (`engineering.{pipelines,tasks,queue,releases,repair,workers,ai_reviews}.view`, `claude_bridge.{settings,tasks,workers}.view`, `bae.{attributions[plural, also a typo],timeline}.view`) and cited "the enterprise permission matrix migration" as evidence — that migration (`2026_12_20_000000_seed_enterprise_permission_matrix.php`) does not touch the `bae`/`claude_bridge`/`engineering` namespaces at all (checked directly: zero matches).

Both were caught by re-grepping every one of my own Group C additions against live enforcement code rather than trusting my own earlier inline comments, corrected to the minimal real token set shown in the table above, and pinned with a dedicated regression test (`test_group_c_previously_fabricated_tokens_are_not_present`, §21) so this specific mistake cannot silently reappear. **No fabricated token was ever committed** — this was caught during this task's own pre-commit verification pass, not after the fact. The other 5 Group C rows (`warehouse-clerk`, `purchasing-officer`, `dispatcher`, `driver`, `sales-representative`) were independently verified the same way and had no such issue.

### Group B — confirmed real, deferred (unchanged)

`hr-officer` (`hr.employees.{view,create,update}`, `hr.attendance.{view,register}`, `hr.leave.view`) and `customer-service-agent` (`crm.service.view`, `crm.tickets.{create,update}`, `omnichannel.inbox.{view,manage}`, `crm.customers.view`) hold only real, already-valid tokens — Group B was never about invalid tokens, it was a capability-**widening** proposal (adding `hr.employees.manage`/`crm.service.manage`-level authority) that Task 1 explicitly flagged as a business decision, not a naming fix. Left **completely unchanged**, per the task's 3-way classification ("add only if approved") — no approval was sought or given in this task.

### Group A — dependency-blocked, deferred (unchanged, no fake permissions)

`warehouse-manager`, `shipping-manager`, `production-director`, `production-manager`, `production-operator`, `quality-inspector`, `packaging-supervisor`, `packaging-operator` — confirmed byte-for-byte unchanged from before this task (diffed against the pre-task file content). No manufacturing/shipping/packing/`logistics.transfers` granular domain was invented; these templates keep their original broad/wildcard grants exactly as Task 1 left them. `RoleTemplateCatalogReconciliationTest::test_group_a_templates_remain_unchanged_with_no_fake_permissions` pins both the exact unchanged permission lists and the absence of a specific list of plausible-but-nonexistent granular tokens across the **whole** catalog, not just Group A's own 8 templates.

### Incidental finding — out of scope, flagged for Task 4

While verifying `sales-representative`'s Group C evidence, found that both `sales-representative` and `sales-manager` hold `sales.customers.*` permissions/scopes — **`sales.customers` does not exist anywhere else in the codebase**; the real resource is `crm.customers` (`config/permissions.php` `modules.crm.customers`). This predates this task entirely (original `TASK-IAM-003` catalog, untouched by Task 1's Group A/B/C/D classification) and was found incidentally, not by re-auditing the catalog. **Not fixed here** — the task's own "no restarting the audit" instruction is read as covering this: it was never part of the Group A/B/C/D set handed to this task, so silently expanding the edit footprint to it, however small the fix looks, would be scope creep on a task explicitly bounded to the pre-classified groups. Documented here for CTO triage / Task 4 instead. Recommended fix, for the record: rename the two `sales.customers` occurrences in `sales-manager`'s scope entry and `sales-representative`'s permissions+scopes to `crm.customers`.

---

## 17. API error-state behavior

401/403 → `PermissionBoundary`/`Can` hide the tab/action entirely, no dead affordance is shown (§21 scenario 30). 404 → `ErrorState`, never a leaked/blank detail (§21 scenario 31). 409 → the server's exact message is surfaced in the lifecycle-confirmation dialog and the dialog stays open, never silently dismissed on failure (§21 scenario 32 — this was an actual bug during initial authoring, caught and fixed before any commit: `onError: onClose` was swallowing the conflict silently; replaced with local error state that renders the real message). 422 → field-level errors from `error.response.data.errors` render next to the matching `FormField`, with the top-level message also shown, not swallowed in favor of only the field errors (§21 scenario 33). A failed impact-preview read disables the (destructive) Apply confirm button — it is never left clickable against unknown/stale impact data (§21 scenario 34).

## 18. Loading / empty / error / loaded state handling

Every list and detail view distinguishes all four states explicitly via the existing `LoadingState`/`EmptyState`/`ErrorState` primitives — `EntityTable`'s own skeleton-row loading state, its `errorState`/`emptyState` props wired to each tab's real query state, and each drawer/dialog switching on `query.isLoading`/`query.isError`/`query.data` before rendering content. A failed list read never silently renders as if it were merely empty (§21 scenario 34's sibling case, also asserted directly for the Roles list, §21).

## 19. Responsive / accessibility

No separate visual system was created for IAM — every screen is built from the existing CRUD kit and shadcn/Radix primitives, which already carry the app's responsive (mobile-card / desktop-table dual layout in `EntityTable`) and accessibility (Radix dialog/select/tabs roles, focus management) behavior. One **pre-existing, out-of-scope** accessibility gap was found while writing tests and is recorded rather than fixed: the shared `FormField` component's `<label for="…">` points at a wrapping `<div>`'s id, not at the actual `<input>`/`<textarea>` element inside it — `getByLabelText` cannot resolve these fields as a result (confirmed directly; not a project-specific IAM issue, `FormField` is used across the whole CRUD kit). Worked around in tests by querying inputs by role/position instead (§21); flagged here rather than silently patched, since fixing a shared, widely-used component is well outside this task's scope.

---

## 20. Backend changes (minimal, evidence-gated, per the task's own explicit allowance)

The only backend file touched is `RoleTemplateCatalog.php` (Group B/C reconciliation, §16) — a pure static-data change (permission-token strings, scope keys, inline evidence comments), no new migration, no schema change, no new service/controller/policy. This is the one exception to "frontend implementation task" the task itself explicitly allowed ("backend changes only if minimal, proven and documented") — every change is a data correction with an evidence citation, not new backend logic.

## 21. Tests written

**YES — 34 named scenarios**, mapped 1:1 across 8 files (7 frontend + 1 backend):

| File | Scenarios covered | Test cases |
|---|---|---|
| `users-tab.test.tsx` | 1 (default-list-excludes-archived), 2 (archived-filter-includes), 4 (valid-lifecycle-actions-shown), 30 (403-handled), 32 (409-surfaced-clearly) | 5 |
| `user-create-drawer.test.tsx` | 3 (create-no-arbitrary-company), 33 (422-field-level) | 4 |
| `user-security-panel.test.tsx` | 5 (password-reset-no-unlock-implication), 6 (archived-reset-unavailable), 10 (session-revoke-flow) | 6 |
| `user-roles-panel.test.tsx` | 7 (role-assignment-invokes-API), 8 (revoke-role-permission-respected), 9 (foreign-resource-not-visible), 16 (authorization-refresh-after-mutation) | 6 |
| `roles-permissions-tab.test.tsx` | 11 (role-list-detail), 12 (custom-role-create-edit-via-templates), 13 (permission-assignment), 14 (protected-system-role-restrictions), 15 (no-generic-permission-token-creation-UI), 31 (404-no-foreign-leak) | 7 |
| `template-detail-drawer.test.tsx` | 17 (system-template-immutable), 18 (custom-template-editable-versioned), 19 (used-template-archive-not-delete), 26 (previous-version-inspectable) | 7 |
| `template-apply-workflow.test.tsx` | 20 (impact-preview-before-apply), 21 (additions-removals-rendered), 22 (affected-holder-count-rendered), 23 (apply-requires-explicit-confirmation), 24 (UI-does-not-expose-unsupported-selected-user-apply), 25 (apply-result-refreshes-data), 34 (failed-read-disables-destructive-CTA) | 11 |
| `RoleTemplateCatalogReconciliationTest.php` (backend) | 27 (eligible-B-C-token-appears-correctly), 28 (stale-invalid-token-removed-only-with-evidence), 29 (Group-A-remains-blocked-no-fake-permission) | 4 (incl. the fabricated-token regression guard, §16) |

**Total: 46 frontend test cases + 4 backend test methods = 50 test cases across 34 named scenarios** (several scenarios have more than one case for thoroughness — e.g. scenario 6 covers both `archived` and `deleted`). Follows the established codebase idiom exactly: real `useQuery`/`useMutation` pipeline with the **service** layer mocked (not the hooks), `@/features/authorization` mocked via a `mockCan`-driven `Can`/`usePermission` factory, and the selector-mode i18n `t` mocked via a path-recording `Proxy` (`goods-inward-mode-card.test.tsx`'s own established pattern). One environment-specific detail discovered and handled: jsdom renders `EntityTable`'s mobile-card and desktop-table branches simultaneously (Tailwind-class-gated visibility, not JS-gated — jsdom evaluates no CSS), so tests assert with `getAllByText`/`.length` where the existing `receiving-center-page.test.tsx` already established that exact idiom.

## 22. Tests executed

**Frontend: YES.** Unlike Task 2 (no PHP/DB toolchain on that device), this device has Node/npm — `npm install` (375 packages) then `npx vitest run` for all 7 new test files plus the full existing suite, and `tsc -b --noEmit` for the whole frontend project. All genuinely run, not merely written.

- All 7 new `iam-admin` test files, run in isolation: **46/46 passing** (the 4 backend `RoleTemplateCatalogReconciliationTest.php` methods are separate — PHPUnit, not executed, see below).
- Full existing frontend suite (396 tests, 49 files, including the 7 new ones — 350 pre-existing + 46 new): **390 passing, 6 failing — all 6 in one pre-existing, unrelated file** (`src/features/inventory-count/components/new-count-dialog.test.tsx`). Confirmed pre-existing and untouched by this task: `git log` shows that file's last change was an unrelated commit (`a7646edf`, "resolve all TypeScript build errors"), and `git status` on its whole directory shows zero changes from this session. Not fixed — out of this task's scope.
- `tsc -b --noEmit` (whole frontend project, project-reference build mode): ran twice — the first pass caught 3 real errors in this task's own test files (2 `mockCan` mock-typing mismatches, 1 unused import), all fixed and re-verified; the second pass exits non-zero but with **zero errors in any `iam-admin` file** — every remaining error (~19, listed in full in §23) is in a file this task never touched, confirmed via `git status` on each affected directory.

**Backend: NO.** Confirmed no PHP binary on this device (`php --version` → not found in both Git Bash and PowerShell), matching Task 2's exact constraint. `RoleTemplateCatalogReconciliationTest.php` was written and manually re-verified line-by-line against the current, post-fix `RoleTemplateCatalog.php` content (every asserted token cross-checked against the file directly), but not executed by PHPUnit.

## 23. Verification state

**Frontend: VERIFIED at the type-check + unit-test level, for this task's own code.** `npm run lint:i18n` reports 0 missing translation keys app-wide; `tsc -b --noEmit` reports zero errors in every `iam-admin` file (the whole-project run exits non-zero, but exclusively on ~19 pre-existing errors in files this task never touched — full list below); all 7 new test files (46 test cases) plus the pre-existing suite pass under `vitest run`, except 6 pre-existing failures in one unrelated file (§22). **Not** browser-verified — the workspace was never opened in an actual browser/dev server (§24 below is explicit about this; it is a real gap, not glossed over).

**Pre-existing whole-project `tsc -b --noEmit` errors, for the record (none touched by this task):** `admin/configuration/pages/{brand-configuration-page,configuration-os-page}.tsx`, `business-accounts/pages/business-accounts-page.tsx`, `engineering/pages/AIEngineeringWorkspacePage.tsx`, `hr/pages/{compensation-explainability-page,exit-management-page,offers-workspace-page}.tsx`, `logistics/dispatch/components/dispatch-conflicts-panel.tsx`, `marketing/automation/pages/{automation-dashboard-page,automation-workspace-page}.tsx`, `marketing/components/connection-status-badge.tsx`, `orders/components/manual-order-form.tsx`, `stock-ledger/components/movement-type-badge.tsx`. All are pre-existing i18n-selector/`StatusVariant` typing gaps unrelated to IAM. Not fixed — out of this task's scope; noted here only so the whole-project non-zero exit code isn't misread as this task's own work being unverified.

**Backend: NOT VERIFIED by execution** (§22) — verified by direct, repeated source grep against live enforcement code instead (§16), which is a materially stronger form of manual review than Task 2's (which relied on reading definitions, not cross-referencing every token against actual route middleware and Policy calls) but is still not equivalent to running the suite.

## 24. Known non-blocking notes (honest, discovered during implementation)

- **`FormField`'s label/input association is broken** (§19) — pre-existing, shared, out of this task's scope to fix.
- **`sales.customers` vs `crm.customers`** (§16) — pre-existing, out of Task 3's authorized scope (not part of the Group A/B/C/D classification), flagged for Task 4/CTO triage rather than silently fixed.
- **The workspace has not been opened in a real browser.** Every claim in §6–§15 is backed by a passing, real-pipeline unit/integration test (§21/§22), not a manual click-through. This is the one honest gap between this report and full UAT-level confidence.
- **`RoleTemplateCatalog.php`'s Group C fix required a genuine self-correction mid-task** (§16) — disclosed in full rather than presented as if the reconciliation had been clean throughout.

## 25. Exact git status (pre-commit)

Immediately before staging: `task/iam-workstream` at HEAD `9f42b37682d97f461209496fab36bb2d2e5d61e6`, 7 modified tracked files, 37 new untracked files (§5), plus this report as a 38th new file — all within `frontend/src/features/iam-admin/`, the 6 integration points, `RoleTemplateCatalog.php`, and the one new backend test file. No unrelated path touched. `origin/develop` unchanged throughout (not touched by this task). Confirmed precisely via `git status --porcelain` immediately before `git add`, not recalled from memory.

## 26. Remaining Task 4 closure items

- Final integration/closure gate across all 3 completed tasks.
- DEV rollout: migrations (Task 2's 3 + none new from this task) still need to be applied on an actual environment; nothing here changes that.
- Real browser verification of the IAM Administration Workspace (§24's honest gap).
- Group B business decision (widen `hr-officer`/`customer-service-agent`, or leave as-is) — needs an actual CTO/business call, not an engineering one.
- The incidental `sales.customers`/`crm.customers` finding (§16) — a small, evidence-backed fix outside this task's authorized scope.
- Backend test execution (`RoleTemplateCatalogReconciliationTest.php` and Task 2's 5 test files) on a device with a working PHP/DB toolchain.

## 27. Recommended Task 4 scope

Per the task's own out-of-scope list, Task 4 is the final closure/integration gate — not new IAM feature work. Suggested boundary: (1) run the full backend test suite (Task 2 + this task's reconciliation test) on the canonical device and fix anything that surfaces; (2) open the workspace in a real browser against a live backend and walk the golden paths for Users/Roles/Templates plus the D12 apply flow; (3) get the Group B business decision from the CTO and either apply it (through the same `RoleTemplateController::update()` path already proven safe in this task) or formally close it as "not now"; (4) decide and apply the `sales.customers`→`crm.customers` fix; (5) apply the 3 pending migrations from Task 2 in the actual target environment. No new UI surface, no new engine, no new compiler — everything needed already exists after Tasks 2 and 3.

---

## 28. Do NOT begin Task 4 automatically

Per this task's own instruction. This report and the commit recorded in §3 are the full extent of this task's work. Task 4 was not started.
