import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { AlertTriangle, Boxes, Plus } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { useFormatter } from '@/hooks/use-formatter';
import { cn } from '@/lib/utils';

import type { DataGridColumnDef } from '@/components/data-grid/types';

import { AssignWarehouseDialog } from './assign-warehouse-dialog';
import { GroupDetailSection } from './group-detail-section';
import {
  useCreateDistributionGroup,
  useOrdersAwaitingGroup,
} from '../hooks/use-distribution-workspace';
import type {
  DistributionOrder,
  GroupAssignmentBlocker,
  OrderAwaitingGroup,
  PreparationWaveCycle,
  SlotSummary,
  ZoneSummary,
} from '../types';

/**
 * Distribution Groups.
 *
 * TASK-DISTRIBUTION-PLANNING-FINAL-UI-SYNC-IMPLEMENTATION-001 — presentation only.
 * Groups render in a responsive **4-column Card grid** (never a carousel). Group
 * creation is a "+ Create Distribution Group" button that opens the existing
 * create workflow in a Dialog (no permanent inline form). Selecting a card reveals
 * the inline Detail Section below the grid. No group / capacity / wave / trip /
 * eligibility logic is duplicated or changed.
 */

/** One Group as a first-class grid Card. */
function GroupCard({
  group,
  money,
  selected,
  onSelect,
}: {
  group: SlotSummary;
  money: (n: number) => string;
  selected: boolean;
  onSelect: () => void;
}) {
  const { t } = useTranslation('logistics');

  const over = group.is_over_capacity;
  const warn = !over && group.is_warning;
  const hasMax = group.capacity_orders != null;
  const pct = hasMax
    ? Math.min(100, Math.round((group.demand_orders / (group.capacity_orders as number)) * 100))
    : null;
  const maxLabel = group.capacity_orders ?? t(($) => $.distributionWorkspace.capacity.noMaximum);

  return (
    <Card
      onClick={onSelect}
      className={cn(
        'flex h-full cursor-pointer flex-col gap-3 p-4 transition-colors hover:border-primary/60',
        selected && 'border-primary bg-accent/40 ring-2 ring-primary',
      )}
      data-testid={`distribution-group-${group.code}`}
    >
      {/* Header */}
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <div className="flex items-center gap-2">
            <span className="font-semibold">{group.code}</span>
            <Badge variant="secondary" className="capitalize">
              {group.status}
            </Badge>
          </div>
          {group.name ? (
            <p className="truncate text-sm text-muted-foreground">{group.name}</p>
          ) : null}
        </div>
        {over ? (
          <Badge variant="destructive">
            {t(($) => $.distributionWorkspace.phase1.overCapacityShort, {
              count: group.overflow_orders,
            })}
          </Badge>
        ) : warn ? (
          <Badge variant="outline" className="text-amber-700 dark:text-amber-400">
            {t(($) => $.distributionWorkspace.phase1.nearCapacity)}
          </Badge>
        ) : (
          <Badge variant="outline" className="text-emerald-600 dark:text-emerald-400">
            {t(($) => $.distributionWorkspace.phase1.ready)}
          </Badge>
        )}
      </div>

      {/* Zone */}
      <p className="truncate text-sm text-muted-foreground">
        {group.zone_names.length > 0 ? group.zone_names.join(' & ') : '—'}
      </p>

      {/* Zone value */}
      <div className="flex items-center justify-between text-sm">
        <span className="text-xs uppercase text-muted-foreground">
          {t(($) => $.distributionWorkspace.phase1.cardValue)}
        </span>
        <span className="font-semibold tabular-nums">{money(group.total_value)}</span>
      </div>

      {/* Orders — shown once as Current · Maximum */}
      <p className="text-sm tabular-nums">
        {`${t(($) => $.distributionWorkspace.capacity.current)}: ${group.demand_orders} · ${t(($) => $.distributionWorkspace.capacity.maximum)}: ${maxLabel}`}
      </p>

      {/* Orders progress = current / maximum */}
      <div>
        <div className="flex items-center justify-between text-xs text-muted-foreground">
          <span>{t(($) => $.distributionWorkspace.phase1.progressOrders)}</span>
          <span className="tabular-nums">{pct === null ? '—' : `${pct}%`}</span>
        </div>
        <div className="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-muted">
          <div
            className={cn(
              'h-full rounded-full',
              over ? 'bg-destructive' : warn ? 'bg-amber-500' : 'bg-primary',
            )}
            style={{ width: `${pct ?? 0}%` }}
          />
        </div>
      </div>

      {/* Footer */}
      <div className="mt-auto pt-1">
        <Button
          variant={selected ? 'secondary' : 'outline'}
          size="sm"
          className="w-full"
          onClick={(e) => {
            e.stopPropagation();
            onSelect();
          }}
          data-testid={`open-group-${group.code}`}
        >
          {t(($) => $.distributionWorkspace.phase1.viewDetails)}
        </Button>
      </div>
    </Card>
  );
}

