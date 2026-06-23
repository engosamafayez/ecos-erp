import { z } from 'zod';

import type { Warehouse, WarehousePayload } from '@/features/warehouses/types/warehouse';

export const warehouseSchema = z.object({
  company_id: z.string().min(1, 'Company is required.'),
  branch_id: z.string().min(1, 'Branch is required.'),
  code: z.string().min(1, 'Code is required.').max(50),
  name: z.string().min(1, 'Name is required.').max(255),
  address: z.string().max(255).optional(),
  city: z.string().max(100).optional(),
  country: z.string().max(100).optional(),
  is_active: z.boolean(),
});

export type WarehouseFormValues = z.infer<typeof warehouseSchema>;

/** Build form values from an existing warehouse (or empty defaults for create). */
export function toFormValues(warehouse?: Warehouse | null): WarehouseFormValues {
  return {
    company_id: warehouse?.company_id ?? '',
    branch_id: warehouse?.branch_id ?? '',
    code: warehouse?.code ?? '',
    name: warehouse?.name ?? '',
    address: warehouse?.address ?? '',
    city: warehouse?.city ?? '',
    country: warehouse?.country ?? '',
    is_active: warehouse?.is_active ?? true,
  };
}

export function toPayload(values: WarehouseFormValues): WarehousePayload {
  return { ...values };
}
