import { useFormContext } from 'react-hook-form';
import { useWatch } from 'react-hook-form';

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
  const { register, setValue, control } = useFormContext<ChannelFormValues>();
  const { data: companyOptions = [], isLoading: companiesLoading } = useCompanyOptions();

  const companyId = useWatch({ control, name: 'company_id' });

  return (
    <div className="flex flex-col gap-4">
      <div className="grid gap-4 sm:grid-cols-2">
        <div className="sm:col-span-2">
          <FormField name="company_id" label="Company" required>
            <Combobox
              options={companyOptions}
              value={companyId || null}
              onChange={(val) => setValue('company_id', val, { shouldValidate: true })}
              placeholder="Select company…"
              loading={companiesLoading}
            />
          </FormField>
        </div>

        <FormField name="name" label="Name" required>
          <Input placeholder="ECOS Main Store" {...register('name')} />
        </FormField>

        <FormField name="platform" label="Platform" required>
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
          <FormField name="store_url" label="Store URL" required>
            <Input type="url" placeholder="https://store.example.com" {...register('store_url')} />
          </FormField>
        </div>

        <FormField name="consumer_key" label="Consumer Key">
          <Input placeholder="ck_…" {...register('consumer_key')} />
        </FormField>

        <FormField name="consumer_secret" label="Consumer Secret">
          <Input type="password" placeholder="cs_…" {...register('consumer_secret')} />
        </FormField>
      </div>

      <div className="flex flex-col gap-2">
        <span className="text-sm font-medium">Sync Settings</span>
        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" className="border-input size-4 rounded" {...register('sync_products')} />
          Sync Products
        </label>
        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" className="border-input size-4 rounded" {...register('sync_prices')} />
          Sync Prices
        </label>
        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" className="border-input size-4 rounded" {...register('sync_stock')} />
          Sync Stock
        </label>
      </div>

      <label className="flex items-center gap-2 text-sm">
        <input type="checkbox" className="border-input size-4 rounded" {...register('is_active')} />
        Active
      </label>
    </div>
  );
}