export function DistributionGroupsPanel({
  windowId,
  warehouseId,
  zones,
  groups,
  canPlan,
  orders,
  columns,
  rowId,
  ordersLoading,
}: {
  windowId: string | undefined;
  /** The Group's owner. Null means no warehouse is selected — creation is blocked. */
  warehouseId: string | null;
  /** id -> name for every warehouse in the company. Accepted for page-contract
   * compatibility; not consumed by the current card/detail presentation. */
  warehouseNames?: Record<string, string>;
  zones: ZoneSummary[];
  groups: SlotSummary[];
  /** Accepted for page-contract compatibility; wave context shows in the page header. */
  wave?: PreparationWaveCycle | null;
  canPlan: boolean;
  /** The window's orders — already warehouse-scoped by the page. */
  orders: DistributionOrder[];
  /** The SAME column definitions the pool uses; no second order presentation. */
  columns: DataGridColumnDef<DistributionOrder>[];
  rowId: (o: DistributionOrder) => string;
  ordersLoading: boolean;
}) {
  const { money } = useFormatter();
  const { t } = useTranslation('logistics');
  const create = useCreateDistributionGroup();

  const [createOpen, setCreateOpen] = useState(false);
  const [selectedZoneIds, setSelectedZoneIds] = useState<Set<number>>(new Set());
  const [name, setName] = useState('');
  // Held as a STRING: an empty box ("no maximum") must stay distinct from a zero.
  const [maxOrders, setMaxOrders] = useState('');

  // The Group whose inline Detail Section is open. Null = grid only.
  const [selectedSlotId, setSelectedSlotId] = useState<string | null>(null);
  const selectedGroup = groups.find((g) => g.slot_id === selectedSlotId) ?? null;

  // A zone already in a group cannot be offered again: the backend would move it,
  // silently emptying the first group. Filtering here makes that impossible to ask for.
  const groupedZoneIds = useMemo(
    () => new Set(groups.flatMap((g) => g.zone_ids)),
    [groups],
  );

  const selectable = useMemo(
    () => zones.filter((z) => z.zone_id !== null && !groupedZoneIds.has(z.zone_id)),
    [zones, groupedZoneIds],
  );

  const selection = useMemo(
    () => selectable.filter((z) => z.zone_id !== null && selectedZoneIds.has(z.zone_id)),
    [selectable, selectedZoneIds],
  );

  const preview = useMemo(
    () => ({
      zones: selection.length,
      orders: selection.reduce((sum, z) => sum + z.order_count, 0),
      value: selection.reduce((sum, z) => sum + z.total_value, 0),
    }),
    [selection],
  );

  function toggle(zoneId: number) {
    setSelectedZoneIds((prev) => {
      const next = new Set(prev);
      if (next.has(zoneId)) next.delete(zoneId);
      else next.add(zoneId);
      return next;
    });
  }

  // An empty box is "no maximum". Anything that is not a whole number >= 1 is
  // rejected before the request rather than sent for the server to refuse.
  const parsedMaxOrders = useMemo<number | null>(() => {
    const trimmed = maxOrders.trim();
    if (trimmed === '') return null;

    const value = Number(trimmed);
    return Number.isInteger(value) && value >= 1 ? value : Number.NaN;
  }, [maxOrders]);

  const maxOrdersInvalid = Number.isNaN(parsedMaxOrders);

  function submit() {
    if (!windowId || !warehouseId || selection.length === 0) return;

    // The group number is derived from how many groups already exist; the backend's
    // unique index on (window, code) is what actually guarantees it.
    const code = `DG-${String(groups.length + 1).padStart(3, '0')}`;

    create.mutate(
      {
        windowId,
        warehouseId,
        code,
        name: name.trim() || undefined,
        capacityOrders: parsedMaxOrders,
        zoneIds: selection.map((z) => z.zone_id as number),
      },
      {
        onSuccess: () => {
          setSelectedZoneIds(new Set());
          setName('');
          setMaxOrders('');
          setCreateOpen(false);
        },
      },
    );
  }

  return (
    <div className="space-y-3" data-testid="distribution-groups">
      {/* ── Create Distribution Group — button + dialog (no permanent form) ── */}
      <div className="flex items-center justify-end">
        <Button onClick={() => setCreateOpen(true)} data-testid="open-create-group">
          <Plus className="me-2 size-4" aria-hidden />
          {t(($) => $.distributionWorkspace.phase1.createGroup)}
        </Button>
      </div>

      <Dialog open={createOpen} onOpenChange={setCreateOpen}>
        <DialogContent className="max-w-2xl">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <Boxes className="size-4 text-muted-foreground" aria-hidden />
              {t(($) => $.distributionWorkspace.groups.newTitle)}
            </DialogTitle>
          </DialogHeader>

          <div className="max-h-[70vh] space-y-3 overflow-y-auto pe-1">
            {selectable.length === 0 ? (
              <p className="text-sm text-muted-foreground">
                {zones.some((z) => z.zone_id !== null)
                  ? t(($) => $.distributionWorkspace.groups.allZonesTaken)
                  : t(($) => $.distributionWorkspace.groups.noZoneWithOrders)}
              </p>
            ) : (
              <>
                <p className="text-sm text-muted-foreground">
                  {t(($) => $.distributionWorkspace.groups.selectHint)}
                </p>

                <div className="grid gap-2 sm:grid-cols-2">
                  {selectable.map((zone) => {
                    const id = zone.zone_id as number;
                    return (
                      <label
                        key={id}
                        htmlFor={`zone-${id}`}
                        className="flex cursor-pointer items-start gap-2 rounded-md border p-3 hover:bg-accent"
                        data-testid={`group-zone-option-${id}`}
                      >
                        <Checkbox
                          id={`zone-${id}`}
                          checked={selectedZoneIds.has(id)}
                          onCheckedChange={() => toggle(id)}
                        />
                        <span className="flex flex-col">
                          <span className="text-sm font-medium">
                            {zone.zone_name ??
                              t(($) => $.distributionWorkspace.zoneFallback, { id })}
                          </span>
                          <span className="text-xs text-muted-foreground">
                            {t(($) => $.distributionWorkspace.groups.zoneOption, {
                              orders: zone.order_count,
                              value: money(zone.total_value),
                            })}
                          </span>
                        </span>
                      </label>
                    );
                  })}
                </div>

                <div className="flex flex-wrap items-end gap-3 border-t pt-3">
                  <div className="flex flex-col gap-1">
                    <Label htmlFor="group-name" className="text-xs uppercase text-muted-foreground">
                      {t(($) => $.distributionWorkspace.groups.nameLabel)}
                    </Label>
                    <Input
                      id="group-name"
                      value={name}
                      onChange={(e) => setName(e.target.value)}
                      placeholder={t(($) => $.distributionWorkspace.groups.namePlaceholder)}
                      className="h-9 w-56"
                    />
                  </div>

                  <div className="flex flex-col gap-1">
                    <Label
                      htmlFor="group-max-orders"
                      className="text-xs uppercase text-muted-foreground"
                    >
                      {t(($) => $.distributionWorkspace.groups.maxOrdersLabel)}
                    </Label>
                    <Input
                      id="group-max-orders"
                      type="number"
                      min={1}
                      step={1}
                      inputMode="numeric"
                      value={maxOrders}
                      onChange={(e) => setMaxOrders(e.target.value)}
                      placeholder={t(($) => $.distributionWorkspace.groups.maxOrdersPlaceholder)}
                      className="h-9 w-32"
                      data-testid="group-max-orders"
                    />
                  </div>

                  <p className="pb-2 text-sm text-muted-foreground" data-testid="group-preview">
                    {t(($) => $.distributionWorkspace.groups.preview, {
                      zones: preview.zones,
                      orders: preview.orders,
                      value: money(preview.value),
                    })}
                  </p>
                </div>

                <p className="text-xs text-muted-foreground">
                  {t(($) => $.distributionWorkspace.groups.maxOrdersHint)}
                </p>

                {!warehouseId ? (
                  <p className="text-xs text-amber-700">
                    {t(($) => $.distributionWorkspace.groups.selectWarehouseFirst)}
                  </p>
                ) : null}

                {!canPlan ? (
                  <p className="text-xs text-amber-700">
                    {t(($) => $.distributionWorkspace.groups.planningClosed)}
                  </p>
                ) : null}

                {create.isError ? (
                  <p className="text-sm text-destructive">
                    {create.error instanceof Error
                      ? create.error.message
                      : t(($) => $.distributionWorkspace.groups.createFailed)}
                  </p>
                ) : null}

                <div className="flex justify-end border-t pt-3">
                  <Button
                    onClick={submit}
                    disabled={
                      selection.length === 0 ||
                      create.isPending ||
                      !canPlan ||
                      !warehouseId ||
                      maxOrdersInvalid
                    }
                    data-testid="create-distribution-group"
                  >
                    <Plus className="me-2 size-4" aria-hidden />
                    {t(($) => $.distributionWorkspace.groups.create)}
                  </Button>
                </div>
              </>
            )}
          </div>
        </DialogContent>
      </Dialog>

      {/* ── Groups board — responsive 4-column Card grid (no carousel) ─────── */}
      {groups.length === 0 ? (
        <Card className="p-8 text-center text-sm text-muted-foreground">
          {t(($) => $.distributionWorkspace.groups.none)}
        </Card>
      ) : (
        <div
          className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4"
          data-testid="distribution-group-grid"
        >
          {groups.map((group) => (
            <GroupCard
              key={group.slot_id}
              group={group}
              money={money}
              selected={selectedSlotId === group.slot_id}
              onSelect={() => setSelectedSlotId(group.slot_id)}
            />
          ))}
        </div>
      )}

      {/* Selected group — inline Detail Section directly below the grid. */}
      {selectedGroup ? (
        <GroupDetailSection
          group={selectedGroup}
          windowId={windowId}
          warehouseId={warehouseId}
          siblings={groups}
          zones={zones}
          canPlan={canPlan}
          orders={orders}
          columns={columns}
          rowId={rowId}
          ordersLoading={ordersLoading}
        />
      ) : null}
    </div>
  );
}

