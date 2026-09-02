import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { supplierCategoriesService } from '@/features/suppliers/services/supplier-categories-service';
import type { SupplierCategoryPayload } from '@/features/suppliers/types/supplier';

const SUPPLIER_CATEGORIES_KEY = 'supplier-categories';

export function useSupplierCategoriesQuery(activeOnly = false) {
  return useQuery({
    queryKey: [SUPPLIER_CATEGORIES_KEY, { activeOnly }],
    queryFn: () => supplierCategoriesService.list({ active_only: activeOnly }),
    staleTime: 60 * 1000,
  });
}

export function useCreateSupplierCategory() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: SupplierCategoryPayload) => supplierCategoriesService.create(payload),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [SUPPLIER_CATEGORIES_KEY] }),
  });
}

export function useUpdateSupplierCategory() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: SupplierCategoryPayload }) =>
      supplierCategoriesService.update(id, payload),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [SUPPLIER_CATEGORIES_KEY] }),
  });
}

export function useDeleteSupplierCategory() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => supplierCategoriesService.remove(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [SUPPLIER_CATEGORIES_KEY] }),
  });
}
