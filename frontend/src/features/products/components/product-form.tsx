import { Controller, useFormContext } from 'react-hook-form';

import { FormField } from '@/components/crud';
import { Input } from '@/components/ui/input';
import { CategorySelect } from '@/features/products/components/category-select';
import { UnitSelect } from '@/features/products/components/unit-select';
import type { ProductFormValues } from '@/features/products/components/product-form-schema';

/**
 * Product-specific form fields. Rendered inside an {@link EntityForm}. Category
 * and Unit use searchable selects from the related modules.
 */
export function ProductFormFields() {
  const { register, control } = useFormContext<ProductFormValues>();

  return (
    <div className="flex flex-col gap-4">
      <div className="grid gap-4 sm:grid-cols-2">
        <FormField name="sku" label="SKU" required>
          <Input placeholder="FG-LAPTOP-XPS" {...register('sku')} />
        </FormField>
        <FormField name="barcode" label="Barcode">
          <Input {...register('barcode')} />
        </FormField>
        <div className="sm:col-span-2">
          <FormField name="name" label="Name" required>
            <Input placeholder="Laptop Dell XPS" {...register('name')} />
          </FormField>
        </div>
        <FormField name="category_id" label="Category" required>
          <Controller
            control={control}
            name="category_id"
            render={({ field }) => (
              <CategorySelect value={field.value || null} onChange={field.onChange} />
            )}
          />
        </FormField>
        <FormField name="unit_id" label="Unit" required>
          <Controller
            control={control}
            name="unit_id"
            render={({ field }) => (
              <UnitSelect value={field.value || null} onChange={field.onChange} />
            )}
          />
        </FormField>
        <FormField name="product_type" label="Type" required>
          <select
            className="border-input h-9 w-full rounded-md border bg-transparent px-3 text-sm shadow-xs"
            {...register('product_type')}
          >
            <option value="finished_good">Finished Good</option>
            <option value="raw_material">Raw Material</option>
          </select>
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
