import React, { useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import {
  Activity,
  ArrowLeft,
  ArrowRightCircle,
  Bot,
  Box,
  Building2,
  CheckCircle2,
  ChevronDown,
  ChevronRight,
  Clock,
  Copy,
  Download,
  Edit,
  ExternalLink,
  Filter,
  GitBranch,
  Globe,
  Loader2,
  Lock,
  MapPin,
  MessageCircle,
  Navigation,
  Package,
  Phone,
  Plus,
  Printer,
  RefreshCw,
  RotateCcw,
  Search,
  ShoppingBag,
  Store,
  Truck,
  User,
  UserCheck,
  Warehouse,
  XCircle,
} from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import { Skeleton } from '@/components/ui/skeleton';
import { Badge } from '@/components/ui/badge';
import { OrderConfirmCustomerDialog } from '@/features/orders/components/order-confirm-customer-dialog';
import { OrderConfirmationBadge } from '@/features/orders/components/order-confirmation-badge';
import { OrderPaymentBadge } from '@/features/orders/components/order-payment-badge';
import { OrderStatusBadge } from '@/features/orders/components/order-status-badge';
import {
  useCustomerOrderStats,
  useOrderActivities,
  useOrderQuery,
  useOrderWorkflowCancel,
  useOrderWorkflowComplete,
  useOrderWorkflowCompleteDelivery,
  useOrderWorkflowConfirm,
  useOrderWorkflowDispatch,
  useOrderWorkflowMarkAwaitingStock,
  useOrderWorkflowMoveToPreparation,
  useOrderWorkflowMoveToReview,
  useOrderWorkflowReschedule,
  useOrderWorkflowResume,
  useOrderWorkflowResumeToConfirmed,
  useOrderWorkflowReturn,
  useOrderWorkflowReturnToConfirmed,
} from '@/features/orders/hooks/use-orders';
import type { Order, OrderActivity, OrderActivityActionType } from '@/features/orders/types/order';
import { getMediaUrl } from '@/lib/media';
import { cn } from '@/lib/utils';
import { ROUTES } from '@/router/routes';

// ── Shared formatting helpers ─────────────────────────────────────────────────

function fmtMoney(n: number) {
  return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function fmtDate(d: string | null | undefined): string {
  if (!d) return '—';
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(d));
}

function fmtDateTime(d: string | null | undefined): string {
  if (!d) return '—';
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(d));
}

// ── Micro-layout primitives ───────────────────────────────────────────────────

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-0.5">
      <span className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">{label}</span>
      <span className="text-sm">{children ?? <span className="text-muted-foreground">—</span>}</span>
    </div>
  );
}

function FieldGrid({ children, cols = 2 }: { children: React.ReactNode; cols?: 1 | 2 | 3 }) {
  return (
    <div className={cn('grid gap-x-4 gap-y-3',
      cols === 1 && 'grid-cols-1',
      cols === 2 && 'grid-cols-2',
      cols === 3 && 'sm:grid-cols-3 grid-cols-2',
    )}>
      {children}
    </div>
  );
}

function SectionLabel({ children }: { children: React.ReactNode }) {
  return (
    <h3 className="mb-3 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
      {children}
    </h3>
  );
}

function InfoCard({ title, icon: Icon, children, className, headerExtra }: {
  title: string;
  icon?: React.ComponentType<{ className?: string }>;
  children: React.ReactNode;
  className?: string;
  headerExtra?: React.ReactNode;
}) {
  return (
    <Card className={cn('gap-0', className)}>
      <CardHeader className="px-4 py-3 border-b">
        <CardTitle className="flex items-center gap-2 text-sm font-semibold">
          {Icon && <Icon className="size-4 text-muted-foreground" />}
          {title}
          {headerExtra && <div className="ms-auto">{headerExtra}</div>}
        </CardTitle>
      </CardHeader>
      <CardContent className="px-4 py-4">{children}</CardContent>
    </Card>
  );
}

function EmptyState({ icon: Icon, message }: { icon: React.ComponentType<{ className?: string }>; message: string }) {
  return (
    <div className="flex flex-col items-center gap-2 py-8 text-center">
      <Icon className="size-8 text-muted-foreground/40" />
      <p className="text-sm text-muted-foreground">{message}</p>
    </div>
  );
}

// ── Part 16 — Loading skeleton ────────────────────────────────────────────────

function Order360Skeleton() {
  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-center gap-3">
        <Skeleton className="h-8 w-48" />
        <Skeleton className="h-6 w-24 rounded-full" />
      </div>
      <div className="grid grid-cols-4 gap-4">
        {Array.from({ length: 7 }).map((_, i) => (
          <Skeleton key={i} className="h-16 rounded-lg" />
        ))}
      </div>
      <div className="grid gap-4 lg:grid-cols-[1fr_320px]">
        <div className="flex flex-col gap-4">
          <Skeleton className="h-40 rounded-lg" />
          <Skeleton className="h-40 rounded-lg" />
          <Skeleton className="h-60 rounded-lg" />
        </div>
        <div className="flex flex-col gap-4">
          <Skeleton className="h-48 rounded-lg" />
          <Skeleton className="h-40 rounded-lg" />
        </div>
      </div>
    </div>
  );
}

// ── Part 13 — KPI Row ─────────────────────────────────────────────────────────

function KpiCard({ label, value, sub, highlight }: {
  label: string;
  value: React.ReactNode;
  sub?: string;
  highlight?: 'success' | 'warning' | 'danger';
}) {
  return (
    <div className={cn(
      'flex flex-col gap-0.5 rounded-lg border bg-card px-3 py-2.5',
      highlight === 'success' && 'border-emerald-200 bg-emerald-50/50 dark:border-emerald-900/50 dark:bg-emerald-950/20',
      highlight === 'warning' && 'border-amber-200 bg-amber-50/50 dark:border-amber-900/50 dark:bg-amber-950/20',
      highlight === 'danger'  && 'border-red-200 bg-red-50/50 dark:border-red-900/50 dark:bg-red-950/20',
    )}>
      <span className="text-[11px] text-muted-foreground">{label}</span>
      <span className={cn(
        'text-lg font-semibold tabular-nums leading-tight',
        highlight === 'success' && 'text-emerald-700 dark:text-emerald-400',
        highlight === 'warning' && 'text-amber-700 dark:text-amber-400',
        highlight === 'danger'  && 'text-red-600 dark:text-red-400',
      )}>{value}</span>
      {sub ? <span className="text-[10px] text-muted-foreground">{sub}</span> : null}
    </div>
  );
}

function KpiRow({ order }: { order: Order }) {
  const totalQty = order.lines.reduce((s, l) => s + l.quantity, 0);
  const remaining = order.remaining_balance;
  const reservedCount = order.inventory_reserved_at ? order.lines.length : 0;

  return (
    <div className="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-7">
      <KpiCard label="Products" value={order.lines.length} sub={`${totalQty} units`} />
      <KpiCard label="Quantity" value={totalQty.toLocaleString()} />
      <KpiCard label="Reserved" value={reservedCount} highlight={reservedCount === order.lines.length ? 'success' : reservedCount > 0 ? 'warning' : undefined} />
      <KpiCard label="Shipping" value={`${fmtMoney(order.shipping_amount)} EGP`} />
      <KpiCard label="Grand Total" value={`${fmtMoney(order.grand_total)} EGP`} highlight="success" />
      <KpiCard
        label="Remaining"
        value={`${fmtMoney(remaining)} EGP`}
        highlight={remaining > 0 ? 'warning' : 'success'}
      />
      <KpiCard
        label="Progress"
        value={order.status_label ?? order.status.replace(/_/g, ' ')}
        sub={order.order_date ? fmtDate(order.order_date) : undefined}
      />
    </div>
  );
}

// ── Part 1 — Enterprise Header ────────────────────────────────────────────────

