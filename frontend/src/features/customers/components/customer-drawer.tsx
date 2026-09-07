import {
  Ban,
  Calendar,
  Copy,
  FileText,
  MapPin,
  MessageCircle,
  Pencil,
  Phone,
  Repeat,
  ShoppingBag,
  X,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/crud';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/crud/status-badge';
import { Skeleton } from '@/components/ui/skeleton';
import {
  Sheet,
  SheetClose,
  SheetContent,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet';
import { Tabs } from '@/components/ds/tabs';
import { toast } from '@/components/ds/use-toast';
import { copyToClipboard } from '@/lib/clipboard';
import { MobileDetailSection } from '@/components/mobile';
import { useIsMobile } from '@/hooks/use-is-mobile';
import { OrderStatusBadge } from '@/features/orders/components/order-status-badge';
import { useOrdersQuery } from '@/features/orders/hooks/use-orders';
import { usePermission } from '@/features/authorization/use-authorization';
import { useCustomerBlockHistory, useCustomerQuery, useUnblockCustomer } from '../hooks/use-customers';
import type { Customer } from '@/features/customers/types/customer';
import { ROUTES } from '@/router/routes';

type Props = {
  customer: Customer | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onEdit: (customer: Customer) => void;
  defaultTab?: string;
};

// ── Shared phone row ──────────────────────────────────────────────────────────

function PhoneRow({
  phone,
  label,
}: {
  phone: string;
  label: string;
}) {
  const { t } = useTranslation('customers');
  const [copied, setCopied] = useState(false);
  const bare = phone.replace(/\D/g, '');

  const doCopy = () => {
    void copyToClipboard(phone).then((ok) => {
      if (ok) {
        setCopied(true);
        toast.success(t($ => $.phone.copySuccess));
        setTimeout(() => setCopied(false), 1500);
      } else {
        toast.error(t($ => $.phone.copyError));
      }
    });
  };

  return (
    <div className="flex items-center gap-2">
      <span className="flex-1 font-mono text-sm">{phone}</span>
      <Badge variant="secondary" className="h-4 shrink-0 px-1.5 text-[9px]">
        {label}
      </Badge>
      <Button size="icon" variant="ghost" className="size-7" asChild title={t($ => $.phone.call)}>
        <a href={`tel:${bare}`}>
          <Phone className="size-3.5" />
        </a>
      </Button>
      <Button size="icon" variant="ghost" className="size-7" asChild title={t($ => $.phone.whatsapp)}>
        <a href={`https://wa.me/${bare}`} target="_blank" rel="noopener noreferrer">
          <MessageCircle className="size-3.5" />
        </a>
      </Button>
      <Button size="icon" variant="ghost" className="size-7" onClick={doCopy} title={t($ => $.phone.copy)}>
        {copied ? (
          <span className="text-[9px] text-emerald-600">✓</span>
        ) : (
          <Copy className="size-3.5" />
        )}
      </Button>
    </div>
  );
}

// ── Blocked Customer card (TASK-...-BLOCKED-CUSTOMERS-009 §41) ────────────────

function BlockedCard({ customer }: { customer: Customer }) {
  const { t } = useTranslation('customers');
  const { can } = usePermission();
  const canUnblock = can('crm.customers.unblock');
  const { data: history = [] } = useCustomerBlockHistory(customer.id, true);
  const unblockCustomer = useUnblockCustomer();
  const [unblocking, setUnblocking] = useState(false);
  const [unblockReason, setUnblockReason] = useState('');

  if (!customer.is_blocked && history.length === 0) {
    return null;
  }

  return (
    <div
      className={cnBorder(customer.is_blocked)}
    >
      <div className="flex items-center justify-between">
        <p className="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
          <Ban className="size-3.5" />
          {t($ => $.drawer.blocked.title)}
        </p>
        {customer.is_blocked ? (
          <Badge
            variant="secondary"
            className="h-5 gap-1 px-1.5 text-[10px] text-red-700 bg-red-100 border-red-200 dark:text-red-400 dark:bg-red-950/50 dark:border-red-800"
          >
            <Ban className="size-3" />
            {t($ => $.drawer.blocked.badge)}
          </Badge>
        ) : null}
      </div>

      {customer.is_blocked ? (
        <div className="flex flex-col gap-2 text-sm">
          <InfoRow label={t($ => $.drawer.blocked.reason)} value={customer.block_reason || t($ => $.blocked.noReasonShort)} />
          {/* TASK-...-FINAL-UI-CLOSURE-014 (§5) — canonical actor identity, never a raw id. */}
          <InfoRow label={t($ => $.drawer.blocked.blockedBy)} value={customer.blocked_by_name ?? '—'} />
          <InfoRow
            label={t($ => $.drawer.blocked.blockedAt)}
            value={customer.blocked_at ? new Date(customer.blocked_at).toLocaleString() : '—'}
          />
          {canUnblock ? (
            <Button
              size="sm"
              variant="outline"
              className="h-7 w-fit gap-1.5 text-xs"
              onClick={() => { setUnblockReason(''); setUnblocking(true); }}
            >
              {t($ => $.drawer.blocked.unblockAction)}
            </Button>
          ) : null}
        </div>
      ) : null}

      {history.length > 0 ? (
        <div className="flex flex-col gap-1 border-t pt-2">
          <p className="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
            {t($ => $.drawer.blocked.history)}
          </p>
          <ul className="flex flex-col gap-1 text-xs text-muted-foreground">
            {history.flatMap((episode) => {
              const rows = [
                <li key={`${episode.id}-blocked`}>
                  {t($ => $.drawer.blocked.historyBlockedEntry, {
                    reason: episode.block_reason,
                    actor: episode.blocked_by_name ?? '—',
                  })}
                  {' · '}
                  {new Date(episode.blocked_at).toLocaleDateString()}
                </li>,
              ];
              if (episode.unblocked_at) {
                rows.push(
                  <li key={`${episode.id}-unblocked`}>
                    {t($ => $.drawer.blocked.historyUnblockedEntry, {
                      reason: episode.unblock_reason ?? '',
                      actor: episode.unblocked_by_name ?? '—',
                    })}
                    {' · '}
                    {new Date(episode.unblocked_at).toLocaleDateString()}
                  </li>,
                );
              }
              return rows;
            })}
          </ul>
        </div>
      ) : null}

      <ConfirmDialog
        open={unblocking}
        onOpenChange={setUnblocking}
        title={t($ => $.blocked.unblockDialog.title)}
        description={
          <>
            {t($ => $.blocked.unblockDialog.description, { name: customer.name })}
            <Input
              autoFocus
              placeholder={t($ => $.blocked.reasonPlaceholder)}
              value={unblockReason}
              onChange={(e) => setUnblockReason(e.target.value)}
              className="mt-2"
            />
          </>
        }
        confirmLabel={t($ => $.drawer.blocked.unblockAction)}
        loading={unblockCustomer.isPending}
        confirmDisabled={unblockReason.trim() === ''}
        onConfirm={() => {
          if (!customer.customer_block_id) return;
          unblockCustomer.mutate(
            { id: customer.id, blockId: customer.customer_block_id, reason: unblockReason.trim() },
            { onSuccess: () => setUnblocking(false) },
          );
        }}
      />
    </div>
  );
}

function cnBorder(blocked: boolean): string {
  return blocked
    ? 'flex flex-col gap-2 rounded-lg border border-red-200 bg-red-50/50 p-3 dark:border-red-900 dark:bg-red-950/20'
    : 'flex flex-col gap-2 rounded-lg border p-3';
}

// ── Summary tab ───────────────────────────────────────────────────────────────

function SummaryTab({ customer }: { customer: Customer }) {
  const { t } = useTranslation('customers');

  return (
    <div className="flex flex-col gap-4 p-4">
      {/* Identity */}
      <div className="flex items-center gap-3">
        <div className="flex size-12 shrink-0 items-center justify-center rounded-full bg-primary/10 text-base font-semibold text-primary">
          {customer.name.slice(0, 2).toUpperCase()}
        </div>
        <div className="min-w-0 flex-1">
          <p className="truncate font-semibold">{customer.name}</p>
          <p className="text-xs text-muted-foreground">{customer.code}</p>
        </div>
        <StatusBadge status={customer.is_active ? 'active' : 'inactive'} className="ms-auto shrink-0" />
      </div>

      {/* Blocked Customer (TASK-...-BLOCKED-CUSTOMERS-009 §41) */}
      <BlockedCard customer={customer} />

      {/* Order KPIs — every figure computed by CustomerOrderMetricsService and rendered
          as-is. Previously this fetched up to 200 orders and summed them in the browser,
          which silently truncated any customer past that page size. */}
      <div className="grid grid-cols-3 gap-2 rounded-lg border p-3">
        <Kpi label={t($ => $.drawer.summary.totalOrders)} value={String(customer.orders_count)} />
        <Kpi label={t($ => $.drawer.summary.totalSpend)} value={fmtNum(customer.total_order_value, 2)} />
        <Kpi label={t($ => $.columns.receivingRate)}
             value={customer.receiving_rate === null ? '—' : `${customer.receiving_rate}%`} />
        <Kpi label={t($ => $.drawer.summary.delivered)} value={String(customer.delivered_count)} />
        <Kpi label={t($ => $.drawer.summary.avgOrderValue)} value={fmtNum(customer.average_order_value, 2)} />
        <Kpi
          label={t($ => $.drawer.summary.lastOrder)}
          value={customer.last_order_at ? new Date(customer.last_order_at).toLocaleDateString() : '—'}
        />
      </div>

      {/* Customer Intelligence — repeat status, first order, purchase cadence. Total
          spend/orders/last order already live in the KPI grid above; this card adds only
          the pieces that grid doesn't cover. All figures computed server-side by
          CustomerOrderMetricsService — never re-derived here. */}
      <div className="flex flex-col gap-1.5 rounded-lg border p-3">
        <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
          {t($ => $.columns.intelligence)}
        </p>
        <div className="flex flex-col gap-2 text-sm">
          <div className="flex items-center gap-2">
            <span className="w-28 shrink-0 text-xs text-muted-foreground">
              {t($ => $.drawer.summary.repeatStatus)}
            </span>
            {customer.is_repeat_customer ? (
              <Badge
                variant="secondary"
                className="h-5 gap-1 px-1.5 text-[10px] text-emerald-700 bg-emerald-100 border-emerald-200 dark:text-emerald-400 dark:bg-emerald-950/50 dark:border-emerald-800"
              >
                <Repeat className="size-3" />
                {t($ => $.intelligence.repeat)}
              </Badge>
            ) : (
              <span className="text-sm text-muted-foreground">{t($ => $.drawer.summary.notRepeat)}</span>
            )}
          </div>
          <InfoRow
            label={t($ => $.drawer.summary.firstOrder)}
            value={customer.first_order_at ? new Date(customer.first_order_at).toLocaleDateString() : '—'}
          />
          <InfoRow
            label={t($ => $.drawer.summary.purchaseCadence)}
            value={
              customer.avg_days_between_orders === null
                ? t($ => $.drawer.summary.cadenceUnavailable)
                : t($ => $.drawer.summary.cadenceDays, { count: customer.avg_days_between_orders })
            }
          />
        </div>
      </div>

      {/* Address + Location */}
      <div className="flex flex-col gap-2 rounded-lg border p-3 text-sm">
        <div className="flex items-start gap-2">
          <span className="w-28 shrink-0 text-xs text-muted-foreground">
            {t($ => $.columns.fullAddress)}
          </span>
          <span className="text-sm">{customer.full_address ?? '—'}</span>
        </div>
        <div className="flex items-start gap-2">
          <span className="w-28 shrink-0 text-xs text-muted-foreground">
            {t($ => $.drawer.summary.preferredGovernorate)}
          </span>
          {/* Most frequent orders.governorate — computed server-side, never ranked here. */}
          <span className="text-sm">{customer.preferred_governorate ?? '—'}</span>
        </div>
        <div className="flex items-start gap-2">
          <span className="w-28 shrink-0 text-xs text-muted-foreground">
            {t($ => $.columns.location)}
          </span>
          {customer.location_url ? (
            <a
              href={customer.location_url}
              target="_blank"
              rel="noopener noreferrer"
              className="inline-flex items-center gap-1 text-sm text-primary hover:underline"
            >
              <MapPin className="size-3.5" />
              {t($ => $.drawer.summary.openInMaps)}
            </a>
          ) : (
            <span className="text-sm text-muted-foreground">—</span>
          )}
        </div>
      </div>

      {/* Info rows */}
      <div className="flex flex-col gap-2.5 rounded-lg border p-3 text-sm">
        {customer.code ? (
          <InfoRow label={t($ => $.drawer.summary.code)} value={customer.code} />
        ) : null}
        {/* Sales Owner — denormalised sales_owner_name, null until a future task adds the
            assignment action. Always shown (not gated) so "Unassigned" is visible by default. */}
        <InfoRow
          label={t($ => $.columns.salesOwner)}
          value={customer.sales_owner_name ?? t($ => $.table.unassigned)}
        />
        {customer.contact_person ? (
          <InfoRow label={t($ => $.drawer.summary.contactPerson)} value={customer.contact_person} />
        ) : null}
        {customer.email ? (
          <InfoRow label={t($ => $.drawer.summary.email)} value={customer.email} />
        ) : null}
        {customer.created_at ? (
          <InfoRow
            label={t($ => $.drawer.summary.memberSince)}
            value={new Date(customer.created_at).toLocaleDateString()}
          />
        ) : null}
        {customer.updated_at ? (
          <InfoRow
            label={t($ => $.drawer.summary.lastUpdated)}
            value={new Date(customer.updated_at).toLocaleDateString()}
          />
        ) : null}
      </div>

      {/* Brands */}
      {customer.brands && customer.brands.length > 0 ? (
        <div className="flex flex-col gap-1.5 rounded-lg border p-3">
          <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
            {t($ => $.drawer.summary.brands, { defaultValue: 'Brands' })}
          </p>
          <div className="flex flex-col gap-1.5">
            {customer.brands.map((b) => (
              <div
                key={b.id}
                className="flex items-center justify-between gap-2 rounded-md border bg-muted/20 px-2.5 py-1.5"
              >
                <span className="flex min-w-0 items-center gap-1.5 truncate text-xs font-medium">
                  {b.is_primary && <span className="text-primary">★</span>}
                  <span className="truncate">{b.brand_name ?? b.brand_code ?? '—'}</span>
                </span>
                <span className="flex shrink-0 items-center gap-3 text-[11px] text-muted-foreground">
                  <span>{b.orders_count} {t($ => $.drawer.summary.brandOrders, { defaultValue: 'orders' })}</span>
                  <span className="font-medium tabular-nums text-foreground">
                    {Number(b.lifetime_value ?? 0).toLocaleString(undefined, { maximumFractionDigits: 0 })}
                  </span>
                  {b.last_order_at && (
                    <span className="hidden sm:inline">
                      {new Date(b.last_order_at).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })}
                    </span>
                  )}
                </span>
              </div>
            ))}
          </div>
        </div>
      ) : null}

      {/* Channels — derived read over this customer's own order history
          (CustomerOrderMetricsService::channelsForCustomers), most-used first. */}
      {customer.channels && customer.channels.length > 0 ? (
        <div className="flex flex-col gap-1.5 rounded-lg border p-3">
          <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
            {t($ => $.columns.channels)}
          </p>
          <div className="flex flex-wrap gap-1.5">
            {customer.channels.map((c) => (
              <Badge key={c.channel_id} variant="secondary" className="h-5 gap-1 px-1.5 text-[10px]">
                {c.channel_name ?? '—'}
                <span className="text-muted-foreground">({c.orders_count})</span>
              </Badge>
            ))}
          </div>
        </div>
      ) : null}
    </div>
  );
}

