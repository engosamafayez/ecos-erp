import { Controller, useFormContext } from 'react-hook-form';
import { useTranslation } from 'react-i18next';

import { FormField } from '@/components/crud';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { CategorySelect } from '@/features/products/components/category-select';
import { UnitSelect } from '@/features/products/components/unit-select';
import type { ProductFormValues } from '@/features/products/components/product-form-schema';

export function ProductFormFields() {
  const { t } = useTranslation('products');
  const { register, control } = useFormContext<ProductFormValues>();

  return (
    <div className="flex flex-col gap-4">
      <div className="grid gap-4 sm:grid-cols-2">
        <FormField name="sku" label={t('form.sku.label')} required>
          <Input placeholder={t('form.sku.placeholder')} {...register('sku')} />
        </FormField>
        <FormField name="barcode" label={t('form.barcode')}>
          <Input {...register('barcode')} />
        </FormField>
        <div className="sm:col-span-2">
          <FormField name="name" label={t('form.name.label')} required>
            <Input placeholder={t('form.name.placeholder')} {...register('name')} />
          </FormField>
        </div>
        <FormField name="category_id" label={t('form.category.label')} required>
          <Controller
            control={control}
            name="category_id"
            render={({ field }) => (
              <CategorySelect value={field.value || null} onChange={field.onChange} />
            )}
          />
        </FormField>
        <FormField name="unit_id" label={t('form.unit.label')} required>
          <Controller
            control={control}
            name="unit_id"
            render={({ field }) => (
              <UnitSelect value={field.value || null} onChange={field.onChange} />
            )}
          />
        </FormField>
        <FormField name="product_type" label={t('form.type.label')} required>
          <select
            className="border-input h-9 w-full rounded-md border bg-transparent px-3 text-sm shadow-xs"
            {...register('product_type')}
          >
            <option value="finished_good">{t('types.finished_good')}</option>
            <option value="raw_material">{t('types.raw_material')}</option>
          </select>
        </FormField>
        <FormField name="stock_status" label={t('form.stockStatus.label')}>
          <select
            className="border-input h-9 w-full rounded-md border bg-transparent px-3 text-sm shadow-xs"
            {...register('stock_status')}
          >
            <option value="">{t('form.stockStatus.placeholder')}</option>
            <option value="instock">{t('stockStatus.instock')}</option>
            <option value="outofstock">{t('stockStatus.outofstock')}</option>
            <option value="onbackorder">{t('stockStatus.onbackorder')}</option>
          </select>
        </FormField>
        <FormField name="regular_price" label={t('form.regularPrice.label')}>
          <Input type="number" step="0.01" min="0" {...register('regular_price', { setValueAs: (v: string) => v === '' || v == null ? null : Number(v) })} />
        </FormField>
        <FormField name="sale_price" label={t('form.salePrice.label')}>
          <Input type="number" step="0.01" min="0" {...register('sale_price', { setValueAs: (v: string) => v === '' || v == null ? null : Number(v) })} />
        </FormField>
        <div className="sm:col-span-2">
          <FormField name="image_url" label={t('form.imageUrl.label')}>
            <Input placeholder={t('form.imageUrl.placeholder')} {...register('image_url')} />
          </FormField>
        </div>
      </div>

      <FormField name="description" label={t('form.description.label')}>
        <Input placeholder={t('form.description.placeholder')} {...register('description')} />
      </FormField>

      <FormField name="short_description" label={t('form.shortDescription.label')}>
        <Textarea
          placeholder={t('form.shortDescription.placeholder')}
          rows={2}
          {...register('short_description')}
        />
      </FormField>

      <FormField name="long_description" label={t('form.longDescription.label')}>
        <Textarea
          placeholder={t('form.longDescription.placeholder')}
          rows={4}
          {...register('long_description')}
        />
      </FormField>

      <label className="flex items-center gap-2 text-sm">
        <input type="checkbox" className="border-input size-4 rounded" {...register('is_active')} />
        {t('form.active')}
      </label>
    </div>
  );
}
