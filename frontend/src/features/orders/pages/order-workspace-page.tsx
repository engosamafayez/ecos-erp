import { useState, useMemo } from 'react';
import { useNavigate, useParams, useLocation } from 'react-router-dom';
import {
  Controller,
  FormProvider,
  useFieldArray,
  useForm,
  useFormContext,
  useWatch,
} from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import axios from 'axios';
import { ArrowLeft, Pencil, Plus, Trash2 } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useQuery } from '@tanstack/react-query';

import { FormField, PageHeader } from '@/components/crud';
import { Combobox } from '@/components/crud/combobox';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { OrderStatusBadge } from '@/features/orders/components/order-status-badge';
import {
  orderSchema,
  toFormValues,
  toPayload,
  type OrderFormValues,
} from '@/features/orders/components/order-form-schema';
import { useOrderQuery, useCreateOrder, useUpdateOrder } from '@/features/orders/hooks/use-orders';
import { useCustomerOptions } from '@/features/orders/hooks/use-customer-options';
import { useChannelOptions } from '@/features/product-mappings/hooks/use-channel-options';
import { productsService } from '@/features/products/services/products-service';
import type { Order, OrderStatus } from '@/features/orders/types/order';
import type { Product } from '@/features/products/types/product';
import { ROUTES } from '@/router/routes';

const FORM_ID = 'order-workspace-form';

const STATUS_OPTIONS: { value: OrderStatus; labelKey: string }[] = [
  { value: 'pending', labelKey: 'status.pending' },
  { value: 'processing', labelKey: 'status.processing' },
  { value: 'completed', labelKey: 'status.completed' },
  { value: 'cancelled', labelKey: 'status.cancelled' },
];

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

function extractMessage(error: unknown): string {
  return axios.isAxiosError(error) && typeof error.response?.data?.message === 'string'
    ? error.response.data.message
    : 'Something went wrong. Please try again.';
}

// ─────────────────────────────────────────────────────────────────────────────
// Summary card — view mode
// ─────────────────────────────────────────────────────────────────────────────

