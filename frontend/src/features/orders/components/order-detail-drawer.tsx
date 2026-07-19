import {
  Activity,
  ArrowDown,
  ArrowRightCircle,
  BadgeCheck,
  Banknote,
  Box,
  Building,
  Building2,
  CalendarClock,
  CheckCircle2,
  Clock,
  Copy,
  Edit,
  ExternalLink,
  FileCheck,
  Flag,
  Globe,
  Hash,
  Home,
  Layers,
  LayoutGrid,
  Loader2,
  Lock,
  Mail,
  Map as MapIcon,
  MapPin,
  MessageSquare,
  Navigation,
  Package,
  Paperclip,
  PenLine,
  Percent,
  Phone,
  RotateCcw,
  ShieldCheck,
  ShoppingBag,
  StickyNote,
  Trash2,
  Truck,
  User,
  UserCheck,
  UserPlus,
  X,
  XCircle,
} from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { MediaViewer } from '@/components/ui/media-viewer';
import { Separator } from '@/components/ui/separator';
import React from 'react';
import {
  Sheet,
  SheetClose,
  SheetContent,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet';
import { Tabs } from '@/components/ds/tabs';
import { OrderStatusBadge } from '@/features/orders/components/order-status-badge';
import { OrderNotesTab } from '@/features/orders/components/notes-tab';
import { OrderDistributionStageBanner } from '@/features/orders/components/order-distribution-stage-banner';
import type { Order, OrderActivity } from '@/features/orders/types/order';
import {
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
import { getMediaUrl } from '@/lib/media';
import { cn } from '@/lib/utils';

// ── Helper primitives ─────────────────────────────────────────────────────────

function DetailRow({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-0.5">
      <dt className="text-xs font-medium text-muted-foreground">{label}</dt>
      <dd className="text-sm">{children ?? <span className="text-muted-foreground">—</span>}</dd>
    </div>
  );
}

function DetailGrid({ children, cols = 2 }: { children: React.ReactNode; cols?: 1 | 2 }) {
  return (
    <dl className={cn('grid gap-x-4 gap-y-4', cols === 2 ? 'grid-cols-2' : 'grid-cols-1')}>
      {children}
    </dl>
  );
}

function SectionTitle({ children }: { children: React.ReactNode }) {
  return <h3 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground mb-3">{children}</h3>;
}

function formatDate(d: string | null): string {
  if (!d) return '—';
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(d));
}

function formatDateTime(d: string | null): string {
  if (!d) return '—';
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(d));
}

