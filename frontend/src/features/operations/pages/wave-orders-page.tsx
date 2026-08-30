import { useMemo, useState } from 'react';
import { CalendarClock, Loader2, ShoppingCart, Waves } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { toast } from '@/components/ds/use-toast';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { SmartToolbar } from '@/components/data-grid/smart-toolbar';
import { UniversalDataGrid } from '@/components/data-grid/universal-data-grid';
import { ColumnVisibilityMenu } from '@/components/data-grid/column-visibility-menu';
import { useColumnVisibility } from '@/components/data-grid/use-column-visibility';
import type { DataGridColumnDef } from '@/components/data-grid/types';
import { useWaveOrders, usePostponeWaveOrder } from '../hooks/use-preparation';
import { useSelectedWaveId } from '../components/wave-picker';
import type { WaveOrderEntry, WaveOrderProduct } from '../types/preparation';

const INLINE_PRODUCTS = 2;

// ── Zone filter tabs ───────────────────────────────────────────────────────────

function ZoneTabs({
  orders,
  zone,
  onZone,
  allZonesLabel,
  unzonedLabel,
}: {
  orders: WaveOrderEntry[];
  zone: string | null;
  onZone: (z: string | null) => void;
  allZonesLabel: string;
  unzonedLabel: string;
}) {
  const counts: Record<string, number> = {};
  let total = 0;
  for (const o of orders) {
    const z = o.delivery_zone ?? unzonedLabel;
    counts[z] = (counts[z] ?? 0) + 1;
    total += 1;
  }
  const zones = Object.keys(counts).sort();

  return (
    <div className="flex items-center gap-1 overflow-x-auto">
      <button
        onClick={() => onZone(null)}
        className={`flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-medium whitespace-nowrap transition-colors ${
          zone === null
            ? 'bg-background text-foreground shadow-sm border'
            : 'text-muted-foreground hover:text-foreground hover:bg-background/60'
        }`}
      >
        {allZonesLabel}
        <span className={`inline-flex h-4 min-w-4 items-center justify-center rounded-full px-1 text-[10px] tabular-nums ${
          zone === null ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground'
        }`}>
          {total}
        </span>
      </button>
      {zones.slice(0, 8).map((z) => (
        <button
          key={z}
          onClick={() => onZone(z)}
          className={`flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-medium whitespace-nowrap transition-colors ${
            zone === z
              ? 'bg-background text-foreground shadow-sm border'
              : 'text-muted-foreground hover:text-foreground hover:bg-background/60'
          }`}
        >
          {z}
          <span className={`inline-flex h-4 min-w-4 items-center justify-center rounded-full px-1 text-[10px] tabular-nums ${
            zone === z ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground'
          }`}>
            {counts[z]}
          </span>
        </button>
      ))}
    </div>
  );
}

// ── Order products cell ────────────────────────────────────────────────────────

function ProductLine({ p }: { p: WaveOrderProduct }) {
  return (
    <div className="flex items-baseline gap-1.5 whitespace-nowrap">
      <span className="truncate">{p.name}</span>
      <span className="tabular-nums text-muted-foreground">&times; {p.quantity}</span>
    </div>
  );
}

/** Shows the first few line items inline; the rest open in a popover. */
function OrderProducts({ products, moreLabel }: { products: WaveOrderProduct[]; moreLabel: (n: number) => string }) {
  if (products.length === 0) {
    return <span className="text-muted-foreground">&mdash;</span>;
  }

  const inline = products.slice(0, INLINE_PRODUCTS);
  const rest = products.length - inline.length;

  return (
    <div className="flex flex-col gap-0.5 text-xs">
      {inline.map((p) => <ProductLine key={p.product_id} p={p} />)}
      {rest > 0 && (
        <Popover>
          <PopoverTrigger asChild>
            <button className="self-start text-[11px] font-medium text-primary hover:underline">
              {moreLabel(rest)}
            </button>
          </PopoverTrigger>
          <PopoverContent align="start" className="w-64 max-h-72 overflow-y-auto">
            <div className="flex flex-col gap-1 text-xs">
              {products.map((p) => <ProductLine key={p.product_id} p={p} />)}
            </div>
          </PopoverContent>
        </Popover>
      )}
    </div>
  );
}

