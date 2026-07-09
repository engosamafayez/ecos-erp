import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { preparationService } from '../services/preparation-service';
import type {
  WavesQuery,
  SessionsQuery,
  PoolQuery,
  CreateWavePayload,
  CreateSessionPayload,
  CancelSessionPayload,
  AddWaveToSessionPayload,
  StartPreparationPayload,
  CompleteProductPayload,
  CancelWavePayload,
  RecalculateWavePayload,
  ApproveWavePayload,
  AssignWorkerPayload,
  ResolveShortagePayload,
  UpdatePoolQualityPayload,
  ReportIssuePayload,
  CreateAssignmentPolicyPayload,
  OverrideWarehousePayload,
} from '../types/preparation';
import { useOrganizationContext } from '@/features/organization/context/organization-context';

function useScope() {
  const { activeCompanyId } = useOrganizationContext();
  return activeCompanyId ?? 'global';
}

const K = {
  timeline:           'preparation-timeline',
  documents:          'preparation-documents',
  waves:              'preparation-waves',
  session:            'preparation-sessions',
  pool:               'preparation-pool',
  dash:               'preparation-dashboard',
  analytics:          'preparation-analytics',
  workers:            'preparation-workers',
  stations:           'preparation-stations',
  productQueue:       'preparation-product-queue',
  productWorkspace:   'preparation-product-workspace',
  todaySessions:      'preparation-today-sessions',
  sessionProducts:    'preparation-session-products',
  assignmentPolicy:   'preparation-assignment-policies',
  enterpriseQueue:    'preparation-enterprise-queue',
  enterpriseCapacity: 'preparation-enterprise-capacity',
  enterpriseOpt:      'preparation-enterprise-optimization',
  enterpriseDash:     'preparation-enterprise-dashboard',
};

// ── Dashboard ─────────────────────────────────────────────────────────────────

export function usePreparationDashboard(params: { warehouse_id?: string; planning_date?: string } = {}) {
  const c = useScope();
  return useQuery({
    queryKey: ['company', c, K.dash, params],
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
  const c = useScope();
  return useQuery({
    queryKey: ['company', c, K.analytics, params],
    queryFn: () => preparationService.getAnalytics(params),
    staleTime: 300_000,
    enabled: !!params.from_date && !!params.to_date,
  });
}

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

// ── Create wave ───────────────────────────────────────────────────────────────

export function useCreateWave() {
  const c = useScope();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (payload: CreateWavePayload) => preparationService.createWave(payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['company', c, K.waves] });
      qc.invalidateQueries({ queryKey: ['company', c, K.dash] });
    },
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
      qc.invalidateQueries({ queryKey: ['company', c, K.dash] });
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
  const c = useScope();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => preparationService.completeWave(id),
    onSuccess: (_data, id) => {
      qc.invalidateQueries({ queryKey: ['company', c, K.waves, 'detail', id] });
      qc.invalidateQueries({ queryKey: ['company', c, K.waves, 'list'] });
      qc.invalidateQueries({ queryKey: ['company', c, K.dash] });
      qc.invalidateQueries({ queryKey: ['company', c, K.pool] });
    },
  });
}

export function useStartPreparation() {
  const c = useScope();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: StartPreparationPayload }) =>
      preparationService.startPreparation(id, payload),
    onSuccess: (_data, { id }) => {
      qc.invalidateQueries({ queryKey: ['company', c, K.waves, 'detail', id] });
      qc.invalidateQueries({ queryKey: ['company', c, K.waves, 'list'] });
      qc.invalidateQueries({ queryKey: ['company', c, K.dash] });
    },
  });
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

