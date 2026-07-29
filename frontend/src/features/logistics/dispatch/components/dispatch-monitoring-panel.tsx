import {
  useAssignmentHealth,
  useCapacityUtilisation,
  useDispatchKpis,
  useQueueStatistics,
} from '../hooks/use-dispatch-ops';

/**
 * A rate with no denominator is not zero — it is unknown. Saying "0%" when
 * nothing was attempted invites a decision that the data does not support.
 */
function percent(value: number | null | undefined): string {
  if (value === null || value === undefined) return 'No data yet';
  return `${Math.round(value * 100)}%`;
}

function minutes(value: number | null | undefined): string {
  if (value === null || value === undefined) return 'No data yet';
  return `${value} min`;
}

function Panel({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div className="rounded-lg border bg-card p-4">
      <h3 className="mb-3 text-sm font-medium">{title}</h3>
      <dl className="space-y-1.5 text-xs">{children}</dl>
    </div>
  );
}

function Row({ label, value }: { label: string; value: string | number | null | undefined }) {
  return (
    <div className="flex items-center justify-between gap-4">
      <dt className="text-muted-foreground">{label}</dt>
      <dd className="tabular-nums">{value ?? '—'}</dd>
    </div>
  );
}

/**
 * Operational metrics only — what happened, never what will happen.
 */
export function DispatchMonitoringPanel() {
  const { data: kpis } = useDispatchKpis();
  const { data: queue } = useQueueStatistics();
  const { data: health } = useAssignmentHealth();
  const { data: capacity } = useCapacityUtilisation();

  return (
    <div className="grid gap-4 md:grid-cols-2">
      <Panel title="Dispatch">
        <Row label="Sessions opened" value={kpis?.sessions_opened} />
        <Row label="Sessions active" value={kpis?.sessions_active} />
        <Row label="Sessions abandoned" value={kpis?.sessions_abandoned} />
        <Row label="Allocations attempted" value={kpis?.allocations_attempted} />
        <Row label="Allocations confirmed" value={kpis?.allocations_confirmed} />
        <Row label="Allocations failed" value={kpis?.allocations_failed} />
        <Row label="Confirmation rate" value={percent(kpis?.confirmation_rate)} />
        <Row label="Automatic share" value={percent(kpis?.automatic_share)} />
        <Row label="Average session length" value={minutes(kpis?.avg_session_minutes)} />
      </Panel>

      <Panel title="Queue">
        <Row label="Depth" value={queue?.depth} />
        <Row label="Needs action" value={queue?.needs_action} />
        <Row label="Stuck" value={queue?.stuck} />
        <Row label="Average wait" value={minutes(queue?.avg_wait_minutes)} />
        <Row label="Longest wait" value={minutes(queue?.oldest_wait_minutes)} />
      </Panel>

      <Panel title="Assignment health">
        <Row label="Open conflicts" value={health?.open_conflicts} />
        <Row label="Blocking conflicts" value={health?.blocking_conflicts} />
        <Row label="Oldest conflict" value={minutes(health?.oldest_conflict_minutes)} />
        <Row label="Pending reviews" value={health?.pending_reviews} />
        <Row label="Oldest review" value={minutes(health?.oldest_review_minutes)} />
        <Row label="Held resources" value={health?.held_locks} />
      </Panel>

      {/* Read from Network's ledger. Dispatch reports it; it does not own it. */}
      <Panel title="Capacity (owned by Network)">
        <Row label="Slots" value={capacity?.slot_count} />
        <Row label="Average utilisation" value={percent(capacity?.avg_utilisation)} />
        <Row label="Near capacity" value={capacity?.at_warn_threshold} />
        <Row label="Exhausted" value={capacity?.exhausted} />
      </Panel>
    </div>
  );
}
