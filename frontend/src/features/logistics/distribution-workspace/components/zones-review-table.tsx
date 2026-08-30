import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Check, ChevronDown, Loader2, MapPin, Search, X } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';

import { UniversalDataGrid } from '@/components/data-grid/universal-data-grid';
import type { DataGridColumnDef } from '@/components/data-grid/types';
import { PhoneCell } from '@/components/ecos/phone-cell';
import { OrderStatusBadge } from '@/features/orders/components/order-status-badge';
import type { OrderStatus } from '@/features/orders/types/order';
import { useFormatter } from '@/hooks/use-formatter';
import { useDistributionZones } from '@/features/logistics/distribution-zones/hooks/use-distribution-zones';
import { useGovernorates, useCities } from '@/features/logistics/geography/hooks/use-geography';

import { OrderAddressCell } from './order-address-cell';
import { useChangeOrderZone, usePatchOrderGeography } from '../hooks/use-distribution-workspace';
import type { DistributionOrder, SlotSummary } from '../types';

/**
 * Display text for the stored payment method — reuses the workspace's existing
 * payment i18n contract (`distributionWorkspace.payment.method.*`). Display only:
 * an unknown value is shown as stored, never mapped to a guess, so no second
 * payment vocabulary is created here.
 */
const PAYMENT_METHOD_KEY: Record<string, (($: typeof import('@/i18n/locales/en/logistics.json')) => string)> = {
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

/**
 * The operational Zones table (TASK-DISTRIBUTION-ZONES-TABLE-UX-001).
 *
 * A searchable, cell-level-editable view of the window's orders. Every edit reuses
 * an EXISTING contract:
 *   • Zone        → `changeOrderZone` (PATCH /assignments/{id}/zone) — the backend
 *                   re-syncs the Group and enforces capacity.
 *   • Governorate → Orders quick-update `{ governorate }` (city cleared, because a
 *                   city belongs to one governorate — §11 never persists an invalid
 *                   Giza+Maadi pair).
 *   • City        → Orders quick-update `{ city, governorate }` — the Geography
 *                   binder re-resolves logistics_city_id and Distribution re-zones.
 *
 * Nothing here writes a zone, a group, a trip, coordinates or an order status. The
 * geography edits go through the same canonical Order address the Orders page reads,
 * and both query roots are invalidated so the two workspaces stay one source of truth.
 */

type Option = { id: number; label: string };

function errorMessage(e: unknown, fallback: string): string {
  return (e as { response?: { data?: { message?: string } } })?.response?.data?.message ?? fallback;
}

/** Case/whitespace-insensitive key, matching how the backend resolver compares names. */
function normaliseName(v: string | null | undefined): string {
  return (v ?? '').trim().toLowerCase();
}

// ── Generic inline single-select cell ─────────────────────────────────────────

function InlineSelectCell({
  open,
  setOpen,
  displayValue,
  emptyLabel,
  currentId,
  options,
  optionsLoading,
  disabled,
  disabledHint,
  ariaLabel,
  savedLabel,
  failedFallback,
  loadingLabel,
  onSelect,
  testId,
}: {
  open: boolean;
  setOpen: (open: boolean) => void;
  displayValue: string | null;
  emptyLabel: string;
  currentId: number | null;
  options: Option[];
  optionsLoading: boolean;
  disabled?: boolean;
  disabledHint?: string;
  ariaLabel: string;
  savedLabel: string;
  failedFallback: string;
  loadingLabel: string;
  onSelect: (id: number) => Promise<unknown>;
  testId?: string;
}) {
  const [status, setStatus] = useState<'idle' | 'saving' | 'saved' | 'error'>('idle');
  const [errorText, setErrorText] = useState<string | null>(null);

  async function choose(id: number) {
    if (id === currentId) {
      setOpen(false);
      return;
    }
    setOpen(false);
    setStatus('saving');
    setErrorText(null);
    try {
      await onSelect(id);
      setStatus('saved');
      window.setTimeout(() => setStatus((s) => (s === 'saved' ? 'idle' : s)), 1600);
    } catch (e) {
      // §12 — do NOT show success on failure. The row keeps the server value
      // (no refetch happened), so the displayed value is effectively reverted;
      // the backend message is surfaced beneath it.
      setErrorText(errorMessage(e, failedFallback));
      setStatus('error');
    }
  }

  if (disabled) {
    return (
      <span className="text-xs text-muted-foreground" data-testid={testId}>
        {disabledHint ?? emptyLabel}
      </span>
    );
  }

  return (
    <div className="flex flex-col gap-0.5" data-testid={testId}>
      <DropdownMenu open={open} onOpenChange={setOpen}>
        <DropdownMenuTrigger asChild>
          <button
            type="button"
            aria-label={ariaLabel}
            onMouseDown={(e) => e.stopPropagation()}
            className={cn(
              'inline-flex max-w-[180px] items-center gap-1 rounded px-1.5 py-0.5 text-xs',
              'hover:bg-accent focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring',
              status === 'error' && 'text-destructive',
            )}
          >
            <span className={cn('truncate', displayValue ? 'font-medium' : 'text-muted-foreground')}>
              {displayValue ?? emptyLabel}
            </span>
            {status === 'saving' ? (
              <Loader2 className="size-3 shrink-0 animate-spin" aria-hidden />
            ) : (
              <ChevronDown className="size-3 shrink-0 opacity-60" aria-hidden />
            )}
          </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="start" className="max-h-64 w-56 overflow-y-auto">
          {optionsLoading ? (
            <div className="flex items-center gap-2 px-2 py-1.5 text-xs text-muted-foreground">
              <Loader2 className="size-3 animate-spin" aria-hidden />
              {loadingLabel}
            </div>
          ) : (
            options.map((o) => (
              <DropdownMenuItem
                key={o.id}
                onSelect={() => void choose(o.id)}
                className="text-xs"
                data-testid={testId ? `${testId}-option-${o.id}` : undefined}
              >
                <Check
                  className={cn('size-3.5', o.id === currentId ? 'opacity-100' : 'opacity-0')}
                  aria-hidden
                />
                {o.label}
              </DropdownMenuItem>
            ))
          )}
        </DropdownMenuContent>
      </DropdownMenu>

      {status === 'saved' ? (
        <span className="inline-flex items-center gap-0.5 text-[10px] text-emerald-600 dark:text-emerald-400">
          <Check className="size-2.5" aria-hidden />
          {savedLabel}
        </span>
      ) : null}
      {status === 'error' && errorText ? (
        <span className="inline-flex items-start gap-0.5 text-[10px] text-destructive">
          <X className="mt-px size-2.5 shrink-0" aria-hidden />
          {errorText}
        </span>
      ) : null}
    </div>
  );
}

// ── Per-column cells ──────────────────────────────────────────────────────────

function ZoneCell({
  order,
  zoneOptions,
  zonesLoading,
}: {
  order: DistributionOrder;
  zoneOptions: Option[];
  zonesLoading: boolean;
}) {
  const { t } = useTranslation('logistics');
  const [open, setOpen] = useState(false);
  const changeZone = useChangeOrderZone();

  return (
    <InlineSelectCell
      open={open}
      setOpen={setOpen}
      displayValue={order.zone_name}
      emptyLabel={t(($) => $.distributionWorkspace.zonesTable.unassigned)}
      currentId={order.zone_id}
      options={zoneOptions}
      optionsLoading={zonesLoading}
      ariaLabel={t(($) => $.distributionWorkspace.zonesTable.editZone)}
      savedLabel={t(($) => $.distributionWorkspace.zonesTable.saved)}
      failedFallback={t(($) => $.distributionWorkspace.zonesTable.saveFailed)}
      loadingLabel={t(($) => $.distributionWorkspace.zonesTable.loadingOptions)}
      onSelect={(zoneId) =>
        changeZone.mutateAsync({ assignmentId: order.assignment_id, zoneId })
      }
      testId={`zone-cell-${order.order_number}`}
    />
  );
}

function GovernorateCell({
  order,
  govOptions,
  govsLoading,
  govNameById,
}: {
  order: DistributionOrder;
  govOptions: Option[];
  govsLoading: boolean;
  govNameById: Map<number, string>;
}) {
  const { t } = useTranslation('logistics');
  const [open, setOpen] = useState(false);
  const patchGeo = usePatchOrderGeography();

  return (
    <InlineSelectCell
      open={open}
      setOpen={setOpen}
      displayValue={order.governorate_name ?? order.shipping_address.governorate}
      emptyLabel={t(($) => $.distributionWorkspace.zonesTable.unassigned)}
      currentId={order.governorate_id}
      options={govOptions}
      optionsLoading={govsLoading}
      ariaLabel={t(($) => $.distributionWorkspace.zonesTable.editGovernorate)}
      savedLabel={t(($) => $.distributionWorkspace.zonesTable.saved)}
      failedFallback={t(($) => $.distributionWorkspace.zonesTable.saveFailed)}
      loadingLabel={t(($) => $.distributionWorkspace.zonesTable.loadingOptions)}
      onSelect={(govId) =>
        // Changing governorate clears the city: a city belongs to exactly one
        // governorate, so the old city cannot remain valid. The operator then picks
        // a city from the new governorate's list. Canonical name so the binder
        // re-resolves; the Distribution zone follows via SyncOrderGeographyListener.
        patchGeo.mutateAsync({
          id: order.order_id,
          data: { governorate: govNameById.get(govId) ?? '', city: null },
        })
      }
      testId={`governorate-cell-${order.order_number}`}
    />
  );
}

function CityCell({
  order,
  effectiveGovId,
  govNameForRow,
}: {
  order: DistributionOrder;
  effectiveGovId: number | null;
  govNameForRow: string | null;
}) {
  const { t } = useTranslation('logistics');
  const [open, setOpen] = useState(false);
  const patchGeo = usePatchOrderGeography();

  // Cities are fetched ONLY while the dropdown is open, keyed by governorate — so
  // rows in the same governorate share one cached request and a closed cell costs
  // nothing. Depends on the governorate (§4): no governorate, no city list.
  const citiesQuery = useCities(open ? effectiveGovId : null, { per_page: 100 });
  const cityOptions: Option[] = useMemo(
    () =>
      (citiesQuery.data?.data ?? []).map((c) => ({
        id: c.id,
        label: c.name_en || c.name_ar,
      })),
    [citiesQuery.data],
  );

  return (
    <InlineSelectCell
      open={open}
      setOpen={setOpen}
      displayValue={order.city_name ?? order.city_text}
      emptyLabel={t(($) => $.distributionWorkspace.zonesTable.selectCity)}
      currentId={order.city_id}
      options={cityOptions}
      optionsLoading={citiesQuery.isLoading}
      disabled={effectiveGovId === null}
      disabledHint={t(($) => $.distributionWorkspace.zonesTable.selectGovernorateFirst)}
      ariaLabel={t(($) => $.distributionWorkspace.zonesTable.editCity)}
      savedLabel={t(($) => $.distributionWorkspace.zonesTable.saved)}
      failedFallback={t(($) => $.distributionWorkspace.zonesTable.saveFailed)}
      loadingLabel={t(($) => $.distributionWorkspace.zonesTable.loadingOptions)}
      onSelect={(cityId) => {
        const chosen = (citiesQuery.data?.data ?? []).find((c) => c.id === cityId);
        const cityName = chosen ? chosen.name_en || chosen.name_ar : '';
        // Send BOTH so the resolver can narrow an ambiguous city name by governorate.
        return patchGeo.mutateAsync({
          id: order.order_id,
          data: { city: cityName, governorate: govNameForRow ?? '' },
        });
      }}
      testId={`city-cell-${order.order_number}`}
    />
  );
}

function LocationCell({ order }: { order: DistributionOrder }) {
  const { t } = useTranslation('logistics');

  if (order.latitude === null || order.longitude === null) {
    return (
      <span className="text-xs text-muted-foreground">
        {t(($) => $.distributionWorkspace.zonesTable.locationUnavailable)}
      </span>
    );
  }

  // Reuse the project's Google Maps convention (?q=lat,lng); prefer the stored URL.
  const href = order.google_maps_url ?? `https://www.google.com/maps?q=${order.latitude},${order.longitude}`;

  return (
    <a
      href={href}
      target="_blank"
      rel="noopener noreferrer"
      onMouseDown={(e) => e.stopPropagation()}
      title={t(($) => $.distributionWorkspace.zonesTable.openLocation)}
      className="inline-flex items-center gap-1 text-xs text-primary hover:underline"
    >
      <MapPin className="size-3" aria-hidden />
      {t(($) => $.distributionWorkspace.zonesTable.location)}
    </a>
  );
}

/** Payment state (Paid / Partial / Unpaid) + method — reuses existing payment i18n. */
function PaymentStatusCell({ order }: { order: DistributionOrder }) {
  const { t } = useTranslation('logistics');
  const paid = order.payment_state === 'paid';
  const partial = order.payment_state === 'partially_paid';
  const methodKey = order.payment_method_effective
    ? PAYMENT_METHOD_KEY[order.payment_method_effective.toLowerCase()]
    : undefined;

  return (
    <div className="flex flex-col gap-0.5">
      <Badge variant={paid ? 'default' : partial ? 'secondary' : 'outline'} className="w-fit">
        {paid
          ? t(($) => $.distributionWorkspace.payment.paid)
          : partial
            ? t(($) => $.distributionWorkspace.payment.partiallyPaid)
            : t(($) => $.distributionWorkspace.payment.unpaid)}
      </Badge>
      <span className="text-xs text-muted-foreground">
        {methodKey ? t(methodKey) : (order.payment_method_effective ?? '—')}
      </span>
    </div>
  );
}

// ── Table ──────────────────────────────────────────────────────────────────────

export function ZonesReviewTable({
  orders,
  groups,
  ordersLoading,
  ordersError,
  onOpenOrder,
}: {
  orders: DistributionOrder[];
  groups: SlotSummary[];
  ordersLoading: boolean;
  ordersError: boolean;
  /** Opens the canonical Order detail drawer. Reused from the page's existing state. */
  onOpenOrder?: (orderId: string) => void;
}) {
  const { t } = useTranslation('logistics');
  const { money } = useFormatter();
  const [search, setSearch] = useState('');

  // Canonical option sources — fetched once for the whole table.
  const { data: zonesResult, isLoading: zonesLoading } = useDistributionZones({
    status: 'active',
    per_page: 100,
  });
  const { data: govResult, isLoading: govsLoading } = useGovernorates({
    status: 'active',
    per_page: 100,
  });

  const zoneOptions: Option[] = useMemo(
    () =>
      (zonesResult?.data ?? []).map((z) => ({ id: z.id, label: z.name_en ?? z.name_ar ?? z.code })),
    [zonesResult],
  );

  const govOptions: Option[] = useMemo(
    () => (govResult?.data ?? []).map((g) => ({ id: g.id, label: g.name_en || g.name_ar })),
    [govResult],
  );

  // Governorate id ↔ name lookups, so an order whose city is unresolved (governorate
  // held only as text) can still drive the cascading City list.
  const govNameById = useMemo(() => {
    const m = new Map<number, string>();
    for (const g of govResult?.data ?? []) m.set(g.id, g.name_en || g.name_ar);
    return m;
  }, [govResult]);

  const govIdByName = useMemo(() => {
    const m = new Map<string, number>();
    for (const g of govResult?.data ?? []) {
      m.set(normaliseName(g.name_en), g.id);
      m.set(normaliseName(g.name_ar), g.id);
    }
    return m;
  }, [govResult]);

  // slot id → group, for the Group column (a row's actual group is its slot).
  const groupBySlot = useMemo(() => {
    const m = new Map<string, SlotSummary>();
    for (const g of groups) m.set(g.slot_id, g);
    return m;
  }, [groups]);

  const filtered = useMemo(() => {
    const q = normaliseName(search);
    if (q === '') return orders;
    return orders.filter((o) => {
      const haystack = [
        o.zone_name,
        o.zone_id === null ? null : String(o.zone_id),
        o.governorate_name,
        o.shipping_address.governorate,
        o.city_name,
        o.city_text,
        o.shipping_address.city,
      ]
        .filter(Boolean)
        .map((v) => normaliseName(v as string))
        .join(' | ');
      return haystack.includes(q);
    });
  }, [orders, search]);

  // The nine approved columns, in the exact order required by
  // TASK-DISTRIBUTION-ZONES-ORDERS-PANEL-UX-004 §6. Products / Phone / Status /
  // Warehouse are no longer standalone columns — status sits under the order
  // number, phone under the customer, and Products lives only in the summary card.
  const columns = useMemo<DataGridColumnDef<DistributionOrder>[]>(() => {
    return [
      // 1 — Order number + status underneath (canonical OrderStatusBadge).
      {
        key: 'order_number',
        label: t(($) => $.distributionWorkspace.columns.order),
        alwaysVisible: true,
        cell: (o) => (
          <div className="flex flex-col items-start gap-0.5">
            {onOpenOrder ? (
              <button
                type="button"
                onMouseDown={(e) => e.stopPropagation()}
                onClick={() => onOpenOrder(o.order_id)}
                className="font-medium underline-offset-2 hover:text-primary hover:underline"
                data-testid={`order-open-${o.order_number}`}
              >
                {o.order_number}
              </button>
            ) : (
              <span className="font-medium">{o.order_number}</span>
            )}
            <OrderStatusBadge status={o.order_status as OrderStatus} />
          </div>
        ),
      },
      // 2 — Order value (canonical money formatting).
      {
        key: 'order_value',
        label: t(($) => $.distributionWorkspace.zonesTable.colOrderValue),
        align: 'end',
        cell: (o) => <span className="tabular-nums">{money(o.total)}</span>,
      },
      // 3 — Payment status + method.
      {
        key: 'payment_status',
        label: t(($) => $.distributionWorkspace.zonesTable.colPaymentStatus),
        cell: (o) => <PaymentStatusCell order={o} />,
      },
      // 4 — Customer name + interactive phone underneath.
      {
        key: 'customer',
        label: t(($) => $.distributionWorkspace.columns.customer),
        cell: (o) => (
          <div className="flex flex-col gap-0.5">
            <span className="font-medium">{o.customer_name ?? '—'}</span>
            <PhoneCell
              phone={o.phone}
              labels={{
                call: t(($) => $.distributionWorkspace.zonesTable.phone.call),
                whatsapp: t(($) => $.distributionWorkspace.zonesTable.phone.whatsapp),
                copy: t(($) => $.distributionWorkspace.zonesTable.phone.copy),
                copied: t(($) => $.distributionWorkspace.zonesTable.phone.copied),
              }}
            />
          </div>
        ),
      },
      // 5 — Full shipping address (existing renderer; nothing reconstructed).
      {
        key: 'shipping_address',
        label: t(($) => $.distributionWorkspace.columns.shippingAddress),
        minWidth: 240,
        cell: (o) => <OrderAddressCell address={o.shipping_address} />,
      },
      // 6 — Location link (coordinate-based; "unavailable" when absent).
      {
        key: 'location',
        label: t(($) => $.distributionWorkspace.zonesTable.colLocation),
        cell: (o) => <LocationCell order={o} />,
      },
      // 7 — City / Governorate, both inline-editable (City on top).
      {
        key: 'city_governorate',
        label: t(($) => $.distributionWorkspace.columns.cityGovernorate),
        minWidth: 170,
        cell: (o) => {
          const effectiveGovId =
            o.governorate_id ?? govIdByName.get(normaliseName(o.shipping_address.governorate)) ?? null;
          const govNameForRow =
            (effectiveGovId !== null ? govNameById.get(effectiveGovId) : null) ??
            o.governorate_name ??
            o.shipping_address.governorate;
          return (
            <div className="flex flex-col gap-0.5">
              <CityCell order={o} effectiveGovId={effectiveGovId} govNameForRow={govNameForRow} />
              <GovernorateCell
                order={o}
                govOptions={govOptions}
                govsLoading={govsLoading}
                govNameById={govNameById}
              />
            </div>
          );
        },
      },
      // 8 — Zone, inline-editable (existing Change-Zone contract).
      {
        key: 'zone',
        label: t(($) => $.distributionWorkspace.columns.zone),
        cell: (o) => <ZoneCell order={o} zoneOptions={zoneOptions} zonesLoading={zonesLoading} />,
      },
      // 9 — Group (display only; canonical slot→group relation).
      {
        key: 'group',
        label: t(($) => $.distributionWorkspace.zonesTable.colGroup),
        cell: (o) => {
          const group = o.virtual_slot_id ? groupBySlot.get(o.virtual_slot_id) : undefined;
          if (!group) {
            return (
              <span className="text-xs text-muted-foreground">
                {t(($) => $.distributionWorkspace.zonesTable.noGroup)}
              </span>
            );
          }
          return (
            <Badge variant="outline" className="text-xs">
              {group.name ? `${group.code} — ${group.name}` : group.code}
            </Badge>
          );
        },
      },
    ];
  }, [t, money, onOpenOrder, govOptions, govsLoading, govNameById, govIdByName, zoneOptions, zonesLoading, groupBySlot]);

  return (
    <div className="space-y-3" data-testid="zones-review-table">
      <div className="flex items-center gap-2">
        <div className="relative w-full max-w-sm">
          <Search className="absolute start-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
          <Input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder={t(($) => $.distributionWorkspace.zonesTable.search)}
            className="ps-8"
            data-testid="zones-table-search"
          />
        </div>
        <span className="text-xs text-muted-foreground" data-testid="zones-table-count">
          {t(($) => $.distributionWorkspace.zonesTable.resultCount, { count: filtered.length })}
        </span>
      </div>

      <Card className="p-0">
        <UniversalDataGrid
          data={filtered}
          columns={columns}
          rowId={(o) => o.assignment_id}
          loading={ordersLoading}
          error={ordersError}
          emptyState={
            <div className="p-8 text-center text-sm text-muted-foreground">
              {t(($) => $.distributionWorkspace.zonesTable.empty)}
            </div>
          }
        />
      </Card>
    </div>
  );
}
