/**
 * IAM Administration Workspace — Roles & Permissions (read-only by architecture decision,
 * see RoleController.php's own docblock: roles are managed exclusively through Role
 * Templates, ADR-039/040 Decision 2).
 */

export type RoleTemplateLink = {
  key: string;
  name: string;
  is_system: boolean;
};

export type RoleSummary = {
  id: string;
  slug: string;
  name: string;
  is_system: boolean;
  user_count: number | null;
  template: RoleTemplateLink | null;
};

export type RoleDetail = RoleSummary & {
  permissions: string[];
};

export type PermissionEntry = {
  name: string;
  resource: string;
  action: string;
  description: string | null;
};

export type PermissionGroupEntry = {
  module: string;
  permissions: PermissionEntry[];
};

export type PermissionCatalog = {
  groups: PermissionGroupEntry[];
  total: number;
};
