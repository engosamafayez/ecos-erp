import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { cashHandoverService } from '../services/cash-handover-service';
import type { ConfirmCashHandoverPayload } from '../types/trip-cash-handover';

// Same query-key prefix as use-trip-settlement.ts, so a successful confirmation
// invalidates the settlement views too (the settlement detail is one of the
// facts the confirmation screen reads alongside the handover context).
const KEY = 'logistics-trips';

export function useCashHandoverContext(tripId: string | null) {
  return useQuery({
    queryKey: [KEY, 'cash-handover', tripId],
    queryFn: () => cashHandoverService.context(tripId as string),
    enabled: tripId !== null,
  });
}

export function useConfirmCashHandover(tripId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: ConfirmCashHandoverPayload) => cashHandoverService.confirm(tripId, payload),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [KEY] }),
  });
}
