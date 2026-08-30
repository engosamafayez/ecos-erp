import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { paymentProofService } from '@/features/orders/services/payment-proof-service';

const key = (orderId: string) => ['payment-proofs', orderId] as const;

export function usePaymentProofs(orderId: string, enabled = true) {
  return useQuery({
    queryKey: key(orderId),
    queryFn: () => paymentProofService.list(orderId),
    enabled: enabled && Boolean(orderId),
  });
}

export function usePaymentProofActions(orderId: string) {
  const qc = useQueryClient();
  const invalidate = () => qc.invalidateQueries({ queryKey: key(orderId) });

  const upload = useMutation({
    mutationFn: (file: File) => paymentProofService.upload(orderId, file),
    onSuccess: invalidate,
  });
  const verify = useMutation({
    mutationFn: (proofId: string) => paymentProofService.verify(proofId),
    onSuccess: invalidate,
  });
  const reject = useMutation({
    mutationFn: (v: { proofId: string; reason: string }) => paymentProofService.reject(v.proofId, v.reason),
    onSuccess: invalidate,
  });

  return { upload, verify, reject };
}
