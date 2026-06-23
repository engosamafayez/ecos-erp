import { useEffect, useMemo, useState } from 'react';
import { FormProvider, useForm } from 'react-hook-form';
import { useNavigate, useParams } from 'react-router-dom';
import { zodResolver } from '@hookform/resolvers/zod';

import { PageHeader } from '@/components/crud';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { PoLineInfo } from '@/features/goods-receipts/components/goods-receipt-header-fields';
import { GoodsReceiptHeaderFields } from '@/features/goods-receipts/components/goods-receipt-header-fields';
import { GoodsReceiptLinesEditor } from '@/features/goods-receipts/components/goods-receipt-lines-editor';
import {
  goodsReceiptSchema,
  toFormValues,
  toPayload,
  type GoodsReceiptFormValues,
} from '@/features/goods-receipts/components/goods-receipt-form-schema';
import {
  useGoodsReceiptQuery,
  useUpdateGoodsReceipt,
} from '@/features/goods-receipts/hooks/use-goods-receipts';
import { ROUTES } from '@/router/routes';

export function EditGoodsReceiptPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const updateGR = useUpdateGoodsReceipt();

  // null = use receipt-derived infos; set when user changes the PO
  const [overridePoLineInfos, setOverridePoLineInfos] = useState<PoLineInfo[] | null>(null);

  const { data: receipt, isLoading, isError } = useGoodsReceiptQuery(id ?? '');

  const form = useForm<GoodsReceiptFormValues>({
    resolver: zodResolver(goodsReceiptSchema),
    defaultValues: toFormValues(null),
  });

  useEffect(() => {
    if (!receipt) return;
    if (receipt.status !== 'draft') {
      navigate(`${ROUTES.goodsReceipts}/${receipt.id}`, { replace: true });
      return;
    }
    form.reset(toFormValues(receipt));
  }, [receipt, form, navigate]);

  // Derive poLineInfos from receipt lines; override when user switches PO
  const poLineInfos = useMemo<PoLineInfo[]>(() => {
    if (overridePoLineInfos !== null) return overridePoLineInfos;
    return (receipt?.lines ?? []).map((l) => ({
      id: l.purchase_order_line_id,
      productName: l.product?.name ?? '—',
      productSku: l.product?.sku ?? '',
    }));
  }, [overridePoLineInfos, receipt]);

  if (isLoading) {
    return (
      <div className="flex h-64 items-center justify-center">
        <span className="text-muted-foreground text-sm">Loading…</span>
      </div>
    );
  }

  if (isError || !receipt) {
    return (
      <div className="flex h-64 items-center justify-center">
        <span className="text-destructive text-sm">Receipt not found.</span>
      </div>
    );
  }

  const onSubmit = (values: GoodsReceiptFormValues) => {
    if (!id) return;
    updateGR.mutate(
      { id, payload: toPayload(values) },
      { onSuccess: () => navigate(`${ROUTES.goodsReceipts}/${id}`) },
    );
  };

  return (
    <FormProvider {...form}>
      <form onSubmit={form.handleSubmit(onSubmit)}>
        <div className="flex flex-col gap-6">
          <PageHeader
            title={`Edit ${receipt.receipt_number}`}
            subtitle="Update receipt details and received quantities."
            breadcrumbs={[
              { label: 'Home', to: ROUTES.dashboard },
              { label: 'Goods Receipts', to: ROUTES.goodsReceipts },
              { label: receipt.receipt_number, to: `${ROUTES.goodsReceipts}/${id}` },
              { label: 'Edit' },
            ]}
            actions={
              <div className="flex items-center gap-2">
                <Button
                  type="button"
                  variant="outline"
                  onClick={() => navigate(`${ROUTES.goodsReceipts}/${id}`)}
                >
                  Cancel
                </Button>
                <Button type="submit" disabled={updateGR.isPending}>
                  {updateGR.isPending ? 'Saving…' : 'Save Changes'}
                </Button>
              </div>
            }
          />

          <Card>
            <CardHeader>
              <CardTitle>Receipt Details</CardTitle>
            </CardHeader>
            <CardContent>
              <GoodsReceiptHeaderFields onPoLinesLoaded={setOverridePoLineInfos} />
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Lines</CardTitle>
            </CardHeader>
            <CardContent>
              <GoodsReceiptLinesEditor poLineInfos={poLineInfos} />
            </CardContent>
          </Card>
        </div>
      </form>
    </FormProvider>
  );
}
