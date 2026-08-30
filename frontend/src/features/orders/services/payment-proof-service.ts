import { api } from '@/lib/axios';

export type PaymentProofState = 'uploaded' | 'verified' | 'rejected';

export type PaymentProof = {
  id: string;
  order_id: string;
  state: PaymentProofState;
  is_active: boolean;
  original_filename: string | null;
  mime_type: string | null;
  size_bytes: number | null;
  download_url: string;
  uploaded_by: number | null;
  uploaded_at: string | null;
  verified_at: string | null;
  rejected_at: string | null;
  rejection_reason: string | null;
  superseded_at: string | null;
};

export const paymentProofService = {
  async list(orderId: string): Promise<PaymentProof[]> {
    const { data } = await api.get<{ data: PaymentProof[] }>(`/orders/${orderId}/payment-proofs`);
    return data.data;
  },

  async upload(orderId: string, file: File): Promise<PaymentProof> {
    const fd = new FormData();
    fd.append('file', file);
    const { data } = await api.post<{ data: PaymentProof }>(`/orders/${orderId}/payment-proofs`, fd);
    return data.data;
  },

  async verify(proofId: string): Promise<PaymentProof> {
    const { data } = await api.post<{ data: PaymentProof }>(`/payment-proofs/${proofId}/verify`);
    return data.data;
  },

  async reject(proofId: string, reason: string): Promise<PaymentProof> {
    const { data } = await api.post<{ data: PaymentProof }>(`/payment-proofs/${proofId}/reject`, { reason });
    return data.data;
  },

  /** Fetches the proof through the tenant-scoped, authenticated endpoint and opens it. */
  async view(proof: PaymentProof): Promise<void> {
    const url = await paymentProofService.objectUrl(proof.download_url);
    window.open(url, '_blank', 'noopener,noreferrer');
    setTimeout(() => URL.revokeObjectURL(url), 60_000);
  },

  /**
   * An object URL for a proof, for INLINE preview.
   *
   * The file lives on a private disk behind an authenticated, tenant-scoped route, so it
   * can never be rendered with a plain `<img src={path}>` — it has to be fetched with the
   * bearer token first. The caller owns the returned URL and must revoke it.
   *
   * Takes the URL rather than the proof object so callers can depend on a primitive.
   */
  async objectUrl(downloadUrl: string): Promise<string> {
    const res = await api.get(downloadUrl, { responseType: 'blob' });
    return URL.createObjectURL(res.data as Blob);
  },
};
