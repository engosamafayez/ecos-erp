import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { warehousesService } from '@/features/warehouses/services/warehouses-service';
import type { WarehousesQuery, WarehousePayload } from '@/features/warehouses/types/warehouse';

const WAREHOUSES_KEY = 'warehouses';

/** Paginated, filtered, sorted warehouses list. */
export function useWarehousesQuery(params: WarehousesQuery) {
  return useQuery({
    queryKey: [WAREHOUSES_KEY, params],
    queryFn: () => warehousesService.list(params),
    placeholderData: keepPreviousData,
  });
}

export function useCreateWarehouse() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: WarehousePayload) => warehousesService.create(payload),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [WAREHOUSES_KEY] }),
  });
}

export function useUpdateWarehouse() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: WarehousePayload }) =>
      warehousesService.update(id, payload),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [WAREHOUSES_KEY] }),
  });
}

export function useDeleteWarehouse() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => warehousesService.remove(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [WAREHOUSES_KEY] }),
  });
}
