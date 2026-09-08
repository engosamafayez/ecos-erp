import { api } from '@/lib/axios';
import type { LiveMapTrip, RouteHistory } from '../types/live-map';

const BASE = '/logistics/distribution';

/**
 * TASK-ECOS-SHIPPING-OS-REDESIGN-004 §8/§13 — Live Driver Map and Route
 * History reads. Both are read-only; no second tracking engine, no client
 * write path beyond the existing Driver App GPS endpoint.
 */
export const liveMapService = {
  async liveMap(): Promise<LiveMapTrip[]> {
    const { data } = await api.get<{ data: LiveMapTrip[] }>(`${BASE}/live-map`);
    return data.data;
  },

  async routeHistory(tripId: string, limit?: number): Promise<RouteHistory> {
    const { data } = await api.get<RouteHistory>(`${BASE}/trips/${tripId}/location-history`, {
      params: limit ? { limit } : undefined,
    });
    return data;
  },
};
