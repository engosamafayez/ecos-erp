import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { goodsReceiptsService } from '@/features/goods-receipts/services/goods-receipts-service';
import type { GoodsReceiptPayload, GoodsReceiptsQuery } from '@/features/goods-receipts/types/goods-receipt';

export const GR_KEY = 'goods-receipts';

export function useGoodsReceiptsQuery(params: GoodsReceiptsQuery) {
  return useQuery({
    queryKey: [GR_KEY, params],
    queryFn: () => goodsReceiptsService.list(params),
    placeholderData: keepPreviousData,
  });
}

export function useGoodsReceiptQuery(id: string) {
  return useQuery({
    queryKey: [GR_KEY, id],
    queryFn: () => goodsReceiptsService.get(id),
    enabled: Boolean(id),
  });
}

export function useCreateGoodsReceipt() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: FormData | GoodsReceiptPayload) => goodsReceiptsService.create(payload),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [GR_KEY] }),
  });
}

export function useUpdateGoodsReceipt() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: FormData | GoodsReceiptPayload }) =>
      goodsReceiptsService.update(id, payload),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [GR_KEY] }),
  });
}

export function useDeleteGoodsReceipt() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => goodsReceiptsService.remove(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [GR_KEY] }),
  });
}

export function usePostGoodsReceipt() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => goodsReceiptsService.post(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [GR_KEY] }),
  });
}
