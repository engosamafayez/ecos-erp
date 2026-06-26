import { X } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { Combobox } from '@/components/crud/combobox';
import { Button } from '@/components/ui/button';
import { useProductOptions } from '@/features/orders/hooks/use-product-options';
import { cn } from '@/lib/utils';

export type AdvancedFilterValues = {
  productId: string | null;
  paymentMethod: string | null;
  shippingCompany: string | null;
  dateFrom: string | null;
  dateTo: string | null;
};

type Props = {
  values: AdvancedFilterValues;
  onChange: (next: AdvancedFilterValues) => void;
  onClear: () => void;
};

function FieldLabel({ children }: { children: React.ReactNode }) {
  return (
    <span className="mb-1 block text-xs font-medium text-muted-foreground">{children}</span>
  );
}

/**
 * DD-026 — Advanced Filters panel.
 * Rendered when the Filters toggle is active. Collapses when toggle is off.
 */
export function OrderAdvancedFilters({ values, onChange, onClear }: Props) {
  const { t } = useTranslation('orders');
  const { data: productOptions = [], isLoading: loadingProducts } = useProductOptions();

  function set<K extends keyof AdvancedFilterValues>(key: K, val: AdvancedFilterValues[K]) {
    onChange({ ...values, [key]: val });
  }

  const hasAny = Object.values(values).some(Boolean);

  return (
    <div className="border-b bg-muted/30 px-4 py-3">
      <div className="flex items-center justify-between mb-2">
        <span className="text-xs font-medium text-muted-foreground">
          {t('filters.advanced')}
        </span>
        {hasAny ? (
          <Button type="button" variant="ghost" size="sm" onClick={onClear} className="h-6 px-2 text-xs">
            <X className="size-3" />
            {t('filters.clearAll')}
          </Button>
        ) : null}
      </div>

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-5">
        {/* Product — DD-024 */}
        <div>
          <FieldLabel>{t('filters.product')}</FieldLabel>
          <Combobox
            options={productOptions}
            value={values.productId}
            onChange={(v) => set('productId', v)}
            placeholder={t('filters.allProducts')}
            searchPlaceholder={t('filters.searchProduct')}
            loading={loadingProducts}
            className="h-8"
          />
          {values.productId ? (
            <button
              type="button"
              onClick={() => set('productId', null)}
              className="mt-0.5 text-[10px] text-muted-foreground hover:text-foreground"
            >
              {t('filters.clearField')}
            </button>
          ) : null}
        </div>

        {/* Payment Method */}
        <div>
          <FieldLabel>{t('filters.paymentMethod')}</FieldLabel>
          <input
            type="text"
            value={values.paymentMethod ?? ''}
            onChange={(e) => set('paymentMethod', e.target.value || null)}
            placeholder={t('filters.paymentMethodPlaceholder')}
            className={cn(
              'h-8 w-full rounded-md border border-input bg-background px-3 text-sm',
              'placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring',
            )}
          />
        </div>

        {/* Shipping Company */}
        <div>
          <FieldLabel>{t('filters.shippingCompany')}</FieldLabel>
          <input
            type="text"
            value={values.shippingCompany ?? ''}
            onChange={(e) => set('shippingCompany', e.target.value || null)}
            placeholder={t('filters.shippingCompanyPlaceholder')}
            className={cn(
              'h-8 w-full rounded-md border border-input bg-background px-3 text-sm',
              'placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring',
            )}
          />
        </div>

        {/* Date From */}
        <div>
          <FieldLabel>{t('filters.dateFrom')}</FieldLabel>
          <input
            type="date"
            value={values.dateFrom ?? ''}
            onChange={(e) => set('dateFrom', e.target.value || null)}
            className={cn(
              'h-8 w-full rounded-md border border-input bg-background px-3 text-sm',
              'text-foreground focus:outline-none focus:ring-1 focus:ring-ring',
            )}
          />
        </div>

        {/* Date To */}
        <div>
          <FieldLabel>{t('filters.dateTo')}</FieldLabel>
          <input
            type="date"
            value={values.dateTo ?? ''}
            onChange={(e) => set('dateTo', e.target.value || null)}
            min={values.dateFrom ?? undefined}
            className={cn(
              'h-8 w-full rounded-md border border-input bg-background px-3 text-sm',
              'text-foreground focus:outline-none focus:ring-1 focus:ring-ring',
            )}
          />
        </div>
      </div>
    </div>
  );
}
