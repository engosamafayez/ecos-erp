import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { preparationService } from '../services/preparation-service';
import type {
  WavesQuery,
  PoolQuery,
  CreateWavePayload,
  StartPreparationPayload,
  CompleteProductPayload,
  CancelWavePayload,
  RecalculateWavePayload,
  ApproveWavePayload,
  AssignWorkerPayload,
  ResolveShortagePayload,
  UpdatePoolQualityPayload,
} from '../types/preparation';

const TIMELINE_KEY  = 'preparation-timeline';
const DOCUMENTS_KEY = 'preparation-documents';

const WAVES_KEY = 'preparation-waves';
const POOL_KEY  = 'preparation-pool';
const DASH_KEY  = 'preparation-dashboard';
const ANALYTICS_KEY = 'preparation-analytics';
const WORKERS_KEY   = 'preparation-workers';
const STATIONS_KEY  = 'preparation-stations';

// ── Dashboard ─────────────────────────────────────────────────────────────────

export function usePreparationDashboard(params: { warehouse_id?: string; planning_date?: string } = {}) {
  return useQuery({
    queryKey: [DASH_KEY, params],
    queryFn: () => preparationService.getDashboard(params),
    staleTime: 30_000,
    refetchInterval: 60_000,
  });
}

// ── Analytics ─────────────────────────────────────────────────────────────────

export function usePreparationAnalytics(params: {
  from_date: string;
  to_date: string;
  warehouse_id?: string;
}) {
  return useQuery({
    queryKey: [ANALYTICS_KEY, params],
    queryFn: () => preparationService.getAnalytics(params),
    staleTime: 300_000,
    enabled: !!params.from_date && !!params.to_date,
  });
}

// ── Waves list ────────────────────────────────────────────────────────────────

export function usePreparationWaves(params: WavesQuery = {}) {
  return useQuery({
    queryKey: [WAVES_KEY, 'list', params],
    queryFn: () => preparationService.listWaves(params),
    placeholderData: keepPreviousData,
    staleTime: 15_000,
  });
}

// ── Wave detail ───────────────────────────────────────────────────────────────

export function usePreparationWave(id: string | null) {
  return useQuery({
    queryKey: [WAVES_KEY, 'detail', id],
    queryFn: () => preparationService.getWave(id!),
    enabled: !!id,
    staleTime: 10_000,
  });
}

// ── Create wave ───────────────────────────────────────────────────────────────

export function useCreateWave() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (payload: CreateWavePayload) => preparationService.createWave(payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: [WAVES_KEY] });
      qc.invalidateQueries({ queryKey: [DASH_KEY] });
    },
  });
}

// ── Wave actions ─────────────────────────────────────────────────────────────

function useWaveAction(fn: (id: string) => Promise<unknown>) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: fn,
    onSuccess: (_data, id) => {
      qc.invalidateQueries({ queryKey: [WAVES_KEY, 'detail', id] });
      qc.invalidateQueries({ queryKey: [WAVES_KEY, 'list'] });
      qc.invalidateQueries({ queryKey: [DASH_KEY] });
    },
  });
}

export function useGenerateDemand() {
  return useWaveAction((id) => preparationService.generateDemand(id));
}

export function useAnalyzeMaterials() {
  return useWaveAction((id) => preparationService.analyzeMaterials(id));
}

export function useCompleteWave() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => preparationService.completeWave(id),
    onSuccess: (_data, id) => {
      qc.invalidateQueries({ queryKey: [WAVES_KEY, 'detail', id] });
      qc.invalidateQueries({ queryKey: [WAVES_KEY, 'list'] });
      qc.invalidateQueries({ queryKey: [DASH_KEY] });
      qc.invalidateQueries({ queryKey: [POOL_KEY] });
    },
  });
}

export function useStartPreparation() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: StartPreparationPayload }) =>
      preparationService.startPreparation(id, payload),
    onSuccess: (_data, { id }) => {
      qc.invalidateQueries({ queryKey: [WAVES_KEY, 'detail', id] });
      qc.invalidateQueries({ queryKey: [WAVES_KEY, 'list'] });
      qc.invalidateQueries({ queryKey: [DASH_KEY] });
    },
  });
}

export function useCompleteItem() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ waveId, itemId, payload }: { waveId: string; itemId: string; payload: CompleteProductPayload }) =>
      preparationService.completeItem(waveId, itemId, payload),
    onSuccess: (_data, { waveId }) => {
      qc.invalidateQueries({ queryKey: [WAVES_KEY, 'detail', waveId] });
      qc.invalidateQueries({ queryKey: [WAVES_KEY, 'list'] });
    },
  });
}

