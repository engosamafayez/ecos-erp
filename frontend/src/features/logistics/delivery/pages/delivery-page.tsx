import { useState } from 'react';
import {
  AlertTriangle,
  CheckCircle,
  ChevronRight,
  Clock,
  PackageX,
  RotateCcw,
  Truck,
  Undo2,
} from 'lucide-react';

import { Pagination } from '@/components/crud';
import { WorkspaceHeader } from '@/components/workspace/header/workspace-header';
import { WorkspacePage } from '@/components/page/layout/workspace-page';
import { SmartToolbar } from '@/components/data-grid/smart-toolbar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';

import { useDeliveries, useDeliveryOptions, useDeliveryStats } from '../hooks/use-deliveries';
import type { Delivery, DeliveryStatus, FailureCategory } from '../types/delivery';
import { DeliveryDrawer } from '../components/delivery-drawer';
import { DeliveryStatusBadge } from '../components/delivery-status-badge';

// ── Skeleton ─────────────────────────────────────────────────────────────────

function TableSkeleton() {
  return (
    <div className="overflow-hidden rounded-lg border bg-card">
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b bg-muted/60">
            {['w-40', 'w-36', 'w-24', 'w-32', 'w-36', 'w-28', 'w-10'].map((w, i) => (
              <th key={i} className={`h-10 px-3 ${w}`} />
            ))}
          </tr>
        </thead>
        <tbody className="divide-y">
          {Array.from({ length: 6 }).map((_, i) => (
            <tr key={i}>
              <td className="px-3 py-2.5"><Skeleton className="h-4 w-32" /></td>
              <td className="px-3 py-2.5"><Skeleton className="h-5 w-28 rounded-full" /></td>
              <td className="px-3 py-2.5"><Skeleton className="h-4 w-12" /></td>
              <td className="px-3 py-2.5"><Skeleton className="h-4 w-24" /></td>
              <td className="px-3 py-2.5"><Skeleton className="h-4 w-28" /></td>
              <td className="px-3 py-2.5"><Skeleton className="h-4 w-20" /></td>
              <td className="px-3 py-2.5" />
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function EmptyDeliveries({ hasFilter }: { hasFilter: boolean }) {
  return (
    <div className="flex flex-col items-center justify-center rounded-lg border bg-card py-16 text-center">
      <Truck className="mb-3 size-12 text-muted-foreground/20" />
      <p className="text-sm font-medium">
        {hasFilter ? 'No deliveries match your filters' : 'Nothing needs attention'}
      </p>
      <p className="mt-1 text-xs text-muted-foreground">
        {hasFilter
          ? 'Try a different status or clear the exception filters.'
          : 'Open deliveries appear here as trips go out. Closed ones are hidden by default.'}
      </p>
    </div>
  );
}

// ── Table ────────────────────────────────────────────────────────────────────

function formatDateTime(value: string | null): string {
  if (!value) return '—';
  return new Date(value).toLocaleString(undefined, {
    day: '2-digit',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
  });
}

function DeliveriesTable({
  rows,
  isLoading,
  hasFilter,
  onRowClick,
}: {
  rows: Delivery[];
  isLoading: boolean;
  hasFilter: boolean;
  onRowClick: (delivery: Delivery) => void;
}) {
  if (isLoading) return <TableSkeleton />;
  if (rows.length === 0) return <EmptyDeliveries hasFilter={hasFilter} />;

  return (
    <div className="overflow-x-auto rounded-lg border bg-card">
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b bg-muted/60 text-left text-xs uppercase tracking-wide text-muted-foreground">
            <th className="h-10 px-3 font-medium">Order</th>
            <th className="h-10 px-3 font-medium">Status</th>
            <th className="h-10 px-3 text-center font-medium">Attempts</th>
            <th className="h-10 px-3 font-medium">Last failure</th>
            <th className="h-10 px-3 font-medium">Promised</th>
            <th className="h-10 px-3 font-medium">SLA</th>
            <th className="h-10 w-10 px-3" />
          </tr>
        </thead>
        <tbody className="divide-y">
          {rows.map((delivery) => {
            const failure = delivery.latest_attempt?.failure ?? null;

            return (
              <tr
                key={delivery.id}
                className="cursor-pointer hover:bg-muted/40"
                onClick={() => onRowClick(delivery)}
              >
                <td className="px-3 py-2.5 font-mono text-xs">{delivery.order_id}</td>
                <td className="px-3 py-2.5">
                  <DeliveryStatusBadge status={delivery.status} />
                </td>
                <td className="px-3 py-2.5 text-center tabular-nums">
                  <span className={delivery.remaining_attempts === 0 ? 'text-destructive' : ''}>
                    {delivery.attempt_count}/{delivery.max_attempts}
                  </span>
                </td>
                <td className="px-3 py-2.5 text-xs">
                  {failure ? (
                    <span className="text-muted-foreground">
                      {failure.reason_label}
                      {!failure.is_retryable && (
                        <Badge variant="destructive" className="ml-1.5 text-[10px]">
                          Final
                        </Badge>
                      )}
                    </span>
                  ) : (
                    '—'
                  )}
                </td>
                <td className="px-3 py-2.5 text-xs text-muted-foreground">
                  {formatDateTime(delivery.promised_at)}
                </td>
                <td className="px-3 py-2.5">
                  {delivery.sla_breached ? (
                    <Badge variant="destructive" className="gap-1 text-[10px]">
                      <Clock className="size-3" />
                      Breached
                    </Badge>
                  ) : (
                    <span className="text-xs text-muted-foreground">On time</span>
                  )}
                </td>
                <td className="px-3 py-2.5 text-right">
                  <ChevronRight className="size-4 text-muted-foreground" />
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

// ── Page ─────────────────────────────────────────────────────────────────────

const STATUS_FILTERS = [
  { key: 'open', label: 'Open' },
  { key: 'all', label: 'All' },
  { key: 'out_for_delivery', label: 'Out for delivery' },
  { key: 'awaiting_retry', label: 'Awaiting retry' },
  { key: 'failed', label: 'Failed' },
  { key: 'returning', label: 'Returning' },
] as const;

type StatusFilterKey = (typeof STATUS_FILTERS)[number]['key'];

/**
 * Delivery Command Center.
 *
 * Exception-driven by design: the default view hides closed deliveries and
 * leads with what is failing, breaching SLA or waiting on a retry decision.
 */
export function DeliveryPage() {
  const [orderId, setOrderId] = useState('');
  const [statusFilter, setStatusFilter] = useState<StatusFilterKey>('open');
  const [categoryFilter, setCategoryFilter] = useState<FailureCategory | 'all'>('all');
  const [slaOnly, setSlaOnly] = useState(false);
  const [page, setPage] = useState(1);

  const [drawerOpen, setDrawerOpen] = useState(false);
  const [selectedId, setSelectedId] = useState<string | null>(null);

  const params = {
    // 'open' is the API default (closed deliveries excluded), so it sends no
    // status at all; every other key is a concrete status filter.
    status:
      statusFilter === 'open'
        ? undefined
        : statusFilter === 'all'
          ? ('all' as const)
          : (statusFilter as DeliveryStatus),
    order_id: orderId || undefined,
    failure_category: categoryFilter === 'all' ? undefined : categoryFilter,
    sla_breached: slaOnly || undefined,
    page,
    per_page: 20,
  };

  const { data: stats } = useDeliveryStats();
  const { data: options } = useDeliveryOptions();
  const { data, isFetching, refetch } = useDeliveries(params);

  const deliveries = data?.data ?? [];
  const meta = data?.meta;
  const hasFilter = !!(orderId || statusFilter !== 'open' || categoryFilter !== 'all' || slaOnly);

  function resetPage<T>(setter: (v: T) => void) {
    return (v: T) => {
      setter(v);
      setPage(1);
    };
  }

  const metrics = [
    { id: 'out', icon: Truck, label: 'Out for Delivery', value: stats?.out_for_delivery ?? 0, isLoading: !stats },
    { id: 'delivered', icon: CheckCircle, label: 'Delivered', value: stats?.delivered ?? 0, isLoading: !stats, colorClass: 'text-emerald-600' },
    { id: 'failed', icon: PackageX, label: 'Failed', value: stats?.failed ?? 0, isLoading: !stats, colorClass: 'text-destructive' },
    { id: 'retry', icon: RotateCcw, label: 'Awaiting Retry', value: stats?.awaiting_retry ?? 0, isLoading: !stats, colorClass: 'text-amber-600' },
    { id: 'sla', icon: AlertTriangle, label: 'SLA Breached', value: stats?.sla_breached ?? 0, isLoading: !stats, colorClass: 'text-destructive' },
    { id: 'returning', icon: Undo2, label: 'Returning', value: stats?.returning ?? 0, isLoading: !stats },
  ];

  return (
    <>
      <WorkspaceHeader
        breadcrumbs={[{ label: 'Logistics OS' }, { label: 'Delivery' }]}
        title="Delivery & Tracking"
        description="Track every order's journey to the customer — attempts, proof, failures and retries"
        metrics={metrics}
      />

      <WorkspacePage
        toolbar={
          <div className="px-4 sm:px-6">
            <SmartToolbar onRefresh={() => refetch()} isFetching={isFetching} />
          </div>
        }
        quickFilters={
          <div className="flex flex-wrap items-center gap-2 px-4 py-2 sm:px-6">
            <Input
              placeholder="Filter by order ID…"
              value={orderId}
              onChange={(e) => {
                setOrderId(e.target.value);
                setPage(1);
              }}
              className="h-8 max-w-xs text-sm"
            />

            {STATUS_FILTERS.map((s) => (
              <Button
                key={s.key}
                size="sm"
                variant={statusFilter === s.key ? 'secondary' : 'ghost'}
                className="h-8 text-xs"
                onClick={() => resetPage(setStatusFilter)(s.key)}
              >
                {s.label}
              </Button>
            ))}

            <span className="mx-1 h-4 w-px bg-border" />

            <Button
              size="sm"
              variant={slaOnly ? 'secondary' : 'ghost'}
              className="h-8 gap-1 text-xs"
              onClick={() => resetPage(setSlaOnly)(!slaOnly)}
            >
              <Clock className="size-3" />
              SLA breached
            </Button>

            {(options?.failure_categories ?? []).map((category) => (
              <Button
                key={category.value}
                size="sm"
                variant={categoryFilter === category.value ? 'secondary' : 'ghost'}
                className="h-8 text-xs"
                onClick={() =>
                  resetPage(setCategoryFilter)(
                    categoryFilter === category.value ? 'all' : category.value,
                  )
                }
              >
                {category.label}
              </Button>
            ))}
          </div>
        }
      >
        <div className="space-y-3 px-4 pb-6 sm:px-6">
          <DeliveriesTable
            rows={deliveries}
            isLoading={isFetching && !data}
            hasFilter={hasFilter}
            onRowClick={(delivery) => {
              setSelectedId(delivery.id);
              setDrawerOpen(true);
            }}
          />

          {meta && meta.total > 0 && (
            <Pagination
              meta={{
                page: meta.current_page,
                perPage: meta.per_page,
                total: meta.total,
                lastPage: meta.last_page,
              }}
              onPageChange={setPage}
            />
          )}
        </div>
      </WorkspacePage>

      <DeliveryDrawer deliveryId={selectedId} open={drawerOpen} onOpenChange={setDrawerOpen} />
    </>
  );
}
