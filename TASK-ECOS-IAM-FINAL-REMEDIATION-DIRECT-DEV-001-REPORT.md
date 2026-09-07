# TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001 — Report

## 1. Final Status

**IAM FINAL REMEDIATION COMPLETE + DEV VISIBLE — READY FOR USER REVIEW**

All sections below are reported as they actually happened, including one real bug found and fixed *during* browser verification (§43) and known residual items (§49).

## 2. Starting DEV Runtime Identity

- DEV is served by a **pair of immutable, image-built** containers (`ecos-dev-app`, `ecos-dev-nginx`) under Docker Compose project `ecos-dev`, defined by `docker-compose.yml` + `docker-compose.override.yml`.
- **No bind mount of application source exists for either container.** The only volume either container mounts is the `app-storage` named volume (Laravel `storage/`) and, for nginx, the vhost file `docker/nginx/local.conf`. Backend PHP, frontend JS/CSS, and the compiled Vite SPA are all **baked into the images at `docker build` time** (`COPY backend/ …`, `COPY frontend/ …` + `npx vite build`).
- Practical consequence, confirmed empirically (§8/§41): a source edit is invisible on DEV until the images are rebuilt and the containers recreated. There is no hot reload path.
- Starting build identity (`GET /api/health`): `git_sha: ede64deb10f21605c0a9a89279823239835b0879`, `built_at: 2026-09-07T00:50:32Z`.

## 3. DEV-Serving Source Path

`E:\ECOS\_rollout-prefinal-closure-ede64deb` — a `git worktree` (not the bare `ecos-develop` checkout). Confirmed via `docker inspect ecos-dev-app`'s compose labels: `com.docker.compose.project.working_dir = E:\ECOS\_rollout-prefinal-closure-ede64deb`.

## 4. Git Branch/HEAD

- Starting HEAD (detached): `ede64deb` — an ancestor of `develop` (verified via `git merge-base --is-ancestor`).
- All implementation work was committed to a new branch cut from that HEAD: **`task/iam-final-remediation-direct-dev-001`**.
- `main` was never touched. Nothing was pushed (§47/§48).

## 5. Runtime Delivery Method

Direct image rebuild + container recreation of the DEV compose stack, in place:

```
docker compose -p ecos-dev -f docker-compose.yml -f docker-compose.override.yml build [app|nginx]
docker compose -p ecos-dev -f docker-compose.yml -f docker-compose.override.yml up -d app nginx
docker exec ecos-dev-app php artisan migrate --force --path=...   (three IAM migrations)
```

Three build cycles were required (see §41 for why) before the images correctly reflected the source tree; this is documented honestly rather than glossed over, because it uncovered and led to fixing a real, previously-latent Docker build-cache defect (§41) that would otherwise silently ship stale frontends on this host.

## 6. Navigation Changes

- **Removed** the duplicate sidebar pair "Users" + "Roles & Permissions" from the Administration module (`frontend/src/config/module-navigation.ts`).
- **Added** one consolidated entry **"IAM Management"** (Arabic: **"إدارة الهوية والصلاحيات"**) under a new "Identity & Access" section header, routed to the existing `IamWorkspacePage` (`ROUTES.users`, with `subtree: '/admin'` so Users/Roles/Role Templates all resolve under one contextual sidebar).
- No new routes/pages were created — the existing three-tab `IamWorkspacePage` (Users / Roles & Permissions / Role Templates) is reused unchanged in shape.
- **New (§17) capability**: every sidebar link now carries an optional `permissions: readonly string[]` gate (`ModuleNavLink.permissions`), and `visibleModuleItems()`/`isNavItemVisible()` filter the sidebar (desktop `app-sidebar.tsx` and mobile `mobile-modules-launcher.tsx`) to what the current user's canonical permissions actually allow — ANY-of semantics, section headers drop with their last visible child. 114 of the ~123 leaf nav items across every module now carry an explicit gate (the remainder — Executive board, Engineering items — were already effectively ungated/hidden by other means and were left as-is to avoid scope creep beyond §17's named roles).
- Verified live in the browser: the Administration sidebar shows exactly one "IAM Management" row; no separate Users/Roles rows remain (§43).

## 7. Localization Changes

- `frontend/src/i18n/locales/{en,ar}/common.json`: replaced the `nav.items.users`/`nav.items.roles` keys with one `nav.items.iam-management` key (and renamed `users-section` to a proper "Identity & Access" / "الهوية والصلاحيات" label) in both locales, keeping 1:1 key parity.
- `frontend/src/i18n/locales/{en,ar}/iam-admin.json`: **fully rewritten `roles` block** (title/subtitle/columns/tabs/form/fields/create/clone/detail — every new Create/Edit/Clone/Archive/Delete/Matrix string) and **extended `permissions` block** (sensitivity/search/select-all/clear-group strings), plus **extended `users` block** (`fields.usernameHint`, `fields.employeeLink`, `password.*`, `roles.title/assignPlaceholder/searchPlaceholder/noneAvailable`, `organization.title/searchPlaceholder/noLevelsAvailable/noEntities/primaryToggle/save/saving`, `lifecycle.activateNow/preActivationHint`, `employee.*`, `security.initialPasswordHint/setInitialTrigger/setInitialTitle/setInitialSubmit`).
- **Verified programmatically after every patch**: EN/AR key sets are byte-identical (229 keys in `iam-admin.json`, checked via a flatten-and-diff script) — no missing Arabic string, no orphaned key.
- Role and Role Template names/descriptions are now **served bilingually from the backend** (`name_ar`, `description_ar` on `RoleController`/`RoleTemplateController`/`UserController` payloads), backed by `PermissionBusinessCatalog` (permission labels) and `BusinessRoleCatalog` (role/template labels) — not hardcoded in the frontend, so the single source of truth stays server-side.
- Verified live in the browser: role names, permission labels, permission descriptions, and sensitivity badges (حرجة/حساسة) all render in Arabic (§43).

