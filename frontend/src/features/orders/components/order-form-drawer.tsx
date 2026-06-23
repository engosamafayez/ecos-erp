import { useEffect, useState } from 'react';
import axios from 'axios';
import { zodResolver } from '@hookform/resolvers/zod';
import { useForm } from 'react-hook-form';

import { EntityDrawer, EntityForm } from '@/components/crud';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { OrderHeaderFields } from '@/features/orders/components/order-header-fields';
import { OrderLinesEditor } from '@/features/orders/components/order-lines-editor';
import { OrderTotalsLive } from '@/features/orders/components/order-totals-live';
import {
  orderSchema,
  toFormValues,
  toPayload,
  type OrderFormValues,
} from '@/features/orders/components/order-form-schema';
import { useCreateOrder, useUpdateOrder } from '@/features/orders/hooks/use-orders';
import type { Order } from '@/features/orders/types/order';

const FORM_ID = 'order-form';

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  order?: Order | null;
};

function extractMessage(error: unknown): string {
  return axios.isAxiosError(error) && typeof error.response?.data?.message === 'string'
    ? error.response.data.message
    : 'Something went wrong. Please try again.';
}

export function OrderFormDrawer({ open, onOpenChange, order }: Props) {
  const isEdit = Boolean(order);
  const createOrder = useCreateOrder();
  const updateOrder = useUpdateOrder();
  const [serverError, setServerError] = useState<string | null>(null);

  const form = useForm<OrderFormValues>({
    resolver: zodResolver(orderSchema),
    defaultValues: toFormValues(order),
  });

  useEffect(() => {
    if (open) {
      form.reset(toFormValues(order));
    }
  }, [open, order, form]);

  const isPending = createOrder.isPending || updateOrder.isPending;

  const handleOpenChange = (next: boolean) => {
    if (!next) setServerError(null);
    onOpenChange(next);
  };

  const handleSubmit = (values: OrderFormValues) => {
    setServerError(null);
    const payload = toPayload(values);
    const handlers = {
      onSuccess: () => handleOpenChange(false),
      onError: (error: unknown) => setServerError(extractMessage(error)),
    };

    if (isEdit && order) {
      updateOrder.mutate({ id: order.id, payload }, handlers);
    } else {
      createOrder.mutate(payload, handlers);
    }
  };

  return (
    <EntityDrawer
      open={open}
      onOpenChange={handleOpenChange}
      title={isEdit ? 'Edit Order' : 'Create Order'}
      description={
        isEdit ? 'Update order details and line items.' : 'Create a new commerce order.'
      }
      footer={
        <>
          <Button type="button" variant="outline" onClick={() => handleOpenChange(false)}>
            Cancel
          </Button>
          <Button type="submit" form={FORM_ID} disabled={isPending}>
            {isPending ? 'Saving…' : isEdit ? 'Save changes' : 'Create order'}
          </Button>
        </>
      }
    >
      {serverError ? (
        <Alert variant="destructive" className="mb-4">
          <AlertTitle>Unable to save</AlertTitle>
          <AlertDescription>{serverError}</AlertDescription>
        </Alert>
      ) : null}

      <EntityForm form={form} id={FORM_ID} onSubmit={handleSubmit} className="flex flex-col gap-4">
        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-base">Order Details</CardTitle>
          </CardHeader>
          <CardContent>
            <OrderHeaderFields />
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-base">Line Items</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            <OrderLinesEditor />
            <OrderTotalsLive />
          </CardContent>
        </Card>
      </EntityForm>
    </EntityDrawer>
  );
}
