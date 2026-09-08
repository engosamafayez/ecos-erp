import { useQuery } from '@tanstack/react-query';

import { liveMapService } from '../services/live-map-service';

const KEY = 'shipping-live-map';

/**
 * TASK-ECOS-SHIPPING-OS-REDESIGN-004 §7 — bounded polling, one bulk refresh.
 * No per-marker requests: every marker on the map is drawn from this single
 * query's result. `refetchIntervalInBackground` is left at its library
 * default (false), so polling pauses while the tab is hidden — the same
 * "visibility pause" behaviour every other polling hook in this codebase
 * relies on implicitly.
 */
export function useLiveMap() {
  return useQuery({
    queryKey: [KEY, 'index'],
    queryFn: () => liveMapService.liveMap(),
    refetchInterval: 15_000,
    staleTime: 0,
  });
}

export function useRouteHistory(tripId: string | null, limit?: number) {
  return useQuery({
    queryKey: [KEY, 'history', tripId, limit],
    queryFn: () => liveMapService.routeHistory(tripId as string, limit),
    enabled: tripId !== null,
  });
}