function OrderHeader({
  order,
  onEdit,
  onConfirmCustomer,
  onPrint,
}: {
  order: Order;
  onEdit: () => void;
  onConfirmCustomer: () => void;
  onPrint: () => void;
}) {
  const navigate = useNavigate();

  return (
    <div className="flex flex-col gap-3 rounded-xl border bg-card px-5 py-4">
      {/* Top row: number + status + back */}
      <div className="flex flex-wrap items-center gap-2">
        <Button
          variant="ghost"
          size="icon"
          className="size-7 shrink-0"
          onClick={() => navigate(ROUTES.orders)}
          aria-label="Back to orders"
        >
          <ArrowLeft className="size-4" />
        </Button>
        <h1 className="font-mono text-xl font-bold tracking-tight">{order.order_number}</h1>
        <OrderStatusBadge status={order.status} />
        {order.confirmation_result ? <OrderConfirmationBadge order={order} /> : null}
        {order.source ? (
          <span className="rounded-full border px-2 py-0.5 text-[10px] font-medium text-muted-foreground capitalize">
            {order.source.replace(/_/g, ' ')}
          </span>
        ) : null}
        <div className="ms-auto flex items-center gap-2">
          <Button variant="outline" size="sm" onClick={onPrint}>
            <Printer className="size-3.5" />
            Print
          </Button>
          <Button variant="outline" size="sm" onClick={onConfirmCustomer}>
            <UserCheck className="size-3.5" />
            Confirm Customer
          </Button>
          <Button size="sm" onClick={onEdit}>
            <Edit className="size-3.5" />
            Edit
          </Button>
        </div>
      </div>

      {/* Meta row */}
      <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
        {order.channel?.name ? (
          <span className="flex items-center gap-1">
            <Store className="size-3" />
            {order.channel.name}
          </span>
        ) : null}
        {order.customer?.name ? (
          <span className="flex items-center gap-1">
            <UserCheck className="size-3" />
            {order.customer.name}
            {order.customer.code ? ` · ${order.customer.code}` : ''}
          </span>
        ) : null}
        {order.assigned_warehouse_id ? (
          <span className="flex items-center gap-1">
            <Warehouse className="size-3" />
            {order.assigned_warehouse_id}
          </span>
        ) : null}
        {order.inventory_reserved_at ? (
          <Badge variant="outline" className="text-[10px] text-emerald-600 border-emerald-300 dark:border-emerald-800 dark:text-emerald-400">
            <CheckCircle2 className="size-2.5 mr-0.5" />
            Reserved {fmtDate(order.inventory_reserved_at)}
          </Badge>
        ) : (
          <Badge variant="outline" className="text-[10px] text-amber-600 border-amber-300 dark:border-amber-800 dark:text-amber-400">
            Not Reserved
          </Badge>
        )}
        {order.tracking_number ? (
          <Badge variant="outline" className="text-[10px]">
            <Truck className="size-2.5 mr-0.5" />
            {order.tracking_number}
          </Badge>
        ) : null}
        <OrderPaymentBadge
          method={order.payment_method_manual ?? order.payment_method}
          methodTitle={order.payment_method_title}
          datePaid={order.date_paid}
        />
        {order.created_at ? (
          <span>Created {fmtDateTime(order.created_at)}</span>
        ) : null}
        {order.status_entered_by ? (
          <span>By {order.status_entered_by}</span>
        ) : null}
      </div>
    </div>
  );
}

// ── Part 2 — Financial Summary ────────────────────────────────────────────────

function FinancialSummaryCard({ order }: { order: Order }) {
  const remaining = order.remaining_balance;

  const rows: Array<{ label: string; value: number; color?: string; always?: boolean }> = [
    { label: 'Products Total', value: order.products_total, always: true },
    { label: 'Shipping',       value: order.shipping_amount },
    { label: 'Discount',       value: -order.discount_amount },
    { label: 'Tax',            value: order.tax_amount },
    { label: 'Deposit Paid',   value: -order.deposit_paid },
  ];

  return (
    <InfoCard title="Financial Summary" icon={ShoppingBag}>
      <div className="flex flex-col gap-1.5 text-sm">
        {rows.map(({ label, value, always }) => {
          if (!always && (!value || value === 0)) return null;
          const isNeg = value < 0;
          return (
            <div key={label} className="flex items-center justify-between gap-4">
              <span className="text-muted-foreground">{label}</span>
              <span className={cn('tabular-nums', isNeg && 'text-emerald-600 dark:text-emerald-400')}>
                {isNeg ? `−${fmtMoney(-value)}` : fmtMoney(value)} EGP
              </span>
            </div>
          );
        })}
        <Separator className="my-1" />
        <div className="flex items-center justify-between gap-4 font-semibold">
          <span>Grand Total</span>
          <span className="tabular-nums">{fmtMoney(order.grand_total)} EGP</span>
        </div>
        {remaining > 0 && (
          <div className="flex items-center justify-between gap-4 rounded-md bg-amber-50 px-2 py-1.5 dark:bg-amber-950/30">
            <span className="text-amber-700 dark:text-amber-400 text-xs font-medium">Remaining Balance</span>
            <span className="tabular-nums font-semibold text-amber-700 dark:text-amber-400">
              {fmtMoney(remaining)} EGP
            </span>
          </div>
        )}
        {remaining <= 0 && (
          <div className="flex items-center gap-1.5 rounded-md bg-emerald-50 px-2 py-1.5 dark:bg-emerald-950/30">
            <CheckCircle2 className="size-3.5 text-emerald-600 dark:text-emerald-400" />
            <span className="text-emerald-700 dark:text-emerald-400 text-xs font-medium">Fully Paid</span>
          </div>
        )}
      </div>
    </InfoCard>
  );
}

// ── Part 3 — Customer 360 Card ────────────────────────────────────────────────

function CustomerCard({ order }: { order: Order }) {
  const customer = order.customer;
  const { data: stats, isLoading } = useCustomerOrderStats(customer?.id ?? null);

  if (!customer) {
    return (
      <InfoCard title="Customer" icon={UserCheck}>
        <EmptyState icon={UserCheck} message="No customer linked to this order." />
      </InfoCard>
    );
  }

  const primaryPhone = order.billing_phone ?? customer.phone ?? customer.mobile;
  const digits = primaryPhone?.replace(/\D/g, '') ?? '';
  const isVip = (stats?.total ?? 0) >= 10;
  const isReturning = (stats?.total ?? 0) >= 2;
  const hasRejected = (stats?.cancelled ?? 0) > 0 && (stats?.completed ?? 0) === 0;
  const hasReturned = false; // not in stats yet

  return (
    <InfoCard title="Customer 360" icon={UserCheck}>
      <div className="flex flex-col gap-4">
        {/* Identity */}
        <div className="flex items-start justify-between gap-2">
          <div className="min-w-0">
            <div className="flex items-center gap-2">
              <p className="font-semibold truncate">{customer.name}</p>
              {isVip && (
                <span className="rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
                  ⭐ VIP
                </span>
              )}
              {!isVip && isReturning && (
                <span className="rounded-full bg-blue-100 px-1.5 py-0.5 text-[10px] font-semibold text-blue-700 dark:bg-blue-900/30 dark:text-blue-400">
                  Returning
                </span>
              )}
              {hasRejected && (
                <span className="rounded-full bg-red-100 px-1.5 py-0.5 text-[10px] font-semibold text-red-700 dark:bg-red-900/30 dark:text-red-400">
                  Rejected Before
                </span>
              )}
              {hasReturned && (
                <span className="rounded-full bg-orange-100 px-1.5 py-0.5 text-[10px] font-semibold text-orange-700 dark:bg-orange-900/30 dark:text-orange-400">
                  Returned Before
                </span>
              )}
            </div>
            <p className="font-mono text-xs text-muted-foreground">{customer.code}</p>
          </div>
          <a
            href={`/app/customers/${customer.id}`}
            className="inline-flex shrink-0 items-center gap-1 text-xs text-primary hover:underline"
          >
            <ExternalLink className="size-3" />
            Open
          </a>
        </div>

        {/* Contact */}
        <FieldGrid cols={2}>
          <Field label="Primary Phone">
            {primaryPhone ? (
              <div className="flex items-center gap-1.5">
                <span className="font-mono text-xs">{primaryPhone}</span>
                <a href={`tel:${digits}`} className="text-muted-foreground hover:text-foreground">
                  <Phone className="size-3" />
                </a>
                <a
                  href={`https://wa.me/${digits}`}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="text-green-600 hover:text-green-700"
                >
                  <MessageCircle className="size-3" />
                </a>
              </div>
            ) : null}
          </Field>
          <Field label="Secondary Phone">
            {customer.mobile && customer.mobile !== primaryPhone ? (
              <span className="font-mono text-xs">{customer.mobile}</span>
            ) : null}
          </Field>
          <Field label="Email">{order.billing_email}</Field>
          <Field label="Governorate">{order.governorate ?? order.shipping_state}</Field>
          <Field label="City">{order.city ?? order.shipping_city ?? order.billing_city}</Field>
          <Field label="Street">{order.shipping_address ?? order.shipping_address_1 ?? order.billing_address_1}</Field>
        </FieldGrid>

        {/* Intelligence Stats */}
        {isLoading ? (
          <div className="flex flex-col gap-1">
            <Skeleton className="h-4 w-full" />
            <Skeleton className="h-4 w-3/4" />
          </div>
        ) : stats ? (
          <>
            <Separator />
            <SectionLabel>Customer Intelligence</SectionLabel>
            <div className="grid grid-cols-3 gap-2">
              {[
                { label: 'Orders', value: stats.total, colorCls: '' },
                { label: 'Delivered', value: stats.completed, colorCls: 'text-emerald-600 dark:text-emerald-400' },
                { label: 'Cancelled', value: stats.cancelled, colorCls: stats.cancelled > 0 ? 'text-red-500 dark:text-red-400' : '' },
              ].map(({ label, value, colorCls }) => (
                <div key={label} className="rounded-md border bg-muted/20 px-2 py-1.5 text-center">
                  <p className={cn('text-base font-semibold tabular-nums', colorCls)}>{value}</p>
                  <p className="text-[10px] text-muted-foreground">{label}</p>
                </div>
              ))}
            </div>
            <FieldGrid cols={2}>
              <Field label="Lifetime Value">
                <span className="font-semibold tabular-nums">{fmtMoney(stats.totalSpend)} EGP</span>
              </Field>
              <Field label="Avg Order Value">
                {stats.aov !== null ? <span className="tabular-nums">{fmtMoney(stats.aov)} EGP</span> : null}
              </Field>
              <Field label="First Order">{fmtDate(stats.firstOrderDate)}</Field>
              <Field label="Last Order">{fmtDate(stats.lastOrderDate)}</Field>
              <Field label="Preferred Zone">{stats.preferredGovernorate}</Field>
            </FieldGrid>
          </>
        ) : null}
      </div>
    </InfoCard>
  );
}

