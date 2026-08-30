import { api } from '@/lib/axios';
import type { ApiResponse } from '@/types';
import type {
  PreparationWave,
  PreparationDashboard,
  PreparationAnalytics,
  PreparationStation,
  PreparedPoolEntry,
  WorkerStatus,
  WavesQuery,
  WavesResult,
  PoolQuery,
  PoolResult,
  CreateWavePayload,
  CurrentWaveResponse,
  StartPreparationPayload,
  CompleteProductPayload,
  CancelWavePayload,
  RecalculateWavePayload,
  TimelineEntry,
  DocumentEntry,
  ApproveWavePayload,
  AssignWorkerPayload,
  ResolveShortagePayload,
  UpdatePoolQualityPayload,
  PreparationSession,
  SessionsQuery,
  SessionsResult,
  CreateSessionPayload,
  CancelSessionPayload,
  AddWaveToSessionPayload,
  ReportIssuePayload,
  CreateAssignmentPolicyPayload,
  OverrideWarehousePayload,
  TodaySessionsResponse,
  SessionProduct,
  SessionConsolidation,
  SessionOrdersResult,
  AssignmentPolicy,
  ProductWorkspace,
  EnterpriseQueueResult,
  CapacityPlanningResult,
  OptimizationSuggestion,
  EnterpriseDashboardResult,
  WaveKpiReadModel,
  WaveProductDemandItem,
  WaveMaterialDemandItem,
  WaveMissingMaterialItem,
  WaveManufacturingDemandItem,
  DeficitDecisionsResponse,
  WaveOrderEntry,
  ProductRelatedOrder,
  MaterialRelatedOrder,
  WaveEngineConfigResponse,
  WaveEngineConfig,
  WaveEngineConfigPayload,
} from '../types/preparation';

const BASE = '/preparation';

