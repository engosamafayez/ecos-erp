import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { syncLogsService } from '@/features/sync-logs/services/sync-logs-service';
import type { SyncLogsListParams } from '@/features/sync-logs/types/sync-log';

export const SYNC_LOGS_KEY = 'sync-logs';

export function useSyncLogsQuery(params: SyncLogsListParams) {
  return useQuery({
    queryKey: [SYNC_LOGS_KEY, params],
    queryFn: () => syncLogsService.list(params),
    placeholderData: keepPreviousData,
  });
}

export function useRetrySyncLog() {
  const queryClient = useQueryClient();
  return useMutation<void, Error, string>({
    mutationFn: (id: string) => syncLogsService.retry(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: [SYNC_LOGS_KEY] });
    },
  });
}
