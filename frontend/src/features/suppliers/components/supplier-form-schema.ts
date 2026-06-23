import { z } from 'zod';

import type { Supplier, SupplierPayload } from '@/features/suppliers/types/supplier';

export const supplierSchema = z.object({
  code: z.string().min(1, 'Code is required.').max(50),
  name: z.string().min(1, 'Name is required.').max(255),
  contact_person: z.string().max(255).optional(),
  email: z.union([z.literal(''), z.email('Enter a valid email address.')]).optional(),
  phone: z.string().max(50).optional(),
  mobile: z.string().max(50).optional(),
  country: z.string().max(100).optional(),
  city: z.string().max(100).optional(),
  address: z.string().max(255).optional(),
  notes: z.string().max(1000).optional(),
  is_active: z.boolean(),
});

export type SupplierFormValues = z.infer<typeof supplierSchema>;

/** Build form values from an existing supplier (or empty defaults for create). */
export function toFormValues(supplier?: Supplier | null): SupplierFormValues {
  return {
    code: supplier?.code ?? '',
    name: supplier?.name ?? '',
    contact_person: supplier?.contact_person ?? '',
    email: supplier?.email ?? '',
    phone: supplier?.phone ?? '',
    mobile: supplier?.mobile ?? '',
    country: supplier?.country ?? '',
    city: supplier?.city ?? '',
    address: supplier?.address ?? '',
    notes: supplier?.notes ?? '',
    is_active: supplier?.is_active ?? true,
  };
}

export function toPayload(values: SupplierFormValues): SupplierPayload {
  return { ...values };
}
