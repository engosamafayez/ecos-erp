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
  state: z.string().max(100).optional(),
  city: z.string().max(100).optional(),
  district: z.string().max(100).optional(),
  address: z.string().max(255).optional(),
  google_maps_url: z.string().max(1000).optional(),
  // opening_balance_* deliberately NOT part of supplier CRUD — REALIGNMENT-001 §7/§18.
  // Opening balance is a Finance posting (SupplierOpeningBalanceService); writing it here
  // would create a second, unposted balance that double-counts against the ledger.
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
    state: supplier?.state ?? '',
    city: supplier?.city ?? '',
    district: supplier?.district ?? '',
    address: supplier?.address ?? '',
    google_maps_url: supplier?.google_maps_url ?? '',
    notes: supplier?.notes ?? '',
    is_active: supplier?.is_active ?? true,
  };
}

/**
 * The supplier CRUD payload carries profile data only. Opening balance is never sent from
 * here — it is posted through the certified Finance endpoint from Supplier 360.
 */
export function toPayload(values: SupplierFormValues): SupplierPayload {
  return { ...values };
}