/**
 * ZONES WITHOUT GROUP — the root operational gap.
 *
 * A Group holds only the Zones an operator attached to it. When a Zone is attached
 * to none, every Order in it is stranded — configuring the Zone clears all of them
 * at once, so the cause is shown before the symptom. TWO BLOCKERS, NEVER MERGED.
 * VISIBILITY ONLY — attaching a Zone is an existing action (the Group Detail
 * Section "Zones" tab).
 */
export function ZonesWithoutGroup({
  windowId,
  warehouseId,
}: {
  windowId: string | undefined;
  warehouseId: string | null;
}) {
  const { t } = useTranslation('logistics');
  const [filter, setFilter] = useState<'all' | 'no_group_only' | 'needs_warehouse'>('all');

  const query = useOrdersAwaitingGroup(windowId, warehouseId);

  if (query.isLoading) {
    return (
      <Card className="p-4">
        <Skeleton className="h-4 w-56" />
        <Skeleton className="mt-3 h-16 w-full" />
      </Card>
    );
  }

  if (query.isError) {
    return (
      <Card className="border-destructive/40 p-4">
        <p className="text-sm text-destructive">
          {t(($) => $.distributionWorkspace.zonesWithoutGroup.loadFailed)}
        </p>
      </Card>
    );
  }

  const zones = query.data?.zones ?? [];

  if (zones.length === 0) {
    return (
      <Card className="p-4" data-testid="zones-without-group-empty">
        <p className="text-sm text-muted-foreground">
          {t(($) => $.distributionWorkspace.zonesWithoutGroup.allCovered)}
        </p>
      </Card>
    );
  }

  const needingWarehouse = zones.filter((zone) => zone.orders_needing_warehouse > 0);
  const noGroupOnly = zones.filter((zone) => zone.orders_needing_warehouse === 0);

  const rows =
    filter === 'needs_warehouse'
      ? needingWarehouse
      : filter === 'no_group_only'
        ? noGroupOnly
        : zones;

  const filters: { key: typeof filter; label: string; count: number }[] = [
    {
      key: 'all',
      label: t(($) => $.distributionWorkspace.zonesWithoutGroup.filterAll),
      count: zones.length,
    },
    {
      key: 'no_group_only',
      label: t(($) => $.distributionWorkspace.zonesWithoutGroup.filterNoGroup),
      count: noGroupOnly.length,
    },
    {
      key: 'needs_warehouse',
      label: t(($) => $.distributionWorkspace.zonesWithoutGroup.filterWarehouse),
      count: needingWarehouse.length,
    },
  ];

  return (
    <Card className="border-amber-500/40 p-4" data-testid="zones-without-group">
      <h3 className="flex items-center gap-1.5 text-sm font-semibold text-amber-700 dark:text-amber-400">
        <AlertTriangle className="size-4" aria-hidden />
        {t(($) => $.distributionWorkspace.zonesWithoutGroup.title, { count: zones.length })}
      </h3>
      <p className="mt-0.5 text-xs text-muted-foreground">
        {t(($) => $.distributionWorkspace.zonesWithoutGroup.caption)}
      </p>

      <div className="mt-3 flex flex-wrap gap-1.5">
        {filters
          .filter((option) => option.key === 'all' || option.count > 0)
          .map((option) => (
            <Button
              key={option.key}
              size="sm"
              variant={filter === option.key ? 'default' : 'outline'}
              onClick={() => setFilter(option.key)}
              data-testid={`zones-without-group-filter-${option.key}`}
            >
              {option.label}
              <span className="ms-1.5 tabular-nums">{option.count}</span>
            </Button>
          ))}
      </div>

      <ul className="mt-3 grid gap-2 sm:grid-cols-2">
        {rows.map((zone) => (
          <li
            key={zone.zone_id}
            className="rounded-md border p-3"
            data-testid={`zone-without-group-${zone.zone_id}`}
          >
            <div className="flex flex-wrap items-center gap-2">
              <span className="font-semibold">
                {zone.zone_name ?? `#${zone.zone_id}`}
              </span>
              <Badge variant="destructive">
                {t(($) => $.distributionWorkspace.zonesWithoutGroup.statusNoGroup)}
              </Badge>
            </div>

            <p className="mt-1 text-sm">
              {t(($) => $.distributionWorkspace.zonesWithoutGroup.ordersWaiting, {
                count: zone.orders_waiting,
              })}
            </p>

            {zone.governorates.length > 0 || zone.warehouses.length > 0 ? (
              <p className="mt-0.5 text-xs text-muted-foreground">
                {[...zone.governorates, ...zone.warehouses].join(' · ')}
              </p>
            ) : null}

            {zone.orders_needing_warehouse > 0 ? (
              <p
                className="mt-1.5 text-xs font-medium text-destructive"
                data-testid={`zone-warehouse-warning-${zone.zone_id}`}
              >
                {t(($) => $.distributionWorkspace.zonesWithoutGroup.alsoNeedWarehouse, {
                  count: zone.orders_needing_warehouse,
                })}
              </p>
            ) : null}

            <p className="mt-2 text-xs text-muted-foreground">
              {t(($) => $.distributionWorkspace.zonesWithoutGroup.howToFix)}
            </p>
          </li>
        ))}
      </ul>
    </Card>
  );
}

