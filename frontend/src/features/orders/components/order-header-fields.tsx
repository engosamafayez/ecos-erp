import { Controller, useFormContext } from 'react-hook-form';

import { Combobox } from '@/components/crud/combobox';
import { FormField } from '@/components/crud';
import { Input } from '@/components/ui/input';
import type { OrderFormValues } from '@/features/orders/components/order-form-schema';
import { useCustomerOptions } from '@/features/orders/hooks/use-customer-options';
import { useChannelOptions } from '@/features/product-mappings/hooks/use-channel-options';

const STATUS_OPTIONS = [
  { value: 'pending', label: 'Pending' },
  { value: 'processing', label: 'Processing' },
  { value: 'completed', label: 'Completed' },
  { value: 'cancelled', label: 'Cancelled' },
] as const;

export function OrderHeaderFields() {
  const { register, control } = useFormContext<OrderFormValues>();
  const { data: customerOptions = [], isLoading: loadingCustomers } = useCustomerOptions();
  const { data: channelOptions = [], isLoading: loadingChannels } = useChannelOptions();

  return (
    <div className="grid gap-4 sm:grid-cols-2">
      <div className="sm:col-span-2">
        <FormField name="customer_id" label="Customer" required>
          <Controller
            control={control}
            name="customer_id"
            render={({ field }) => (
              <Combobox
                options={customerOptions}
                value={field.value || null}
                onChange={field.onChange}
                placeholder="Select customer…"
                loading={loadingCustomers}
              />
            )}
          />
        </FormField>
      </div>

      <div className="sm:col-span-2">
        <FormField name="channel_id" label="Channel">
          <Controller
            control={control}
            name="channel_id"
            render={({ field }) => (
              <Combobox
                options={channelOptions}
                value={field.value || null}
                onChange={field.onChange}
                placeholder="Select channel (optional)…"
                loading={loadingChannels}
              />
            )}
          />
        </FormField>
      </div>

      <FormField name="order_date" label="Order Date" required>
        <Input type="date" {...register('order_date')} />
      </FormField>

      <FormField name="status" label="Status" required>
        <select
          {...register('status')}
          className="border-input h-9 w-full rounded-md border bg-transparent px-3 text-sm shadow-xs"
        >
          {STATUS_OPTIONS.map((s) => (
            <option key={s.value} value={s.value}>
              {s.label}
            </option>
          ))}
        </select>
      </FormField>

      <div className="sm:col-span-2">
        <FormField name="external_order_id" label="External Order ID">
          <Input placeholder="e.g. WC-1234" {...register('external_order_id')} />
        </FormField>
      </div>

      <div className="sm:col-span-2">
        <FormField name="notes" label="Notes">
          <textarea
            rows={3}
            placeholder="Optional notes…"
            className="border-input placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50"
            {...register('notes')}
          />
        </FormField>
      </div>
    </div>
  );
}
