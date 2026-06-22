import { z } from 'zod';

import type { Company, CompanyPayload } from '@/features/companies/types/company';

export const companySchema = z.object({
  code: z.string().min(1, 'Code is required.').max(50),
  name: z.string().min(1, 'Name is required.').max(255),
  legal_name: z.string().max(255).optional(),
  tax_number: z.string().max(100).optional(),
  commercial_registration: z.string().max(100).optional(),
  email: z.union([z.literal(''), z.email('Enter a valid email address.')]).optional(),
  phone: z.string().max(50).optional(),
  mobile: z.string().max(50).optional(),
  website: z.union([z.literal(''), z.url('Enter a valid URL.')]).optional(),
  currency: z.string().max(8).optional(),
  timezone: z.string().max(64).optional(),
  country: z.string().max(100).optional(),
  city: z.string().max(100).optional(),
  address: z.string().max(255).optional(),
  postal_code: z.string().max(32).optional(),
  is_active: z.boolean(),
});

export type CompanyFormValues = z.infer<typeof companySchema>;

/** Build form values from an existing company (or empty defaults for create). */
export function toFormValues(company?: Company | null): CompanyFormValues {
  return {
    code: company?.code ?? '',
    name: company?.name ?? '',
    legal_name: company?.legal_name ?? '',
    tax_number: company?.tax_number ?? '',
    commercial_registration: company?.commercial_registration ?? '',
    email: company?.email ?? '',
    phone: company?.phone ?? '',
    mobile: company?.mobile ?? '',
    website: company?.website ?? '',
    currency: company?.currency ?? '',
    timezone: company?.timezone ?? '',
    country: company?.country ?? '',
    city: company?.city ?? '',
    address: company?.address ?? '',
    postal_code: company?.postal_code ?? '',
    is_active: company?.is_active ?? true,
  };
}

export function toPayload(values: CompanyFormValues): CompanyPayload {
  return { ...values };
}
