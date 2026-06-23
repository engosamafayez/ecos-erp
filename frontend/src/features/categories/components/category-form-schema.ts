import { z } from 'zod';

import type { Category, CategoryPayload } from '@/features/categories/types/category';

export const categorySchema = z.object({
  parent_id: z.string().optional(),
  code: z.string().min(1, 'Code is required.').max(50),
  name: z.string().min(1, 'Name is required.').max(255),
  description: z.string().max(500).optional(),
  sort_order: z.string().regex(/^\d*$/, 'Sort order must be a positive number.').optional(),
  is_active: z.boolean(),
});

export type CategoryFormValues = z.infer<typeof categorySchema>;

/** Build form values from an existing category (or empty defaults for create). */
export function toFormValues(category?: Category | null): CategoryFormValues {
  return {
    parent_id: category?.parent_id ?? '',
    code: category?.code ?? '',
    name: category?.name ?? '',
    description: category?.description ?? '',
    sort_order: String(category?.sort_order ?? 0),
    is_active: category?.is_active ?? true,
  };
}

export function toPayload(values: CategoryFormValues): CategoryPayload {
  return {
    parent_id: values.parent_id,
    code: values.code,
    name: values.name,
    description: values.description,
    sort_order: values.sort_order ? Number(values.sort_order) : 0,
    is_active: values.is_active,
  };
}
