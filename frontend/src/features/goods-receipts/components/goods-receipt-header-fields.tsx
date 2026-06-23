import { useEffect } from 'react';
import { Controller, useFormContext } from 'react-hook-form';
import { useQuery } from '@tanstack/react-query';

import { FormField } from '@/components/crud';
import { Combobox } from '@/components/crud/combobox';
import { Input } from '@/components/ui/input';
import type { GoodsReceiptFormValues } from '@/features/goods-receipts/components/goods-receipt-form-schema';
import { useApprovedPoOptions } from '@/features/goods-receipts/hooks/use-approved-po-options';
import { useWarehouseOptions } from '@/features/goods-receipts/hooks/use-warehouse-options';
import { purchaseOrdersService } from '@/features/purchase-orders/services/purchase-orders-service';

export type PoLineInfo = {
  id: string;
  productName: string;
  productSku: string;
};

type Props = {
  readOnly?: boolean;
  onPoLinesLoaded?: (infos: PoLineInfo[]) => void;
};

export function GoodsReceiptHeaderFields({ readOnly = false, onPoLinesLoaded }: Props) {
  const { register, control, watch, setValue } = useFormContext<GoodsReceiptFormValues>();
  const { data: poOptions = [], isLoading: loadingPOs } = useApprovedPoOptions();
  const { data: warehouseOptions = [], isLoading: loadingWarehouses } = useWarehouseOptions();

  const selectedPoId = watch('purchase_order_id');

  const { data: poDetail } = useQuery({
    queryKey: ['po-detail-for-gr', selectedPoId],
    queryFn: () => purchaseOrdersService.get(selectedPoId),
    enabled: Boolean(selectedPoId) && !readOnly,
  });

  useEffect(() => {
    if (!poDetail || readOnly) {
      return;
    }
    const formLines = poDetail.lines.map((l) => ({
      purchase_order_line_id: l.id,
      product_id: l.product_id,
      ordered_quantity: l.quantity,
      received_quantity: '',
    }));
    setValue('lines', formLines, { shouldValidate: false });

    const infos: PoLineInfo[] = poDetail.lines.map((l) => ({
      id: l.id,
      productName: l.product?.name ?? '—',
      productSku: l.product?.sku ?? '',
    }));
    onPoLinesLoaded?.(infos);
  }, [poDetail, readOnly, setValue, onPoLinesLoaded]);

  return (
    <div className="grid gap-4 sm:grid-cols-2">
      <div className="sm:col-span-2">
        <FormField name="purchase_order_id" label="Purchase Order" required>
          <Controller
            control={control}
            name="purchase_order_id"
            render={({ field }) => (
              <Combobox
                options={poOptions}
                value={field.value || null}
                onChange={field.onChange}
                placeholder="Select approved PO…"
                loading={loadingPOs}
                disabled={readOnly}
              />
            )}
          />
        </FormField>
      </div>

      <div className="sm:col-span-2">
        <FormField name="warehouse_id" label="Warehouse" required>
          <Controller
            control={control}
            name="warehouse_id"
            render={({ field }) => (
              <Combobox
                options={warehouseOptions}
                value={field.value || null}
                onChange={field.onChange}
                placeholder="Select warehouse…"
                loading={loadingWarehouses}
                disabled={readOnly}
              />
            )}
          />
        </FormField>
      </div>

      <FormField name="receipt_date" label="Receipt Date" required>
        <Input type="date" disabled={readOnly} {...register('receipt_date')} />
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
