import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { suppliersService } from '@/features/suppliers/services/suppliers-service';
import type { SuppliersQuery, SupplierPayload } from '@/features/suppliers/types/supplier';

const SUPPLIERS_KEY = 'suppliers';

export function useSupplierQuery(id: string) {
  return useQuery({
    queryKey: [SUPPLIERS_KEY, id],
    queryFn: () => suppliersService.get(id),
    enabled: Boolean(id),
  });
}

/** Paginated, filtered, sorted suppliers list. */
export function useSuppliersQuery(params: SuppliersQuery) {
  return useQuery({
    queryKey: [SUPPLIERS_KEY, params],
    queryFn: () => suppliersService.list(params),
    placeholderData: keepPreviousData,
  });
}

export function useCreateSupplier() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: SupplierPayload) => suppliersService.create(payload),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [SUPPLIERS_KEY] }),
  });
}

export function useUpdateSupplier() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: SupplierPayload }) =>
      suppliersService.update(id, payload),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [SUPPLIERS_KEY] }),
  });
}

export function useDeleteSupplier() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => suppliersService.remove(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [SUPPLIERS_KEY] }),
  });
}
