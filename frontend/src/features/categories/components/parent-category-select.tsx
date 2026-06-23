import { useQuery } from '@tanstack/react-query';

import { Combobox } from '@/components/crud';
import { categoriesService } from '@/features/categories/services/categories-service';

type ParentCategorySelectProps = {
  value: string | null;
  onChange: (value: string) => void;
  /** Category id to exclude from the options (prevents selecting self). */
  excludeId?: string;
  placeholder?: string;
  disabled?: boolean;
  className?: string;
};

const NONE_OPTION = { value: '', label: '— None (top level) —' };

/**
 * Searchable parent-category select backed by the Categories API. Reuses the
 * generic Combobox from the CRUD kit. Includes a "None" option for top-level
 * categories. Only levels 1–2 can be parents (3 is the max depth).
 */
export function ParentCategorySelect({
  value,
  onChange,
  excludeId,
  placeholder = 'Select parent…',
  disabled,
  className,
}: ParentCategorySelectProps) {
  const { data, isLoading } = useQuery({
    queryKey: ['category-options'],
    queryFn: () => categoriesService.list({ per_page: 100, sort_by: 'name', sort_dir: 'asc' }),
    staleTime: 60 * 1000,
  });

  const options = [
    NONE_OPTION,
    ...(data?.items ?? [])
      .filter((category) => category.id !== excludeId && category.level < 3)
      .map((category) => ({
        value: category.id,
        label: `${category.name} (${category.code}) · L${category.level}`,
      })),
  ];

  return (
    <Combobox
      options={options}
      value={value ?? ''}
      onChange={onChange}
      loading={isLoading}
      placeholder={placeholder}
      searchPlaceholder="Search categories…"
      emptyText="No categories found"
      disabled={disabled}
      className={className}
    />
  );
}
