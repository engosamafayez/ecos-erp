import { Controller, useFormContext } from 'react-hook-form';

import { FormField } from '@/components/crud';
import { Input } from '@/components/ui/input';
import { CompanySelect } from '@/features/branches/components/company-select';
import type { BranchFormValues } from '@/features/branches/components/branch-form-schema';

/**
 * Branch-specific form fields. Rendered inside an {@link EntityForm}. The
 * Company field uses a searchable select backed by the Companies API.
 */
export function BranchFormFields() {
  const { register, control } = useFormContext<BranchFormValues>();

  return (
    <div className="flex flex-col gap-4">
      <FormField name="company_id" label="Company" required>
        <Controller
          control={control}
          name="company_id"
          render={({ field }) => (
            <CompanySelect value={field.value || null} onChange={field.onChange} />
          )}
        />
      </FormField>

      <div className="grid gap-4 sm:grid-cols-2">
        <FormField name="code" label="Code" required>
          <Input placeholder="CAI-HQ" {...register('code')} />
        </FormField>
        <FormField name="name" label="Branch name" required>
          <Input placeholder="Cairo HQ" {...register('name')} />
        </FormField>
        <FormField name="manager_name" label="Manager">
          <Input {...register('manager_name')} />
        </FormField>
        <FormField name="phone" label="Phone">
          <Input {...register('phone')} />
        </FormField>
        <FormField name="email" label="Email">
          <Input type="email" placeholder="branch@example.com" {...register('email')} />
        </FormField>
        <FormField name="city" label="City">
          <Input {...register('city')} />
        </FormField>
        <FormField name="country" label="Country">
          <Input placeholder="Egypt" {...register('country')} />
        </FormField>
        <div className="sm:col-span-2">
          <FormField name="address" label="Address">
            <Input {...register('address')} />
          </FormField>
        </div>
      </div>

      <div className="flex flex-col gap-2">
        <label className="flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            className="border-input size-4 rounded"
            {...register('is_head_office')}
          />
          Head office
        </label>
        <label className="flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            className="border-input size-4 rounded"
            {...register('is_active')}
          />
          Active
        </label>
      </div>
    </div>
  );
}
