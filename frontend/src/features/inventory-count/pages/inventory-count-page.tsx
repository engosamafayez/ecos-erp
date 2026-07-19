import { useMemo, useState } from 'react';
import type React from 'react';
import { Camera } from 'lucide-react';

import { PageHeader } from '@/components/crud';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { ROUTES } from '@/router/routes';

import { CountStatusBadge } from '../components/count-status-badge';
import { CountSessionDrawer } from '../components/count-session-drawer';
import { NewCountDialog } from '../components/new-count-dialog';
import { useCountSessionsQuery, useDeleteCountSession } from '../hooks/use-inventory-count';
import type { CountSession, CountSessionStatus } from '../types/inventory-count';
import { toast } from '@/components/ds/use-toast';

const STATUSES: { value: CountSessionStatus | 'all'; label: string }[] = [
  { value: 'all', label: 'الكل' },
  { value: 'draft', label: 'مسودة' },
  { value: 'in_progress', label: 'قيد التنفيذ' },
  { value: 'completed', label: 'مكتمل' },
  { value: 'approved', label: 'معتمد' },
  { value: 'cancelled', label: 'ملغى' },
];

const PER_PAGE = 15;

function fmtDateTime(d: string | null | undefined): React.ReactNode {
  if (!d) return <span className="text-muted-foreground">—</span>;
  const dt = new Date(d);
  return (
    <div className="leading-tight">
      <p>{new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(dt)}</p>
      <p className="text-[11px] text-muted-foreground">
        {new Intl.DateTimeFormat(undefined, { timeStyle: 'short' }).format(dt)}
      </p>
    </div>
  );
}

function fmtMoney(v: number | null | undefined): React.ReactNode {
  if (v == null || v === 0) return <span className="text-muted-foreground">—</span>;
  return (
    <span className="font-mono text-sm tabular-nums text-destructive">
      {v.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 })} EGP
    </span>
  );
}

function AccuracyBadge({ pct }: { pct: number | null | undefined }) {
  if (pct == null) return <span className="text-muted-foreground text-xs">—</span>;
  const color = pct >= 95 ? 'text-emerald-600' : pct >= 80 ? 'text-amber-600' : 'text-destructive';
  return <span className={`font-mono text-sm font-semibold ${color}`}>{pct.toFixed(1)}%</span>;
}