export function useCancelWave() {
  const c = useScope();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: CancelWavePayload }) =>
      preparationService.cancelWave(id, payload),
    onSuccess: (_data, { id }) => {
      qc.invalidateQueries({ queryKey: ['company', c, K.waves, 'detail', id] });
      qc.invalidateQueries({ queryKey: ['company', c, K.waves, 'list'] });
      qc.invalidateQueries({ queryKey: ['company', c, K.dash] });
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

// ── Wave enterprise actions ───────────────────────────────────────────────────

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

export function useAssignWorker() {
  const c = useScope();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ waveId, payload }: { waveId: string; payload: AssignWorkerPayload }) =>
      preparationService.assignWorker(waveId, payload),
    onSuccess: (_data, { waveId }) => {
      qc.invalidateQueries({ queryKey: ['company', c, K.waves, 'detail', waveId] });
      qc.invalidateQueries({ queryKey: ['company', c, K.workers] });
    },
  });
}

export function useReleaseWorker() {
  const c = useScope();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ waveId, userId }: { waveId: string; userId: string }) =>
      preparationService.releaseWorker(waveId, userId),
    onSuccess: (_data, { waveId }) => {
      qc.invalidateQueries({ queryKey: ['company', c, K.waves, 'detail', waveId] });
      qc.invalidateQueries({ queryKey: ['company', c, K.workers] });
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

export function useUpdatePoolQuality() {
  const c = useScope();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ poolId, payload }: { poolId: string; payload: UpdatePoolQualityPayload }) =>
      preparationService.updatePoolQuality(poolId, payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['company', c, K.pool] });
    },
  });
}

// ── Wave timeline / documents ─────────────────────────────────────────────────

export function useWaveTimeline(waveId: string | null) {
  const c = useScope();
  return useQuery({
    queryKey: ['company', c, K.timeline, waveId],
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
  const c = useScope();
  return useQuery({
    queryKey: ['company', c, K.documents, waveId],
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
  const c = useScope();
  return useQuery({
    queryKey: ['company', c, K.pool, params],
    queryFn: () => preparationService.listPool(params),
    placeholderData: keepPreviousData,
    staleTime: 15_000,
    enabled: !!params.warehouse_id,
  });
}

// ── Workers ───────────────────────────────────────────────────────────────────

export function usePreparationWorkers(params: { warehouse_id: string; planning_date?: string }) {
  const c = useScope();
  return useQuery({
    queryKey: ['company', c, K.workers, params],
    queryFn: () => preparationService.listWorkers(params),
    staleTime: 30_000,
    enabled: !!params.warehouse_id,
  });
}

// ── Stations ──────────────────────────────────────────────────────────────────

export function usePreparationStations(params: { warehouse_id: string; status?: string }) {
  const c = useScope();
  return useQuery({
    queryKey: ['company', c, K.stations, params],
    queryFn: () => preparationService.listStations(params),
    staleTime: 60_000,
    enabled: !!params.warehouse_id,
  });
}

// ── Sessions ──────────────────────────────────────────────────────────────────

export function usePreparationSessions(params: SessionsQuery = {}) {
  const c = useScope();
  return useQuery({
    queryKey: ['company', c, K.session, 'list', params],
    queryFn: () => preparationService.listSessions(params),
    placeholderData: keepPreviousData,
    staleTime: 15_000,
  });
}

export function usePreparationSession(id: string | null) {
  const c = useScope();
  return useQuery({
    queryKey: ['company', c, K.session, 'detail', id],
    queryFn: () => preparationService.getSession(id!),
    enabled: !!id,
    staleTime: 10_000,
  });
}

export function useCreateSession() {
  const c = useScope();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (payload: CreateSessionPayload) => preparationService.createSession(payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['company', c, K.session] });
      qc.invalidateQueries({ queryKey: ['company', c, K.dash] });
    },
  });
}

export function useStartSession() {
  const c = useScope();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => preparationService.startSession(id),
    onSuccess: (_data, id) => {
      qc.invalidateQueries({ queryKey: ['company', c, K.session, 'detail', id] });
      qc.invalidateQueries({ queryKey: ['company', c, K.session, 'list'] });
      qc.invalidateQueries({ queryKey: ['company', c, K.todaySessions] });
    },
  });
}

export function useCompleteSession() {
  const c = useScope();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => preparationService.completeSession(id),
    onSuccess: (_data, id) => {
      qc.invalidateQueries({ queryKey: ['company', c, K.session, 'detail', id] });
      qc.invalidateQueries({ queryKey: ['company', c, K.session, 'list'] });
      qc.invalidateQueries({ queryKey: ['company', c, K.dash] });
    },
  });
}

