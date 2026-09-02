import { useQuery } from '@tanstack/react-query';

import { useOrganizationContext } from '@/features/organization/context/organization-context';

import { financeDriverLedgerService } from '../services/finance-driver-ledger-service';
import type { DriverLedgerParams } from '../types/finance-driver-ledger';

/**
 * React-query hooks for the Driver Ledger tab (Costing & Profitability).
 * Company-scoped keys. Both queries are lazy — enabled only once a driver id
 * has been entered — mirroring use-finance-ap.ts's useSupplierLedger exactly.
 */
function useCompanyId() {
  const { activeCompanyId } = useOrganizationContext();
  return activeCompanyId ?? 'global';
}

export function useDriverLedger(driverId: string | null, params: DriverLedgerParams = {}) {
  const companyId = useCompanyId();
  return useQuery({
    queryKey: ['company', companyId, 'finance', 'driver-ledger', driverId, params],
    queryFn: () => financeDriverLedgerService.ledger(driverId as string, params),
    enabled: Boolean(driverId),
  });
}

export function useDriverBalance(driverId: string | null) {
  const companyId = useCompanyId();
  return useQuery({
    queryKey: ['company', companyId, 'finance', 'driver-balance', driverId],
    queryFn: () => financeDriverLedgerService.balance(driverId as string),
    enabled: Boolean(driverId),
  });
}
