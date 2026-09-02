import { useTranslation } from 'react-i18next';

import { Combobox } from '@/components/crud';
import { useSupplierCategoriesQuery } from '@/features/suppliers/hooks/use-supplier-categories';

type SupplierCategorySelectProps = {
  value: string | null;
  onChange: (value: string) => void;
  placeholder?: string;
  disabled?: boolean;
  className?: string;
};

export function SupplierCategorySelect({
  value,
  onChange,
  placeholder,
  disabled,
  className,
}: SupplierCategorySelectProps) {
  const { t } = useTranslation('suppliers');
  const { data, isLoading } = useSupplierCategoriesQuery(true);

  const options = (data ?? []).map((c) => ({
    value: c.id,
    label: `${c.name} (${c.code})`,
  }));

  return (
    <Combobox
      options={options}
      value={value ?? ''}
      onChange={onChange}
      loading={isLoading}
      placeholder={placeholder ?? t($ => $.wizard.fields.categoryPlaceholder)}
      emptyText={t($ => $.categorySelect.empty)}
      disabled={disabled}
      className={className}
    />
  );
}
