import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useQuery } from '@tanstack/react-query';

import { EcosMultiCombobox, type EcosMultiComboboxOption } from '@/components/ui/ecos-multi-combobox';
import { categoriesService } from '@/features/categories/services/categories-service';

type KnownOption = { id: string; code: string; name: string };

type SupplierProductCategoriesSelectProps = {
  value: string[];
  onChange: (ids: string[]) => void;
  /** Already-assigned Categories from the Supplier being edited — keeps their
   *  chip labels correct even before the user has searched for them. */
  preloaded?: KnownOption[];
  disabled?: boolean;
};

/**
 * Searchable multi-select over the canonical (shared, non-tenant) Category
 * catalog — reuses the existing /categories endpoint, same scope filter
 * ProductCategorySelect already uses. Distinct from Supplier Category
 * (Task 2): this declares what the Supplier can SUPPLY, not what the
 * Supplier itself IS.
 */
export function SupplierProductCategoriesSelect({ value, onChange, preloaded = [], disabled }: SupplierProductCategoriesSelectProps) {
  const { t } = useTranslation('suppliers');
  const { t: tCommon } = useTranslation('common');
  const [search, setSearch] = useState('');

  const { data, isLoading } = useQuery({
    queryKey: ['product-categories-for-supplier', search],
    queryFn: () => categoriesService.list({ scope: 'product', search: search || undefined, per_page: 50 }),
    staleTime: 30 * 1000,
  });

  const options = useMemo<EcosMultiComboboxOption[]>(() => {
    const fromSearch = (data?.items ?? []).map((c) => ({ value: c.id, label: `${c.name} (${c.code})` }));
    const fromPreload = preloaded.map((c) => ({ value: c.id, label: `${c.name} (${c.code})` }));
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
      placeholder={t($ => $.capabilities.productCategories.placeholder)}
      searchPlaceholder={t($ => $.capabilities.productCategories.searchPlaceholder)}
      emptyText={t($ => $.capabilities.productCategories.empty)}
      loadingText={tCommon($ => $.loading)}
      optionsLabel={t($ => $.capabilities.productCategories.searchPlaceholder)}
      disabled={disabled}
    />
  );
}
