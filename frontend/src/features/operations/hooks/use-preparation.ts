import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { preparationService } from '../services/preparation-service';
import type {
  WavesQuery,
  CompleteProductPayload,
  RecalculateWavePayload,
  ApproveWavePayload,
  ResolveShortagePayload,
  ReportIssuePayload,
  WaveEngineConfigPayload,
} from '../types/preparation';
import { useOrganizationContext } from '@/features/organization/context/organization-context';



function useScope() {
  const { activeCompanyId } = useOrganizationContext();
  return activeCompanyId ?? 'global';
}

const K = {
  waves:            'preparation-waves',
  waveDemand:       'wave-demand',
  productWorkspace: 'preparation-product-workspace',
  waveEngine:       'wave-engine-config',
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

// ── Wave Engine configuration (operational cycle) ─────────────────────────────

export function useWaveEngineConfig() {
  const c = useScope();
  return useQuery({
    queryKey: ['company', c, K.waveEngine],
    queryFn: () => preparationService.getWaveEngineConfig(),
    staleTime: 60_000,
  });
}

export function useUpdateWaveEngineConfig() {
  const c = useScope();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: WaveEngineConfigPayload }) =>
      preparationService.updateWaveEngineConfig(id, payload),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['company', c, K.waveEngine] }),
  });
}

// ── Current active wave (auto-open) ───────────────────────────────────────────

/**
 * Resolves the canonical current active wave from server state (§3-§6). Short staleTime so
 * re-entry / refocus re-resolves — a wave that has ended and been replaced by a new current
 * wave is picked up rather than trusting the last wave id (§4). Never cached across companies.
 */
export function useCurrentWave() {
  const c = useScope();
  return useQuery({
    queryKey: ['company', c, K.waves, 'current'],
    queryFn: () => preparationService.getCurrentWave(),
    staleTime: 10_000,
    refetchOnWindowFocus: true,
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

// ── Demand Engine read model hooks (TASK-PREP-INTEGRATION-001) ────────────────
// Each hook owns its own React Query key so widgets refresh independently.
// WebSocket integration: when demand events arrive, invalidate only the affected key.

export function useWaveKpis(waveId: string | null) {
  const c = useScope();
  return useQuery({
    queryKey: ['company', c, K.waveDemand, 'kpis', waveId],
    queryFn: () => preparationService.getWaveKpis(waveId!),
    enabled: !!waveId,
    staleTime: 30_000,
    refetchOnWindowFocus: true,
  });
}

export function useWaveProductDemand(waveId: string | null) {
  const c = useScope();
  return useQuery({
    queryKey: ['company', c, K.waveDemand, 'product-demand', waveId],
    queryFn: () => preparationService.getWaveProductDemand(waveId!),
    enabled: !!waveId,
    staleTime: 30_000,
    refetchOnWindowFocus: true,
  });
}

export function useWaveMaterialDemand(waveId: string | null) {
  const c = useScope();
  return useQuery({
    queryKey: ['company', c, K.waveDemand, 'material-demand', waveId],
    queryFn: () => preparationService.getWaveMaterialDemand(waveId!),
    enabled: !!waveId,
    staleTime: 30_000,
    refetchOnWindowFocus: true,
  });
}

export function useWaveMissingMaterials(waveId: string | null) {
  const c = useScope();
  return useQuery({
    queryKey: ['company', c, K.waveDemand, 'missing-materials', waveId],
    queryFn: () => preparationService.getWaveMissingMaterials(waveId!),
    enabled: !!waveId,
    staleTime: 30_000,
    refetchInterval: 60_000,
    refetchOnWindowFocus: true,
  });
}

/**
 * Procurement's Expected Incoming for a missing material (planning input only).
 *
 * Invalidates the demand keys so Missing Materials and Deficit Decisions both re-read
 * Uncovered from the server rather than recomputing anything in React.
 */
export function useUpdateExpectedIncoming(waveId: string | null) {
  const c = useScope();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (v: { materialId: string; expectedQty: number }) =>
      preparationService.updateExpectedIncoming(waveId!, v.materialId, v.expectedQty),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['company', c, K.waveDemand] });
    },
  });
}

export function useDeficitDecisions(waveId: string | null) {
  const c = useScope();
  return useQuery({
    queryKey: ['company', c, K.waveDemand, 'deficit-decisions', waveId],
    queryFn: () => preparationService.getDeficitDecisions(waveId!),
    enabled: !!waveId,
    staleTime: 30_000,
    refetchInterval: 60_000,
    refetchOnWindowFocus: true,
  });
}

/**
 * "استمرار العملية رغم العجز" — continue this product despite the shortage.
 *
 * Records the operator decision only; it never deletes the order line or product and never
 * touches the order status/total. On success every wave-scoped query is invalidated so the
 * Deficit Decisions, Product Demand and KPI views re-read canonical backend state.
 */
export function useContinueDespiteShortage() {
  const c = useScope();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ waveId, productId }: { waveId: string; productId: string }) =>
      preparationService.continueDespiteShortage(waveId, productId),
    onSuccess: (_data, { waveId }) => {
      qc.invalidateQueries({ queryKey: ['company', c, K.waveDemand] });
      qc.invalidateQueries({ queryKey: ['company', c, K.waves, 'detail', waveId] });
    },
  });
}

export function useWaveManufacturingDemand(waveId: string | null) {
  const c = useScope();
  return useQuery({
    queryKey: ['company', c, K.waveDemand, 'manufacturing-demand', waveId],
    queryFn: () => preparationService.getWaveManufacturingDemand(waveId!),
    enabled: !!waveId,
    staleTime: 30_000,
    refetchOnWindowFocus: true,
  });
}

