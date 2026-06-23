import { useFormContext } from 'react-hook-form';
import { useTranslation } from 'react-i18next';

import { FormField } from '@/components/crud';
import { Input } from '@/components/ui/input';
import type { SupplierFormValues } from '@/features/suppliers/components/supplier-form-schema';

export function SupplierFormFields() {
  const { t } = useTranslation('suppliers');
  const { register } = useFormContext<SupplierFormValues>();

  return (
    <div className="flex flex-col gap-4">
      <div className="grid gap-4 sm:grid-cols-2">
        <FormField name="code" label={t('form.code.label')} required>
          <Input placeholder={t('form.code.placeholder')} {...register('code')} />
        </FormField>
        <FormField name="name" label={t('form.name.label')} required>
          <Input placeholder={t('form.name.placeholder')} {...register('name')} />
        </FormField>
        <FormField name="contact_person" label={t('form.contactPerson')}>
          <Input {...register('contact_person')} />
        </FormField>
        <FormField name="email" label={t('form.email.label')}>
          <Input type="email" placeholder={t('form.email.placeholder')} {...register('email')} />
        </FormField>
        <FormField name="phone" label={t('form.phone')}>
          <Input {...register('phone')} />
        </FormField>
        <FormField name="mobile" label={t('form.mobile')}>
          <Input {...register('mobile')} />
        </FormField>
        <FormField name="country" label={t('form.country')}>
          <Input {...register('country')} />
        </FormField>
        <FormField name="city" label={t('form.city')}>
          <Input {...register('city')} />
        </FormField>
        <div className="sm:col-span-2">
          <FormField name="address" label={t('form.address')}>
            <Input {...register('address')} />
          </FormField>
        </div>
        <div className="sm:col-span-2">
          <FormField name="notes" label={t('form.notes.label')}>
            <Input placeholder={t('form.notes.placeholder')} {...register('notes')} />
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
