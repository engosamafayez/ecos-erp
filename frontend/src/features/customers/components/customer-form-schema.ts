import { z } from 'zod';

import type { Customer, CustomerPayload } from '@/features/customers/types/customer';

export const customerSchema = z.object({
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

export type CustomerFormValues = z.infer<typeof customerSchema>;

export function toFormValues(customer?: Customer | null): CustomerFormValues {
  return {
    code: customer?.code ?? '',
    name: customer?.name ?? '',
    contact_person: customer?.contact_person ?? '',
    email: customer?.email ?? '',
    phone: customer?.phone ?? '',
    mobile: customer?.mobile ?? '',
    country: customer?.country ?? '',
    city: customer?.city ?? '',
    address: customer?.address ?? '',
    notes: customer?.notes ?? '',
    is_active: customer?.is_active ?? true,
  };
}

export function toPayload(values: CustomerFormValues): CustomerPayload {
  return { ...values };
}
