import { Controller, useFormContext } from 'react-hook-form';
import { useTranslation } from 'react-i18next';

import { FormField } from '@/components/crud';
import { Input } from '@/components/ui/input';
import { ParentCategorySelect } from '@/features/categories/components/parent-category-select';
import type { CategoryFormValues } from '@/features/categories/components/category-form-schema';

type CategoryFormFieldsProps = {
  currentId?: string;
};

export function CategoryFormFields({ currentId }: CategoryFormFieldsProps) {
  const { t } = useTranslation('categories');
  const { register, control } = useFormContext<CategoryFormValues>();

  return (
    <div className="flex flex-col gap-4">
      <FormField name="parent_id" label={t('form.parentCategory')}>
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
        <FormField name="code" label={t('form.code.label')} required>
          <Input placeholder={t('form.code.placeholder')} {...register('code')} />
        </FormField>
        <FormField name="name" label={t('form.name.label')} required>
          <Input placeholder={t('form.name.placeholder')} {...register('name')} />
        </FormField>
        <FormField name="sort_order" label={t('form.sortOrder')}>
          <Input type="number" min={0} {...register('sort_order')} />
        </FormField>
      </div>

      <FormField name="description" label={t('form.description.label')}>
        <Input placeholder={t('form.description.placeholder')} {...register('description')} />
      </FormField>

      <label className="flex items-center gap-2 text-sm">
        <input type="checkbox" className="border-input size-4 rounded" {...register('is_active')} />
        {t('form.active')}
      </label>
    </div>
  );
}
