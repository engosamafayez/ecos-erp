import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Combobox } from '@/components/crud';
import { useProductsQuery } from '@/features/products/hooks/use-products';
import type { ProductType } from '@/features/products/types/product';
import { ENTITY_PRODUCT_TYPE, type LineEntityType } from '@/features/supplier-invoices/components/invoice-line-calc';

type Props = {
  entityType: LineEntityType;
  value: string;
  /** Label of the current selection — kept visible even when it's outside the latest results. */
  valueLabel: string;
  onChange: (productId: string, label: string) => void;
};

/**
 * §7–§8 — server-side, type-filtered, tenant-scoped product / raw-material search.
 *
 * Drives the typed term into `GET /products?search=&product_type=&status=active` (the canonical
 * searchable endpoint) instead of client-filtering a preloaded page. `product_type` separates
 * finished goods (Product) from raw materials so a Product search never returns Raw Materials or
 * vice-versa; tenancy + active-scoping are enforced server-side.
 */
export function ProductLineSelect({ entityType, value, valueLabel, onChange }: Props) {
  const { t } = useTranslation('supplier-invoices');
  const [query, setQuery] = useState('');
  const [debounced, setDebounced] = useState('');

  // Debounce the typed term (setState in a timeout, not synchronously in the effect body).
  useEffect(() => {
    const id = setTimeout(() => setDebounced(query), 250);
    return () => clearTimeout(id);
  }, [query]);

  const { data, isFetching } = useProductsQuery({
    search: debounced || undefined,
    product_type: ENTITY_PRODUCT_TYPE[entityType] as ProductType,
    status: 'active',
    per_page: 25,
  });

  const options = useMemo(() => {
    const items = (data?.items ?? []).map((p) => ({ value: p.id, label: `${p.sku} — ${p.name}` }));
    // Keep the current selection visible even when the latest search doesn't include it.
    if (value && valueLabel && !items.some((o) => o.value === value)) {
      items.unshift({ value, label: valueLabel });
    }
    return items;
  }, [data, value, valueLabel]);

  const isRaw = entityType === 'raw_material';

  return (
    <Combobox
      options={options}
      value={value || null}
      onChange={(v) => onChange(v, options.find((o) => o.value === v)?.label ?? '')}
      onSearchChange={setQuery}
      filterClientSide={false}
      loading={isFetching}
      placeholder={isRaw ? t($ => $.editor.placeholders.selectRawMaterial) : t($ => $.editor.placeholders.selectProduct)}
      searchPlaceholder={isRaw ? t($ => $.editor.placeholders.searchRawMaterial) : t($ => $.editor.placeholders.searchProduct)}
    />
  );
}
