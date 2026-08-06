import { api } from '@/lib/axios';
import type {
  AddTripOrderPayload,
  CaptureProofPayload,
  CompleteStopPayload,
  ConfirmReturnPayload,
  DeliveryAction,
  DeliveryException,
  DeliveryOptions,
  DeliveryProof,
  DeliveryStop,
  MoveTripOrderPayload,
  RaiseExceptionPayload,
  RecordActionPayload,
  RecordReturnPayload,
  TripOrder,
  TripReturn,
} from '../types/trip-execution';

const BASE = '/logistics/distribution/trips';

/**
 * Trip execution — orders on the trip, the stop list built from them, the
 * driver's actions and proof, and the exceptions and returns that come out of
 * the run.
 *
 * Collections come back as `{ data: [...] }` and single writes as a bare
 * resource; both are unwrapped here.
 *
 * Several of these refuse work the domain considers illegal — completing a stop
 * out of order, assigning an order to a full trip — with a 422 carrying the
 * reason. Those rejections are propagated, never swallowed.
 */
export const tripExecutionService = {
  async options(): Promise<DeliveryOptions> {
    const { data } = await api.get<DeliveryOptions>('/logistics/distribution/delivery/options');
    return data;
  },

  // ── Orders on the trip ─────────────────────────────────────────────────────

  async orders(tripId: string): Promise<TripOrder[]> {
    const { data } = await api.get<{ data: TripOrder[] }>(`${BASE}/${tripId}/orders`);
    return data.data;
  },

  async addOrder(tripId: string, payload: AddTripOrderPayload): Promise<TripOrder> {
    const { data } = await api.post<{ data: TripOrder }>(`${BASE}/${tripId}/orders`, payload);
    return data.data;
  },

  async removeOrder(tripId: string, orderId: string): Promise<void> {
    await api.delete(`${BASE}/${tripId}/orders/${orderId}`);
  },

  async moveOrder(tripId: string, payload: MoveTripOrderPayload): Promise<TripOrder> {
    const { data } = await api.post<{ data: TripOrder }>(`${BASE}/${tripId}/orders/move`, payload);
    return data.data;
  },

  // ── Stops ──────────────────────────────────────────────────────────────────

  async stops(tripId: string): Promise<DeliveryStop[]> {
    const { data } = await api.get<{ data: DeliveryStop[] }>(`${BASE}/${tripId}/stops`);
    return data.data;
  },

  /** Builds the stop list from the trip's assigned orders. Idempotent. */
  async generateStops(tripId: string): Promise<number> {
    const { data } = await api.post<{ created: number; message: string }>(
      `${BASE}/${tripId}/stops/generate`,
    );
    return data.created;
  },

  async startStop(tripId: string, stopId: number): Promise<DeliveryStop> {
    const { data } = await api.patch<{ data: DeliveryStop }>(
      `${BASE}/${tripId}/stops/${stopId}/start`,
    );
    return data.data;
  },

  async completeStop(
    tripId: string,
    stopId: number,
    payload: CompleteStopPayload,
  ): Promise<DeliveryStop> {
    const { data } = await api.patch<{ data: DeliveryStop }>(
      `${BASE}/${tripId}/stops/${stopId}/complete`,
      payload,
    );
    return data.data;
  },

  // ── Driver workflow: actions and proof ─────────────────────────────────────

  async recordAction(
    tripId: string,
    stopId: number,
    payload: RecordActionPayload,
  ): Promise<DeliveryAction> {
    const { data } = await api.post<{ data: DeliveryAction }>(
      `${BASE}/${tripId}/stops/${stopId}/actions`,
      payload,
    );
    return data.data;
  },

  async captureProof(
    tripId: string,
    stopId: number,
    payload: CaptureProofPayload,
  ): Promise<DeliveryProof> {
    const { data } = await api.post<{ data: DeliveryProof }>(
      `${BASE}/${tripId}/stops/${stopId}/proof`,
      payload,
    );
    return data.data;
  },

  // ── Exceptions ─────────────────────────────────────────────────────────────

  async exceptions(tripId: string): Promise<DeliveryException[]> {
    const { data } = await api.get<{ data: DeliveryException[] }>(`${BASE}/${tripId}/exceptions`);
    return data.data;
  },

  async raiseException(
    tripId: string,
    payload: RaiseExceptionPayload,
  ): Promise<DeliveryException> {
    const { data } = await api.post<{ data: DeliveryException }>(
      `${BASE}/${tripId}/exceptions`,
      payload,
    );
    return data.data;
  },

  async resolveException(
    tripId: string,
    exceptionId: number,
    resolutionNotes?: string,
  ): Promise<DeliveryException> {
    const { data } = await api.patch<{ data: DeliveryException }>(
      `${BASE}/${tripId}/exceptions/${exceptionId}/resolve`,
      { resolution_notes: resolutionNotes ?? null },
    );
    return data.data;
  },

  // ── Returns (product + custody, unified) ───────────────────────────────────

  async returns(tripId: string): Promise<TripReturn[]> {
    const { data } = await api.get<{ data: TripReturn[] }>(`${BASE}/${tripId}/returns`);
    return data.data;
  },

  async recordReturn(tripId: string, payload: RecordReturnPayload): Promise<TripReturn> {
    const { data } = await api.post<{ data: TripReturn }>(`${BASE}/${tripId}/returns`, payload);
    return data.data;
  },

  async confirmReturn(
    tripId: string,
    returnId: number,
    payload: ConfirmReturnPayload,
  ): Promise<TripReturn> {
    const { data } = await api.patch<{ data: TripReturn }>(
      `${BASE}/${tripId}/returns/${returnId}/confirm`,
      payload,
    );
    return data.data;
  },
};
