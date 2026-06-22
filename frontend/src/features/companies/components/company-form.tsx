import type { ReactNode } from 'react';
import { zodResolver } from '@hookform/resolvers/zod';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

import { Input } from '@/components/ui/input';

const companySchema = z.object({
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

type CompanyFormProps = {
  formId: string;
  defaultValues: CompanyFormValues;
  onSubmit: (values: CompanyFormValues) => void;
};

function Field({ label, error, children }: { label: string; error?: string; children: ReactNode }) {
  return (
    <div className="flex flex-col gap-1.5">
      <label className="text-sm font-medium">{label}</label>
      {children}
      {error ? <p className="text-destructive text-xs">{error}</p> : null}
    </div>
  );
}

/**
 * Reusable company form (React Hook Form + Zod). Shared by the create and edit
 * dialogs. Submits via the `formId` so the dialog footer button can live
 * outside the <form>.
 */
export function CompanyForm({ formId, defaultValues, onSubmit }: CompanyFormProps) {
  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<CompanyFormValues>({
    resolver: zodResolver(companySchema),
    defaultValues,
  });

  return (
    <form id={formId} onSubmit={handleSubmit(onSubmit)} className="flex flex-col gap-4" noValidate>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Code *" error={errors.code?.message}>
          <Input placeholder="ECOS" {...register('code')} />
        </Field>
        <Field label="Name *" error={errors.name?.message}>
          <Input placeholder="ECOS Holding" {...register('name')} />
        </Field>
        <Field label="Legal name" error={errors.legal_name?.message}>
          <Input {...register('legal_name')} />
        </Field>
        <Field label="Tax number" error={errors.tax_number?.message}>
          <Input {...register('tax_number')} />
        </Field>
        <Field label="Commercial registration" error={errors.commercial_registration?.message}>
          <Input {...register('commercial_registration')} />
        </Field>
        <Field label="Email" error={errors.email?.message}>
          <Input type="email" placeholder="info@example.com" {...register('email')} />
        </Field>
        <Field label="Phone" error={errors.phone?.message}>
          <Input {...register('phone')} />
        </Field>
        <Field label="Mobile" error={errors.mobile?.message}>
          <Input {...register('mobile')} />
        </Field>
        <Field label="Website" error={errors.website?.message}>
          <Input placeholder="https://example.com" {...register('website')} />
        </Field>
        <Field label="Currency" error={errors.currency?.message}>
          <Input placeholder="EGP" {...register('currency')} />
        </Field>
        <Field label="Timezone" error={errors.timezone?.message}>
          <Input placeholder="Africa/Cairo" {...register('timezone')} />
        </Field>
        <Field label="Country" error={errors.country?.message}>
          <Input placeholder="Egypt" {...register('country')} />
        </Field>
        <Field label="City" error={errors.city?.message}>
          <Input {...register('city')} />
        </Field>
        <Field label="Postal code" error={errors.postal_code?.message}>
          <Input {...register('postal_code')} />
        </Field>
        <div className="sm:col-span-2">
          <Field label="Address" error={errors.address?.message}>
            <Input {...register('address')} />
          </Field>
        </div>
      </div>

      <label className="flex items-center gap-2 text-sm">
        <input type="checkbox" className="border-input size-4 rounded" {...register('is_active')} />
        Active
      </label>
    </form>
  );
}
