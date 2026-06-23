import { useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { Pencil } from 'lucide-react';

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

export function OrderDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { data: order, isLoading } = useOrderQuery(id);
  const [editOpen, setEditOpen] = useState(false);

  if (isLoading) {
    return (
      <div className="flex flex-col gap-6">
        <PageHeader
          title="Loading…"
          breadcrumbs={[
            { label: 'Home', to: ROUTES.dashboard },
            { label: 'Orders', to: ROUTES.orders },
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
          title="Not Found"
          breadcrumbs={[
            { label: 'Home', to: ROUTES.dashboard },
            { label: 'Orders', to: ROUTES.orders },
          ]}
        />
        <p className="text-muted-foreground text-sm">This order does not exist.</p>
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={order.order_number}
        subtitle={order.customer?.name ?? ''}
        breadcrumbs={[
          { label: 'Home', to: ROUTES.dashboard },
          { label: 'Orders', to: ROUTES.orders },
          { label: order.order_number },
        ]}
        actions={
          <div className="flex items-center gap-2">
            <OrderStatusBadge status={order.status} />
            <Button variant="outline" onClick={() => navigate(ROUTES.orders)}>
              Back
            </Button>
            <Button onClick={() => setEditOpen(true)}>
              <Pencil className="size-4" />
              Edit
            </Button>
          </div>
        }
      />

      <Card>
        <CardHeader>
          <CardTitle>Order Details</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <LabelValue label="Order Number" value={order.order_number} />
            <LabelValue label="Customer" value={order.customer?.name} />
            <LabelValue label="Channel" value={order.channel?.name} />
            <LabelValue label="Order Date" value={order.order_date} />
            <LabelValue label="Status" value={<OrderStatusBadge status={order.status} />} />
            <LabelValue label="External Order ID" value={order.external_order_id} />
          </div>
          {order.notes && (
            <div className="mt-4">
              <span className="text-muted-foreground text-xs">Notes</span>
              <p className="mt-0.5 text-sm">{order.notes}</p>
            </div>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Line Items</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-muted-foreground border-b text-left">
                  <th className="pb-2 pr-3 font-medium">Product</th>
                  <th className="w-28 pb-2 pr-3 font-medium">Qty</th>
                  <th className="w-32 pb-2 pr-3 font-medium">Unit Price</th>
                  <th className="w-32 pb-2 font-medium text-right">Line Total</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {order.lines.map((line) => (
                  <tr key={line.id}>
                    <td className="py-2 pr-3">
                      <span className="font-medium">{line.product?.name ?? '—'}</span>
                      {line.product?.sku && (
                        <span className="text-muted-foreground ml-1.5 text-xs">
                          {line.product.sku}
                        </span>
                      )}
                    </td>
                    <td className="py-2 pr-3">{line.quantity}</td>
                    <td className="py-2 pr-3">{fmt(line.unit_price)}</td>
                    <td className="py-2 text-right font-medium">{fmt(line.line_total)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="flex flex-col items-end gap-1 border-t pt-3 text-sm">
            <div className="flex gap-8">
              <span className="text-muted-foreground">Subtotal</span>
              <span className="w-28 text-right font-medium">{fmt(order.subtotal)}</span>
            </div>
            <div className="flex gap-8 text-base font-semibold">
              <span>Total</span>
              <span className="w-28 text-right">{fmt(order.total)}</span>
            </div>
          </div>
        </CardContent>
      </Card>

      <OrderFormDrawer
        open={editOpen}
        onOpenChange={setEditOpen}
        order={order}
      />
    </div>
  );
}
