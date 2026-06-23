import { useFormContext } from 'react-hook-form';

import { FormField } from '@/components/crud';
import { Input } from '@/components/ui/input';
import type { SupplierFormValues } from '@/features/suppliers/components/supplier-form-schema';

/**
 * Supplier-specific form fields. Rendered inside an {@link EntityForm}.
 */
export function SupplierFormFields() {
  const { register } = useFormContext<SupplierFormValues>();

  return (
    <div className="flex flex-col gap-4">
      <div className="grid gap-4 sm:grid-cols-2">
        <FormField name="code" label="Code" required>
          <Input placeholder="SUP-001" {...register('code')} />
        </FormField>
        <FormField name="name" label="Name" required>
          <Input placeholder="Delta Trading" {...register('name')} />
        </FormField>
        <FormField name="contact_person" label="Contact person">
          <Input {...register('contact_person')} />
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
        <FormField name="country" label="Country">
          <Input placeholder="Egypt" {...register('country')} />
        </FormField>
        <FormField name="city" label="City">
          <Input {...register('city')} />
        </FormField>
        <div className="sm:col-span-2">
          <FormField name="address" label="Address">
            <Input {...register('address')} />
          </FormField>
        </div>
        <div className="sm:col-span-2">
          <FormField name="notes" label="Notes">
            <Input {...register('notes')} />
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
