import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { unitsService } from '@/features/units/services/units-service';
import type { UnitsQuery, UnitPayload } from '@/features/units/types/unit';

const UNITS_KEY = 'units';

/** Paginated, filtered, sorted units list. */
export function useUnitsQuery(params: UnitsQuery) {
  return useQuery({
    queryKey: [UNITS_KEY, params],
    queryFn: () => unitsService.list(params),
    placeholderData: keepPreviousData,
  });
}

export function useCreateUnit() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: UnitPayload) => unitsService.create(payload),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [UNITS_KEY] }),
  });
}

export function useUpdateUnit() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: UnitPayload }) =>
      unitsService.update(id, payload),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [UNITS_KEY] }),
  });
}

export function useDeleteUnit() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => unitsService.remove(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [UNITS_KEY] }),
  });
}
