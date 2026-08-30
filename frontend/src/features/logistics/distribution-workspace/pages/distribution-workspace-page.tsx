import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

import type enLogistics from '@/i18n/locales/en/logistics.json';
import { AlertTriangle, Boxes, CheckCircle2, CircleOff, Package } from 'lucide-react';

import { useNavLabel } from '@/components/layout/use-nav-label';
import { WorkspaceBreadcrumbs } from '@/components/workspace/breadcrumbs/workspace-breadcrumbs';
import { WorkspaceHeader } from '@/components/workspace/header/workspace-header';
import type { WorkspaceMetric } from '@/components/workspace/types';
import { WorkspacePage } from '@/components/page/layout/workspace-page';
import { PageDrawer } from '@/components/page/drawer/page-drawer';
import { SmartToolbar } from '@/components/data-grid/smart-toolbar';
import type { DataGridColumnDef } from '@/components/data-grid/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useFormatter } from '@/hooks/use-formatter';
import { useOrganizationContext } from '@/features/organization/context/organization-context';
import { useWarehousesQuery } from '@/features/warehouses/hooks/use-warehouses';

import { DistributionGroupsPanel } from '../components/distribution-groups-panel';
import { DistributionExceptionsPanel } from '../components/distribution-exceptions-panel';
import { DistributionMapTab } from '../components/distribution-map-tab';
import { DistributionSettingsTab } from '../components/distribution-settings-tab';
import { DistributionTemplatesTab } from '../components/distribution-templates-tab';
import { DistributionOrderDetail } from '../components/distribution-order-detail';
import { OrderAddressCell } from '../components/order-address-cell';
import { ZoneOrdersDrawer } from '../components/zone-orders-drawer';
import { ZonesReviewTable } from '../components/zones-review-table';
import { ZoneOrdersSummaryCard } from '../components/zone-orders-summary-card';
import {
  useCollectDistribution,
  useCurrentDistributionWindow,
  useDistributionOrders,
  useOrdersAwaitingGroup,
} from '../hooks/use-distribution-workspace';
import type {
  DistributionOrder,
  PreparationWaveCycle,
  SlotSummary,
  UnassignedReason,
  ZoneSummary,
} from '../types';

/**
 * A zone as the REVIEW tab renders it — rolled up from the orders the tab
 * actually displays, plus the Group its planned slot belongs to. It extends the
 * server `ZoneSummary` shape so the existing zone panels and the ZoneOrdersDrawer
 * consume it with no change.
 */
type ReviewZone = ZoneSummary & {
  group_code: string | null;
  group_name: string | null;
};

/**
 * Distributor Orders → Distribution Planning — the canonical Distribution Core
 * surface.
 *
 * TASK-DISTRIBUTION-PLANNING-WORKSPACE-PHASE-1 — this file is the workspace SHELL.
 * It renders the enterprise WorkspaceHeader (breadcrumbs + wave/window badge + KPI
 * metrics row), a SmartToolbar (Refresh/Collect + an on-demand Exceptions opener),
 * and the operational tabs. Every figure still comes from
 * `GET /logistics/distribution/windows/current` and `.../windows/{id}/orders`:
 * eligibility, city binding, zone resolution, capacity, aggregation and the
 * paid/unpaid split are all decided server-side and rendered verbatim. Nothing
 * here plans a vehicle, finalises, or hands off to Loading.
 */

// ── Reasons ──────────────────────────────────────────────────────────────────

/** A `logistics` namespace selector — resolved at render, never stored translated. */
type LogisticsLabel = ($: typeof enLogistics) => string;

const UNASSIGNED_REASON_LABEL: Record<UnassignedReason, LogisticsLabel> = {
  address_incomplete: ($) => $.distributionWorkspace.unassignedReason.addressIncomplete,
  city_not_resolved: ($) => $.distributionWorkspace.unassignedReason.cityNotResolved,
  zone_not_configured: ($) => $.distributionWorkspace.unassignedReason.zoneNotConfigured,
  unresolved: ($) => $.distributionWorkspace.unassignedReason.unresolved,
};

// ── Payment method ───────────────────────────────────────────────────────────

