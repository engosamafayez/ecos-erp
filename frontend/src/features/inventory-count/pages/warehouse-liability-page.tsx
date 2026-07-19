import { useState } from 'react';
import { CheckCircle, Clock, Loader2, Shield, XCircle } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { toast } from '@/components/ds/use-toast';
import { PageHeader } from '@/components/crud';

import {
  useWarehouseLiabilitiesQuery,
  useApproveWarehouseLiability,
  useRejectWarehouseLiability,
} from '../hooks/use-inventory-count';
import type { WarehouseLiability } from '../types/inventory-count';

function statusBadge(status: string) {
  if (status === 'approved') {
    return <Badge variant="outline" className="text-emerald-600 border-emerald-200 bg-emerald-50 dark:bg-emerald-950/20 text-xs"><CheckCircle className="size-3 mr-1" />معتمد</Badge>;
  }
  if (status === 'rejected') {
    return <Badge variant="outline" className="text-muted-foreground text-xs"><XCircle className="size-3 mr-1" />مرفوض</Badge>;
  }
  return <Badge variant="outline" className="text-amber-600 border-amber-200 bg-amber-50 dark:bg-amber-950/20 text-xs"><Clock className="size-3 mr-1" />معلّق</Badge>;
}

function liabilityTypeBadge(type: string) {
  if (type === 'waste_transferred') {
    return <span className="text-xs bg-orange-100 text-orange-700 dark:bg-orange-950/30 dark:text-orange-300 rounded px-1.5 py-0.5">تحويل هدر</span>;
  }
  return <span className="text-xs bg-red-100 text-red-700 dark:bg-red-950/30 dark:text-red-300 rounded px-1.5 py-0.5">عجز</span>;
}

type ActionState = { liability: WarehouseLiability; action: 'approve' | 'reject'; actorName: string; notes: string };

