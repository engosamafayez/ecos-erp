import { Controller, useFormContext } from 'react-hook-form';

import { FormField } from '@/components/crud';
import { Input } from '@/components/ui/input';
import { CompanySelect } from '@/features/branches/components/company-select';
import { BranchSelect } from '@/features/warehouses/components/branch-select';
import type { WarehouseFormValues } from '@/features/warehouses/components/warehouse-form-schema';

/**
 * Warehouse-specific form fields. Rendered inside an {@link EntityForm}. The
 * Company and Branch fields use searchable selects; choosing a company resets
 * the branch.
 */
export function WarehouseFormFields() {
  const { register, control, setValue, watch } = useFormContext<WarehouseFormValues>();
  const companyId = watch('company_id');

  return (
    <div className="flex flex-col gap-4">
      <div className="grid gap-4 sm:grid-cols-2">
        <FormField name="company_id" label="Company" required>
          <Controller
            control={control}
            name="company_id"
            render={({ field }) => (
              <CompanySelect
                value={field.value || null}
                onChange={(value) => {
                  field.onChange(value);
                  setValue('branch_id', '');
                }}
              />
            )}
          />
        </FormField>
        <FormField name="branch_id" label="Branch" required>
          <Controller
            control={control}
            name="branch_id"
            render={({ field }) => (
              <BranchSelect
                companyId={companyId}
                value={field.value || null}
                onChange={field.onChange}
              />
            )}
          />
        </FormField>
        <FormField name="code" label="Code" required>
          <Input placeholder="WH-MAIN" {...register('code')} />
        </FormField>
        <FormField name="name" label="Name" required>
          <Input placeholder="Main Warehouse" {...register('name')} />
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

      <label className="flex items-center gap-2 text-sm">
        <input type="checkbox" className="border-input size-4 rounded" {...register('is_active')} />
        Active
      </label>
    </div>
  );
}