/**
 * ORDERS AWAITING GROUP ASSIGNMENT — the exception surface.
 *
 * An eligible Order can sit in the Window, carry a Zone, and belong to NO Group.
 * THE BLOCKER COMES FROM THE SERVER — never inferred here; each Order appears in
 * exactly one bucket. READ ONLY except the existing warehouse-override dialog.
 */
export function OrdersAwaitingGroup({
  windowId,
  warehouseId,
}: {
  windowId: string | undefined;
  warehouseId: string | null;
}) {
  const { t } = useTranslation('logistics');
  const [filter, setFilter] = useState<'all' | GroupAssignmentBlocker>('all');
  const [assigning, setAssigning] = useState<OrderAwaitingGroup | null>(null);

  const query = useOrdersAwaitingGroup(windowId, warehouseId);
  const data = query.data;

  if (!data || data.summary.total === 0) {
    return null;
  }

  const rows =
    filter === 'all' ? data.orders : data.orders.filter((order) => order.blocker === filter);

  const filters: { key: 'all' | GroupAssignmentBlocker; label: string; count: number }[] = [
    {
      key: 'all',
      label: t(($) => $.distributionWorkspace.awaitingGroup.filterAll),
      count: data.summary.total,
    },
    {
      key: 'warehouse_unassigned',
      label: t(($) => $.distributionWorkspace.awaitingGroup.filterWarehouse),
      count: data.summary.warehouse_unassigned,
    },
    {
      key: 'zone_not_in_group',
      label: t(($) => $.distributionWorkspace.awaitingGroup.filterZone),
      count: data.summary.zone_not_in_group,
    },
    {
      key: 'awaiting_group_assignment',
      label: t(($) => $.distributionWorkspace.awaitingGroup.filterAwaiting),
      count: data.summary.awaiting_group_assignment,
    },
  ];

  return (
    <Card className="border-amber-500/40 p-4" data-testid="orders-awaiting-group">
      <h3 className="flex items-center gap-1.5 text-sm font-semibold text-amber-700 dark:text-amber-400">
        <AlertTriangle className="size-4" aria-hidden />
        {t(($) => $.distributionWorkspace.awaitingGroup.title, { count: data.summary.total })}
      </h3>
      <p className="mt-0.5 text-xs text-muted-foreground">
        {t(($) => $.distributionWorkspace.awaitingGroup.caption)}
      </p>

      <div className="mt-3 flex flex-wrap gap-1.5">
        {filters
          .filter((option) => option.key === 'all' || option.count > 0)
          .map((option) => (
            <Button
              key={option.key}
              size="sm"
              variant={filter === option.key ? 'default' : 'outline'}
              onClick={() => setFilter(option.key)}
              data-testid={`awaiting-group-filter-${option.key}`}
            >
              {option.label}
              <span className="ms-1.5 tabular-nums">{option.count}</span>
            </Button>
          ))}
      </div>

      <ul className="mt-3 space-y-1.5">
        {rows.map((order) => (
          <li
            key={order.order_id}
            className="rounded-md border p-2 text-sm"
            data-testid={`awaiting-group-row-${order.order_number}`}
          >
            <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
              <span className="font-medium" dir="ltr">
                {order.order_number}
              </span>
              <Badge variant="outline">{order.order_status}</Badge>
              {order.payment_state ? (
                <Badge variant={order.payment_state === 'paid' ? 'secondary' : 'outline'}>
                  {order.payment_state}
                </Badge>
              ) : null}
              <Badge variant="destructive">
                {order.blocker === 'warehouse_unassigned'
                  ? t(($) => $.distributionWorkspace.awaitingGroup.reasonWarehouse)
                  : order.blocker === 'zone_not_in_group'
                    ? t(($) => $.distributionWorkspace.awaitingGroup.reasonZone)
                    : t(($) => $.distributionWorkspace.awaitingGroup.reasonAwaiting)}
              </Badge>
            </div>

            <div className="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-muted-foreground">
              {order.customer_name ? <span>{order.customer_name}</span> : null}
              {order.city_name || order.governorate_name ? (
                <span>{[order.city_name, order.governorate_name].filter(Boolean).join(' · ')}</span>
              ) : null}
              <span>
                {t(($) => $.common.zone)}:{' '}
                {order.zone_name ?? order.zone_id ?? t(($) => $.common.unassigned)}
              </span>
              <span>
                {t(($) => $.distributionWorkspace.awaitingGroup.warehouse)}:{' '}
                {order.warehouse_name ?? t(($) => $.common.unassigned)}
              </span>
              {order.total !== null ? (
                <span className="tabular-nums">{order.total}</span>
              ) : null}
              {order.products_count !== null ? (
                <span>
                  {t(($) => $.distributionWorkspace.order.products, {
                    count: order.products_count,
                  })}
                </span>
              ) : null}
            </div>

            {order.secondary_reason ? (
              <p className="mt-1 text-xs text-muted-foreground">
                {t(($) => $.distributionWorkspace.awaitingGroup.secondary, {
                  reason: order.secondary_reason,
                })}
              </p>
            ) : null}

            {order.blocker === 'warehouse_unassigned' ? (
              <Button
                size="sm"
                variant="outline"
                className="mt-1.5"
                onClick={() => setAssigning(order)}
                data-testid={`assign-warehouse-${order.order_number}`}
              >
                {t(($) => $.distributionWorkspace.assignWarehouse.action)}
              </Button>
            ) : null}
          </li>
        ))}
      </ul>

      <AssignWarehouseDialog
        order={assigning}
        activeWarehouseId={warehouseId}
        onClose={() => setAssigning(null)}
      />
    </Card>
  );
}
