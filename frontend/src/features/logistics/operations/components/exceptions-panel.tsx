import { useState } from 'react';
import { ChevronRight, Repeat2, ShieldAlert } from 'lucide-react';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';

import { useAlerts, useExceptionSummary, useExceptions } from '../hooks/use-operations';
import type { ExceptionSeverity } from '../types/operations';
import { ExceptionStatusBadge, SeverityIcon, SourceBadge } from './operations-badges';
import { ExceptionDrawer } from './exception-drawer';

const SEVERITY_FILTERS = [
  { key: 'all', label: 'All' },
  { key: 'critical', label: 'Critical' },
  { key: 'warning', label: 'Warning' },
  { key: 'info', label: 'Info' },
] as const;

type SeverityFilterKey = (typeof SEVERITY_FILTERS)[number]['key'];

/**
 * The one queue, whatever produced the problem.
 *
 * Ordered loudest first then longest waiting — the order an operator would work
 * it. Sorting by recency would bury a critical problem from an hour ago under a
 * stream of routine noise.
 */
export function ExceptionsPanel() {
  const [severity, setSeverity] = useState<SeverityFilterKey>('all');
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [drawerOpen, setDrawerOpen] = useState(false);

  const { data, isLoading } = useExceptions({
    outstanding_only: true,
    severity: severity === 'all' ? undefined : (severity as ExceptionSeverity),
  });
  const { data: summary } = useExceptionSummary();
  const { data: alerts } = useAlerts();

  const rows = data?.data ?? [];
  const bySource = Object.entries(summary?.by_source ?? {}).filter(([s]) => s !== 'operations');

  return (
    <div className="space-y-4">
      {alerts && alerts.length > 0 && (
        <Alert>
          <ShieldAlert className="size-4" />
          <AlertDescription className="text-xs">
            {alerts.length} alert{alerts.length === 1 ? '' : 's'} live
            {summary && summary.overdue_for_escalation > 0
              ? ` · ${summary.overdue_for_escalation} past their escalation threshold`
              : ''}
            .{' '}
            {bySource.length > 0 &&
              `Owned elsewhere — ${bySource.map(([s, n]) => `${s}: ${n}`).join(', ')}.`}
          </AlertDescription>
        </Alert>
      )}

      <div className="flex flex-wrap items-center gap-2">
        {SEVERITY_FILTERS.map((f) => (
          <Button
            key={f.key}
            size="sm"
            variant={severity === f.key ? 'secondary' : 'ghost'}
            className="h-8 text-xs"
            onClick={() => setSeverity(f.key)}
          >
            {f.label}
          </Button>
        ))}
      </div>

      {isLoading ? (
        <Skeleton className="h-64 w-full" />
      ) : rows.length === 0 ? (
        <div className="rounded-lg border bg-card py-16 text-center">
          <p className="text-sm font-medium">Nothing needs a person</p>
          <p className="mt-1 text-xs text-muted-foreground">
            The queue is empty, which is what a healthy operation looks like.
          </p>
        </div>
      ) : (
        <div className="overflow-hidden rounded-lg border bg-card">
          <ul className="divide-y">
            {rows.map((exception) => (
              <li key={exception.id}>
                <button
                  type="button"
                  className="flex w-full items-start gap-2 p-3 text-left hover:bg-muted/40"
                  onClick={() => {
                    setSelectedId(exception.id);
                    setDrawerOpen(true);
                  }}
                >
                  <SeverityIcon severity={exception.severity} />
                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-1.5">
                      <span className="text-sm font-medium">{exception.title}</span>
                      {/* Where the fix actually lives. */}
                      <SourceBadge source={exception.source} label={exception.source_label} />
                      <ExceptionStatusBadge status={exception.status} />
                      {exception.is_recurring && (
                        <Badge variant="outline" className="gap-1 text-[10px]">
                          <Repeat2 className="size-2.5" />
                          {exception.occurrence_count}×
                        </Badge>
                      )}
                      {exception.is_overdue_for_escalation && (
                        <Badge variant="destructive" className="text-[10px]">
                          Overdue
                        </Badge>
                      )}
                    </div>
                    {exception.description && (
                      <p className="mt-0.5 truncate text-xs text-muted-foreground">
                        {exception.description}
                      </p>
                    )}
                    <p className="mt-0.5 text-[11px] text-muted-foreground">
                      {exception.category_label} · open {exception.age_minutes} min
                      {exception.unacknowledged_minutes !== null
                        ? ` · unlooked-at ${exception.unacknowledged_minutes} min`
                        : ' · acknowledged'}
                    </p>
                  </div>
                  <ChevronRight className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                </button>
              </li>
            ))}
          </ul>
        </div>
      )}

      <ExceptionDrawer exceptionId={selectedId} open={drawerOpen} onOpenChange={setDrawerOpen} />
    </div>
  );
}
