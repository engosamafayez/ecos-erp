import { useMemo, useState } from 'react';
import { CheckCircle2, Plus, RotateCcw, Send, Trash2, XCircle } from 'lucide-react';

import {
  ActionMenu,
  ConfirmDialog,
  EntityTable,
  EntityToolbar,
  PageHeader,
  Pagination,
} from '@/components/crud';
import type { ColumnDef } from '@/components/crud/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
  useApproveSupplierReturn,
  useCancelSupplierReturn,
  useDeleteSupplierReturn,
  useSubmitSupplierReturn,
  useSupplierReturnsQuery,
  useSupplierReturnStats,
} from '@/features/supplier-returns/hooks/use-supplier-returns';
import { SupplierReturnDrawer } from '@/features/supplier-returns/components/supplier-return-drawer';
import type {
  SupplierReturn,
  SupplierReturnStatus,
} from '@/features/supplier-returns/types/supplier-return';

const STATUS_COLORS: Record<SupplierReturnStatus, string> = {
  draft:            'bg-gray-100 text-gray-700',
  waiting_approval: 'bg-yellow-100 text-yellow-800',
  approved:         'bg-blue-100 text-blue-800',
  sent:             'bg-purple-100 text-purple-800',
  credit_pending:   'bg-orange-100 text-orange-800',
  completed:        'bg-green-100 text-green-800',
  cancelled:        'bg-red-100 text-red-700',
  rejected:         'bg-red-100 text-red-700',
};

const STATUS_LABELS: Record<SupplierReturnStatus, string> = {
  draft:            'Draft',
  waiting_approval: 'Waiting Approval',
  approved:         'Approved',
  sent:             'Sent',
  credit_pending:   'Credit Pending',
  completed:        'Completed',
  cancelled:        'Cancelled',
  rejected:         'Rejected',
};

const PER_PAGE = 15;

