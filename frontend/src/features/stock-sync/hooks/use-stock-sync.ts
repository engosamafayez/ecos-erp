import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { stockSyncService } from '@/features/stock-sync/services/stock-sync-service';
import type { StockSyncLogsQuery, SyncStockResult } from '@/features/stock-sync/types/stock-sync';

export const STOCK_SYNC_LOGS_KEY = 'stock-sync-logs';

export function useStockSyncLogsQuery(params: StockSyncLogsQuery) {
  return useQuery({
    queryKey: [STOCK_SYNC_LOGS_KEY, params],
    queryFn: () => stockSyncService.listLogs(params),
    placeholderData: keepPreviousData,
  });
}

export function useSyncStock() {
  const queryClient = useQueryClient();
  return useMutation<SyncStockResult, Error, string>({
    mutationFn: (channelId: string) => stockSyncService.syncStock(channelId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: [STOCK_SYNC_LOGS_KEY] });
      queryClient.invalidateQueries({ queryKey: ['channels'] });
    },
  });
}
