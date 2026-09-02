import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useQuery } from '@tanstack/react-query';

import { EcosMultiCombobox, type EcosMultiComboboxOption } from '@/components/ui/ecos-multi-combobox';
import { productsService } from '@/features/products/services/products-service';

type KnownOption = { id: string; sku: string; name: string };

type SupplierRawMaterialsSelectProps = {
  value: string[];
  onChange: (ids: string[]) => void;
  /** Already-assigned Raw Materials from the Supplier being edited — keeps
   *  their chip labels correct even before the user has searched for them. */
  preloaded?: KnownOption[];
  disabled?: boolean;
};

/**
 * Searchable multi-select over the canonical Product catalog, restricted to
 * Raw Materials — no duplicate catalog data, reuses the existing /products
 * endpoint (product_type filter + search, already supported).
 */
export function SupplierRawMaterialsSelect({ value, onChange, preloaded = [], disabled }: SupplierRawMaterialsSelectProps) {
  const { t } = useTranslation('suppliers');
  const { t: tCommon } = useTranslation('common');
  const [search, setSearch] = useState('');

  const { data, isLoading } = useQuery({
    queryKey: ['raw-materials-for-supplier', search],
    queryFn: () => productsService.list({ product_type: 'raw_material', search: search || undefined, per_page: 50 }),
    staleTime: 30 * 1000,
  });

  const options = useMemo<EcosMultiComboboxOption[]>(() => {
    const fromSearch = (data?.items ?? []).map((p) => ({ value: p.id, label: `${p.name} (${p.sku})` }));
    const fromPreload = preloaded.map((p) => ({ value: p.id, label: `${p.name} (${p.sku})` }));
    const merged = new Map(fromPreload.map((o) => [o.value, o]));
    for (const o of fromSearch) merged.set(o.value, o);

    return Array.from(merged.values());
  }, [data, preloaded]);

  return (
    <EcosMultiCombobox
      options={options}
      value={value}
      onChange={onChange}
      loading={isLoading}
      onSearchChange={setSearch}
      filterClientSide={false}
      placeholder={t($ => $.capabilities.rawMaterials.placeholder)}
      searchPlaceholder={t($ => $.capabilities.rawMaterials.searchPlaceholder)}
      emptyText={t($ => $.capabilities.rawMaterials.empty)}
      loadingText={tCommon($ => $.loading)}
      optionsLabel={t($ => $.capabilities.rawMaterials.searchPlaceholder)}
      disabled={disabled}
    />
  );
}
