/**
 * IAM Management — Roles & Permissions
 * (TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001, §11 + §13 + §14).
 *
 * Roles are now WRITABLE. The original architecture decision they were read-only for
 * (ADR-039/040 Decision 2 — grants are authored through Role Templates, never by direct
 * `role_permissions` writes) is unchanged and still enforced: every write below is served
 * by RoleAuthoringService, which authors the backing template and calls the one canonical
 * compiler. See RoleController.php's docblock.
 */

export type RoleTemplateLink = {
  key: string;
  name: string;
  is_system: boolean;
  status: string;
};

export type RoleSummary = {
  id: string;
  slug: string;
  name: string;
  /** Arabic business name (§5). Falls back to `name` for an admin-authored custom role. */
  name_ar: string;
  description: string | null;
  description_ar: string | null;
  is_system: boolean;
  /** True when this role is one of the fourteen approved business roles (§12). */
  is_business_catalog: boolean;
  archived: boolean;
  archived_at: string | null;
  archived_reason: string | null;
  user_count: number | null;
  /**
   * False for a system role and for a role compiled from an immutable ECOS system
   * template — those are View + Clone only (§11/§15). Server-computed, so the UI cannot
   * offer a save the backend will refuse.
   */
  editable: boolean;
  /** Org-unit types §18 expects an administrator to assign for a holder of this role. */
  scope_expectation: string[];
  template: RoleTemplateLink | null;
};

export type RoleAssignedUser = {
  id: number;
  name: string;
  email: string;
  status: string;
};

export type RoleDetail = RoleSummary & {
  /** Canonical permission keys — the value the permission matrix edits. */
  permissions: string[];
  /** The same set with Arabic business labels, for read-only display. */
  permission_details: PermissionEntry[];
  definition: Record<string, unknown> | null;
  /** §11/§19 — who would be affected by archiving or deleting this role. */
  assigned_users: RoleAssignedUser[];
};

export type RoleListResult = {
  data: RoleSummary[];
  meta: {
    total: number;
    business_catalog: string[];
    categories: { value: string; label: string }[];
  };
};

/** §14 — the three bands the matrix renders differently. */
export type PermissionSensitivity = 'normal' | 'elevated' | 'critical';

/**
 * One permission, described in business language (§13).
 *
 * `name` is the CANONICAL key and is always present — §13 keeps it as secondary technical
 * detail rather than hiding it. `label_ar` / `description_ar` are what the admin reads
 * first.
 */
export type PermissionEntry = {
  name: string;
  module: string;
  module_label_ar: string;
  module_label_en: string;
  resource: string;
  action: string;
  label_ar: string;
  label_en: string;
  description_ar: string;
  sensitivity: PermissionSensitivity;
  /** The original English catalogue description, preserved from the previous contract. */
  description?: string | null;
};

export type PermissionGroupEntry = {
  module: string;
  label_ar: string;
  label_en: string;
  sort: number;
  count: number;
  sensitive_count: number;
  permissions: PermissionEntry[];
};

export type PermissionCatalog = {
  groups: PermissionGroupEntry[];
  total: number;
  sensitivity_levels: PermissionSensitivity[];
};

export type CreateRolePayload = {
  name: string;
  description?: string | null;
  category?: string | null;
  permissions?: string[];
};

export type UpdateRolePayload = {
  name?: string;
  description?: string | null;
  category?: string | null;
};
