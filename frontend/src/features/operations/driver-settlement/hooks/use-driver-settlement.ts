import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

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
