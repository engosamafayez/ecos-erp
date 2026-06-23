import { useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { Pencil } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { PageHeader } from '@/components/crud';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { OrderFormDrawer } from '@/features/orders/components/order-form-drawer';
import { OrderStatusBadge } from '@/features/orders/components/order-status-badge';
import { useOrderQuery } from '@/features/orders/hooks/use-orders';
import { ROUTES } from '@/router/routes';

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

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">{title}</CardTitle>
      </CardHeader>
      <CardContent>{children}</CardContent>
    </Card>
  );
}

export function OrderDetailPage() {
  const { t } = useTranslation('orders');
  const { t: tCommon } = useTranslation('common');
  const { id = '' } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { data: order, isLoading } = useOrderQuery(id);
  const [editOpen, setEditOpen] = useState(false);

  if (isLoading) {
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

  if (!order) {
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

  const shippingName = [order.shipping_first_name, order.shipping_last_name].filter(Boolean).join(' ') || null;
  const billingName = [order.billing_first_name, order.billing_last_name].filter(Boolean).join(' ') || null;

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
            <OrderStatusBadge status={order.status} />
            <Button variant="outline" onClick={() => navigate(ROUTES.orders)}>
              {t('detail.back')}
            </Button>
            <Button onClick={() => setEditOpen(true)}>
              <Pencil className="size-4" />
              {tCommon('common.edit')}
            </Button>
          </div>
        }
      />

      {/* Order Details */}
      <Section title={t('detail.orderDetails')}>
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <LabelValue label={t('detail.orderNumber')} value={order.order_number} />
          <LabelValue label={t('detail.orderDate')} value={order.order_date} />
          <LabelValue label={t('detail.status')} value={<OrderStatusBadge status={order.status} />} />
          <LabelValue label={t('detail.channel')} value={order.channel?.name} />
          <LabelValue label={t('detail.externalOrderId')} value={order.external_order_id} />
          {billingName && (
            <LabelValue label={t('detail.billingName')} value={billingName} />
          )}
        </div>
        {order.notes && (
          <div className="mt-4 border-t pt-4">
            <span className="text-muted-foreground text-xs">{t('detail.notes')}</span>
            <p className="mt-0.5 text-sm">{order.notes}</p>
          </div>
        )}
        {order.customer_note && (
          <div className="mt-4 border-t pt-4">
            <span className="text-muted-foreground text-xs">{t('detail.customerNote')}</span>
            <p className="bg-muted/40 mt-1 rounded-md border px-3 py-2 text-sm italic">
              {order.customer_note}
            </p>
          </div>
        )}
      </Section>

      {/* Customer Information */}
      <Section title={t('detail.customerInformation')}>
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <LabelValue label={t('detail.customer')} value={order.customer?.name} />
          <LabelValue label={t('detail.customerCode')} value={order.customer?.code} />
        </div>
      </Section>

      {/* Shipping Information */}
      {hasShipping && (
        <Section title={t('detail.shippingInformation')}>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {shippingName && <LabelValue label={t('detail.shippingName')} value={shippingName} />}
            {order.shipping_company && <LabelValue label={t('detail.shippingCompany')} value={order.shipping_company} />}
            {order.shipping_method && <LabelValue label={t('detail.shippingMethod')} value={order.shipping_method} />}
            {order.shipping_address_1 && <LabelValue label={t('detail.shippingAddress1')} value={order.shipping_address_1} />}
            {order.shipping_address_2 && <LabelValue label={t('detail.shippingAddress2')} value={order.shipping_address_2} />}
            {order.shipping_city && <LabelValue label={t('detail.shippingCity')} value={order.shipping_city} />}
            {order.shipping_state && <LabelValue label={t('detail.shippingState')} value={order.shipping_state} />}
            {order.shipping_postcode && <LabelValue label={t('detail.shippingPostcode')} value={order.shipping_postcode} />}
            {order.shipping_country && <LabelValue label={t('detail.shippingCountry')} value={order.shipping_country} />}
          </div>
        </Section>
      )}

      {/* Payment Information */}
      {hasPayment && (
        <Section title={t('detail.paymentInformation')}>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {order.payment_method_title && (
              <LabelValue label={t('detail.paymentMethodTitle')} value={order.payment_method_title} />
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
        </Section>
      )}

      {/* Order Items */}
      <Section title={t('detail.lineItems')}>
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
                    {line.product?.image_url ? (
                      <img
                        src={line.product.image_url}
                        alt={line.product.name}
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
                  <td className="py-2 text-right font-medium tabular-nums">{fmt(line.line_total)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {/* Order Totals */}
        <div className="flex flex-col items-end gap-1.5 border-t pt-4 text-sm">
          <div className="flex w-72 justify-between gap-4">
            <span className="text-muted-foreground">{t('detail.subtotal')}</span>
            <span className="tabular-nums font-medium">{fmt(order.subtotal)}</span>
          </div>
          {order.shipping_total > 0 && (
            <div className="flex w-72 justify-between gap-4">
              <span className="text-muted-foreground">{t('detail.shippingTotal')}</span>
              <span className="tabular-nums font-medium">{fmt(order.shipping_total)}</span>
            </div>
          )}
          {order.discount_total > 0 && (
            <div className="flex w-72 justify-between gap-4">
              <span className="text-muted-foreground">{t('detail.discountTotal')}</span>
              <span className="tabular-nums font-medium text-emerald-600">−{fmt(order.discount_total)}</span>
            </div>
          )}
          <div className="flex w-72 justify-between gap-4 border-t pt-2 text-base font-semibold">
            <span>{t('detail.total')}</span>
            <span className="tabular-nums">{fmt(order.total)}</span>
          </div>
        </div>
      </Section>

      <OrderFormDrawer
        open={editOpen}
        onOpenChange={setEditOpen}
        order={order}
      />
    </div>
  );
}
