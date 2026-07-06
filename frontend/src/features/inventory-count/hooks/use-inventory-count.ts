import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  inventoryCountService,
  wasteInvestigationService,
  warehouseLiabilityService,
} from '../services/inventory-count-service';
import type { CountReportData } from '../types/inventory-count';
import type {
  CountSessionsQuery,
  CreateCountSessionPayload,
  UpdateCountSessionPayload,
  WasteInvestigationsQuery,
  ResolveWasteInvestigationPayload,
  WarehouseLiabilitiesQuery,
} from '../types/inventory-count';

const KEYS = {
  list: (params: CountSessionsQuery) => ['inventory-counts', params] as const,
  detail: (id: string) => ['inventory-counts', id] as const,
};

export function useCountSessionsQuery(params: CountSessionsQuery = {}) {
  return useQuery({
    queryKey: KEYS.list(params),
    queryFn: () => inventoryCountService.list(params),
  });
}

export function useCountSessionQuery(id: string) {
  return useQuery({
    queryKey: KEYS.detail(id),
    queryFn: () => inventoryCountService.get(id),
    enabled: !!id,
  });
}

export function useCreateCountSession() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (payload: CreateCountSessionPayload) => inventoryCountService.create(payload),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['inventory-counts'] }),
  });
}

export function useUpdateCountSession(id: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (payload: UpdateCountSessionPayload) => inventoryCountService.update(id, payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: KEYS.detail(id) });
      qc.invalidateQueries({ queryKey: ['inventory-counts'] });
    },
  });
}

export function useDeleteCountSession() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => inventoryCountService.delete(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['inventory-counts'] }),
  });
}

function useSessionAction(action: (id: string) => Promise<unknown>) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => action(id),
    onSuccess: (_data, id) => {
      qc.invalidateQueries({ queryKey: KEYS.detail(id) });
      qc.invalidateQueries({ queryKey: ['inventory-counts'] });
    },
  });
}

export function useStartCountSession() {
  return useSessionAction((id) => inventoryCountService.start(id));
}

export function useCompleteCountSession() {
  return useSessionAction((id) => inventoryCountService.complete(id));
}

export function useApproveCountSession() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, approvedBy }: { id: string; approvedBy?: string }) =>
      inventoryCountService.approve(id, approvedBy),
    onSuccess: (_data, { id }) => {
      qc.invalidateQueries({ queryKey: KEYS.detail(id) });
      qc.invalidateQueries({ queryKey: ['inventory-counts'] });
    },
  });
}

export function useCancelCountSession() {
  return useSessionAction((id) => inventoryCountService.cancel(id));
}

export function useCountReportQuery(id: string) {
  return useQuery<CountReportData>({
    queryKey: ['inventory-counts', id, 'report'] as const,
    queryFn:  () => inventoryCountService.report(id),
    enabled:  !!id,
  });
}

export function useUploadCountLineAttachment(sessionId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ lineId, file, description }: { lineId: string; file: File; description?: string }) =>
      inventoryCountService.uploadLineAttachment(sessionId, lineId, file, description),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['inventory-counts', sessionId] }),
  });
}

export function useDeleteCountLineAttachment(sessionId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ lineId, attachmentId }: { lineId: string; attachmentId: string }) =>
      inventoryCountService.deleteLineAttachment(sessionId, lineId, attachmentId),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['inventory-counts', sessionId] }),
  });
}

// ─── Waste Investigations ────────────────────────────────────────────────────

const WASTE_KEYS = {
  list: (params: WasteInvestigationsQuery) => ['waste-investigations', params] as const,
  detail: (id: string) => ['waste-investigations', id] as const,
};

export function useWasteInvestigationsQuery(params: WasteInvestigationsQuery = {}) {
  return useQuery({
    queryKey: WASTE_KEYS.list(params),
    queryFn: () => wasteInvestigationService.list(params),
  });
}

export function useWasteInvestigationQuery(id: string) {
  return useQuery({
    queryKey: WASTE_KEYS.detail(id),
    queryFn: () => wasteInvestigationService.get(id),
    enabled: !!id,
  });
}

export function useResolveWasteInvestigation() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: ResolveWasteInvestigationPayload }) =>
      wasteInvestigationService.resolve(id, payload),
    onSuccess: (_data, { id }) => {
      qc.invalidateQueries({ queryKey: WASTE_KEYS.detail(id) });
      qc.invalidateQueries({ queryKey: ['waste-investigations'] });
      qc.invalidateQueries({ queryKey: ['warehouse-liabilities'] });
    },
  });
}

// ─── Warehouse Liabilities ───────────────────────────────────────────────────

const LIABILITY_KEYS = {
  list: (params: WarehouseLiabilitiesQuery) => ['warehouse-liabilities', params] as const,
  detail: (id: string) => ['warehouse-liabilities', id] as const,
};

export function useWarehouseLiabilitiesQuery(params: WarehouseLiabilitiesQuery = {}) {
  return useQuery({
    queryKey: LIABILITY_KEYS.list(params),
    queryFn: () => warehouseLiabilityService.list(params),
  });
}

export function useApproveWarehouseLiability() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, ...payload }: { id: string; approved_by: string; notes?: string | null }) =>
      warehouseLiabilityService.approve(id, payload),
    onSuccess: (_data, { id }) => {
      qc.invalidateQueries({ queryKey: LIABILITY_KEYS.detail(id) });
      qc.invalidateQueries({ queryKey: ['warehouse-liabilities'] });
    },
  });
}

// ─── Waste Investigation Attachments ─────────────────────────────────────────

export function useUploadWasteAttachment(investigationId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({
      file,
      description,
      uploadedBy,
    }: {
      file: File;
      description?: string;
      uploadedBy?: string;
    }) => wasteInvestigationService.uploadAttachment(investigationId, file, description, uploadedBy),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: WASTE_KEYS.detail(investigationId) });
    },
  });
}

export function useDeleteWasteAttachment(investigationId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (attachmentId: string) =>
      wasteInvestigationService.deleteAttachment(investigationId, attachmentId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: WASTE_KEYS.detail(investigationId) });
    },
  });
}

export function useRejectWarehouseLiability() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, ...payload }: { id: string; rejected_by: string; reason?: string | null }) =>
      warehouseLiabilityService.reject(id, payload),
    onSuccess: (_data, { id }) => {
      qc.invalidateQueries({ queryKey: LIABILITY_KEYS.detail(id) });
      qc.invalidateQueries({ queryKey: ['warehouse-liabilities'] });
    },
  });
}
