import { api } from '@/lib/axios';
import type { ApiResponse } from '@/types';

import type { DriverBalance, DriverLedger, DriverLedgerParams } from '../types/finance-driver-ledger';

/**
 * Finance Driver Ledger API client (Costing & Profitability), against the
 * already-implemented DriverLedgerController endpoints. Read-only; unwraps
 * the `{ data }` envelope. No backend changes.
 */
export const financeDriverLedgerService = {
  async ledger(driverId: string, params: DriverLedgerParams = {}): Promise<DriverLedger> {
    const { data } = await api.get<ApiResponse<DriverLedger>>(`/finance/drivers/${driverId}/ledger`, { params });
    return data.data;
  },

  async balance(driverId: string): Promise<DriverBalance> {
    const { data } = await api.get<ApiResponse<DriverBalance>>(`/finance/drivers/${driverId}/balance`);
    return data.data;
  },
};
