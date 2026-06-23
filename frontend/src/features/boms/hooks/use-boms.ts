import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { bomsService } from '@/features/boms/services/boms-service';
import type { BomPayload, BomsQuery } from '@/features/boms/types/bom';

const BOMS_KEY = 'boms';

export function useBomsQuery(params: BomsQuery) {
  return useQuery({
    queryKey: [BOMS_KEY, params],
    queryFn: () => bomsService.list(params),
  });
}

export function useBomQuery(id: string) {
  return useQuery({
    queryKey: [BOMS_KEY, id],
    queryFn: () => bomsService.get(id),
    enabled: Boolean(id),
  });
}

export function useCreateBom() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: BomPayload) => bomsService.create(payload),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [BOMS_KEY] }),
  });
}

export function useUpdateBom(id: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: BomPayload) => bomsService.update(id, payload),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [BOMS_KEY] }),
  });
}

export function useDeleteBom() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => bomsService.remove(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [BOMS_KEY] }),
  });
}
