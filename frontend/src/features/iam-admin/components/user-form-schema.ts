import { z } from 'zod';

import type { CreateUserPayload, UpdateUserPayload, UserDetail } from '@/features/iam-admin/types/user';

/**
 * D2/D3 (TASK-ECOS-IAM-SECURE-ADMIN-API-002, CTO-ratified): deliberately no `company_id`
 * field anywhere in this schema — ownership is always server-derived, never client-chosen,
 * on create or update. This mirrors CreateUserRequest/UpdateUserRequest on the backend
 * exactly; the absence is the contract, not an oversight.
 *
 * TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001, §7/§10: `username` is now a login
 * identifier (shape-validated the same way the backend does — letters/digits/._- , no `@`,
 * so it can never collide in SHAPE with an email address) and the create form carries an
 * OPTIONAL initial password. Server-side strength (Password::defaults()) and cross-field
 * uniqueness are re-checked authoritatively by the backend regardless of what this schema
 * accepts — this is a UX convenience, not the security boundary.
 */
const USERNAME_PATTERN = /^[A-Za-z0-9._-]+$/;

export const userSchema = z
  .object({
    name: z.string().min(1, 'Name is required.').max(255),
    email: z.email('Enter a valid email address.'),
    display_name: z.string().max(255).optional(),
    username: z
      .string()
      .max(255)
      .refine((v) => v === '' || USERNAME_PATTERN.test(v), 'Only letters, numbers, dots, dashes and underscores.')
      .optional(),
    employee_number: z.string().max(255).optional(),
    phone: z.string().max(64).optional(),
    // §10 — optional initial credential. Empty means "keep the previous unusable-random
    // password" behaviour (create) or "leave unchanged" (edit, where this section is hidden).
    password: z.string().max(255).optional(),
    password_confirmation: z.string().max(255).optional(),
    require_password_change: z.boolean().optional(),
  })
  .refine((v) => !v.password || v.password === v.password_confirmation, {
    message: 'Passwords do not match.',
    path: ['password_confirmation'],
  });

export type UserFormValues = z.infer<typeof userSchema>;

export function toFormValues(user?: UserDetail | null): UserFormValues {
  return {
    name: user?.name ?? '',
    email: user?.email ?? '',
    display_name: user?.display_name ?? '',
    username: user?.username ?? '',
    employee_number: user?.employee_number ?? '',
    phone: user?.phone ?? '',
    password: '',
    password_confirmation: '',
    require_password_change: true,
  };
}

/**
 * §6/§8/§9/§10 — the identity form values plus the three provisioning extras Create User
 * now carries in one submission: roles to assign, organization scope, and whether to
 * activate immediately. Kept OUTSIDE the zod schema (they have no per-field validation of
 * their own) and merged in here.
 */
export function toCreatePayload(
  values: UserFormValues,
  extra: {
    roleTemplates: string[];
    primaryRoleTemplate: string | null;
    organizations: CreateUserPayload['organizations'];
    activate: boolean;
  },
): CreateUserPayload {
  const { password, password_confirmation, require_password_change, ...identity } = values;

  return {
    ...identity,
    ...(password ? { password, password_confirmation, require_password_change } : {}),
    role_templates: extra.roleTemplates.length > 0 ? extra.roleTemplates : undefined,
    primary_role_template: extra.primaryRoleTemplate,
    organizations: extra.organizations && extra.organizations.length > 0 ? extra.organizations : undefined,
    activate: extra.activate,
  };
}

export function toUpdatePayload(values: UserFormValues): UpdateUserPayload {
  const { password: _password, password_confirmation: _confirmation, require_password_change: _rpc, ...identity } = values;
  return identity;
}