// ── Part 5 — Address Card ─────────────────────────────────────────────────────

function AddressCard({ order }: { order: Order }) {
  const loc = order.location;
  const mapsUrl = loc?.lat && loc?.lng
    ? `https://www.google.com/maps?q=${loc.lat},${loc.lng}`
    : null;
  const coordsText = loc?.lat && loc?.lng ? `${loc.lat}, ${loc.lng}` : null;

  const hasAddress = order.governorate || order.city || order.shipping_address ||
    order.shipping_address_1 || order.shipping_city || order.area ||
    order.delivery_zone || order.billing_address_1;

  if (!hasAddress && !loc) {
    return (
      <InfoCard title="Delivery Address" icon={MapPin}>
        <EmptyState icon={MapPin} message="No address recorded for this order." />
      </InfoCard>
    );
  }

  return (
    <InfoCard title="Delivery Address" icon={MapPin}>
      <div className="flex flex-col gap-4">
        <FieldGrid cols={2}>
          <Field label="Governorate">{order.governorate ?? order.shipping_state}</Field>
          <Field label="City">{order.city ?? order.shipping_city ?? order.billing_city}</Field>
          <Field label="Zone / Area">{order.delivery_zone ?? order.area}</Field>
          <Field label="Street">{order.shipping_address ?? order.shipping_address_1 ?? order.billing_address_1}</Field>
          {order.building ? <Field label="Building">{order.building}</Field> : null}
          {order.floor ? <Field label="Floor">{order.floor}</Field> : null}
          {order.apartment ? <Field label="Apartment">{order.apartment}</Field> : null}
          {order.landmark ? <Field label="Landmark">{order.landmark}</Field> : null}
          {!order.building && order.shipping_address_2 ? <Field label="Building / Unit">{order.shipping_address_2}</Field> : null}
        </FieldGrid>

        {coordsText ? (
          <>
            <Separator />
            <div className="flex flex-col gap-2">
              <SectionLabel>GPS Coordinates</SectionLabel>
              <div className="flex items-center gap-1.5 font-mono text-xs text-muted-foreground">
                <MapPin className="size-3 shrink-0" />
                {coordsText}
                {loc?.set_by ? <span className="ml-1 capitalize">· Set by {loc.set_by}</span> : null}
              </div>
              <div className="flex flex-wrap gap-1.5">
                {mapsUrl ? (
                  <Button variant="outline" size="sm" asChild className="h-7 text-xs">
                    <a href={mapsUrl} target="_blank" rel="noopener noreferrer">
                      <Navigation className="size-3" />
                      Open Maps
                    </a>
                  </Button>
                ) : null}
                {mapsUrl ? (
                  <Button variant="outline" size="sm" className="h-7 text-xs" onClick={() => void navigator.clipboard.writeText(mapsUrl)}>
                    <Copy className="size-3" />
                    Copy Link
                  </Button>
                ) : null}
                {coordsText ? (
                  <Button variant="outline" size="sm" className="h-7 text-xs" onClick={() => void navigator.clipboard.writeText(coordsText)}>
                    <Copy className="size-3" />
                    Copy Coords
                  </Button>
                ) : null}
                {loc?.lat && loc?.lng ? (
                  <Button variant="outline" size="sm" asChild className="h-7 text-xs">
                    <a
                      href={`https://www.waze.com/ul?ll=${loc.lat}%2C${loc.lng}&navigate=yes`}
                      target="_blank"
                      rel="noopener noreferrer"
                    >
                      <Navigation className="size-3" />
                      Waze
                    </a>
                  </Button>
                ) : null}
              </div>
            </div>
          </>
        ) : null}
      </div>
    </InfoCard>
  );
}

// ── Part 4 — Shipping Card ────────────────────────────────────────────────────

function ShippingCard({ order }: { order: Order }) {
  const hasMeaningfulShipping = order.shipping_company_name || order.shipping_method ||
    order.tracking_number || order.requested_delivery_date || order.delivery_window;

  if (!hasMeaningfulShipping) {
    return (
      <InfoCard title="Shipping" icon={Truck}>
        <EmptyState icon={Truck} message="No shipment information recorded." />
      </InfoCard>
    );
  }

  return (
    <InfoCard title="Shipping" icon={Truck}>
      <FieldGrid cols={2}>
        <Field label="Carrier / Company">{order.shipping_company_name}</Field>
        <Field label="Shipping Method">{order.shipping_method}</Field>
        <Field label="Tracking Number">
          {order.tracking_number ? (
            <span className="font-mono text-xs">{order.tracking_number}</span>
          ) : null}
        </Field>
        <Field label="Delivery Attempts">
          <span className={cn('font-semibold', order.shipping_attempts > 0 && 'text-amber-600 dark:text-amber-400')}>
            {order.shipping_attempts ?? 0}
          </span>
        </Field>
        <Field label="Delivery Window">{order.delivery_window}</Field>
        <Field label="Requested Delivery">{fmtDate(order.requested_delivery_date)}</Field>
        <Field label="Preferred Time">
          {order.preferred_delivery_time ? (
            <span className="capitalize">{order.preferred_delivery_time}</span>
          ) : null}
        </Field>
        <Field label="Delivery Zone">{order.delivery_zone}</Field>
        <Field label="Shipping Cost">
          {order.shipping_cost != null ? `${fmtMoney(order.shipping_cost)} EGP` : null}
        </Field>
        <Field label="Cost Source">
          {order.shipping_cost_source ? (
            <span className="capitalize">{order.shipping_cost_source}</span>
          ) : null}
        </Field>
        {order.inventory_shipped_at ? (
          <Field label="Dispatched At">{fmtDateTime(order.inventory_shipped_at)}</Field>
        ) : null}
      </FieldGrid>
    </InfoCard>
  );
}

