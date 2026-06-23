import { useFormContext } from 'react-hook-form';
import { useWatch } from 'react-hook-form';

import { Combobox } from '@/components/crud/combobox';
import { FormField } from '@/components/crud';
import { Input } from '@/components/ui/input';
import type { ProductMappingFormValues } from '@/features/product-mappings/components/product-mapping-form-schema';
import { useChannelOptions } from '@/features/product-mappings/hooks/use-channel-options';
import { useProductOptions } from '@/features/product-mappings/hooks/use-product-options';

const SYNC_STATUS_OPTIONS = [
  { value: 'pending', label: 'Pending' },
  { value: 'synced', label: 'Synced' },
  { value: 'error', label: 'Error' },
] as const;

export function ProductMappingFormFields() {
  const { register, setValue, control } = useFormContext<ProductMappingFormValues>();
  const { data: productOptions = [], isLoading: productsLoading } = useProductOptions();
  const { data: channelOptions = [], isLoading: channelsLoading } = useChannelOptions();

  const productId = useWatch({ control, name: 'product_id' });
  const channelId = useWatch({ control, name: 'channel_id' });

  return (
    <div className="flex flex-col gap-4">
      <div className="grid gap-4 sm:grid-cols-2">
        <div className="sm:col-span-2">
          <FormField name="product_id" label="Product" required>
            <Combobox
              options={productOptions}
              value={productId || null}
              onChange={(val) => setValue('product_id', val, { shouldValidate: true })}
              placeholder="Select product…"
              searchPlaceholder="Search by SKU or name…"
              loading={productsLoading}
            />
          </FormField>
        </div>

        <div className="sm:col-span-2">
          <FormField name="channel_id" label="Channel" required>
            <Combobox
              options={channelOptions}
              value={channelId || null}
              onChange={(val) => setValue('channel_id', val, { shouldValidate: true })}
              placeholder="Select channel…"
              loading={channelsLoading}
            />
          </FormField>
        </div>

        <FormField name="external_product_id" label="External Product ID" required>
          <Input placeholder="543" {...register('external_product_id')} />
        </FormField>

        <FormField name="external_sku" label="External SKU">
          <Input placeholder="DELL-XPS-WOO" {...register('external_sku')} />
        </FormField>

        <div className="sm:col-span-2">
          <FormField name="sync_status" label="Sync Status">
            <select
              {...register('sync_status')}
              className="border-input h-9 w-full rounded-md border bg-transparent px-3 text-sm shadow-xs"
            >
              {SYNC_STATUS_OPTIONS.map((s) => (
                <option key={s.value} value={s.value}>
                  {s.label}
                </option>
              ))}
            </select>
          </FormField>
        </div>
      </div>
    </div>
  );
}
