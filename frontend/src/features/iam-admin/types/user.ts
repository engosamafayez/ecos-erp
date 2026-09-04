/**
 * IAM Administration Workspace — Users (TASK-ECOS-IAM-ADMINISTRATION-WORKSPACE-003).
 * Mirrors TASK-ECOS-IAM-SECURE-ADMIN-API-002's UserController serialization exactly.
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

export type UserTemplateAssignment = {
  key: string | null;
  name: string | null;
  is_primary: boolean;
};

export type UserOrganizationAssignment = {
  type: string;
  id: string | null;
  label: string | null;
  is_primary: boolean;
};

export type UserSummary = {
  id: number;
  name: string;
  display_name: string;
  email: string;
  employee_number: string | null;
  status: UserLifecycleStatus;
  status_label: string;
  company_id: string | null;
  last_login_at: string | null;
  last_activity_at: string | null;
  trashed: boolean;
};

export type UserDetail = UserSummary & {
  username: string | null;
  phone: string | null;
  job_title: string | null;
  employment_type: string | null;
  manager_id: number | null;
  hire_date: string | null;
  templates: UserTemplateAssignment[];
  organizations: UserOrganizationAssignment[];
  created_at: string | null;
  updated_at: string | null;
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
};

export type UpdateUserPayload = Partial<CreateUserPayload>;

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

/** D5: the backend validates strength via Password::defaults() — the form never re-derives that rule. */
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
