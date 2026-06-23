import { useFormContext, useWatch } from 'react-hook-form';

import { Input } from '@/components/ui/input';
import type { GoodsReceiptFormValues, GrLineFormValues } from '@/features/goods-receipts/components/goods-receipt-form-schema';

type PoLineInfo = {
  id: string;
  productName: string;
  productSku: string;
};

type LineError = {
  received_quantity?: { message?: string };
};

function fieldErr(err: LineError | undefined, k: keyof LineError) {
  const e = err?.[k];
  return typeof e?.message === 'string' ? e.message : undefined;
}

function fmt(n: number) {
  return n % 1 === 0 ? String(n) : n.toFixed(4).replace(/\.?0+$/, '');
}

type Props = {
  readOnly?: boolean;
  poLineInfos?: PoLineInfo[];
};

export function GoodsReceiptLinesEditor({ readOnly = false, poLineInfos = [] }: Props) {
  const {
    register,
    control,
    formState: { errors },
  } = useFormContext<GoodsReceiptFormValues>();

  const lines: GrLineFormValues[] = useWatch({ control, name: 'lines' }) ?? [];
  const lineErrors = errors.lines as LineError[] | undefined;

  if (lines.length === 0) {
    return (
      <p className="text-muted-foreground text-sm">
        Select an approved purchase order to load lines automatically.
      </p>
    );
  }

  return (
    <div className="overflow-x-auto">
      <table className="w-full text-sm">
        <thead>
          <tr className="text-muted-foreground border-b text-left">
            <th className="pb-2 pr-3 font-medium">Product</th>
            <th className="w-32 pb-2 pr-3 font-medium text-right">Ordered Qty</th>
            <th className="w-36 pb-2 pr-3 font-medium">Received Qty</th>
            <th className="w-32 pb-2 font-medium text-right">Remaining Qty</th>
          </tr>
        </thead>
        <tbody className="divide-y">
          {lines.map((line, index) => {
            const received = Number(line.received_quantity ?? 0);
            const remaining = Math.max(0, line.ordered_quantity - received);
            const errs = lineErrors?.[index];
            const info = poLineInfos.find((p) => p.id === line.purchase_order_line_id);

            return (
              <tr key={line.purchase_order_line_id}>
                <input type="hidden" {...register(`lines.${index}.purchase_order_line_id`)} />
                <input type="hidden" {...register(`lines.${index}.product_id`)} />
                <input
                  type="hidden"
                  {...register(`lines.${index}.ordered_quantity`, { valueAsNumber: true })}
                />
                <td className="py-2 pr-3">
                  <span className="font-medium">{info?.productName ?? '—'}</span>
                  {info?.productSku && (
                    <span className="text-muted-foreground ml-1.5 text-xs">{info.productSku}</span>
                  )}
                </td>
                <td className="py-2 pr-3 text-right">{fmt(line.ordered_quantity)}</td>
                <td className="py-2 pr-3">
                  <Input
                    type="number"
                    min="0"
                    max={line.ordered_quantity}
                    step="any"
                    disabled={readOnly}
                    {...register(`lines.${index}.received_quantity`)}
                  />
                  {fieldErr(errs, 'received_quantity') && (
                    <p className="text-destructive mt-1 text-xs">
                      {fieldErr(errs, 'received_quantity')}
                    </p>
                  )}
                </td>
                <td className="py-2 text-right">{fmt(remaining)}</td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}
