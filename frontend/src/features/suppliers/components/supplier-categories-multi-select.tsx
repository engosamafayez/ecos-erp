import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';

import { EcosMultiCombobox, type EcosMultiComboboxOption } from '@/components/ui/ecos-multi-combobox';
import { useSupplierCategoriesQuery } from '@/features/suppliers/hooks/use-supplier-categories';
import type { SupplierCategory } from '@/features/suppliers/types/supplier';

type SupplierCategoriesMultiSelectProps = {
  value: string[];
  onChange: (ids: string[]) => void;
  /** Already-assigned Categories from the Supplier being edited — keeps their
   *  chip labels correct even before the lookup list has loaded. */
  preloaded?: SupplierCategory[];
  disabled?: boolean;
};

/**
 * Searchable multi-select over the canonical, company-scoped Supplier Category lookup
 * (TASK-...-SUPPLIER-MASTER-AND-RETURNS-FINAL-018 §A.1) — a Supplier may belong to more
 * than one. Reuses the exact same `/supplier-categories` list the single-select
 * `SupplierCategorySelect` already uses (no duplicate category model, no second lookup).
 * The list is small (a handful of classification tags per company), so filtering is
 * client-side — same as `SupplierCategorySelect` — rather than a server round-trip per
 * keystroke like the much larger Raw Materials / Product Categories catalogs.
 */
export function SupplierCategoriesMultiSelect({
  value,
  onChange,
  preloaded = [],
  disabled,
}: SupplierCategoriesMultiSelectProps) {
  const { t } = useTranslation('suppliers');
  const { t: tCommon } = useTranslation('common');
  const { data, isLoading } = useSupplierCategoriesQuery(true);

  const options = useMemo<EcosMultiComboboxOption[]>(() => {
    const fromList = (data ?? []).map((c) => ({ value: c.id, label: `${c.name} (${c.code})` }));
    const fromPreload = preloaded.map((c) => ({ value: c.id, label: `${c.name} (${c.code})` }));
    const merged = new Map(fromPreload.map((o) => [o.value, o]));
    for (const o of fromList) merged.set(o.value, o);

    return Array.from(merged.values());
  }, [data, preloaded]);

  return (
    <EcosMultiCombobox
      options={options}
      value={value}
      onChange={onChange}
      loading={isLoading}
      placeholder={t($ => $.wizard.fields.categoriesPlaceholder)}
      searchPlaceholder={t($ => $.wizard.fields.categoriesSearchPlaceholder)}
      emptyText={t($ => $.categorySelect.empty)}
      loadingText={tCommon($ => $.loading)}
      optionsLabel={t($ => $.wizard.fields.categoriesSearchPlaceholder)}
      disabled={disabled}
    />
  );
}
