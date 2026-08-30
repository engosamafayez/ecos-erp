import { api } from '@/lib/axios';
import type {
  DaySettlementBoard,
  DaySettlementBoardParams,
  DaySettlementDriverDetail,
  DaySettlementMovement,
} from '../types/driver-settlement';

// Read-only per-driver/per-day rollup over the canonical per-trip settlement engine and
// the canonical vehicle-custody / shift-reconciliation engines. All WRITES (open/submit-cash/
// reconcile/dispute/finalize, proof verify/reject, warehouse return receipt) go through their
// own canonical services — this service never mutates settlement or reconciliation state.
const BASE = '/logistics/distribution/driver-settlement';

export const driverSettlementService = {
  async board(params: DaySettlementBoardParams): Promise<DaySettlementBoard> {
    const { data } = await api.get<DaySettlementBoard>(BASE, { params });
    return data;
  },

  async detail(assignmentId: number, date: string): Promise<DaySettlementDriverDetail> {
    const { data } = await api.get<DaySettlementDriverDetail>(`${BASE}/${assignmentId}`, {
      params: { date },
    });
    return data;
  },

  // ── Operations review of driver trip movements (TASK-OPERATIONS-DRIVER-TRIP-MOVEMENT-APPROVAL-001) ──
  // WRITES — the canonical ReviewDriverTripMovementAction is the authority; these only invoke it.
  async approveMovement(movementId: string, note?: string): Promise<DaySettlementMovement> {
    const { data } = await api.patch<{ data: DaySettlementMovement }>(
      `/logistics/distribution/driver-movements/${movementId}/approve`,
      note ? { note } : {},
    );
    return data.data;
  },

  async rejectMovement(movementId: string, reason: string): Promise<DaySettlementMovement> {
    const { data } = await api.patch<{ data: DaySettlementMovement }>(
      `/logistics/distribution/driver-movements/${movementId}/reject`,
      { reason },
    );
    return data.data;
  },

  /** Fetch a movement's receipt as a blob (authenticated) so it can be opened without leaking a path. */
  async movementReceipt(movementId: string): Promise<Blob> {
    const { data } = await api.get(`/logistics/distribution/driver-movements/${movementId}/receipt`, {
      responseType: 'blob',
    });
    return data as Blob;
  },
};