const PAYMENT_METHOD_LABEL: Record<string, LogisticsLabel> = {
  cod: ($) => $.distributionWorkspace.payment.method.cod,
  cash_on_delivery: ($) => $.distributionWorkspace.payment.method.cod,
  instapay: ($) => $.distributionWorkspace.payment.method.instapay,
  visa: ($) => $.distributionWorkspace.payment.method.visa,
  mastercard: ($) => $.distributionWorkspace.payment.method.mastercard,
  credit_card: ($) => $.distributionWorkspace.payment.method.creditCard,
  mobile_wallet: ($) => $.distributionWorkspace.payment.method.wallet,
  wallet: ($) => $.distributionWorkspace.payment.method.wallet,
  bank_transfer: ($) => $.distributionWorkspace.payment.method.bankTransfer,
};

function paymentMethodLabel(
  method: string | null,
  translate: (key: LogisticsLabel) => string,
): string {
  if (!method) return '—';
  const key = PAYMENT_METHOD_LABEL[method.toLowerCase()];
  return key ? translate(key) : method;
}

// ── Operational cycle ────────────────────────────────────────────────────────

/**
 * Render a UTC boundary in the COMPANY's operational timezone.
 *
 * Not the browser's: a planner in another zone must still read the cycle the
 * warehouse actually runs on. Falls back to the raw value if the zone is unknown
 * rather than silently showing a local time that means something else.
 */
function cycleTime(iso: string | null | undefined, timezone: string | null): string {
  if (!iso) return '—';

  const parsed = new Date(iso.includes('T') ? iso : iso.replace(' ', 'T') + 'Z');
  if (Number.isNaN(parsed.getTime())) return iso;

  try {
    return new Intl.DateTimeFormat('en-GB', {
      hour: '2-digit',
      minute: '2-digit',
      timeZone: timezone ?? 'UTC',
    }).format(parsed);
  } catch {
    return parsed.toISOString().slice(11, 16);
  }
}

function CycleField({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-xs uppercase text-muted-foreground">{label}</dt>
      <dd className="font-medium text-foreground">{value}</dd>
    </div>
  );
}

/**
 * No planning window could be resolved — TASK-1-A §1.
 *
 * A missing Preparation Wave is NOT a reason to hide the board: the wave selects
 * the current cycle, it does not gate Distribution, so a warehouse with real
 * window data still renders it.
 */
function UnresolvedWindow({ warehouseSelected }: { warehouseSelected: boolean }) {
  const { t } = useTranslation('logistics');

  const body = () =>
    warehouseSelected
      ? t(($) => $.distributionWorkspace.unresolved.generic)
      : t(($) => $.distributionWorkspace.unresolved.selectWarehouse);

  return (
    <Card className="p-8" data-testid="distribution-unresolved">
      <div className="mx-auto max-w-md text-center">
        <h2 className="text-base font-semibold">
          {t(($) => $.distributionWorkspace.unresolved.title)}
        </h2>
        <p className="mt-2 text-sm text-muted-foreground">{body()}</p>
        <p className="mt-4 text-xs text-muted-foreground">
          {t(($) => $.distributionWorkspace.unresolved.hint)}
        </p>
      </div>
    </Card>
  );
}

/**
 * The operational cycle header.
 *
 * Distribution shows the PREPARATION WAVE's boundaries because it has no schedule
 * of its own. When no wave is active there is nothing to align to, and that is
 * stated rather than filled in with the window's own ingestion times.
 */
function CycleHeader({
  wave,
  warehouseSelected,
}: {
  wave: PreparationWaveCycle | null;
  warehouseSelected: boolean;
}) {
  const { t } = useTranslation('logistics');

  if (!wave) {
    return (
      <p className="text-sm text-amber-700 dark:text-amber-400" data-testid="distribution-cycle">
        {warehouseSelected
          ? t(($) => $.distributionWorkspace.cycle.noWave)
          : t(($) => $.distributionWorkspace.cycle.selectWarehouse)}
      </p>
    );
  }

  return (
    <dl
      className="grid grid-cols-2 gap-x-6 gap-y-1 text-sm text-muted-foreground sm:grid-cols-5"
      data-testid="distribution-cycle"
    >
      <CycleField
        label={t(($) => $.distributionWorkspace.cycle.wave)}
        value={wave.wave_number}
      />
      <CycleField
        label={t(($) => $.distributionWorkspace.cycle.start)}
        value={cycleTime(wave.starts_at, wave.timezone)}
      />
      <CycleField
        label={t(($) => $.distributionWorkspace.cycle.cutoff)}
        value={cycleTime(wave.cutoff_at, wave.timezone)}
      />
      <CycleField
        label={t(($) => $.distributionWorkspace.cycle.end)}
        value={cycleTime(wave.ends_at, wave.timezone)}
      />
      <CycleField
        label={t(($) => $.distributionWorkspace.cycle.timezone)}
        value={wave.timezone ?? '—'}
      />
    </dl>
  );
}

