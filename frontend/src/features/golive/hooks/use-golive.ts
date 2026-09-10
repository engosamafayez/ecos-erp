import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { goliveService } from '@/features/golive/services/golive-service';
import type {
  OpeningBalancePayload,
  OpeningInventoryLine,
  ResetExecutePayload,
  ResetPreviewPayload,
} from '@/features/golive/types/golive';

export const GOLIVE_STATUS_KEY = 'golive-status';

export function useGoLiveStatus() {
  return useQuery({
    queryKey: [GOLIVE_STATUS_KEY],
    queryFn: () => goliveService.status(),
  });
}

export function useGoLivePreview() {
  return useMutation({
    mutationFn: (payload: ResetPreviewPayload) => goliveService.preview(payload),
  });
}

export function useGoLiveExecuteReset() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: ResetExecutePayload) => goliveService.execute(payload),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [GOLIVE_STATUS_KEY] }),
  });
}

export function useEstablishOpeningInventory() {
  return useMutation({
    mutationFn: (lines: OpeningInventoryLine[]) => goliveService.establishOpeningInventory(lines),
  });
}

export function usePostSupplierOpeningBalance() {
  return useMutation({
    mutationFn: ({ supplierId, payload }: { supplierId: string; payload: OpeningBalancePayload & { type: 'payable' | 'advance' } }) =>
      goliveService.postSupplierOpeningBalance(supplierId, payload),
  });
}

export function usePostCustomerOpeningBalance() {
  return useMutation({
    mutationFn: ({ customerId, payload }: { customerId: string; payload: OpeningBalancePayload }) =>
      goliveService.postCustomerOpeningBalance(customerId, payload),
  });
}

export function useActivateGoLive() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: () => goliveService.activate(),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [GOLIVE_STATUS_KEY] }),
  });
}