function SummaryRows({
  subtotal,
  shippingTotal,
  discountTotal,
  total,
}: {
  subtotal: number;
  shippingTotal: number;
  discountTotal: number;
  total: number;
}) {
  const { t } = useTranslation('orders');
  return (
    <div className="flex flex-col gap-2 text-sm">
      <div className="flex justify-between gap-3">
        <span className="text-muted-foreground">{t('detail.subtotal')}</span>
        <span className="font-medium tabular-nums">{fmt(subtotal)}</span>
      </div>
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
  return (
    <Card className="lg:sticky lg:top-6">
      <CardHeader className="pb-3">
        <CardTitle className="text-base">{t('workspace.summary')}</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <SummaryRows
          subtotal={order.subtotal}
          shippingTotal={order.shipping_total}
          discountTotal={order.discount_total}
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
// Summary card — form mode (reads live from form context)
// ─────────────────────────────────────────────────────────────────────────────

function FormSummaryCard() {
  const { t } = useTranslation('orders');
  const lines = useWatch<OrderFormValues, 'lines'>({ name: 'lines' });
  const subtotal = (lines ?? []).reduce(
    (sum, l) => sum + Number(l.quantity || 0) * Number(l.unit_price || 0),
    0,
  );
  return (
    <Card className="lg:sticky lg:top-6">
      <CardHeader className="pb-3">
        <CardTitle className="text-base">{t('workspace.summary')}</CardTitle>
      </CardHeader>
      <CardContent>
        <SummaryRows subtotal={subtotal} shippingTotal={0} discountTotal={0} total={subtotal} />
      </CardContent>
    </Card>
  );
}

// ─────────────────────────────────────────────────────────────────────────────
// Product lines — form mode
// ─────────────────────────────────────────────────────────────────────────────

type LineError = {
  product_id?: { message?: string };
  quantity?: { message?: string };
  unit_price?: { message?: string };
};

function fieldError(errors: LineError | undefined, field: keyof LineError): string | undefined {
  const e = errors?.[field];
  return typeof e?.message === 'string' ? e.message : undefined;
}

function FormProductLines({ productMap }: { productMap: Map<string, Product> }) {
  const { t } = useTranslation('orders');
  const {
    register,
    control,
    setValue,
    watch,
    formState: { errors },
  } = useFormContext<OrderFormValues>();

  const { fields, append, remove } = useFieldArray({ control, name: 'lines' });

  const productOptions = useMemo(
    () =>
      Array.from(productMap.values()).map((p) => ({ value: p.id, label: `${p.sku} – ${p.name}` })),
    [productMap],
  );

  const lines = watch('lines');
  const lineErrors = errors.lines as LineError[] | undefined;

  return (
    <div className="flex flex-col gap-4">
      {typeof errors.lines?.message === 'string' && (
        <p className="text-destructive text-xs">{errors.lines.message}</p>
      )}

      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="text-muted-foreground border-b text-left">
              <th className="w-12 pb-2 pr-3 font-medium">{t('detail.image')}</th>
              <th className="pb-2 pr-3 font-medium">{t('detail.product')}</th>
              <th className="w-28 pb-2 pr-3 font-medium">{t('detail.qty')}</th>
              <th className="w-32 pb-2 pr-3 font-medium">{t('detail.unitPrice')}</th>
              <th className="w-32 pb-2 pr-3 text-right font-medium">{t('detail.lineTotal')}</th>
              <th className="w-10 pb-2" />
            </tr>
          </thead>
          <tbody className="divide-y">
            {fields.map((field, index) => {
              const qty = Number(lines[index]?.quantity ?? 0);
              const price = Number(lines[index]?.unit_price ?? 0);
              const lineTotal = qty * price;
              const errs = lineErrors?.[index];
              const selectedProduct = productMap.get(lines[index]?.product_id ?? '');

              return (
                <tr key={field.id}>
                  <td className="py-2 pr-3">
                    {selectedProduct?.image_url ? (
                      <img
                        src={selectedProduct.image_url}
                        alt={selectedProduct.name}
                        className="size-10 rounded object-cover"
                      />
                    ) : (
                      <div className="bg-muted size-10 rounded" />
                    )}
                  </td>
                  <td className="py-2 pr-3">
                    <Combobox
                      options={productOptions}
                      value={lines[index]?.product_id ?? null}
                      onChange={(v) =>
                        setValue(`lines.${index}.product_id`, v, { shouldValidate: true })
                      }
                      placeholder={t('workspace.selectProduct')}
                    />
                    {fieldError(errs, 'product_id') && (
                      <p className="text-destructive mt-1 text-xs">
                        {fieldError(errs, 'product_id')}
                      </p>
                    )}
                  </td>
                  <td className="py-2 pr-3">
                    <Input
                      type="number"
                      min="0.0001"
                      step="any"
                      {...register(`lines.${index}.quantity`)}
                    />
                    {fieldError(errs, 'quantity') && (
                      <p className="text-destructive mt-1 text-xs">
                        {fieldError(errs, 'quantity')}
                      </p>
                    )}
                  </td>
                  <td className="py-2 pr-3">
                    <Input
                      type="number"
                      min="0"
                      step="any"
                      {...register(`lines.${index}.unit_price`)}
                    />
                    {fieldError(errs, 'unit_price') && (
                      <p className="text-destructive mt-1 text-xs">
                        {fieldError(errs, 'unit_price')}
                      </p>
                    )}
                  </td>
                  <td className="py-2 pr-3 text-right font-medium tabular-nums">{fmt(lineTotal)}</td>
                  <td className="py-2">
                    <Button
                      type="button"
                      size="icon"
                      variant="ghost"
                      className="text-destructive size-8"
                      onClick={() => remove(index)}
                      disabled={fields.length === 1}
                    >
                      <Trash2 className="size-4" />
                    </Button>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      <div>
        <Button
          type="button"
          size="sm"
          variant="outline"
          onClick={() => append({ product_id: '', quantity: '', unit_price: '' })}
        >
          <Plus className="size-3.5" />
          {t('workspace.addLine')}
        </Button>
      </div>
    </div>
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
// FORM workspace — create / edit
// ─────────────────────────────────────────────────────────────────────────────

function FormWorkspace({ mode, order }: { mode: 'create' | 'edit'; order?: Order }) {
  const { t } = useTranslation('orders');
  const { t: tCommon } = useTranslation('common');
  const navigate = useNavigate();
  const isEdit = mode === 'edit';

  const createOrder = useCreateOrder();
  const updateOrder = useUpdateOrder();
  const [serverError, setServerError] = useState<string | null>(null);

  const { data: customerOptions = [], isLoading: loadingCustomers } = useCustomerOptions();
  const { data: channelOptions = [], isLoading: loadingChannels } = useChannelOptions();

  const { data: allProducts = [] } = useQuery({
    queryKey: ['products-all-rich'],
    queryFn: () => productsService.list({ per_page: 500, status: 'active' }).then((r) => r.items),
    staleTime: 60_000,
  });
  const productMap = useMemo(
    () => new Map(allProducts.map((p) => [p.id, p])),
    [allProducts],
  );

  const form = useForm<OrderFormValues>({
    resolver: zodResolver(orderSchema),
    defaultValues: toFormValues(order),
  });

  const isPending = createOrder.isPending || updateOrder.isPending;

  const handleCancel = () => {
    if (isEdit && order) {
      navigate(`${ROUTES.orders}/${order.id}`);
    } else {
      navigate(ROUTES.orders);
    }
  };

  const handleSubmit = (values: OrderFormValues) => {
    setServerError(null);
    const payload = toPayload(values);
    if (isEdit && order) {
      updateOrder.mutate(
        { id: order.id, payload },
        {
          onSuccess: () => navigate(`${ROUTES.orders}/${order.id}`),
          onError: (err) => setServerError(extractMessage(err)),
        },
      );
    } else {
      createOrder.mutate(payload, {
        onSuccess: (created) => navigate(`${ROUTES.orders}/${created.id}`),
        onError: (err) => setServerError(extractMessage(err)),
      });
    }
  };

  const shippingName = isEdit && order
    ? [order.shipping_first_name, order.shipping_last_name].filter(Boolean).join(' ') || null
    : null;
  const hasShipping =
    isEdit &&
    order &&
    (shippingName || order.shipping_company || order.shipping_address_1 || order.shipping_city);
  const hasPayment =
    isEdit &&
    order &&
    (order.payment_method_title || order.payment_method || order.transaction_id || order.date_paid);

  const breadcrumbs = [
    { label: tCommon('home'), to: ROUTES.dashboard },
    { label: t('title'), to: ROUTES.orders },
    ...(isEdit && order
      ? [{ label: order.order_number, to: `${ROUTES.orders}/${order.id}` }]
      : []),
    { label: isEdit ? t('workspace.editTitle') : t('workspace.newTitle') },
  ];

  return (
    <FormProvider {...form}>
      <div className="flex flex-col gap-6">
        <PageHeader
          title={isEdit ? t('workspace.editTitle') : t('workspace.newTitle')}
          subtitle={isEdit ? order?.order_number : undefined}
          breadcrumbs={breadcrumbs}
          actions={
            <div className="flex items-center gap-2">
              <Button type="button" variant="outline" onClick={handleCancel}>
                {t('workspace.cancel')}
              </Button>
              <Button type="submit" form={FORM_ID} disabled={isPending}>
                {isPending
                  ? t(isEdit ? 'workspace.saving' : 'workspace.creating')
                  : t(isEdit ? 'workspace.save' : 'workspace.create')}
              </Button>
            </div>
          }
        />

        <form id={FORM_ID} onSubmit={form.handleSubmit(handleSubmit)}>
          {serverError && (
            <Alert variant="destructive" className="mb-6">
              <AlertTitle>{t('workspace.errorTitle')}</AlertTitle>
              <AlertDescription>{serverError}</AlertDescription>
            </Alert>
          )}

          <div className="grid gap-6 lg:grid-cols-[1fr_340px]">
            <div className="flex min-w-0 flex-col gap-6">
              {/* Order Information */}
              <WorkspaceCard title={t('detail.orderDetails')}>
                <div className="grid gap-4 sm:grid-cols-2">
                  <div className="sm:col-span-2">
                    <FormField name="channel_id" label={t('detail.channel')}>
                      <Controller
                        control={form.control}
                        name="channel_id"
                        render={({ field }) => (
                          <Combobox
                            options={channelOptions}
                            value={field.value ?? null}
                            onChange={field.onChange}
                            placeholder={t('workspace.selectChannel')}
                            loading={loadingChannels}
                          />
                        )}
                      />
                    </FormField>
                  </div>

                  <FormField name="order_date" label={t('detail.orderDate')} required>
                    <Input type="date" {...form.register('order_date')} />
                  </FormField>

                  <FormField name="status" label={t('detail.status')} required>
                    <select
                      {...form.register('status')}
                      className="border-input h-9 w-full rounded-md border bg-transparent px-3 text-sm shadow-xs"
                    >
                      {STATUS_OPTIONS.map((s) => (
                        <option key={s.value} value={s.value}>
                          {t(s.labelKey)}
                        </option>
                      ))}
                    </select>
                  </FormField>

                  <div className="sm:col-span-2">
                    <FormField name="external_order_id" label={t('detail.externalOrderId')}>
                      <Input
                        placeholder={t('workspace.externalIdPlaceholder')}
                        {...form.register('external_order_id')}
                      />
                    </FormField>
                  </div>
                </div>
              </WorkspaceCard>

              {/* Customer */}
              <WorkspaceCard title={t('detail.customerInformation')}>
                <FormField name="customer_id" label={t('detail.customer')} required>
                  <Controller
                    control={form.control}
                    name="customer_id"
                    render={({ field }) => (
                      <Combobox
                        options={customerOptions}
                        value={field.value || null}
                        onChange={field.onChange}
                        placeholder={t('workspace.selectCustomer')}
                        loading={loadingCustomers}
                      />
                    )}
                  />
                </FormField>
              </WorkspaceCard>

              {/* Shipping — read-only, only shown in edit mode when data exists */}
              {hasShipping && order && (
                <WorkspaceCard title={t('detail.shippingInformation')}>
                  <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {shippingName && (
                      <LabelValue label={t('detail.shippingName')} value={shippingName} />
                    )}
                    {order.shipping_company && (
                      <LabelValue
                        label={t('detail.shippingCompany')}
                        value={order.shipping_company}
                      />
                    )}
                    {order.shipping_method && (
                      <LabelValue label={t('detail.shippingMethod')} value={order.shipping_method} />
                    )}
                    {order.shipping_address_1 && (
                      <LabelValue
                        label={t('detail.shippingAddress1')}
                        value={order.shipping_address_1}
                      />
                    )}
                    {order.shipping_address_2 && (
                      <LabelValue
                        label={t('detail.shippingAddress2')}
                        value={order.shipping_address_2}
                      />
                    )}
                    {order.shipping_city && (
                      <LabelValue label={t('detail.shippingCity')} value={order.shipping_city} />
                    )}
                    {order.shipping_state && (
                      <LabelValue label={t('detail.shippingState')} value={order.shipping_state} />
                    )}
                    {order.shipping_postcode && (
                      <LabelValue
                        label={t('detail.shippingPostcode')}
                        value={order.shipping_postcode}
                      />
                    )}
                    {order.shipping_country && (
                      <LabelValue
                        label={t('detail.shippingCountry')}
                        value={order.shipping_country}
                      />
                    )}
                  </div>
                </WorkspaceCard>
              )}

              {/* Payment — read-only, only shown in edit mode when data exists */}
              {hasPayment && order && (
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

              {/* Products */}
              <WorkspaceCard title={t('workspace.products')}>
                <FormProductLines productMap={productMap} />
              </WorkspaceCard>

              {/* Notes */}
              <WorkspaceCard title={t('detail.notes')}>
                <div className="flex flex-col gap-4">
                  <FormField name="notes" label={t('detail.notes')}>
                    <textarea
                      rows={3}
                      placeholder={t('workspace.notesPlaceholder')}
                      className="border-input placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50"
                      {...form.register('notes')}
                    />
                  </FormField>
                  {isEdit && order?.customer_note && (
                    <div className="border-t pt-4">
                      <span className="text-muted-foreground text-xs">{t('detail.customerNote')}</span>
                      <p className="bg-muted/40 mt-1 rounded-md border px-3 py-2 text-sm italic">
                        {order.customer_note}
                      </p>
                    </div>
                  )}
                </div>
              </WorkspaceCard>
            </div>

            {/* Right column: live summary (inside FormProvider so useWatch works) */}
            <div>
              <FormSummaryCard />
            </div>
          </div>
        </form>
      </div>
    </FormProvider>
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
  return <FormWorkspace mode={mode} order={mode === 'edit' ? order : undefined} />;
}