// ── Page ──────────────────────────────────────────────────────────────────────

export function WaveOrdersPage() {
  const { t } = useTranslation('operations');
  const tAny = t as (key: string, opts?: Record<string, unknown>) => string;

  const waveId = useSelectedWaveId();
  const { data: orders, isLoading, isFetching, refetch } = useWaveOrders(waveId);
  const postpone = usePostponeWaveOrder();

  const [search, setSearch]         = useState('');
  const [zoneFilter, setZoneFilter] = useState<string | null>(null);
  const [pending, setPending]       = useState<WaveOrderEntry | null>(null);

  const unassignedZone = t($ => $.wave.orders.unassignedZone);
  const moreLabel = (n: number) => tAny('wave.orders.productsMore', { count: n });

  function confirmPostpone() {
    if (!pending || !waveId) return;
    const order = pending;
    setPending(null);
    postpone.mutate(
      { waveId, orderId: order.order_id },
      {
        onSuccess: () => toast.success(t($ => $.wave.orders.postpone.success)),
        onError: () => toast.error(t($ => $.wave.orders.postpone.error)),
      },
    );
  }

  // Order # · Customer · Delivery Zone · Products · Actions — nothing else.
  // Payment, Governorate and Added At are deliberately absent: preparation does not act
  // on them, and Delivery Zone is the operational geography level.
  const columns: DataGridColumnDef<WaveOrderEntry>[] = useMemo(() => [
    {
      key: 'order_number',
      label: t($ => $.wave.orders.columns.orderNo),
      alwaysVisible: true,
      cell: (o) => (
        <span className="font-mono text-sm font-medium">{o.order_number}</span>
      ),
    },
    {
      key: 'customer',
      label: t($ => $.wave.orders.columns.customer),
      defaultVisible: true,
      cell: (o) => (
        <span className="text-sm">
          {o.customer_name ?? <span className="text-muted-foreground">&mdash;</span>}
        </span>
      ),
    },
    {
      key: 'delivery_zone',
      label: t($ => $.wave.orders.columns.deliveryZone),
      defaultVisible: true,
      cell: (o) => (
        o.delivery_zone
          ? <span className="text-sm">{o.delivery_zone}</span>
          : <Badge variant="outline" className="text-[11px] font-normal text-muted-foreground">{unassignedZone}</Badge>
      ),
    },
    {
      key: 'products',
      label: t($ => $.wave.orders.columns.products),
      defaultVisible: true,
      cell: (o) => <OrderProducts products={o.products} moreLabel={moreLabel} />,
    },
    {
      key: 'actions',
      label: t($ => $.wave.orders.columns.actions),
      alwaysVisible: true,
      align: 'end',
      cell: (o) => (
        <Button
          variant="ghost"
          size="sm"
          className="h-7 gap-1.5 text-xs"
          title={t($ => $.wave.orders.postpone.tooltip)}
          disabled={postpone.isPending}
          onClick={() => setPending(o)}
        >
          <CalendarClock className="h-3.5 w-3.5" />
          {t($ => $.wave.orders.postpone.action)}
        </Button>
      ),
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps
  ], [t, unassignedZone, postpone.isPending]);

  const colMetas = useMemo(() => columns.map((c) => ({
    key: c.key,
    label: c.label,
    alwaysVisible: c.alwaysVisible,
    defaultVisible: c.defaultVisible,
  })), [columns]);

  const colVis = useColumnVisibility('wave-orders-cols-v2', colMetas);

  const allOrders = orders ?? [];

  const filtered = allOrders.filter((o) => {
    if (zoneFilter !== null) {
      const oZone = o.delivery_zone ?? unassignedZone;
      if (oZone !== zoneFilter) return false;
    }
    if (search) {
      const q = search.toLowerCase();
      return (
        o.order_number.toLowerCase().includes(q) ||
        (o.customer_name ?? '').toLowerCase().includes(q) ||
        (o.delivery_zone ?? '').toLowerCase().includes(q) ||
        o.products.some((p) => p.name.toLowerCase().includes(q))
      );
    }
    return true;
  });

  return (
    <div className="flex flex-col h-full">
      <SmartToolbar
        onRefresh={() => void refetch()}
        isFetching={isFetching}
        viewControls={
          // Column visibility applies to the desktop table only; mobile renders cards.
          <span className="hidden lg:flex">
            <ColumnVisibilityMenu
              columns={colMetas}
              visibility={colVis.visibility}
              onToggle={colVis.toggle}
              onReset={colVis.reset}
            />
          </span>
        }
      />

      <div className="flex items-center justify-between gap-3 px-4 py-2 border-b bg-muted/30 flex-wrap">
        <ZoneTabs
          orders={allOrders}
          zone={zoneFilter}
          onZone={setZoneFilter}
          allZonesLabel={t($ => $.wave.orders.allZones)}
          unzonedLabel={unassignedZone}
        />
        <div className="flex items-center gap-2 shrink-0">
          <Input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder={tAny('wave.orders.searchPlaceholder')}
            className="h-7 text-xs w-48"
          />
        </div>
      </div>

      <div className="flex-1 overflow-hidden">
        {!waveId ? (
          <div className="flex flex-col items-center justify-center h-64 gap-2 text-muted-foreground">
            <Waves className="h-8 w-8 opacity-30" />
            <p className="text-sm">{t($ => $.wave.orders.noWave)}</p>
          </div>
        ) : isLoading ? (
          <div className="flex items-center justify-center h-64 gap-2 text-muted-foreground">
            <Loader2 className="h-4 w-4 animate-spin" />
            <span className="text-sm">{t($ => $.wave.loading)}</span>
          </div>
        ) : (
          <UniversalDataGrid<WaveOrderEntry>
            columns={columns}
            data={filtered}
            rowId={(o) => o.id}
            loading={false}
            columnVisibility={colVis.visibility}
            renderMobileCard={(o) => (
              // Same fields and the SAME postpone action as the desktop row — no separate
              // mobile mutation or eligibility (§14/§18). Wave membership rules are untouched.
              <div role="listitem" className="border-b p-3.5 last:border-0 space-y-2">
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0">
                    <p className="font-mono text-sm font-medium">{o.order_number}</p>
                    <p className="text-xs text-muted-foreground truncate">
                      {o.customer_name ?? '—'}
                    </p>
                  </div>
                  {o.delivery_zone ? (
                    <span className="shrink-0 text-xs text-muted-foreground">{o.delivery_zone}</span>
                  ) : (
                    <Badge variant="outline" className="shrink-0 text-[10px] font-normal text-muted-foreground">
                      {unassignedZone}
                    </Badge>
                  )}
                </div>
                <OrderProducts products={o.products} moreLabel={moreLabel} />
                <div className="flex justify-end">
                  <Button
                    variant="outline"
                    size="sm"
                    className="h-8 gap-1.5 text-xs"
                    disabled={postpone.isPending}
                    onClick={() => setPending(o)}
                  >
                    <CalendarClock className="h-3.5 w-3.5" />
                    {t($ => $.wave.orders.postpone.action)}
                  </Button>
                </div>
              </div>
            )}
            emptyState={
              <div className="flex flex-col items-center justify-center py-16 gap-2 text-muted-foreground">
                <ShoppingCart className="w-8 h-8" />
                <p className="text-sm">
                  {allOrders.length === 0
                    ? t($ => $.wave.orders.emptyNoOrders)
                    : t($ => $.wave.orders.emptyNoMatch)}
                </p>
              </div>
            }
          />
        )}
      </div>

      <AlertDialog open={pending !== null} onOpenChange={(open) => { if (!open) setPending(null); }}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t($ => $.wave.orders.postpone.title)}</AlertDialogTitle>
            <AlertDialogDescription>
              {t($ => $.wave.orders.postpone.body)}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            {/* Cancel closes the dialog and changes nothing — no request is sent. */}
            <AlertDialogCancel>{t($ => $.wave.orders.postpone.cancel)}</AlertDialogCancel>
            <AlertDialogAction onClick={confirmPostpone}>
              {t($ => $.wave.orders.postpone.confirm)}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}
