import { useWatch, useFormContext } from 'react-hook-form';
import { useTranslation } from 'react-i18next';

import type { OrderFormValues } from '@/features/orders/components/order-form-schema';

function fmt(n: number) {
  return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

export function OrderTotalsLive() {
  const { t } = useTranslation('orders');
  const { control } = useFormContext<OrderFormValues>();
  const lines = useWatch({ control, name: 'lines' });

  const subtotal = (lines ?? []).reduce(
    (sum, l) => sum + Number(l?.quantity ?? 0) * Number(l?.unit_price ?? 0),
    0,
  );

  return (
    <div className="flex flex-col items-end gap-1 border-t pt-3 text-sm">
      <div className="flex gap-8">
        <span className="text-muted-foreground">{t('detail.subtotal')}</span>
        <span className="w-28 text-end font-medium">{fmt(subtotal)}</span>
      </div>
      <div className="flex gap-8 text-base font-semibold">
        <span>{t('detail.total')}</span>
        <span className="w-28 text-end">{fmt(subtotal)}</span>
      </div>
    </div>
  );
}
