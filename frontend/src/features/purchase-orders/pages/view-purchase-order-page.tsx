import { useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { CheckCircle, Pencil, XCircle } from 'lucide-react';
import { useTranslation } from 'react-i18next';

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
  const { t } = useTranslation('purchase-orders');
  const { t: tCommon } = useTranslation('common');
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
          title={t('detail.loading')}
          breadcrumbs={[{ label: tCommon('home'), to: ROUTES.dashboard }, { label: t('title'), to: ROUTES.purchaseOrders }, { label: '…' }]}
        />
      </div>
    );
  }

  if (!order) {
    return (
      <div className="flex flex-col gap-6">
        <PageHeader
          title={t('detail.notFound')}
          breadcrumbs={[{ label: tCommon('home'), to: ROUTES.dashboard }, { label: t('title'), to: ROUTES.purchaseOrders }]}
        />
        <p className="text-muted-foreground text-sm">{t('detail.notFoundMessage')}</p>
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={order.po_number}
        subtitle={order.supplier?.name ?? ''}
        breadcrumbs={[
          { label: tCommon('home'), to: ROUTES.dashboard },
          { label: t('title'), to: ROUTES.purchaseOrders },
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
                  {tCommon('common.edit')}
                </Button>
                <Button onClick={() => setApproving(true)}>
                  <CheckCircle className="size-4" />
                  {tCommon('actions.approve')}
                </Button>
              </>
            )}
            {order.status !== 'cancelled' && (
              <Button variant="destructive" onClick={() => setCancelling(true)}>
                <XCircle className="size-4" />
                {tCommon('common.cancel')}
              </Button>
            )}
          </div>
        }
      />

      <Card>
        <CardHeader>
          <CardTitle>{t('detail.orderDetails')}</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <LabelValue label={t('detail.supplier')} value={order.supplier?.name ?? '—'} />
            <LabelValue label={t('detail.orderDate')} value={order.order_date} />
            <LabelValue label={t('detail.expectedDate')} value={order.expected_date ?? '—'} />
            <LabelValue label={t('detail.status')} value={<PoStatusBadge status={order.status} />} />
          </div>
          {order.notes && (
            <div className="mt-4">
              <span className="text-muted-foreground text-xs">{t('detail.notes')}</span>
              <p className="mt-0.5 text-sm">{order.notes}</p>
            </div>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>{t('detail.lineItems')}</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-muted-foreground border-b text-left">
                  <th className="pb-2 pr-3 font-medium">{t('detail.product')}</th>
                  <th className="w-28 pb-2 pr-3 font-medium">{t('detail.qty')}</th>
                  <th className="w-32 pb-2 pr-3 font-medium">{t('detail.unitPrice')}</th>
                  <th className="w-32 pb-2 font-medium text-right">{t('detail.lineTotal')}</th>
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
        title={t('dialogs.approve.title')}
        description={t('dialogs.approve.description', { number: order.po_number })}
        confirmLabel={t('dialogs.approve.confirm')}
        loading={approvePO.isPending}
        onConfirm={() => {
          approvePO.mutate(order.id, { onSuccess: () => setApproving(false) });
        }}
      />

      <ConfirmDialog
        open={cancelling}
        onOpenChange={setCancelling}
        title={t('dialogs.cancel.title')}
        description={t('dialogs.cancel.description', { number: order.po_number })}
        confirmLabel={t('dialogs.cancel.confirm')}
        variant="destructive"
        loading={cancelPO.isPending}
        onConfirm={() => {
          cancelPO.mutate(order.id, { onSuccess: () => setCancelling(false) });
        }}
      />
    </div>
  );
}
