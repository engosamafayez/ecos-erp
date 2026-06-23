import { Controller, useFormContext } from 'react-hook-form';

import { FormField } from '@/components/crud';
import { Input } from '@/components/ui/input';
import { ParentCategorySelect } from '@/features/categories/components/parent-category-select';
import type { CategoryFormValues } from '@/features/categories/components/category-form-schema';

type CategoryFormFieldsProps = {
  /** Current category id (edit mode) — excluded from the parent options. */
  currentId?: string;
};

/**
 * Category-specific form fields. Rendered inside an {@link EntityForm}. The
 * Parent field uses a searchable select backed by the Categories API.
 */
export function CategoryFormFields({ currentId }: CategoryFormFieldsProps) {
  const { register, control } = useFormContext<CategoryFormValues>();

  return (
    <div className="flex flex-col gap-4">
      <FormField name="parent_id" label="Parent category">
        <Controller
          control={control}
          name="parent_id"
          render={({ field }) => (
            <ParentCategorySelect
              value={field.value ?? ''}
              onChange={field.onChange}
              excludeId={currentId}
            />
          )}
        />
      </FormField>

      <div className="grid gap-4 sm:grid-cols-2">
        <FormField name="code" label="Code" required>
          <Input placeholder="ELEC" {...register('code')} />
        </FormField>
        <FormField name="name" label="Name" required>
          <Input placeholder="Electronics" {...register('name')} />
        </FormField>
        <FormField name="sort_order" label="Sort order">
          <Input type="number" min={0} {...register('sort_order')} />
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