// ── Phones tab ────────────────────────────────────────────────────────────────

function PhonesTab({ customer }: { customer: Customer }) {
  const { t } = useTranslation('customers');
  const hasAny = customer.phone || customer.mobile;

  return (
    <div className="p-4">
      {hasAny ? (
        <div className="flex flex-col gap-2 rounded-lg border p-3">
          <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
            {t($ => $.drawer.phonesTab.title)}
          </p>
          {customer.phone ? (
            <PhoneRow phone={customer.phone} label={t($ => $.drawer.phonesTab.primary)} />
          ) : null}
          {customer.mobile ? (
            <PhoneRow phone={customer.mobile} label={t($ => $.drawer.phonesTab.secondary)} />
          ) : null}
        </div>
      ) : (
        <div className="flex flex-col items-center gap-2 py-8 text-center">
          <Phone className="size-8 text-muted-foreground/40" />
          <p className="text-sm text-muted-foreground">{t($ => $.drawer.phonesTab.noPhone)}</p>
        </div>
      )}
    </div>
  );
}

// ── Addresses tab ─────────────────────────────────────────────────────────────

function AddressesTab({ customer }: { customer: Customer }) {
  const { t } = useTranslation('customers');
  const [copied, setCopied] = useState(false);

  // Same source as SummaryTab (customer.full_address): CustomerController::fullAddress()
  // prefers the customer's default customer_addresses row over the legacy flat
  // address/city/country columns. This tab previously re-derived its own string from
  // those legacy columns, which could genuinely disagree with Summary whenever a
  // structured default address existed — reading the same resolved field keeps both
  // tabs honest about a single address. No `is_default` flag reaches the frontend for
  // this field, so no "Default" badge is shown here rather than asserting one.
  const fullAddress = customer.full_address;
  const mapHref = customer.location_url || (fullAddress ? `https://maps.google.com/?q=${encodeURIComponent(fullAddress)}` : null);

  const doCopy = () => {
    if (!fullAddress) return;
    void navigator.clipboard.writeText(fullAddress).then(() => {
      setCopied(true);
      setTimeout(() => setCopied(false), 1500);
    });
  };

  return (
    <div className="p-4">
      {fullAddress ? (
        <div className="flex flex-col gap-3 rounded-lg border p-3">
          <div className="flex items-start gap-2">
            <MapPin className="mt-0.5 size-3.5 shrink-0 text-muted-foreground" />
            <p className="text-sm">{fullAddress}</p>
          </div>

          <div className="flex gap-2">
            <Button
              size="sm"
              variant="outline"
              className="h-7 gap-1.5 text-xs"
              onClick={doCopy}
            >
              <Copy className="size-3" />
              {copied ? '✓' : t($ => $.drawer.addresses.copyAddress)}
            </Button>
            {mapHref ? (
              <Button size="sm" variant="outline" className="h-7 gap-1.5 text-xs" asChild>
                <a href={mapHref} target="_blank" rel="noopener noreferrer">
                  <MapPin className="size-3" />
                  {t($ => $.drawer.addresses.openMap)}
                </a>
              </Button>
            ) : null}
          </div>
        </div>
      ) : (
        <div className="flex flex-col items-center gap-2 py-8 text-center">
          <MapPin className="size-8 text-muted-foreground/40" />
          <p className="text-sm text-muted-foreground">{t($ => $.drawer.addresses.noAddress)}</p>
        </div>
      )}
    </div>
  );
}