export function WarehouseLiabilityPage() {
  const [month, setMonth] = useState(() => new Date().toISOString().slice(0, 7));
  const [statusFilter, setStatusFilter] = useState<'all' | 'pending' | 'approved' | 'rejected'>('all');
  const [actionState, setActionState] = useState<ActionState | null>(null);

  const query = useWarehouseLiabilitiesQuery({
    month,
    status: statusFilter === 'all' ? undefined : statusFilter,
  });

  const approveMutation = useApproveWarehouseLiability();
  const rejectMutation = useRejectWarehouseLiability();

  const items: WarehouseLiability[] = query.data?.data ?? [];
  const summary = query.data?.summary;
  const isSaving = approveMutation.isPending || rejectMutation.isPending;

  async function handleConfirm() {
    if (!actionState || !actionState.actorName.trim()) return;
    try {
      if (actionState.action === 'approve') {
        await approveMutation.mutateAsync({
          id: actionState.liability.id,
          approved_by: actionState.actorName.trim(),
          notes: actionState.notes || null,
        });
        toast.success('تمت الموافقة على المسؤولية وتم تعديل المخزون.');
      } else {
        await rejectMutation.mutateAsync({
          id: actionState.liability.id,
          rejected_by: actionState.actorName.trim(),
          reason: actionState.notes || null,
        });
        toast.success('تم رفض المسؤولية.');
      }
      setActionState(null);
    } catch {
      toast.error('فشل الإجراء. حاول مرة أخرى.');
    }
  }

  return (
    <div className="flex flex-col gap-6 p-6">
      <PageHeader
        title="سجل مسؤولية المستودع"
        subtitle="تتبع واعتماد عجز المخزون وتحويلات الهدر المنسوبة إلى المستودعات"
      />

      {/* KPI strip */}
      <div className="grid grid-cols-2 gap-4 sm:grid-cols-6">
        <div className="rounded-lg border bg-card p-4">
          <p className="text-xs text-muted-foreground">معلّقة</p>
          <p className="text-2xl font-semibold mt-1 text-amber-600">{summary?.pending ?? '—'}</p>
        </div>
        <div className="rounded-lg border bg-card p-4">
          <p className="text-xs text-muted-foreground">معتمدة</p>
          <p className="text-2xl font-semibold mt-1 text-emerald-600">{summary?.approved ?? '—'}</p>
        </div>
        <div className="rounded-lg border bg-card p-4">
          <p className="text-xs text-muted-foreground">مرفوضة</p>
          <p className="text-2xl font-semibold mt-1 text-muted-foreground">{summary?.rejected ?? '—'}</p>
        </div>
        <div className="rounded-lg border bg-card p-4">
          <p className="text-xs text-muted-foreground">القيمة المعلّقة</p>
          <p className="text-base font-semibold mt-1 tabular-nums">
            {summary?.total_pending_value != null
              ? Number(summary.total_pending_value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
              : '—'}
          </p>
        </div>
        <div className="rounded-lg border bg-card p-4">
          <p className="text-xs text-muted-foreground">المعتمدة (FIFO)</p>
          <p className="text-base font-semibold mt-1 tabular-nums text-emerald-600">
            {summary?.total_approved_value != null
              ? Number(summary.total_approved_value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
              : '—'}
          </p>
        </div>
        <div className="rounded-lg border bg-card p-4">
          <p className="text-xs text-muted-foreground">الشهر</p>
          <input
            type="month"
            value={month}
            onChange={(e) => setMonth(e.target.value)}
            className="mt-1 h-8 w-full rounded border border-input bg-background px-2 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
          />
        </div>
      </div>

      {/* Status filters */}
      <div className="flex gap-2">
        {(['all', 'pending', 'approved', 'rejected'] as const).map((s) => (
          <button
            key={s}
            onClick={() => setStatusFilter(s)}
            className={`rounded px-3 py-1.5 text-xs font-medium transition-colors ${
              statusFilter === s
                ? 'bg-primary text-primary-foreground'
                : 'bg-muted text-muted-foreground hover:bg-muted/80'
            }`}
          >
            {s === 'all' ? 'الكل' : s === 'pending' ? 'معلّقة' : s === 'approved' ? 'معتمدة' : 'مرفوضة'}
          </button>
        ))}
      </div>

      {/* Table */}
      <div className="rounded-lg border bg-card overflow-hidden">
        {query.isLoading ? (
          <div className="flex items-center justify-center py-16">
            <Loader2 className="size-6 animate-spin text-muted-foreground" />
          </div>
        ) : items.length === 0 ? (
          <div className="flex flex-col items-center gap-2 py-16 text-center">
            <Shield className="size-8 text-muted-foreground/40" />
            <p className="text-sm text-muted-foreground">لا توجد مسؤوليات مستودع</p>
          </div>
        ) : (
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b bg-muted/40">
                <th className="px-4 py-2.5 text-start text-xs font-medium text-muted-foreground">المنتج</th>
                <th className="px-4 py-2.5 text-start text-xs font-medium text-muted-foreground">النوع</th>
                <th className="px-4 py-2.5 text-end text-xs font-medium text-muted-foreground">الكمية</th>
                <th className="px-4 py-2.5 text-end text-xs font-medium text-muted-foreground">إجمالي التكلفة</th>
                <th className="px-4 py-2.5 text-start text-xs font-medium text-muted-foreground">المستودع</th>
                <th className="px-4 py-2.5 text-start text-xs font-medium text-muted-foreground">المدير</th>
                <th className="px-4 py-2.5 text-start text-xs font-medium text-muted-foreground">الحالة</th>
                <th className="px-4 py-2.5 text-end text-xs font-medium text-muted-foreground">الإجراءات</th>
              </tr>
            </thead>
            <tbody>
              {items.map((lib) => (
                <tr key={lib.id} className="border-b last:border-0 hover:bg-muted/30 transition-colors">
                  <td className="px-4 py-2.5">
                    <p className="font-medium">{lib.product?.name ?? '—'}</p>
                    <p className="text-[11px] text-muted-foreground">{lib.product?.sku}</p>
                  </td>
                  <td className="px-4 py-2.5">{liabilityTypeBadge(lib.liability_type)}</td>
                  <td className="px-4 py-2.5 text-end tabular-nums">{Number(lib.quantity).toFixed(2)}</td>
                  <td className="px-4 py-2.5 text-end tabular-nums font-medium">
                    <div>
                      {Number(lib.cost_snapshot_total_value ?? lib.total_cost).toLocaleString(undefined, {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2,
                      })}
                    </div>
                    {lib.cost_snapshot_total_value != null && (
                      <div className="text-[10px] text-emerald-600">FIFO مجمَّد</div>
                    )}
                  </td>
                  <td className="px-4 py-2.5 text-xs text-muted-foreground">{(lib.warehouse as { id: string; name: string } | null)?.name ?? '—'}</td>
                  <td className="px-4 py-2.5 text-xs text-muted-foreground">{lib.warehouse_manager ?? '—'}</td>
                  <td className="px-4 py-2.5">{statusBadge(lib.status)}</td>
                  <td className="px-4 py-2.5 text-end">
                    {lib.status === 'pending' && (
                      <div className="flex items-center justify-end gap-1.5">
                        <Button
                          size="sm"
                          variant="default"
                          className="h-7 text-xs"
                          onClick={() => setActionState({ liability: lib, action: 'approve', actorName: '', notes: '' })}
                        >
                          اعتماد
                        </Button>
                        <Button
                          size="sm"
                          variant="outline"
                          className="h-7 text-xs text-destructive hover:text-destructive"
                          onClick={() => setActionState({ liability: lib, action: 'reject', actorName: '', notes: '' })}
                        >
                          رفض
                        </Button>
                      </div>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      {/* Approve/Reject dialog */}
      <Dialog open={!!actionState} onOpenChange={(o) => { if (!o) setActionState(null); }}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>
              {actionState?.action === 'approve' ? 'اعتماد المسؤولية' : 'رفض المسؤولية'}
            </DialogTitle>
          </DialogHeader>
          {actionState && (
            <div className="space-y-4 py-2">
              <div className="rounded-md border bg-muted/40 px-4 py-3 text-sm">
                <p className="font-medium">{actionState.liability.product?.name}</p>
                <p className="text-muted-foreground text-xs mt-0.5">
                  الكمية: {Number(actionState.liability.quantity).toFixed(2)} ·
                  التكلفة: {Number(actionState.liability.total_cost).toLocaleString()} ·
                  {liabilityTypeBadge(actionState.liability.liability_type)}
                </p>
                {actionState.action === 'approve' && (
                  <p className="text-xs text-amber-600 mt-2 font-medium">
                    الاعتماد سيخصم {Number(actionState.liability.quantity).toFixed(2)} وحدة من المخزون.
                  </p>
                )}
              </div>

              <div className="space-y-1.5">
                <Label className="text-xs font-medium">
                  {actionState.action === 'approve' ? 'اعتمد بواسطة' : 'رُفض بواسطة'}
                </Label>
                <input
                  value={actionState.actorName}
                  onChange={(e) => setActionState((s) => s ? { ...s, actorName: e.target.value } : s)}
                  placeholder="اسمك"
                  className="h-9 w-full rounded border border-input bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
                />
              </div>

              <div className="space-y-1.5">
                <Label className="text-xs font-medium">
                  {actionState.action === 'approve' ? 'ملاحظات (اختياري)' : 'سبب الرفض (اختياري)'}
                </Label>
                <Textarea
                  value={actionState.notes}
                  onChange={(e) => setActionState((s) => s ? { ...s, notes: e.target.value } : s)}
                  placeholder={actionState.action === 'approve' ? 'أضف ملاحظات الاعتماد…' : 'لماذا يتم الرفض؟'}
                  rows={3}
                  className="text-sm"
                />
              </div>
            </div>
          )}
          <DialogFooter>
            <Button variant="outline" onClick={() => setActionState(null)}>إلغاء</Button>
            <Button
              variant={actionState?.action === 'approve' ? 'default' : 'destructive'}
              onClick={handleConfirm}
              disabled={!actionState?.actorName.trim() || isSaving}
            >
              {isSaving && <Loader2 className="size-3.5 mr-1.5 animate-spin" />}
              {actionState?.action === 'approve' ? 'اعتماد وتعديل المخزون' : 'رفض'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