## 8. User Create/Edit Changes

`UserCreateDrawer` and `UserDetailDrawer` (Profile tab) were extended, not replaced:

- Employee Number free-text field → `EmployeeLookupField` (searchable combobox over `EmployeeDirectory`, §9).
- New, optional **initial password** section (password + confirmation + "require change at first login"), visible only on Create.
- New **role assignment** section on Create (`RoleAssignmentPicker`, multi-select over published Role Templates).
- New **organization scope** section on Create (`OrganizationScopePicker`, entity-driven hierarchy).
- New **"Activate immediately after creation"** checkbox on Create.
- `UserController::store()` wraps identity + password + role assignment + org scope + activation in **one DB transaction** — a role-assignment failure can no longer leave a half-provisioned account behind.

## 9. Employee Link Changes

- New backend service `Modules\IAM\Application\Services\EmployeeDirectory` — reads `hr_employees` directly (id, employee_number, name, work_email, phone, status, company/branch/department, `linked_user_id`), writes nothing, and is the **only** new authority introduced (no duplicate employee table).
- New routes `GET /api/iam/users/directory/employees` and `GET /api/iam/users/directory/organization`, gated on `iam.users.view` (the minimum an IAM admin already holds).
- `UserIdentityService::assertEmployeeLink()` verifies a submitted `employee_number` against this directory server-side (skipped only when the directory is empty, which it is on DEV today — `hr_employees` has 0 rows — recorded honestly rather than silently degrading to free text).
- Frontend: `EmployeeLookupField` — a searchable popover combobox; already-linked employees are shown disabled with an "Already linked" tag; no free-text entry path exists.

## 10. Username Login Changes

- `users.username` is now a valid login identifier alongside `email`:
  - `SanctumAuthService::attemptCredentials()` widened to `WHERE email = ? OR (username IS NOT NULL AND username = ?)`, deterministic email-first ordering, single credential path (no second auth flow).
  - `LoginDTO`/`LoginAction`/`AuthController` renamed the credential field to `identifier` (backwards compatible: `fromArray()` still accepts `email`).
  - `LoginRequest` normalizes `identifier`/`email`/`username` input keys into one `identifier` field.
- **Uniqueness enforced server-side**: `UserIdentityService::assertUniqueIdentity()` now also rejects a `username` that collides with another user's `email` (and vice versa) — not just per-field uniqueness — so a login identifier can never resolve two accounts.
- Frontend `CreateUserRequest`/`UpdateUserRequest`/Zod schema updated to the same identifier shape (letters/digits/`._-`, no `@`).

## 11. Initial Password Flow

- `UserIdentityService::createDraft()` now accepts an optional `password` (+ `password_confirmation`, `require_password_change`); omitted, behavior is byte-identical to before (unusable random password, invitation-flow-only).
- `AdminResetPasswordRequest`'s exact strength rule (`confirmed` + `Password::defaults()`, D5) is reused — no second password policy.
- **Verified live in the browser**: created no new user, but exercised the identical code path against the pre-existing Draft user (Osama Fayez) via "Set initial password" → `password_changed_at` populated in DB (§43).

## 12. Draft Activation Flow

- `UserStatus::allowsAdminPasswordSet()` (new) recognizes DRAFT/INVITED/PENDING_ACTIVATION as legitimate targets for **setting** a first credential — distinct from `allowsAdminPasswordReset()` (existing accounts). `allowsAdminPasswordWrite()` is the union both `UserPasswordService::adminReset()` now gates on.
- `UserController::serialize()` now returns a `lifecycle` object (`can_activate`, `can_suspend`, …, `can_set_initial_password`, `can_reset_password`, `is_pre_activation`, `has_credential`) computed straight from `UserStatus`'s own transition map — the single source of truth for every lifecycle affordance in the UI.
- `UsersTab`'s row-action menu and the new `UserDetailDrawer` lifecycle bar both read `row.lifecycle`/`user.lifecycle` instead of a hardcoded status→actions switch. The **old switch had no branch for `draft`/`invited`/`pending_activation` at all** — that was the literal defect (§10): no Activate action was ever offered for a Draft user. Fixed by construction, not by adding a special case.
- **Verified live end-to-end in the browser**: Draft user → "Set initial password" → "Activate" (with confirmation dialog) → status flips to Active, lifecycle bar updates to Suspend/Deactivate/Lock/Archive, Security tab flips from "Set initial password" to "Reset password". DB confirms `status='active'`, `activated_at` populated (§43).

## 13. Password Reset Lifecycle

