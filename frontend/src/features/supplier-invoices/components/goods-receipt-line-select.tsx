import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { useFormatter } from '@/hooks/use-formatter';

import { Combobox } from '@/components/crud';
import { useEligibleReceiptLines } from '@/features/supplier-invoices/hooks/use-supplier-invoices';

type Props = {
  supplierId: string;
  productId: string;
  value: string | null;
  onChange: (goodsReceiptLineId: string | null) => void;
  excludeInvoiceId?: string;
  disabled?: boolean;
};

/**
 * §9 (remediation-004) — explicit Goods Receipt Line anchor picker for one invoice line.
 *
 * Lists only lines this exact supplier+product may legally settle (company/supplier/product
 * matched, remaining invoiceable quantity > 0 — {@see
 * Modules\Purchasing\SupplierInvoices\Domain\Services\InvoiceReceiptAnchorService::eligibleFor}).
 * Never infers a choice: with no supplier/product selected yet, or no eligible lines, this
 * simply offers nothing to pick — the invoice line stays unanchored (valid for a Draft/Validated
 * commercial document; POST is what enforces the anchor where the company's inbound mode
 * requires one).
 */
export function GoodsReceiptLineSelect({ supplierId, productId, value, onChange, excludeInvoiceId, disabled }: Props) {
  const { t } = useTranslation('supplier-invoices');
  const fmt = useFormatter();
  const { data = [], isFetching, isError, refetch } = useEligibleReceiptLines(supplierId, productId, excludeInvoiceId);

  const options = useMemo(
    () => data.map((line) => ({
      value: line.id,
      label: [
        line.receipt_number ?? line.id.slice(0, 8),
        line.po_number,
        `${fmt.number(line.available_quantity)} ${t($ => $.editor.anchor.availableSuffix)}`,
      ].filter(Boolean).join(' · '),
    })),
    [data, fmt, t],
  );

  const noProductYet = productId === '' || supplierId === '';

  return (
    <Combobox
      options={options}
      value={value}
      onChange={(v) => onChange(v || null)}
      loading={isFetching}
      isError={isError}
      onRetry={refetch}
      disabled={disabled || noProductYet}
      placeholder={noProductYet ? t($ => $.editor.anchor.selectProductFirst) : t($ => $.editor.anchor.placeholder)}
      searchPlaceholder={t($ => $.editor.anchor.searchPlaceholder)}
      emptyText={t($ => $.editor.anchor.empty)}
    />
  );
}
