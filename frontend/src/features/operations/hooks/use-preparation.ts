import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { preparationService } from '../services/preparation-service';
import type {
  WavesQuery,
  CompleteProductPayload,
  RecalculateWavePayload,
  ApproveWavePayload,
  ResolveShortagePayload,
  ReportIssuePayload,
} from '../types/preparation';
import { useOrganizationContext } from '@/features/organization/context/organization-context';

function useScope() {
  const { activeCompanyId } = useOrganizationContext();
  return activeCompanyId ?? 'global';
}

const K = {
  waves:            'preparation-waves',
  productWorkspace: 'preparation-product-workspace',
};

// ── Waves list ────────────────────────────────────────────────────────────────

export function usePreparationWaves(params: WavesQuery = {}) {
  const c = useScope();
  return useQuery({
    queryKey: ['company', c, K.waves, 'list', params],
    queryFn: () => preparationService.listWaves(params),
    placeholderData: keepPreviousData,
    staleTime: 15_000,
  });
}

// ── Wave detail ───────────────────────────────────────────────────────────────

export function usePreparationWave(id: string | null) {
  const c = useScope();
  return useQuery({
    queryKey: ['company', c, K.waves, 'detail', id],
    queryFn: () => preparationService.getWave(id!),
    enabled: !!id,
    staleTime: 10_000,
  });
}

// ── Wave actions ─────────────────────────────────────────────────────────────

function useWaveAction(fn: (id: string) => Promise<unknown>) {
  const c = useScope();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: fn,
    onSuccess: (_data, id) => {
      qc.invalidateQueries({ queryKey: ['company', c, K.waves, 'detail', id] });
      qc.invalidateQueries({ queryKey: ['company', c, K.waves, 'list'] });
    },
  });
}

export function useGenerateDemand() {
  return useWaveAction((id) => preparationService.generateDemand(id));
}

export function useAnalyzeMaterials() {
  return useWaveAction((id) => preparationService.analyzeMaterials(id));
}

export function useCompleteItem() {
  const c = useScope();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ waveId, itemId, payload }: { waveId: string; itemId: string; payload: CompleteProductPayload }) =>
      preparationService.completeItem(waveId, itemId, payload),
    onSuccess: (_data, { waveId }) => {
      qc.invalidateQueries({ queryKey: ['company', c, K.waves, 'detail', waveId] });
      qc.invalidateQueries({ queryKey: ['company', c, K.waves, 'list'] });
    },
  });
}

export function useRecalculateWave() {
  const c = useScope();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: RecalculateWavePayload }) =>
      preparationService.recalculateWave(id, payload),
    onSuccess: (_data, { id }) => {
      qc.invalidateQueries({ queryKey: ['company', c, K.waves, 'detail', id] });
      qc.invalidateQueries({ queryKey: ['company', c, K.waves, 'list'] });
    },
  });
}

export function useApproveWave() {
  const c = useScope();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload?: ApproveWavePayload }) =>
      preparationService.approveWave(id, payload),
    onSuccess: (_data, { id }) => {
      qc.invalidateQueries({ queryKey: ['company', c, K.waves, 'detail', id] });
      qc.invalidateQueries({ queryKey: ['company', c, K.waves, 'list'] });
    },
  });
}

export function useResolveShortage() {
  const c = useScope();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ waveId, payload }: { waveId: string; payload: ResolveShortagePayload }) =>
      preparationService.resolveShortage(waveId, payload),
    onSuccess: (_data, { waveId }) => {
      qc.invalidateQueries({ queryKey: ['company', c, K.waves, 'detail', waveId] });
      qc.invalidateQueries({ queryKey: ['company', c, K.waves, 'list'] });
    },
  });
}

// ── Product Workspace ─────────────────────────────────────────────────────────

export function useProductWorkspace(waveId: string | null, itemId: string | null) {
  const c = useScope();
  return useQuery({
    queryKey: ['company', c, K.productWorkspace, waveId, itemId],
    queryFn: () => preparationService.getProductWorkspace(waveId!, itemId!),
    enabled: !!waveId && !!itemId,
    staleTime: 10_000,
  });
}

export function useReportIssue() {
  const c = useScope();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ waveId, payload }: { waveId: string; payload: ReportIssuePayload }) =>
      preparationService.reportIssue(waveId, payload),
    onSuccess: (_data, { waveId }) => {
      qc.invalidateQueries({ queryKey: ['company', c, K.waves, 'detail', waveId] });
    },
  });
}
