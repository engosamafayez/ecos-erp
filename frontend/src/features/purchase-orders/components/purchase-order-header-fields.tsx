import { Controller, useFormContext } from 'react-hook-form';

import { FormField } from '@/components/crud';
import { Combobox } from '@/components/crud/combobox';
import { Input } from '@/components/ui/input';
import { useSupplierOptions } from '@/features/purchase-orders/hooks/use-supplier-options';
import type { PurchaseOrderFormValues } from '@/features/purchase-orders/components/purchase-order-form-schema';

export function PurchaseOrderHeaderFields({ readOnly = false }: { readOnly?: boolean }) {
  const { register, control } = useFormContext<PurchaseOrderFormValues>();
  const { data: supplierOptions = [], isLoading: loadingSuppliers } = useSupplierOptions();

  return (
    <div className="grid gap-4 sm:grid-cols-2">
      <div className="sm:col-span-2">
        <FormField name="supplier_id" label="Supplier" required>
          <Controller
            control={control}
            name="supplier_id"
            render={({ field }) => (
              <Combobox
                options={supplierOptions}
                value={field.value || null}
                onChange={field.onChange}
                placeholder="Select supplier…"
                loading={loadingSuppliers}
                disabled={readOnly}
              />
            )}
          />
        </FormField>
      </div>

      <FormField name="order_date" label="Order Date" required>
        <Input type="date" disabled={readOnly} {...register('order_date')} />
      </FormField>

      <FormField name="expected_date" label="Expected Date">
        <Input type="date" disabled={readOnly} {...register('expected_date')} />
      </FormField>

      <div className="sm:col-span-2">
        <FormField name="notes" label="Notes">
          <textarea
            rows={3}
            placeholder="Optional notes…"
            disabled={readOnly}
            className="border-input placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50"
            {...register('notes')}
          />
        </FormField>
      </div>
    </div>
  );
}
