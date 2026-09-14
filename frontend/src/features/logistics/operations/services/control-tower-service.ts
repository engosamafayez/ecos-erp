import { api } from '@/lib/axios';
import type { Paginated } from '../types/operations';
import type {
  CustodySummary,
  ExpectedReturnRow,
  ExternalCarrierSummary,
  ReturnsSummary,
  SettlementSummary,
  ShippingSummary,
} from '../types/control-tower';

/**
 * TASK-ECOS-V1.1-OPS-04-TASK2 — the Task 1 Control Tower read model.
 * Same base/permission group as operationsService; additive only.
 */
const BASE = '/logistics/operations';

export const controlTowerService = {
  async shipping(): Promise<ShippingSummary> {
    const { data } = await api.get<{ data: ShippingSummary }>(`${BASE}/summary/shipping`);
    return data.data;
  },

  async custody(): Promise<CustodySummary> {
    const { data } = await api.get<{ data: CustodySummary }>(`${BASE}/summary/custody`);
    return data.data;
  },

  async returns(): Promise<ReturnsSummary> {
    const { data } = await api.get<{ data: ReturnsSummary }>(`${BASE}/summary/returns`);
    return data.data;
  },

  async settlement(): Promise<SettlementSummary> {
    const { data } = await api.get<{ data: SettlementSummary }>(`${BASE}/summary/settlement`);
    return data.data;
  },

  async externalCarrier(): Promise<ExternalCarrierSummary> {
    const { data } = await api.get<{ data: ExternalCarrierSummary }>(
      `${BASE}/summary/external-carrier`,
    );
    return data.data;
  },

  async expectedReturns(page = 1, perPage = 25): Promise<Paginated<ExpectedReturnRow>> {
    const { data } = await api.get<Paginated<ExpectedReturnRow>>(`${BASE}/expected-returns`, {
      params: { page, per_page: perPage },
    });
    return data;
  },
};