export const preparationService = {
  // ── Dashboard ───────────────────────────────────────────────────────────────

  async getDashboard(params: { warehouse_id?: string; planning_date?: string } = {}): Promise<PreparationDashboard> {
    const filtered = clean(params);
    const { data } = await api.get<ApiResponse<PreparationDashboard>>(`${BASE}/dashboard`, { params: filtered });
    return data.data;
  },

  // ── Analytics ───────────────────────────────────────────────────────────────

  async getAnalytics(params: {
    from_date: string;
    to_date: string;
    warehouse_id?: string;
  }): Promise<PreparationAnalytics> {
    const { data } = await api.get<ApiResponse<PreparationAnalytics>>(`${BASE}/analytics`, { params: clean(params) });
    return data.data;
  },

  // ── Waves ───────────────────────────────────────────────────────────────────

  async listWaves(params: WavesQuery = {}): Promise<WavesResult> {
    const filtered = clean(params);
    const { data } = await api.get<ApiResponse<WavesResult>>(`${BASE}/waves`, { params: filtered });
    return data.data;
  },

  async getWave(id: string): Promise<PreparationWave> {
    const { data } = await api.get<ApiResponse<PreparationWave>>(`${BASE}/waves/${id}`);
    return data.data;
  },

  /**
   * The canonical current active wave for Today's Preparation (§3-§6). Read-only; resolves
   * the ONE open operational wave from server state, or reports the none/multiple cases so
   * the client never relies on a stale wave id or silently picks among several.
   */
  async getCurrentWave(): Promise<CurrentWaveResponse> {
    const { data } = await api.get<ApiResponse<CurrentWaveResponse>>(`${BASE}/waves/current`);
    return data.data;
  },

  // ── Wave Engine configuration (operational cycle) ─────────────────────────────
  // Lives under the Configuration OS facade, not the /preparation prefix.
  async getWaveEngineConfig(): Promise<WaveEngineConfigResponse> {
    const { data } = await api.get<ApiResponse<WaveEngineConfigResponse>>('/configuration/wave-engine');
    return data.data;
  },

  async updateWaveEngineConfig(id: string, payload: WaveEngineConfigPayload): Promise<WaveEngineConfig> {
    const { data } = await api.put<ApiResponse<WaveEngineConfig>>(`/configuration/wave-engine/${id}`, payload);
    return data.data;
  },

  async createWave(payload: CreateWavePayload): Promise<PreparationWave> {
    const { data } = await api.post<ApiResponse<PreparationWave>>(`${BASE}/waves`, payload);
    return data.data;
  },

  async generateDemand(id: string): Promise<PreparationWave> {
    const { data } = await api.post<ApiResponse<PreparationWave>>(`${BASE}/waves/${id}/generate-demand`);
    return data.data;
  },

  async analyzeMaterials(id: string): Promise<PreparationWave> {
    const { data } = await api.post<ApiResponse<PreparationWave>>(`${BASE}/waves/${id}/analyze-materials`);
    return data.data;
  },

  async startPreparation(id: string, payload: StartPreparationPayload): Promise<PreparationWave> {
    const { data } = await api.post<ApiResponse<PreparationWave>>(`${BASE}/waves/${id}/start`, payload);
    return data.data;
  },

  async advanceWave(id: string): Promise<PreparationWave> {
    const { data } = await api.post<ApiResponse<PreparationWave>>(`${BASE}/waves/${id}/advance`);
    return data.data;
  },

  async completeItem(waveId: string, itemId: string, payload: CompleteProductPayload): Promise<PreparationWave> {
    const { data } = await api.patch<ApiResponse<PreparationWave>>(
      `${BASE}/waves/${waveId}/items/${itemId}/complete`,
      payload,
    );
    return data.data;
  },

  async completeWave(id: string): Promise<PreparationWave> {
    const { data } = await api.post<ApiResponse<PreparationWave>>(`${BASE}/waves/${id}/complete`);
    return data.data;
  },

  async cancelWave(id: string, payload: CancelWavePayload): Promise<PreparationWave> {
    const { data } = await api.post<ApiResponse<PreparationWave>>(`${BASE}/waves/${id}/cancel`, payload);
    return data.data;
  },

  async recalculateWave(id: string, payload: RecalculateWavePayload): Promise<PreparationWave> {
    const { data } = await api.post<ApiResponse<PreparationWave>>(`${BASE}/waves/${id}/recalculate`, payload);
    return data.data;
  },

  // ── Wave enterprise actions ──────────────────────────────────────────────────

  async approveWave(id: string, payload: ApproveWavePayload = {}): Promise<PreparationWave> {
    const { data } = await api.post<ApiResponse<PreparationWave>>(`${BASE}/waves/${id}/approve`, payload);
    return data.data;
  },

  async assignWorker(waveId: string, payload: AssignWorkerPayload): Promise<PreparationWave> {
    const { data } = await api.post<ApiResponse<PreparationWave>>(`${BASE}/waves/${waveId}/workers`, payload);
    return data.data;
  },

  async releaseWorker(waveId: string, userId: string): Promise<PreparationWave> {
    const { data } = await api.delete<ApiResponse<PreparationWave>>(`${BASE}/waves/${waveId}/workers/${userId}`);
    return data.data;
  },

  async resolveShortage(waveId: string, payload: ResolveShortagePayload): Promise<PreparationWave> {
    const { data } = await api.post<ApiResponse<PreparationWave>>(`${BASE}/waves/${waveId}/resolve-shortage`, payload);
    return data.data;
  },

  async updatePoolQuality(poolId: string, payload: UpdatePoolQualityPayload): Promise<PreparedPoolEntry> {
    const { data } = await api.patch<ApiResponse<PreparedPoolEntry>>(`${BASE}/pool/${poolId}/quality`, payload);
    return data.data;
  },

  // ── Wave timeline / documents ────────────────────────────────────────────────

  async getWaveTimeline(waveId: string): Promise<TimelineEntry[]> {
    const { data } = await api.get<ApiResponse<TimelineEntry[]>>(`${BASE}/waves/${waveId}/timeline`);
    return data.data;
  },

  async listWaveDocuments(waveId: string): Promise<DocumentEntry[]> {
    const { data } = await api.get<ApiResponse<DocumentEntry[]>>(`${BASE}/waves/${waveId}/documents`);
    return data.data;
  },

  // ── Pool ────────────────────────────────────────────────────────────────────

  async listPool(params: PoolQuery): Promise<PoolResult> {
    const { data } = await api.get<ApiResponse<PoolResult>>(`${BASE}/pool`, { params: clean(params) });
    return data.data;
  },

  // ── Workers ─────────────────────────────────────────────────────────────────

  async listWorkers(params: { warehouse_id: string; planning_date?: string }): Promise<WorkerStatus[]> {
    const { data } = await api.get<ApiResponse<WorkerStatus[]>>(`${BASE}/workers`, { params: clean(params) });
    return data.data;
  },

  // ── Stations ────────────────────────────────────────────────────────────────

  async listStations(params: { warehouse_id: string; status?: string }): Promise<PreparationStation[]> {
    const { data } = await api.get<ApiResponse<PreparationStation[]>>(`${BASE}/stations`, { params: clean(params) });
    return data.data;
  },

  // ── Product Workspace ────────────────────────────────────────────────────────

  async getProductQueue(waveId: string, params: { status?: string } = {}): Promise<{ items: unknown[] }> {
    const { data } = await api.get<ApiResponse<{ items: unknown[] }>>(`${BASE}/waves/${waveId}/product-queue`, { params: clean(params) });
    return data.data;
  },

  async getProductWorkspace(waveId: string, itemId: string): Promise<ProductWorkspace> {
    const { data } = await api.get<ApiResponse<ProductWorkspace>>(`${BASE}/waves/${waveId}/items/${itemId}/workspace`);
    return data.data;
  },

  async reportIssue(waveId: string, payload: ReportIssuePayload): Promise<void> {
    await api.post(`${BASE}/waves/${waveId}/issues`, payload);
  },

  // ── Sessions (CR-PREP-001) ───────────────────────────────────────────────────

  async listSessions(params: SessionsQuery = {}): Promise<SessionsResult> {
    const { data } = await api.get<ApiResponse<SessionsResult>>(`${BASE}/sessions`, { params: clean(params) });
    return data.data;
  },

  async getSession(id: string): Promise<PreparationSession> {
    const { data } = await api.get<ApiResponse<PreparationSession>>(`${BASE}/sessions/${id}`);
    return data.data;
  },

  async createSession(payload: CreateSessionPayload): Promise<PreparationSession> {
    const { data } = await api.post<ApiResponse<PreparationSession>>(`${BASE}/sessions`, payload);
    return data.data;
  },

  async startSession(id: string): Promise<PreparationSession> {
    const { data } = await api.post<ApiResponse<PreparationSession>>(`${BASE}/sessions/${id}/start`);
    return data.data;
  },

  async planSession(id: string): Promise<PreparationSession> {
    const { data } = await api.post<ApiResponse<PreparationSession>>(`${BASE}/sessions/${id}/plan`);
    return data.data;
  },

  async completeSession(id: string): Promise<PreparationSession> {
    const { data } = await api.post<ApiResponse<PreparationSession>>(`${BASE}/sessions/${id}/complete`);
    return data.data;
  },

  async approveSession(id: string): Promise<PreparationSession> {
    const { data } = await api.post<ApiResponse<PreparationSession>>(`${BASE}/sessions/${id}/approve`);
    return data.data;
  },

  async closeSession(id: string): Promise<PreparationSession> {
    const { data } = await api.post<ApiResponse<PreparationSession>>(`${BASE}/sessions/${id}/close`);
    return data.data;
  },

  async cancelSession(id: string, payload: CancelSessionPayload): Promise<PreparationSession> {
    const { data } = await api.post<ApiResponse<PreparationSession>>(`${BASE}/sessions/${id}/cancel`, payload);
    return data.data;
  },

  async freezeSession(id: string): Promise<PreparationSession> {
    const { data } = await api.post<ApiResponse<PreparationSession>>(`${BASE}/sessions/${id}/freeze`);
    return data.data;
  },

  async addWaveToSession(sessionId: string, payload: AddWaveToSessionPayload): Promise<PreparationSession> {
    const { data } = await api.post<ApiResponse<PreparationSession>>(`${BASE}/sessions/${sessionId}/waves`, payload);
    return data.data;
  },

  async getConsolidation(sessionId: string): Promise<SessionConsolidation> {
    const { data } = await api.get<ApiResponse<SessionConsolidation>>(`${BASE}/sessions/${sessionId}/consolidation`);
    return data.data;
  },

  async getSessionProducts(sessionId: string): Promise<SessionProduct[]> {
    const { data } = await api.get<ApiResponse<SessionProduct[]>>(`${BASE}/sessions/${sessionId}/products`);
    return data.data;
  },

  async getSessionOrders(sessionId: string, params: { per_page?: number; page?: number } = {}): Promise<SessionOrdersResult> {
    const { data } = await api.get<ApiResponse<SessionOrdersResult>>(`${BASE}/sessions/${sessionId}/orders`, { params: clean(params) });
    return data.data;
  },

  async attachOrderToSession(sessionId: string, orderId: string): Promise<void> {
    await api.post(`${BASE}/sessions/${sessionId}/orders`, { order_id: orderId });
  },

  async detachOrderFromSession(sessionId: string, sessionOrderId: string, reason: string): Promise<void> {
    await api.delete(`${BASE}/sessions/${sessionId}/orders/${sessionOrderId}`, { data: { reason } });
  },

  // ── Today Sessions ───────────────────────────────────────────────────────────

  async getTodaySessions(params: { date?: string } = {}): Promise<TodaySessionsResponse> {
    const { data } = await api.get<ApiResponse<TodaySessionsResponse>>(`${BASE}/today`, { params: clean(params) });
    return data.data;
  },

  // ── Assignment Policies ──────────────────────────────────────────────────────

  async listAssignmentPolicies(params: { warehouse_id?: string; is_active?: boolean } = {}): Promise<AssignmentPolicy[]> {
    const { data } = await api.get<ApiResponse<AssignmentPolicy[]>>(`${BASE}/assignment-policies`, { params: clean(params) });
    return data.data;
  },

  async createAssignmentPolicy(payload: CreateAssignmentPolicyPayload): Promise<AssignmentPolicy> {
    const { data } = await api.post<ApiResponse<AssignmentPolicy>>(`${BASE}/assignment-policies`, payload);
    return data.data;
  },

  async deleteAssignmentPolicy(id: string): Promise<void> {
    await api.delete(`${BASE}/assignment-policies/${id}`);
  },

  /**
   * Manually assign (or re-assign) an Order's warehouse.
   *
   * PATH IS DELIBERATELY OUTSIDE `BASE`. The route is registered at
   * `api/orders/{order}/override-warehouse` — the root authenticated group, next to the
   * other order verbs — not under the `preparation` prefix. Built from BASE it resolved
   * to `/preparation/orders/...`, which is not a registered URI, so every call 404'd.
   * The method had no caller, so nothing regressed; it is corrected here rather than
   * duplicated, keeping ONE client for this endpoint.
   *
   * The server is the authority on all of it: `sales.orders.update`, the Order tenant
   * scope, and an explicit same-company check on the target warehouse. `reason` is
   * required (min 10 chars) because the engine writes it to the audit trail.
   */
  async overrideWarehouse(orderId: string, payload: OverrideWarehousePayload): Promise<void> {
    await api.post(`/orders/${orderId}/override-warehouse`, payload);
  },

  // ── Enterprise (Phases 6, 8, 9, 13) ─────────────────────────────────────────

  async getEnterpriseQueue(params: { planning_date?: string; warehouse_id?: string; wave_id?: string } = {}): Promise<EnterpriseQueueResult> {
    const { data } = await api.get<ApiResponse<EnterpriseQueueResult>>(`${BASE}/enterprise/queue`, { params: clean(params) });
    return data.data;
  },

  async getCapacityPlanning(params: { planning_date?: string; warehouse_id?: string } = {}): Promise<CapacityPlanningResult> {
    const { data } = await api.get<ApiResponse<CapacityPlanningResult>>(`${BASE}/enterprise/capacity`, { params: clean(params) });
    return data.data;
  },

  async getOptimizationSuggestions(params: { planning_date?: string; warehouse_id?: string } = {}): Promise<OptimizationSuggestion[]> {
    const { data } = await api.get<ApiResponse<OptimizationSuggestion[]>>(`${BASE}/enterprise/optimization`, { params: clean(params) });
    return data.data;
  },

  async getEnterpriseDashboard(params: { planning_date?: string; warehouse_id?: string } = {}): Promise<EnterpriseDashboardResult> {
    const { data } = await api.get<ApiResponse<EnterpriseDashboardResult>>(`${BASE}/enterprise/dashboard`, { params: clean(params) });
    return data.data;
  },

  // ── Demand Engine read models (TASK-PREP-INTEGRATION-001) ────────────────────

  async getWaveKpis(waveId: string): Promise<WaveKpiReadModel> {
    const { data } = await api.get<ApiResponse<WaveKpiReadModel>>(`${BASE}/waves/${waveId}/kpis`);
    return data.data;
  },

  async getWaveProductDemand(waveId: string): Promise<WaveProductDemandItem[]> {
    const { data } = await api.get<ApiResponse<WaveProductDemandItem[]>>(`${BASE}/waves/${waveId}/product-demand`);
    return data.data;
  },

  async getWaveMaterialDemand(waveId: string): Promise<WaveMaterialDemandItem[]> {
    const { data } = await api.get<ApiResponse<WaveMaterialDemandItem[]>>(`${BASE}/waves/${waveId}/material-demand`);
    return data.data;
  },

  async getWaveMissingMaterials(waveId: string): Promise<WaveMissingMaterialItem[]> {
    const { data } = await api.get<ApiResponse<WaveMissingMaterialItem[]>>(`${BASE}/waves/${waveId}/missing-materials`);
    return data.data;
  },

  /**
   * Procurement's Expected Incoming for one missing material.
   *
   * PLANNING ONLY — it saves a single number. It does not receive stock, create a goods
   * receipt or a stock movement, touch reservations, or change the real missing_qty.
   */
  async updateExpectedIncoming(waveId: string, materialId: string, expectedQty: number): Promise<void> {
    await api.put(`${BASE}/waves/${waveId}/missing-materials/${materialId}/expected-incoming`, {
      expected_qty: expectedQty,
    });
  },

  /** Clears `postponed_at` on the retained membership row. UPDATE only — never an insert. */
  async returnOrderToPreparation(waveId: string, orderId: string): Promise<void> {
    await api.post(`${BASE}/waves/${waveId}/orders/${orderId}/return-to-preparation`);
  },

  async getWaveManufacturingDemand(waveId: string): Promise<WaveManufacturingDemandItem[]> {
    const { data } = await api.get<ApiResponse<WaveManufacturingDemandItem[]>>(`${BASE}/waves/${waveId}/manufacturing-demand`);
    return data.data;
  },

  /** "قرارات العجز" — orders/products whose material shortage is not covered by open POs. */
  async getDeficitDecisions(waveId: string): Promise<DeficitDecisionsResponse> {
    const { data } = await api.get<ApiResponse<DeficitDecisionsResponse>>(`${BASE}/waves/${waveId}/deficit-decisions`);
    return data.data;
  },

  /**
   * "استمرار العملية رغم العجز" — continue this product despite the shortage.
   * Records the operator decision on the product-demand row; never deletes the order line.
   */
  async continueDespiteShortage(waveId: string, productId: string): Promise<void> {
    await api.post(`${BASE}/waves/${waveId}/product-demand/${productId}/continue-despite-shortage`);
  },

  async getWaveOrders(waveId: string): Promise<WaveOrderEntry[]> {
    const { data } = await api.get<ApiResponse<WaveOrderEntry[]>>(`${BASE}/waves/${waveId}/orders`);
    return data.data;
  },

  /**
   * Postpone an order out of the current preparation cycle.
   *
   * Not a delete: the backend retains the membership row with `postponed_at` stamped, so
   * the order leaves today's aggregation while its history — and the order itself — remain.
   */
  async postponeWaveOrder(waveId: string, orderId: string): Promise<void> {
    await api.post(`${BASE}/waves/${waveId}/orders/${orderId}/postpone`);
  },

  /**
   * Product-level Prepared. The operator states ONE number per product; it is never
   * distributed across order lines and `order_lines.prepared_qty` is not touched.
   */
  async updateProductPrepared(waveId: string, productId: string, preparedQty: number): Promise<WaveProductDemandItem> {
    const { data } = await api.patch<ApiResponse<WaveProductDemandItem>>(
      `${BASE}/waves/${waveId}/product-demand/${productId}/prepared`,
      { prepared_qty: preparedQty },
    );
    return data.data;
  },

  /**
   * Explicit "preparation finished" declaration. Deliberately separate from editing
   * Prepared — reaching Required is not the same as the operator declaring it done.
   */
  async completeProductPreparation(waveId: string, productId: string): Promise<WaveProductDemandItem> {
    const { data } = await api.post<ApiResponse<WaveProductDemandItem>>(
      `${BASE}/waves/${waveId}/product-demand/${productId}/complete`,
    );
    return data.data;
  },

  /**
   * Withdraw the completion declaration. Prepared is untouched — only the claim is
   * withdrawn, so the floor's number survives.
   */
  async uncompleteProductPreparation(waveId: string, productId: string): Promise<WaveProductDemandItem> {
    const { data } = await api.post<ApiResponse<WaveProductDemandItem>>(
      `${BASE}/waves/${waveId}/product-demand/${productId}/uncomplete`,
    );
    return data.data;
  },

  async getProductRelatedOrders(waveId: string, productId: string): Promise<ProductRelatedOrder[]> {
    const { data } = await api.get<ApiResponse<ProductRelatedOrder[]>>(
      `${BASE}/waves/${waveId}/product-demand/${productId}/orders`,
    );
    return data.data;
  },

  async getMaterialRelatedOrders(waveId: string, materialId: string): Promise<MaterialRelatedOrder[]> {
    const { data } = await api.get<ApiResponse<MaterialRelatedOrder[]>>(
      `${BASE}/waves/${waveId}/missing-materials/${materialId}/orders`,
    );
    return data.data;
  },
};

function clean(params: Record<string, unknown>): Record<string, unknown> {
  return Object.fromEntries(
    Object.entries(params).filter(([, v]) => v !== undefined && v !== '' && v !== 'all'),
  );
}
