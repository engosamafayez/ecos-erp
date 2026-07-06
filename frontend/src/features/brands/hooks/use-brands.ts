import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { brandsService } from '@/features/brands/services/brands-service';
import type { BrandsQuery, BrandPayload } from '@/features/brands/types/brand';

const BRANDS_KEY = 'brands';

export function useBrandsQuery(params: BrandsQuery, options?: { enabled?: boolean }) {
  return useQuery({
    queryKey: [BRANDS_KEY, params],
    queryFn: () => brandsService.list(params),
    placeholderData: keepPreviousData,
    enabled: options?.enabled ?? true,
  });
}

export function useBrandQuery(id: string) {
  return useQuery({
    queryKey: [BRANDS_KEY, id],
    queryFn: () => brandsService.get(id),
    enabled: !!id,
  });
}

export function useCreateBrand() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: BrandPayload) => brandsService.create(payload),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [BRANDS_KEY] }),
  });
}

export function useUpdateBrand() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: Omit<BrandPayload, 'company_id'> }) =>
      brandsService.update(id, payload),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [BRANDS_KEY] }),
  });
}

export function useDeleteBrand() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => brandsService.remove(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [BRANDS_KEY] }),
  });
}
