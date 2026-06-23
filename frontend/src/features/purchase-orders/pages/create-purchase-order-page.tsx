import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { zodResolver } from '@hookform/resolvers/zod';
import axios from 'axios';
import { useForm } from 'react-hook-form';

import { EntityForm, PageHeader } from '@/components/crud';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { PurchaseOrderHeaderFields } from '@/features/purchase-orders/components/purchase-order-header-fields';
import { PurchaseOrderLinesEditor } from '@/features/purchase-orders/components/purchase-order-lines-editor';
import { PurchaseOrderTotalsLive } from '@/features/purchase-orders/components/purchase-order-totals-live';
import {
  purchaseOrderSchema,
  toFormValues,
  toPayload,
  type PurchaseOrderFormValues,
} from '@/features/purchase-orders/components/purchase-order-form-schema';
import { useCreatePurchaseOrder } from '@/features/purchase-orders/hooks/use-purchase-orders';
import { ROUTES } from '@/router/routes';

function extractMessage(error: unknown): string {
  return axios.isAxiosError(error) && typeof error.response?.data?.message === 'string'
    ? error.response.data.message
    : 'Something went wrong. Please try again.';
}

const FORM_ID = 'create-po-form';

export function CreatePurchaseOrderPage() {
  const navigate = useNavigate();
  const createPO = useCreatePurchaseOrder();
  const [serverError, setServerError] = useState<string | null>(null);

  const form = useForm<PurchaseOrderFormValues>({
    resolver: zodResolver(purchaseOrderSchema),
    defaultValues: toFormValues(null),
  });

  const handleSubmit = (values: PurchaseOrderFormValues) => {
    setServerError(null);
    createPO.mutate(toPayload(values), {
      onSuccess: (po) => navigate(`${ROUTES.purchaseOrders}/${po.id}`),
      onError: (error) => setServerError(extractMessage(error)),
    });
  };

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="New Purchase Order"
        subtitle="Create a draft purchase order."
        breadcrumbs={[
          { label: 'Home', to: ROUTES.dashboard },
          { label: 'Purchase Orders', to: ROUTES.purchaseOrders },
          { label: 'New' },
        ]}
        actions={
          <>
            <Button variant="outline" onClick={() => navigate(ROUTES.purchaseOrders)}>
              Cancel
            </Button>
            <Button type="submit" form={FORM_ID} disabled={createPO.isPending}>
              {createPO.isPending ? 'Creating…' : 'Create Order'}
            </Button>
          </>
        }
      />

      {serverError ? (
        <Alert variant="destructive">
          <AlertTitle>Unable to create order</AlertTitle>
          <AlertDescription>{serverError}</AlertDescription>
        </Alert>
      ) : null}

      <EntityForm form={form} id={FORM_ID} onSubmit={handleSubmit} className="flex flex-col gap-6">
        <Card>
          <CardHeader>
            <CardTitle>Order Details</CardTitle>
          </CardHeader>
          <CardContent>
            <PurchaseOrderHeaderFields />
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Line Items</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            <PurchaseOrderLinesEditor />
            <PurchaseOrderTotalsLive />
          </CardContent>
        </Card>
      </EntityForm>
    </div>
  );
}
