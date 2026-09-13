/**
 * IAM Administration Workspace — Users.
 * Mirrors Modules\IAM\Presentation\Http\Controllers\UserController's serialization exactly.
 *
 * TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001, §6-§10: the create/edit workflow and the
 * lifecycle surface both changed on the backend, so the types below grew with them —
 * `lifecycle`, an initial `password`, `role_templates`/`organizations` on create, and the
 * employee/organization directory shapes the new pickers consume.
 */

/** Matches Modules\IAM\Domain\Enums\UserStatus. */
export type UserLifecycleStatus =
  | 'draft'
  | 'invited'
  | 'pending_activation'
  | 'active'
  | 'inactive'
  | 'suspended'
  | 'locked'
  | 'archived'
  | 'deleted';

/**
 * Server-computed lifecycle capability flags (§10) — read from the canonical UserStatus
 * authority so the UI can never offer an action the backend will refuse. This is what makes
 * Activate "explicit and discoverable" and what tells the password panel whether it is
 * SETTING a first credential or RESETTING one.
 */
export type UserLifecycleCapabilities = {
  is_pre_activation: boolean;
  can_activate: boolean;
  can_suspend: boolean;
  can_deactivate: boolean;
  can_lock: boolean;
  can_unlock: boolean;
  can_archive: boolean;
  can_restore: boolean;
  can_set_initial_password: boolean;
  can_reset_password: boolean;
  can_authenticate: boolean;
  requires_password_change: boolean;
  has_credential: boolean;
};

export type UserTemplateAssignment = {
  key: string | null;
  name: string | null;
  /** Arabic business role name (§5), when this template is one of the approved fourteen. */
  name_ar: string | null;
  is_primary: boolean;
  /** Org-unit types §18 expects assigned alongside this role. */
  scope_expectation: string[];
};

export type UserOrganizationAssignment = {
  type: string;
  id: string | null;
  label: string | null;
  is_primary: boolean;
  /** False for the remaining free-form types (department/cost_center — no canonical table). */
  canonical: boolean;
};

export type EmployeeLookupEntry = {
  id: string;
  employee_number: string | null;
  name: string;
  work_email: string | null;
  phone: string | null;
  status: string | null;
  company_id: string | null;
  branch_id: string | null;
  department_id: string | null;
  /** Already linked to a user account — disable selecting it again elsewhere. */
  linked_user_id: number | null;
};

/** Compact per-row role chip for the Users list (User-review remediation, item G). */
export type UserRoleChip = Pick<UserTemplateAssignment, 'key' | 'name' | 'name_ar' | 'is_primary'>;

export type UserSummary = {
  id: number;
  name: string;
  display_name: string;
  email: string;
  username: string | null;
  employee_number: string | null;
  status: UserLifecycleStatus;
  status_label: string;
  company_id: string | null;
  last_login_at: string | null;
  last_activity_at: string | null;
  trashed: boolean;
  lifecycle: UserLifecycleCapabilities;
  roles: UserRoleChip[];
};

export type UserDetail = UserSummary & {
  phone: string | null;
  job_title: string | null;
  employment_type: string | null;
  manager_id: number | null;
  hire_date: string | null;
  templates: UserTemplateAssignment[];
  organizations: UserOrganizationAssignment[];
  employee: EmployeeLookupEntry | null;
  created_at: string | null;
  updated_at: string | null;
  /**
   * Present ONLY in the direct response to a successful create call (User-review
   * remediation, Batch 02, item B) — the plaintext of a server-generated initial password,
   * shown to the creator exactly once. Absent on every other read of this user, always.
   */
  generated_password?: string;
};

