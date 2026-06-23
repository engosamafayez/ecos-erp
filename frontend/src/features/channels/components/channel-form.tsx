import { useFormContext } from 'react-hook-form';
import { useWatch } from 'react-hook-form';
import { useTranslation } from 'react-i18next';

import { Combobox } from '@/components/crud/combobox';
import { FormField } from '@/components/crud';
import { Input } from '@/components/ui/input';
import type { ChannelFormValues } from '@/features/channels/components/channel-form-schema';
import { useCompanyOptions } from '@/features/channels/hooks/use-company-options';

const PLATFORM_OPTIONS = [
  { value: 'woocommerce', label: 'WooCommerce' },
  { value: 'shopify', label: 'Shopify' },
  { value: 'amazon', label: 'Amazon' },
  { value: 'noon', label: 'Noon' },
  { value: 'salla', label: 'Salla' },
  { value: 'zid', label: 'Zid' },
] as const;

export function ChannelFormFields() {
  const { t } = useTranslation('channels');
  const { register, setValue, control } = useFormContext<ChannelFormValues>();
  const { data: companyOptions = [], isLoading: companiesLoading } = useCompanyOptions();

  const companyId = useWatch({ control, name: 'company_id' });

  return (
    <div className="flex flex-col gap-4">
      <div className="grid gap-4 sm:grid-cols-2">
        <div className="sm:col-span-2">
          <FormField name="company_id" label={t('form.company.label')} required>
            <Combobox
              options={companyOptions}
              value={companyId || null}
              onChange={(val) => setValue('company_id', val, { shouldValidate: true })}
              placeholder={t('form.company.placeholder')}
              loading={companiesLoading}
            />
          </FormField>
        </div>

        <FormField name="name" label={t('form.name.label')} required>
          <Input placeholder={t('form.name.placeholder')} {...register('name')} />
        </FormField>

        <FormField name="platform" label={t('form.platform.label')} required>
          <select
            {...register('platform')}
            className="border-input h-9 w-full rounded-md border bg-transparent px-3 text-sm shadow-xs"
          >
            {PLATFORM_OPTIONS.map((p) => (
              <option key={p.value} value={p.value}>
                {p.label}
              </option>
            ))}
          </select>
        </FormField>

        <div className="sm:col-span-2">
          <FormField name="store_url" label={t('form.storeUrl.label')} required>
            <Input type="url" placeholder={t('form.storeUrl.placeholder')} {...register('store_url')} />
          </FormField>
        </div>

        <FormField name="consumer_key" label={t('form.consumerKey.label')}>
          <Input placeholder={t('form.consumerKey.placeholder')} {...register('consumer_key')} />
        </FormField>

        <FormField name="consumer_secret" label={t('form.consumerSecret.label')}>
          <Input type="password" placeholder={t('form.consumerSecret.placeholder')} {...register('consumer_secret')} />
        </FormField>
      </div>

      <div className="flex flex-col gap-2">
        <span className="text-sm font-medium">{t('form.syncSettings')}</span>
        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" className="border-input size-4 rounded" {...register('sync_products')} />
          {t('form.syncProducts')}
        </label>
        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" className="border-input size-4 rounded" {...register('sync_prices')} />
          {t('form.syncPrices')}
        </label>
        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" className="border-input size-4 rounded" {...register('sync_stock')} />
          {t('form.syncStock')}
        </label>
      </div>

      <label className="flex items-center gap-2 text-sm">
        <input type="checkbox" className="border-input size-4 rounded" {...register('is_active')} />
        {t('form.active')}
      </label>
    </div>
  );
}