/** Raw number → "X,XXX.XX" without currency, for inline use (line items). */
function formatMoney(n: number): string {
  const v = Object.is(n, -0) ? 0 : n;
  return v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/**
 * Enterprise currency formatting: "EGP X,XXX.XX".
 * Returns "—" for zero or near-zero values (handles -0.00).
 * Pass allowZero=true to show "EGP 0.00" explicitly.
 */
function fmtCur(n: number | null | undefined, allowZero = false): string {
  const v = Object.is(n ?? 0, -0) ? 0 : (n ?? 0);
  if (!allowZero && Math.abs(v) < 0.005) return '—';
  return `EGP ${Math.abs(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

// ── Payment method labels ─────────────────────────────────────────────────────

const PAYMENT_METHOD_LABELS: Record<string, string> = {
  cod:           'الدفع عند الاستلام',
  cash:          'نقد',
  visa:          'بطاقة فيزا',
  mastercard:    'ماستركارد',
  credit_card:   'بطاقة ائتمان',
  card:          'بطاقة ائتمان',
  bank:          'تحويل بنكي',
  bank_transfer: 'تحويل بنكي',
  instalment:    'تقسيط',
  installment:   'تقسيط',
  wallet:        'محفظة رقمية',
  online:        'دفع إلكتروني',
  cheque:        'شيك',
  check:         'شيك',
};

/** Converts a technical payment code ("cod", "bank_transfer") to a business label. */
function formatPaymentLabel(raw: string | null): string | null {
  if (!raw) return null;
  const key = raw.toLowerCase().replace(/[-\s]/g, '_');
  if (PAYMENT_METHOD_LABELS[key]) return PAYMENT_METHOD_LABELS[key];
  for (const [k, label] of Object.entries(PAYMENT_METHOD_LABELS)) {
    if (key.includes(k) || k.includes(key)) return label;
  }
  // Fallback: title-case the raw value
  return raw.split(/[-_\s]/).map((w) => w.charAt(0).toUpperCase() + w.slice(1)).join(' ');
}

/** Resolves the best human-readable payment method from an order. */
function resolvePaymentLabel(order: { payment_method_manual?: string | null; payment_method_title?: string | null; payment_method?: string | null }): string | null {
  if (order.payment_method_manual) return formatPaymentLabel(order.payment_method_manual);
  if (order.payment_method_title)  return order.payment_method_title; // WooCommerce title is already readable
  if (order.payment_method)        return formatPaymentLabel(order.payment_method);
  return null;
}

// ── Financial row ─────────────────────────────────────────────────────────────

type FinRowValue = number | 'not_applicable' | 'empty';

function FinancialRow({
  label,
  value,
  pct,
  isDiscount = false,
  bold = false,
  allowZero = false,
}: {
  label: string;
  value: FinRowValue;
  pct?: number | null;
  isDiscount?: boolean;
  bold?: boolean;
  allowZero?: boolean;
}) {
  const effectiveLabel = pct != null ? `${label} (${pct}%)` : label;
  let display: React.ReactNode;

  if (value === 'not_applicable') {
    display = <span className="text-muted-foreground italic text-sm">لا ينطبق</span>;
  } else if (value === 'empty' || (!allowZero && typeof value === 'number' && Math.abs(value) < 0.005)) {
    display = <span className="text-muted-foreground text-sm">—</span>;
  } else {
    const formatted = fmtCur(value as number, allowZero);
    display = (
      <span className={cn('tabular-nums text-sm', isDiscount && 'text-emerald-600 dark:text-emerald-400', bold && 'font-semibold')}>
        {isDiscount ? `−${formatted}` : formatted}
      </span>
    );
  }

  return (
    <div className="flex items-baseline justify-between gap-4">
      <span className={cn('text-sm', bold ? 'font-semibold' : 'text-muted-foreground')}>{effectiveLabel}</span>
      {display}
    </div>
  );
}

function KpiCard({ label, value, variant }: { label: string; value: string; variant?: 'discount' }) {
  return (
    <div className="rounded-md border bg-muted/20 px-3 py-2.5">
      <div className="text-[10px] font-medium uppercase tracking-wide text-muted-foreground mb-0.5">{label}</div>
      <div className={cn('text-sm font-semibold tabular-nums', variant === 'discount' && 'text-emerald-600 dark:text-emerald-400')}>
        {value}
      </div>
    </div>
  );
}

// ── Payment proof preview ─────────────────────────────────────────────────────
// Replaced by MediaViewer — trigger rendered inline at call site.

// ── Tab panels ────────────────────────────────────────────────────────────────

function SummaryTab({ order, t }: { order: Order; t: (k: string) => string }) {
  const paymentLabel = resolvePaymentLabel(order);
  const hasDiscount = order.discount_amount > 0.005;
  const hasDeposit  = order.deposit_paid > 0.005;

  const formulaParts: string[] = ['إجمالي المنتجات'];
  if (order.shipping_amount > 0.005) formulaParts.push('الشحن');
  if (order.tax_amount > 0.005)      formulaParts.push('الضريبة');

  return (
    <div className="flex flex-col gap-6 p-4">
      <DetailGrid>
        <DetailRow label={t('detail.orderNumber')}><span className="font-mono font-medium">{order.order_number}</span></DetailRow>
        <DetailRow label={t('detail.orderDate')}>{formatDate(order.order_date)}</DetailRow>
        <DetailRow label={t('detail.status')}><OrderStatusBadge status={order.status} /></DetailRow>
        <DetailRow label={t('detail.channel')}>{order.channel?.name}</DetailRow>
        <DetailRow label={t('detail.externalOrderId')}><span className="font-mono text-xs">{order.external_order_id ?? '—'}</span></DetailRow>
        <DetailRow label={t('detail.paymentMethodTitle')}>{paymentLabel}</DetailRow>
      </DetailGrid>

      <Separator />

      {/* ── Financial Summary ── */}
      <div>
        <SectionTitle>{t('detail.financialSummary')}</SectionTitle>
        <div className="flex flex-col gap-2">
          <FinancialRow label="إجمالي المنتجات" value={order.products_total} />
          <FinancialRow label="الشحن"           value={order.shipping_amount} />
          {hasDiscount && (
            <FinancialRow
              label="الخصم"
              pct={order.discount_percentage}
              value={order.discount_amount}
              isDiscount
            />
          )}
          <FinancialRow
            label="الضريبة"
            value={order.tax_amount > 0 ? order.tax_amount : 'not_applicable'}
          />
          <Separator className="my-1" />
          <FinancialRow label="الإجمالي الكلي" value={order.grand_total} bold allowZero />
          {hasDeposit && (
            <>
              <FinancialRow label="دفعة مقدمة"   value={order.deposit_paid} isDiscount />
              <FinancialRow label="الرصيد المتبقي" value={order.remaining_balance} bold allowZero />
            </>
          )}
        </div>

        {/* Calculation transparency footer */}
        <div className="mt-4 rounded-md bg-muted/40 px-3 py-2 text-[11px] leading-relaxed text-muted-foreground">
          <span className="font-medium">المعادلة: </span>
          {formulaParts.join(' + ')}
          {hasDiscount ? ' − الخصم' : ''}
          {' = الإجمالي الكلي'}
          {hasDeposit ? ' | الإجمالي الكلي − الدفعة المقدمة = المتبقي' : ''}
        </div>
      </div>

      {/* ── KPI cards ── */}
      <div className="grid grid-cols-2 gap-3">
        <KpiCard label="إجمالي المنتجات"  value={fmtCur(order.products_total, true)} />
        <KpiCard
          label={order.discount_percentage != null ? `إجمالي الخصم (${order.discount_percentage}%)` : 'إجمالي الخصم'}
          value={hasDiscount ? fmtCur(order.discount_amount) : '—'}
          variant="discount"
        />
        <KpiCard label="دفعة مقدمة"      value={hasDeposit ? fmtCur(order.deposit_paid) : '—'} />
        <KpiCard label="الرصيد المتبقي"   value={fmtCur(order.remaining_balance, true)} />
      </div>

      {order.notes ? (
        <>
          <Separator />
          <DetailRow label={t('detail.notes')}>{order.notes}</DetailRow>
        </>
      ) : null}
    </div>
  );
}

// ── Address field row ─────────────────────────────────────────────────────────

function AddrField({
  icon,
  label,
  value,
}: {
  icon: React.ReactNode;
  label: string;
  value: string | null | undefined;
}) {
  return (
    <div className="flex items-start gap-2.5">
      <span className="mt-0.5 shrink-0 text-muted-foreground">{icon}</span>
      <div className="min-w-0 flex-1">
        <p className="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">{label}</p>
        <p className="text-sm leading-snug">{value || '—'}</p>
      </div>
    </div>
  );
}

// ── Note card ─────────────────────────────────────────────────────────────────

function NoteCard({
  icon,
  title,
  content,
}: {
  icon: React.ReactNode;
  title: string;
  content: string;
}) {
  return (
    <div className="rounded-lg border overflow-hidden">
      <div className="flex items-center gap-2 border-b bg-muted/40 px-3 py-2">
        <span className="text-muted-foreground">{icon}</span>
        <span className="text-xs font-semibold">{title}</span>
      </div>
      <p className="px-3 py-2.5 text-sm leading-relaxed whitespace-pre-wrap text-muted-foreground">{content}</p>
    </div>
  );
}

// ── Customer Tab ──────────────────────────────────────────────────────────────

function CustomerTab({ order, t }: { order: Order; t: (k: string) => string }) {
  const cust = order.customer;
  const primaryPhone   = order.billing_phone ?? cust?.phone;
  const secondaryPhone = cust?.mobile;
  const email          = cust?.email ?? order.billing_email;
  const stats          = cust?.stats;
  const aov            = stats && stats.total_orders > 0
    ? stats.lifetime_value / stats.total_orders
    : null;

  const hasLegacyBilling  = !!(order.billing_address_1 || order.billing_city || order.billing_first_name);
  const hasLegacyShipping = !!(order.shipping_address_1 || order.shipping_city);
  const hasLocation       = !!order.location;
  const hasMapsData       = !!(order.location || order.google_maps_url);

  const mapsUrl = order.location
    ? `https://www.google.com/maps?q=${order.location.lat},${order.location.lng}`
    : (order.google_maps_url ?? null);

  const fullAddressParts = [
    order.governorate,
    order.city,
    order.delivery_zone,
    order.shipping_address,
    order.building  ? `مبنى ${order.building}` : null,
    order.floor     ? `طابق ${order.floor}` : null,
    order.apartment ? `شقة ${order.apartment}` : null,
    order.landmark  ? `بالقرب من: ${order.landmark}` : null,
  ].filter(Boolean) as string[];
  const fullAddress = fullAddressParts.join('\n');

  const hasAddrData = !!(
    order.governorate || order.city || order.delivery_zone ||
    order.shipping_address || order.building || order.floor ||
    order.apartment || order.landmark
  );

  const copyAddress  = () => void navigator.clipboard.writeText(fullAddress || '—');
  const copyMapsLink = () => void navigator.clipboard.writeText(mapsUrl ?? fullAddress ?? '—');

  const internalNoteContent = [order.notes, order.internal_notes]
    .filter(Boolean).join('\n\n') || null;

  return (
    <div className="flex flex-col gap-4 p-4">

      {/* ── 1. Customer Information Card ── */}
      <div className="rounded-lg border overflow-hidden">
        <div className="flex items-center gap-2 border-b bg-muted/40 px-3 py-2.5">
          <User className="size-3.5 text-muted-foreground" />
          <span className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
            {t('detail.customerInformation')}
          </span>
        </div>
        <div className="p-4">
          <DetailGrid cols={2}>
            <DetailRow label="الاسم">
              <span className="font-medium">{cust?.name ?? '—'}</span>
            </DetailRow>
            <DetailRow label="الكود">
              <span className="flex items-center gap-1 font-mono text-xs">
                <Hash className="size-3 text-muted-foreground" />
                {cust?.code ?? '—'}
              </span>
            </DetailRow>
            <DetailRow label="الهاتف الأساسي">
              {primaryPhone ? (
                <a href={`tel:${primaryPhone}`} className="flex items-center gap-1 text-sm hover:underline">
                  <Phone className="size-3 shrink-0 text-muted-foreground" />
                  {primaryPhone}
                </a>
              ) : <span className="text-muted-foreground">—</span>}
            </DetailRow>
            <DetailRow label="الهاتف الثانوي">
              {secondaryPhone ? (
                <a href={`tel:${secondaryPhone}`} className="flex items-center gap-1 text-sm hover:underline">
                  <Phone className="size-3 shrink-0 text-muted-foreground" />
                  {secondaryPhone}
                </a>
              ) : <span className="text-muted-foreground">—</span>}
            </DetailRow>
            <DetailRow label="البريد الإلكتروني">
              {email ? (
                <a href={`mailto:${email}`} className="flex min-w-0 items-center gap-1 text-sm text-primary hover:underline">
                  <Mail className="size-3 shrink-0" />
                  <span className="truncate">{email}</span>
                </a>
              ) : <span className="text-muted-foreground">—</span>}
            </DetailRow>
            <DetailRow label="عميل منذ">
              {cust?.created_at
                ? formatDate(cust.created_at)
                : <span className="text-muted-foreground">—</span>}
            </DetailRow>
            <DetailRow label="الحالة">
              {cust?.is_active !== undefined ? (
                <span className={cn(
                  'inline-flex items-center gap-1 text-sm font-medium',
                  cust.is_active ? 'text-emerald-600 dark:text-emerald-400' : 'text-muted-foreground',
                )}>
                  {cust.is_active
                    ? <CheckCircle2 className="size-3" />
                    : <XCircle className="size-3" />}
                  {cust.is_active ? 'نشط' : 'غير نشط'}
                </span>
              ) : <span className="text-muted-foreground">—</span>}
            </DetailRow>
            <DetailRow label="آخر طلب">
              {stats?.last_order_date
                ? formatDate(stats.last_order_date)
                : <span className="text-muted-foreground">—</span>}
            </DetailRow>
          </DetailGrid>
        </div>
      </div>

      {/* ── 2. Customer Summary ── */}
      {stats ? (
        <div className="rounded-lg border overflow-hidden">
          <div className="flex items-center gap-2 border-b bg-muted/40 px-3 py-2.5">
            <Activity className="size-3.5 text-muted-foreground" />
            <span className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">ملخص العميل</span>
          </div>
          <div className="grid grid-cols-2 divide-x divide-y">
            <div className="p-3">
              <p className="text-[10px] font-medium uppercase tracking-wide text-muted-foreground mb-0.5">القيمة الإجمالية</p>
              <p className="text-sm font-semibold tabular-nums">{fmtCur(stats.lifetime_value, true)}</p>
            </div>
            <div className="p-3">
              <p className="text-[10px] font-medium uppercase tracking-wide text-muted-foreground mb-0.5">متوسط قيمة الطلب</p>
              <p className="text-sm font-semibold tabular-nums">{aov != null ? fmtCur(aov, true) : '—'}</p>
            </div>
            <div className="p-3">
              <p className="text-[10px] font-medium uppercase tracking-wide text-muted-foreground mb-0.5">إجمالي الطلبات</p>
              <p className="text-sm font-semibold">{stats.total_orders.toLocaleString()}</p>
            </div>
            <div className="p-3">
              <p className="text-[10px] font-medium uppercase tracking-wide text-muted-foreground mb-0.5">أول طلب</p>
              <p className="text-sm">{stats.first_order_date ? formatDate(stats.first_order_date) : '—'}</p>
            </div>
          </div>
        </div>
      ) : null}

      {/* ── 3. Delivery Address ── */}
      <div className="rounded-lg border overflow-hidden">
        {/* Card header: title + verified badge + action buttons */}
        <div className="flex items-center justify-between border-b bg-muted/40 px-3 py-2.5">
          <div className="flex items-center gap-2">
            <MapPin className="size-3.5 text-muted-foreground" />
            <span className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">عنوان التوصيل</span>
            {hasLocation && (
              <span className="inline-flex items-center gap-0.5 rounded-full bg-emerald-100 px-1.5 py-0.5 text-[9px] font-semibold text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-400">
                <BadgeCheck className="size-2.5" />
                GPS محدد
              </span>
            )}
          </div>
          <div className="flex items-center gap-1">
            {mapsUrl ? (
              <Button variant="ghost" size="sm" className="h-7 gap-1 px-2 text-xs" asChild>
                <a href={mapsUrl} target="_blank" rel="noopener noreferrer">
                  <ExternalLink className="size-3" />
                  الخريطة
                </a>
              </Button>
            ) : null}
            {(hasAddrData || hasMapsData) ? (
              <Button variant="ghost" size="sm" className="h-7 gap-1 px-2 text-xs" onClick={copyAddress}>
                <Copy className="size-3" />
                نسخ
              </Button>
            ) : null}
          </div>
        </div>

        {/* 2×2 grid: Location | Building | Full Address | Map */}
        <div className="grid grid-cols-2 divide-x divide-y">

          {/* Top-left: Location */}
          <div className="p-4">
            <p className="mb-3 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
              📍 الموقع
            </p>
            <div className="flex flex-col gap-3">
              <AddrField icon={<Globe className="size-3.5" />}      label="المحافظة"     value={order.governorate} />
              <AddrField icon={<Building className="size-3.5" />}   label="المدينة"      value={order.city} />
              <AddrField icon={<LayoutGrid className="size-3.5" />} label="المنطقة"      value={order.delivery_zone} />
              <AddrField icon={<Navigation className="size-3.5" />} label="الشارع"       value={order.shipping_address} />
            </div>
          </div>

          {/* Top-right: Building Details */}
          <div className="p-4">
            <p className="mb-3 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
              🏢 تفاصيل المبنى
            </p>
            <div className="flex flex-col gap-3">
              <AddrField icon={<Building2 className="size-3.5" />} label="المبنى"       value={order.building} />
              <AddrField icon={<Layers className="size-3.5" />}    label="الطابق"       value={order.floor} />
              <AddrField icon={<Home className="size-3.5" />}      label="الشقة"        value={order.apartment} />
              <AddrField icon={<Flag className="size-3.5" />}      label="علامة مميزة" value={order.landmark} />
            </div>
          </div>

          {/* Bottom-left: Full Address */}
          <div className="p-4">
            <p className="mb-3 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
              📋 العنوان الكامل
            </p>
            {fullAddressParts.length > 0 ? (
              <p className="text-sm leading-relaxed whitespace-pre-line">{fullAddress}</p>
            ) : (
              <p className="text-sm text-muted-foreground">—</p>
            )}
            {order.address_notes ? (
              <div className="mt-3 border-t pt-3">
                <p className="mb-1 text-[10px] font-medium text-muted-foreground">ملاحظات العنوان</p>
                <p className="text-xs leading-relaxed text-muted-foreground">{order.address_notes}</p>
              </div>
            ) : null}
            {mapsUrl ? (
              <a
                href={mapsUrl}
                target="_blank"
                rel="noopener noreferrer"
                className="mt-2 inline-flex items-center gap-1 text-xs text-primary hover:underline"
              >
                <ExternalLink className="size-3" />
                عرض على خرائط جوجل
              </a>
            ) : null}
          </div>

          {/* Bottom-right: Map Preview */}
          <div className="p-4">
            <p className="mb-3 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
              🗺 معاينة الخريطة
            </p>
            {order.location ? (
              <div className="flex flex-col gap-2">
                <div className="flex items-start gap-2 rounded-md border bg-muted/30 px-3 py-2.5">
                  <MapPin className="mt-0.5 size-3.5 shrink-0 text-primary" />
                  <div className="min-w-0">
                    <p className="font-mono text-xs font-medium tabular-nums">
                      {Number(order.location.lat).toFixed(6)}, {Number(order.location.lng).toFixed(6)}
                    </p>
                    <a
                      href={`https://www.google.com/maps?q=${order.location.lat},${order.location.lng}`}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="text-[10px] text-primary hover:underline"
                    >
                      maps.google.com ↗
                    </a>
                  </div>
                </div>
                <div className="flex gap-1.5">
                  <Button variant="outline" size="sm" className="h-7 flex-1 text-xs" asChild>
                    <a href={mapsUrl!} target="_blank" rel="noopener noreferrer">
                      <ExternalLink className="mr-1 size-3" />
                      فتح
                    </a>
                  </Button>
                  <Button variant="outline" size="sm" className="h-7 flex-1 text-xs" onClick={copyMapsLink}>
                    <Copy className="mr-1 size-3" />
                    نسخ
                  </Button>
                </div>
              </div>
            ) : hasMapsData ? (
              <div className="flex flex-col gap-2">
                <div className="flex aspect-video items-center justify-center rounded-md border bg-muted/30">
                  <div className="flex flex-col items-center gap-1.5 text-muted-foreground">
                    <MapIcon className="size-5" />
                    <p className="text-[10px]">رابط URL فقط — لا يوجد موقع GPS</p>
                  </div>
                </div>
                <Button variant="outline" size="sm" className="h-7 w-full text-xs" asChild>
                  <a href={mapsUrl!} target="_blank" rel="noopener noreferrer">
                    <ExternalLink className="mr-1 size-3" />
                    Open Maps
                  </a>
                </Button>
              </div>
            ) : (
              <div className="flex aspect-video items-center justify-center rounded-md border border-dashed bg-muted/20">
                <div className="flex flex-col items-center gap-1.5 text-muted-foreground">
                  <MapIcon className="size-5" />
                  <p className="text-[10px]">لا توجد بيانات موقع</p>
                </div>
              </div>
            )}
          </div>

        </div>
      </div>

      {/* ── 4. Notes (3 independent cards) ── */}
      {(cust?.notes || internalNoteContent || order.customer_note) ? (
        <div className="flex flex-col gap-3">
          <h3 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">الملاحظات</h3>
          {cust?.notes ? (
            <NoteCard
              icon={<User className="size-3.5" />}
              title="ملاحظات العميل"
              content={cust.notes}
            />
          ) : null}
          {internalNoteContent ? (
            <NoteCard
              icon={<Lock className="size-3.5" />}
              title="ملاحظات داخلية"
              content={internalNoteContent}
            />
          ) : null}
          {order.customer_note ? (
            <NoteCard
              icon={<StickyNote className="size-3.5" />}
              title="ملاحظات WooCommerce"
              content={order.customer_note}
            />
          ) : null}
        </div>
      ) : null}

      {/* ── Legacy billing (WooCommerce orders only) ── */}
      {hasLegacyBilling ? (
        <>
          <Separator />
          <div>
            <SectionTitle>{t('detail.billingInformation')}</SectionTitle>
            <DetailGrid cols={2}>
              {(order.billing_first_name || order.billing_last_name) ? (
                <DetailRow label={t('detail.billingName')}>
                  {[order.billing_first_name, order.billing_last_name].filter(Boolean).join(' ')}
                </DetailRow>
              ) : null}
              {order.billing_phone && order.billing_phone !== primaryPhone ? (
                <DetailRow label={t('detail.billingPhone')}>{order.billing_phone}</DetailRow>
              ) : null}
              {order.billing_email && order.billing_email !== email ? (
                <DetailRow label={t('detail.billingEmail')}>{order.billing_email}</DetailRow>
              ) : null}
              {order.billing_address_1 ? <DetailRow label={t('detail.billingAddress1')}>{order.billing_address_1}</DetailRow> : null}
              {order.billing_city      ? <DetailRow label={t('detail.billingCity')}>{order.billing_city}</DetailRow>           : null}
              {order.billing_country   ? <DetailRow label={t('detail.billingCountry')}>{order.billing_country}</DetailRow>     : null}
            </DetailGrid>
          </div>
        </>
      ) : null}

      {/* ── Legacy WooCommerce shipping address ── */}
      {hasLegacyShipping ? (
        <>
          <Separator />
          <div>
            <SectionTitle>{t('detail.shippingInformation')}</SectionTitle>
            <DetailGrid cols={2}>
              {(order.shipping_first_name || order.shipping_last_name) ? (
                <DetailRow label={t('detail.shippingName')}>
                  {[order.shipping_first_name, order.shipping_last_name].filter(Boolean).join(' ')}
                </DetailRow>
              ) : null}
              {order.shipping_company   ? <DetailRow label={t('detail.shippingCompany')}>{order.shipping_company}</DetailRow>   : null}
              {order.shipping_address_1 ? <DetailRow label={t('detail.shippingAddress1')}>{order.shipping_address_1}</DetailRow> : null}
              {order.shipping_city      ? <DetailRow label={t('detail.shippingCity')}>{order.shipping_city}</DetailRow>          : null}
              {order.shipping_country   ? <DetailRow label={t('detail.shippingCountry')}>{order.shipping_country}</DetailRow>    : null}
            </DetailGrid>
          </div>
        </>
      ) : null}

    </div>
  );
}

function ProductsTab({ order, t }: { order: Order; t: (k: string) => string }) {
  return (
    <div className="flex flex-col gap-0 p-4">
      {/* Price protection banner — prices are frozen at order creation */}
      <div className="mb-3 rounded-md border border-emerald-200 bg-emerald-50 dark:border-emerald-900/50 dark:bg-emerald-950/30 px-3 py-2 flex items-start gap-2">
        <Lock className="h-3 w-3 text-emerald-600 dark:text-emerald-400 mt-0.5 shrink-0" />
        <p className="text-[11px] text-emerald-700 dark:text-emerald-400 leading-relaxed">
          <span className="font-semibold">حماية أسعار الطلب مُفعَّلة —</span>{' '}
          جميع أسعار الوحدات أدناه مجمَّدة نهائياً عند لحظة إنشاء هذا الطلب.
          تغييرات أسعار الكتالوج لا تؤثر على هذا الطلب.
        </p>
      </div>

      {(order.lines ?? []).length === 0 ? (
        <p className="text-center text-sm text-muted-foreground py-8">{t('table.empty')}</p>
      ) : (
        <div className="flex flex-col divide-y">
          {(order.lines ?? []).map((line) => (
            <div key={line.id} className="flex items-center gap-3 py-3">
              {getMediaUrl(line.product?.image_url) ? (
                <img src={getMediaUrl(line.product!.image_url)!} alt={line.product!.name ?? ''} className="size-10 rounded-md object-cover ring-1 ring-border shrink-0" />
              ) : (
                <div className="flex size-10 items-center justify-center rounded-md bg-muted ring-1 ring-border shrink-0">
                  <Package className="size-4 text-muted-foreground" />
                </div>
              )}
              <div className="flex-1 min-w-0">
                <p className="text-sm font-medium truncate">{line.product?.name ?? '—'}</p>
                <p className="text-xs text-muted-foreground font-mono">{line.product?.sku}</p>
              </div>
              <div className="text-end shrink-0">
                <p className="text-sm font-medium tabular-nums">{fmtCur(line.line_total, true)}</p>
                <div className="flex items-center justify-end gap-1 mt-0.5">
                  <p className="text-xs text-muted-foreground">{line.quantity} × EGP {formatMoney(line.unit_price)}</p>
                  <span className="inline-flex items-center gap-0.5 text-[10px] text-emerald-600 dark:text-emerald-400 font-medium">
                    <Lock className="h-2.5 w-2.5" />
                    محمي
                  </span>
                </div>
              </div>
            </div>
          ))}
        </div>
      )}
      {(order.fees ?? []).length > 0 ? (
        <>
          <Separator />
          <div className="py-3">
            <SectionTitle>{t('detail.fees')}</SectionTitle>
            {(order.fees ?? []).map((f) => (
              <div key={f.id} className="flex justify-between text-sm py-1">
                <span className="text-muted-foreground">{f.name}</span>
                <span className="tabular-nums">{fmtCur(f.total, true)}</span>
              </div>
            ))}
          </div>
        </>
      ) : null}
      {(order.coupons ?? []).length > 0 ? (
        <>
          <Separator />
          <div className="py-3">
            <SectionTitle>{t('detail.coupons')}</SectionTitle>
            {(order.coupons ?? []).map((c) => (
              <div key={c.id} className="flex justify-between text-sm py-1">
                <span className="font-mono text-xs text-muted-foreground">{c.code}</span>
                <span className="tabular-nums text-emerald-600">−{fmtCur(c.discount, true)}</span>
              </div>
            ))}
          </div>
        </>
      ) : null}
    </div>
  );
}

function PaymentTab({ order, t: _t }: { order: Order; t: (k: string) => string }) {
  const paymentLabel = resolvePaymentLabel(order);

  // Derive payment status
  const isPaid     = Boolean(order.date_paid);
  const hasDeposit = order.deposit_paid > 0;
  const hasRemaining = order.remaining_balance > 0;
  const isPartial  = !isPaid && hasDeposit;

  const paymentStatusLabel = isPaid ? 'مدفوع' : isPartial ? 'مدفوع جزئياً' : 'غير مدفوع';
  const paymentStatusCls   = isPaid
    ? 'text-emerald-600 dark:text-emerald-400'
    : isPartial
      ? 'text-amber-600 dark:text-amber-400'
      : 'text-muted-foreground';

  const hasAnyPayment = Boolean(
    paymentLabel || order.date_paid || hasDeposit || hasRemaining ||
    order.discount_amount > 0 || order.transaction_id || order.payment_proof_path,
  );

  if (!hasAnyPayment) {
    return (
      <div className="flex flex-col items-center gap-3 py-12 text-center p-4">
        <p className="text-sm text-muted-foreground">لا توجد معلومات دفع متاحة.</p>
      </div>
    );
  }

  return (
    <div className="p-4 flex flex-col gap-6">

      {/* ── Payment Status ── */}
      <div>
        <SectionTitle>حالة الدفع</SectionTitle>
        <div className="rounded-md border bg-muted/20 px-4 py-3 flex items-center gap-3">
          {isPaid
            ? <ShieldCheck className="size-4 text-emerald-500 shrink-0" />
            : <div className="size-4 rounded-full border-2 border-muted-foreground shrink-0" />
          }
          <div className="flex-1 min-w-0">
            <p className={cn('text-sm font-semibold', paymentStatusCls)}>{paymentStatusLabel}</p>
            {isPaid && order.date_paid ? (
              <p className="text-xs text-muted-foreground">تم التحقق {formatDateTime(order.date_paid)}</p>
            ) : null}
          </div>
        </div>
      </div>

      {/* ── Payment Details ── */}
      <div>
        <SectionTitle>تفاصيل الدفع</SectionTitle>
        <DetailGrid cols={1}>
          {paymentLabel ? (
            <DetailRow label="طريقة الدفع">
              <span className="font-medium">{paymentLabel}</span>
            </DetailRow>
          ) : null}
          {order.transaction_id ? (
            <DetailRow label="رقم المعاملة">
              <span className="font-mono text-xs">{order.transaction_id}</span>
            </DetailRow>
          ) : null}
          <DetailRow label="حالة التحقق">
            <span className={cn('font-medium', isPaid ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400')}>
              {isPaid ? 'تم التحقق' : 'بانتظار التحقق'}
            </span>
          </DetailRow>
          {order.date_paid ? (
            <DetailRow label="تاريخ التحقق">{formatDateTime(order.date_paid)}</DetailRow>
          ) : null}
        </DetailGrid>
      </div>

      <Separator />

      {/* ── Financial Details — canonical fields only, no legacy calculations ── */}
      <div>
        <SectionTitle>التفاصيل المالية</SectionTitle>
        <div className="flex flex-col gap-2">
          <FinancialRow label="إجمالي المنتجات" value={order.products_total} allowZero />
          <FinancialRow label="الشحن"            value={order.shipping_amount} />
          {order.discount_amount > 0.005 && (
            <FinancialRow
              label={order.discount_percentage != null ? `الخصم (${order.discount_percentage}%)` : 'الخصم'}
              value={order.discount_amount}
              isDiscount
            />
          )}
          <FinancialRow
            label="الضريبة"
            value={order.tax_amount > 0.005 ? order.tax_amount : 'not_applicable'}
          />
          <Separator />
          <FinancialRow label="الإجمالي الكلي" value={order.grand_total} allowZero bold />
          {hasDeposit && (
            <div className="flex items-baseline justify-between gap-4">
              <span className="text-sm text-muted-foreground">دفعة مقدمة</span>
              <span className="text-sm font-semibold tabular-nums text-sky-600 dark:text-sky-400">
                {fmtCur(order.deposit_paid)}
              </span>
            </div>
          )}
          {order.remaining_balance > 0.005 && (
            <div className="flex items-baseline justify-between gap-4">
              <span className="text-sm text-muted-foreground">الرصيد المتبقي</span>
              <span className="text-sm font-semibold tabular-nums text-amber-600 dark:text-amber-400">
                {fmtCur(order.remaining_balance)}
              </span>
            </div>
          )}
        </div>
      </div>

      <Separator />

      {/* ── Payment Proof ── */}
      <div>
        <SectionTitle>إثبات الدفع</SectionTitle>
        {order.payment_proof_path ? (
          <MediaViewer
            path={order.payment_proof_path}
            title="إثبات الدفع"
            trigger={
              <button
                type="button"
                className="inline-flex items-center gap-1.5 text-sm text-primary hover:underline"
              >
                <Paperclip className="size-3.5" />
                إثبات الدفع
              </button>
            }
          />
        ) : (
          <p className="text-sm text-muted-foreground">لم يتم رفع إثبات الدفع.</p>
        )}
      </div>

      {/* ── Legacy reference (WooCommerce orders) ── */}
      {order.payment_method && order.payment_method !== order.payment_method_manual ? (
        <>
          <Separator />
          <div>
            <SectionTitle>مرجع WooCommerce</SectionTitle>
            <DetailGrid>
              <DetailRow label="كود البوابة">
                <span className="font-mono text-xs text-muted-foreground">{order.payment_method}</span>
              </DetailRow>
              {order.payment_method_title ? (
                <DetailRow label="اسم البوابة">{order.payment_method_title}</DetailRow>
              ) : null}
            </DetailGrid>
          </div>
        </>
      ) : null}
    </div>
  );
}

// ── Shipping Tab helpers ──────────────────────────────────────────────────────

function buildFullAddress(order: Order): string {
  const parts = [
    order.governorate,
    order.city,
    order.delivery_zone,
    order.shipping_address,
    order.building   ? `مبنى ${order.building}` : null,
    order.floor      ? `طابق ${order.floor}`     : null,
    order.apartment  ? `شقة ${order.apartment}`  : null,
    order.landmark,
  ].filter((v): v is string => Boolean(v));
  return parts.join(', ');
}

function VerificationBadge({
  label,
  verified,
  detail,
  icon: Icon,
  statusText,
  statusColor,
}: {
  label: string;
  verified: boolean;
  detail?: string | null;
  icon?: React.ComponentType<{ className?: string }>;
  statusText?: string;
  statusColor?: string;
}) {
  const resolvedStatus = statusText ?? (verified ? 'مؤكد' : 'غير مؤكد');
  const resolvedColor  = statusColor ?? (verified
    ? 'text-emerald-600 dark:text-emerald-400'
    : 'text-muted-foreground');

  return (
    <div className="flex items-center gap-2.5 rounded-md border px-3 py-2.5">
      {verified ? (
        <CheckCircle2 className="size-4 shrink-0 text-emerald-500" />
      ) : (
        Icon ? <Icon className="size-4 shrink-0 text-muted-foreground/50" /> : (
          <div className="size-4 rounded-full border-2 border-muted-foreground/40 shrink-0" />
        )
      )}
      <div className="min-w-0 flex-1">
        <p className="text-xs font-medium leading-tight">{label}</p>
        {detail ? (
          <p className="text-[10px] text-muted-foreground truncate">{detail}</p>
        ) : null}
        <p className={cn('text-[10px] font-semibold mt-0.5', resolvedColor)}>
          {resolvedStatus}
        </p>
      </div>
    </div>
  );
}

function ShippingTab({ order }: { order: Order; t: (k: string) => string }) {
  const fullAddress  = buildFullAddress(order);
  const loc          = order.location;
  const hasMapsData  = !!(loc || order.google_maps_url);
  const mapsUrl      = loc
    ? `https://www.google.com/maps?q=${loc.lat},${loc.lng}`
    : (order.google_maps_url ?? '');
  const coordsStr    = loc ? `${loc.lat},${loc.lng}` : '';

  const attemptsLabel = (order.shipping_attempts ?? 0) > 0
    ? `${order.shipping_attempts} محاولة توصيل`
    : null;

  return (
    <div className="flex flex-col gap-6 p-4">

      {/* ── 1. Delivery Address ── */}
      <div>
        <SectionTitle>عنوان التوصيل</SectionTitle>

        {/* 2-column grid: Location | Building Details */}
        <div className="grid grid-cols-2 gap-x-6 gap-y-0">

          {/* Column 1 — Location */}
          <div className="flex flex-col gap-1 mb-1">
            <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground flex items-center gap-1.5 mb-1">
              <MapPin className="size-3" />الموقع
            </p>
            <DetailRow label="المحافظة">{order.governorate}</DetailRow>
            <DetailRow label="المدينة">{order.city}</DetailRow>
            <DetailRow label="المنطقة">{order.delivery_zone}</DetailRow>
            <DetailRow label="الشارع">{order.shipping_address}</DetailRow>
          </div>

          {/* Column 2 — Building Details */}
          <div className="flex flex-col gap-1 mb-1">
            <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground flex items-center gap-1.5 mb-1">
              <Building2 className="size-3" />تفاصيل المبنى
            </p>
            <DetailRow label="المبنى">{order.building}</DetailRow>
            <DetailRow label="الطابق">{order.floor}</DetailRow>
            <DetailRow label="الشقة">{order.apartment}</DetailRow>
            <DetailRow label="علامة مميزة">{order.landmark}</DetailRow>
          </div>
        </div>

        {/* Column 3 — Address Summary */}
        <div className="mt-4 rounded-md border bg-muted/20 px-3 py-3">
          <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground mb-2">ملخص العنوان</p>
          <dl className="flex flex-col gap-2">
            <DetailRow label="العنوان الكامل">
              {fullAddress || null}
            </DetailRow>
            <DetailRow label="ملاحظات العنوان">
              {order.address_notes}
            </DetailRow>
            <DetailRow label="رابط خرائط جوجل">
              {order.google_maps_url ? (
                <a
                  href={order.google_maps_url}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="text-primary hover:underline text-xs break-all"
                >
                  {order.google_maps_url}
                </a>
              ) : null}
            </DetailRow>
          </dl>
        </div>

        {/* Column 4 — Map Card */}
        {loc?.lat && loc?.lng ? (
          <div className="mt-4 overflow-hidden rounded-lg border">
            <div className="flex items-start gap-2 bg-muted/20 px-3 py-2.5">
              <MapPin className="mt-0.5 size-3.5 shrink-0 text-primary" />
              <div className="min-w-0">
                <p className="font-mono text-xs font-medium tabular-nums">
                  {Number(loc.lat).toFixed(6)}, {Number(loc.lng).toFixed(6)}
                </p>
                <a
                  href={`https://www.google.com/maps?q=${loc.lat},${loc.lng}`}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="text-[10px] text-primary hover:underline"
                >
                  maps.google.com ↗
                </a>
              </div>
            </div>
            <div className="flex flex-wrap gap-2 p-2 border-t bg-muted/10">
              <Button variant="outline" size="sm" asChild>
                <a href={mapsUrl} target="_blank" rel="noopener noreferrer">
                  <MapPin className="size-3.5" />
                  فتح الخريطة
                </a>
              </Button>
              <Button variant="outline" size="sm" asChild>
                <a href={`https://www.waze.com/ul?ll=${loc.lat}%2C${loc.lng}&navigate=yes`} target="_blank" rel="noopener noreferrer">
                  <Navigation className="size-3.5" />
                  Waze
                </a>
              </Button>
              {loc.set_by ? (
                <span className="ms-auto self-center text-[10px] text-muted-foreground">
                  أُضيف بواسطة: {loc.set_by}
                </span>
              ) : null}
            </div>
          </div>
        ) : hasMapsData ? (
          <div className="mt-4 rounded-lg border border-dashed bg-muted/10 flex items-center justify-center gap-2 py-6 text-muted-foreground">
            <MapIcon className="size-5" />
            <span className="text-xs">لا توجد إحداثيات GPS — رابط الخريطة فقط</span>
          </div>
        ) : null}
      </div>

      <Separator />

      {/* ── 2. Delivery Schedule ── */}
      <div>
        <SectionTitle>موعد التوصيل</SectionTitle>
        <DetailGrid>
          <DetailRow label="التوصيل المطلوب">{formatDate(order.requested_delivery_date)}</DetailRow>
          <DetailRow label="نافذة التوصيل">{order.delivery_window}</DetailRow>
          <DetailRow label="الوقت المفضل">{order.preferred_delivery_time}</DetailRow>
          <DetailRow label="الوصول المتوقع">{null}</DetailRow>
          <DetailRow label="الأولوية">{null}</DetailRow>
          <DetailRow label="مستوى الخدمة">{null}</DetailRow>
        </DetailGrid>
      </div>

      <Separator />

      {/* ── 3. Shipping Assignment ── */}
      <div>
        <SectionTitle>تعيين الشحنة</SectionTitle>
        <DetailGrid>
          <DetailRow label="شركة الشحن">{order.shipping_company_name}</DetailRow>
          <DetailRow label="ناقل الشحن">{order.shipping_method}</DetailRow>
          <DetailRow label="السائق">{null}</DetailRow>
          <DetailRow label="المركبة">{null}</DetailRow>
          <DetailRow label="كود المركبة">{null}</DetailRow>
          <DetailRow label="المسار">{null}</DetailRow>
          <DetailRow label="الموجة">{null}</DetailRow>
          <DetailRow label="دفعة التحميل">{null}</DetailRow>
        </DetailGrid>
      </div>

      <Separator />

      {/* ── 4. Tracking ── */}
      <div>
        <SectionTitle>التتبع</SectionTitle>
        <DetailGrid>
          <DetailRow label="رقم التتبع">
            {order.tracking_number
              ? <span className="font-mono text-xs">{order.tracking_number}</span>
              : null}
          </DetailRow>
          <DetailRow label="رابط التتبع">{null}</DetailRow>
          <DetailRow label="حالة الشحنة">
            {attemptsLabel}
          </DetailRow>
        </DetailGrid>
      </div>

      <Separator />

      {/* ── 5. Delivery Verification ── */}
      <div>
        <SectionTitle>التحقق من التوصيل</SectionTitle>
        <div className="grid grid-cols-2 gap-2">
          <VerificationBadge
            label="الموقع محدد"
            icon={MapPin}
            verified={hasMapsData}
          />
          <VerificationBadge
            label="العنوان مكتمل"
            icon={Building2}
            verified={!!(order.governorate && order.city)}
          />
          <VerificationBadge
            label="الهاتف مسجل"
            icon={Phone}
            verified={!!order.billing_phone}
            detail={order.billing_phone}
          />
          <VerificationBadge
            label="تأكيد العميل"
            icon={UserCheck}
            verified={order.confirmation_result === 'confirmed'}
            detail={
              order.confirmation_result === 'confirmed' && order.customer_confirmed_at
                ? formatDate(order.customer_confirmed_at)
                : order.confirmation_result && order.confirmation_result !== 'confirmed'
                ? (order.customer_confirmed_by ? `بواسطة: ${order.customer_confirmed_by}` : null)
                : null
            }
            statusText={
              order.confirmation_result === 'confirmed'   ? 'مؤكد'        :
              order.confirmation_result === 'not_answered'? 'لم يُجب'     :
              order.confirmation_result === 'rejected'    ? 'مرفوض'       :
              order.confirmation_result === 'postponed'   ? 'مؤجل'        :
              'قيد الانتظار'
            }
            statusColor={
              order.confirmation_result === 'confirmed'   ? 'text-emerald-600 dark:text-emerald-400' :
              order.confirmation_result === 'rejected'    ? 'text-red-600 dark:text-red-400'         :
              order.confirmation_result === 'not_answered'? 'text-amber-600 dark:text-amber-400'     :
              order.confirmation_result === 'postponed'   ? 'text-blue-600 dark:text-blue-400'       :
              'text-muted-foreground'
            }
          />
        </div>
      </div>

      <Separator />

      {/* ── 6. Shipping Actions ── */}
      <div>
        <SectionTitle>إجراءات الشحن</SectionTitle>
        <div className="flex flex-wrap gap-2">
          {hasMapsData && (
            <Button variant="outline" size="sm" asChild>
              <a href={mapsUrl} target="_blank" rel="noopener noreferrer">
                <MapPin className="size-3.5" />
                فتح الخريطة
              </a>
            </Button>
          )}
          {fullAddress && (
            <Button
              variant="outline"
              size="sm"
              onClick={() => void navigator.clipboard.writeText(fullAddress)}
            >
              <Copy className="size-3.5" />
              نسخ العنوان
            </Button>
          )}
          {coordsStr && (
            <Button
              variant="outline"
              size="sm"
              onClick={() => void navigator.clipboard.writeText(coordsStr)}
            >
              <Navigation className="size-3.5" />
              نسخ الإحداثيات
            </Button>
          )}
          {order.tracking_number ? (
            <Button variant="outline" size="sm" disabled>
              <ExternalLink className="size-3.5" />
              تتبع الشحنة
            </Button>
          ) : null}
        </div>
      </div>
    </div>
  );
}


/**
 * DD-016 — Single location tab. Shows an interactive map frame when coordinates exist.
 */
function LocationTab({ order, t }: { order: Order; t: (k: string, opts?: Record<string, unknown>) => string }) {
  const loc = order.location;

  return (
    <div className="flex flex-col gap-4 p-4">
      {loc?.lat && loc?.lng ? (
        <>
          {/* GPS coordinates card */}
          <div className="flex items-start gap-3 overflow-hidden rounded-lg border bg-muted/20 px-4 py-3">
            <MapPin className="mt-0.5 size-4 shrink-0 text-primary" />
            <div className="min-w-0">
              <p className="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">موقع GPS</p>
              <p className="font-mono text-sm font-medium tabular-nums">
                {Number(loc.lat).toFixed(6)}, {Number(loc.lng).toFixed(6)}
              </p>
              <a
                href={`https://www.google.com/maps?q=${loc.lat},${loc.lng}`}
                target="_blank"
                rel="noopener noreferrer"
                className="text-[10px] text-primary hover:underline"
              >
                maps.google.com ↗
              </a>
            </div>
          </div>
          {/* Location actions */}
          <div className="flex flex-wrap gap-2">
            <Button variant="outline" size="sm" asChild>
              <a
                href={`https://www.google.com/maps?q=${loc.lat},${loc.lng}`}
                target="_blank"
                rel="noopener noreferrer"
              >
                <Navigation className="size-3.5" />
                {t('address.openMaps')}
              </a>
            </Button>
            <Button variant="outline" size="sm" asChild>
              <a
                href={`https://www.waze.com/ul?ll=${loc.lat}%2C${loc.lng}&navigate=yes`}
                target="_blank"
                rel="noopener noreferrer"
              >
                <Navigation className="size-3.5" />
                Waze
              </a>
            </Button>
            <Button
              variant="outline"
              size="sm"
              onClick={() => void navigator.clipboard.writeText(`https://www.google.com/maps?q=${loc.lat},${loc.lng}`)}
            >
              <MapPin className="size-3.5" />
              {t('address.copyLink')}
            </Button>
          </div>
          {loc.set_by ? (
            <p className="text-xs text-muted-foreground">
              {t('drawer.locationSetBy', { by: loc.set_by })}
            </p>
          ) : null}
        </>
      ) : (
        <div className="flex flex-col items-center gap-3 py-12 text-center">
          <MapPin className="size-8 text-muted-foreground" />
          <p className="text-sm text-muted-foreground">{t('drawer.noLocation')}</p>
        </div>
      )}
    </div>
  );
}

// ── Workflow Tab ──────────────────────────────────────────────────────────────

type WorkflowAction = {
  key: string;
  label: string;
  icon: React.ComponentType<{ className?: string }>;
  variant: 'default' | 'outline' | 'destructive';
};

const WORKFLOW_ACTIONS: Record<string, WorkflowAction[]> = {
  pending: [
    { key: 'confirm',          label: 'تأكيد الطلب',              icon: CheckCircle2,     variant: 'default'     },
    { key: 'cancel',           label: 'إلغاء الطلب',              icon: XCircle,          variant: 'destructive' },
  ],
  awaiting_payment: [
    { key: 'confirm',          label: 'تأكيد الطلب',              icon: CheckCircle2,     variant: 'default'     },
    { key: 'cancel',           label: 'إلغاء الطلب',              icon: XCircle,          variant: 'destructive' },
  ],
  processing: [
    { key: 'prepare',          label: 'نقل إلى التحضير',          icon: ArrowRightCircle, variant: 'default'     },
    { key: 'awaiting_stock',   label: 'تعليق: بانتظار المخزون',  icon: Box,              variant: 'outline'     },
    { key: 'review',           label: 'إرسال للمراجعة',           icon: Activity,         variant: 'outline'     },
    { key: 'cancel',           label: 'إلغاء الطلب',              icon: XCircle,          variant: 'destructive' },
  ],
  awaiting_stock: [
    { key: 'resume',           label: 'استئناف المعالجة',         icon: ArrowRightCircle, variant: 'default'     },
    { key: 'cancel',           label: 'إلغاء الطلب',              icon: XCircle,          variant: 'destructive' },
  ],
  confirmed: [
    { key: 'prepare',          label: 'نقل إلى التحضير',          icon: ArrowRightCircle, variant: 'default'     },
    { key: 'reschedule',       label: 'إعادة جدولة',              icon: Clock,            variant: 'outline'     },
    { key: 'cancel',           label: 'إلغاء الطلب',              icon: XCircle,          variant: 'destructive' },
  ],
  preparing: [
    { key: 'dispatch',         label: 'إرسال الطلبات',            icon: Truck,            variant: 'default'     },
    { key: 'review',           label: 'إرسال للمراجعة',           icon: Activity,         variant: 'outline'     },
    { key: 'reschedule',       label: 'إعادة جدولة',              icon: Clock,            variant: 'outline'     },
    { key: 'cancel',           label: 'إلغاء الطلب',              icon: XCircle,          variant: 'destructive' },
  ],
  out_for_delivery: [
    { key: 'complete_delivery', label: 'تسليم الطلب',             icon: CheckCircle2,     variant: 'default'     },
    { key: 'return',            label: 'معالجة الإرجاع',          icon: RotateCcw,        variant: 'outline'     },
    { key: 'review',            label: 'إرسال للمراجعة',          icon: Activity,         variant: 'outline'     },
    { key: 'reschedule',        label: 'إعادة جدولة',             icon: Clock,            variant: 'outline'     },
  ],
  delivered: [
    { key: 'complete',          label: 'إتمام مراجعة الحسابات',   icon: CheckCircle2,     variant: 'default'     },
    { key: 'review',            label: 'إرسال للمراجعة',          icon: Activity,         variant: 'outline'     },
    { key: 'resume',            label: 'استئناف المعالجة',         icon: ArrowRightCircle, variant: 'outline'     },
    { key: 'resume_confirmed',  label: 'استئناف: مؤكد',           icon: ArrowRightCircle, variant: 'outline'     },
    { key: 'reschedule',        label: 'إعادة جدولة',             icon: Clock,            variant: 'outline'     },
    { key: 'cancel',            label: 'إلغاء الطلب',             icon: XCircle,          variant: 'destructive' },
  ],
  returned: [
    { key: 'return_to_confirmed', label: 'إعادة إلى مؤكد',       icon: RotateCcw,        variant: 'default'     },
    { key: 'review',              label: 'نقل إلى المراجعة',      icon: Activity,         variant: 'outline'     },
    { key: 'cancel',              label: 'إلغاء الطلب',           icon: XCircle,          variant: 'destructive' },
  ],
  review: [
    { key: 'resume',             label: 'استئناف المعالجة',        icon: ArrowRightCircle, variant: 'default'     },
    { key: 'reschedule',         label: 'إعادة جدولة',             icon: Clock,            variant: 'outline'     },
    { key: 'cancel',             label: 'إلغاء الطلب',             icon: XCircle,          variant: 'destructive' },
  ],
  rescheduled: [
    { key: 'resume',             label: 'استئناف المعالجة',        icon: ArrowRightCircle, variant: 'default'     },
    { key: 'reschedule',         label: 'إعادة جدولة',             icon: Clock,            variant: 'outline'     },
    { key: 'cancel',             label: 'إلغاء الطلب',             icon: XCircle,          variant: 'destructive' },
  ],
  completed: [],
  cancelled: [],
};

function WorkflowTab({ order, onClose }: { order: Order; onClose: () => void }) {
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
  const [showRescheduleForm, setShowRescheduleForm] = useState(false);
  const [rescheduleDate, setRescheduleDate] = useState(today);

  const actions = WORKFLOW_ACTIONS[order.status] ?? [];
  const isPending = [
    confirm, moveToPrep, completeDeliv, completeOrder, processReturn,
    cancelOrder, moveToReview, resume, dispatch, reschedule,
    markAwaitingStock, resumeConfirmed, returnConfirmed,
  ].some((m) => m.isPending);

  function handleAction(key: string) {
    switch (key) {
      case 'confirm':           confirm.mutate(order.id, { onSuccess: onClose }); break;
      case 'prepare':           moveToPrep.mutate(order.id, { onSuccess: onClose }); break;
      case 'complete_delivery': completeDeliv.mutate(order.id, { onSuccess: onClose }); break;
      case 'complete':          completeOrder.mutate(order.id, { onSuccess: onClose }); break;
      case 'return':            processReturn.mutate({ id: order.id }, { onSuccess: onClose }); break;
      case 'cancel':            cancelOrder.mutate({ id: order.id }, { onSuccess: onClose }); break;
      case 'review':            moveToReview.mutate({ id: order.id }, { onSuccess: onClose }); break;
      case 'resume':            resume.mutate(order.id, { onSuccess: onClose }); break;
      case 'dispatch':          dispatch.mutate(order.id, { onSuccess: onClose }); break;
      case 'awaiting_stock':    markAwaitingStock.mutate({ id: order.id }, { onSuccess: onClose }); break;
      case 'resume_confirmed':  resumeConfirmed.mutate(order.id, { onSuccess: onClose }); break;
      case 'return_to_confirmed': returnConfirmed.mutate(order.id, { onSuccess: onClose }); break;
      case 'reschedule':        setShowRescheduleForm(true); break;
    }
  }

  function handleRescheduleConfirm() {
    if (!rescheduleDate) return;
    reschedule.mutate(
      { id: order.id, nextDeliveryDate: rescheduleDate },
      { onSuccess: () => { setShowRescheduleForm(false); onClose(); } },
    );
  }

  return (
    <div className="flex flex-col gap-6 p-4">
      <div>
        <SectionTitle>الحالة الحالية</SectionTitle>
        <OrderStatusBadge status={order.status} />
      </div>
      {actions.length > 0 ? (
        <div>
          <SectionTitle>الإجراءات المتاحة</SectionTitle>
          <div className="flex flex-col gap-2">
            {actions.map((action) => {
              if (action.key === 'reschedule' && showRescheduleForm) {
                return (
                  <div key="reschedule-form" className="flex flex-col gap-2 rounded-md border p-3">
                    <label className="text-xs font-medium text-muted-foreground">تاريخ التوصيل الجديد</label>
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
                        تأكيد إعادة الجدولة
                      </Button>
                      <Button size="sm" variant="ghost" onClick={() => setShowRescheduleForm(false)}>
                        إلغاء
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
                  <action.icon className="size-4" />
                  {action.label}
                </Button>
              );
            })}
          </div>
        </div>
      ) : (
        <p className="text-sm text-muted-foreground">لا توجد إجراءات متاحة لهذه الحالة.</p>
      )}
    </div>
  );
}

// ── Inventory Tab ─────────────────────────────────────────────────────────────

function InventoryTab({ order }: { order: Order }) {
  const inv = order as Order & {
    inventory_reserved_at?: string | null;
    inventory_shipped_at?: string | null;
    assigned_warehouse_id?: string | null;
  };

  return (
    <div className="flex flex-col gap-6 p-4">
      <div>
        <SectionTitle>حالة الحجز</SectionTitle>
        <DetailGrid>
          <DetailRow label="تاريخ الحجز">
            {inv.inventory_reserved_at ? formatDate(inv.inventory_reserved_at) : (
              <span className="text-amber-600 text-sm font-medium">غير محجوز</span>
            )}
          </DetailRow>
          <DetailRow label="تاريخ الشحن">
            {inv.inventory_shipped_at ? formatDate(inv.inventory_shipped_at) : (
              <span className="text-muted-foreground text-sm">—</span>
            )}
          </DetailRow>
        </DetailGrid>
      </div>
      <Separator />
      <div>
        <SectionTitle>التنفيذ</SectionTitle>
        <DetailGrid>
          <DetailRow label="المستودع المعيّن">
            {inv.assigned_warehouse_id ?? '—'}
          </DetailRow>
          <DetailRow label="البنود">
            {(order.lines ?? []).length} بند
          </DetailRow>
        </DetailGrid>
      </div>
      <Separator />
      <div>
        <SectionTitle>بنود المخزون</SectionTitle>
        <div className="flex flex-col gap-2">
          {(order.lines ?? []).map((line) => (
            <div key={line.id} className="flex items-center gap-3 rounded-md border bg-muted/20 px-3 py-2 text-sm">
              <Box className="size-4 shrink-0 text-muted-foreground" />
              <div className="flex-1 min-w-0">
                <p className="truncate font-medium">{line.product?.name ?? line.product_id}</p>
                <p className="font-mono text-xs text-muted-foreground">{line.product?.sku}</p>
              </div>
              <span className="shrink-0 tabular-nums font-medium">×{line.quantity}</span>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}

// ── Timeline Tab ──────────────────────────────────────────────────────────────

type TColor = 'primary' | 'green' | 'amber' | 'blue' | 'cyan' | 'muted' | 'red';

const STATUS_LABELS: Record<string, string> = {
  pending: 'قيد الانتظار', awaiting_payment: 'بانتظار الدفع',
  processing: 'قيد المعالجة', awaiting_stock: 'بانتظار المخزون',
  confirmed: 'مؤكد', preparing: 'قيد التحضير',
  out_for_delivery: 'خرج للتوصيل', delivered: 'تم التسليم',
  completed: 'مكتمل', cancelled: 'ملغى',
  review: 'قيد المراجعة', rescheduled: 'معاد جدولته', returned: 'مُعاد',
};

const ADDRESS_FIELD_LABELS: Record<string, string> = {
  governorate: 'المحافظة', city: 'المدينة', shipping_address: 'الشارع',
  building: 'المبنى', floor: 'الطابق', apartment: 'الشقة',
  landmark: 'علامة مميزة', area: 'المنطقة', address_notes: 'ملاحظات العنوان',
  billing_phone: 'هاتف العميل', customer_secondary_phone: 'هاتف ثانوي',
  customer_name: 'اسم العميل',
};

// ── Helpers ───────────────────────────────────────────────────────────────────

function fmtRelative(d: Date): string {
  const diff = Math.floor((Date.now() - d.getTime()) / 1000);
  if (diff < 60)   return 'الآن';
  if (diff < 3600) return `منذ ${Math.floor(diff / 60)} د`;
  if (diff < 86400) return `منذ ${Math.floor(diff / 3600)} س`;
  return '';
}

function fmtDayLabel(d: Date): string {
  const today = new Date();
  if (d.toDateString() === today.toDateString()) return 'اليوم';
  const yesterday = new Date(today);
  yesterday.setDate(today.getDate() - 1);
  if (d.toDateString() === yesterday.toDateString()) return 'أمس';
  return new Intl.DateTimeFormat('ar-EG', { month: 'short', day: 'numeric', year: 'numeric' }).format(d);
}

type DayGroup = { label: string; events: OrderActivity[] };

function groupByDay(events: OrderActivity[]): DayGroup[] {
  const map = new Map<string, DayGroup>();
  for (const ev of events) {
    const d = new Date(ev.created_at);
    const key = d.toDateString();
    if (!map.has(key)) map.set(key, { label: fmtDayLabel(d), events: [] });
    map.get(key)!.events.push(ev);
  }
  return Array.from(map.values());
}

// ── Event classification ──────────────────────────────────────────────────────

function resolveEventMeta(ev: OrderActivity): { title: string; color: TColor; icon: React.ReactNode } {
  const p     = (ev.payload  ?? {}) as Record<string, unknown>;
  const field = (p.field as string | undefined)
    ?? (ev.previous_value ? Object.keys(ev.previous_value)[0] : undefined);

  switch (ev.event_type) {
    case 'order_created':    return { title: 'إنشاء الطلب',             color: 'primary', icon: <ShoppingBag className="size-3.5" /> };
    case 'order_updated':    return { title: 'تحديث الطلب',             color: 'blue',    icon: <Edit className="size-3.5" /> };
    case 'customer_confirmed': return { title: 'تأكيد العميل',          color: 'green',   icon: <UserCheck className="size-3.5" /> };
    case 'customer_created': return { title: 'إنشاء عميل',              color: 'cyan',    icon: <UserPlus className="size-3.5" /> };
    case 'customer_reused':  return { title: 'مطابقة عميل',             color: 'cyan',    icon: <User className="size-3.5" /> };
    case 'discount_applied': return { title: 'تطبيق الخصم',             color: 'amber',   icon: <Percent className="size-3.5" /> };
    case 'discount_updated': return { title: 'تحديث الخصم',             color: 'amber',   icon: <Percent className="size-3.5" /> };
    case 'deposit_recorded': return { title: 'تسجيل دفعة مقدمة',        color: 'cyan',    icon: <Banknote className="size-3.5" /> };
    case 'deposit_updated':  return { title: 'تحديث الدفعة المقدمة',    color: 'cyan',    icon: <Banknote className="size-3.5" /> };
    case 'note_added':       return { title: 'إضافة ملاحظة داخلية',     color: 'primary', icon: <MessageSquare className="size-3.5" /> };
    case 'note_updated':     return { title: 'تعديل ملاحظة داخلية',     color: 'primary', icon: <PenLine className="size-3.5" /> };
    case 'note_deleted':     return { title: 'حذف ملاحظة داخلية',       color: 'muted',   icon: <Trash2 className="size-3.5" /> };
    case 'proof_uploaded':   return { title: 'رفع إثبات الدفع',         color: 'green',   icon: <FileCheck className="size-3.5" /> };
    case 'awaiting_payment': return { title: 'بانتظار الدفع',            color: 'amber',   icon: <Clock className="size-3.5" /> };
    case 'delivery_date_set': return { title: 'تحديد تاريخ التوصيل',    color: 'blue',    icon: <CalendarClock className="size-3.5" /> };
    case 'shipping_override': return { title: 'تعديل تكلفة الشحن',      color: 'amber',   icon: <Truck className="size-3.5" /> };
    case 'order_zone_updated': return { title: 'تحديث المنطقة',          color: 'blue',    icon: <MapIcon className="size-3.5" /> };
    case 'location_set':     return { title: 'تحديد الموقع',             color: 'blue',    icon: <Navigation className="size-3.5" /> };
    case 'status_changed':   return { title: 'تغيير الحالة',             color: 'blue',    icon: <Activity className="size-3.5" /> };
    case 'field_updated': {
      if (field === 'status')
        return { title: 'تغيير الحالة', color: 'blue', icon: <Activity className="size-3.5" /> };
      if (field && field in ADDRESS_FIELD_LABELS && !['billing_phone','customer_secondary_phone'].includes(field))
        return { title: 'تحديث العنوان', color: 'blue', icon: <MapPin className="size-3.5" /> };
      if (field && ['billing_phone','customer_secondary_phone'].includes(field))
        return { title: 'تحديث الهاتف', color: 'blue', icon: <Phone className="size-3.5" /> };
      return { title: 'تحديث البيانات', color: 'muted', icon: <Edit className="size-3.5" /> };
    }
    default:
      if (ev.event_type.includes('cancel') || ev.event_type.includes('return'))
        return { title: ev.description, color: 'amber', icon: <XCircle className="size-3.5" /> };
      if (ev.event_type.includes('complet') || ev.event_type.includes('confirm'))
        return { title: ev.description, color: 'green', icon: <CheckCircle2 className="size-3.5" /> };
      return { title: ev.description, color: 'muted', icon: <Activity className="size-3.5" /> };
  }
}

// ── Primitive display components ──────────────────────────────────────────────


/** Vertical card diff — per-field card matching spec layout. */
function FieldChange({ label, oldVal, newVal }: { label: string; oldVal: string; newVal: string }) {
  return (
    <div className="rounded-md border border-border bg-muted/20 p-2.5 text-xs">
      <p className="font-semibold text-foreground mb-2">{label}</p>
      <div className="space-y-1.5">
        <div>
          <p className="text-[10px] font-medium text-muted-foreground uppercase tracking-wide mb-0.5">القديم</p>
          <p className={cn('font-mono break-all', oldVal ? 'line-through text-rose-600 dark:text-rose-400' : 'text-muted-foreground italic')}>{oldVal || '—'}</p>
        </div>
        <div className="flex justify-center">
          <ArrowDown className="size-3 text-muted-foreground" />
        </div>
        <div>
          <p className="text-[10px] font-medium text-muted-foreground uppercase tracking-wide mb-0.5">الجديد</p>
          <p className={cn('font-mono break-all font-medium', newVal ? 'text-emerald-600 dark:text-emerald-400' : 'text-muted-foreground italic')}>{newVal || '—'}</p>
        </div>
      </div>
    </div>
  );
}

/** Status transition card — for workflow/status events. */
function StatusTransitionCard({
  oldStatus, newStatus, byName, reason,
}: {
  oldStatus: string; newStatus: string; byName?: string | null; reason?: string | null;
}) {
  const STATUS_COLOR: Record<string, string> = {
    pending: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
    awaiting_payment: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
    processing: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
    awaiting_stock: 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300',
    confirmed: 'bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-300',
    preparing: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300',
    out_for_delivery: 'bg-cyan-100 text-cyan-700 dark:bg-cyan-900/40 dark:text-cyan-300',
    delivered: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
    completed: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
    cancelled: 'bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-300',
    review: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300',
    rescheduled: 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300',
    returned: 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300',
  };

  const badgeCls = (s: string) => cn(
    'inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold',
    STATUS_COLOR[s] ?? 'bg-muted text-muted-foreground',
  );

  return (
    <div className="rounded-md border border-border bg-muted/20 p-3 text-xs space-y-2.5">
      <div className="space-y-1.5">
        <div>
          <p className="text-[10px] font-medium text-muted-foreground uppercase tracking-wide mb-1">الحالة السابقة</p>
          <span className={badgeCls(oldStatus)}>{STATUS_LABELS[oldStatus] ?? oldStatus}</span>
        </div>
        <div className="flex justify-center">
          <ArrowDown className="size-3 text-muted-foreground" />
        </div>
        <div>
          <p className="text-[10px] font-medium text-muted-foreground uppercase tracking-wide mb-1">الحالة الجديدة</p>
          <span className={badgeCls(newStatus)}>{STATUS_LABELS[newStatus] ?? newStatus}</span>
        </div>
      </div>
      {(byName || reason) ? (
        <div className="border-t border-border pt-2 space-y-1">
          {byName  ? <p className="text-muted-foreground">بواسطة <span className="font-medium text-foreground">{byName}</span></p> : null}
          {reason  ? <p className="text-muted-foreground">السبب <span className="font-medium text-foreground">{reason}</span></p> : null}
        </div>
      ) : null}
    </div>
  );
}

// ── Per-event structured details ──────────────────────────────────────────────

function EventDetails({ ev }: { ev: OrderActivity }) {
  const meta = (ev.metadata      ?? {}) as Record<string, unknown>;
  const pl   = (ev.payload       ?? {}) as Record<string, unknown>;
  const prev = ev.previous_value as Record<string, unknown> | null;
  const next = ev.new_value      as Record<string, unknown> | null;

  switch (ev.event_type) {
    case 'order_created':
      return (
        <div className="flex flex-col gap-0.5 text-xs text-muted-foreground">
          {meta.channel       ? <span>القناة: <span className="font-medium text-foreground">{String(meta.channel)}</span></span> : null}
          {meta.customer_name ? <span>العميل: <span className="font-medium text-foreground">{String(meta.customer_name)}</span></span> : null}
          {meta.order_total != null ? <span>الإجمالي: <span className="font-medium text-foreground">{fmtCur(Number(meta.order_total))}</span></span> : null}
        </div>
      );

    case 'customer_confirmed': {
      const method = String(meta.method ?? pl.communication_method ?? '');
      const result = String(meta.result ?? pl.result ?? '');
      const notes  = String(meta.notes  ?? pl.notes  ?? '');
      return (
        <div className="rounded-md border border-border bg-muted/20 p-2.5 text-xs space-y-1">
          {method ? <p className="text-muted-foreground">الطريقة <span className="font-medium text-foreground capitalize">{method}</span></p> : null}
          {result ? (
            <p className="text-muted-foreground">النتيجة{' '}
              <span className={cn('font-semibold', result === 'confirmed' ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400')}>
                {result}
              </span>
            </p>
          ) : null}
          {notes ? <p className="text-muted-foreground italic border-t border-border pt-1 mt-1">{notes}</p> : null}
        </div>
      );
    }

    case 'discount_applied':
    case 'discount_updated': {
      const type_  = String(meta.type ?? pl.type ?? next?.discount_type ?? '');
      const amount = meta.amount ?? pl.amount ?? next?.discount_amount;
      const calcVal = meta.calculated_value ?? pl.calculated_value;

      if (prev?.discount_amount !== undefined && next?.discount_amount !== undefined) {
        const fmt_ = (a: unknown, t: unknown) => String(t) === 'percentage' ? `${a}%` : fmtCur(Number(a ?? 0));
        return (
          <div className="space-y-2">
            <FieldChange
              label="الخصم"
              oldVal={fmt_(prev.discount_amount, prev.discount_type)}
              newVal={fmt_(next.discount_amount, next.discount_type)}
            />
          </div>
        );
      }

      return (
        <div className="rounded-md border border-border bg-muted/20 p-2.5 text-xs space-y-1">
          {type_  ? <p className="text-muted-foreground">النوع <span className="font-medium text-foreground capitalize">{type_}</span></p> : null}
          {amount != null ? (
            <p className="text-muted-foreground">
              القيمة <span className="font-medium text-foreground">{type_ === 'percentage' ? `${amount}%` : fmtCur(Number(amount))}</span>
            </p>
          ) : null}
          {calcVal != null ? (
            <p className="text-muted-foreground border-t border-border pt-1 mt-1">
              المحسوب <span className="font-semibold text-amber-600 dark:text-amber-400">{fmtCur(Number(calcVal))}</span>
            </p>
          ) : null}
        </div>
      );
    }

    case 'deposit_recorded':
    case 'deposit_updated': {
      const prevDep = prev?.deposit_amount;
      const nextDep = next?.deposit_amount;
      const prevRem = prev?.remaining_balance;
      const nextRem = next?.remaining_balance;
      const grandTotal = meta.grand_total ?? pl.grand_total;

      if (prevDep !== undefined && nextDep !== undefined) {
        return (
          <div className="space-y-2">
            <FieldChange label="الدفعة المقدمة" oldVal={fmtCur(Number(prevDep))} newVal={fmtCur(Number(nextDep))} />
            {prevRem !== undefined && nextRem !== undefined
              ? <FieldChange label="الرصيد المتبقي" oldVal={fmtCur(Number(prevRem))} newVal={fmtCur(Number(nextRem))} />
              : null}
          </div>
        );
      }

      const amount = pl.amount ?? meta.amount;
      return (
        <div className="rounded-md border border-border bg-muted/20 p-2.5 text-xs space-y-1">
          {amount != null ? <p className="text-muted-foreground">الدفعة المقدمة <span className="font-medium text-foreground">{fmtCur(Number(amount))}</span></p> : null}
          {grandTotal != null ? <p className="text-muted-foreground">الإجمالي الكلي <span className="font-medium text-foreground">{fmtCur(Number(grandTotal))}</span></p> : null}
        </div>
      );
    }

    case 'note_added': {
      const content = String(meta.content ?? pl.preview ?? '');
      return content ? (
        <div className="rounded-md border border-border bg-muted/20 p-2.5 text-xs">
          <p className="text-[10px] font-medium text-muted-foreground uppercase tracking-wide mb-1">المحتوى</p>
          <p className="text-foreground whitespace-pre-wrap line-clamp-4">{content}</p>
        </div>
      ) : null;
    }

    case 'note_updated': {
      const oldContent = String(prev?.content ?? '');
      const newContent = String(next?.content ?? '');
      if (oldContent || newContent) {
        return (
          <div className="rounded-md border border-border bg-muted/20 p-2.5 text-xs space-y-2">
            {oldContent ? (
              <div>
                <p className="text-[10px] font-medium text-muted-foreground uppercase tracking-wide mb-1">السابق</p>
                <p className="text-muted-foreground line-through whitespace-pre-wrap line-clamp-3">{oldContent}</p>
              </div>
            ) : null}
            <div className="flex justify-center">
              <ArrowDown className="size-3 text-muted-foreground" />
            </div>
            <div>
              <p className="text-[10px] font-medium text-muted-foreground uppercase tracking-wide mb-1">الحالي</p>
              <p className="text-foreground whitespace-pre-wrap line-clamp-3">{newContent || '—'}</p>
            </div>
          </div>
        );
      }
      return null;
    }

    case 'note_deleted': {
      const preview = String(meta.content_preview ?? '');
      return preview ? (
        <div className="rounded-md border border-border bg-muted/20 p-2.5 text-xs">
          <p className="text-[10px] font-medium text-muted-foreground uppercase tracking-wide mb-1">المحتوى المحذوف</p>
          <p className="text-muted-foreground line-through whitespace-pre-wrap line-clamp-3">{preview}</p>
        </div>
      ) : null;
    }

    case 'status_changed': {
      const oldVal = String(prev?.status ?? pl.old_value ?? '');
      const newVal = String(next?.status ?? pl.new_value ?? '');
      return (
        <StatusTransitionCard
          oldStatus={oldVal}
          newStatus={newVal}
          byName={ev.actor_name}
          reason={ev.reason}
        />
      );
    }

    case 'field_updated': {
      const f      = String(pl.field ?? (prev ? Object.keys(prev)[0] : ''));
      const oldVal = String(prev?.[f] ?? pl.old_value ?? '');
      const newVal = String(next?.[f] ?? pl.new_value ?? '');
      if (f === 'status') {
        return (
          <StatusTransitionCard
            oldStatus={oldVal}
            newStatus={newVal}
            byName={ev.actor_name}
            reason={ev.reason}
          />
        );
      }
      return <FieldChange label={ADDRESS_FIELD_LABELS[f] ?? f.replace(/_/g, ' ')} oldVal={oldVal} newVal={newVal} />;
    }

    case 'order_updated':
      if (prev && next && Object.keys(next).length > 0) {
        return (
          <div className="flex flex-col gap-2">
            {Object.keys(next).map((f) => (
              <FieldChange
                key={f}
                label={ADDRESS_FIELD_LABELS[f] ?? f.replace(/_/g, ' ')}
                oldVal={String(prev[f] ?? '')}
                newVal={String(next[f] ?? '')}
              />
            ))}
          </div>
        );
      }
      return ev.changed_fields?.length ? (
        <p className="text-xs text-muted-foreground">تعديل: {ev.changed_fields.join(', ')}</p>
      ) : null;

    case 'order_zone_updated': {
      const prev_ = String(pl.previous_zone ?? '');
      const next_ = String(pl.new_zone ?? '');
      return prev_ || next_ ? <FieldChange label="منطقة التوصيل" oldVal={prev_} newVal={next_} /> : null;
    }

    case 'shipping_override':
      return pl.cost != null ? (
        <div className="rounded-md border border-border bg-muted/20 p-2.5 text-xs">
          <p className="text-muted-foreground">تكلفة مخصصة <span className="font-semibold text-foreground">{fmtCur(Number(pl.cost))}</span></p>
        </div>
      ) : null;

    default:
      return null;
  }
}

// ── Actor + Timestamp block ───────────────────────────────────────────────────

function ActorBlock({ ev }: { ev: OrderActivity }) {
  const d     = new Date(ev.created_at);
  const date  = new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', year: 'numeric' }).format(d);
  const time  = new Intl.DateTimeFormat(undefined, { hour: 'numeric', minute: '2-digit', second: '2-digit' }).format(d);
  const rel   = fmtRelative(d);

  const name   = ev.actor_name;
  const email  = ev.actor_email;
  const role   = ev.actor_role ?? (ev.metadata as Record<string, unknown> | null)?.actor_role as string | undefined;
  const branch = (ev.metadata as Record<string, unknown> | null)?.actor_branch as string | undefined;

  // Email local part as "username"
  const username = email ? email.split('@')[0] : null;

  return (
    <div className="mt-1.5 space-y-1 text-xs">
      {/* Identity */}
      <div className="grid grid-cols-[auto_1fr] gap-x-2 gap-y-0.5">
        {name     ? <><span className="text-muted-foreground">بواسطة</span><span className="font-medium text-foreground">{name}</span></> : null}
        {username ? <><span className="text-muted-foreground">المستخدم</span><span className="font-mono text-foreground">{username}</span></> : null}
        {role     ? <><span className="text-muted-foreground">الدور</span><span className="text-foreground">{role}</span></> : null}
        {branch   ? <><span className="text-muted-foreground">الفرع</span><span className="text-foreground">{branch}</span></> : null}
      </div>
      {/* Exact timestamp */}
      <div className="flex items-baseline gap-2 text-muted-foreground">
        <span className="tabular-nums">{date}</span>
        <span className="font-mono tabular-nums">{time}</span>
        {rel ? <span className="text-[10px] opacity-70">· {rel}</span> : null}
      </div>
    </div>
  );
}

// ── Timeline Tab ─────────────────────────────────────────────────────────────

function TimelineTab({ order }: { order: Order }) {
  const { data: activities, isLoading } = useOrderActivities(order.id);

  const DOT_CLS: Record<TColor, string> = {
    primary: 'border-primary bg-primary/10',
    green:   'border-emerald-500 bg-emerald-50 dark:bg-emerald-950/40',
    amber:   'border-amber-500 bg-amber-50 dark:bg-amber-950/40',
    blue:    'border-blue-500 bg-blue-50 dark:bg-blue-950/40',
    cyan:    'border-cyan-500 bg-cyan-50 dark:bg-cyan-950/40',
    muted:   'border-border bg-background',
    red:     'border-rose-500 bg-rose-50 dark:bg-rose-950/40',
  };
  const ICON_CLS: Record<TColor, string> = {
    primary: 'text-primary',
    green:   'text-emerald-600 dark:text-emerald-400',
    amber:   'text-amber-600 dark:text-amber-400',
    blue:    'text-blue-600 dark:text-blue-400',
    cyan:    'text-cyan-600 dark:text-cyan-400',
    muted:   'text-muted-foreground',
    red:     'text-rose-600 dark:text-rose-400',
  };

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <Loader2 className="size-5 animate-spin text-muted-foreground" />
      </div>
    );
  }

  const events: OrderActivity[] = activities ?? [];

  if (events.length === 0) {
    return (
      <div className="flex flex-col items-center gap-3 py-12 text-center p-4">
        <Activity className="size-8 text-muted-foreground" />
        <p className="text-sm text-muted-foreground">لا توجد أحداث مسجلة بعد.</p>
      </div>
    );
  }

  const groups = groupByDay(events);

  return (
    <div className="p-4 space-y-6">
      {groups.map((group) => (
        <div key={group.label}>
          {/* Day separator */}
          <div className="flex items-center gap-2 mb-3 sticky top-0 bg-background/90 backdrop-blur-sm py-1 z-10">
            <span className="text-xs font-semibold text-muted-foreground">{group.label}</span>
            <div className="flex-1 h-px bg-border" />
          </div>

          {/* Events in this day */}
          <div className="relative">
            <div className="absolute left-4 top-0 bottom-0 w-px bg-border" />
            <div className="flex flex-col gap-0">
              {group.events.map((ev) => {
                const { title, color, icon } = resolveEventMeta(ev);
                return (
                  <div key={ev.id} className="flex items-start gap-3 py-3 relative">
                    {/* Icon dot — event ID surfaced as tooltip for debug/support */}
                    <div
                      title={`Event ID: ${ev.id}`}
                      className={cn(
                        'relative z-10 flex size-8 shrink-0 items-center justify-center rounded-full border cursor-help',
                        DOT_CLS[color],
                      )}
                    >
                      <span className={ICON_CLS[color]}>{icon}</span>
                    </div>

                    {/* Content */}
                    <div className="min-w-0 flex-1 pt-0.5">
                      <p className="text-sm font-semibold leading-tight">{title}</p>
                      <ActorBlock ev={ev} />
                      <div className="mt-2">
                        <EventDetails ev={ev} />
                      </div>
                    </div>
                  </div>
                );
              })}
            </div>
          </div>
        </div>
      ))}
    </div>
  );
}

// ── Workflow History Tab ──────────────────────────────────────────────────────

function WorkflowHistoryTab({ order }: { order: Order }) {
  const typedOrder = order as Order & {
    status_entered_at?: string | null;
    status_entered_by?: string | null;
    previous_status?: string | null;
  };

  return (
    <div className="flex flex-col gap-6 p-4">
      {/* Current status */}
      <div>
        <SectionTitle>الحالة الحالية</SectionTitle>
        <div className="rounded-md border bg-muted/20 px-4 py-3 flex items-start gap-3">
          <div className="flex-1 min-w-0">
            <div className="flex items-center gap-2 mb-1">
              <OrderStatusBadge status={order.status} />
            </div>
            {typedOrder.status_entered_at ? (
              <p className="text-xs text-muted-foreground">
                دخل في:{' '}
                {new Intl.DateTimeFormat('ar-EG', { dateStyle: 'medium', timeStyle: 'short' }).format(
                  new Date(typedOrder.status_entered_at),
                )}
              </p>
            ) : null}
            {typedOrder.status_entered_by ? (
              <p className="text-xs text-muted-foreground">بواسطة: {typedOrder.status_entered_by}</p>
            ) : null}
          </div>
        </div>
      </div>

      {/* Previous status */}
      {typedOrder.previous_status ? (
        <div>
          <SectionTitle>الحالة السابقة</SectionTitle>
          <div className="rounded-md border px-4 py-3">
            <p className="text-sm font-medium capitalize text-muted-foreground">
              {STATUS_LABELS[String(typedOrder.previous_status)] ?? String(typedOrder.previous_status).replace(/_/g, ' ')}
            </p>
          </div>
        </div>
      ) : null}

      {/* Key dates */}
      <div>
        <SectionTitle>التواريخ الرئيسية</SectionTitle>
        <DetailGrid>
          <DetailRow label="تاريخ الإنشاء">
            {formatDate(order.created_at)}
          </DetailRow>
          {order.date_paid ? (
            <DetailRow label="تأكيد الدفع">
              {formatDate(order.date_paid)}
            </DetailRow>
          ) : null}
          {order.inventory_reserved_at ? (
            <DetailRow label="الحجز">
              {formatDate(order.inventory_reserved_at)}
            </DetailRow>
          ) : null}
          {order.inventory_shipped_at ? (
            <DetailRow label="الإرسال">
              {formatDate(order.inventory_shipped_at)}
            </DetailRow>
          ) : null}
          {order.requested_delivery_date ? (
            <DetailRow label="التوصيل المطلوب">
              {formatDate(order.requested_delivery_date)}
            </DetailRow>
          ) : null}
        </DetailGrid>
      </div>

      {/* Order source */}
      <div>
        <SectionTitle>مصدر الطلب</SectionTitle>
        <p className="text-sm capitalize">{order.source ?? 'يدوي'}</p>
      </div>

      <p className="text-xs text-muted-foreground border-t pt-3">
        سجل التدقيق الكامل متاح من خلال نقطة نهاية سجل النشاط. التواريخ الرئيسية معروضة أعلاه.
      </p>
    </div>
  );
}

// ── Main component ────────────────────────────────────────────────────────────

type OrderDetailDrawerProps = {
  order: Order | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onEdit?: (order: Order) => void;
};

export function OrderDetailDrawer({ order, open, onOpenChange, onEdit }: OrderDetailDrawerProps) {
  const { t } = useTranslation('orders');
  const [activeTab, setActiveTab] = useState('summary');

  // Fetch fresh detail data so canonical financial fields and full customer profile are always current.
  // Falls back to grid row data (order) until the request completes.
  const { data: detailOrder, isLoading: detailLoading } = useOrderQuery(order?.id ?? '');
  const displayOrder = detailOrder ?? order;

  if (!displayOrder) return null;

  const isEnriching = detailLoading && !detailOrder;

  const tabs = [
    { key: 'summary',   label: t('drawer.tabs.summary'),   content: <SummaryTab order={displayOrder} t={t} /> },
    { key: 'workflow',  label: 'سير العمل',                 content: <WorkflowTab order={displayOrder} onClose={() => onOpenChange(false)} /> },
    { key: 'history',   label: 'السجل',                    content: <WorkflowHistoryTab order={displayOrder} /> },
    { key: 'customer',  label: t('drawer.tabs.customer'),   content: <CustomerTab order={displayOrder} t={t} /> },
    { key: 'products',  label: t('drawer.tabs.products'),   content: <ProductsTab order={displayOrder} t={t} />, badge: (displayOrder.lines ?? []).length },
    { key: 'inventory', label: 'المخزون',                   content: <InventoryTab order={displayOrder} /> },
    { key: 'timeline',  label: 'الجدول الزمني',            content: <TimelineTab order={displayOrder} /> },
    { key: 'payment',   label: t('drawer.tabs.payment'),    content: <PaymentTab order={displayOrder} t={t} /> },
    { key: 'shipping',  label: t('drawer.tabs.shipping'),   content: <ShippingTab order={displayOrder} t={t} /> },
    { key: 'notes',     label: t('drawer.tabs.notes'),      content: <OrderNotesTab order={displayOrder} t={t} /> },
    { key: 'location',  label: t('drawer.tabs.location'),   content: <LocationTab order={displayOrder} t={t} /> },
  ];

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent
        side="right"
        className="flex w-full flex-col gap-0 p-0 sm:max-w-none sm:w-[48vw] sm:min-w-[480px] sm:max-w-[820px]"
      >
        {/* ── Header ── */}
        <SheetHeader className="border-b px-4 py-3">
          <div className="flex items-center gap-3">
            <div className="flex-1 min-w-0">
              <SheetTitle className="flex items-center gap-2 font-mono text-base">
                {displayOrder.order_number}
                <OrderStatusBadge status={displayOrder.status} />
              </SheetTitle>
              <p className="text-xs text-muted-foreground mt-0.5">
                {displayOrder.customer?.name ?? '—'} · {displayOrder.channel?.name ?? '—'}
              </p>
            </div>
            {onEdit ? (
              <Button
                variant="outline"
                size="sm"
                onClick={() => { onEdit(displayOrder); onOpenChange(false); }}
              >
                <Edit className="size-3.5" />
                {t('actions.edit')}
              </Button>
            ) : null}
            <SheetClose asChild>
              <Button variant="ghost" size="icon" className="size-8 shrink-0">
                <X className="size-4" />
              </Button>
            </SheetClose>
          </div>
        </SheetHeader>

        {/* Loading indicator — shown only on first fetch before detail data arrives */}
        {isEnriching ? (
          <div className="h-0.5 w-full animate-pulse bg-primary/40" />
        ) : null}

        {/* Distribution OS stage — shown when order is assigned to an active trip */}
        <OrderDistributionStageBanner orderId={displayOrder.id} />

        {/* ── Tabs + content ── */}
        <div className="flex-1 overflow-y-auto">
          <Tabs
            tabs={tabs}
            activeKey={activeTab}
            onTabChange={setActiveTab}
            className="h-full"
            contentClassName="overflow-y-auto"
          />
        </div>
      </SheetContent>
    </Sheet>
  );
}
