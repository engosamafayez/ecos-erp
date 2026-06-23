import { useFormContext } from 'react-hook-form';

import { FormField } from '@/components/crud';
import { Input } from '@/components/ui/input';
import type { UnitFormValues } from '@/features/units/components/unit-form-schema';

/**
 * Unit-specific form fields. Rendered inside an {@link EntityForm}.
 */
export function UnitFormFields() {
  const { register } = useFormContext<UnitFormValues>();

  return (
    <div className="flex flex-col gap-4">
      <div className="grid gap-4 sm:grid-cols-2">
        <FormField name="code" label="Code" required>
          <Input placeholder="PCS" {...register('code')} />
        </FormField>
        <FormField name="name" label="Name" required>
          <Input placeholder="Pieces" {...register('name')} />
        </FormField>
        <FormField name="symbol" label="Symbol">
          <Input placeholder="pcs" {...register('symbol')} />
        </FormField>
      </div>

      <FormField name="description" label="Description">
        <Input {...register('description')} />
      </FormField>

      <label className="flex items-center gap-2 text-sm">
        <input type="checkbox" className="border-input size-4 rounded" {...register('is_active')} />
        Active
      </label>
    </div>
  );
}
