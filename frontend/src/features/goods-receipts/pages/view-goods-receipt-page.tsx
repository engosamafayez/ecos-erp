import { useState } from 'react';
import { FormProvider, useForm } from 'react-hook-form';
import { useNavigate, useParams } from 'react-router-dom';
import { Pencil, Send, Trash2 } from 'lucide-react';

import { ConfirmDialog, PageHeader } from '@/components/crud';
import { GrStatusBadge } from '@/features/goods-receipts/components/gr-status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { GoodsReceiptHeaderFields } from '@/features/goods-receipts/components/goods-receipt-header-fields';
import { GoodsReceiptLinesEditor } from '@/features/goods-receipts/components/goods-receipt-lines-editor';
import { toFormValues } from '@/features/goods-receipts/components/goods-receipt-form-schema';
import type { GoodsReceiptFormValues } from '@/features/goods-receipts/components/goods-receipt-form-schema';
import {
  useDeleteGoodsReceipt,
  useGoodsReceiptQuery,
  usePostGoodsReceipt,
} from '@/features/goods-receipts/hooks/use-goods-receipts';
import { ROUTES } from '@/router/routes';

export function ViewGoodsReceiptPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const [confirmDelete, setConfirmDelete] = useState(false);
  const [confirmPost, setConfirmPost] = useState(false);

  const { data: receipt, isLoading, isError } = useGoodsReceiptQuery(id ?? '');
  const deleteGR = useDeleteGoodsReceipt();
  const postGR = usePostGoodsReceipt();

  const form = useForm<GoodsReceiptFormValues>({
    defaultValues: receipt ? toFormValues(receipt) : toFormValues(null),
    values: receipt ? toFormValues(receipt) : undefined,
  });

  const poLineInfos = (receipt?.lines ?? []).map((l) => ({
    id: l.purchase_order_line_id,
    productName: l.product?.name ?? '—',
    productSku: l.product?.sku ?? '',
  }));

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

  const isDraft = receipt.status === 'draft';

  return (
    <FormProvider {...form}>
      <div className="flex flex-col gap-6">
        <PageHeader
          title={receipt.receipt_number}
          subtitle={<GrStatusBadge status={receipt.status} />}
          breadcrumbs={[
            { label: 'Home', to: ROUTES.dashboard },
            { label: 'Goods Receipts', to: ROUTES.goodsReceipts },
            { label: receipt.receipt_number },
          ]}
          actions={
            isDraft ? (
              <div className="flex items-center gap-2">
                <Button
                  variant="outline"
                  onClick={() => navigate(`${ROUTES.goodsReceipts}/${receipt.id}/edit`)}
                >
                  <Pencil className="size-4" />
                  Edit
                </Button>
                <Button variant="outline" onClick={() => setConfirmPost(true)}>
                  <Send className="size-4" />
                  Post
                </Button>
                <Button variant="destructive" onClick={() => setConfirmDelete(true)}>
                  <Trash2 className="size-4" />
                  Delete
                </Button>
              </div>
            ) : null
          }
        />

        <Card>
          <CardHeader>
            <CardTitle>Receipt Details</CardTitle>
          </CardHeader>
          <CardContent>
            <GoodsReceiptHeaderFields readOnly />
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Lines</CardTitle>
          </CardHeader>
          <CardContent>
            <GoodsReceiptLinesEditor readOnly poLineInfos={poLineInfos} />
          </CardContent>
        </Card>
      </div>

      <ConfirmDialog
        open={confirmDelete}
        onOpenChange={setConfirmDelete}
        title="Delete goods receipt"
        description={
          <>
            Delete <span className="text-foreground font-medium">{receipt.receipt_number}</span>?
            This action cannot be undone.
          </>
        }
        confirmLabel="Delete"
        variant="destructive"
        loading={deleteGR.isPending}
        onConfirm={() => {
          deleteGR.mutate(receipt.id, {
            onSuccess: () => navigate(ROUTES.goodsReceipts),
          });
        }}
      />

      <ConfirmDialog
        open={confirmPost}
        onOpenChange={setConfirmPost}
        title="Post goods receipt"
        description={
          <>
            Post <span className="text-foreground font-medium">{receipt.receipt_number}</span>? Stock
            will be updated and the receipt will become read-only.
          </>
        }
        confirmLabel="Post Receipt"
        loading={postGR.isPending}
        onConfirm={() => {
          postGR.mutate(receipt.id, {
            onSuccess: () => setConfirmPost(false),
          });
        }}
      />
    </FormProvider>
  );
}