// ── Orders tab ────────────────────────────────────────────────────────────────

function OrdersTab({ customer }: { customer: Customer }) {
  const { t } = useTranslation('customers');
  const { data, isLoading } = useOrdersQuery({
    customer_id: customer.id,
    per_page: 15,
    sort_by: 'order_date',
    sort_dir: 'desc',
  });

  const orders = data?.items ?? [];

  if (isLoading) {
    return (
      <div className="flex flex-col gap-2 p-4">
        {Array.from({ length: 5 }).map((_, i) => (
          <Skeleton key={i} className="h-14 w-full rounded-lg" />
        ))}
      </div>
    );
  }

  if (orders.length === 0) {
    return (
      <div className="flex flex-col items-center gap-2 py-8 text-center">
        <ShoppingBag className="size-8 text-muted-foreground/40" />
        <p className="text-sm text-muted-foreground">{t($ => $.drawer.orders.empty)}</p>
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-2 p-4">
      {orders.map((order) => (
        <div
          key={order.id}
          className="flex items-center justify-between rounded-lg border px-3 py-2.5"
        >
          <div className="flex flex-col gap-0.5">
            <p className="font-mono text-sm font-medium">{order.order_number}</p>
            <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
              <Calendar className="size-3" />
              {order.order_date ? new Date(order.order_date).toLocaleDateString() : '—'}
            </div>
          </div>
          <div className="flex items-center gap-2">
            <OrderStatusBadge status={order.status} />
            <span className="text-sm font-medium tabular-nums">
              {order.total.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
            </span>
          </div>
        </div>
      ))}
      {data && data.meta.total > 15 ? (
        <p className="text-center text-xs text-muted-foreground">
          {t($ => $.drawer.orders.more, { count: data.meta.total - 15 })}
        </p>
      ) : null}
    </div>
  );
}

// ── Memory tab (Customer Notes / Pinned Notes) ────────────────────────────────

function MemoryTab({
  customer,
  onEdit,
  onOpenChange,
}: {
  customer: Customer;
  onEdit: (customer: Customer) => void;
  onOpenChange: (open: boolean) => void;
}) {
  const { t } = useTranslation('customers');

  return (
    <div className="p-4">
      {customer.notes ? (
        <div className="flex flex-col gap-3">
          <div className="flex items-start gap-2 rounded-lg border bg-amber-50/50 p-3 dark:bg-amber-950/20">
            <FileText className="mt-0.5 size-4 shrink-0 text-amber-500" />
            <div className="min-w-0 flex-1">
              <p className="mb-1 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
                {t($ => $.drawer.memory.note)}
              </p>
              <p className="whitespace-pre-wrap text-sm">{customer.notes}</p>
            </div>
          </div>
          <Button
            size="sm"
            variant="outline"
            className="h-7 w-fit gap-1.5 text-xs"
            onClick={() => {
              onOpenChange(false);
              onEdit(customer);
            }}
          >
            <Pencil className="size-3" />
            {t($ => $.drawer.memory.edit)}
          </Button>
        </div>
      ) : (
        <div className="flex flex-col items-center gap-3 py-8 text-center">
          <FileText className="size-8 text-muted-foreground/40" />
          <p className="text-sm text-muted-foreground">{t($ => $.drawer.memory.empty)}</p>
          <Button
            size="sm"
            variant="outline"
            className="h-7 gap-1.5 text-xs"
            onClick={() => {
              onOpenChange(false);
              onEdit(customer);
            }}
          >
            <Pencil className="size-3" />
            {t($ => $.drawer.memory.edit)}
          </Button>
        </div>
      )}
    </div>
  );
}

// ── Helper ────────────────────────────────────────────────────────────────────

function ProductsTab({ customer }: { customer: Customer }) {
  const { t } = useTranslation('customers');
  // GET /customers/{id} — grouped and summed by the database, one query.
  const { data: full, isLoading } = useCustomerQuery(customer.id);
  const rows = full?.purchased_products ?? [];

  if (isLoading) {
    return <div className="p-4"><Skeleton className="h-24 w-full" /></div>;
  }

  if (rows.length === 0) {
    return (
      <div className="p-4 text-sm text-muted-foreground">
        {t($ => $.drawer.products.empty)}
      </div>
    );
  }

  return (
    <div className="overflow-x-auto p-4">
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b text-start text-[11px] uppercase tracking-wide text-muted-foreground">
            <th className="py-2 pe-3 text-start font-medium">{t($ => $.drawer.products.product)}</th>
            <th className="py-2 pe-3 text-end font-medium">{t($ => $.drawer.products.quantity)}</th>
            <th className="py-2 pe-3 text-end font-medium">{t($ => $.drawer.products.orders)}</th>
            <th className="py-2 text-start font-medium">{t($ => $.drawer.products.lastOrdered)}</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((r) => (
            <tr key={r.product_id ?? r.product_name} className="border-b last:border-0">
              <td className="py-2 pe-3">{r.product_name ?? '—'}</td>
              <td className="py-2 pe-3 text-end tabular-nums">{fmtNum(r.total_quantity, 2)}</td>
              <td className="py-2 pe-3 text-end tabular-nums">{r.orders_count}</td>
              <td className="py-2">
                {r.last_ordered_at ? new Date(r.last_ordered_at).toLocaleDateString() : '—'}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function Kpi({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex flex-col items-center gap-0.5 text-center">
      <span className="text-base font-semibold tabular-nums">{value}</span>
      <span className="text-[11px] text-muted-foreground">{label}</span>
    </div>
  );
}

/** Presentation only — never a recomputation. */
function fmtNum(n: number | null | undefined, digits = 0) {
  return typeof n === 'number'
    ? n.toLocaleString(undefined, { minimumFractionDigits: digits, maximumFractionDigits: digits })
    : '—';
}

function InfoRow({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-start gap-2">
      <span className="w-28 shrink-0 text-xs text-muted-foreground">{label}</span>
      <span className="text-sm">{value}</span>
    </div>
  );
}

// ── Main component ────────────────────────────────────────────────────────────

export function CustomerDrawer({ customer, open, onOpenChange, onEdit, defaultTab }: Props) {
  const { t } = useTranslation('customers');
  const isMobile = useIsMobile();
  const navigate = useNavigate();
  const { can } = usePermission();

  const [activeTab, setActiveTab] = useState(defaultTab ?? 'summary');

  useEffect(() => {
    setActiveTab(defaultTab ?? 'summary');
  }, [customer?.id, defaultTab]);

  if (!customer) return null;

  const primaryPhone = customer.phone;
  const addressLine  = [customer.city, customer.country].filter(Boolean).join(', ');

  const tabs = [
    {
      key: 'summary',
      label: t($ => $.drawer.tabs.summary),
      content: <SummaryTab customer={customer} />,
    },
    {
      key: 'phones',
      label: t($ => $.drawer.tabs.phones),
      content: <PhonesTab customer={customer} />,
    },
    {
      key: 'addresses',
      label: t($ => $.drawer.tabs.addresses),
      content: <AddressesTab customer={customer} />,
    },
    {
      key: 'orders',
      label: t($ => $.drawer.tabs.orders),
      content: <OrdersTab customer={customer} />,
    },
    {
      key: 'products',
      label: t($ => $.drawer.tabs.products),
      content: <ProductsTab customer={customer} />,
    },
    {
      key: 'memory',
      label: t($ => $.drawer.tabs.memory),
      content: (
        <MemoryTab customer={customer} onEdit={onEdit} onOpenChange={onOpenChange} />
      ),
    },
  ];

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent side="right" className="flex flex-col gap-0 p-0">
        {/* ── Header ──────────────────────────────────────────────────────── */}
        <SheetHeader className="border-b px-4 py-3">
          <div className="flex items-start justify-between gap-2">
            <div className="min-w-0 flex-1">
              <SheetTitle className="truncate text-base font-semibold leading-tight">
                {customer.name}
              </SheetTitle>

              {/* Phone quick actions */}
              {primaryPhone ? (
                <div className="mt-1 flex items-center gap-1.5">
                  <span className="font-mono text-xs text-muted-foreground">{primaryPhone}</span>
                  <Button
                    size="icon"
                    variant="ghost"
                    className="size-5"
                    asChild
                    title={t($ => $.phone.call)}
                  >
                    <a href={`tel:${primaryPhone.replace(/\D/g, '')}`}>
                      <Phone className="size-3" />
                    </a>
                  </Button>
                  <Button
                    size="icon"
                    variant="ghost"
                    className="size-5"
                    asChild
                    title={t($ => $.phone.whatsapp)}
                  >
                    <a
                      href={`https://wa.me/${primaryPhone.replace(/\D/g, '')}`}
                      target="_blank"
                      rel="noopener noreferrer"
                    >
                      <MessageCircle className="size-3" />
                    </a>
                  </Button>
                </div>
              ) : null}

              {/* Address line */}
              {addressLine ? (
                <div className="mt-0.5 flex items-center gap-1 text-xs text-muted-foreground">
                  <MapPin className="size-3" />
                  <span>{addressLine}</span>
                </div>
              ) : null}
            </div>

            <div className="flex shrink-0 items-center gap-1">
              {/* TASK-ECOS-CUSTOMER-SUPPLIER-LEDGER-LINKS-CLOSURE-001 — links out to the
                  existing canonical Finance AR statement/ledger for this customer; no
                  second statement implementation lives here. Hidden (not merely
                  disabled) unless the viewer holds the same finance.ar.view permission
                  that gates the Accounts Receivable page itself. */}
              {can('finance.ar.view') && (
                <Button
                  variant="outline"
                  size="sm"
                  className="h-7 gap-1.5 text-xs"
                  onClick={() => navigate(`${ROUTES.financeReceivables}?customer_id=${customer.id}`)}
                >
                  <FileText className="size-3" />
                  {t($ => $.actions.accountStatement)}
                </Button>
              )}
              <Button
                variant="outline"
                size="sm"
                className="h-7 gap-1.5 text-xs"
                onClick={() => {
                  onOpenChange(false);
                  onEdit(customer);
                }}
              >
                <Pencil className="size-3" />
                {t($ => $.actions.edit)}
              </Button>
              <SheetClose asChild>
                <Button variant="ghost" size="icon" className="size-7">
                  <X className="size-4" />
                </Button>
              </SheetClose>
            </div>
          </div>
        </SheetHeader>

        {/* ── Body ────────────────────────────────────────────────────────── */}
        {isMobile ? (
          // Mobile: every canonical section stacked and scrollable instead of
          // a 6-tab switcher (design report §9 — "organize into touch-friendly
          // sections... do not blindly reproduce a desktop drawer layout
          // vertically" — this reuses the exact same tab bodies/data, just
          // presented as sections rather than hidden behind tab taps, matching
          // the pattern already applied to Products' 8-tab detail).
          <div className="flex-1 overflow-y-auto">
            {tabs.map((tab) => (
              <MobileDetailSection key={tab.key} title={tab.label}>
                {tab.content}
              </MobileDetailSection>
            ))}
          </div>
        ) : (
          <Tabs
            tabs={tabs}
            activeKey={activeTab}
            onTabChange={setActiveTab}
            className="flex-1 overflow-hidden"
            contentClassName="overflow-y-auto"
          />
        )}
      </SheetContent>
    </Sheet>
  );
}
