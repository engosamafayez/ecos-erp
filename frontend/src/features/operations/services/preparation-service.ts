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

  async getProductQueue(id: string): Promise<{ items: unknown[] }> {
    const { data } = await api.get<ApiResponse<{ items: unknown[] }>>(`${BASE}/waves/${id}/product-queue`);
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
};

function clean(params: Record<string, unknown>): Record<string, unknown> {
  return Object.fromEntries(
    Object.entries(params).filter(([, v]) => v !== undefined && v !== '' && v !== 'all'),
  );
}