export function useCancelWave() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: CancelWavePayload }) =>
      preparationService.cancelWave(id, payload),
    onSuccess: (_data, { id }) => {
      qc.invalidateQueries({ queryKey: [WAVES_KEY, 'detail', id] });
      qc.invalidateQueries({ queryKey: [WAVES_KEY, 'list'] });
      qc.invalidateQueries({ queryKey: [DASH_KEY] });
    },
  });
}

export function useRecalculateWave() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: RecalculateWavePayload }) =>
      preparationService.recalculateWave(id, payload),
    onSuccess: (_data, { id }) => {
      qc.invalidateQueries({ queryKey: [WAVES_KEY, 'detail', id] });
      qc.invalidateQueries({ queryKey: [WAVES_KEY, 'list'] });
    },
  });
}

// ── Wave enterprise actions ───────────────────────────────────────────────────

export function useApproveWave() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload?: ApproveWavePayload }) =>
      preparationService.approveWave(id, payload),
    onSuccess: (_data, { id }) => {
      qc.invalidateQueries({ queryKey: [WAVES_KEY, 'detail', id] });
      qc.invalidateQueries({ queryKey: [WAVES_KEY, 'list'] });
    },
  });
}

export function useAssignWorker() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ waveId, payload }: { waveId: string; payload: AssignWorkerPayload }) =>
      preparationService.assignWorker(waveId, payload),
    onSuccess: (_data, { waveId }) => {
      qc.invalidateQueries({ queryKey: [WAVES_KEY, 'detail', waveId] });
      qc.invalidateQueries({ queryKey: [WORKERS_KEY] });
    },
  });
}

export function useReleaseWorker() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ waveId, userId }: { waveId: string; userId: string }) =>
      preparationService.releaseWorker(waveId, userId),
    onSuccess: (_data, { waveId }) => {
      qc.invalidateQueries({ queryKey: [WAVES_KEY, 'detail', waveId] });
      qc.invalidateQueries({ queryKey: [WORKERS_KEY] });
    },
  });
}

export function useResolveShortage() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ waveId, payload }: { waveId: string; payload: ResolveShortagePayload }) =>
      preparationService.resolveShortage(waveId, payload),
    onSuccess: (_data, { waveId }) => {
      qc.invalidateQueries({ queryKey: [WAVES_KEY, 'detail', waveId] });
      qc.invalidateQueries({ queryKey: [WAVES_KEY, 'list'] });
    },
  });
}

export function useUpdatePoolQuality() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ poolId, payload }: { poolId: string; payload: UpdatePoolQualityPayload }) =>
      preparationService.updatePoolQuality(poolId, payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: [POOL_KEY] });
    },
  });
}

// ── Wave timeline / documents ─────────────────────────────────────────────────

export function useWaveTimeline(waveId: string | null) {
  return useQuery({
    queryKey: [TIMELINE_KEY, waveId],
    queryFn: async () => {
      try {
        return await preparationService.getWaveTimeline(waveId!);
      } catch (err: unknown) {
        const status = (err as { response?: { status?: number } })?.response?.status;
        if (status === 404) return [];
        throw err;
      }
    },
    enabled: !!waveId,
    staleTime: 30_000,
    retry: false,
  });
}

export function useWaveDocuments(waveId: string | null) {
  return useQuery({
    queryKey: [DOCUMENTS_KEY, waveId],
    queryFn: async () => {
      try {
        return await preparationService.listWaveDocuments(waveId!);
      } catch (err: unknown) {
        const status = (err as { response?: { status?: number } })?.response?.status;
        if (status === 404) return [];
        throw err;
      }
    },
    enabled: !!waveId,
    staleTime: 60_000,
    retry: false,
  });
}

// ── Pool ──────────────────────────────────────────────────────────────────────

export function usePreparedPool(params: PoolQuery) {
  return useQuery({
    queryKey: [POOL_KEY, params],
    queryFn: () => preparationService.listPool(params),
    placeholderData: keepPreviousData,
    staleTime: 15_000,
    enabled: !!params.warehouse_id,
  });
}

// ── Workers ───────────────────────────────────────────────────────────────────

export function usePreparationWorkers(params: { warehouse_id: string; planning_date?: string }) {
  return useQuery({
    queryKey: [WORKERS_KEY, params],
    queryFn: () => preparationService.listWorkers(params),
    staleTime: 30_000,
    enabled: !!params.warehouse_id,
  });
}

// ── Stations ──────────────────────────────────────────────────────────────────

export function usePreparationStations(params: { warehouse_id: string; status?: string }) {
  return useQuery({
    queryKey: [STATIONS_KEY, params],
    queryFn: () => preparationService.listStations(params),
    staleTime: 60_000,
    enabled: !!params.warehouse_id,
  });
}
