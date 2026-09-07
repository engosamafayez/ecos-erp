import { z } from 'zod';

import type { CreateUserPayload, UpdateUserPayload, UserDetail } from '@/features/iam-admin/types/user';

/**
 * D2/D3 (TASK-ECOS-IAM-SECURE-ADMIN-API-002, CTO-ratified): deliberately no `company_id`
 * field anywhere in this schema — ownership is always server-derived, never client-chosen,
 * on create or update. This mirrors CreateUserRequest/UpdateUserRequest on the backend
 * exactly; the absence is the contract, not an oversight.
 *
 * TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001, §7: `username` is now a login identifier
 * (shape-validated the same way the backend does — letters/digits/._- , no `@`, so it can
 * never collide in SHAPE with an email address).
 *
 * User-review remediation (Batch 02, item B): this schema no longer carries password fields
 * at all. Create User no longer asks an administrator to invent an initial credential — one
 * is generated securely server-side and returned once (see user-create-drawer.tsx) — and
 * Edit never touched a password through this form to begin with (toUpdatePayload already
 * discarded them). "Reset password" for an existing account remains its own, separate
 * surface (user-security-panel.tsx / UserPasswordService::adminReset()), untouched by this.
 */
const USERNAME_PATTERN = /^[A-Za-z0-9._-]+$/;

export const userSchema = z.object({
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
  return {
    ...values,
    role_templates: extra.roleTemplates.length > 0 ? extra.roleTemplates : undefined,
    primary_role_template: extra.primaryRoleTemplate,
    organizations: extra.organizations && extra.organizations.length > 0 ? extra.organizations : undefined,
    activate: extra.activate,
  };
}

export function toUpdatePayload(values: UserFormValues): UpdateUserPayload {
  return values;
}
