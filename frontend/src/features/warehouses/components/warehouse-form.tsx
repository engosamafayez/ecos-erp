import { Controller, useFormContext } from 'react-hook-form';
import { useTranslation } from 'react-i18next';

import { FormField } from '@/components/crud';
import { Input } from '@/components/ui/input';
import { CompanySelect } from '@/features/branches/components/company-select';
import { BranchSelect } from '@/features/warehouses/components/branch-select';
import type { WarehouseFormValues } from '@/features/warehouses/components/warehouse-form-schema';

export function WarehouseFormFields() {
  const { t } = useTranslation('warehouses');
  const { register, control, setValue, watch } = useFormContext<WarehouseFormValues>();
  const companyId = watch('company_id');

  return (
    <div className="flex flex-col gap-4">
      <div className="grid gap-4 sm:grid-cols-2">
        <FormField name="company_id" label={t('form.company.label')} required>
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
        <FormField name="branch_id" label={t('form.branch.label')} required>
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
        <FormField name="code" label={t('form.code.label')} required>
          <Input placeholder={t('form.code.placeholder')} {...register('code')} />
        </FormField>
        <FormField name="name" label={t('form.name.label')} required>
          <Input placeholder={t('form.name.placeholder')} {...register('name')} />
        </FormField>
        <FormField name="city" label={t('form.city')}>
          <Input {...register('city')} />
        </FormField>
        <FormField name="country" label={t('form.country')}>
          <Input {...register('country')} />
        </FormField>
        <div className="sm:col-span-2">
          <FormField name="address" label={t('form.address')}>
            <Input {...register('address')} />
          </FormField>
        </div>
      </div>

      <label className="flex items-center gap-2 text-sm">
        <input type="checkbox" className="border-input size-4 rounded" {...register('is_active')} />
        {t('form.active')}
      </label>
    </div>
  );
}
