import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Plus } from 'lucide-react';

import { ActionMenu } from '@/components/crud';
import { UniversalDataGrid, type DataGridColumnDef } from '@/components/data-grid';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { useToast } from '@/components/ds/use-toast';
import { usePermission } from '@/features/authorization';
import { useFormatter } from '@/hooks/use-formatter';

import { CostAllocationIdRef, CostAllocationMethodBadge } from './cost-allocation-badges';
import { CostAllocationFormDrawer } from './cost-allocation-form-drawer';
import { NoAccess } from './finance-panels';
import { useCostAllocations, useReverseCostAllocation } from '../hooks/use-finance-cost-allocation';
import { backendMessage } from '../utils/backend-message';
import type { CostAllocation } from '../types/finance-cost-allocation';

/**
 * Cost Allocation tab (Costing & Profitability). Attributes a POSTED expense's
 * cost to one or more Brand/Profit-Center destinations. This engine
 * deliberately never creates a second GL journal — it is a
 * management-dimension attribution only. Reversal is append-only (a new
 * negative-amount row); the reason-required confirm below mirrors
 * JournalDetailDrawer's reversing-toggle+reason pattern, adapted to a dialog
 * since this tab (unlike Journals) has no per-row detail drawer.
 */
export function CostAllocationTab() {
  const { t } = useTranslation('finance');
  const fmt = useFormatter();
  const { can } = usePermission();
  const { toast } = useToast();

  const allocations = useCostAllocations();
  const reverse = useReverseCostAllocation();

  const [createOpen, setCreateOpen] = useState(false);
  const [reversingId, setReversingId] = useState<string | null>(null);
  const [reason, setReason] = useState('');

  const canManage = can('finance.cost_allocation.manage');
  const list = useMemo(() => allocations.data ?? [], [allocations.data]);

  // A row that has already been reversed once shouldn't offer a second reversal from here —
  // an informational guard only; the backend remains the authority on what it will accept.
  const reversedSourceIds = useMemo(() => {
    const set = new Set<string>();
    for (const row of list) {
      if (row.reverses_allocation_id) set.add(row.reverses_allocation_id);
    }
    return set;
  }, [list]);

  const canReverseRow = (row: CostAllocation) =>
    canManage && row.reverses_allocation_id == null && !reversedSourceIds.has(row.id);

  const closeReverse = () => { setReversingId(null); setReason(''); };

  async function confirmReverse() {
    if (!reversingId || reason.trim() === '') return;
    try {
      await reverse.mutateAsync({ uuid: reversingId, reason: reason.trim() });
      toast({ title: t(($) => $.costAllocation.toast.reversed) });
      closeReverse();
    } catch (error) {
      toast({
        title: t(($) => $.costAllocation.reverse.failed),
        description: backendMessage(error),
        variant: 'destructive',
      });
    }
  }

  if (!can('finance.cost_allocation.view')) {
    return <NoAccess />;
  }

  const columns: DataGridColumnDef<CostAllocation>[] = [
    {
      key: 'source', label: t(($) => $.costAllocation.field.source), pin: 'left',
      cell: (r) => (
        <span className="flex items-center gap-1.5">
          <span>{r.source_type || '—'}</span>
          <CostAllocationIdRef id={r.source_id} />
        </span>
      ),
    },
    { key: 'source_amount', label: t(($) => $.costAllocation.field.sourceAmount), align: 'end', cell: (r) => <span className="tabular-nums">{fmt.money(r.source_amount)}</span> },
    { key: 'method', label: t(($) => $.costAllocation.field.method), cell: (r) => <CostAllocationMethodBadge method={r.method} /> },
    { key: 'destination_profit_center_id', label: t(($) => $.costAllocation.field.destination), cell: (r) => <span className="font-mono text-xs">{r.destination_profit_center_id}</span> },
    { key: 'allocated_amount', label: t(($) => $.costAllocation.field.allocatedAmount), align: 'end', cell: (r) => <span className="tabular-nums font-medium">{fmt.money(r.allocated_amount)}</span> },
    { key: 'percentage', label: t(($) => $.costAllocation.field.percentage), align: 'end', cell: (r) => <span className="tabular-nums">{r.percentage == null ? '—' : fmt.percent(r.percentage, false)}</span> },
    { key: 'created_at', label: t(($) => $.costAllocation.field.createdAt), cell: (r) => fmt.date(r.created_at) },
    {
      key: 'reversal', label: t(($) => $.costAllocation.field.reversalOf),
      cell: (r) => (r.reverses_allocation_id ? <CostAllocationIdRef id={r.reverses_allocation_id} /> : '—'),
    },
    {
      key: 'actions', label: '', pin: 'right', align: 'end', alwaysVisible: true,
      cell: (r) => {
        const items = canReverseRow(r)
          ? [{ key: 'reverse', label: t(($) => $.gl.actions.reverse), onSelect: () => setReversingId(r.id) }]
          : [];
        return <ActionMenu items={items} />;
      },
    },
  ];

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-xs text-muted-foreground">{t(($) => $.costAllocation.hint)}</p>
        {canManage && (
          <Button onClick={() => setCreateOpen(true)}>
            <Plus className="me-1.5 size-4" /> {t(($) => $.costAllocation.action.new)}
          </Button>
        )}
      </div>

      <UniversalDataGrid
        data={list}
        columns={columns}
        rowId={(r) => r.id}
        loading={allocations.isLoading}
        error={allocations.isError}
        emptyState={<p className="py-10 text-center text-sm text-muted-foreground">{t(($) => $.costAllocation.empty)}</p>}
      />

      <CostAllocationFormDrawer open={createOpen} onOpenChange={setCreateOpen} />

      <Dialog open={reversingId !== null} onOpenChange={(o) => { if (!o) closeReverse(); }}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t(($) => $.costAllocation.reverse.title)}</DialogTitle>
          </DialogHeader>
          <div className="space-y-2">
            <p className="text-sm text-muted-foreground">{t(($) => $.costAllocation.reverse.description)}</p>
            <Textarea
              placeholder={t(($) => $.costAllocation.reverse.reasonPlaceholder)}
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              maxLength={500}
            />
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={closeReverse} disabled={reverse.isPending}>
              {t(($) => $.gl.actions.cancel)}
            </Button>
            <Button onClick={() => void confirmReverse()} disabled={reverse.isPending || reason.trim() === ''}>
              {reverse.isPending ? t(($) => $.gl.actions.saving) : t(($) => $.gl.actions.confirmReverse)}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
