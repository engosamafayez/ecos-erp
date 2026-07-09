import { useNavigate, useParams, useLocation } from 'react-router-dom';
import { ArrowLeft, Pencil } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { ManualOrderFormWorkspace } from '@/features/orders/components/manual-order-form';
import { PageHeader } from '@/components/crud';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { OrderStatusBadge } from '@/features/orders/components/order-status-badge';
import { getMediaUrl } from '@/lib/media';
import { useOrderQuery } from '@/features/orders/hooks/use-orders';
import type { Order } from '@/features/orders/types/order';
import { ROUTES } from '@/router/routes';

// ─────────────────────────────────────────────────────────────────────────────
// Shared helpers
// ─────────────────────────────────────────────────────────────────────────────

function fmt(n: number) {
  return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function LabelValue({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-0.5">
      <span className="text-muted-foreground text-xs">{label}</span>
      <span className="text-sm font-medium">{value ?? '—'}</span>
    </div>
  );
}

function WorkspaceCard({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <Card>
      <CardHeader className="pb-3">
        <CardTitle className="text-base">{title}</CardTitle>
      </CardHeader>
      <CardContent>{children}</CardContent>
    </Card>
  );
}

// ─────────────────────────────────────────────────────────────────────────────
// Summary card — view mode
// ─────────────────────────────────────────────────────────────────────────────

function SummaryRows({
  subtotal,
  feesTotal,
  shippingTotal,
  discountTotal,
  taxTotal,
  total,
}: {
  subtotal: number;
  feesTotal: number;
  shippingTotal: number;
  discountTotal: number;
  taxTotal: number;
  total: number;
}) {
  const { t } = useTranslation('orders');
  return (
    <div className="flex flex-col gap-2 text-sm">
      <div className="flex justify-between gap-3">
        <span className="text-muted-foreground">{t('detail.subtotal')}</span>
        <span className="font-medium tabular-nums">{fmt(subtotal)}</span>
      </div>
      {feesTotal > 0 && (
        <div className="flex justify-between gap-3">
          <span className="text-muted-foreground">{t('detail.feesTotal')}</span>
          <span className="font-medium tabular-nums">{fmt(feesTotal)}</span>
        </div>
      )}
      {shippingTotal > 0 && (
        <div className="flex justify-between gap-3">
          <span className="text-muted-foreground">{t('detail.shippingTotal')}</span>
          <span className="font-medium tabular-nums">{fmt(shippingTotal)}</span>
        </div>
      )}
      {discountTotal > 0 && (
        <div className="flex justify-between gap-3">
          <span className="text-muted-foreground">{t('detail.discountTotal')}</span>
          <span className="font-medium tabular-nums text-emerald-600">−{fmt(discountTotal)}</span>
        </div>
      )}
      {taxTotal > 0 && (
        <div className="flex justify-between gap-3">
          <span className="text-muted-foreground">{t('detail.taxTotal')}</span>
          <span className="font-medium tabular-nums">{fmt(taxTotal)}</span>
        </div>
      )}
      <div className="border-t pt-2">
        <div className="flex justify-between gap-3 text-base font-semibold">
          <span>{t('detail.total')}</span>
          <span className="tabular-nums">{fmt(total)}</span>
        </div>
      </div>
    </div>
  );
}

function ViewSummaryCard({ order }: { order: Order }) {
  const { t } = useTranslation('orders');
  const feesTotal = order.fees.reduce((sum, f) => sum + f.total, 0);
  return (
    <Card className="lg:sticky lg:top-6">
      <CardHeader className="pb-3">
        <CardTitle className="text-base">{t('workspace.summary')}</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <SummaryRows
          subtotal={order.subtotal}
          feesTotal={feesTotal}
          shippingTotal={order.shipping_total}
          discountTotal={order.discount_total}
          taxTotal={order.tax_total}
          total={order.total}
        />
        <div className="border-t pt-4">
          <OrderStatusBadge status={order.status} />
        </div>
      </CardContent>
    </Card>
  );
}

// ─────────────────────────────────────────────────────────────────────────────
// VIEW workspace
// ─────────────────────────────────────────────────────────────────────────────

function ViewWorkspace({ order }: { order: Order }) {
  const { t } = useTranslation('orders');
  const { t: tCommon } = useTranslation('common');
  const navigate = useNavigate();

  const shippingName =
    [order.shipping_first_name, order.shipping_last_name].filter(Boolean).join(' ') || null;
  const billingName =
    [order.billing_first_name, order.billing_last_name].filter(Boolean).join(' ') || null;

  const hasBilling =
    billingName ||
    order.billing_company ||
    order.billing_address_1 ||
    order.billing_city ||
    order.billing_email ||
    order.billing_phone;

  const hasShipping =
    shippingName ||
    order.shipping_company ||
    order.shipping_address_1 ||
    order.shipping_city ||
    order.shipping_country;

  const hasPayment =
    order.payment_method_title ||
    order.payment_method ||
    order.transaction_id ||
    order.date_paid;

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={order.order_number}
        subtitle={order.customer?.name ?? ''}
        breadcrumbs={[
          { label: tCommon('home'), to: ROUTES.dashboard },
          { label: t('title'), to: ROUTES.orders },
          { label: order.order_number },
        ]}
        actions={
          <div className="flex items-center gap-2">
            <Button variant="outline" onClick={() => navigate(ROUTES.orders)}>
              <ArrowLeft className="size-4" />
              {t('detail.back')}
            </Button>
            <Button onClick={() => navigate(`${ROUTES.orders}/${order.id}/edit`)}>
              <Pencil className="size-4" />
              {tCommon('common.edit')}
            </Button>
          </div>
        }
      />

      <div className="grid gap-6 lg:grid-cols-[1fr_340px]">
        <div className="flex min-w-0 flex-col gap-6">
          {/* Order Information */}
          <WorkspaceCard title={t('detail.orderDetails')}>
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
              <LabelValue label={t('detail.orderNumber')} value={order.order_number} />
              <LabelValue label={t('detail.orderDate')} value={order.order_date} />
              <LabelValue label={t('detail.channel')} value={order.channel?.name} />
              <LabelValue label={t('detail.externalOrderId')} value={order.external_order_id} />
              {billingName && (
                <LabelValue label={t('detail.billingName')} value={billingName} />
              )}
            </div>
          </WorkspaceCard>

          {/* Customer */}
          <WorkspaceCard title={t('detail.customerInformation')}>
            <div className="grid gap-4 sm:grid-cols-2">
              <LabelValue label={t('detail.customer')} value={order.customer?.name} />
              <LabelValue label={t('detail.customerCode')} value={order.customer?.code} />
            </div>
          </WorkspaceCard>

          {/* Billing (WooCommerce data — always read-only) */}
          {hasBilling && (
            <WorkspaceCard title={t('detail.billingInformation')}>
              <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {billingName && (
                  <LabelValue label={t('detail.billingName')} value={billingName} />
                )}
                {order.billing_company && (
                  <LabelValue label={t('detail.billingCompany')} value={order.billing_company} />
                )}
                {order.billing_address_1 && (
                  <LabelValue label={t('detail.billingAddress1')} value={order.billing_address_1} />
                )}
                {order.billing_address_2 && (
                  <LabelValue label={t('detail.billingAddress2')} value={order.billing_address_2} />
                )}
                {order.billing_city && (
                  <LabelValue label={t('detail.billingCity')} value={order.billing_city} />
                )}
                {order.billing_state && (
                  <LabelValue label={t('detail.billingState')} value={order.billing_state} />
                )}
                {order.billing_postcode && (
                  <LabelValue label={t('detail.billingPostcode')} value={order.billing_postcode} />
                )}
                {order.billing_country && (
                  <LabelValue label={t('detail.billingCountry')} value={order.billing_country} />
                )}
                {order.billing_phone && (
                  <LabelValue label={t('detail.billingPhone')} value={order.billing_phone} />
                )}
                {order.billing_email && (
                  <LabelValue label={t('detail.billingEmail')} value={order.billing_email} />
                )}
              </div>
            </WorkspaceCard>
          )}

          {/* Shipping (WooCommerce data — always read-only) */}
          {hasShipping && (
            <WorkspaceCard title={t('detail.shippingInformation')}>
              <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {shippingName && (
                  <LabelValue label={t('detail.shippingName')} value={shippingName} />
                )}
                {order.shipping_company && (
                  <LabelValue label={t('detail.shippingCompany')} value={order.shipping_company} />
                )}
                {order.shipping_method && (
                  <LabelValue label={t('detail.shippingMethod')} value={order.shipping_method} />
                )}
                {order.shipping_address_1 && (
                  <LabelValue label={t('detail.shippingAddress1')} value={order.shipping_address_1} />
                )}
                {order.shipping_address_2 && (
                  <LabelValue label={t('detail.shippingAddress2')} value={order.shipping_address_2} />
                )}
                {order.shipping_city && (
                  <LabelValue label={t('detail.shippingCity')} value={order.shipping_city} />
                )}
                {order.shipping_state && (
                  <LabelValue label={t('detail.shippingState')} value={order.shipping_state} />
                )}
                {order.shipping_postcode && (
                  <LabelValue label={t('detail.shippingPostcode')} value={order.shipping_postcode} />
                )}
                {order.shipping_country && (
                  <LabelValue label={t('detail.shippingCountry')} value={order.shipping_country} />
                )}
              </div>
            </WorkspaceCard>
          )}

          {/* Payment (WooCommerce data — always read-only) */}
          {hasPayment && (
            <WorkspaceCard title={t('detail.paymentInformation')}>
              <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {order.payment_method_title && (
                  <LabelValue
                    label={t('detail.paymentMethodTitle')}
                    value={order.payment_method_title}
                  />
                )}
                {order.payment_method && (
                  <LabelValue label={t('detail.paymentMethod')} value={order.payment_method} />
                )}
                {order.transaction_id && (
                  <LabelValue label={t('detail.transactionId')} value={order.transaction_id} />
                )}
                {order.date_paid && (
                  <LabelValue
                    label={t('detail.datePaid')}
                    value={new Date(order.date_paid).toLocaleString()}
                  />
                )}
              </div>
            </WorkspaceCard>
          )}

          {/* Fees */}
          {order.fees.length > 0 && (
            <WorkspaceCard title={t('detail.fees')}>
              <table className="w-full text-sm">
                <thead>
                  <tr className="text-muted-foreground border-b text-left">
                    <th className="pb-2 pr-3 font-medium">{t('detail.feeName')}</th>
                    <th className="w-36 pb-2 text-right font-medium">{t('detail.feeAmount')}</th>
                  </tr>
                </thead>
                <tbody className="divide-y">
                  {order.fees.map((fee) => (
                    <tr key={fee.id}>
                      <td className="py-2 pr-3">{fee.name}</td>
                      <td className="py-2 text-right tabular-nums font-medium">{fmt(fee.total)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </WorkspaceCard>
          )}

          {/* Coupons */}
          {order.coupons.length > 0 && (
            <WorkspaceCard title={t('detail.coupons')}>
              <table className="w-full text-sm">
                <thead>
                  <tr className="text-muted-foreground border-b text-left">
                    <th className="pb-2 pr-3 font-medium">{t('detail.couponCode')}</th>
                    <th className="w-36 pb-2 text-right font-medium">{t('detail.couponDiscount')}</th>
                  </tr>
                </thead>
                <tbody className="divide-y">
                  {order.coupons.map((coupon) => (
                    <tr key={coupon.id}>
                      <td className="py-2 pr-3">
                        <code className="bg-muted rounded px-1.5 py-0.5 text-xs font-mono">
                          {coupon.code}
                        </code>
                      </td>
                      <td className="py-2 text-right tabular-nums font-medium text-emerald-600">
                        −{fmt(coupon.discount)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </WorkspaceCard>
          )}

          {/* Products */}
          <WorkspaceCard title={t('workspace.products')}>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="text-muted-foreground border-b text-left">
                    <th className="w-12 pb-2 pr-3 font-medium">{t('detail.image')}</th>
                    <th className="pb-2 pr-3 font-medium">{t('detail.product')}</th>
                    <th className="w-24 pb-2 pr-3 font-medium">{t('detail.qty')}</th>
                    <th className="w-32 pb-2 pr-3 font-medium">{t('detail.unitPrice')}</th>
                    <th className="w-32 pb-2 text-right font-medium">{t('detail.lineTotal')}</th>
                  </tr>
                </thead>
                <tbody className="divide-y">
                  {order.lines.map((line) => (
                    <tr key={line.id}>
                      <td className="py-2 pr-3">
                        {getMediaUrl(line.product?.image_url) ? (
                          <img
                            src={getMediaUrl(line.product!.image_url)!}
                            alt={line.product!.name}
                            className="size-10 rounded object-cover"
                          />
                        ) : (
                          <div className="bg-muted size-10 rounded" />
                        )}
                      </td>
                      <td className="py-2 pr-3">
                        <span className="font-medium">{line.product?.name ?? '—'}</span>
                        {line.product?.sku && (
                          <span className="text-muted-foreground ml-1.5 text-xs">
                            {line.product.sku}
                          </span>
                        )}
                      </td>
                      <td className="py-2 pr-3 tabular-nums">{line.quantity}</td>
                      <td className="py-2 pr-3 tabular-nums">{fmt(line.unit_price)}</td>
                      <td className="py-2 text-right font-medium tabular-nums">
                        {fmt(line.line_total)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </WorkspaceCard>

          {/* Notes */}
          {(order.notes || order.customer_note) && (
            <WorkspaceCard title={t('detail.notes')}>
              {order.notes && (
                <div>
                  <span className="text-muted-foreground text-xs">{t('detail.notes')}</span>
                  <p className="mt-0.5 text-sm">{order.notes}</p>
                </div>
              )}
              {order.customer_note && (
                <div className={order.notes ? 'mt-4 border-t pt-4' : ''}>
                  <span className="text-muted-foreground text-xs">{t('detail.customerNote')}</span>
                  <p className="bg-muted/40 mt-1 rounded-md border px-3 py-2 text-sm italic">
                    {order.customer_note}
                  </p>
                </div>
              )}
            </WorkspaceCard>
          )}
        </div>

        {/* Right column: sticky summary */}
        <div>
          <ViewSummaryCard order={order} />
        </div>
      </div>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────────────
// Page entry point
// ─────────────────────────────────────────────────────────────────────────────

export function OrderWorkspacePage() {
  const { t } = useTranslation('orders');
  const { t: tCommon } = useTranslation('common');
  const { id } = useParams<{ id: string }>();
  const { pathname } = useLocation();

  const mode: 'create' | 'edit' | 'view' = !id
    ? 'create'
    : pathname.endsWith('/edit')
    ? 'edit'
    : 'view';

  // enabled: false in create mode (id is undefined)
  const { data: order, isLoading } = useOrderQuery(id ?? '');

  // Loading state — only for edit/view
  if (id && isLoading) {
    return (
      <div className="flex flex-col gap-6">
        <PageHeader
          title={t('detail.loading')}
          breadcrumbs={[
            { label: tCommon('home'), to: ROUTES.dashboard },
            { label: t('title'), to: ROUTES.orders },
            { label: '…' },
          ]}
        />
      </div>
    );
  }

  // Not found — only for edit/view
  if (id && !order) {
    return (
      <div className="flex flex-col gap-6">
        <PageHeader
          title={t('detail.notFound')}
          breadcrumbs={[
            { label: tCommon('home'), to: ROUTES.dashboard },
            { label: t('title'), to: ROUTES.orders },
          ]}
        />
        <p className="text-muted-foreground text-sm">{t('detail.notFoundMessage')}</p>
      </div>
    );
  }

  if (mode === 'view') return <ViewWorkspace order={order!} />;
  if (mode === 'create') return <ManualOrderFormWorkspace />;
  return <ManualOrderFormWorkspace mode="edit" order={order!} />;
}
