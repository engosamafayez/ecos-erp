import { useTranslation } from 'react-i18next';

import type enLogistics from '@/i18n/locales/en/logistics.json';

import type { TripStatus } from '../types/trip';

type LogisticsLabel = ($: typeof enLogistics) => string;

/**
 * Every trip status has its own translated label, so the backend's English
 * `status_label` / `allowed_transitions[].label` is never rendered directly —
 * those are a fallback for surfaces that have no translation layer, and using
 * them here would leak English into Arabic. Shared by TripStatusBadge and
 * TripDrawer so both stay derived from the same map.
 */
export const TRIP_STATUS_LABEL: Record<TripStatus, LogisticsLabel> = {
  planning: ($) => $.trips.status.planning,
  loading: ($) => $.trips.status.loading,
  loading_completed: ($) => $.trips.status.loading_completed,
  driver_accepted: ($) => $.trips.status.driver_accepted,
  dispatch_blocked: ($) => $.trips.status.dispatch_blocked,
  ready_for_dispatch: ($) => $.trips.status.ready_for_dispatch,
  dispatched: ($) => $.trips.status.dispatched,
  out_for_delivery: ($) => $.trips.status.out_for_delivery,
  in_progress: ($) => $.trips.status.in_progress,
  completed: ($) => $.trips.status.completed,
  settlement_pending: ($) => $.trips.status.settlement_pending,
  closed: ($) => $.trips.status.closed,
  cancelled: ($) => $.trips.status.cancelled,
};

export function useTripStatusLabel() {
  const { t } = useTranslation('logistics');
  return (status: TripStatus) => t(TRIP_STATUS_LABEL[status]);
}
