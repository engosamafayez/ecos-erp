import { useQuery } from '@tanstack/react-query';

import { Combobox } from '@/components/crud';
import { categoriesService } from '@/features/categories/services/categories-service';

type ProductCategorySelectProps = {
  value: string | null;
  onChange: (value: string) => void;
  placeholder?: string;
  disabled?: boolean;
  className?: string;
};

export function ProductCategorySelect({
  value,
  onChange,
  placeholder = 'Select category…',
  disabled,
  className,
}: ProductCategorySelectProps) {
  const { data, isLoading } = useQuery({
    queryKey: ['category-options', 'product'],
    queryFn: () =>
      categoriesService.list({ per_page: 100, sort_by: 'name', sort_dir: 'asc', scope: 'product' }),
    staleTime: 60 * 1000,
  });

  const options = (data?.items ?? []).map((c) => ({
    value: c.id,
    label: `${c.name} (${c.code})`,
  }));

  return (
    <Combobox
      options={options}
      value={value ?? ''}
      onChange={onChange}
      loading={isLoading}
      placeholder={placeholder}
      searchPlaceholder="Search categories…"
      emptyText="No product categories found"
      disabled={disabled}
      className={className}
    />
  );
}