/** D2/D3: no company_id field — ownership is always server-derived. */
export type CreateUserPayload = {
  name: string;
  email: string;
  display_name?: string;
  username?: string;
  employee_number?: string;
  phone?: string;
  avatar_path?: string;
  /**
   * Explicit initial credential override — omitted by the standard Create User UI, which
   * relies on the server auto-generating one instead (User-review remediation, Batch 02,
   * item B) and returning it once via `CreateUserResult.generated_password`. Kept optional
   * here only for a caller that legitimately needs to set its own.
   */
  password?: string;
  password_confirmation?: string;
  require_password_change?: boolean;
  /** §8 — role templates to assign at creation, through the canonical assignment service. */
  role_templates?: string[];
  primary_role_template?: string | null;
  /** §9 — organization scope, entity-driven. */
  organizations?: OrganizationScopeAssignmentInput[];
  /** §10 — activate immediately after provisioning, through the canonical transition. */
  activate?: boolean;
};

export type UpdateUserPayload = Partial<
  Pick<CreateUserPayload, 'name' | 'email' | 'display_name' | 'username' | 'employee_number' | 'phone' | 'avatar_path'>
>;

export type PaginationMeta = {
  total: number;
  page: number;
  per_page: number;
};

export type UsersListResult = {
  data: UserSummary[];
  meta: PaginationMeta;
};

export type UsersQuery = {
  q?: string;
  page?: number;
  per_page?: number;
  template?: string;
  org_type?: string;
  org_id?: string;
  /** D8: archived (and deleted) are excluded unless this is explicitly true. */
  include_archived?: boolean;
};

/** The six D4 lifecycle actions plus restore — each maps 1:1 to a UserController endpoint. */
export type LifecycleAction = 'activate' | 'suspend' | 'deactivate' | 'lock' | 'unlock' | 'archive' | 'restore';

export type AssignOrganizationPayload = {
  org_type: string;
  org_id?: string | null;
  label?: string;
  primary?: boolean;
};

/** §9 — one entry of the multi-select organization scope save. */
export type OrganizationScopeAssignmentInput = {
  org_type: string;
  org_id?: string | null;
  label?: string;
  primary?: boolean;
};

/**
 * D5/§10: the backend validates strength via Password::defaults() — the form never
 * re-derives that rule. The same shape serves both an initial-password SET and a
 * RESET; which one it is is a lifecycle-state question the backend already answers
 * (see UserLifecycleCapabilities), not a different payload shape.
 */
export type ResetPasswordPayload = {
  password: string;
  password_confirmation: string;
  require_password_change?: boolean;
};

export type UserSession = {
  id: string;
  ip_address: string | null;
  browser: string | null;
  platform: string | null;
  login_at: string | null;
  last_activity_at: string | null;
};

/**
 * §9 — one level of the canonical organization hierarchy
 * (Company → Brand → Branch → Warehouse → Region → Channel → Team → Business Unit).
 */
export type OrganizationLevel = {
  type: string;
  label_ar: string;
  label_en: string;
  order: number;
  parents: Record<string, string>;
  /** False when this org-unit type has no canonical table in this installation. */
  available: boolean;
  total: number;
  entities: OrganizationEntity[];
};

export type OrganizationEntity = {
  id: string;
  name: string;
  code: string | null;
  /** Parent type → parent entity id, e.g. `{ company: '...' }` for a Brand. */
  parents: Record<string, string>;
};

export type OrganizationDirectoryResult = {
  levels: OrganizationLevel[];
  free_form_types: string[];
};

export type EmployeeDirectoryResult = {
  available: boolean;
  data: EmployeeLookupEntry[];
};

/**
 * CORE-02 Task 1 — Invitation Closure. Mirrors UserController::invitations()'s serialization.
 * Never carries a token — the raw token is returned exactly once, directly from invite()/
 * resendInvitation(), and is never persisted or re-derivable afterward.
 */
export type Invitation = {
  id: string;
  email: string;
  status: 'pending' | 'accepted' | 'expired' | 'revoked';
  expired: boolean;
  expires_at: string | null;
  accepted_at: string | null;
  invited_by: number | null;
  created_at: string | null;
};

export type InvitationIssueResult = {
  invitation_token: string;
  expires_in_hours: number;
};

export type InvitePayload = {
  ttl_hours?: number;
};