export function useCancelSession() {
  const c = useScope();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: CancelSessionPayload }) =>
      preparationService.cancelSession(id, payload),
    onSuccess: (_data, { id }) => {
      qc.invalidateQueries({ queryKey: ['company', c, K.session, 'detail', id] });
      qc.invalidateQueries({ queryKey: ['company', c, K.session, 'list'] });
    },
  });
}

export function useAddWaveToSession() {
  const c = useScope();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ sessionId, payload }: { sessionId: string; payload: AddWaveToSessionPayload }) =>
      preparationService.addWaveToSession(sessionId, payload),
    onSuccess: (_data, { sessionId }) => {
      qc.invalidateQueries({ queryKey: ['company', c, K.session, 'detail', sessionId] });
      qc.invalidateQueries({ queryKey: ['company', c, K.waves, 'list'] });
    },
  });
}

export function usePlanSession() {
  const c = useScope();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => preparationService.planSession(id),
    onSuccess: (_data, id) => {
      qc.invalidateQueries({ queryKey: ['company', c, K.session, 'detail', id] });
      qc.invalidateQueries({ queryKey: ['company', c, K.session, 'list'] });
    },
  });
}

export function useApproveSession() {
  const c = useScope();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => preparationService.approveSession(id),
    onSuccess: (_data, id) => {
      qc.invalidateQueries({ queryKey: ['company', c, K.session, 'detail', id] });
      qc.invalidateQueries({ queryKey: ['company', c, K.session, 'list'] });
      qc.invalidateQueries({ queryKey: ['company', c, K.pool] });
    },
  });
}

export function useCloseSession() {
  const c = useScope();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => preparationService.closeSession(id),
    onSuccess: (_data, id) => {
      qc.invalidateQueries({ queryKey: ['company', c, K.session, 'detail', id] });
      qc.invalidateQueries({ queryKey: ['company', c, K.session, 'list'] });
      qc.invalidateQueries({ queryKey: ['company', c, K.dash] });
    },
  });
}

export function useConsolidation(sessionId: string | null) {
  const c = useScope();
  return useQuery({
    queryKey: ['company', c, K.session, 'consolidation', sessionId],
    queryFn: () => preparationService.getConsolidation(sessionId!),
    enabled: !!sessionId,
    staleTime: 30_000,
  });
}

// ── Product Queue (enriched) ──────────────────────────────────────────────────

export function useProductQueue(waveId: string | null, params: { status?: string } = {}) {
  const c = useScope();
  return useQuery({
    queryKey: ['company', c, K.productQueue, waveId, params],
    queryFn: () => preparationService.getProductQueue(waveId!, params),
    enabled: !!waveId,
    staleTime: 10_000,
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

// ── CR-PREP-001: Today's Sessions ─────────────────────────────────────────────

export function useTodaySessions(params: { date?: string } = {}) {
  const c = useScope();
  return useQuery({
    queryKey: ['company', c, K.todaySessions, params],
    queryFn:  () => preparationService.getTodaySessions(params),
    staleTime: 30_000,
    refetchInterval: 60_000,
  });
}

export function useSessionOrders(sessionId: string | null, params: { per_page?: number; page?: number } = {}) {
  const c = useScope();
  return useQuery({
    queryKey: ['company', c, K.session, 'orders', sessionId, params],
    queryFn:  () => preparationService.getSessionOrders(sessionId!, params),
    enabled:  !!sessionId,
    staleTime: 15_000,
  });
}

export function useAttachOrderToSession() {
  const c = useScope();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ sessionId, orderId }: { sessionId: string; orderId: string }) =>
      preparationService.attachOrderToSession(sessionId, orderId),
    onSuccess: (_data, { sessionId }) => {
      qc.invalidateQueries({ queryKey: ['company', c, K.session, 'orders', sessionId] });
      qc.invalidateQueries({ queryKey: ['company', c, K.todaySessions] });
    },
  });
}

