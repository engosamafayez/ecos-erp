import { useFormContext } from 'react-hook-form';
import { useTranslation } from 'react-i18next';

import { FormField } from '@/components/crud';
import { Input } from '@/components/ui/input';
import type { CompanyFormValues } from '@/features/companies/components/company-form-schema';

export function CompanyFormFields() {
  const { t } = useTranslation('companies');
  const { register } = useFormContext<CompanyFormValues>();

  return (
    <div className="flex flex-col gap-4">
      <div className="grid gap-4 sm:grid-cols-2">
        <FormField name="code" label={t('form.code.label')} required>
          <Input placeholder={t('form.code.placeholder')} {...register('code')} />
        </FormField>
        <FormField name="name" label={t('form.name.label')} required>
          <Input placeholder={t('form.name.placeholder')} {...register('name')} />
        </FormField>
        <FormField name="legal_name" label={t('form.legalName')}>
          <Input {...register('legal_name')} />
        </FormField>
        <FormField name="tax_number" label={t('form.taxNumber')}>
          <Input {...register('tax_number')} />
        </FormField>
        <FormField name="commercial_registration" label={t('form.commercialRegistration')}>
          <Input {...register('commercial_registration')} />
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
        <FormField name="website" label={t('form.website.label')}>
          <Input placeholder={t('form.website.placeholder')} {...register('website')} />
        </FormField>
        <FormField name="currency" label={t('form.currency.label')}>
          <Input placeholder={t('form.currency.placeholder')} {...register('currency')} />
        </FormField>
        <FormField name="timezone" label={t('form.timezone.label')}>
          <Input placeholder={t('form.timezone.placeholder')} {...register('timezone')} />
        </FormField>
        <FormField name="country" label={t('form.country.label')}>
          <Input placeholder={t('form.country.placeholder')} {...register('country')} />
        </FormField>
        <FormField name="city" label={t('form.city')}>
          <Input {...register('city')} />
        </FormField>
        <FormField name="postal_code" label={t('form.postalCode')}>
          <Input {...register('postal_code')} />
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
