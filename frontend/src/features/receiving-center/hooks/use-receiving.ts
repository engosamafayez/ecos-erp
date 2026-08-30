import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { useOrganizationContext } from '@/features/organization/context/organization-context';
import { receivingService } from '../services/receiving-service';
import type { ReceiveLineInput, ReceivingQueueParams } from '../types/receiving';

const KEY = 'receiving';

function useScope() {
  const { activeCompanyId } = useOrganizationContext();
  return activeCompanyId ?? 'global';
}

export function useReceivingQueue(params: ReceivingQueueParams) {
  const c = useScope();
  return useQuery({
    queryKey: ['company', c, KEY, 'queue', params],
    queryFn: () => receivingService.queue(params),
    placeholderData: keepPreviousData,
    staleTime: 15_000,
  });
}

export function useReceivingPoDetail(purchaseOrderId: string | null) {
  const c = useScope();
  return useQuery({
    queryKey: ['company', c, KEY, 'po-detail', purchaseOrderId],
    queryFn: () => receivingService.poDetail(purchaseOrderId as string),
    enabled: purchaseOrderId !== null,
    staleTime: 5_000,
  });
}

/**
 * Receive actual quantities against a PO. On success every receiving-scoped query is invalidated so
 * the queue, KPIs and the PO detail all re-read canonical backend state (received/remaining/status).
 */
export function useReceiveAgainstPo() {
  const c = useScope();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ purchaseOrderId, lines }: { purchaseOrderId: string; lines: ReceiveLineInput[] }) =>
      receivingService.receive(purchaseOrderId, lines),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['company', c, KEY] });
    },
  });
}
