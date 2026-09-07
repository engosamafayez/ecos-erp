import { api } from '@/lib/axios';
import type {
  CashHandoverContext,
  ConfirmCashHandoverPayload,
  TripCashHandover,
} from '../types/trip-cash-handover';

// Mirrors trip-settlement-service.ts's own BASE constant and conventions exactly —
// same trip-scoped path family, same api client, same response envelope shape.
const BASE = '/logistics/distribution/trips';

export const cashHandoverService = {
  async context(tripId: string): Promise<CashHandoverContext> {
    const { data } = await api.get<{ data: CashHandoverContext }>(`${BASE}/${tripId}/cash-handover`);
    return data.data;
  },

  async confirm(tripId: string, payload: ConfirmCashHandoverPayload): Promise<TripCashHandover> {
    const { data } = await api.post<{ data: TripCashHandover }>(
      `${BASE}/${tripId}/cash-handover/confirm`,
      payload,
    );
    return data.data;
  },
};
