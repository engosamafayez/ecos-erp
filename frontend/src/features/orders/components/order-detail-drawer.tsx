import { Edit, MapPin, Navigation, Package, X } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import {
  Sheet,
  SheetClose,
  SheetContent,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet';
import { Tabs } from '@/components/ds/tabs';
import { OrderStatusBadge } from '@/features/orders/components/order-status-badge';
import type { Order } from '@/features/orders/types/order';
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

function formatMoney(n: number): string {
  return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// ── Tab panels ────────────────────────────────────────────────────────────────

function SummaryTab({ order, t }: { order: Order; t: (k: string) => string }) {
  return (
    <div className="flex flex-col gap-6 p-4">
      <DetailGrid>
        <DetailRow label={t('detail.orderNumber')}><span className="font-mono font-medium">{order.order_number}</span></DetailRow>
        <DetailRow label={t('detail.orderDate')}>{formatDate(order.order_date)}</DetailRow>
        <DetailRow label={t('detail.status')}><OrderStatusBadge status={order.status} /></DetailRow>
        <DetailRow label={t('detail.channel')}>{order.channel?.name}</DetailRow>
        <DetailRow label={t('detail.externalOrderId')}><span className="font-mono text-xs">{order.external_order_id ?? '—'}</span></DetailRow>
        <DetailRow label={t('detail.paymentMethodTitle')}>{order.payment_method_title ?? order.payment_method}</DetailRow>
      </DetailGrid>
      <Separator />
      <div>
        <SectionTitle>{t('detail.financialSummary')}</SectionTitle>
        <div className="flex flex-col gap-1.5 text-sm">
          {[
            { label: t('detail.subtotal'),       value: order.subtotal },
            { label: t('detail.shippingTotal'),   value: order.shipping_total },
            { label: t('detail.discountTotal'),   value: -order.discount_total },
            { label: t('detail.taxTotal'),        value: order.tax_total },
          ].map(({ label, value }) => (
            <div key={label} className="flex justify-between gap-4">
              <span className="text-muted-foreground">{label}</span>
              <span className={cn('tabular-nums', value < 0 && 'text-emerald-600')}>{formatMoney(value)}</span>
            </div>
          ))}
          <Separator className="my-1" />
          <div className="flex justify-between gap-4 font-semibold">
            <span>{t('detail.total')}</span>
            <span className="tabular-nums">{formatMoney(order.total)}</span>
          </div>
        </div>
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

function CustomerTab({ order, t }: { order: Order; t: (k: string) => string }) {
  return (
    <div className="flex flex-col gap-6 p-4">
      <div>
        <SectionTitle>{t('detail.customerInformation')}</SectionTitle>
        <DetailGrid>
          <DetailRow label={t('detail.customer')}>{order.customer?.name}</DetailRow>
          <DetailRow label={t('detail.customerCode')}><span className="font-mono text-xs">{order.customer?.code}</span></DetailRow>
        </DetailGrid>
      </div>
      <Separator />
      <div>
        <SectionTitle>{t('detail.billingInformation')}</SectionTitle>
        <DetailGrid>
          <DetailRow label={t('detail.billingName')}>
            {[order.billing_first_name, order.billing_last_name].filter(Boolean).join(' ') || null}
          </DetailRow>
          <DetailRow label={t('detail.billingPhone')}>{order.billing_phone}</DetailRow>
          <DetailRow label={t('detail.billingEmail')}>{order.billing_email}</DetailRow>
          <DetailRow label={t('detail.billingCompany')}>{order.billing_company}</DetailRow>
          <DetailRow label={t('detail.billingAddress1')}>{order.billing_address_1}</DetailRow>
          <DetailRow label={t('detail.billingAddress2')}>{order.billing_address_2}</DetailRow>
          <DetailRow label={t('detail.billingCity')}>{order.billing_city}</DetailRow>
          <DetailRow label={t('detail.billingState')}>{order.billing_state}</DetailRow>
          <DetailRow label={t('detail.billingPostcode')}>{order.billing_postcode}</DetailRow>
          <DetailRow label={t('detail.billingCountry')}>{order.billing_country}</DetailRow>
        </DetailGrid>
      </div>
      {order.customer_note ? (
        <>
          <Separator />
          <DetailRow label={t('detail.customerNote')}>{order.customer_note}</DetailRow>
        </>
      ) : null}
    </div>
  );
}

function ProductsTab({ order, t }: { order: Order; t: (k: string) => string }) {
  return (
    <div className="flex flex-col gap-0 p-4">
      {order.lines.length === 0 ? (
        <p className="text-center text-sm text-muted-foreground py-8">{t('table.empty')}</p>
      ) : (
        <div className="flex flex-col divide-y">
          {order.lines.map((line) => (
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
                <p className="text-sm font-medium tabular-nums">{formatMoney(line.line_total)}</p>
                <p className="text-xs text-muted-foreground">{line.quantity} × {formatMoney(line.unit_price)}</p>
              </div>
            </div>
          ))}
        </div>
      )}
      {order.fees.length > 0 ? (
        <>
          <Separator />
          <div className="py-3">
            <SectionTitle>{t('detail.fees')}</SectionTitle>
            {order.fees.map((f) => (
              <div key={f.id} className="flex justify-between text-sm py-1">
                <span className="text-muted-foreground">{f.name}</span>
                <span className="tabular-nums">{formatMoney(f.total)}</span>
              </div>
            ))}
          </div>
        </>
      ) : null}
      {order.coupons.length > 0 ? (
        <>
          <Separator />
          <div className="py-3">
            <SectionTitle>{t('detail.coupons')}</SectionTitle>
            {order.coupons.map((c) => (
              <div key={c.id} className="flex justify-between text-sm py-1">
                <span className="font-mono text-xs text-muted-foreground">{c.code}</span>
                <span className="tabular-nums text-emerald-600">-{formatMoney(c.discount)}</span>
              </div>
            ))}
          </div>
        </>
      ) : null}
    </div>
  );
}

function PaymentTab({ order, t }: { order: Order; t: (k: string) => string }) {
  return (
    <div className="p-4">
      <DetailGrid>
        <DetailRow label={t('detail.paymentMethod')}><span className="font-mono text-xs">{order.payment_method}</span></DetailRow>
        <DetailRow label={t('detail.paymentMethodTitle')}>{order.payment_method_title}</DetailRow>
        <DetailRow label={t('detail.transactionId')}><span className="font-mono text-xs">{order.transaction_id}</span></DetailRow>
        <DetailRow label={t('detail.datePaid')}>{formatDate(order.date_paid)}</DetailRow>
      </DetailGrid>
    </div>
  );
}

function ShippingTab({ order, t }: { order: Order; t: (k: string) => string }) {
  return (
    <div className="flex flex-col gap-6 p-4">
      <DetailGrid>
        <DetailRow label={t('detail.shippingName')}>
          {[order.shipping_first_name, order.shipping_last_name].filter(Boolean).join(' ') || null}
        </DetailRow>
        <DetailRow label={t('detail.shippingCompany')}>{order.shipping_company}</DetailRow>
        <DetailRow label={t('detail.shippingMethod')}>{order.shipping_method}</DetailRow>
        <DetailRow label={t('drawer.shippingCarrier')}>{order.shipping_company_name}</DetailRow>
        <DetailRow label={t('drawer.trackingNumber')}><span className="font-mono text-xs">{order.tracking_number}</span></DetailRow>
        <DetailRow label={t('drawer.shippingAttempts')}>{order.shipping_attempts ?? 0}</DetailRow>
      </DetailGrid>
      <Separator />
      <div>
        <SectionTitle>{t('detail.shippingInformation')}</SectionTitle>
        <DetailGrid>
          <DetailRow label={t('detail.shippingAddress1')}>{order.shipping_address_1}</DetailRow>
          <DetailRow label={t('detail.shippingAddress2')}>{order.shipping_address_2}</DetailRow>
          <DetailRow label={t('detail.shippingCity')}>{order.shipping_city}</DetailRow>
          <DetailRow label={t('detail.shippingState')}>{order.shipping_state}</DetailRow>
          <DetailRow label={t('detail.shippingPostcode')}>{order.shipping_postcode}</DetailRow>
          <DetailRow label={t('detail.shippingCountry')}>{order.shipping_country}</DetailRow>
        </DetailGrid>
      </div>
    </div>
  );
}

function NotesTab({ order, t }: { order: Order; t: (k: string) => string }) {
  return (
    <div className="flex flex-col gap-4 p-4">
      <DetailRow label={t('detail.notes')}>
        {order.notes ? (
          <p className="text-sm whitespace-pre-wrap">{order.notes}</p>
        ) : (
          <span className="text-muted-foreground text-sm">{t('drawer.noNotes')}</span>
        )}
      </DetailRow>
      <Separator />
      <DetailRow label={t('detail.customerNote')}>
        {order.customer_note ? (
          <p className="text-sm whitespace-pre-wrap">{order.customer_note}</p>
        ) : (
          <span className="text-muted-foreground text-sm">{t('drawer.noNotes')}</span>
        )}
      </DetailRow>
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
          {/* Embedded map */}
          <div className="overflow-hidden rounded-lg border aspect-video">
            <iframe
              title={t('drawer.locationMap')}
              width="100%"
              height="100%"
              style={{ border: 0 }}
              loading="lazy"
              src={`https://maps.google.com/maps?q=${loc.lat},${loc.lng}&z=15&output=embed`}
            />
          </div>
          {/* Location actions */}
          <div className="flex flex-wrap gap-2">
            <Button
              variant="outline"
              size="sm"
              asChild
            >
              <a
                href={`https://www.google.com/maps?q=${loc.lat},${loc.lng}`}
                target="_blank"
                rel="noopener noreferrer"
              >
                <Navigation className="size-3.5" />
                {t('address.openMaps')}
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

  if (!order) return null;

  const tabs = [
    { key: 'summary',       label: t('drawer.tabs.summary'),       content: <SummaryTab order={order} t={t} /> },
    { key: 'customer',      label: t('drawer.tabs.customer'),      content: <CustomerTab order={order} t={t} /> },
    { key: 'products',      label: t('drawer.tabs.products'),      content: <ProductsTab order={order} t={t} />, badge: order.lines.length },
    { key: 'payment',   label: t('drawer.tabs.payment'),   content: <PaymentTab order={order} t={t} /> },
    { key: 'shipping',  label: t('drawer.tabs.shipping'),  content: <ShippingTab order={order} t={t} /> },
    { key: 'notes',     label: t('drawer.tabs.notes'),     content: <NotesTab order={order} t={t} /> },
    { key: 'location',  label: t('drawer.tabs.location'),  content: <LocationTab order={order} t={t} /> },
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
                {order.order_number}
                <OrderStatusBadge status={order.status} />
              </SheetTitle>
              <p className="text-xs text-muted-foreground mt-0.5">
                {order.customer?.name ?? '—'} · {order.channel?.name ?? '—'}
              </p>
            </div>
            {onEdit ? (
              <Button
                variant="outline"
                size="sm"
                onClick={() => { onEdit(order); onOpenChange(false); }}
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