// ── Part 6 — Inventory Card ───────────────────────────────────────────────────

function InventoryCard({ order }: { order: Order }) {
  const isReserved = Boolean(order.inventory_reserved_at);
  const isShipped = Boolean(order.inventory_shipped_at);

  return (
    <InfoCard title="Inventory & Fulfillment" icon={Warehouse}>
      <div className="flex flex-col gap-4">
        <FieldGrid cols={2}>
          <Field label="Reservation Status">
            {isReserved ? (
              <span className="inline-flex items-center gap-1 text-sm font-medium text-emerald-600 dark:text-emerald-400">
                <CheckCircle2 className="size-3.5" />
                Reserved
              </span>
            ) : (
              <span className="text-sm font-medium text-amber-600 dark:text-amber-400">Not Reserved</span>
            )}
          </Field>
          <Field label="Reserved At">{fmtDateTime(order.inventory_reserved_at)}</Field>
          <Field label="Warehouse">{order.assigned_warehouse_id ?? '—'}</Field>
          <Field label="Dispatched At">{isShipped ? fmtDateTime(order.inventory_shipped_at) : null}</Field>
        </FieldGrid>
        <Separator />
        <SectionLabel>Lines ({order.lines.length})</SectionLabel>
        <div className="flex flex-col gap-1.5">
          {order.lines.length === 0 ? (
            <p className="text-sm text-muted-foreground">No lines.</p>
          ) : order.lines.map((line) => (
            <div key={line.id} className="flex items-center gap-2.5 rounded-md border bg-muted/20 px-3 py-2 text-sm">
              <Box className="size-3.5 shrink-0 text-muted-foreground" />
              <div className="flex-1 min-w-0">
                <p className="truncate text-sm font-medium">{line.product?.name ?? line.product_id}</p>
                {line.product?.sku ? (
                  <p className="font-mono text-[10px] text-muted-foreground">{line.product.sku}</p>
                ) : null}
              </div>
              <span className="shrink-0 text-sm tabular-nums font-semibold">×{line.quantity}</span>
            </div>
          ))}
        </div>
      </div>
    </InfoCard>
  );
}

// ── Part 7 — Products Grid ────────────────────────────────────────────────────

function ProductsGrid({ order }: { order: Order }) {
  if (order.lines.length === 0) {
    return (
      <InfoCard title="Products" icon={Package}>
        <EmptyState icon={Package} message="No products on this order." />
      </InfoCard>
    );
  }

  return (
    <InfoCard title={`Products (${order.lines.length})`} icon={Package}>
      <div className="flex flex-col gap-0 -mx-4">
        {/* Price protection banner */}
        <div className="mx-4 mb-3 flex items-start gap-2 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 dark:border-emerald-900/50 dark:bg-emerald-950/30">
          <Lock className="mt-0.5 size-3 shrink-0 text-emerald-600 dark:text-emerald-400" />
          <p className="text-[11px] text-emerald-700 dark:text-emerald-400 leading-relaxed">
            <span className="font-semibold">Price Protection Active — </span>
            All unit prices are frozen at the moment this order was placed.
          </p>
        </div>
        {/* Header */}
        <div className="grid grid-cols-[2fr_1fr_1fr_1fr] gap-2 border-b px-4 pb-2 text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
          <span>Product</span>
          <span className="text-center">Qty</span>
          <span className="text-end">Unit Price</span>
          <span className="text-end">Line Total</span>
        </div>
        {/* Rows */}
        {order.lines.map((line) => (
          <div key={line.id} className="grid grid-cols-[2fr_1fr_1fr_1fr] items-center gap-2 border-b px-4 py-3 last:border-0 hover:bg-muted/20">
            <div className="flex items-center gap-2.5 min-w-0">
              {getMediaUrl(line.product?.image_url) ? (
                <img
                  src={getMediaUrl(line.product!.image_url)!}
                  alt={line.product!.name ?? ''}
                  className="size-9 rounded-md object-cover ring-1 ring-border shrink-0"
                />
              ) : (
                <div className="flex size-9 shrink-0 items-center justify-center rounded-md bg-muted ring-1 ring-border">
                  <Package className="size-4 text-muted-foreground" />
                </div>
              )}
              <div className="min-w-0">
                <p className="truncate text-sm font-medium">{line.product?.name ?? '—'}</p>
                {line.product?.sku ? (
                  <p className="font-mono text-[10px] text-muted-foreground">{line.product.sku}</p>
                ) : null}
                {line.product?.unit_name ? (
                  <p className="text-[10px] text-muted-foreground">{line.product.unit_name}</p>
                ) : null}
              </div>
            </div>
            <p className="text-center text-sm tabular-nums font-medium">{line.quantity}</p>
            <p className="text-end text-sm tabular-nums">{fmtMoney(line.unit_price)}</p>
            <p className="text-end text-sm tabular-nums font-semibold">{fmtMoney(line.line_total)}</p>
          </div>
        ))}
        {/* Totals row */}
        <div className="flex items-center justify-between gap-4 border-t px-4 pt-3 text-sm">
          <span className="text-muted-foreground">Subtotal</span>
          <span className="font-semibold tabular-nums">{fmtMoney(order.products_total)} EGP</span>
        </div>
        {order.fees.length > 0 ? order.fees.map((f) => (
          <div key={f.id} className="flex items-center justify-between gap-4 px-4 py-1 text-sm">
            <span className="text-muted-foreground">{f.name}</span>
            <span className="tabular-nums">{fmtMoney(f.total)} EGP</span>
          </div>
        )) : null}
        {order.coupons.length > 0 ? order.coupons.map((c) => (
          <div key={c.id} className="flex items-center justify-between gap-4 px-4 py-1 text-sm">
            <span className="font-mono text-xs text-muted-foreground">{c.code}</span>
            <span className="tabular-nums text-emerald-600 dark:text-emerald-400">-{fmtMoney(c.discount)} EGP</span>
          </div>
        )) : null}
      </div>
    </InfoCard>
  );
}

// ── Part 8 — Payment Card ─────────────────────────────────────────────────────

