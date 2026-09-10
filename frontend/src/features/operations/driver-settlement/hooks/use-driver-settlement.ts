import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { loadingOsService } from '@/features/operations/loading-os/services/loading-os-service';

import { driverSettlementService } from '../services/driver-settlement-service';
import type { DaySettlementBoardParams } from '../types/driver-settlement';

const KEY = 'driver-settlement';

/** The Active / History / Day board. History keeps the previous page while fetching the next. */
export function useDriverSettlementBoard(params: DaySettlementBoardParams, enabled = true) {
  return useQuery({
    queryKey: [KEY, 'board', params],
    queryFn: () => driverSettlementService.board(params),
    enabled,
    placeholderData: keepPreviousData,
  });
}

export function useDriverSettlementDetail(assignmentId: number | null, date: string) {
  return useQuery({
    queryKey: [KEY, 'detail', assignmentId, date],
    queryFn: () => driverSettlementService.detail(assignmentId as number, date),
    enabled: assignmentId !== null && Boolean(date),
  });
}

/**
 * Operations review of a driver trip movement (Approve / Reject). On success it refreshes the
 * detail (so the movements list, totals and closing readiness re-derive from canonical truth) and
 * the board (so KPIs / expenses / net-cash update). The canonical action is the authority.
 */
export function useReviewDriverMovement(assignmentId: number | null, date: string) {
  const qc = useQueryClient();
  const invalidate = () => {
    void qc.invalidateQueries({ queryKey: [KEY, 'detail', assignmentId, date] });
    void qc.invalidateQueries({ queryKey: [KEY, 'board'] });
  };

  const approve = useMutation({
    mutationFn: ({ movementId, note }: { movementId: string; note?: string }) =>
      driverSettlementService.approveMovement(movementId, note),
    onSuccess: invalidate,
  });

  const reject = useMutation({
    mutationFn: ({ movementId, reason }: { movementId: string; reason: string }) =>
      driverSettlementService.rejectMovement(movementId, reason),
    onSuccess: invalidate,
  });

  return { approve, reject };
}

/**
 * Confirm Receipt on the Returns tab — the canonical Warehouse Return Receipt
 * (ReceiveVehicleReturnAction, via loadingOsService), never a second inventory-movement path.
 * Refreshes the detail on success so Loaded/Delivered/Remaining and the reconciliation rows
 * re-derive from the canonical custody engine, plus the board so Goods Remaining stays in sync.
 */
export function useReceiveVehicleReturn(assignmentId: number | null, date: string) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: ({
      sessionId,
      opsAssignmentId,
      lineId,
      payload,
    }: {
      sessionId: string;
      opsAssignmentId: string;
      lineId: string;
      payload: { quantity_accepted: number; quantity_damaged: number; damage_reason?: string | null };
    }) => loadingOsService.receiveReturn(sessionId, opsAssignmentId, lineId, payload),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: [KEY, 'detail', assignmentId, date] });
      void qc.invalidateQueries({ queryKey: [KEY, 'board'] });
    },
  });
}
