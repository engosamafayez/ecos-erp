import { useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
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
import { useQuery } from '@tanstack/react-query';
import {
  AlertTriangle,
  ArrowLeft,
  Building2,
  CheckCircle2,
  ChevronDown,
  ChevronRight,
  ChevronUp,
  Loader2,
  Lock,
  Plus,
  Trash2,
  Unlock,
} from 'lucide-react';

import { cn } from '@/lib/utils';
import { FormField, PageHeader } from '@/components/crud';
import { Combobox } from '@/components/crud/combobox';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { OrderCustomerAlerts } from '@/features/orders/components/order-customer-alerts';
import { OrderCustomerIntelligencePanel } from '@/features/orders/components/order-customer-intelligence-panel';
import { OrderCustomerLookupField } from '@/features/orders/components/order-customer-lookup-field';
import { OrderPaymentSection } from '@/features/orders/components/order-payment-section';
import { BrandConfigHealthCard } from '@/features/orders/components/brand-config-health-card';
import { ProductBrowser } from '@/features/orders/components/product-browser';
import {
  manualOrderSchema,
  toManualPayload,
  toEditPayload,
  type ManualOrderFormValues,
  type ManualOrderLineFormValues,
} from '@/features/orders/components/order-form-schema';
import { useCreateManualOrder, useUpdateOrder } from '@/features/orders/hooks/use-orders';
import { useProductPricing } from '@/features/orders/hooks/use-product-pricing';
import { useBrandOptions } from '@/features/brands/hooks/use-brand-options';
import {
  useBrandConfigHealth,
  useBrandDeliveryGeography,
  useBrandDeliveryWindows,
} from '@/features/brands/hooks/use-brand-delivery';
import { channelsService } from '@/features/channels/services/channels-service';
import { productsService } from '@/features/products/services/products-service';
import { useCompaniesQuery } from '@/features/companies/hooks/use-companies';
import type { CustomerLookupResult, Order } from '@/features/orders/types/order';
import type { Product } from '@/features/products/types/product';
import { getMediaUrl } from '@/lib/media';
import { ROUTES } from '@/router/routes';

const FORM_ID = 'manual-order-form';

const STATUS_OPTIONS = [
  { value: 'in_progress',      label: 'In Progress' },
  { value: 'pending',          label: 'Pending' },
  { value: 'awaiting_payment', label: 'Awaiting Payment' },
  { value: 'confirm_order',    label: 'Confirm Order' },
] as const;

// ── Inline hooks ──────────────────────────────────────────────────────────────

function useChannelsByBrand(brandId: string | null) {
  return useQuery({
    queryKey: ['channels-by-brand', brandId ?? ''],
    queryFn: () => channelsService.list({ brand_id: brandId!, per_page: 100, status: 'active' }),
    enabled: Boolean(brandId),
    staleTime: 5 * 60 * 1000,
    select: (d) => d.items.map((c) => ({ value: c.id, label: c.name })),
  });
}

function useFinishedProducts(channelId: string | null) {
  return useQuery({
    queryKey: ['products-fg-order', channelId ?? ''],
    queryFn: () =>
      productsService
        .list({ channel_id: channelId!, product_type: 'finished_good', per_page: 500, status: 'active' })
        .then((r) => r.items),
    enabled: Boolean(channelId),
    staleTime: 60_000,
  });
}

function useRawMaterials() {
  return useQuery({
    queryKey: ['products-rm-order'],
    queryFn: () =>
      productsService
        .list({ product_type: 'raw_material', per_page: 500, status: 'active' })
        .then((r) => r.items),
    staleTime: 60_000,
  });
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function fmt(n: number) {
  return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function extractMessage(error: unknown): string {
  return axios.isAxiosError(error) && typeof error.response?.data?.message === 'string'
    ? error.response.data.message
    : 'Something went wrong. Please try again.';
}

function resolvedProductPrice(p: Product): number | null {
  return p.sale_price ?? p.regular_price ?? null;
}

// ── Progress Indicator ────────────────────────────────────────────────────────

function ProgressIndicator({ steps }: { steps: { label: string; done: boolean }[] }) {
  const allDone = steps.every((s) => s.done);
  return (
    <div className="flex items-center gap-1 overflow-x-auto rounded-lg border bg-muted/30 px-4 py-2.5">
      {steps.map((step, i) => {
        const isLast = i === steps.length - 1;
        const isReady = isLast && allDone;
        return (
          <div key={i} className="flex items-center gap-1 shrink-0">
            <div
              className={cn(
                'flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium transition-colors',
                isReady
                  ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-400'
                  : step.done
                  ? 'bg-primary/10 text-primary'
                  : 'text-muted-foreground',
              )}
            >
              {step.done ? (
                <CheckCircle2 className="size-3 shrink-0" />
              ) : (
                <span className="flex size-3.5 shrink-0 items-center justify-center rounded-full border text-[10px]">
                  {i + 1}
                </span>
              )}
              {step.label}
            </div>
            {!isLast && <ChevronRight className="size-3 text-muted-foreground shrink-0" />}
          </div>
        );
      })}
    </div>
  );
}

// ── Live Financial Summary ─────────────────────────────────────────────────────

function LiveFinancialSummary() {
  const lines = useWatch<ManualOrderFormValues, 'lines'>({ name: 'lines' });
  const discountAmountStr = useWatch<ManualOrderFormValues, 'discount_amount'>({ name: 'discount_amount' });
  const discountType = useWatch<ManualOrderFormValues, 'discount_type'>({ name: 'discount_type' });
  const shippingCostStr = useWatch<ManualOrderFormValues, 'shipping_cost'>({ name: 'shipping_cost' });
  const depositAmountStr = useWatch<ManualOrderFormValues, 'deposit_amount'>({ name: 'deposit_amount' });

  const subtotal = (lines ?? []).reduce(
    (sum, l) => sum + Number(l.quantity || 0) * Number(l.unit_price || 0),
    0,
  );
  const discountRaw = Number(discountAmountStr || 0);
  const discount = discountType === 'percentage' ? (subtotal * discountRaw) / 100 : discountRaw;
  const shipping = Number(shippingCostStr || 0);
  const deposit = Number(depositAmountStr || 0);
  const grandTotal = Math.max(0, subtotal - discount + shipping);
  const remaining = Math.max(0, grandTotal - deposit);

  return (
    <Card className="sticky top-4">
      <CardHeader className="pb-2">
        <CardTitle className="text-sm font-semibold uppercase tracking-wider text-muted-foreground">
          Order Summary
        </CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-2 text-sm">
        <div className="flex justify-between gap-3">
          <span className="text-muted-foreground">Subtotal</span>
          <span className="font-medium tabular-nums">{fmt(subtotal)}</span>
        </div>
        {discount > 0 && (
          <div className="flex justify-between gap-3">
            <span className="text-muted-foreground">Discount</span>
            <span className="font-medium tabular-nums text-emerald-600">−{fmt(discount)}</span>
          </div>
        )}
        {shipping > 0 && (
          <div className="flex justify-between gap-3">
            <span className="text-muted-foreground">Shipping</span>
            <span className="font-medium tabular-nums">{fmt(shipping)}</span>
          </div>
        )}
        <div className="border-t pt-2">
          <div className="flex justify-between gap-3 text-base font-semibold">
            <span>Grand Total</span>
            <span className="tabular-nums">{fmt(grandTotal)}</span>
          </div>
        </div>
        {deposit > 0 && (
          <>
            <div className="flex justify-between gap-3">
              <span className="text-muted-foreground">Deposit Paid</span>
              <span className="font-medium tabular-nums text-emerald-600">−{fmt(deposit)}</span>
            </div>
            <div className="border-t pt-2">
              <div className="flex justify-between gap-3 font-semibold">
                <span>Remaining Balance</span>
                <span className={cn('tabular-nums', remaining > 0 ? 'text-amber-600' : 'text-emerald-600')}>
                  {fmt(remaining)}
                </span>
              </div>
            </div>
          </>
        )}
      </CardContent>
    </Card>
  );
}

// ── Order Line Row ────────────────────────────────────────────────────────────

type LineError = {
  product_id?: { message?: string };
  quantity?: { message?: string };
  unit_price?: { message?: string };
};

function fieldError(errors: LineError | undefined, field: keyof LineError) {
  const e = errors?.[field];
  return typeof e?.message === 'string' ? e.message : undefined;
}

function ManualLineRow({
  index,
  productMap,
  onRemove,
  canRemove,
  errors: errs,
}: {
  index: number;
  productMap: Map<string, Product>;
  onRemove: () => void;
  canRemove: boolean;
  errors: LineError | undefined;
}) {
  const { register, setValue, watch } = useFormContext<ManualOrderFormValues>();

  const lines = watch('lines');
  const line = lines[index];
  const productId = line?.product_id ?? '';
  const qty = Number(line?.quantity ?? 0);
  const price = Number(line?.unit_price ?? 0);
  const selectedProduct = productMap.get(productId);

  const { data: pricing } = useProductPricing(productId || null);

  const prevProductIdRef = useRef<string>('');

  useEffect(() => {
    if (productId !== prevProductIdRef.current) {
      prevProductIdRef.current = productId;
      if (!productId) return;
      setValue(`lines.${index}.unit_price`, '', { shouldValidate: false });
    }
  }, [productId]); // eslint-disable-line react-hooks/exhaustive-deps

  // Auto-fill approved price when pricing resolves
  useEffect(() => {
    if (!productId || pricing?.approved_price == null) return;
    const current = line?.unit_price ?? '';
    if (current === '' || Number(current) === 0) {
      setValue(`lines.${index}.unit_price`, String(pricing.approved_price), { shouldValidate: false });
    }
  }, [pricing?.approved_price, productId]); // eslint-disable-line react-hooks/exhaustive-deps

  const isPriceLocked = pricing?.approved_price != null;
  const imgUrl = getMediaUrl(selectedProduct?.image_url);

  return (
    <tr>
      <td className="py-2 pr-3 align-middle">
        <div className="flex items-center gap-2">
          {imgUrl ? (
            <img src={imgUrl} alt={selectedProduct!.name} className="size-8 rounded object-cover shrink-0" />
          ) : (
            <div className="size-8 rounded bg-muted shrink-0" />
          )}
          <div className="min-w-0">
            <p className="truncate text-sm font-medium">{selectedProduct?.name ?? productId}</p>
            <p className="font-mono text-xs text-muted-foreground">{selectedProduct?.sku}</p>
            {pricing?.has_pending_review && (
              <span className="inline-flex items-center gap-0.5 text-[10px] text-amber-600">
                <AlertTriangle className="size-2.5" />
                Price Review Pending
              </span>
            )}
          </div>
        </div>
      </td>

      <td className="w-24 py-2 pr-3 align-middle">
        <Input type="number" min="0.0001" step="any" className="h-8 text-sm" {...register(`lines.${index}.quantity`)} />
        {fieldError(errs, 'quantity') && (
          <p className="text-destructive mt-0.5 text-xs">{fieldError(errs, 'quantity')}</p>
        )}
      </td>

      {/* Price: locked to approved — read-only display */}
      <td className="w-28 py-2 pr-3 align-middle">
        <input type="hidden" {...register(`lines.${index}.unit_price`)} />
        <div className="flex h-8 items-center gap-1 rounded-md border bg-muted/50 px-2.5 text-sm font-medium tabular-nums">
          {isPriceLocked && <Lock className="size-3 shrink-0 text-muted-foreground" />}
          <span className={!price ? 'italic text-muted-foreground' : ''}>
            {price > 0 ? fmt(price) : '—'}
          </span>
        </div>
      </td>

      <td className="w-24 py-2 pr-3 align-middle text-right font-medium tabular-nums text-sm">
        {fmt(qty * price)}
      </td>

      <td className="w-10 py-2 align-middle">
        <Button
          type="button"
          size="icon"
          variant="ghost"
          className="size-7 text-destructive"
          onClick={onRemove}
          disabled={!canRemove}
        >
          <Trash2 className="size-3.5" />
        </Button>
      </td>
    </tr>
  );
}

// ── Products Section ──────────────────────────────────────────────────────────

function ManualOrderProductsSection({
  finishedProducts,
  rawMaterials,
  channelSelected,
  loadingProducts,
}: {
  finishedProducts: Product[];
  rawMaterials: Product[];
  channelSelected: boolean;
  loadingProducts: boolean;
}) {
  const {
    control,
    setValue,
    formState: { errors },
  } = useFormContext<ManualOrderFormValues>();
  const { fields, append, remove } = useFieldArray({ control, name: 'lines' });
  const lines = useWatch<ManualOrderFormValues, 'lines'>({ name: 'lines' });
  const [showRm, setShowRm] = useState(false);
  const [showBrowser, setShowBrowser] = useState(true);

  const fgProductMap = useMemo(() => new Map(finishedProducts.map((p) => [p.id, p])), [finishedProducts]);
  const rmProductMap = useMemo(() => new Map(rawMaterials.map((p) => [p.id, p])), [rawMaterials]);
  const allProductMap = useMemo(() => new Map([...fgProductMap, ...rmProductMap]), [fgProductMap, rmProductMap]);

  const lineErrors = errors.lines as LineError[] | undefined;

  const fgIndices: number[] = [];
  const rmIndices: number[] = [];
  fields.forEach((_, i) => {
    const pid = (lines ?? [])[i]?.product_id;
    if (pid && rmProductMap.has(pid)) rmIndices.push(i);
    else fgIndices.push(i);
  });

  // Filter out empty placeholder lines from the count
  const filledFgIndices = fgIndices.filter((i) => Boolean((lines ?? [])[i]?.product_id));
  const filledRmIndices = rmIndices.filter((i) => Boolean((lines ?? [])[i]?.product_id));

  // PART 5 — Empty state before channel selected
  if (!channelSelected) {
    return (
      <div className="flex items-center justify-center rounded-lg border border-dashed py-8 text-sm text-muted-foreground">
        Select a Sales Channel above to load available products.
      </div>
    );
  }

  const handleAddProduct = (product: Product) => {
    const existingIdx = (lines ?? []).findIndex((l) => l.product_id === product.id);
    if (existingIdx >= 0) {
      const current = Number((lines ?? [])[existingIdx]?.quantity ?? 0);
      setValue(`lines.${existingIdx}.quantity`, String(current + 1), { shouldValidate: false });
      return;
    }
    const price = resolvedProductPrice(product);
    append({
      product_id: product.id,
      quantity: '1',
      unit_price: price != null ? String(price) : '',
    } as ManualOrderLineFormValues);
    // Collapse browser after adding if there are already products
    if (filledFgIndices.length >= 2) setShowBrowser(false);
  };

  return (
    <div className="flex flex-col gap-4">
      {typeof errors.lines?.message === 'string' && (
        <p className="text-destructive text-xs">{errors.lines.message}</p>
      )}

      {/* PART 3 — Product Browser (collapsible) */}
      <div className="rounded-lg border bg-muted/20">
        <button
          type="button"
          className="flex w-full items-center justify-between px-4 py-3 text-sm font-medium hover:bg-muted/40 transition-colors rounded-lg"
          onClick={() => setShowBrowser((v) => !v)}
        >
          <span className="flex items-center gap-2">
            Browse Finished Products
            {finishedProducts.length > 0 && (
              <Badge variant="secondary" className="h-4 px-1.5 text-[10px]">
                {finishedProducts.length}
              </Badge>
            )}
          </span>
          {showBrowser ? <ChevronUp className="size-4" /> : <ChevronDown className="size-4" />}
        </button>

        {showBrowser && (
          <div className="border-t px-4 pb-4 pt-3">
            <ProductBrowser
              products={finishedProducts}
              onAdd={handleAddProduct}
              isLoading={loadingProducts}
            />
          </div>
        )}
      </div>

      {/* FG Order Lines */}
      {filledFgIndices.length > 0 && (
        <div>
          <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
            Selected Products ({filledFgIndices.length})
          </p>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b text-left text-xs text-muted-foreground">
                  <th className="pb-1.5 pr-3 font-medium">Product</th>
                  <th className="w-24 pb-1.5 pr-3 font-medium">Qty</th>
                  <th className="w-28 pb-1.5 pr-3 font-medium">Price</th>
                  <th className="w-24 pb-1.5 pr-3 text-right font-medium">Total</th>
                  <th className="w-10 pb-1.5" />
                </tr>
              </thead>
              <tbody className="divide-y">
                {fgIndices.map((i) => {
                  const pid = (lines ?? [])[i]?.product_id;
                  if (!pid) return null;
                  return (
                    <ManualLineRow
                      key={fields[i].id}
                      index={i}
                      productMap={allProductMap}
                      onRemove={() => remove(i)}
                      canRemove={fields.length > 1}
                      errors={lineErrors?.[i]}
                    />
                  );
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* Raw Materials (collapsible) */}
      <div className="border-t pt-3">
        <button
          type="button"
          className="flex w-full items-center justify-between text-sm font-medium text-muted-foreground hover:text-foreground transition-colors"
          onClick={() => setShowRm((v) => !v)}
        >
          <span>
            Raw Materials
            {filledRmIndices.length > 0 && (
              <span className="ml-1.5 rounded-full bg-muted px-1.5 py-0.5 text-[10px] font-medium">
                {filledRmIndices.length}
              </span>
            )}
          </span>
          {showRm ? <ChevronUp className="size-4" /> : <ChevronDown className="size-4" />}
        </button>

        {showRm && (
          <div className="mt-3 flex flex-col gap-3">
            <ProductBrowser products={rawMaterials} onAdd={handleAddProduct} />
            {filledRmIndices.length > 0 && (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b text-left text-xs text-muted-foreground">
                      <th className="pb-1.5 pr-3 font-medium">Material</th>
                      <th className="w-24 pb-1.5 pr-3 font-medium">Qty</th>
                      <th className="w-28 pb-1.5 pr-3 font-medium">Price</th>
                      <th className="w-24 pb-1.5 pr-3 text-right font-medium">Total</th>
                      <th className="w-10 pb-1.5" />
                    </tr>
                  </thead>
                  <tbody className="divide-y">
                    {rmIndices.map((i) => {
                      const pid = (lines ?? [])[i]?.product_id;
                      if (!pid) return null;
                      return (
                        <ManualLineRow
                          key={fields[i].id}
                          index={i}
                          productMap={allProductMap}
                          onRemove={() => remove(i)}
                          canRemove={fields.length > 1}
                          errors={lineErrors?.[i]}
                        />
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}
            <Button
              type="button"
              size="sm"
              variant="outline"
              className="w-fit"
              onClick={() => append({ product_id: '', quantity: '1', unit_price: '' } as ManualOrderLineFormValues)}
            >
              <Plus className="size-3.5" />
              Add Raw Material Line
            </Button>
          </div>
        )}
      </div>
    </div>
  );
}

// ── Loading spinner ───────────────────────────────────────────────────────────

function InlineSpinner({ label }: { label: string }) {
  return (
    <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
      <Loader2 className="size-3 animate-spin" />
      {label}
    </span>
  );
}

// ── Main component ─────────────────────────────────────────────────────────────

type Props = {
  mode?: 'create' | 'edit';
  order?: Order;
};

export function ManualOrderFormWorkspace({ mode = 'create', order }: Props) {
  const navigate = useNavigate();
  const createManual = useCreateManualOrder();
  const updateOrder  = useUpdateOrder();
  const isEdit = mode === 'edit';

  const [serverError, setServerError] = useState<string | null>(null);
  const [lookupResult, setLookupResult] = useState<CustomerLookupResult>(null);
  const [isNewCustomer, setIsNewCustomer] = useState(isEdit); // edit starts with customer known
  const [brandId, setBrandId] = useState<string | null>(null);
  const [brandName, setBrandName] = useState<string | undefined>(undefined);
  const [overrideUnlocked, setOverrideUnlocked] = useState(false);

  // PART 1 — Companies
  const { data: companiesResult } = useCompaniesQuery({ per_page: 100, status: 'active' });
  const companyOptions = useMemo(
    () => (companiesResult?.items ?? []).map((c) => ({ value: c.id, label: c.name })),
    [companiesResult],
  );

  const form = useForm<ManualOrderFormValues>({
    resolver: zodResolver(manualOrderSchema),
    defaultValues: isEdit && order
      ? {
          channel_id:   order.channel_id ?? undefined,
          status:       order.status,
          order_date:   order.order_date,
          customer_id:  order.customer_id,
          customer_name: order.customer?.name ?? undefined,
          customer_phone: order.billing_phone ?? undefined,
          notes:        order.notes ?? undefined,
          lines: order.lines.length > 0
            ? order.lines.map((l) => ({
                product_id: l.product_id,
                quantity:   String(l.quantity),
                unit_price: String(l.unit_price),
              }))
            : [{ product_id: '', quantity: '1', unit_price: '' }],
        }
      : {
          status:     'in_progress',
          order_date: new Date().toISOString().slice(0, 10),
          lines:      [{ product_id: '', quantity: '', unit_price: '' }],
        },
  });

  const watchedCompanyId   = form.watch('company_id') ?? '';
  const watchedChannelId   = form.watch('channel_id') ?? '';
  const watchedGovernorate = form.watch('governorate') ?? '';
  const watchedLines       = form.watch('lines');
  const watchedPayment     = form.watch('payment_method_manual');
  const watchedZoneId      = form.watch('delivery_zone_id') ?? '';
  const watchedShipSrc     = form.watch('shipping_cost_source');

  // Data loading
  const { data: brandOptions = [], isLoading: loadingBrands } = useBrandOptions(watchedCompanyId || null);
  const { data: channelOptions = [], isLoading: loadingChannels } = useChannelsByBrand(brandId);
  const { data: fgProducts = [], isFetching: loadingProducts } = useFinishedProducts(watchedChannelId || null);
  const { data: rawMaterials = [] } = useRawMaterials();
  const { data: geography, isFetching: loadingGeo } = useBrandDeliveryGeography(brandId);
  const { data: deliveryWindows = [], isFetching: loadingWindows } = useBrandDeliveryWindows(brandId);

  // PART 1 — Brand configuration health check
  const { data: configHealth, isFetching: loadingHealth } = useBrandConfigHealth(brandId);

  const governorateOptions = useMemo(
    () => (geography?.governorates ?? []).map((g) => ({ value: g.id, label: g.name })),
    [geography],
  );

  const zoneOptions = useMemo(() => {
    if (!watchedGovernorate || !geography) return [];
    const gov = geography.governorates.find((g) => g.id === watchedGovernorate);
    return (gov?.zones ?? []).map((z) => ({ value: z.id, label: z.name, shipping_cost: z.shipping_cost }));
  }, [geography, watchedGovernorate]);

  const windowOptions = useMemo(
    () => deliveryWindows.map((w) => ({ value: w.id, label: w.label })),
    [deliveryWindows],
  );

  // PART 8 — Auto-populate shipping cost when zone changes
  useEffect(() => {
    if (!watchedZoneId || overrideUnlocked) return;
    const zone = zoneOptions.find((z) => z.value === watchedZoneId);
    if (zone?.shipping_cost != null) {
      form.setValue('shipping_cost', String(zone.shipping_cost));
      form.setValue('shipping_cost_source', 'auto');
    }
  }, [watchedZoneId, zoneOptions]); // eslint-disable-line react-hooks/exhaustive-deps

  // ── Cascade handlers ────────────────────────────────────────────────────────

  const resetDownstreamOfBrand = () => {
    form.setValue('channel_id', undefined);
    form.setValue('governorate', undefined);
    form.setValue('delivery_zone_id', undefined);
    form.setValue('delivery_zone', undefined);
    form.setValue('delivery_window_id', undefined);
    form.setValue('delivery_window', undefined);
    form.setValue('shipping_cost', undefined);
    form.setValue('shipping_cost_source', undefined);
    form.setValue('lines', [{ product_id: '', quantity: '', unit_price: '' }]);
    setOverrideUnlocked(false);
  };

  const handleCompanyChange = (value: string | null) => {
    form.setValue('company_id', value ?? '');
    setBrandId(null);
    setBrandName(undefined);
    resetDownstreamOfBrand();
  };

  const handleBrandChange = (value: string | null) => {
    setBrandId(value);
    const opt = brandOptions.find((b) => b.value === value);
    setBrandName(opt?.label);
    resetDownstreamOfBrand();
  };

  const handleChannelChange = (value: string | null) => {
    form.setValue('channel_id', value ?? undefined);
    form.setValue('lines', [{ product_id: '', quantity: '', unit_price: '' }]);
  };

  const handleGovernorateChange = (value: string | null) => {
    form.setValue('governorate', value ?? undefined);
    form.setValue('delivery_zone_id', undefined);
    form.setValue('delivery_zone', undefined);
    form.setValue('shipping_cost', undefined);
    form.setValue('shipping_cost_source', undefined);
    setOverrideUnlocked(false);
  };

  const handleZoneChange = (value: string | null) => {
    form.setValue('delivery_zone_id', value ?? undefined);
    const zone = zoneOptions.find((z) => z.value === value);
    form.setValue('delivery_zone', zone?.label ?? undefined);
  };

  const handleWindowChange = (value: string | null) => {
    form.setValue('delivery_window_id', value ?? undefined);
    const win = deliveryWindows.find((w) => w.id === value);
    form.setValue('delivery_window', win?.label ?? undefined);
  };

  // ── Customer handlers ───────────────────────────────────────────────────────

  const handleCustomerFound = (result: CustomerLookupResult) => {
    setLookupResult(result);
    setIsNewCustomer(false);
    if (result) {
      form.setValue('customer_id', result.customer.id);
      form.setValue('customer_name', result.customer.name);
      form.setValue('customer_phone', result.customer.phone ?? '');
      const defaultAddr = result.addresses.find((a) => a.is_default) ?? result.addresses[0];
      if (defaultAddr) {
        form.setValue('city', defaultAddr.city ?? '');
        form.setValue('area', defaultAddr.area ?? '');
        form.setValue('shipping_address', defaultAddr.address_line ?? '');
        if (defaultAddr.google_maps_lat != null) {
          form.setValue('google_maps_lat', defaultAddr.google_maps_lat);
          form.setValue('google_maps_lng', defaultAddr.google_maps_lng ?? undefined);
        }
      }
    }
  };

  const handleCustomerNotFound = (phone: string) => {
    setLookupResult(null);
    setIsNewCustomer(true);
    form.setValue('customer_id', undefined);
    form.setValue('customer_phone', phone);
  };

  const handleLookupClear = () => {
    setLookupResult(null);
    setIsNewCustomer(false);
    form.setValue('customer_id', undefined);
    form.setValue('customer_name', undefined);
    form.setValue('customer_phone', undefined);
  };

  const handleOverrideToggle = () => {
    const next = !overrideUnlocked;
    setOverrideUnlocked(next);
    if (!next) {
      const zone = zoneOptions.find((z) => z.value === watchedZoneId);
      if (zone?.shipping_cost != null) {
        form.setValue('shipping_cost', String(zone.shipping_cost));
        form.setValue('shipping_cost_source', 'auto');
      }
    } else {
      form.setValue('shipping_cost_source', 'override');
    }
  };

  const handleSubmit = (values: ManualOrderFormValues) => {
    setServerError(null);
    if (isEdit && order) {
      updateOrder.mutate(
        { id: order.id, payload: toEditPayload(values) },
        {
          onSuccess: () => navigate(`${ROUTES.orders}/${order.id}`),
          onError: (err) => setServerError(extractMessage(err)),
        },
      );
      return;
    }
    if (configHealth && !configHealth.is_ready) {
      setServerError('Brand configuration is incomplete. Please configure the brand before creating orders.');
      return;
    }
    createManual.mutate(toManualPayload(values), {
      onSuccess: (created) => navigate(`${ROUTES.orders}/${created.id}`),
      onError: (err) => setServerError(extractMessage(err)),
    });
  };

  const isPending = isEdit ? updateOrder.isPending : createManual.isPending;
  const showContentSections = isEdit || ((!configHealth || configHealth.is_ready) && Boolean(brandId));
  const customerResolved = Boolean(lookupResult) || isNewCustomer;
  const hasProductLine = (watchedLines ?? []).some((l) => Boolean(l.product_id));
  const companyLocked = hasProductLine;
  const autoShippingCost = zoneOptions.find((z) => z.value === watchedZoneId)?.shipping_cost;

  const progressSteps = [
    { label: 'Context',  done: Boolean(watchedCompanyId) && Boolean(brandId) && Boolean(watchedChannelId) },
    { label: 'Customer', done: customerResolved },
    { label: 'Location', done: Boolean(watchedGovernorate) && Boolean(watchedZoneId) },
    { label: 'Products', done: hasProductLine },
    { label: 'Payment',  done: Boolean(watchedPayment) },
    {
      label: 'Ready',
      done:
        Boolean(watchedCompanyId) &&
        Boolean(brandId) &&
        Boolean(watchedChannelId) &&
        customerResolved &&
        Boolean(watchedGovernorate) &&
        Boolean(watchedZoneId) &&
        hasProductLine &&
        Boolean(watchedPayment) &&
        (configHealth?.is_ready ?? false),
    },
  ];

  return (
    <FormProvider {...form}>
      <div className="flex flex-col gap-4">
        <PageHeader
          title={isEdit ? `Edit Order #${order?.order_number ?? ''}` : 'New Order'}
          breadcrumbs={[
            { label: 'Home', to: ROUTES.dashboard },
            { label: 'Orders', to: ROUTES.orders },
            { label: isEdit ? `Edit #${order?.order_number ?? ''}` : 'New Order' },
          ]}
          actions={
            <div className="flex items-center gap-2">
              <Button
                type="button"
                variant="outline"
                onClick={() => navigate(isEdit && order ? `${ROUTES.orders}/${order.id}` : ROUTES.orders)}
              >
                <ArrowLeft className="size-4" />
                Cancel
              </Button>
              <Button
                type="submit"
                form={FORM_ID}
                disabled={isPending || (!isEdit && configHealth !== undefined && !configHealth.is_ready)}
              >
                {isEdit
                  ? (isPending ? 'Saving…' : 'Save Changes')
                  : (isPending ? 'Creating…' : 'Create Order')}
              </Button>
            </div>
          }
        />

        {!isEdit && <ProgressIndicator steps={progressSteps} />}

        <form id={FORM_ID} onSubmit={form.handleSubmit(handleSubmit)}>
          {serverError && (
            <Alert variant="destructive" className="mb-4">
              <AlertTitle>Error</AlertTitle>
              <AlertDescription>{serverError}</AlertDescription>
            </Alert>
          )}

          <div className="grid gap-4 lg:grid-cols-[1fr_300px]">
            <div className="flex min-w-0 flex-col gap-4">

              {/* § 1 — Order Context */}
              <Card>
                <CardContent className="pt-4">
                  {isEdit ? (
                    /* Edit mode: read-only context — channel, status, dates */
                    <div className="grid gap-3 sm:grid-cols-3">
                      <FormField name="channel_id_display" label="Sales Channel">
                        <div className="flex h-9 items-center gap-1.5 rounded-md border bg-muted/50 px-3 text-sm">
                          <span className="flex-1 truncate">
                            {order?.channel?.name ?? order?.channel_id ?? '—'}
                          </span>
                          <Badge variant="secondary" className="shrink-0 text-[10px]">Locked</Badge>
                        </div>
                      </FormField>

                      <FormField name="status" label="Order Status" required>
                        <select
                          {...form.register('status')}
                          className="border-input h-9 w-full rounded-md border bg-transparent px-3 text-sm shadow-xs"
                        >
                          {STATUS_OPTIONS.map((s) => (
                            <option key={s.value} value={s.value}>{s.label}</option>
                          ))}
                        </select>
                      </FormField>

                      <FormField name="order_date" label="Order Date">
                        <Input type="date" {...form.register('order_date')} />
                      </FormField>
                    </div>
                  ) : (
                    /* Create mode: full cascade */
                    <div className="grid gap-3 sm:grid-cols-3">

                      {/* PART 1 — Company selector (locks when products added) */}
                      <FormField name="company_id" label="Company" required>
                        {companyLocked ? (
                          <div className="flex h-9 items-center gap-1.5 rounded-md border bg-muted/50 px-3 text-sm">
                            <Building2 className="size-3.5 shrink-0 text-muted-foreground" />
                            <span className="flex-1 truncate font-medium">
                              {companyOptions.find((c) => c.value === watchedCompanyId)?.label ?? watchedCompanyId}
                            </span>
                            <Badge variant="secondary" className="shrink-0 text-[10px]">Locked</Badge>
                          </div>
                        ) : (
                          <Controller
                            control={form.control}
                            name="company_id"
                            render={({ field }) => (
                              <Combobox
                                options={companyOptions}
                                value={field.value ?? null}
                                onChange={handleCompanyChange}
                                placeholder="Select company"
                              />
                            )}
                          />
                        )}
                        {form.formState.errors.company_id && (
                          <p className="mt-1 text-xs text-destructive">
                            {form.formState.errors.company_id.message}
                          </p>
                        )}
                      </FormField>

                      {/* Brand */}
                      <FormField name="brand_id_display" label="Brand" required>
                        {!watchedCompanyId ? (
                          <div className="flex h-9 items-center rounded-md border border-dashed px-3 text-sm text-muted-foreground">
                            Select a Company first
                          </div>
                        ) : (
                          <div className="relative">
                            <Combobox
                              options={brandOptions}
                              value={brandId}
                              onChange={handleBrandChange}
                              placeholder="Select brand"
                              loading={loadingBrands}
                            />
                          </div>
                        )}
                      </FormField>

                      {/* Sales Channel */}
                      <FormField name="channel_id" label="Sales Channel" required>
                        {!brandId ? (
                          <div className="flex h-9 items-center rounded-md border border-dashed px-3 text-sm text-muted-foreground">
                            Select a Brand first
                          </div>
                        ) : loadingChannels ? (
                          <div className="flex h-9 items-center px-1"><InlineSpinner label="Loading channels…" /></div>
                        ) : (
                          <Controller
                            control={form.control}
                            name="channel_id"
                            render={({ field }) => (
                              <Combobox
                                options={channelOptions}
                                value={field.value ?? null}
                                onChange={handleChannelChange}
                                placeholder="Select channel"
                              />
                            )}
                          />
                        )}
                      </FormField>

                      {/* Order Status */}
                      <FormField name="status" label="Order Status" required>
                        <select
                          {...form.register('status')}
                          className="border-input h-9 w-full rounded-md border bg-transparent px-3 text-sm shadow-xs"
                        >
                          {STATUS_OPTIONS.map((s) => (
                            <option key={s.value} value={s.value}>{s.label}</option>
                          ))}
                        </select>
                      </FormField>

                      {/* Delivery Date */}
                      <FormField name="requested_delivery_date" label="Delivery Date">
                        <Input type="date" {...form.register('requested_delivery_date')} />
                      </FormField>

                      {/* PART 2 — Delivery Window */}
                      <FormField name="delivery_window_id" label="Delivery Window">
                        {!brandId ? (
                          <div className="flex h-9 items-center rounded-md border border-dashed px-3 text-sm text-muted-foreground">
                            Select a Brand first
                          </div>
                        ) : loadingWindows ? (
                          <div className="flex h-9 items-center px-1"><InlineSpinner label="Loading windows…" /></div>
                        ) : (
                          <Controller
                            control={form.control}
                            name="delivery_window_id"
                            render={({ field }) => (
                              <Combobox
                                options={windowOptions}
                                value={field.value ?? null}
                                onChange={handleWindowChange}
                                placeholder={windowOptions.length ? 'Select window' : 'No windows configured'}
                              />
                            )}
                          />
                        )}
                      </FormField>
                    </div>
                  )}

                  {/* PART 1 — Brand health check (create mode only, after brand selected) */}
                  {!isEdit && brandId && (
                    <div className="mt-3">
                      {loadingHealth ? (
                        <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                          <Loader2 className="size-3 animate-spin" />
                          Checking brand configuration…
                        </div>
                      ) : configHealth && !configHealth.is_ready ? (
                        <BrandConfigHealthCard
                          health={configHealth}
                          brandId={brandId}
                          brandName={brandName}
                        />
                      ) : null}
                    </div>
                  )}
                </CardContent>
              </Card>

              {/* § 2 — Customer & Delivery */}
              {showContentSections && (
                <Card>
                  <CardHeader className="pb-3">
                    <CardTitle className="text-base">Customer & Delivery</CardTitle>
                  </CardHeader>
                  <CardContent className="flex flex-col gap-4">
                    {!isEdit && (
                      <OrderCustomerLookupField
                        onFound={handleCustomerFound}
                        onNotFound={handleCustomerNotFound}
                        onClear={handleLookupClear}
                      />
                    )}

                    {lookupResult && (
                      <>
                        <OrderCustomerIntelligencePanel
                          stats={lookupResult.stats}
                          customerName={lookupResult.customer.name}
                        />
                        <OrderCustomerAlerts stats={lookupResult.stats} />
                      </>
                    )}

                    {(isEdit || isNewCustomer || lookupResult) && (
                      <div className="grid gap-3 sm:grid-cols-2">
                        {isNewCustomer && (
                          <div className="sm:col-span-2">
                            <FormField name="customer_name" label="Customer Name" required>
                              <Input placeholder="Full name" {...form.register('customer_name')} />
                              {form.formState.errors.customer_name && (
                                <p className="mt-1 text-xs text-destructive">
                                  {form.formState.errors.customer_name.message}
                                </p>
                              )}
                            </FormField>
                          </div>
                        )}

                        {isNewCustomer && (
                          <FormField name="customer_phone" label="Primary Phone">
                            <Input placeholder="Phone number" {...form.register('customer_phone')} />
                          </FormField>
                        )}

                        <FormField name="customer_secondary_phone" label="Secondary Phone">
                          <Input placeholder="Secondary phone (optional)" {...form.register('customer_secondary_phone')} />
                        </FormField>

                        {/* Governorate */}
                        <FormField name="governorate" label="Governorate" required>
                          {loadingGeo ? (
                            <div className="flex h-9 items-center px-1"><InlineSpinner label="Loading geography…" /></div>
                          ) : (
                            <Controller
                              control={form.control}
                              name="governorate"
                              render={({ field }) => (
                                <Combobox
                                  options={governorateOptions}
                                  value={field.value ?? null}
                                  onChange={handleGovernorateChange}
                                  placeholder={governorateOptions.length ? 'Select governorate' : 'No governorates configured'}
                                />
                              )}
                            />
                          )}
                        </FormField>

                        {/* Delivery Zone */}
                        <FormField name="delivery_zone_id" label="Delivery Zone" required>
                          <Controller
                            control={form.control}
                            name="delivery_zone_id"
                            render={({ field }) => (
                              <Combobox
                                options={zoneOptions}
                                value={field.value ?? null}
                                onChange={handleZoneChange}
                                placeholder={watchedGovernorate ? 'Select zone' : 'Select governorate first'}
                                disabled={!watchedGovernorate}
                              />
                            )}
                          />
                        </FormField>

                        <input type="hidden" {...form.register('city')} />
                        <input type="hidden" {...form.register('area')} />

                        <div className="sm:col-span-2">
                          <FormField name="shipping_address" label="Shipping Address">
                            <Input placeholder="Full street address" {...form.register('shipping_address')} />
                          </FormField>
                        </div>

                        <div className="sm:col-span-2">
                          <FormField name="customer_notes" label="Customer Notes">
                            <textarea
                              rows={2}
                              placeholder="Notes about this customer"
                              className="border-input placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-[3px]"
                              {...form.register('customer_notes')}
                            />
                          </FormField>
                        </div>
                      </div>
                    )}
                  </CardContent>
                </Card>
              )}

              {/* § 3 — Products */}
              {showContentSections && (
                <Card>
                  <CardHeader className="pb-3">
                    <div className="flex items-center justify-between gap-3">
                      <CardTitle className="text-base">Products</CardTitle>
                      {watchedChannelId && loadingProducts && (
                        <InlineSpinner label="Loading products…" />
                      )}
                    </div>
                  </CardHeader>
                  <CardContent>
                    <ManualOrderProductsSection
                      finishedProducts={fgProducts}
                      rawMaterials={rawMaterials}
                      channelSelected={Boolean(watchedChannelId)}
                      loadingProducts={loadingProducts && fgProducts.length === 0}
                    />
                  </CardContent>
                </Card>
              )}

              {/* § 4 — Payment & Shipping */}
              {showContentSections && (
                <Card>
                  <CardHeader className="pb-3">
                    <CardTitle className="text-base">Payment & Shipping</CardTitle>
                  </CardHeader>
                  <CardContent className="flex flex-col gap-4">
                    <OrderPaymentSection />

                    {/* Shipping cost with Automatic / Manual Override badge */}
                    <div className="border-t pt-4">
                      <FormField name="shipping_cost" label="Shipping Cost">
                        <div className="flex items-center gap-2">
                          <div className="relative flex-1">
                            <Input
                              type="number"
                              min="0"
                              step="0.01"
                              placeholder="0.00"
                              {...form.register('shipping_cost')}
                              disabled={!overrideUnlocked && autoShippingCost != null}
                              className={cn(!overrideUnlocked && autoShippingCost != null ? 'bg-muted' : '')}
                            />
                          </div>
                          <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            onClick={handleOverrideToggle}
                            title={overrideUnlocked ? 'Lock to auto rate' : 'Override shipping cost'}
                          >
                            {overrideUnlocked ? <Lock className="size-4" /> : <Unlock className="size-4" />}
                          </Button>
                        </div>
                        {watchedShipSrc === 'auto' && !overrideUnlocked && (
                          <p className="mt-1 flex items-center gap-1.5 text-xs text-emerald-600">
                            <Badge variant="outline" className="border-emerald-300 px-1.5 py-0 text-[10px] text-emerald-600">
                              Automatic
                            </Badge>
                            Based on selected delivery zone.
                          </p>
                        )}
                        {watchedShipSrc === 'override' && (
                          <p className="mt-1 flex items-center gap-1.5 text-xs text-amber-600">
                            <Badge variant="outline" className="border-amber-300 px-1.5 py-0 text-[10px] text-amber-600">
                              Manual Override
                            </Badge>
                            Will be audited.
                          </p>
                        )}
                      </FormField>
                    </div>

                    {/* Discount + Deposit */}
                    <div className="grid gap-3 border-t pt-4 sm:grid-cols-3">
                      <FormField name="discount_type" label="Discount Type">
                        <select
                          {...form.register('discount_type')}
                          className="border-input h-9 w-full rounded-md border bg-transparent px-3 text-sm shadow-xs"
                        >
                          <option value="">No discount</option>
                          <option value="percentage">Percentage (%)</option>
                          <option value="fixed">Fixed amount</option>
                        </select>
                      </FormField>
                      <FormField name="discount_amount" label="Discount Value">
                        <Input type="number" min="0" step="any" placeholder="0.00" {...form.register('discount_amount')} />
                      </FormField>
                      <FormField name="deposit_amount" label="Deposit">
                        <Input type="number" min="0" step="any" placeholder="0.00" {...form.register('deposit_amount')} />
                      </FormField>
                    </div>

                    <div className="border-t pt-4">
                      <FormField name="notes" label="Order Notes">
                        <textarea
                          rows={2}
                          placeholder="Internal notes about this order"
                          className="border-input placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-[3px]"
                          {...form.register('notes')}
                        />
                      </FormField>
                    </div>
                  </CardContent>
                </Card>
              )}
            </div>

            {/* Right sidebar — sticky summary */}
            <div>
              <LiveFinancialSummary />
            </div>
          </div>
        </form>
      </div>
    </FormProvider>
  );
}
