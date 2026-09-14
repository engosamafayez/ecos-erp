import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { customerPortalService } from '@/features/customer-portal/services/customer-portal-service';
import { useTrackingSession } from '@/features/customer-portal/context/tracking-session-context';
import type { SupportCategory } from '@/features/customer-portal/types';

const TRACK_KEY = 'customer-track';

export function useRequestVerificationMutation() {
  return useMutation({
    mutationFn: (input: { orderNumber: string; contact: string }) =>
      customerPortalService.requestVerification(input.orderNumber, input.contact),
  });
}

export function useVerifyMutation() {
  const { startSession } = useTrackingSession();
  return useMutation({
    mutationFn: (input: { orderNumber: string; contact: string; code: string }) =>
      customerPortalService.verify(input.orderNumber, input.contact, input.code),
    onSuccess: ({ token, expiresAt }) => startSession(token, expiresAt),
  });
}

export function useTrackOrderQuery() {
  const { isVerified } = useTrackingSession();
  return useQuery({
    queryKey: [TRACK_KEY, 'order'],
    queryFn: customerPortalService.order,
    enabled: isVerified,
    retry: false,
  });
}

export function useTrackInvoiceQuery(enabled: boolean) {
  const { isVerified } = useTrackingSession();
  return useQuery({
    queryKey: [TRACK_KEY, 'invoice'],
    queryFn: customerPortalService.invoice,
    enabled: isVerified && enabled,
    retry: false,
  });
}

export function useTrackSupportQuery(enabled: boolean) {
  const { isVerified } = useTrackingSession();
  return useQuery({
    queryKey: [TRACK_KEY, 'support'],
    queryFn: customerPortalService.support,
    enabled: isVerified && enabled,
    retry: false,
  });
}

export function useCreateSupportRequestMutation() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (input: { category: SupportCategory; subject: string; description?: string }) =>
      customerPortalService.createSupportRequest(input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: [TRACK_KEY, 'support'] });
    },
  });
}

export function useTrackPaymentMethodOptionsQuery(enabled: boolean) {
  const { isVerified } = useTrackingSession();
  return useQuery({
    queryKey: [TRACK_KEY, 'payment-method-options'],
    queryFn: customerPortalService.paymentMethodOptions,
    enabled: isVerified && enabled,
    retry: false,
  });
}

export function useChangePaymentMethodMutation() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (paymentMethod: string) => customerPortalService.changePaymentMethod(paymentMethod),
    onSuccess: () => {
      // Refetch the canonical order — never optimistically assume the resulting state (§11).
      void queryClient.invalidateQueries({ queryKey: [TRACK_KEY, 'order'] });
      void queryClient.invalidateQueries({ queryKey: [TRACK_KEY, 'payment-method-options'] });
    },
  });
}
