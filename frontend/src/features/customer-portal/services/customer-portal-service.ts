import axios from 'axios';

import { env } from '@/lib/env';
import { trackingTokenStorage } from '@/features/customer-portal/lib/tracking-token-storage';
import type {
  SupportCategory,
  TrackInvoice,
  TrackOrder,
  TrackPaymentMethodOptions,
  TrackTicket,
} from '@/features/customer-portal/types';

/**
 * TASK-ECOS-...-020 §28 — a bounded API client for `/api/track/**` ONLY.
 *
 * Deliberately its own Axios instance (mirrors the existing `publicApi` precedent in
 * `features/hr/services/recruitment-service.ts`, not the shared `api` instance used by the
 * internal ERP): no staff bearer token is ever attached, and a 401 here never triggers the
 * staff auth store's logout/onUnauthorized handler. Its own interceptor attaches ONLY the
 * customer's own guest tracking token, and a 401 here clears ONLY that token.
 */
const trackApi = axios.create({ baseURL: env.apiUrl });

let onSessionInvalid: (() => void) | null = null;

/** Registered by the tracking-session provider so a 401/expired/revoked token clears itself. */
export function setOnSessionInvalid(handler: (() => void) | null): void {
  onSessionInvalid = handler;
}

trackApi.interceptors.request.use((config) => {
  const session = trackingTokenStorage.get();
  if (session) {
    config.headers.Authorization = `Bearer ${session.token}`;
  }
  return config;
});

trackApi.interceptors.response.use(
  (response) => response,
  (error: unknown) => {
    if (axios.isAxiosError(error) && error.response?.status === 401) {
      trackingTokenStorage.clear();
      onSessionInvalid?.();
    }
    return Promise.reject(error);
  },
);

export const customerPortalService = {
  async requestVerification(orderNumber: string, contact: string): Promise<void> {
    await trackApi.post('/track/request', { order_number: orderNumber, contact });
  },

  async verify(
    orderNumber: string,
    contact: string,
    code: string,
  ): Promise<{ token: string; expiresAt: string }> {
    const { data } = await trackApi.post<{ data: { tracking_token: string; expires_at: string } }>(
      '/track/verify',
      {
        order_number: orderNumber,
        contact,
        code,
      },
    );
    return { token: data.data.tracking_token, expiresAt: data.data.expires_at };
  },

  async order(): Promise<TrackOrder> {
    const { data } = await trackApi.get<{ data: TrackOrder }>('/track/order');
    return data.data;
  },

  async invoice(): Promise<TrackInvoice> {
    const { data } = await trackApi.get<{ data: TrackInvoice }>('/track/order/invoice');
    return data.data;
  },

  /** Returns a same-origin, token-authenticated URL — NOT a public/unauthenticated link. */
  invoicePdfRequest(lang: 'en' | 'ar') {
    return trackApi.get<Blob>(`/track/order/invoice/pdf?lang=${lang}`, { responseType: 'blob' });
  },

  async support(): Promise<TrackTicket[]> {
    const { data } = await trackApi.get<{ data: TrackTicket[] }>('/track/order/support');
    return data.data;
  },

  async createSupportRequest(input: {
    category: SupportCategory;
    subject: string;
    description?: string;
  }): Promise<TrackTicket> {
    const { data } = await trackApi.post<{ data: TrackTicket }>('/track/order/support', input);
    return data.data;
  },

  async paymentMethodOptions(): Promise<TrackPaymentMethodOptions> {
    const { data } = await trackApi.get<{ data: TrackPaymentMethodOptions }>(
      '/track/order/payment-method',
    );
    return data.data;
  },

  async changePaymentMethod(paymentMethod: string): Promise<{ message: string }> {
    const { data } = await trackApi.post<{ data: { message: string } }>(
      '/track/order/payment-method',
      {
        payment_method: paymentMethod,
      },
    );
    return data.data;
  },
};
