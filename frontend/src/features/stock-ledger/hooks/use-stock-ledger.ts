import { keepPreviousData, useQuery } from '@tanstack/react-query';

import { stockLedgerService } from '@/features/stock-ledger/services/stock-ledger-service';
import type { StockMovementsQuery } from '@/features/stock-ledger/types/stock-movement';

export const STOCK_LEDGER_KEY = 'stock-movements';

export function useStockMovementsQuery(params: StockMovementsQuery) {
  return useQuery({
    queryKey: [STOCK_LEDGER_KEY, params],
    queryFn: () => stockLedgerService.list(params),
    placeholderData: keepPreviousData,
  });
}

export function useStockMovementQuery(id: string) {
  return useQuery({
    queryKey: [STOCK_LEDGER_KEY, id],
    queryFn: () => stockLedgerService.get(id),
    enabled: Boolean(id),
  });
}
