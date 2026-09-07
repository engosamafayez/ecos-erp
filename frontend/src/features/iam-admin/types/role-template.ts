/**
 * IAM Administration Workspace — Role Templates.
 * Mirrors RoleTemplateController.php exactly, including the D12/D13 CTO-ratified contracts.
 */

/** Matches Modules\IAM\Domain\Enums\RoleTemplateStatus. */
export type RoleTemplateStatus = 'draft' | 'published' | 'deprecated' | 'archived';

export type TemplateDefinition = {
  permissions?: string[];
  [key: string]: unknown;
};

export type RoleTemplateSummary = {
  key: string;
  name: string;
  /** Arabic business name (§5/§12) — falls back to `name` outside the approved catalogue. */
  name_ar: string;
  description: string | null;
  description_ar: string | null;
  category: string;
  status: RoleTemplateStatus;
  version: number;
  is_system: boolean;
  is_composable: boolean;
  /** null for system templates (global, per D11) — populated only for custom templates. */
  company_id: string | null;
  compiled: boolean;
};

export type RoleTemplateDetail = RoleTemplateSummary & {
  definition: TemplateDefinition;
  assignment_count: number;
  published_at: string | null;
  created_at: string | null;
  updated_at: string | null;
};

export type TemplateVersionEntry = {
  version: number;
  status: string;
  change_note: string | null;
  created_by: number | null;
  created_at: string | null;
};

export type TemplateDiffListDimension = { added: string[]; removed: string[] };
export type TemplateDiffMapDimension = {
  changed: Record<string, { from: unknown; to: unknown }>;
};

export type RoleTemplateComparison = {
  left: string;
  right: string;
  identical: boolean;
  lists: Record<string, TemplateDiffListDimension>;
  maps: Record<string, TemplateDiffMapDimension>;
};

/** D2/D11: company_id is never a client-supplied field. */
export type CreateRoleTemplatePayload = {
  key: string;
  name: string;
  description?: string;
  category: string;
  is_composable?: boolean;
  definition: TemplateDefinition;
};

export type UpdateRoleTemplatePayload = {
  name?: string;
  description?: string;
  category?: string;
  is_composable?: boolean;
  definition?: TemplateDefinition;
  change_note?: string;
};

export type CloneRoleTemplatePayload = {
  new_key: string;
  new_name?: string;
};

/**
 * D12/§16: the backend-supported Impact Preview fields, verbatim. `affected_holders` is
 * the template-wide holder count — see the workspace's Apply Version UX for why this is
 * necessarily a template-wide number, not a per-holder breakdown.
 */
export type TemplateImpactPreview = {
  template_version: number;
  compiled: boolean;
  affected_holders: number;
  permission_additions: string[];
  permission_removals: string[];
};

export type TemplateApplyResult = {
  role_id: string;
  template_version: number;
  affected_holders: number;
  permission_count: number;
};