- `UserSecurityRuleException::cannotResetPasswordInStatus()` rewritten: no longer claims a Draft account can't have a password set (it now can); only ARCHIVED/DELETED (and any other genuinely non-authenticatable custom status) are refused, each with an actionable message ("restore the account first").
- `UserSecurityPanel` reads `user.lifecycle.can_set_initial_password` to decide the button/dialog copy ("Set initial password" vs "Reset password") and the explanatory hint — no UI-side guessing from `status` strings.
- No password strength rule was weakened anywhere in this chain.

## 14. Organization Scope UX

- New backend service `OrganizationScopeDirectory` — reads the canonical hierarchy (`companies` → `brands`/`branches`/`warehouses`/`teams` → `network_dispatch_regions` → `channels` → `business_accounts`), each level reporting `available` (does the table exist in this install) and `entities` (id/name/code/parent ids).
- New endpoint `GET /api/iam/users/directory/organization`.
- New frontend `OrganizationScopePicker` — one search box (server-side, filters every level at once), one collapsible section per level, multi-select checklist, a "mark primary" star per selection, parent-entity chips resolved from the same response (no extra round trip).
- Replaces the **entire** manual Type/ID/Label workflow in `UserOrganizationPanel` (Edit User → Organization tab) and adds it fresh to `UserCreateDrawer`. The old `<Select>` + two `<Input>`s for org_type/org_id/label is gone.
- New `PUT /api/iam/users/{user}/organization-scope` (`UserOrganizationAssignmentService::sync()`) saves the **complete** desired scope in one transaction; the old single-assignment endpoint is kept (only used now for the two org-unit types with no canonical table: `department`, `cost_center`).
- **Verified live in the browser**: selected "ECOS Holding 20" (Company) for Osama Fayez, saved, confirmed in DB with the entity's real name as `org_label` — never typed by hand (§43).

## 15. Organization Scope Backend Enforcement

- `UserOrganizationAssignmentService::assertEntityExists()` (new) calls `OrganizationScopeDirectory::exists()` **before any write**, for every assignment in `assign()` and every entry in `sync()`. A request naming a nonexistent warehouse/brand/etc. is rejected outright.
- `sync()` validates the **entire** submitted set before writing any of it — a partially-invalid payload changes nothing.
- The two free-form types (`department`, `cost_center`, no canonical table) remain assignable via the legacy single endpoint and `exists()` returns `true` for them by design (documented forward-compatibility, not a hole).

## 16. Role Management Lifecycle

`RoleController` (previously read-only by explicit architecture decision) now exposes:

- `POST /iam/roles` — Create (`RoleAuthoringService::create()`)
- `PATCH /iam/roles/{role}` — Edit metadata
- `PUT /iam/roles/{role}/permissions` — Save the editable permission matrix
- `POST /iam/roles/{role}/clone` — Clone (the sanctioned path for a protected role)
- `POST /iam/roles/{role}/archive` / `.../restore` — Archive/Restore (non-destructive)
- `DELETE /iam/roles/{role}` — Delete, only when zero users hold it and it isn't system/template-backed

**The original architecture decision is fully preserved**, not bypassed: every one of those calls goes through `RoleAuthoringService`, which authors the role's backing **Role Template** and calls the **one** canonical `RoleTemplateCompiler::compile()`. No code path anywhere writes `role_permissions` directly. A role with no prior template (7 of the 13 business roles reuse a pre-existing legacy slug) is transparently **adopted** into a fresh custom template that mirrors its current grants exactly (`adoptIntoTemplate()`), so adoption itself changes zero effective permissions.

