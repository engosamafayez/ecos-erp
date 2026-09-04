import { z } from 'zod';

import type { CreateUserPayload, UpdateUserPayload, UserDetail } from '@/features/iam-admin/types/user';

/**
 * D2/D3 (TASK-ECOS-IAM-SECURE-ADMIN-API-002, CTO-ratified): deliberately no `company_id`
 * field anywhere in this schema — ownership is always server-derived, never client-chosen,
 * on create or update. This mirrors CreateUserRequest/UpdateUserRequest on the backend
 * exactly; the absence is the contract, not an oversight.
 */
export const userSchema = z.object({
  name: z.string().min(1, 'Name is required.').max(255),
  email: z.email('Enter a valid email address.'),
  display_name: z.string().max(255).optional(),
  username: z.string().max(255).optional(),
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

export function toCreatePayload(values: UserFormValues): CreateUserPayload {
  return { ...values };
}

export function toUpdatePayload(values: UserFormValues): UpdateUserPayload {
  return { ...values };
}