function PaymentCard({ order }: { order: Order }) {
  const method = order.payment_method_manual ?? order.payment_method;
  const remaining = order.remaining_balance;

  return (
    <InfoCard title="Payment" icon={ShoppingBag}>
      <FieldGrid cols={2}>
        <Field label="Method">
          <OrderPaymentBadge
            method={method}
            methodTitle={order.payment_method_title}
            datePaid={order.date_paid}
          />
        </Field>
        <Field label="Transaction ID">
          {order.transaction_id ? (
            <span className="font-mono text-xs">{order.transaction_id}</span>
          ) : null}
        </Field>
        <Field label="Payment Date">{fmtDate(order.date_paid)}</Field>
        <Field label="Payment Status">
          {order.date_paid ? (
            <span className="text-sm font-medium text-emerald-600 dark:text-emerald-400">Verified</span>
          ) : (
            <span className="text-sm font-medium text-amber-600 dark:text-amber-400">Pending</span>
          )}
        </Field>
        {order.payment_proof_path ? (
          <Field label="Proof Uploaded">
            <a
              href={getMediaUrl(order.payment_proof_path) ?? '#'}
              target="_blank"
              rel="noopener noreferrer"
              className="inline-flex items-center gap-1 text-xs text-primary hover:underline"
            >
              <ExternalLink className="size-3" />
              View Proof
            </a>
          </Field>
        ) : null}
        {order.deposit_amount ? (
          <Field label="Deposit Paid">
            <span className="font-semibold text-emerald-600 dark:text-emerald-400">
              {fmtMoney(order.deposit_amount)} EGP
            </span>
          </Field>
        ) : null}
        <Field label="Remaining Balance">
          <span className={cn('font-semibold tabular-nums', remaining > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400')}>
            {fmtMoney(remaining)} EGP
          </span>
        </Field>
      </FieldGrid>
    </InfoCard>
  );
}

// ── Part 9 — Timeline ─────────────────────────────────────────────────────────

// ── Enterprise Audit Timeline helpers ────────────────────────────────────────

type AuditFilter = OrderActivityActionType | 'all';

const AUDIT_FILTERS: Array<{ key: AuditFilter; label: string }> = [
  { key: 'all',       label: 'All' },
  { key: 'workflow',  label: 'Workflow' },
  { key: 'payment',   label: 'Payment' },
  { key: 'inventory', label: 'Inventory' },
  { key: 'shipping',  label: 'Shipping' },
  { key: 'customer',  label: 'Customer' },
  { key: 'system',    label: 'System' },
  { key: 'note',      label: 'Notes' },
];

type EventIconConfig = { Icon: React.ComponentType<{ className?: string }>; bg: string; ring: string; text: string };

const EVENT_CONFIGS: Record<string, EventIconConfig> = {
  workflow:   { Icon: GitBranch,    bg: 'bg-violet-100 dark:bg-violet-950', ring: 'border-violet-300 dark:border-violet-700', text: 'text-violet-600 dark:text-violet-400' },
  payment:    { Icon: CheckCircle2, bg: 'bg-emerald-100 dark:bg-emerald-950', ring: 'border-emerald-300 dark:border-emerald-700', text: 'text-emerald-600 dark:text-emerald-400' },
  inventory:  { Icon: Box,          bg: 'bg-amber-100 dark:bg-amber-950', ring: 'border-amber-300 dark:border-amber-700', text: 'text-amber-600 dark:text-amber-400' },
  shipping:   { Icon: Truck,        bg: 'bg-cyan-100 dark:bg-cyan-950', ring: 'border-cyan-300 dark:border-cyan-700', text: 'text-cyan-600 dark:text-cyan-400' },
  customer:   { Icon: UserCheck,    bg: 'bg-blue-100 dark:bg-blue-950', ring: 'border-blue-300 dark:border-blue-700', text: 'text-blue-600 dark:text-blue-400' },
  note:       { Icon: MessageCircle, bg: 'bg-orange-100 dark:bg-orange-950', ring: 'border-orange-300 dark:border-orange-700', text: 'text-orange-600 dark:text-orange-400' },
  created:    { Icon: Plus,         bg: 'bg-green-100 dark:bg-green-950', ring: 'border-green-300 dark:border-green-700', text: 'text-green-600 dark:text-green-400' },
  updated:    { Icon: Edit,         bg: 'bg-blue-100 dark:bg-blue-950', ring: 'border-blue-300 dark:border-blue-700', text: 'text-blue-600 dark:text-blue-400' },
  deleted:    { Icon: XCircle,      bg: 'bg-red-100 dark:bg-red-950', ring: 'border-red-300 dark:border-red-700', text: 'text-red-600 dark:text-red-400' },
  automation: { Icon: RefreshCw,    bg: 'bg-violet-100 dark:bg-violet-950', ring: 'border-violet-300 dark:border-violet-700', text: 'text-violet-600 dark:text-violet-400' },
};

const DEFAULT_EVENT_CONFIG: EventIconConfig = {
  Icon: Activity,
  bg: 'bg-slate-100 dark:bg-slate-800',
  ring: 'border-slate-300 dark:border-slate-600',
  text: 'text-slate-500 dark:text-slate-400',
};

function getEventConfig(actionType: string | null): EventIconConfig {
  return EVENT_CONFIGS[actionType ?? ''] ?? DEFAULT_EVENT_CONFIG;
}

function AuditSourceBadge({ source }: { source: string | null }) {
  if (!source) return null;
  const labels: Record<string, string> = {
    dashboard:  'Dashboard',
    mobile_app: 'Mobile',
    api:        'API',
    woocommerce:'WooCommerce',
    automation: 'Automation',
    cron:       'Scheduled',
    webhook:    'Webhook',
  };
  return (
    <Badge variant="outline" className="text-[10px] px-1.5 py-0 h-4 font-normal">
      {labels[source] ?? source}
    </Badge>
  );
}

function AuditActorBadge({ actorType }: { actorType: string | null }) {
  if (!actorType || actorType === 'user') return null;
  const cfg: Record<string, { label: string; Icon: React.ComponentType<{ className?: string }>; cls: string }> = {
    system:      { label: 'System',     Icon: Bot,       cls: 'text-slate-500' },
    api:         { label: 'API',        Icon: Globe,     cls: 'text-slate-500' },
    automation:  { label: 'Auto',       Icon: RefreshCw, cls: 'text-violet-600' },
    woocommerce: { label: 'WooCommerce',Icon: Store,     cls: 'text-orange-600' },
    webhook:     { label: 'Webhook',    Icon: Globe,     cls: 'text-slate-500' },
  };
  const c = cfg[actorType];
  if (!c) return null;
  return (
    <span className={cn('inline-flex items-center gap-1 text-[10px]', c.cls)}>
      <c.Icon className="size-2.5" />
      {c.label}
    </span>
  );
}

function ChangeDiff({ prev, next, fields }: {
  prev: Record<string, unknown> | null;
  next: Record<string, unknown> | null;
  fields: string[] | null;
}) {
  const keys = fields?.length ? fields : prev ? Object.keys(prev) : next ? Object.keys(next) : [];
  if (keys.length === 0) return null;
  return (
    <div className="mt-2 rounded-md border bg-muted/30 px-3 py-2">
      <p className="text-[11px] font-semibold text-muted-foreground mb-1.5 uppercase tracking-wide">Changed Fields</p>
      <div className="flex flex-col gap-1">
        {keys.map(k => (
          <div key={k} className="flex items-baseline gap-1.5 text-xs font-mono">
            <span className="text-muted-foreground min-w-[90px] shrink-0">{k}</span>
            <span className="text-red-500 line-through break-all">{prev?.[k] != null ? String(prev[k]) : '—'}</span>
            <span className="text-muted-foreground mx-0.5">→</span>
            <span className="text-emerald-600 dark:text-emerald-400 break-all">{next?.[k] != null ? String(next[k]) : '—'}</span>
          </div>
        ))}
      </div>
    </div>
  );
}

function auditExportJSON(events: OrderActivity[], orderId: string) {
  const blob = new Blob([JSON.stringify(events, null, 2)], { type: 'application/json' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `order-${orderId.slice(0, 8)}-audit-${new Date().toISOString().slice(0, 10)}.json`;
  a.click();
  URL.revokeObjectURL(url);
}

function auditExportCSV(events: OrderActivity[], orderId: string) {
  const header = ['Date', 'Event Type', 'Action', 'Description', 'Actor', 'Actor Type', 'Source', 'Reason', 'Changed Fields', 'IP Address'];
  const rows = events.map(e => [
    e.created_at,
    e.event_type,
    e.action_type ?? '',
    e.description,
    e.actor_name ?? '',
    e.actor_type ?? '',
    e.source ?? '',
    e.reason ?? '',
    (e.changed_fields ?? []).join('; '),
    e.ip_address ?? '',
  ]);
  const csv = [header, ...rows]
    .map(r => r.map(c => `"${String(c).replace(/"/g, '""')}"`).join(','))
    .join('\n');
  const blob = new Blob([csv], { type: 'text/csv' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `order-${orderId.slice(0, 8)}-audit-${new Date().toISOString().slice(0, 10)}.csv`;
  a.click();
  URL.revokeObjectURL(url);
}

function EnterpriseAuditTimeline({ order }: { order: Order }) {
  const { data: events = [], isLoading } = useOrderActivities(order.id);
  const [filter, setFilter] = useState<AuditFilter>('all');
  const [search, setSearch] = useState('');
  const [expanded, setExpanded] = useState<Set<string>>(new Set());

  function toggle(id: string) {
    setExpanded(prev => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id); else next.add(id);
      return next;
    });
  }

  const filtered = events.filter(ev => {
    if (filter !== 'all' && ev.action_type !== filter) return false;
    if (search.trim()) {
      const q = search.toLowerCase();
      return (
        ev.description.toLowerCase().includes(q) ||
        (ev.actor_name ?? '').toLowerCase().includes(q) ||
        (ev.event_type ?? '').toLowerCase().includes(q) ||
        (ev.reason ?? '').toLowerCase().includes(q)
      );
    }
    return true;
  });

  return (
    <InfoCard
      title="Audit Timeline"
      icon={Activity}
      headerExtra={
        <div className="flex items-center gap-1.5">
          <Button
            variant="ghost"
            size="sm"
            className="h-7 px-2 text-xs gap-1"
            onClick={() => auditExportCSV(filtered, order.id)}
          >
            <Download className="size-3" />
            CSV
          </Button>
          <Button
            variant="ghost"
            size="sm"
            className="h-7 px-2 text-xs gap-1"
            onClick={() => auditExportJSON(filtered, order.id)}
          >
            <Download className="size-3" />
            JSON
          </Button>
        </div>
      }
    >
      {/* Filter bar */}
      <div className="flex items-center gap-1.5 overflow-x-auto pb-1 scrollbar-none">
        <Filter className="size-3.5 text-muted-foreground shrink-0" />
        {AUDIT_FILTERS.map(f => (
          <button
            key={f.key}
            onClick={() => setFilter(f.key)}
            className={cn(
              'shrink-0 rounded-full px-3 py-0.5 text-xs font-medium transition-colors',
              filter === f.key
                ? 'bg-primary text-primary-foreground'
                : 'bg-muted text-muted-foreground hover:bg-muted/80',
            )}
          >
            {f.label}
          </button>
        ))}
      </div>

      {/* Search */}
      <div className="relative mt-2">
        <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 size-3.5 text-muted-foreground" />
        <Input
          value={search}
          onChange={e => setSearch(e.target.value)}
          placeholder="Search events…"
          className="pl-8 h-8 text-sm"
        />
      </div>

      {/* Event list */}
      {isLoading ? (
        <div className="flex flex-col gap-3 mt-3">
          {Array.from({ length: 4 }).map((_, i) => (
            <div key={i} className="flex items-start gap-3">
              <Skeleton className="size-8 rounded-full shrink-0" />
              <div className="flex-1 space-y-1.5 pt-1">
                <Skeleton className="h-3.5 w-3/4" />
                <Skeleton className="h-3 w-1/2" />
              </div>
            </div>
          ))}
        </div>
      ) : filtered.length === 0 ? (
        <EmptyState icon={Activity} message={search || filter !== 'all' ? 'No events match your filter.' : 'No audit events recorded yet.'} />
      ) : (
        <div className="relative mt-3">
          <div className="absolute left-4 top-0 bottom-0 w-px bg-border" />
          <div className="flex flex-col gap-0">
            {filtered.map(ev => {
              const cfg = getEventConfig(ev.action_type);
              const isOpen = expanded.has(ev.id);
              const hasDetail = ev.reason || ev.previous_value || ev.new_value || ev.ip_address || (ev.payload && Object.keys(ev.payload).length > 0);

              return (
                <div key={ev.id} className="relative pl-1">
                  {/* Collapsed row */}
                  <button
                    className={cn(
                      'group flex w-full items-start gap-3 rounded-md px-2 py-2.5 text-start transition-colors',
                      hasDetail ? 'hover:bg-muted/40 cursor-pointer' : 'cursor-default',
                    )}
                    onClick={() => hasDetail && toggle(ev.id)}
                    disabled={!hasDetail}
                  >
                    <div className={cn('relative z-10 flex size-8 shrink-0 items-center justify-center rounded-full border', cfg.bg, cfg.ring)}>
                      <cfg.Icon className={cn('size-3.5', cfg.text)} />
                    </div>

                    <div className="flex-1 min-w-0 pt-0.5">
                      <div className="flex items-start justify-between gap-2">
                        <p className="text-sm font-medium leading-snug">{ev.description}</p>
                        {hasDetail && (
                          isOpen
                            ? <ChevronDown className="size-3.5 text-muted-foreground shrink-0 mt-0.5" />
                            : <ChevronRight className="size-3.5 text-muted-foreground shrink-0 mt-0.5" />
                        )}
                      </div>

                      <div className="flex flex-wrap items-center gap-x-2 gap-y-0.5 mt-0.5">
                        {/* Actor */}
                        {ev.actor_name ? (
                          <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                            <User className="size-2.5" />
                            {ev.actor_name}
                          </span>
                        ) : (
                          <AuditActorBadge actorType={ev.actor_type} />
                        )}

                        {ev.actor_name && <AuditActorBadge actorType={ev.actor_type} />}

                        <AuditSourceBadge source={ev.source} />

                        {ev.action_type && ev.action_type !== 'system' && (
                          <Badge variant="secondary" className="text-[10px] px-1.5 py-0 h-4 capitalize font-normal">
                            {ev.action_type}
                          </Badge>
                        )}

                        <span className="text-[11px] text-muted-foreground">{fmtDateTime(ev.created_at)}</span>
                      </div>
                    </div>
                  </button>

                  {/* Expanded detail panel */}
                  {isOpen && hasDetail && (
                    <div className="ml-11 mb-2 rounded-md border bg-muted/20 px-3 py-2.5 text-sm">
                      {ev.reason && (
                        <div className="mb-2">
                          <span className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Reason</span>
                          <p className="text-sm mt-0.5">{ev.reason}</p>
                        </div>
                      )}

                      <ChangeDiff
                        prev={ev.previous_value}
                        next={ev.new_value}
                        fields={ev.changed_fields}
                      />

                      {(ev.payload && Object.keys(ev.payload).length > 0 && !ev.previous_value && !ev.new_value) && (
                        <div className="mt-2 rounded-md border bg-muted/30 px-3 py-2">
                          <p className="text-[11px] font-semibold text-muted-foreground mb-1.5 uppercase tracking-wide">Details</p>
                          <div className="flex flex-col gap-1">
                            {Object.entries(ev.payload).map(([k, v]) => (
                              <div key={k} className="flex items-baseline gap-1.5 text-xs font-mono">
                                <span className="text-muted-foreground min-w-[90px] shrink-0">{k}</span>
                                <span className="break-all">{String(v)}</span>
                              </div>
                            ))}
                          </div>
                        </div>
                      )}

                      {ev.ip_address && (
                        <p className="text-[11px] text-muted-foreground mt-2 flex items-center gap-1">
                          <Globe className="size-2.5" />
                          {ev.ip_address}
                        </p>
                      )}
                    </div>
                  )}
                </div>
              );
            })}
          </div>
        </div>
      )}
    </InfoCard>
  );
}

// ── Part 10 — Workflow History ────────────────────────────────────────────────

function WorkflowHistoryCard({ order }: { order: Order }) {
  return (
    <InfoCard title="Workflow History" icon={GitBranch}>
      <div className="flex flex-col gap-4">
        <div className="rounded-md border bg-muted/20 px-4 py-3">
          <div className="flex items-center gap-2 mb-1">
            <span className="text-xs text-muted-foreground">Current Status</span>
            <OrderStatusBadge status={order.status} />
          </div>
          {order.status_entered_at ? (
            <p className="text-xs text-muted-foreground">Entered: {fmtDateTime(order.status_entered_at)}</p>
          ) : null}
          {order.status_entered_by ? (
            <p className="text-xs text-muted-foreground">By: {order.status_entered_by}</p>
          ) : null}
        </div>
        {order.previous_status ? (
          <div className="rounded-md border px-4 py-2">
            <p className="text-xs text-muted-foreground mb-0.5">Previous Status</p>
            <p className="text-sm font-medium capitalize">{String(order.previous_status).replace(/_/g, ' ')}</p>
          </div>
        ) : null}
        <FieldGrid cols={2}>
          <Field label="Created">{fmtDate(order.created_at)}</Field>
          {order.date_paid ? <Field label="Payment Verified">{fmtDate(order.date_paid)}</Field> : null}
          {order.inventory_reserved_at ? <Field label="Reserved">{fmtDate(order.inventory_reserved_at)}</Field> : null}
          {order.inventory_shipped_at ? <Field label="Dispatched">{fmtDate(order.inventory_shipped_at)}</Field> : null}
          {order.requested_delivery_date ? <Field label="Requested Delivery">{fmtDate(order.requested_delivery_date)}</Field> : null}
        </FieldGrid>
        <div className="rounded-md border px-3 py-2 text-xs text-muted-foreground">
          Full audit trail available via activity log. Key milestone dates shown above.
        </div>
      </div>
    </InfoCard>
  );
}

// ── Part 11 — Related Records ─────────────────────────────────────────────────

function RelatedRecordsCard({ order }: { order: Order }) {
  const records: Array<{ label: string; value: string | null | undefined; href?: string; icon: React.ComponentType<{ className?: string }> }> = [
    { label: 'Customer', value: order.customer?.name, href: order.customer ? `/app/customers/${order.customer.id}` : undefined, icon: UserCheck },
    { label: 'Channel', value: order.channel?.name, icon: Store },
    { label: 'Warehouse', value: order.assigned_warehouse_id, icon: Warehouse },
  ];

  const filled = records.filter((r) => r.value);
  if (filled.length === 0) return null;

  return (
    <InfoCard title="Related Records" icon={ExternalLink}>
      <div className="flex flex-col gap-2">
        {filled.map(({ label, value, href, icon: Icon }) => (
          <div key={label} className="flex items-center gap-2.5 rounded-md border px-3 py-2 text-sm">
            <Icon className="size-4 shrink-0 text-muted-foreground" />
            <div className="flex-1 min-w-0">
              <p className="text-[10px] text-muted-foreground">{label}</p>
              <p className="truncate font-medium">{value}</p>
            </div>
            {href ? (
              <a href={href} className="shrink-0 text-muted-foreground hover:text-foreground">
                <ExternalLink className="size-3.5" />
              </a>
            ) : null}
          </div>
        ))}
      </div>
    </InfoCard>
  );
}

// ── Part 12 — Quick Actions + Workflow ───────────────────────────────────────

type WorkflowAction = {
  key: string;
  label: string;
  icon: React.ComponentType<{ className?: string }>;
  variant: 'default' | 'outline' | 'destructive';
};

const WORKFLOW_ACTIONS: Record<string, WorkflowAction[]> = {
  pending: [
    { key: 'confirm',          label: 'Confirm Order',        icon: CheckCircle2,     variant: 'default'     },
    { key: 'cancel',           label: 'Cancel Order',         icon: XCircle,          variant: 'destructive' },
  ],
  awaiting_payment: [
    { key: 'confirm',          label: 'Confirm Order',        icon: CheckCircle2,     variant: 'default'     },
    { key: 'cancel',           label: 'Cancel Order',         icon: XCircle,          variant: 'destructive' },
  ],
  processing: [
    { key: 'prepare',          label: 'Move To Preparing',    icon: ArrowRightCircle, variant: 'default'     },
    { key: 'awaiting_stock',   label: 'Mark Awaiting Stock',  icon: Box,              variant: 'outline'     },
    { key: 'review',           label: 'Send To Review',       icon: Activity,         variant: 'outline'     },
    { key: 'cancel',           label: 'Cancel Order',         icon: XCircle,          variant: 'destructive' },
  ],
  awaiting_stock: [
    { key: 'resume',           label: 'Resume Processing',    icon: ArrowRightCircle, variant: 'default'     },
    { key: 'cancel',           label: 'Cancel Order',         icon: XCircle,          variant: 'destructive' },
  ],
  confirmed: [
    { key: 'prepare',          label: 'Move To Preparing',    icon: ArrowRightCircle, variant: 'default'     },
    { key: 'reschedule',       label: 'Reschedule',           icon: Clock,            variant: 'outline'     },
    { key: 'cancel',           label: 'Cancel Order',         icon: XCircle,          variant: 'destructive' },
  ],
  preparing: [
    { key: 'dispatch',         label: 'Dispatch',             icon: Truck,            variant: 'default'     },
    { key: 'review',           label: 'Send To Review',       icon: Activity,         variant: 'outline'     },
    { key: 'reschedule',       label: 'Reschedule',           icon: Clock,            variant: 'outline'     },
    { key: 'cancel',           label: 'Cancel Order',         icon: XCircle,          variant: 'destructive' },
  ],
  out_for_delivery: [
    { key: 'complete_delivery', label: 'Mark Delivered',      icon: CheckCircle2,     variant: 'default'     },
    { key: 'return',            label: 'Process Return',      icon: RotateCcw,        variant: 'outline'     },
    { key: 'review',            label: 'Send To Review',      icon: Activity,         variant: 'outline'     },
    { key: 'reschedule',        label: 'Reschedule',          icon: Clock,            variant: 'outline'     },
  ],
  delivered: [
    { key: 'complete',          label: 'Complete Review',     icon: CheckCircle2,     variant: 'default'     },
    { key: 'review',            label: 'Send To Review',      icon: Activity,         variant: 'outline'     },
    { key: 'resume',            label: 'Resume Processing',   icon: ArrowRightCircle, variant: 'outline'     },
    { key: 'resume_confirmed',  label: 'Resume To Confirmed', icon: ArrowRightCircle, variant: 'outline'     },
    { key: 'reschedule',        label: 'Reschedule',          icon: Clock,            variant: 'outline'     },
    { key: 'cancel',            label: 'Cancel Order',        icon: XCircle,          variant: 'destructive' },
  ],
  returned: [
    { key: 'return_to_confirmed', label: 'Return To Confirmed', icon: RotateCcw,      variant: 'default'     },
    { key: 'review',              label: 'Move To Review',      icon: Activity,        variant: 'outline'     },
    { key: 'cancel',              label: 'Cancel Order',        icon: XCircle,         variant: 'destructive' },
  ],
  review: [
    { key: 'resume',    label: 'Resume Processing', icon: ArrowRightCircle, variant: 'default'     },
    { key: 'reschedule', label: 'Reschedule',       icon: Clock,            variant: 'outline'     },
    { key: 'cancel',    label: 'Cancel Order',      icon: XCircle,         variant: 'destructive' },
  ],
  rescheduled: [
    { key: 'resume',    label: 'Resume Processing', icon: ArrowRightCircle, variant: 'default'     },
    { key: 'reschedule', label: 'Reschedule',       icon: Clock,            variant: 'outline'     },
    { key: 'cancel',    label: 'Cancel Order',      icon: XCircle,         variant: 'destructive' },
  ],
  completed: [],
  cancelled: [],
};

function QuickActionsPanel({
  order,
  onEdit,
  onConfirmCustomer,
  onPrint,
}: {
  order: Order;
  onEdit: () => void;
  onConfirmCustomer: () => void;
  onPrint: () => void;
}) {
  const confirm          = useOrderWorkflowConfirm();
  const moveToPrep       = useOrderWorkflowMoveToPreparation();
  const completeDeliv    = useOrderWorkflowCompleteDelivery();
  const completeOrder    = useOrderWorkflowComplete();
  const processReturn    = useOrderWorkflowReturn();
  const cancelOrder      = useOrderWorkflowCancel();
  const moveToReview     = useOrderWorkflowMoveToReview();
  const resume           = useOrderWorkflowResume();
  const dispatch         = useOrderWorkflowDispatch();
  const reschedule       = useOrderWorkflowReschedule();
  const markAwaitingStock = useOrderWorkflowMarkAwaitingStock();
  const resumeConfirmed  = useOrderWorkflowResumeToConfirmed();
  const returnConfirmed  = useOrderWorkflowReturnToConfirmed();

  const today = new Date().toISOString().slice(0, 10);
  const [showReschedule, setShowReschedule] = useState(false);
  const [rescheduleDate, setRescheduleDate] = useState(today);

  const isPending = [
    confirm, moveToPrep, completeDeliv, completeOrder, processReturn,
    cancelOrder, moveToReview, resume, dispatch, reschedule,
    markAwaitingStock, resumeConfirmed, returnConfirmed,
  ].some((m) => m.isPending);

  const actions = WORKFLOW_ACTIONS[order.status as keyof typeof WORKFLOW_ACTIONS] ?? [];

  function handleAction(key: string) {
    switch (key) {
      case 'confirm':             confirm.mutate(order.id); break;
      case 'prepare':             moveToPrep.mutate(order.id); break;
      case 'complete_delivery':   completeDeliv.mutate(order.id); break;
      case 'complete':            completeOrder.mutate(order.id); break;
      case 'return':              processReturn.mutate({ id: order.id }); break;
      case 'cancel':              cancelOrder.mutate({ id: order.id }); break;
      case 'review':              moveToReview.mutate({ id: order.id }); break;
      case 'resume':              resume.mutate(order.id); break;
      case 'dispatch':            dispatch.mutate(order.id); break;
      case 'awaiting_stock':      markAwaitingStock.mutate({ id: order.id }); break;
      case 'resume_confirmed':    resumeConfirmed.mutate(order.id); break;
      case 'return_to_confirmed': returnConfirmed.mutate(order.id); break;
      case 'reschedule':          setShowReschedule(true); break;
    }
  }

  function handleRescheduleConfirm() {
    if (!rescheduleDate) return;
    reschedule.mutate({ id: order.id, nextDeliveryDate: rescheduleDate }, {
      onSuccess: () => setShowReschedule(false),
    });
  }

  return (
    <Card className="gap-0">
      <CardHeader className="px-4 py-3 border-b">
        <CardTitle className="text-sm font-semibold">Quick Actions</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-2 px-4 py-4">
        {/* Static actions */}
        <Button variant="outline" size="sm" className="justify-start gap-2" onClick={onEdit}>
          <Edit className="size-4" /> Edit Order
        </Button>
        <Button variant="outline" size="sm" className="justify-start gap-2" onClick={onPrint}>
          <Printer className="size-4" /> Print Invoice
        </Button>
        <Button variant="outline" size="sm" className="justify-start gap-2" onClick={onConfirmCustomer}>
          <UserCheck className="size-4" /> Confirm Customer
        </Button>
        {order.customer ? (
          <Button variant="outline" size="sm" className="justify-start gap-2" asChild>
            <a href={`/app/customers/${order.customer.id}`}>
              <ExternalLink className="size-4" /> Open Customer
            </a>
          </Button>
        ) : null}
        {order.assigned_warehouse_id ? (
          <Button variant="outline" size="sm" className="justify-start gap-2" asChild>
            <a href={ROUTES.warehouses}>
              <Warehouse className="size-4" /> Open Warehouse
            </a>
          </Button>
        ) : null}

        {/* Workflow actions */}
        {actions.length > 0 ? (
          <>
            <Separator className="my-1" />
            <p className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Workflow</p>
            {actions.map((action) => {
              if (action.key === 'reschedule' && showReschedule) {
                return (
                  <div key="reschedule-form" className="flex flex-col gap-2 rounded-md border p-3">
                    <label className="text-xs font-medium text-muted-foreground">New Delivery Date</label>
                    <input
                      type="date"
                      value={rescheduleDate}
                      min={today}
                      onChange={(e) => setRescheduleDate(e.target.value)}
                      className="h-8 w-full rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
                    />
                    <div className="flex gap-2">
                      <Button
                        size="sm"
                        onClick={handleRescheduleConfirm}
                        disabled={reschedule.isPending || !rescheduleDate}
                        className="gap-1.5"
                      >
                        {reschedule.isPending ? <Loader2 className="size-3.5 animate-spin" /> : <Clock className="size-3.5" />}
                        Confirm
                      </Button>
                      <Button size="sm" variant="ghost" onClick={() => setShowReschedule(false)}>
                        Cancel
                      </Button>
                    </div>
                  </div>
                );
              }
              return (
                <Button
                  key={action.key}
                  variant={action.variant}
                  size="sm"
                  onClick={() => handleAction(action.key)}
                  disabled={isPending}
                  className="justify-start gap-2"
                >
                  {isPending ? <Loader2 className="size-4 animate-spin" /> : <action.icon className="size-4" />}
                  {action.label}
                </Button>
              );
            })}
          </>
        ) : null}
      </CardContent>
    </Card>
  );
}

// ── Main Page ─────────────────────────────────────────────────────────────────

export function OrderDetailPage() {
  const { t } = useTranslation('orders');
  const { id = '' } = useParams<{ id: string }>();
  const { data: order, isLoading, isError } = useOrderQuery(id);

  const navigate = useNavigate();
  const [confirmOpen, setConfirmOpen] = useState(false);

  // Part 16 — Loading
  if (isLoading) {
    return (
      <div className="flex flex-col gap-4 px-1">
        <Order360Skeleton />
      </div>
    );
  }

  // Part 15 — Error state (network failure / 5xx)
  if (isError) {
    return (
      <div className="flex flex-col items-center gap-4 py-20 text-center">
        <Package className="size-12 text-destructive/40" />
        <p className="text-lg font-medium">Failed to load order</p>
        <p className="text-sm text-muted-foreground">Could not retrieve order data. Check your connection and try again.</p>
        <Button variant="outline" size="sm" onClick={() => window.location.reload()}>Retry</Button>
      </div>
    );
  }

  // Part 15 — Not found
  if (!order) {
    return (
      <div className="flex flex-col items-center gap-4 py-20 text-center">
        <Package className="size-12 text-muted-foreground/40" />
        <p className="text-lg font-medium">{t('detail.notFound')}</p>
        <p className="text-sm text-muted-foreground">{t('detail.notFoundMessage')}</p>
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-4">
      {/* Part 1 — Header */}
      <OrderHeader
        order={order}
        onEdit={() => navigate(`${ROUTES.orders}/${order.id}/edit`)}
        onConfirmCustomer={() => setConfirmOpen(true)}
        onPrint={() => window.print()}
      />

      {/* Part 13 — KPI Row */}
      <KpiRow order={order} />

      {/* Part 14 — Responsive layout: main content + right rail */}
      <div className="grid gap-4 lg:grid-cols-[1fr_320px]">
        {/* ── Left / Center column ── */}
        <div className="flex flex-col gap-4 min-w-0">
          {/* Part 3 — Customer 360 */}
          <CustomerCard order={order} />

          {/* Two-col row: Address + Shipping */}
          <div className="grid gap-4 md:grid-cols-2">
            {/* Part 5 — Address */}
            <AddressCard order={order} />
            {/* Part 4 — Shipping */}
            <ShippingCard order={order} />
          </div>

          {/* Part 7 — Products Grid */}
          <ProductsGrid order={order} />

          {/* Two-col row: Payment + Inventory */}
          <div className="grid gap-4 md:grid-cols-2">
            {/* Part 8 — Payment */}
            <PaymentCard order={order} />
            {/* Part 6 — Inventory */}
            <InventoryCard order={order} />
          </div>

          {/* Part 9 — Timeline */}
          <EnterpriseAuditTimeline order={order} />

          {/* Part 10 — Workflow History */}
          <WorkflowHistoryCard order={order} />
        </div>

        {/* ── Right sticky rail ── */}
        <div className="flex flex-col gap-4 lg:sticky lg:top-4 lg:self-start">
          {/* Part 2 — Financial Summary */}
          <FinancialSummaryCard order={order} />

          {/* Part 12 — Quick Actions + Workflow */}
          <QuickActionsPanel
            order={order}
            onEdit={() => navigate(`${ROUTES.orders}/${order.id}/edit`)}
            onConfirmCustomer={() => setConfirmOpen(true)}
            onPrint={() => window.print()}
          />

          {/* Part 11 — Related Records */}
          <RelatedRecordsCard order={order} />

          {/* Notes (contextual) */}
          {(order.notes || order.customer_note) ? (
            <InfoCard title="Notes" icon={Building2}>
              <div className="flex flex-col gap-3">
                {order.notes ? (
                  <div>
                    <p className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground mb-1">Internal</p>
                    <p className="text-sm whitespace-pre-wrap">{order.notes}</p>
                  </div>
                ) : null}
                {order.customer_note ? (
                  <div>
                    <p className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground mb-1">Customer Note</p>
                    <p className="rounded-md border bg-muted/30 px-3 py-2 text-sm italic">{order.customer_note}</p>
                  </div>
                ) : null}
              </div>
            </InfoCard>
          ) : null}
        </div>
      </div>

      {/* Dialogs */}
      <OrderConfirmCustomerDialog order={order} open={confirmOpen} onOpenChange={setConfirmOpen} />
    </div>
  );
}