// ── Small presentational pieces ──────────────────────────────────────────────

function PaymentBadge({ order }: { order: DistributionOrder }) {
  const { t } = useTranslation('logistics');
  const paid = order.payment_state === 'paid';
  const partial = order.payment_state === 'partially_paid';

  return (
    <Badge variant={paid ? 'default' : partial ? 'secondary' : 'outline'} className="w-fit">
      {paid
        ? t(($) => $.distributionWorkspace.payment.paid)
        : partial
          ? t(($) => $.distributionWorkspace.payment.partiallyPaid)
          : t(($) => $.distributionWorkspace.payment.unpaid)}
    </Badge>
  );
}

function ZoneCell({ order }: { order: DistributionOrder }) {
  const { t } = useTranslation('logistics');
  const reasonKey = order.unassigned_reason
    ? UNASSIGNED_REASON_LABEL[order.unassigned_reason]
    : undefined;

  if (order.zone_id !== null) {
    return (
      <Badge variant="secondary" className="w-fit">
        {order.zone_name ??
          t(($) => $.distributionWorkspace.zoneFallback, { id: order.zone_id })}
      </Badge>
    );
  }

  return (
    <div className="flex flex-col gap-0.5">
      <Badge variant="outline" className="w-fit text-amber-700">
        {t(($) => $.common.unassigned)}
      </Badge>
      <span className="text-xs text-muted-foreground">
        {reasonKey ? t(reasonKey) : (order.unassigned_reason ?? '—')}
      </span>
    </div>
  );
}

// ── Page ─────────────────────────────────────────────────────────────────────