export function SupplierReturnsPage() {
  const [search, setSearch]           = useState('');
  const [statusFilter, setStatus]     = useState<SupplierReturnStatus | 'all'>('all');
  const [page, setPage]               = useState(1);
  const [sort, setSort]               = useState<{ field: string; direction: 'asc' | 'desc' }>({
    field: 'created_at', direction: 'desc',
  });
  const [selectedId, setSelectedId]   = useState<string | null>(null);
  const [creatingNew, setCreatingNew] = useState(false);
  const [cancelling, setCancelling]   = useState<SupplierReturn | null>(null);
  const [deleting, setDeleting]       = useState<SupplierReturn | null>(null);

  const params = useMemo(() => ({
    search:   search || undefined,
    status:   statusFilter === 'all' ? undefined : statusFilter,
    page,
    per_page: PER_PAGE,
  }), [search, statusFilter, page]);

  const { data, isLoading, isError, isFetching, refetch } = useSupplierReturnsQuery(params);
  const { data: stats }  = useSupplierReturnStats();
  const submitMutation   = useSubmitSupplierReturn();
  const approveMutation  = useApproveSupplierReturn();
  const cancelMutation   = useCancelSupplierReturn();
  const deleteMutation   = useDeleteSupplierReturn();

  const items = data?.items ?? [];
  const meta  = data?.meta;

  const handleSort = (field: string) => {
    setSort(curr =>
      curr.field === field
        ? { field, direction: curr.direction === 'asc' ? 'desc' : 'asc' }
        : { field, direction: 'asc' }
    );
    setPage(1);
  };

  const handleClearFilters = () => {
    setStatus('all');
    setPage(1);
  };

  const columns: ColumnDef<SupplierReturn>[] = [
    {
      key: 'return_number',
      header: 'رقم المرتجع',
      cell: (r) => <span className="font-mono text-sm font-medium">{r.return_number}</span>,
    },
    {
      key: 'supplier',
      header: 'المورد',
      cell: (r) => <span className="text-sm">{r.supplier?.name ?? '—'}</span>,
    },
    {
      key: 'return_date',
      header: 'تاريخ المرتجع',
      cell: (r) => <span className="text-sm text-gray-600">{r.return_date}</span>,
    },
    {
      key: 'reason',
      header: 'السبب',
      cell: (r) => (
        <span className="text-xs text-gray-500 capitalize">{r.reason?.replace(/_/g, ' ') ?? '—'}</span>
      ),
    },
    {
      key: 'total_return_value',
      header: 'القيمة',
      cell: (r) => (
        <span className="text-sm font-medium">SAR {r.total_return_value.toLocaleString()}</span>
      ),
    },
    {
      key: 'status',
      header: 'الحالة',
      cell: (r) => (
        <Badge className={`${STATUS_COLORS[r.status]} border-0 text-xs`} variant="secondary">
          {STATUS_LABELS[r.status]}
        </Badge>
      ),
    },
  ];

  return (
    <div className="flex-1 flex flex-col min-h-0">
      <div className="px-6 py-4 border-b border-gray-200 bg-white">
        <div className="flex items-center justify-between mb-4">
          <PageHeader
            title="مرتجعات الموردين"
            subtitle="إدارة مرتجعات البضائع المعيبة أو الخاطئة أو الزائدة للموردين"
          />
          <Button onClick={() => setCreatingNew(true)} size="sm" className="gap-1.5">
            <Plus className="w-3.5 h-3.5" />
            مرتجع جديد
          </Button>
        </div>

        {stats && (
          <div className="flex gap-2 flex-wrap">
            {([
              { label: 'مسودة',          key: 'draft',          color: 'gray'   },
              { label: 'في الانتظار',        key: 'waiting',        color: 'yellow' },
              { label: 'ائتمان معلّق', key: 'credit_pending', color: 'orange' },
              { label: 'مكتمل',      key: 'completed',      color: 'green'  },
            ] as const).map(({ label, key, color }) => {
              const colorMap: Record<string, string> = {
                gray:   'bg-gray-100 text-gray-700',
                yellow: 'bg-yellow-50 text-yellow-700',
                orange: 'bg-orange-50 text-orange-700',
                green:  'bg-green-50 text-green-700',
              };
              return (
                <button
                  key={key}
                  onClick={() => { setStatus(key === 'waiting' ? 'waiting_approval' : key as SupplierReturnStatus); setPage(1); }}
                  className={`px-3 py-1.5 rounded-full text-xs font-medium transition-colors ${colorMap[color]} hover:opacity-80`}
                >
                  {label}: {stats[key as keyof typeof stats] as number}
                </button>
              );
            })}
            <button
              onClick={() => { setStatus('all'); setPage(1); }}
              className="px-3 py-1.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600 hover:bg-gray-200"
            >
              الكل: {stats.total}
            </button>
          </div>
        )}
      </div>

      <div className="flex-1 overflow-auto p-6">
        <Card className="shadow-none border-gray-200">
          <CardContent className="flex flex-col gap-4 pt-6">
            <EntityToolbar
              searchPlaceholder="ابحث في المرتجعات…"
              onSearchChange={(v) => { setSearch(v); setPage(1); }}
              onRefresh={() => void refetch()}
              isRefreshing={isFetching}
              onClearFilters={handleClearFilters}
              filterPanel={
                <div className="flex flex-col gap-3">
                  <div className="flex flex-col gap-1.5">
                    <span className="text-sm font-medium">الحالة</span>
                    <select
                      value={statusFilter}
                      onChange={(e) => { setStatus(e.target.value as SupplierReturnStatus | 'all'); setPage(1); }}
                      className="border-input h-9 rounded-md border bg-transparent px-3 text-sm shadow-xs"
                    >
                      <option value="all">جميع الحالات</option>
                      <option value="draft">مسودة</option>
                      <option value="waiting_approval">في انتظار الاعتماد</option>
                      <option value="approved">معتمد</option>
                      <option value="sent">مُرسَل</option>
                      <option value="credit_pending">ائتمان معلّق</option>
                      <option value="completed">مكتمل</option>
                      <option value="cancelled">ملغي</option>
                    </select>
                  </div>
                </div>
              }
            />

            <EntityTable<SupplierReturn>
              columns={columns}
              data={items}
              getRowId={(r) => r.id}
              isLoading={isLoading}
              isError={isError}
              sort={sort}
              onSortChange={handleSort}
              rowActions={(r) => (
                <ActionMenu
                  label={`Actions for ${r.return_number}`}
                  items={[
                    {
                      key: 'view',
                      label: 'عرض التفاصيل',
                      icon: RotateCcw,
                      onSelect: () => setSelectedId(r.id),
                    },
                    ...(r.status === 'draft' ? [
                      {
                        key: 'submit',
                        label: 'تقديم للاعتماد',
                        icon: Send,
                        onSelect: () => submitMutation.mutate(r.id),
                      },
                    ] : []),
                    ...(r.status === 'waiting_approval' ? [
                      {
                        key: 'approve',
                        label: 'اعتماد',
                        icon: CheckCircle2,
                        onSelect: () => approveMutation.mutate(r.id),
                      },
                    ] : []),
                    ...(['draft', 'waiting_approval'].includes(r.status) ? [
                      {
                        key: 'cancel',
                        label: 'إلغاء',
                        icon: XCircle,
                        variant: 'destructive' as const,
                        onSelect: () => setCancelling(r),
                      },
                    ] : []),
                    ...(r.status === 'draft' ? [
                      {
                        key: 'delete',
                        label: 'حذف',
                        icon: Trash2,
                        variant: 'destructive' as const,
                        onSelect: () => setDeleting(r),
                      },
                    ] : []),
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
      </div>

      <SupplierReturnDrawer
        id={selectedId}
        open={selectedId !== null}
        onOpenChange={(open) => { if (!open) setSelectedId(null); }}
      />

      <SupplierReturnDrawer
        id={null}
        open={creatingNew}
        onOpenChange={(open) => { if (!open) setCreatingNew(false); }}
        mode="create"
      />

      <ConfirmDialog
        open={cancelling !== null}
        onOpenChange={(open) => { if (!open) setCancelling(null); }}
        title="إلغاء المرتجع"
        description={`هل تريد إلغاء المرتجع ${cancelling?.return_number}؟`}
        confirmLabel="إلغاء المرتجع"
        variant="destructive"
        loading={cancelMutation.isPending}
        onConfirm={() => {
          if (cancelling) cancelMutation.mutate(cancelling.id, { onSuccess: () => setCancelling(null) });
        }}
      />

      <ConfirmDialog
        open={deleting !== null}
        onOpenChange={(open) => { if (!open) setDeleting(null); }}
        title="حذف المرتجع"
        description={`هل تريد حذف المرتجع ${deleting?.return_number}؟ لا يمكن التراجع.`}
        confirmLabel="حذف"
        variant="destructive"
        loading={deleteMutation.isPending}
        onConfirm={() => {
          if (deleting) deleteMutation.mutate(deleting.id, { onSuccess: () => setDeleting(null) });
        }}
      />
    </div>
  );
}