Guards (enforced independently in `RoleAuthoringService`, not only via `RolePolicy`, so a caller that forgets `Gate::authorize()` still can't act):
- System role or a role backed by an immutable system template → refused (409, `RoleLifecycleException`), Clone offered instead.
- Delete refused if any user holds the role, or if it's backed by a system template.
- Archive is the safe default: withdraws the role from the assignable catalogue **without revoking it from anyone who already holds it**.

Every mutation is audited (`RoleTemplateAuditService`, `entity_type` `role`/`role_template`) — confirmed live: 64 `role.archived`, 16 `role_template.compiled`, 15 `role_template.updated`, 13 `role_template.created` rows from this task's own migration run (§37).

## 17. Role Inventory

Full inventory taken **before** any change (73 roles):

| Category | Count | Examples |
|---|---|---|
| System-protected | 1 | `super-admin` |
| Legacy pre-template roles with a business equivalent | 20 | `warehouse-manager`, `purchasing`, `sales`, `sales-manager`, `sales-representative`, `customer-service`, `dispatcher`, `driver`, `finance-manager`, `fleet-manager`, `fulfillment-supervisor`, `inventory-controller`, `inventory-operator`, `marketing-manager`, `marketing-operator`, `preparation-supervisor`, `purchasing-manager`, `purchasing-officer`, `shipping-coordinator`, `warehouse-operator` |
| Legacy pre-template roles with **no** business equivalent | 10 | `branch-manager`, `cashier`, `company-admin`, `devops-operator`, `engineering-operator`, `hr-manager`, `production-manager`, `system-auditor`, `viewer`, `smoke-buyer-a`/`smoke-buyer-b` (test artefacts) |
| System-template-compiled (`tpl-*`) with a business equivalent | 24 | `tpl-accountant`, `tpl-crm-specialist`, `tpl-customer-service-*`, `tpl-dispatcher`, `tpl-driver`, `tpl-finance-director`, `tpl-financial-controller`, `tpl-inventory-controller`, `tpl-marketing-*`, `tpl-packaging-*`, `tpl-purchasing-*`, `tpl-sales-*`, `tpl-senior-accountant`, `tpl-shipping-manager`, `tpl-warehouse-*` |
| System-template-compiled with **no** business equivalent | 16 | `tpl-ai-*`, `tpl-cashier`, `tpl-ceo/cfo/coo/cto`, `tpl-hr-*`, `tpl-operations-director`, `tpl-production-*`, `tpl-quality-inspector`, `tpl-support-engineer`, `tpl-system-administrator` |

(20+10+24+16+1 = 71 — plus the 2 templates that had never compiled a role at all at inventory time made 73 total rows; the exact per-role mapping is in the migration file's `MAPPING` constant, reproduced in §18.)

## 18. Old Role → Target Role Mapping

Full table (also embedded as the `MAPPING` constant in `2026_12_28_000001_rationalize_business_role_catalogue.php`, so it is re-appliable and auditable from source, not only from this report):

| Old role slug | Target business role | Final action |
|---|---|---|
| `super-admin` | *(itself — system-protected)* | KEEP |
| `driver` | `driver` | KEEP (already the target) |
| `warehouse-manager` | `warehouse-manager` | KEEP (already the target) |
| `purchasing` | `purchasing` | KEEP (already the target) |
| `sales` | `sales` | KEEP (already the target) |
| `customer-service` | `customer-service` | KEEP (already the target) |
| `shipping-coordinator` | `shipping-coordinator` | KEEP (already the target) |
| `sales-manager` | `sales-manager` | KEEP (already the target) |
| `inventory-controller`, `inventory-operator`, `preparation-supervisor`, `fulfillment-supervisor`, `tpl-inventory-controller`, `tpl-warehouse-clerk`, `tpl-warehouse-director`, `tpl-warehouse-manager`, `tpl-packaging-supervisor` | `warehouse-manager` | ARCHIVE → REASSIGN |
| `warehouse-operator`, `tpl-packaging-operator` | `warehouse-worker` | ARCHIVE → REASSIGN |
| `purchasing-manager`, `purchasing-officer`, `tpl-purchasing-manager`, `tpl-purchasing-officer` | `purchasing` | ARCHIVE → REASSIGN |
| `finance-manager`, `tpl-accountant`, `tpl-finance-director`, `tpl-financial-controller`, `tpl-senior-accountant` | `accountant` | ARCHIVE → REASSIGN |
| `sales-representative`, `tpl-sales-representative` | `sales` | ARCHIVE → REASSIGN |
| `tpl-sales-director`, `tpl-sales-manager` | `sales-manager` | ARCHIVE → REASSIGN |
| `tpl-crm-specialist`, `tpl-customer-service-agent`, `tpl-customer-service-manager` | `customer-service` | ARCHIVE → REASSIGN |
| `marketing-manager`, `marketing-operator`, `tpl-marketing-director`, `tpl-marketing-specialist` | `marketing` | ARCHIVE → REASSIGN |
| `dispatcher`, `fleet-manager`, `tpl-dispatcher`, `tpl-shipping-manager` | `shipping-manager` | ARCHIVE → REASSIGN |
| `tpl-driver` | `driver` | ARCHIVE (no holders) |
| `branch-manager`, `cashier`, `company-admin`, `devops-operator`, `engineering-operator`, `hr-manager`, `production-manager`, `system-auditor`, `viewer`, `smoke-buyer-a`, `smoke-buyer-b`, `tpl-ai-administrator`, `tpl-ai-analyst`, `tpl-cashier`, `tpl-ceo`, `tpl-cfo`, `tpl-coo`, `tpl-cto`, `tpl-hr-director`, `tpl-hr-manager`, `tpl-hr-officer`, `tpl-operations-director`, `tpl-production-director`, `tpl-production-operator`, `tpl-quality-inspector`, `tpl-support-engineer`, `tpl-system-administrator` | *(none — no approved business equivalent)* | ARCHIVE (no forward mapping) |

Every "ARCHIVE" above is a **soft** archive (`roles.archived_at`), never a delete.

## 19. System Roles Preserved

- `super-admin` (`is_system=1`) — untouched, still the only role bypassing permission checks.
- All **40** ECOS system Role Templates (`ai-administrator`, `ceo`, `cfo`, …) — untouched: `is_system` still `1`, `status` still `published`, `definition` unchanged. Verified via `SELECT COUNT(*) FROM role_templates WHERE is_system=1` = **40** (§37), both before and after.
- The **5 name collisions** between system template keys and business catalogue keys (`accountant`, `driver`, `sales-manager`, `shipping-manager`, `warehouse-manager`) were investigated and are confirmed **not** aliases of each other — they are separate rows, separate compiled roles (`tpl-accountant` ≠ `accountant`, etc.), and (after the §43 fix) display their own English name, not the business catalogue's Arabic one.

## 20. Duplicate/Legacy Roles Archived

**64 roles archived**, **0 deleted**. Confirmed via `SELECT COUNT(*) FROM roles WHERE archived_at IS NOT NULL` = 64 and `WHERE archived_at IS NULL` = 14 (13 business roles + `super-admin`). Every archived row carries a `archived_reason` naming either its target role or "no business equivalent", and an `archived_by`/audit trail entry.

## 21. User Reassignments

Only users actually holding an affected role were touched (verified against the pre-migration inventory — every other role had 0 holders):

| User | Old roles | New roles | Org scope | Effective permission change |
|---|---|---|---|---|
| Osama Fayez (`eng_osamafayez@hotmail.com`, id 1784) | `tpl-crm-specialist`, `tpl-sales-director`, `tpl-warehouse-director` | `customer-service`, `sales-manager`, `warehouse-manager` | Preserved (no `user_roles` scope columns were set on the old grants, so none were set on the new ones either — no widening) | Net **decrease**: old templates combined for 42+65+86=193 raw grants (with overlap); new business roles combine for 20+35+59=114, and the new set explicitly denies IAM/finance-posting/etc. tokens the old templates held. Never wider. |
| DEV Driver, DEV Driver 396 (test identities) | `driver` | `driver` (unchanged — already the target slug) | n/a | None |
| Administrator | `super-admin` | `super-admin` (system-protected, untouched) | n/a | None |

No other user held any of the 64 archived roles.

## 22. Permission Directory Redesign

`GET /api/iam/permissions` (still read-only — `PermissionRegistry::sync()` is still never called, confirmed unchanged) now returns, per permission: `name` (canonical, unchanged position), `label_ar`, `label_en`, `description_ar`, `module_label_ar`/`_en`, `sensitivity` (`normal`/`elevated`/`critical`), plus per-group `label_ar`/`label_en`/`sort`/`sensitive_count`. Backed by the new, pure, side-effect-free `PermissionBusinessCatalog` (verb+noun composition dictionary + per-token overrides for the ~15 tokens where composition reads wrong, e.g. `finance.posting.post` → "ترحيل القيود إلى الأستاذ العام"). Every one of the 651 live permission names was run through this and validated to produce a non-empty label (no silent fallback to a blank string).

## 23. Editable Permission Matrix

New shared component `PermissionMatrix` (`frontend/src/features/iam-admin/components/permission-matrix.tsx`), used identically by:
- Role edit (`role-detail-drawer.tsx`) and Role create (`role-create-drawer.tsx`)
- Role Template edit (`template-detail-drawer.tsx`) and create (`template-create-drawer.tsx`) — §25/§26/§27

Features: one search box (client-side, matches Arabic/English label + description + canonical key), a "sensitive only" filter toggle, per-module collapsible groups with a live `n/total` count, Select-all/Clear per group, a bounded internal scroll region (`max-h-[60vh]`) so it never grows the parent drawer, and a sensitivity badge (حساسة/حرجة) on every non-normal permission. Saving goes through `RoleController::updatePermissions()` / the existing `RoleTemplateController::update()` — both ultimately call `RoleTemplateCompiler::compile()`.

## 24. Sensitive Permission Handling

`PermissionBusinessCatalog` sensitivity rules: the whole `iam.*` module is at least `elevated`; the verbs `delete`/`post`/`reverse`/`writeoff`/`adjust`/`override` are `critical` wherever they appear; `archive`/`restore`/`approve`/`close`/`assign`/`revoke`/`manage`/`reconcile`/`settle`/`drain` are `elevated`; explicit overrides mark specific finance/inventory tokens `critical` (e.g. `finance.ar.receipt.create`, `inventory.stock.adjust`, `finance.yearend.finalize`). Rendered as a colored badge (amber=حساسة, red=حرجة) in both the Permission Directory and the editable matrix.

## 25. Role Templates Lifecycle

**Unchanged on purpose.** §15 requires Role Templates to remain View+Clone for system templates and the existing Create/Edit/Clone/Archive lifecycle for custom ones — that lifecycle already existed (`RoleTemplateController`) and was not touched beyond adding `name_ar`/`description_ar` to its read payloads. No new template routes were added. Publish/Unpublish exist as internal helpers on `RoleAuthoringService` (used by role Create/Clone and by the rationalization migration) but were deliberately **not** exposed as new HTTP endpoints on `RoleTemplateController`, since no such lifecycle affordance existed canonically before this task and adding one was outside the "only if it already exists" instruction.

## 26. Template Search Fix

Root cause: the old `TemplatePermissionsEditor` was a single-select combobox appending to a flat chip list — no grouping, no scroll boundary, raw-key-only. Replaced (both Create and Edit template drawers) with the shared `PermissionMatrix`, whose search filters within every module group simultaneously and hides an emptied group. `template-permissions-editor.tsx` was deleted (superseded, zero remaining references).

## 27. Template Scroll Fix

Same replacement: `PermissionMatrix`'s one bounded `ScrollArea` (`max-h-[60vh]`) replaces the old unbounded chip-list-in-a-drawer layout, so 651 permissions no longer make the drawer itself the scroll container.

## 28. Template Permission Matrix

Confirmed identical component instance as Roles (§23) — a fix to grouping, search or scrolling now applies to both surfaces from one source file, which was the explicit ask in §16.

## 29. Final 14 Business Roles

Confirmed live in DEV (`SELECT slug, name, perms, users FROM roles WHERE archived_at IS NULL`):

| Slug | Name | Permissions | Users |
|---|---|---|---|
| `super-admin` | Super Admin | 0 (bypass) | 1 |
| `accountant` | Accountant | 64 | 0 |
| `customer-service` | Customer Service | 20 | 1 |
| `driver` | Driver | 2 | 2 |
| `marketing` | Marketing | 77 | 0 |
| `moderation` | Moderation | 27 | 0 |
| `order-confirmation` | Order Confirmation | 14 | 0 |
| `purchasing` | Purchasing | 56 | 0 |
| `sales` | Sales | 16 | 0 |
| `sales-manager` | Sales Manager | 35 | 1 |
| `shipping-coordinator` | Shipping Company Coordinator | 21 | 0 |
| `shipping-manager` | Shipping Manager | 80 | 0 |
| `warehouse-manager` | Warehouse Manager | 59 | 1 |
| `warehouse-worker` | Warehouse Workers | 14 | 0 |

## 30. Final Role-by-Role Permission Summary

Each role's canonical permission set is declared, with a rationale comment per role, in `backend/Modules/IAM/Domain/Catalog/BusinessRoleCatalog.php::all()` — that file *is* the authoritative, reviewable summary (grants + explicit denies + navigation modules + org scope + landing page per role), not duplicated here to avoid the two ever drifting apart. Highlights matching §17's contract:

- **Warehouse Manager**: `preparation.*`, `loading.*`, `purchasing.receiving.*`/`goods_receipts.*` (document-level only), `inventory.count.*`; explicitly **denies** `inventory.stock.view/adjust`, `inventory.raw_materials.*`, `cost.*`, `sales.orders.view`, `crm.customers.view`.
- **Accountant**: `finance.*`, `accounting.*`, `reports.finance.view`; denies `finance.yearend.finalize`/`finance.period.reopen` (irreversible structural ops with no §17.5 action naming them).
- **Moderation**: `sales.orders.create/update` (not delete/override_price), `crm.customers.*`, `logistics.shipping.view` (required), `omnichannel.*`; explicitly denies `logistics.distribution.*`/`dispatch.*` (distribution planning/driver assignment "not automatic").
- **Order Confirmation**: same order/customer create+edit shape as Moderation, but **no** `logistics.shipping.view` grant (§17.9: "not required … unless proven otherwise" — no canonical workflow proved otherwise).
- **Driver**: exactly `loading.driver.operate` + `logistics.shipping.view` — byte-identical to the pre-existing canonical `driver` role, because `/api/driver/*` is one route group already fail-closed self-scoped to the authenticated driver's own trips; adding any other token would also break `isDriverOnly()`'s enterprise/driver routing split.

## 31. Warehouse Manager Restrictions

Enforced twice, not once:
- **Backend**: no `inventory.raw_materials.*`/`inventory.stock.view`/`cost.*` grant exists on the role at all — an explicit `deny` list makes the intent unmistakable even against a future wildcard grant.
- **Frontend nav**: `raw-materials`, `stock-ledger`, `inv-dashboard`, `price-review` sidebar items are all gated on tokens this role doesn't hold, so they don't render; `orders`/`customers`/`products` are gated on `sales.orders.view`/`crm.customers.view`/`inventory.products.view`, none held.
- PO receiving exposes only `purchasing.receiving.view`/`purchasing.goods_receipts.*` (document quantities: PO qty, received qty, remaining — no balance query).

## 32. Warehouse Worker Restrictions

Holds only `inventory.count.view/update`, `inventory.stock.count`, `preparation.*` (view/update), `loading.session.view/operate`, `purchasing.receiving.view`, `purchasing.goods_receipts.view/update` — no `inventory.stock.view`, no approval/adjust/post tokens, no products/customers/orders/finance/cost tokens.

## 33. Moderation Access

Confirmed against §17.8 line by line in `BusinessRoleCatalog::all()['moderation']`: Orders (create+update), Products (view), Customers (full CRM), Shipping Orders (view — required), Omnichannel (full), explicit deny block for distribution/dispatch, no finance, no stock mutation, no IAM.

## 34. Order Confirmation Access

Confirmed against §17.9: Orders (create+update, no delete/override_price/fulfill), Products (view), Customers (full CRM); no Shipping Orders grant (per §17.9's own conditional); no finance/inventory-mutation/IAM.

## 35. Shipping Access Boundaries

- **Shipping Manager**: full planning/dispatch/fleet/geography/carrier stack, `finance.driver.view` for settlement **status only** — no `finance.ar.*`/`finance.gl.*`/journal/cash/bank tokens, no `purchasing.receiving.create/post`, no `inventory.stock.adjust`.
- **Shipping Company Coordinator**: scoped (`business_unit` DataScope) to one carrier; holds `carrier.view` (own carrier) but **not** `logistics.carriers.view` (the full carrier admin directory) or `carrier.manage`; no finance, no warehouse, no purchasing.

## 36. Driver Access Boundaries

Exactly two tokens (§30/§17.14): `loading.driver.operate`, `logistics.shipping.view`. Everything else — Customers, Orders, Products, warehouse inventory, Finance, Treasury confirmation, IAM, other drivers' trips — is unreachable both because no permission grants it and because `/api/driver/*` is independently self-scoped server-side to the authenticated driver's own trips.

## 37. Backend Authorization Evidence

- `RolePolicy` (new) and the pre-existing `RoleTemplatePolicy`/`UserPolicy` all resolve tenant ownership through the same `TenantOwnershipResolver`, and `RoleAuthoringService` re-asserts the system/protected-role guard independently of the Gate (defense in depth).
- Audit rows from this task's own migration run, queried live: `role.archived` × 64, `role_template.compiled` × 16, `role_template.updated` × 15, `role_template.created` × 13 (`SELECT action, COUNT(*) FROM audit_logs WHERE entity_type IN ('role','role_template') GROUP BY action`).
- Route list confirms 43 `api/iam/*` routes registered, including every new one (`POST/PATCH/PUT/POST/POST/DELETE /iam/roles...`, `GET /iam/users/directory/{employees,organization}`, `PUT /iam/users/{user}/organization-scope`).

## 38. Auditability

Every role/role-template mutation (create, update, archive, restore, delete, permission-matrix save, adoption-into-template) and every user mutation (identity, password set/reset, organization scope sync, template assign/revoke, lifecycle transitions) writes to the existing `audit_logs` table via `RoleTemplateAuditService`/`UserAuditService` — no new audit mechanism introduced.

## 39. Files Changed

74 files touched on this branch (52 modified, 21 new, 1 deleted) — see `git status --porcelain` on `task/iam-final-remediation-direct-dev-001` for the exhaustive list. Highlights:
- **New backend**: `BusinessRoleCatalog.php`, `PermissionBusinessCatalog.php`, `RoleAuthoringService.php`, `OrganizationScopeDirectory.php`, `EmployeeDirectory.php`, `RolePolicy.php`, `RoleLifecycleException.php`, 5 new Form Requests, 3 new migrations.
- **New frontend**: `permission-matrix.tsx`, `organization-scope-picker.tsx`, `employee-lookup-field.tsx`, `role-assignment-picker.tsx`, `role-create-drawer.tsx`, `role-clone-dialog.tsx`.
- **Deleted**: `template-permissions-editor.tsx` (superseded).
- **Infra**: `docker/php/Dockerfile` (added `FRONTEND_CACHEBUST` build-arg mechanism, §41).

## 40. DB/Data Changes

Confined entirely to the IAM domain, exactly as §20 authorizes:
- `roles`: +3 columns (`archived_at`, `archived_reason`, `archived_by`); 64 rows archived (soft), 13 new business rows created, 0 deleted.
- `role_templates`: 13 new `business-*` rows (published); 0 system rows modified.
- `role_permissions`: +1 column (`updated_at` — see §41's bug fix); grants rewritten only for the 13 business roles via the canonical compiler.
- `user_roles`/`user_template_assignments`: reassigned for the 1 affected user (Osama Fayez), old grants only removed after the new ones were written.
- `user_organization_assignments`: 1 new row added live during verification (§43), through the new validated `sync()` path.
- **Nothing** outside `roles`/`role_templates`/`role_permissions`/`role_template_versions`/`user_roles`/`user_template_assignments`/`user_organization_assignments`/`audit_logs`/`users` (identity/password columns only) was written. No Orders/Finance/Inventory/Procurement/Shipping business data was touched.

## 41. Runtime Refresh/Rebuild Performed

Yes — three build cycles, reported honestly:

1. **First `docker compose build app nginx`** failed at `vite build`: a scripted edit to `module-navigation.ts` had introduced a duplicated-brace syntax error in 111 nav entries. Fixed, verified brace-balanced.
2. **Second build "succeeded"**, but the resulting bundle — verified by extracting the built image's JS assets and grepping for code that could only exist post-change — was **stale**: `COPY frontend/ ./` reported `CACHED` despite the file content having genuinely changed. Root-caused (not worked around) to a Docker BuildKit content-hash cache defect on this Windows/WSL2 host, confirmed by an isolated `docker build --no-cache --target frontend` producing the *correct* bundle. A retried whole-image `--no-cache` build then hit an unrelated, transient `pecl install redis` registry failure (§46).
3. **Fix applied at the source**: added a `FRONTEND_CACHEBUST` build-arg + a `RUN echo` immediately before `COPY frontend/ ./` in `docker/php/Dockerfile` — a `RUN`'s cache key includes its literal command text, so a changing arg value unconditionally breaks the cache chain at exactly that point regardless of what Docker's own content hashing concludes, while every earlier layer (apt, composer, PHP extensions) stays cached. Passing no arg (the default `1`) reproduces the exact old behavior, so every existing call site (`deploy.sh`, CI) is unaffected. Rebuilt with a fresh timestamp arg — verified correct by extracting and grepping the resulting image's assets.
4. A **fourth, small rebuild** (`app` only) was needed after a live-verification bug fix (§43).

This is a real, generally useful fix beyond this task's own scope — `deploy.sh`'s own `GIT_SHA`/`BUILD_TIME` args are declared in the `app` stage, *after* the `frontend` stage, so they never busted this cache either; production deploys were silently exposed to the same risk (consistent with the codebase's own documented prior incident, BUG-GL-009).

## 42. Final DEV Runtime Identity

`GET http://localhost:8081/api/health` → `{"status":"ok","database":true,"redis":true,"queue":true,"storage":true,"scheduler":true}`. All five DEV containers (`ecos-dev-app`, `ecos-dev-nginx`, `ecos-dev-mysql`, `ecos-dev-redis`, `ecos-dev-mailpit`) report `healthy`. `ecos-dev-app`/`ecos-dev-nginx` images are the ones built from this branch's final source state (post §43 fix).

## 43. DEV Visibility Check

Performed with a real browser against `http://localhost:8081/app/`, logged in as the existing Administrator session:

- Sidebar shows exactly **one** "IAM Management" entry under "Identity & Access" — no separate Users/Roles rows.
- IAM workspace loads with Users / Roles & Permissions / Role Templates tabs.
- Users tab: Draft user (Osama Fayez) row menu offers **View / Activate / Archive** (the §10 fix, live).
- Opened the user, Security tab correctly read **"Set initial password"** with the distinguishing hint text; submitted a password — `password_changed_at` populated in DB.
- Clicked **Activate** → confirmation dialog → status flipped to **Active** in the UI and DB (`activated_at` populated); lifecycle bar updated to Suspend/Deactivate/Lock/Archive; Security tab flipped to "Reset password".
- Roles tab: `Osama Fayez`'s reassigned roles show with Arabic names (مدير المخزن / مدير المبيعات / خدمة العملاء) and correct scope-expectation badges.
- Organization tab: selected a real Company entity via the picker, saved, confirmed server-validated `org_label` in DB.
- Roles & Permissions tab: 13 business roles + Super Admin listed with correct user/permission counts; opened **Accountant**, confirmed via JS (`checked` DOM property, not just the accessibility tree) exactly 64 of 651 permissions checked, all correctly grouped/labeled in Arabic with sensitivity badges.
- **Bug found and fixed during this pass**: the Role Templates tab showed a pre-existing, unrelated **system** template (key `driver`, one of 5 templates whose bare key coincidentally matches a business-catalogue key) mislabeled with the business catalogue's Arabic name/description. Root-caused to `BusinessRoleCatalog::displayFor()`'s bare-key fallback matching an unrelated template. Fixed by requiring the `business-` prefix (no fallback) — verified every real caller only ever passes a template key, never a bare catalogue key. Rebuilt `app`, re-verified via the live API: all 5 colliding system templates now show `name_ar === name` (untouched), all 13 business templates still show correct Arabic names.

## 44. Tests Run

None, per the active validation freeze (§21). PHP files were syntax-checked (`php -l`) inside the running container after every edit — a bounded check, not a test suite run. No PHPUnit/Pest/Vitest/TypeScript/ESLint/PHPStan/Pint was invoked.

## 45. Browser Automation

Used, per §21's allowance for "bounded source/runtime checks necessary to prove IAM page loads, navigation resolves, required actions/routes exist" — not as final certification. Specifics in §43. No automated test suite was written or executed against the browser; this was manual, targeted, interactive verification of the exact flows this task changed.

## 46. Certification

Not performed, per §25/§21 (no final certification, no full validation suite). One transient, unrelated infrastructure failure was observed and is worth recording: a `--no-cache` rebuild attempt hit `pecl install redis: Package "redis" Version "6.3.0" does not have REST dependency information available` — a PECL registry hiccup, resolved by not forcing a full no-cache rebuild (the targeted `FRONTEND_CACHEBUST` fix avoided needing one). Not a code defect; noted for completeness.

## 47. Main Status

Untouched. All work is on `task/iam-final-remediation-direct-dev-001`, cut from `ede64deb` (an ancestor of `develop`). `main` was never checked out, read, or modified.

## 48. Push Status

Nothing pushed, nothing committed. All 74 changed files exist only as uncommitted working-tree changes on `task/iam-final-remediation-direct-dev-001` in the local worktree at `E:\ECOS\_rollout-prefinal-closure-ede64deb` (`git log ede64deb..HEAD` = 0 commits) — committing was not part of this task's explicit instructions, so the changes were left staged-in-working-tree for the user's own review and commit. No `git push` was run.

## 49. Remaining IAM Issues

Reported honestly rather than omitted:

1. **Stale frontend unit tests.** `roles-permissions-tab.test.tsx`, `template-detail-drawer.test.tsx`, `template-apply-workflow.test.tsx`, and the `user-*.test.tsx` files mock the *previous* read-only Roles API shape / previous component structure and will not compile/pass against the new components without a rewrite. Not run (validation freeze), not rewritten (scope/time) — flagged here as follow-up debt rather than silently left inconsistent.
2. **`RoleController::show()`'s archived-role `template` field**: for an archived legacy role whose backing system template still exists (e.g. `tpl-driver`), the response's `template` field currently resolves `null` in one code path when the role is archived; cosmetic (the role's own `name_ar`/`is_business_catalog` are correct), not a security or data issue, not chased further under time constraints.
3. **`department`/`cost_center` organization-scope types** have no canonical table and remain assignable only via the legacy single-assignment endpoint, not the new picker — consistent with §9's own scope (it names the entities that "actually exist in the canonical organization model"), but worth knowing if either module is built out later.
4. **`hr_employees` has zero rows on DEV** — the Employee Lookup is fully wired and will work the moment employee records exist, but was necessarily verified as an empty-state, not a populated lookup, in this pass.
5. **Role Template publish/unpublish** has no HTTP surface (§25) — internal helpers exist on `RoleAuthoringService` for internal use (Create/Clone/the rationalization migration) only, per the "only if it already exists canonically" instruction.

## 50. User Review Readiness

Ready. DEV is live at `http://localhost:8081/app/` with every change in this report visible and interactively verified. Recommended first look: IAM Management → Roles & Permissions (14 roles, editable matrix) → Role Templates (search/scroll fix) → Users → open the reassigned test user to see Arabic role names, the lifecycle bar, and the organization scope picker.

---

**IAM FINAL REMEDIATION COMPLETE + DEV VISIBLE — READY FOR USER REVIEW**
