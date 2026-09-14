import { useTranslation } from 'react-i18next';

import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Button } from '@/components/ui/button';
import { useTrackingSession } from '@/features/customer-portal/context/tracking-session-context';
import { useTrackOrderQuery } from '@/features/customer-portal/hooks/use-customer-portal';
import { InvoicePanel } from '@/features/customer-portal/components/invoice-panel';
import { SupportPanel } from '@/features/customer-portal/components/support-panel';
import { PaymentMethodPanel } from '@/features/customer-portal/components/payment-method-panel';

const TIMELINE_EVENT_KEYS = [
  'order_created',
  'confirmed',
  'payment_confirmed',
  'payment_proof_submitted',
  'preparation_completed',
  'out_for_delivery',
  'delivered',
  'cancelled',
  'returned',
  'on_hold',
] as const;

type TimelineEventKey = (typeof TIMELINE_EVENT_KEYS)[number];

function isTimelineEventKey(value: string): value is TimelineEventKey {
  return (TIMELINE_EVENT_KEYS as readonly string[]).includes(value);
}

export function OrderTrackingView({ language }: { language: 'en' | 'ar' }) {
  const { t } = useTranslation('customer-portal');
  const { endSession } = useTrackingSession();
  const orderQuery = useTrackOrderQuery();

  if (orderQuery.isLoading) {
    return (
      <div className="mx-auto flex max-w-3xl flex-col gap-4 px-4 py-10 sm:px-6">
        <Skeleton className="h-8 w-48" />
        <Skeleton className="h-40 w-full" />
        <Skeleton className="h-40 w-full" />
      </div>
    );
  }

  if (orderQuery.isError || !orderQuery.data) {
    return (
      <div className="mx-auto flex max-w-md flex-col items-center gap-4 px-4 py-16 text-center">
        <p className="text-muted-foreground text-sm">{t(($) => $.order.loadError)}</p>
        <Button variant="outline" onClick={() => void orderQuery.refetch()}>
          {t(($) => $.common.retry)}
        </Button>
        <Button variant="ghost" onClick={endSession}>
          {t(($) => $.session.endSession)}
        </Button>
      </div>
    );
  }

  const order = orderQuery.data;

  return (
    <div className="bg-background min-h-screen">
      <style>{'@page { margin: 12mm; }'}</style>

      <header className="border-b print:hidden">
        <div className="mx-auto flex max-w-3xl flex-wrap items-center justify-between gap-2 px-4 py-4 sm:px-6">
          <div>
            {order.brand ? <div className="text-sm font-semibold">{order.brand.name}</div> : null}
            <div className="text-muted-foreground text-xs">
              {t(($) => $.order.orderNumberLabel)}: {order.order_number}
            </div>
          </div>
          <Button variant="ghost" size="sm" onClick={endSession}>
            {t(($) => $.session.endSession)}
          </Button>
        </div>
      </header>

      <main className="mx-auto max-w-3xl px-4 py-6 sm:px-6">
        <Tabs defaultValue="order">
          <TabsList className="print:hidden">
            <TabsTrigger value="order">{t(($) => $.order.orderNumberLabel)}</TabsTrigger>
            <TabsTrigger value="invoice">{t(($) => $.invoice.heading)}</TabsTrigger>
            <TabsTrigger value="support">{t(($) => $.support.heading)}</TabsTrigger>
          </TabsList>

          <TabsContent value="order" className="flex flex-col gap-6">
            {/* A. Header */}
            <div className="flex flex-wrap items-center justify-between gap-2">
              <div>
                <div className="text-muted-foreground text-xs">
                  {t(($) => $.order.orderDateLabel)}
                </div>
                <div className="text-sm font-medium">{order.order_date ?? '—'}</div>
              </div>
              <Badge variant="secondary">{order.canonical_status_label}</Badge>
            </div>

            {/* B. Delivery */}
            <section className="border-border rounded-md border p-4">
              <h2 className="mb-3 text-sm font-semibold">{t(($) => $.delivery.heading)}</h2>
              <dl className="grid grid-cols-2 gap-3 text-sm">
                <div>
                  <dt className="text-muted-foreground">
                    {t(($) => $.order.requestedDeliveryLabel)}
                  </dt>
                  <dd>{order.requested_delivery_date ?? '—'}</dd>
                </div>
                <div>
                  <dt className="text-muted-foreground">
                    {t(($) => $.delivery.shippingCompanyLabel)}
                  </dt>
                  <dd>{order.delivery.shipping_company ?? '—'}</dd>
                </div>
                <div>
                  <dt className="text-muted-foreground">{t(($) => $.delivery.stopStatusLabel)}</dt>
                  <dd>{order.delivery.stop_status_label ?? '—'}</dd>
                </div>
                <div>
                  <dt className="text-muted-foreground">{t(($) => $.delivery.driverLabel)}</dt>
                  {/* §10 — driver identity is rendered EXACTLY as the backend returns it: first
                      name only, or nothing at all before Out for Delivery. Never a phone number,
                      never a "Call Driver" action — no such data or action is ever fetched. */}
                  <dd>{order.delivery.driver?.first_name ?? t(($) => $.delivery.noDriverYet)}</dd>
                </div>
              </dl>
            </section>

            {/* C. Items */}
            <section>
              <h2 className="mb-3 text-sm font-semibold">{t(($) => $.items.heading)}</h2>
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-border border-b">
                      <th className="py-2 text-start font-medium">{t(($) => $.items.product)}</th>
                      <th className="py-2 text-start font-medium">{t(($) => $.items.quantity)}</th>
                      <th className="py-2 text-start font-medium">{t(($) => $.items.unitPrice)}</th>
                      <th className="py-2 text-start font-medium">{t(($) => $.items.lineTotal)}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {order.items.map((item, index) => (
                      <tr key={index} className="border-border/60 border-b last:border-0">
                        <td className="py-2">{item.product_name ?? '—'}</td>
                        <td className="py-2">{item.quantity}</td>
                        <td className="py-2">{item.unit_price.toFixed(2)}</td>
                        <td className="py-2">{item.line_total.toFixed(2)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </section>

            {/* D. Financial summary */}
            <section className="border-border rounded-md border p-4">
              <h2 className="mb-3 text-sm font-semibold">{t(($) => $.financial.heading)}</h2>
              <dl className="flex flex-col gap-1.5 text-sm">
                {[
                  [t(($) => $.financial.subtotal), order.subtotal],
                  [t(($) => $.financial.shipping), order.shipping_amount],
                  [t(($) => $.financial.discount), -order.discount_amount],
                  [t(($) => $.financial.tax), order.tax_amount],
                ].map(([label, value]) => (
                  <div key={label as string} className="flex justify-between">
                    <dt className="text-muted-foreground">{label}</dt>
                    <dd>{(value as number).toFixed(2)}</dd>
                  </div>
                ))}
                <Separator className="my-1" />
                <div className="flex justify-between font-semibold">
                  <dt>{t(($) => $.financial.grandTotal)}</dt>
                  <dd>{order.grand_total.toFixed(2)}</dd>
                </div>
                <div className="flex justify-between">
                  <dt className="text-muted-foreground">{t(($) => $.financial.paid)}</dt>
                  <dd>{order.paid_amount.toFixed(2)}</dd>
                </div>
                <div className="flex justify-between">
                  <dt className="text-muted-foreground">{t(($) => $.financial.outstanding)}</dt>
                  <dd>{order.outstanding_amount.toFixed(2)}</dd>
                </div>
              </dl>
            </section>

            {/* E. Payment */}
            <section className="flex flex-col gap-3">
              <h2 className="text-sm font-semibold">{t(($) => $.payment.heading)}</h2>
              <div className="flex flex-wrap items-center gap-2 text-sm">
                <Badge variant="outline">{t(($) => $.payment.state[order.payment_state])}</Badge>
                {order.payment_proof_state ? (
                  <Badge variant="outline">
                    {t(($) => $.payment.proofState[order.payment_proof_state!])}
                  </Badge>
                ) : null}
              </div>
              {/* §11 — the change-method action is rendered ONLY when the backend itself says
                  this order is eligible; there is no client-side eligibility guess and no
                  payment-link option anywhere on this surface (§20). */}
              {order.payment_method_change_eligible ? (
                <PaymentMethodPanel onChanged={() => void orderQuery.refetch()} />
              ) : null}
            </section>

            {/* F. Timeline */}
            <section>
              <h2 className="mb-3 text-sm font-semibold">{t(($) => $.timeline.heading)}</h2>
              <ol className="flex flex-col gap-2 text-sm">
                {order.timeline.map((event, index) => {
                  const key = isTimelineEventKey(event.event) ? event.event : null;
                  return (
                    <li key={index} className="flex justify-between">
                      <span>{key ? t(($) => $.timeline.events[key]) : event.event}</span>
                      <span className="text-muted-foreground">
                        {new Date(event.occurred_at).toLocaleString()}
                      </span>
                    </li>
                  );
                })}
              </ol>
            </section>
          </TabsContent>

          <TabsContent value="invoice">
            <InvoicePanel language={language} />
          </TabsContent>

          <TabsContent value="support">
            <SupportPanel availability={order.support} />
          </TabsContent>
        </Tabs>
      </main>
    </div>
  );
}