export function useWaveOrders(waveId: string | null) {
  const c = useScope();
  return useQuery({
    queryKey: ['company', c, K.waveDemand, 'orders', waveId],
    queryFn: () => preparationService.getWaveOrders(waveId!),
    enabled: !!waveId,
    staleTime: 30_000,
    refetchOnWindowFocus: true,
  });
}

/**
 * Postpone an order out of the current preparation cycle.
 *
 * On success every wave-scoped query is invalidated so the table, Product Demand, Raw
 * Materials and Missing Materials all re-read canonical backend state. The row is never
 * hidden locally — `K.waveDemand` covers orders, product-demand, material-demand and
 * missing-materials, and the wave detail carries the order count.
 */
export function usePostponeWaveOrder() {
  const c = useScope();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ waveId, orderId }: { waveId: string; orderId: string }) =>
      preparationService.postponeWaveOrder(waveId, orderId),
    onSuccess: (_data, { waveId }) => {
      qc.invalidateQueries({ queryKey: ['company', c, K.waveDemand] });
      qc.invalidateQueries({ queryKey: ['company', c, K.waves, 'detail', waveId] });
      qc.invalidateQueries({ queryKey: ['company', c, K.waves, 'list'] });
    },
  });
}

/** Return a postponed order to preparation (PART 2). Invalidates the same wave-scoped keys
 * postpone does, so the queue, the wave and demand all re-read backend state. */
export function useReturnOrderToPreparation() {
  const c = useScope();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ waveId, orderId }: { waveId: string; orderId: string }) =>
      preparationService.returnOrderToPreparation(waveId, orderId),
    onSuccess: (_data, { waveId }) => {
      qc.invalidateQueries({ queryKey: ['company', c, K.waveDemand] });
      qc.invalidateQueries({ queryKey: ['company', c, K.waves, 'detail', waveId] });
      qc.invalidateQueries({ queryKey: ['company', c, K.waves, 'list'] });
    },
  });
}

export function useAdvanceWave() {
  const c = useScope();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => preparationService.advanceWave(id),
    onSuccess: (_data, id) => {
      qc.invalidateQueries({ queryKey: ['company', c, K.waves, 'detail', id] });
      qc.invalidateQueries({ queryKey: ['company', c, K.waves, 'list'] });
      qc.invalidateQueries({ queryKey: ['company', c, K.waveDemand] });
    },
  });
}

// ── Product Demand: operator-owned Prepared + drill-down ─────────────────────

/**
 * Product-level Prepared. Invalidates the whole wave-demand scope so Remaining,
 * Completion % and the KPI row all re-read canonical backend state — no client-side
 * arithmetic, and `order_lines.prepared_qty` is never involved.
 */
/**
 * Recording preparation changes BOTH the product demand and the wave header totals, so the
 * wave-detail query has to be invalidated too. Invalidating only `waveDemand` left the
 * header showing a stale Complete% even after the backend recomputed it.
 */
function invalidatePreparation(qc: ReturnType<typeof useQueryClient>, c: string, waveId: string) {
  qc.invalidateQueries({ queryKey: ['company', c, K.waveDemand] });
  qc.invalidateQueries({ queryKey: ['company', c, K.waves, 'detail', waveId] });
  qc.invalidateQueries({ queryKey: ['company', c, K.waves, 'list'] });
}

export function useUpdateProductPrepared() {
  const c = useScope();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ waveId, productId, preparedQty }: { waveId: string; productId: string; preparedQty: number }) =>
      preparationService.updateProductPrepared(waveId, productId, preparedQty),
    onSuccess: (_data, { waveId }) => invalidatePreparation(qc, c, waveId),
  });
}

/** Explicit "preparation finished" — never inferred from prepared reaching required. */
export function useCompleteProductPreparation() {
  const c = useScope();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ waveId, productId }: { waveId: string; productId: string }) =>
      preparationService.completeProductPreparation(waveId, productId),
    onSuccess: (_data, { waveId }) => invalidatePreparation(qc, c, waveId),
  });
}

/**
 * Withdraw the completion declaration.
 *
 * The mirror of useCompleteProductPreparation, invalidating the same scope. Completion
 * is reversible by design: Required keeps moving while the wave is open, so a product
 * marked done can legitimately need re-opening.
 */
export function useUncompleteProductPreparation() {
  const c = useScope();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ waveId, productId }: { waveId: string; productId: string }) =>
      preparationService.uncompleteProductPreparation(waveId, productId),
    onSuccess: (_data, { waveId }) => invalidatePreparation(qc, c, waveId),
  });
}

export function useProductRelatedOrders(waveId: string | null, productId: string | null) {
  const c = useScope();
  return useQuery({
    queryKey: ['company', c, K.waveDemand, 'product-orders', waveId, productId],
    queryFn: () => preparationService.getProductRelatedOrders(waveId!, productId!),
    enabled: !!waveId && !!productId,
    staleTime: 30_000,
  });
}

export function useMaterialRelatedOrders(waveId: string | null, materialId: string | null) {
  const c = useScope();
  return useQuery({
    queryKey: ['company', c, K.waveDemand, 'material-orders', waveId, materialId],
    queryFn: () => preparationService.getMaterialRelatedOrders(waveId!, materialId!),
    enabled: !!waveId && !!materialId,
    staleTime: 30_000,
  });
}
