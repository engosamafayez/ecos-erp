import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Eye, Pencil, Plus, Send, Trash2 } from 'lucide-react';

import {
  ActionMenu,
  ConfirmDialog,
  EntityTable,
  EntityToolbar,
  PageHeader,
  Pagination,
} from '@/components/crud';
import type { ColumnDef } from '@/components/crud/types';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { GrStatusBadge } from '@/features/goods-receipts/components/gr-status-badge';
import {
  useDeleteGoodsReceipt,
  useGoodsReceiptsQuery,
  usePostGoodsReceipt,
} from '@/features/goods-receipts/hooks/use-goods-receipts';
import type {
  GoodsReceipt,
  GoodsReceiptSortField,
  GoodsReceiptStatus,
} from '@/features/goods-receipts/types/goods-receipt';
import { ROUTES } from '@/router/routes';

const PER_PAGE = 10;

export function GoodsReceiptsPage() {
  const navigate = useNavigate();
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState<GoodsReceiptStatus | 'all'>('all');
  const [page, setPage] = useState(1);
  const [sort, setSort] = useState<{ field: GoodsReceiptSortField; direction: 'asc' | 'desc' }>({
    field: 'created_at',
    direction: 'desc',
  });
  const [deleting, setDeleting] = useState<GoodsReceipt | null>(null);
  const [posting, setPosting] = useState<GoodsReceipt | null>(null);

  const params = useMemo(
    () => ({
      search: search || undefined,
      status: statusFilter,
      page,
      per_page: PER_PAGE,
      sort_by: sort.field,
      sort_dir: sort.direction,
    }),
    [search, statusFilter, page, sort],
  );

  const { data, isLoading, isError, isFetching, refetch } = useGoodsReceiptsQuery(params);
  const deleteGR = useDeleteGoodsReceipt();
  const postGR = usePostGoodsReceipt();

  const items = data?.items ?? [];
  const meta = data?.meta;

  const handleSort = (field: string) => {
    setSort((curr) =>
      curr.field === field
        ? { field: field as GoodsReceiptSortField, direction: curr.direction === 'asc' ? 'desc' : 'asc' }
        : { field: field as GoodsReceiptSortField, direction: 'asc' },
    );
    setPage(1);
  };

  const columns: ColumnDef<GoodsReceipt>[] = [
    {
      key: 'receipt_number',
      header: 'Receipt #',
      sortable: true,
      cell: (gr) => <span className="font-medium">{gr.receipt_number}</span>,
    },
    {
      key: 'purchase_order',
      header: 'Purchase Order',
      cell: (gr) => gr.purchase_order?.po_number ?? '—',
    },
    {
      key: 'warehouse',
      header: 'Warehouse',
      cell: (gr) => gr.warehouse?.name ?? '—',
    },
    {
      key: 'receipt_date',
      header: 'Receipt Date',
      sortable: true,
      cell: (gr) => gr.receipt_date,
    },
    {
      key: 'status',
      header: 'Status',
      sortable: true,
      cell: (gr) => <GrStatusBadge status={gr.status} />,
    },
  ];

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Goods Receipts"
        subtitle="Receive products from approved purchase orders into warehouses."
        breadcrumbs={[{ label: 'Home', to: ROUTES.dashboard }, { label: 'Goods Receipts' }]}
        actions={
          <Button onClick={() => navigate(ROUTES.goodsReceiptsNew)}>
            <Plus className="size-4" />
            New Receipt
          </Button>
        }
      />

      <Card>
        <CardContent className="flex flex-col gap-4 pt-6">
          <EntityToolbar
            searchPlaceholder="Search by receipt # or PO number…"
            onSearchChange={(v) => { setSearch(v); setPage(1); }}
            onRefresh={() => void refetch()}
            isRefreshing={isFetching}
            onExport={() => undefined}
            onClearFilters={() => { setStatusFilter('all'); setPage(1); }}
            filterPanel={
              <div className="flex flex-col gap-1.5">
                <span className="text-sm font-medium">Status</span>
                <select
                  value={statusFilter}
                  onChange={(e) => { setStatusFilter(e.target.value as GoodsReceiptStatus | 'all'); setPage(1); }}
                  className="border-input h-9 rounded-md border bg-transparent px-3 text-sm shadow-xs"
                >
                  <option value="all">All</option>
                  <option value="draft">Draft</option>
                  <option value="posted">Posted</option>
                </select>
              </div>
            }
          />

          <EntityTable<GoodsReceipt>
            columns={columns}
            data={items}
            getRowId={(gr) => gr.id}
            isLoading={isLoading}
            isError={isError}
            sort={sort}
            onSortChange={handleSort}
            rowActions={(gr) => (
              <ActionMenu
                label={`Actions for ${gr.receipt_number}`}
                items={[
                  {
                    key: 'view',
                    label: 'View',
                    icon: Eye,
                    onSelect: () => navigate(`${ROUTES.goodsReceipts}/${gr.id}`),
                  },
                  ...(gr.status === 'draft'
                    ? [
                        {
                          key: 'edit',
                          label: 'Edit',
                          icon: Pencil,
                          onSelect: () => navigate(`${ROUTES.goodsReceipts}/${gr.id}/edit`),
                        },
                        {
                          key: 'post',
                          label: 'Post',
                          icon: Send,
                          onSelect: () => setPosting(gr),
                        },
                        {
                          key: 'delete',
                          label: 'Delete',
                          icon: Trash2,
                          variant: 'destructive' as const,
                          onSelect: () => setDeleting(gr),
                        },
                      ]
                    : []),
                ]}
              />
            )}
          />

          {meta ? (
            <Pagination
              meta={{ page: meta.current_page, perPage: meta.per_page, total: meta.total, lastPage: meta.last_page }}
              onPageChange={setPage}
            />
          ) : null}
        </CardContent>
      </Card>

      <ConfirmDialog
        open={deleting !== null}
        onOpenChange={(open) => { if (!open) setDeleting(null); }}
        title="Delete goods receipt"
        description={
          <>
            Delete <span className="text-foreground font-medium">{deleting?.receipt_number}</span>?
            Only draft receipts can be deleted.
          </>
        }
        confirmLabel="Delete"
        variant="destructive"
        loading={deleteGR.isPending}
        onConfirm={() => {
          if (deleting) deleteGR.mutate(deleting.id, { onSuccess: () => setDeleting(null) });
        }}
      />

      <ConfirmDialog
        open={posting !== null}
        onOpenChange={(open) => { if (!open) setPosting(null); }}
        title="Post goods receipt"
        description={
          <>
            Post <span className="text-foreground font-medium">{posting?.receipt_number}</span>? Stock
            will be updated and the receipt will become read-only.
          </>
        }
        confirmLabel="Post Receipt"
        loading={postGR.isPending}
        onConfirm={() => {
          if (posting) postGR.mutate(posting.id, { onSuccess: () => setPosting(null) });
        }}
      />
    </div>
  );
}
