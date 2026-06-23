import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { categoriesService } from '@/features/categories/services/categories-service';
import type { CategoriesQuery, CategoryPayload } from '@/features/categories/types/category';

const CATEGORIES_KEY = 'categories';

/** Paginated, filtered, sorted categories list. */
export function useCategoriesQuery(params: CategoriesQuery) {
  return useQuery({
    queryKey: [CATEGORIES_KEY, params],
    queryFn: () => categoriesService.list(params),
    placeholderData: keepPreviousData,
  });
}

export function useCreateCategory() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: CategoryPayload) => categoriesService.create(payload),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [CATEGORIES_KEY] }),
  });
}

export function useUpdateCategory() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: CategoryPayload }) =>
      categoriesService.update(id, payload),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [CATEGORIES_KEY] }),
  });
}

export function useDeleteCategory() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => categoriesService.remove(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [CATEGORIES_KEY] }),
  });
}
