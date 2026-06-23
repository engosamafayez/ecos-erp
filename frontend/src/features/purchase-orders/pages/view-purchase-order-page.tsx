import { useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { CheckCircle, Pencil, XCircle } from 'lucide-react';

import { ConfirmDialog, PageHeader } from '@/components/crud';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { PoStatusBadge } from '@/features/purchase-orders/components/po-status-badge';
import { PurchaseOrderTotals } from '@/features/purchase-orders/components/purchase-order-totals';
import {
  useApprovePurchaseOrder,
  useCancelPurchaseOrder,
  usePurchaseOrderQuery,
} from '@/features/purchase-orders/hooks/use-purchase-orders';
import { ROUTES } from '@/router/routes';

function LabelValue({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-0.5">
      <span className="text-muted-foreground text-xs">{label}</span>
      <span className="text-sm font-medium">{value}</span>
    </div>
  );
}

export function ViewPurchaseOrderPage() {
  const { id = '' } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { data: order, isLoading } = usePurchaseOrderQuery(id);
  const approvePO = useApprovePurchaseOrder();
  const cancelPO = useCancelPurchaseOrder();

  const [approving, setApproving] = useState(false);
  const [cancelling, setCancelling] = useState(false);

  if (isLoading) {
    return (
      <div className="flex flex-col gap-6">
        <PageHeader
          title="Loading…"
          breadcrumbs={[{ label: 'Home', to: ROUTES.dashboard }, { label: 'Purchase Orders', to: ROUTES.purchaseOrders }, { label: '…' }]}
        />
      </div>
    );
  }

  if (!order) {
    return (
      <div className="flex flex-col gap-6">
        <PageHeader
          title="Not Found"
          breadcrumbs={[{ label: 'Home', to: ROUTES.dashboard }, { label: 'Purchase Orders', to: ROUTES.purchaseOrders }]}
        />
        <p className="text-muted-foreground text-sm">This purchase order does not exist.</p>
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={order.po_number}
        subtitle={order.supplier?.name ?? ''}
        breadcrumbs={[
          { label: 'Home', to: ROUTES.dashboard },
          { label: 'Purchase Orders', to: ROUTES.purchaseOrders },
          { label: order.po_number },
        ]}
        actions={
          <div className="flex items-center gap-2">
            <PoStatusBadge status={order.status} />
            {order.status === 'draft' && (
              <>
                <Button
                  variant="outline"
                  onClick={() => navigate(`${ROUTES.purchaseOrders}/${order.id}/edit`)}
                >
                  <Pencil className="size-4" />
                  Edit
                </Button>
                <Button onClick={() => setApproving(true)}>
                  <CheckCircle className="size-4" />
                  Approve
                </Button>
              </>
            )}
            {order.status !== 'cancelled' && (
              <Button variant="destructive" onClick={() => setCancelling(true)}>
                <XCircle className="size-4" />
                Cancel
              </Button>
            )}
          </div>
        }
      />

      <Card>
        <CardHeader>
          <CardTitle>Order Details</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <LabelValue label="Supplier" value={order.supplier?.name ?? '—'} />
            <LabelValue label="Order Date" value={order.order_date} />
            <LabelValue label="Expected Date" value={order.expected_date ?? '—'} />
            <LabelValue label="Status" value={<PoStatusBadge status={order.status} />} />
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
                        <span className="text-muted-foreground ml-1.5 text-xs">{line.product.sku}</span>
                      )}
                    </td>
                    <td className="py-2 pr-3">{line.quantity}</td>
                    <td className="py-2 pr-3">
                      {line.unit_price.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                    </td>
                    <td className="py-2 text-right font-medium">
                      {line.line_total.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <PurchaseOrderTotals subtotal={order.subtotal} total={order.total} />
        </CardContent>
      </Card>

      <ConfirmDialog
        open={approving}
        onOpenChange={setApproving}
        title="Approve purchase order"
        description={
          <>
            Approve <span className="text-foreground font-medium">{order.po_number}</span>? The order will become
            read-only after approval.
          </>
        }
        confirmLabel="Approve"
        loading={approvePO.isPending}
        onConfirm={() => {
          approvePO.mutate(order.id, { onSuccess: () => setApproving(false) });
        }}
      />

      <ConfirmDialog
        open={cancelling}
        onOpenChange={setCancelling}
        title="Cancel purchase order"
        description={
          <>
            Cancel <span className="text-foreground font-medium">{order.po_number}</span>? This
            cannot be undone.
          </>
        }
        confirmLabel="Cancel Order"
        variant="destructive"
        loading={cancelPO.isPending}
        onConfirm={() => {
          cancelPO.mutate(order.id, { onSuccess: () => setCancelling(false) });
        }}
      />
    </div>
  );
}
