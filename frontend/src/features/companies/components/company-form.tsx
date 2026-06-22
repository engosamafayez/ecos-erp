import { useFormContext } from 'react-hook-form';

import { FormField } from '@/components/crud';
import { Input } from '@/components/ui/input';
import type { CompanyFormValues } from '@/features/companies/components/company-form-schema';

/**
 * Company-specific form fields. Rendered inside an {@link EntityForm} (which
 * provides the React Hook Form context); only the field layout is company-aware.
 */
export function CompanyFormFields() {
  const { register } = useFormContext<CompanyFormValues>();

  return (
    <div className="flex flex-col gap-4">
      <div className="grid gap-4 sm:grid-cols-2">
        <FormField name="code" label="Code" required>
          <Input placeholder="ECOS" {...register('code')} />
        </FormField>
        <FormField name="name" label="Name" required>
          <Input placeholder="ECOS Holding" {...register('name')} />
        </FormField>
        <FormField name="legal_name" label="Legal name">
          <Input {...register('legal_name')} />
        </FormField>
        <FormField name="tax_number" label="Tax number">
          <Input {...register('tax_number')} />
        </FormField>
        <FormField name="commercial_registration" label="Commercial registration">
          <Input {...register('commercial_registration')} />
        </FormField>
        <FormField name="email" label="Email">
          <Input type="email" placeholder="info@example.com" {...register('email')} />
        </FormField>
        <FormField name="phone" label="Phone">
          <Input {...register('phone')} />
        </FormField>
        <FormField name="mobile" label="Mobile">
          <Input {...register('mobile')} />
        </FormField>
        <FormField name="website" label="Website">
          <Input placeholder="https://example.com" {...register('website')} />
        </FormField>
        <FormField name="currency" label="Currency">
          <Input placeholder="EGP" {...register('currency')} />
        </FormField>
        <FormField name="timezone" label="Timezone">
          <Input placeholder="Africa/Cairo" {...register('timezone')} />
        </FormField>
        <FormField name="country" label="Country">
          <Input placeholder="Egypt" {...register('country')} />
        </FormField>
        <FormField name="city" label="City">
          <Input {...register('city')} />
        </FormField>
        <FormField name="postal_code" label="Postal code">
          <Input {...register('postal_code')} />
        </FormField>
        <div className="sm:col-span-2">
          <FormField name="address" label="Address">
            <Input {...register('address')} />
          </FormField>
        </div>
      </div>

      <label className="flex items-center gap-2 text-sm">
        <input type="checkbox" className="border-input size-4 rounded" {...register('is_active')} />
        Active
      </label>
    </div>
  );
}
