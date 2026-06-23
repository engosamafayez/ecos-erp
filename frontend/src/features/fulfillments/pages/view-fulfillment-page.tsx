import { useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { CheckCircle, XCircle } from 'lucide-react';

import { ConfirmDialog, PageHeader } from '@/components/crud';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { FulfillmentStatusBadge } from '@/features/fulfillments/components/fulfillment-status-badge';
import {
  useCancelFulfillment,
  useFulfillFulfillment,
  useFulfillmentQuery,
} from '@/features/fulfillments/hooks/use-fulfillments';
import { ROUTES } from '@/router/routes';

function LabelValue({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-0.5">
      <span className="text-muted-foreground text-xs">{label}</span>
      <span className="text-sm font-medium">{value ?? '—'}</span>
    </div>
  );
}

export function ViewFulfillmentPage() {
  const { id = '' } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const [confirmFulfill, setConfirmFulfill] = useState(false);
  const [confirmCancel, setConfirmCancel] = useState(false);

  const { data: fulfillment, isLoading, isError } = useFulfillmentQuery(id);
  const fulfill = useFulfillFulfillment();
  const cancel = useCancelFulfillment();

  if (isLoading) {
    return (
      <div className="flex h-64 items-center justify-center">
        <span className="text-muted-foreground text-sm">Loading…</span>
      </div>
    );
  }

  if (isError || !fulfillment) {
    return (
      <div className="flex h-64 items-center justify-center">
        <span className="text-destructive text-sm">Fulfillment not found.</span>
      </div>
    );
  }

  const isPending = fulfillment.status === 'pending';

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={fulfillment.fulfillment_number}
        subtitle={<FulfillmentStatusBadge status={fulfillment.status} />}
        breadcrumbs={[
          { label: 'Home', to: ROUTES.dashboard },
          { label: 'Fulfillments', to: ROUTES.fulfillments },
          { label: fulfillment.fulfillment_number },
        ]}
        actions={
          <div className="flex items-center gap-2">
            <Button variant="outline" onClick={() => navigate(ROUTES.fulfillments)}>
              Back
            </Button>
            {isPending && (
              <>
                <Button onClick={() => setConfirmFulfill(true)}>
                  <CheckCircle className="size-4" />
                  Fulfill
                </Button>
                <Button variant="destructive" onClick={() => setConfirmCancel(true)}>
                  <XCircle className="size-4" />
                  Cancel
                </Button>
              </>
            )}
          </div>
        }
      />

      <Card>
        <CardHeader>
          <CardTitle>Fulfillment Details</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <LabelValue label="Fulfillment #" value={fulfillment.fulfillment_number} />
            <LabelValue label="Order" value={fulfillment.order?.order_number} />
            <LabelValue label="Customer" value={fulfillment.order?.customer?.name} />
            <LabelValue label="Warehouse" value={fulfillment.warehouse?.name} />
            <LabelValue label="Date" value={fulfillment.fulfillment_date} />
            <LabelValue
              label="Status"
              value={<FulfillmentStatusBadge status={fulfillment.status} />}
            />
          </div>
          {fulfillment.notes && (
            <div className="mt-4">
              <span className="text-muted-foreground text-xs">Notes</span>
              <p className="mt-0.5 text-sm">{fulfillment.notes}</p>
            </div>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Line Items</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-muted-foreground border-b text-left">
                  <th className="pb-2 pr-3 font-medium">Product</th>
                  <th className="w-32 pb-2 font-medium">Quantity</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {fulfillment.lines.map((line) => (
                  <tr key={line.id}>
                    <td className="py-2 pr-3">
                      <span className="font-medium">{line.product?.name ?? '—'}</span>
                      {line.product?.sku && (
                        <span className="text-muted-foreground ml-1.5 text-xs">
                          {line.product.sku}
                        </span>
                      )}
                    </td>
                    <td className="py-2">{line.quantity}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </CardContent>
      </Card>

      <ConfirmDialog
        open={confirmFulfill}
        onOpenChange={setConfirmFulfill}
        title="Fulfill shipment"
        description={
          <>
            Fulfill{' '}
            <span className="text-foreground font-medium">
              {fulfillment.fulfillment_number}
            </span>
            ? Stock will be deducted from the warehouse and this fulfillment will become
            read-only.
          </>
        }
        confirmLabel="Fulfill"
        loading={fulfill.isPending}
        onConfirm={() => {
          fulfill.mutate(fulfillment.id, { onSuccess: () => setConfirmFulfill(false) });
        }}
      />

      <ConfirmDialog
        open={confirmCancel}
        onOpenChange={setConfirmCancel}
        title="Cancel fulfillment"
        description={
          <>
            Cancel{' '}
            <span className="text-foreground font-medium">
              {fulfillment.fulfillment_number}
            </span>
            ? This action cannot be undone.
          </>
        }
        confirmLabel="Cancel Fulfillment"
        variant="destructive"
        loading={cancel.isPending}
        onConfirm={() => {
          cancel.mutate(fulfillment.id, { onSuccess: () => setConfirmCancel(false) });
        }}
      />
    </div>
  );
}
