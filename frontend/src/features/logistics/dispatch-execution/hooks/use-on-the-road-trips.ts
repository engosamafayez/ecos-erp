import { useTrips } from '@/features/logistics/trips/hooks/use-trips';
import type { Trip } from '@/features/logistics/trips/types/trip';

/** Rows fetched PER status. Bounded so the coordination view stays light. */
const PER_STATUS_LIMIT = 10;

/**
 * "On the road" trips for the Active Trips tab — dispatched, out_for_delivery,
 * in_progress.
 *
 * `TripStats.on_the_road` is a backend STATS rollup of these three statuses (see
 * its own docblock: "It is not a status, and is not filterable as one"), and
 * `TripsQuery.status` accepts exactly one `TripStatus`. So this calls the
 * EXISTING `useTrips` hook once per real status — the same hook and the same
 * `/logistics/distribution/trips` endpoint the Trips Workspace itself uses — and
 * merges the three real result sets. Nothing is re-derived: each list is exactly
 * what `tripService.list({ status })` returned; this only interleaves them by
 * recency for display.
 */
export function useOnTheRoadTrips() {
  const dispatched = useTrips({ status: 'dispatched', per_page: PER_STATUS_LIMIT });
  const outForDelivery = useTrips({ status: 'out_for_delivery', per_page: PER_STATUS_LIMIT });
  const inProgress = useTrips({ status: 'in_progress', per_page: PER_STATUS_LIMIT });

  const isLoading = dispatched.isLoading || outForDelivery.isLoading || inProgress.isLoading;
  const isError = dispatched.isError || outForDelivery.isError || inProgress.isError;

  const trips: Trip[] = [
    ...(dispatched.data?.data ?? []),
    ...(outForDelivery.data?.data ?? []),
    ...(inProgress.data?.data ?? []),
  ].sort((a, b) => {
    // Most recently dispatched first — a plain recency sort, not a classification.
    const aTime = a.dispatched_at ? new Date(a.dispatched_at).getTime() : 0;
    const bTime = b.dispatched_at ? new Date(b.dispatched_at).getTime() : 0;
    return bTime - aTime;
  });

  function refetch() {
    void dispatched.refetch();
    void outForDelivery.refetch();
    void inProgress.refetch();
  }

  return { trips, isLoading, isError, refetch };
}