export function InventoryCountPage() {
  const [statusFilter, setStatusFilter] = useState<CountSessionStatus | 'all'>('all');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [newDialogOpen, setNewDialogOpen] = useState(false);

  const params = useMemo(
    () => ({
      status: statusFilter === 'all' ? undefined : statusFilter,
      per_page: PER_PAGE,
      page,
    }),
    [statusFilter, page],
  );

  const { data, isLoading, isFetching, refetch } = useCountSessionsQuery(params);
  const deleteMutation = useDeleteCountSession();

  const items = data?.items ?? [];
  const meta = data?.meta;

  const filtered = search.trim()
    ? items.filter(
        (s) =>
          s.count_number.toLowerCase().includes(search.toLowerCase()) ||
          s.warehouse?.name?.toLowerCase().includes(search.toLowerCase()),
      )
    : items;

  function openSession(session: CountSession) {
    setSelectedId(session.id);
    setDrawerOpen(true);
  }

  async function handleDelete(session: CountSession, e: React.MouseEvent) {
    e.stopPropagation();
    if (!confirm(`حذف جلسة الجرد ${session.count_number}?`)) return;
    try {
      await deleteMutation.mutateAsync(session.id);
      toast.success('تم حذف الجلسة.');
    } catch {
      toast.error('فشل حذف الجلسة.');
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="جرد المخزون"
        subtitle="إدارة جلسات الجرد الفعلية للمخزون."
        breadcrumbs={[{ label: 'الرئيسية', to: ROUTES.dashboard }, { label: 'جرد المخزون' }]}
        actions={
          <Button onClick={() => setNewDialogOpen(true)}>+ جلسة جديدة</Button>
        }
      />

      {/* Status filter chips */}
      <div className="flex flex-wrap gap-2">
        {STATUSES.map((s) => (
          <button
            key={s.value}
            onClick={() => { setStatusFilter(s.value); setPage(1); }}
            className={[
              'inline-flex items-center rounded-full border px-3 py-1 text-xs font-medium transition-colors',
              statusFilter === s.value
                ? 'border-primary bg-primary text-primary-foreground'
                : 'border-border bg-background text-foreground hover:bg-accent',
            ].join(' ')}
          >
            {s.label}
          </button>
        ))}
      </div>

      <Card>
        <CardContent className="flex flex-col gap-4 pt-4">
          {/* Toolbar */}
          <div className="flex items-center gap-3 flex-wrap">
            <Input
              placeholder="بحث برقم الجلسة أو المستودع…"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              className="h-8 w-56 text-sm"
            />
            <Button
              variant="outline"
              size="sm"
              onClick={() => void refetch()}
              disabled={isFetching}
              className="h-8"
            >
              تحديث
            </Button>
            <span className="ms-auto text-xs text-muted-foreground">
              {meta ? `${meta.total} جلسة` : ''}
            </span>
          </div>

          {/* Table */}
          <div className="overflow-x-auto rounded-md border">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b bg-muted/40">
                  <th className="px-4 py-2.5 text-start text-xs font-medium text-muted-foreground">رقم الجلسة</th>
                  <th className="px-4 py-2.5 text-start text-xs font-medium text-muted-foreground">المستودع</th>
                  <th className="px-4 py-2.5 text-start text-xs font-medium text-muted-foreground">الحالة</th>
                  <th className="px-4 py-2.5 text-start text-xs font-medium text-muted-foreground">تاريخ البدء</th>
                  <th className="px-4 py-2.5 text-start text-xs font-medium text-muted-foreground">تاريخ الاكتمال</th>
                  <th className="px-4 py-2.5 text-end text-xs font-medium text-muted-foreground">الدقة</th>
                  <th className="px-4 py-2.5 text-end text-xs font-medium text-muted-foreground">قيمة العجز</th>
                  <th className="px-4 py-2.5 text-end text-xs font-medium text-muted-foreground">قيمة الهدر</th>
                  <th className="px-4 py-2.5 text-center text-xs font-medium text-muted-foreground">المرفقات</th>
                  <th className="px-4 py-2.5" />
                </tr>
              </thead>
              <tbody>
                {isLoading ? (
                  <tr>
                    <td colSpan={10} className="px-4 py-10 text-center text-sm text-muted-foreground">
                      جارٍ التحميل…
                    </td>
                  </tr>
                ) : filtered.length === 0 ? (
                  <tr>
                    <td colSpan={10} className="px-4 py-10 text-center text-sm text-muted-foreground">
                      {search ? 'لا توجد جلسات مطابقة.' : 'لا توجد جلسات جرد بعد. أنشئ جلسة للبدء.'}
                    </td>
                  </tr>
                ) : (
                  filtered.map((session) => (
                    <tr
                      key={session.id}
                      onClick={() => openSession(session)}
                      className="border-b last:border-0 hover:bg-muted/40 cursor-pointer transition-colors"
                    >
                      <td className="px-4 py-2.5 font-mono text-xs font-medium">{session.count_number}</td>
                      <td className="px-4 py-2.5">{session.warehouse?.name ?? '—'}</td>
                      <td className="px-4 py-2.5">
                        <CountStatusBadge status={session.status} />
                      </td>
                      <td className="px-4 py-2.5 text-xs">{fmtDateTime(session.started_at)}</td>
                      <td className="px-4 py-2.5 text-xs">{fmtDateTime(session.completed_at)}</td>
                      <td className="px-4 py-2.5 text-end">
                        <AccuracyBadge pct={session.variance_summary?.inventory_accuracy_pct} />
                      </td>
                      <td className="px-4 py-2.5 text-end">{fmtMoney(session.shortage_value)}</td>
                      <td className="px-4 py-2.5 text-end">{fmtMoney(session.waste_value)}</td>
                      <td className="px-4 py-2.5 text-center">
                        {session.attachment_count > 0 ? (
                          <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                            <Camera className="size-3" />
                            {session.attachment_count}
                          </span>
                        ) : (
                          <span className="text-muted-foreground text-xs">—</span>
                        )}
                      </td>
                      <td className="px-4 py-2.5 text-end">
                        {session.status === 'draft' && (
                          <button
                            onClick={(e) => void handleDelete(session, e)}
                            className="text-xs text-muted-foreground hover:text-destructive transition-colors"
                          >
                            حذف
                          </button>
                        )}
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>

          {/* Pagination */}
          {meta && meta.last_page > 1 && (
            <div className="flex items-center justify-between text-xs text-muted-foreground">
              <span>
                صفحة {meta.current_page} من {meta.last_page} — {meta.total} إجمالاً
              </span>
              <div className="flex gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  className="h-7 px-2 text-xs"
                  disabled={page <= 1}
                  onClick={() => setPage((p) => p - 1)}
                >
                  السابق
                </Button>
                <Button
                  variant="outline"
                  size="sm"
                  className="h-7 px-2 text-xs"
                  disabled={page >= meta.last_page}
                  onClick={() => setPage((p) => p + 1)}
                >
                  التالي
                </Button>
              </div>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Drawer for session detail */}
      <CountSessionDrawer
        sessionId={selectedId}
        open={drawerOpen}
        onOpenChange={(open) => {
          setDrawerOpen(open);
          if (!open) setSelectedId(null);
        }}
      />

      {/* New count dialog */}
      <NewCountDialog open={newDialogOpen} onOpenChange={setNewDialogOpen} />
    </div>
  );
}