export function DistributionWorkspacePage() {
  const navLabel = useNavLabel();
  const { money } = useFormatter();

  const { activeWarehouseId } = useOrganizationContext();
  const { t } = useTranslation('logistics');

  const { data: warehouseData } = useWarehousesQuery({ per_page: 100 });
  const warehouseNames = useMemo(
    () => Object.fromEntries((warehouseData?.items ?? []).map((w) => [w.id, w.name])),
    [warehouseData],
  );

  const { data, isLoading, isError, error, refetch, isFetching } =
    useCurrentDistributionWindow(activeWarehouseId);
  const collect = useCollectDistribution();

  const [tab, setTab] = useState('groups');
  const [openZone, setOpenZone] = useState<ZoneSummary | null>(null);
  const [detailOrderId, setDetailOrderId] = useState<string | null>(null);
  const [exceptionsOpen, setExceptionsOpen] = useState(false);

  const currentWindow = data?.window;
  const wave = data?.preparation_wave ?? null;
  const zones = useMemo(() => data?.zones ?? [], [data]);
  const slots = useMemo(() => data?.slots ?? [], [data]);
  const canPlan = currentWindow?.accepts_manual_assignment ?? false;

  const ordersQuery = useDistributionOrders(currentWindow?.id, activeWarehouseId);
  const orders = useMemo(() => ordersQuery.data ?? [], [ordersQuery.data]);

  // Read-only. Powers the Exceptions count + the on-demand Exceptions drawer; the
  // same query key the two exception surfaces use, so React Query dedupes it.
  const awaitingQuery = useOrdersAwaitingGroup(currentWindow?.id, activeWarehouseId);

  const assigned = useMemo(() => orders.filter((o) => o.zone_id !== null), [orders]);
  const unassigned = useMemo(() => orders.filter((o) => o.zone_id === null), [orders]);

  const realZones = useMemo(() => zones.filter((z) => z.zone_id !== null), [zones]);

  const reviewZones = useMemo<ReviewZone[]>(() => {
    const groupByZone = new Map<number, SlotSummary>();
    for (const g of slots) {
      for (const zid of g.zone_ids) groupByZone.set(zid, g);
    }

    const byZone = new Map<number, ReviewZone>();
    const slotIdsByZone = new Map<number, Set<string>>();

    for (const o of orders) {
      if (o.zone_id === null) continue;

      let z = byZone.get(o.zone_id);
      if (!z) {
        const g = groupByZone.get(o.zone_id) ?? null;
        z = {
          zone_id: o.zone_id,
          zone_code: null,
          zone_name: o.zone_name,
          virtual_slot_id: g?.slot_id ?? null,
          order_count: 0,
          total_value: 0,
          spans_slots: false,
          products_count: 0,
          paid_orders: 0,
          unpaid_orders: 0,
          group_code: g?.code ?? null,
          group_name: g?.name ?? null,
        };
        byZone.set(o.zone_id, z);
        slotIdsByZone.set(o.zone_id, new Set());
      }

      z.order_count += 1;
      z.total_value += o.total;
      z.products_count += o.products_count;
      if (o.payment_state === 'paid') z.paid_orders += 1;
      if (o.virtual_slot_id !== null) slotIdsByZone.get(o.zone_id)!.add(o.virtual_slot_id);
    }

    return [...byZone.values()]
      .map((z) => ({
        ...z,
        unpaid_orders: z.order_count - z.paid_orders,
        spans_slots: (slotIdsByZone.get(z.zone_id as number)?.size ?? 0) > 1,
      }))
      .sort((a, b) => b.order_count - a.order_count);
  }, [orders, slots]);

  const columns = useMemo<DataGridColumnDef<DistributionOrder>[]>(
    () => [
      {
        key: 'order_number',
        label: t(($) => $.distributionWorkspace.columns.order),
        alwaysVisible: true,
        cell: (o) => (
          <div className="flex flex-col">
            <span className="font-medium">{o.order_number}</span>
            <span className="text-xs text-muted-foreground">{o.order_status}</span>
          </div>
        ),
      },
      {
        key: 'customer',
        label: t(($) => $.distributionWorkspace.columns.customer),
        cell: (o) => (
          <div className="flex flex-col">
            <span>{o.customer_name ?? '—'}</span>
            <span className="text-xs text-muted-foreground">{o.phone ?? ''}</span>
          </div>
        ),
      },
      {
        key: 'total',
        label: t(($) => $.distributionWorkspace.columns.value),
        align: 'end',
        cell: (o) => money(o.total),
      },
      {
        key: 'payment_method',
        label: t(($) => $.distributionWorkspace.columns.paymentMethod),
        cell: (o) => (
          <div className="flex flex-col gap-0.5">
            <span>{paymentMethodLabel(o.payment_method_effective, (k) => t(k))}</span>
            <PaymentBadge order={o} />
          </div>
        ),
      },
      {
        key: 'products',
        label: t(($) => $.distributionWorkspace.columns.products),
        align: 'end',
        cell: (o) => (
          <button
            type="button"
            onClick={() => setDetailOrderId(o.order_id)}
            className="flex flex-col items-end rounded px-1 py-0.5 text-end hover:bg-accent focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
            data-testid={`order-products-${o.order_number}`}
            title={t(($) => $.distributionWorkspace.order.viewProducts)}
          >
            <span className="tabular-nums underline decoration-dotted underline-offset-2">
              {t(($) => $.distributionWorkspace.order.products, { count: o.products_count })}
            </span>
            <span className="text-xs tabular-nums text-muted-foreground">
              {t(($) => $.distributionWorkspace.order.units, { count: o.total_quantity })}
            </span>
          </button>
        ),
      },
      {
        key: 'address',
        label: t(($) => $.distributionWorkspace.columns.shippingAddress),
        minWidth: 260,
        cell: (o) => <OrderAddressCell address={o.shipping_address} />,
      },
      {
        key: 'location',
        label: t(($) => $.distributionWorkspace.columns.cityGovernorate),
        cell: (o) => (
          <div className="flex flex-col">
            <span>{o.city_name ?? o.city_text ?? '—'}</span>
            <span className="text-xs text-muted-foreground">{o.governorate_name ?? ''}</span>
          </div>
        ),
      },
      {
        key: 'zone',
        label: t(($) => $.distributionWorkspace.columns.zone),
        cell: (o) => <ZoneCell order={o} />,
      },
      {
        key: 'received_at',
        label: t(($) => $.distributionWorkspace.columns.received),
        defaultVisible: false,
        cell: (o) => (o.received_at ? o.received_at.slice(0, 16) : '—'),
      },
      {
        key: 'last_updated_at',
        label: t(($) => $.distributionWorkspace.columns.lastUpdated),
        defaultVisible: false,
        cell: (o) => (o.last_updated_at ? o.last_updated_at.slice(0, 16) : '—'),
      },
      {
        key: 'warehouse',
        label: t(($) => $.distributionWorkspace.columns.warehouse),
        defaultVisible: false,
        cell: (o) => o.warehouse_name ?? '—',
      },
    ],
    [money, t],
  );

  const rowId = (o: DistributionOrder) => o.assignment_id;

  // ── Loading / error ────────────────────────────────────────────────────────

  if (isLoading) {
    return (
      <div className="space-y-4 p-4">
        <Skeleton className="h-24 w-full" />
        <Skeleton className="h-20 w-full" />
        <Skeleton className="h-64 w-full" />
      </div>
    );
  }

  if (isError) {
    return (
      <div className="p-4">
        <Card className="border-destructive/40 p-6">
          <p className="font-medium text-destructive">
            {t(($) => $.distributionWorkspace.pool.loadFailed)}
          </p>
          <p className="mt-1 text-sm text-muted-foreground">
            {error instanceof Error
              ? error.message
              : t(($) => $.distributionWorkspace.pool.pleaseRetry)}
          </p>
          <Button variant="outline" size="sm" className="mt-3" onClick={() => void refetch()}>
            {t(($) => $.distributionWorkspace.pool.retry)}
          </Button>
        </Card>
      </div>
    );
  }

  // UNRESOLVED — TASK-1-A §1. Two cases: no warehouse in context, or the tenant
  // has no Distribution Window at all. A missing Preparation Wave is NOT a case.
  if (activeWarehouseId === null || data?.resolution === 'no_planning_window') {
    return (
      <div className="space-y-4 p-4" data-testid="distribution-workspace">
        <WorkspaceBreadcrumbs
          crumbs={[
            { label: navLabel.group('operations') },
            { label: navLabel.item('logistics-distribution-plan') },
          ]}
        />
        <h1 className="text-xl font-semibold">
          {navLabel.item('logistics-distribution-plan')}
        </h1>
        <UnresolvedWindow warehouseSelected={activeWarehouseId !== null} />
      </div>
    );
  }

  // ── KPI metrics (canonical; several click-to-open the Exceptions drawer) ─────
  const attentionGroups = slots.filter((g) => g.is_over_capacity || g.is_warning).length;
  const awaitingTotal = awaitingQuery.data?.summary.total ?? 0;
  const exceptionsCount = attentionGroups + awaitingTotal;

  const metrics: WorkspaceMetric[] = [
    {
      id: 'eligible',
      icon: Package,
      label: t(($) => $.distributionWorkspace.kpi.eligible),
      value: orders.length,
    },
    {
      id: 'assigned',
      icon: CheckCircle2,
      label: t(($) => $.distributionWorkspace.kpi.assigned),
      value: assigned.length,
    },
    {
      id: 'unassigned',
      icon: CircleOff,
      label: t(($) => $.distributionWorkspace.kpi.unassigned),
      value: unassigned.length,
      colorClass: unassigned.length > 0 ? 'bg-amber-500/10 text-amber-600' : undefined,
    },
    {
      id: 'groups',
      icon: Boxes,
      label: t(($) => $.distributionWorkspace.phase1.kpiActiveGroups),
      value: slots.length,
    },
    {
      id: 'attention',
      icon: AlertTriangle,
      label: t(($) => $.distributionWorkspace.phase1.kpiNeedAttention),
      value: exceptionsCount,
      colorClass: exceptionsCount > 0 ? 'bg-destructive/10 text-destructive' : undefined,
      onClick: () => setExceptionsOpen(true),
      active: exceptionsOpen,
    },
  ];

  return (
    <div data-testid="distribution-workspace">
      <WorkspaceHeader
        breadcrumbs={[
          { label: navLabel.group('operations') },
          { label: navLabel.item('logistics-distribution-plan') },
        ]}
        title={navLabel.item('logistics-distribution-plan')}
        description={t(($) => $.distributionWorkspace.phase1.descr)}
        badge={
          currentWindow ? (
            <Badge variant={currentWindow.status === 'open' ? 'default' : 'secondary'}>
              {currentWindow.status_label}
            </Badge>
          ) : undefined
        }
        metrics={metrics}
        toolbarSlot={<CycleHeader wave={wave} warehouseSelected={activeWarehouseId !== null} />}
      />

      <WorkspacePage
        toolbar={
          <SmartToolbar
            onRefresh={() => collect.mutate()}
            isFetching={collect.isPending || isFetching}
            refreshLabel={t(($) => $.distributionWorkspace.pool.refresh)}
            secondaryActions={[
              {
                key: 'exceptions',
                label: t(($) => $.distributionWorkspace.phase1.exceptionsAction, {
                  count: exceptionsCount,
                }),
                icon: AlertTriangle,
                onClick: () => setExceptionsOpen(true),
              },
            ]}
          />
        }
      >
        <div className="space-y-4 px-4 sm:px-6">
          {collect.isSuccess && collect.data ? (
            <p
              className="text-sm text-muted-foreground"
              data-testid="distribution-collect-result"
            >
              {t(($) => $.distributionWorkspace.pool.collectResult, {
                collected: collect.data.collected,
                bound: collect.data.cities_bound,
                unresolved: collect.data.cities_unresolved,
                rezoned: collect.data.rezoned,
              })}
            </p>
          ) : null}

          <Tabs value={tab} onValueChange={setTab}>
            <TabsList className="flex-wrap">
              <TabsTrigger value="groups" data-testid="tab-groups">
                {t(($) => $.distributionWorkspace.tabs.groups, { count: slots.length })}
              </TabsTrigger>
              <TabsTrigger value="zones" data-testid="tab-zones">
                {t(($) => $.distributionWorkspace.tabs.zones, { count: reviewZones.length })}
              </TabsTrigger>
              <TabsTrigger value="map" data-testid="tab-map">
                {t(($) => $.distributionWorkspace.tabs.map)}
              </TabsTrigger>
              <TabsTrigger value="settings" data-testid="tab-settings">
                {t(($) => $.distributionWorkspace.tabs.settings)}
              </TabsTrigger>
              <TabsTrigger value="templates" data-testid="tab-templates">
                {t(($) => $.distributionWorkspace.tabs.templates)}
              </TabsTrigger>
            </TabsList>

            {/* ── Groups Overview — the main operational board ────────────────── */}
            <TabsContent value="groups" className="mt-3">
              <DistributionGroupsPanel
                windowId={currentWindow?.id}
                warehouseId={activeWarehouseId}
                warehouseNames={warehouseNames}
                zones={realZones}
                groups={slots}
                wave={wave}
                canPlan={canPlan}
                orders={orders}
                columns={columns}
                rowId={rowId}
                ordersLoading={ordersQuery.isLoading}
              />
            </TabsContent>

            <TabsContent value="map" className="mt-3">
              <DistributionMapTab
                windowId={currentWindow?.id}
                warehouseId={activeWarehouseId}
                active={tab === 'map'}
              />
            </TabsContent>

            <TabsContent value="settings" className="mt-3">
              <DistributionSettingsTab windowId={currentWindow?.id} groups={slots} />
            </TabsContent>

            <TabsContent value="templates" className="mt-3">
              <DistributionTemplatesTab
                windowId={currentWindow?.id}
                warehouseId={activeWarehouseId}
                active={tab === 'templates'}
              />
            </TabsContent>

            {/* ── Zones — the authoritative Zone review surface, unchanged ────── */}
            <TabsContent value="zones" className="mt-3">
              <Tabs defaultValue="orders">
                <TabsList className="flex-wrap">
                  <TabsTrigger value="orders" data-testid="tab-all-orders">
                    {t(($) => $.distributionWorkspace.tabs.allOrders, { count: orders.length })}
                  </TabsTrigger>

                  {reviewZones.map((zone) => (
                    <TabsTrigger
                      key={zone.zone_id}
                      value={`zone-${zone.zone_id}`}
                      data-testid={`tab-zone-${zone.zone_id}`}
                    >
                      {t(($) => $.distributionWorkspace.tabs.zone, {
                        name:
                          zone.zone_name ??
                          t(($$) => $$.distributionWorkspace.zoneFallback, { id: zone.zone_id }),
                        count: zone.order_count,
                      })}
                    </TabsTrigger>
                  ))}

                  <TabsTrigger value="unassigned" data-testid="tab-unassigned">
                    {t(($) => $.distributionWorkspace.tabs.unassigned, { count: unassigned.length })}
                  </TabsTrigger>
                </TabsList>

                <TabsContent value="orders" className="mt-3">
                  <div data-testid="all-orders-grid">
                    <ZonesReviewTable
                      orders={orders}
                      groups={slots}
                      ordersLoading={ordersQuery.isLoading}
                      ordersError={ordersQuery.isError}
                      onOpenOrder={setDetailOrderId}
                    />
                  </div>
                </TabsContent>

                {reviewZones.map((zone) => {
                  const zoneOrders = assigned.filter((o) => o.zone_id === zone.zone_id);
                  const zoneLabel =
                    zone.zone_name ??
                    t(($) => $.distributionWorkspace.zoneFallback, { id: zone.zone_id });
                  const groupLabel = zone.group_code
                    ? zone.group_name
                      ? `${zone.group_code} — ${zone.group_name}`
                      : zone.group_code
                    : null;

                  return (
                    <TabsContent key={zone.zone_id} value={`zone-${zone.zone_id}`} className="mt-3">
                      <div className="space-y-3" data-testid={`zone-panel-${zone.zone_id}`}>
                        <ZoneOrdersSummaryCard
                          zoneName={zone.zone_code ? `${zone.zone_code} — ${zoneLabel}` : zoneLabel}
                          groupLabel={groupLabel}
                          spansSlots={zone.spans_slots}
                          orders={zone.order_count}
                          products={zone.products_count}
                          orderValue={zone.total_value}
                          paid={zone.paid_orders}
                          unpaid={zone.unpaid_orders}
                          onViewOrders={() => setOpenZone(zone)}
                          testId={`zone-summary-${zone.zone_id}`}
                        />

                        <ZonesReviewTable
                          orders={zoneOrders}
                          groups={slots}
                          ordersLoading={ordersQuery.isLoading}
                          ordersError={ordersQuery.isError}
                          onOpenOrder={setDetailOrderId}
                        />
                      </div>
                    </TabsContent>
                  );
                })}

                <TabsContent value="unassigned" className="mt-3">
                  {unassigned.length === 0 ? (
                    <Card
                      className="p-8 text-center text-sm text-muted-foreground"
                      data-testid="unassigned-grid"
                    >
                      {t(($) => $.distributionWorkspace.unassignedPanel.allResolved)}
                    </Card>
                  ) : (
                    <div className="space-y-3" data-testid="unassigned-grid">
                      <div className="flex items-start gap-2 rounded-lg border p-4 text-sm">
                        <AlertTriangle
                          className="mt-0.5 size-4 shrink-0 text-amber-600"
                          aria-hidden
                        />
                        <p className="text-muted-foreground">
                          {t(($) => $.distributionWorkspace.unassignedPanel.explain)}
                        </p>
                      </div>
                      <ZonesReviewTable
                        orders={unassigned}
                        groups={slots}
                        ordersLoading={ordersQuery.isLoading}
                        ordersError={ordersQuery.isError}
                        onOpenOrder={setDetailOrderId}
                      />
                    </div>
                  )}
                </TabsContent>
              </Tabs>
            </TabsContent>
          </Tabs>
        </div>
      </WorkspacePage>

      <DistributionOrderDetail
        orderId={detailOrderId}
        open={detailOrderId !== null}
        onOpenChange={(open) => {
          if (!open) setDetailOrderId(null);
        }}
      />

      {currentWindow && openZone ? (
        <ZoneOrdersDrawer
          window={currentWindow}
          zone={openZone}
          slots={slots}
          open={Boolean(openZone)}
          onOpenChange={(open) => !open && setOpenZone(null)}
        />
      ) : null}

      <PageDrawer
        open={exceptionsOpen}
        onOpenChange={setExceptionsOpen}
        title={t(($) => $.distributionWorkspace.phase1.exceptionsTitle)}
        description={t(($) => $.distributionWorkspace.phase1.exceptionsDescr)}
        size="lg"
      >
        <DistributionExceptionsPanel
          windowId={currentWindow?.id}
          warehouseId={activeWarehouseId}
        />
      </PageDrawer>
    </div>
  );
}