export function useDetachOrderFromSession() {
  const c = useScope();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ sessionId, sessionOrderId, reason }: { sessionId: string; sessionOrderId: string; reason: string }) =>
      preparationService.detachOrderFromSession(sessionId, sessionOrderId, reason),
    onSuccess: (_data, { sessionId }) => {
      qc.invalidateQueries({ queryKey: ['company', c, K.session, 'orders', sessionId] });
      qc.invalidateQueries({ queryKey: ['company', c, K.todaySessions] });
    },
  });
}

// ── CR-PREP-001: Freeze session / Session products ────────────────────────────

export function useFreezeSession() {
  const c = useScope();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (sessionId: string) => preparationService.freezeSession(sessionId),
    onSuccess: (_data, sessionId) => {
      qc.invalidateQueries({ queryKey: ['company', c, K.session, 'detail', sessionId] });
      qc.invalidateQueries({ queryKey: ['company', c, K.session, 'list'] });
      qc.invalidateQueries({ queryKey: ['company', c, K.todaySessions] });
    },
  });
}

export function useSessionProducts(sessionId: string | null) {
  const c = useScope();
  return useQuery({
    queryKey: ['company', c, K.sessionProducts, sessionId],
    queryFn:  () => preparationService.getSessionProducts(sessionId!),
    enabled:  !!sessionId,
    staleTime: 15_000,
  });
}

// ── CR-PREP-001: Assignment Policies ─────────────────────────────────────────

export function useAssignmentPolicies(params: { warehouse_id?: string; is_active?: boolean } = {}) {
  const c = useScope();
  return useQuery({
    queryKey: ['company', c, K.assignmentPolicy, params],
    queryFn:  () => preparationService.listAssignmentPolicies(params),
    staleTime: 300_000,
  });
}

export function useCreateAssignmentPolicy() {
  const c = useScope();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (payload: CreateAssignmentPolicyPayload) =>
      preparationService.createAssignmentPolicy(payload),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['company', c, K.assignmentPolicy] }),
  });
}

export function useDeleteAssignmentPolicy() {
  const c = useScope();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => preparationService.deleteAssignmentPolicy(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['company', c, K.assignmentPolicy] }),
  });
}

export function useOverrideWarehouse() {
  const c = useScope();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ orderId, payload }: { orderId: string; payload: OverrideWarehousePayload }) =>
      preparationService.overrideWarehouse(orderId, payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['company', c, K.todaySessions] });
    },
  });
}

// ── Enterprise (Phases 6, 8, 9, 13) ──────────────────────────────────────────

export function useEnterpriseQueue(params: {
  planning_date?: string;
  warehouse_id?: string;
  wave_id?: string;
} = {}) {
  const c = useScope();
  return useQuery({
    queryKey: ['company', c, K.enterpriseQueue, params],
    queryFn:  () => preparationService.getEnterpriseQueue(params),
    staleTime: 15_000,
    refetchInterval: 30_000,
  });
}

export function useCapacityPlanning(params: {
  planning_date?: string;
  warehouse_id?: string;
} = {}) {
  const c = useScope();
  return useQuery({
    queryKey: ['company', c, K.enterpriseCapacity, params],
    queryFn:  () => preparationService.getCapacityPlanning(params),
    staleTime: 30_000,
    refetchInterval: 60_000,
  });
}

export function useOptimizationSuggestions(params: {
  planning_date?: string;
  warehouse_id?: string;
} = {}) {
  const c = useScope();
  return useQuery({
    queryKey: ['company', c, K.enterpriseOpt, params],
    queryFn:  () => preparationService.getOptimizationSuggestions(params),
    staleTime: 60_000,
  });
}

export function useEnterpriseDashboard(params: {
  planning_date?: string;
  warehouse_id?: string;
} = {}) {
  const c = useScope();
  return useQuery({
    queryKey: ['company', c, K.enterpriseDash, params],
    queryFn:  () => preparationService.getEnterpriseDashboard(params),
    staleTime: 30_000,
    refetchInterval: 60_000,
  });
}
