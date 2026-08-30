import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';

import { useToast } from '@/components/ds/use-toast';

import { loadingOsService } from '../services/loading-os-service';

const keys = {
  sessions: ['loading-os', 'sessions'] as const,
  assignments: (sessionId: string) => ['loading-os', 'assignments', sessionId] as const,
  allocations: (sessionId: string, assignmentId: string) =>
    ['loading-os', 'allocations', sessionId, assignmentId] as const,
  inventory: (sessionId: string, assignmentId: string) =>
    ['loading-os', 'inventory', sessionId, assignmentId] as const,
  reconciliation: (sessionId: string, assignmentId: string) =>
    ['loading-os', 'reconciliation', sessionId, assignmentId] as const,
  groups: (warehouseId: string | null) => ['loading-os', 'groups', warehouseId] as const,
  group: (slotId: string) => ['loading-os', 'group', slotId] as const,
};

/**
 * The operative cycle's Groups — the workspace entry point.
 *
 * Keyed by warehouse so switching warehouse refetches rather than showing another
 * warehouse's Groups. Required is live-derived server-side, so there is no snapshot to
 * invalidate: a refetch is the whole synchronisation mechanism.
 */
export function useLoadingGroups(warehouseId: string | null) {
  return useQuery({
    queryKey: keys.groups(warehouseId),
    queryFn: () => loadingOsService.listGroups(warehouseId),
  });
}

/** One Group's manifest: products, quantities and whatever transport exists. */
export function useLoadingGroup(slotId: string | null) {
  return useQuery({
    queryKey: keys.group(slotId ?? ''),
    queryFn: () => loadingOsService.getGroup(slotId as string),
    enabled: Boolean(slotId),
  });
}

/**
 * Open the Group's loading session — the certified, idempotent action.
 *
 * On success both the list and the manifest are invalidated, so the new execution
 * context is read back from the server rather than assumed. Nothing here marks any
 * product as loaded.
 */
/**
 * Warehouse: record + confirm the loaded quantity for one product.
 *
 * The server's refreshed manifest is written straight into the cache, so Remaining and
 * the workflow state come back from the canonical read rather than being guessed here.
 * The list is invalidated too, because the group's totals moved.
 */
export function useConfirmLoaded(slotId: string) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (vars: { productId: string; quantityLoaded: number; expectedLoadedQty?: number }) =>
      loadingOsService.confirmLoaded(
        slotId,
        vars.productId,
        vars.quantityLoaded,
        vars.expectedLoadedQty,
      ),
    onSuccess: (fresh) => {
      qc.setQueryData(keys.group(slotId), fresh);
      qc.invalidateQueries({ queryKey: ['loading-os', 'groups'] });
    },
  });
}

/** Warehouse: accept / edit / reject a driver's request. */
export function useResolveAdjustment(slotId: string) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (vars: {
      adjustmentId: string;
      action: 'accept' | 'edit' | 'reject';
      quantityLoaded?: number;
    }) =>
      loadingOsService.resolveAdjustment(
        slotId,
        vars.adjustmentId,
        vars.action,
        vars.quantityLoaded,
      ),
    onSuccess: (fresh) => {
      qc.setQueryData(keys.group(slotId), fresh);
      qc.invalidateQueries({ queryKey: ['loading-os', 'groups'] });
    },
  });
}

export function useStartLoading(slotId: string) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (vars: { windowId: string; tripId: string }) =>
      loadingOsService.startLoading(vars.windowId, slotId, vars.tripId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: keys.group(slotId) });
      qc.invalidateQueries({ queryKey: ['loading-os', 'groups'] });
    },
  });
}

export function useLoadingSessions() {
  return useQuery({
    queryKey: keys.sessions,
    queryFn: () => loadingOsService.listSessions(),
    staleTime: 15_000,
  });
}

export function useVehicleAssignments(sessionId: string | null) {
  return useQuery({
    queryKey: keys.assignments(sessionId ?? ''),
    queryFn: () => loadingOsService.listAssignments(sessionId!),
    enabled: sessionId !== null,
    staleTime: 15_000,
  });
}

export function useAllocations(sessionId: string | null, assignmentId: string | null) {
  return useQuery({
    queryKey: keys.allocations(sessionId ?? '', assignmentId ?? ''),
    queryFn: () => loadingOsService.listAllocations(sessionId!, assignmentId!),
    enabled: sessionId !== null && assignmentId !== null,
    staleTime: 10_000,
  });
}

export function useVehicleInventory(sessionId: string | null, assignmentId: string | null) {
  return useQuery({
    queryKey: keys.inventory(sessionId ?? '', assignmentId ?? ''),
    queryFn: () => loadingOsService.getInventory(sessionId!, assignmentId!),
    enabled: sessionId !== null && assignmentId !== null,
    staleTime: 10_000,
  });
}

export function useReconciliation(sessionId: string | null, assignmentId: string | null) {
  return useQuery({
    queryKey: keys.reconciliation(sessionId ?? '', assignmentId ?? ''),
    queryFn: () => loadingOsService.getReconciliation(sessionId!, assignmentId!),
    enabled: sessionId !== null && assignmentId !== null,
    staleTime: 5_000,
  });
}

export function useRecordDelivery(sessionId: string, assignmentId: string) {
  const { t } = useTranslation('operations');
  const { toast } = useToast();
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (payload: { allocation_record_id: string; quantity_delivered: number }) =>
      loadingOsService.recordDelivery(sessionId, assignmentId, payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: keys.allocations(sessionId, assignmentId) });
      qc.invalidateQueries({ queryKey: keys.inventory(sessionId, assignmentId) });
      qc.invalidateQueries({ queryKey: keys.reconciliation(sessionId, assignmentId) });
      toast({ title: t($ => $.loadingOs.toasts.deliveryRecorded) });
    },
    onError: (e: Error) => toast({ title: e.message, variant: 'destructive' }),
  });
}

export function useOpenReconciliation(sessionId: string, assignmentId: string) {
  const { t } = useTranslation('operations');
  const { toast } = useToast();
  const qc = useQueryClient();

  return useMutation({
    mutationFn: () => loadingOsService.openReconciliation(sessionId, assignmentId),
    onSuccess: (data) => {
      qc.setQueryData(keys.reconciliation(sessionId, assignmentId), data);
      toast({ title: t($ => $.loadingOs.toasts.reconciliationRefreshed) });
    },
    onError: (e: Error) => toast({ title: e.message, variant: 'destructive' }),
  });
}

export function useRecordReturn(sessionId: string, assignmentId: string) {
  const { t } = useTranslation('operations');
  const { toast } = useToast();
  const qc = useQueryClient();

  return useMutation({
    mutationFn: ({
      lineId,
      quantity,
      notes,
    }: {
      lineId: string;
      quantity: number;
      notes?: string;
    }) =>
      loadingOsService.recordReturn(sessionId, assignmentId, lineId, {
        quantity_returned_actual: quantity,
        resolution_notes: notes,
      }),
    onSuccess: (data) => {
      qc.setQueryData(keys.reconciliation(sessionId, assignmentId), data);
      toast({ title: t($ => $.loadingOs.toasts.returnRecorded) });
    },
    onError: (e: Error) => toast({ title: e.message, variant: 'destructive' }),
  });
}
