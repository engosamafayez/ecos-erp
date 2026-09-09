import { Badge } from '@/components/ui/badge';

import type { TripStatus } from '../types/trip';
import { useTripStatusLabel } from '../lib/trip-status-label';

/**
 * Status colours follow the operational meaning of the state, not its position
 * in the sequence: anything blocking dispatch reads as a problem, anything on
 * the road reads as active, and terminal states are muted.
 */
const STATUS_CLASS: Record<TripStatus, string> = {
  planning: 'bg-muted text-muted-foreground',
  loading: 'bg-sky-500/10 text-sky-600 dark:text-sky-400',
  loading_completed: 'bg-sky-500/10 text-sky-600 dark:text-sky-400',
  driver_accepted: 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400',
  dispatch_blocked: 'bg-destructive/10 text-destructive',
  ready_for_dispatch: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
  dispatched: 'bg-blue-500/10 text-blue-600 dark:text-blue-400',
  out_for_delivery: 'bg-blue-500/10 text-blue-600 dark:text-blue-400',
  in_progress: 'bg-blue-500/10 text-blue-600 dark:text-blue-400',
  completed: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
  settlement_pending: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
  closed: 'bg-muted text-muted-foreground',
  cancelled: 'bg-muted text-muted-foreground line-through',
};

export function TripStatusBadge({ status }: { status: TripStatus }) {
  const label = useTripStatusLabel();

  return (
    <Badge variant="secondary" className={STATUS_CLASS[status]}>
      {label(status)}
    </Badge>
  );
}
