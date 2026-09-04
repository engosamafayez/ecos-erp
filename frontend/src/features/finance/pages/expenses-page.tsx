import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FolderPlus, Plus, Receipt, Wallet } from 'lucide-react';

import { UniversalDataGrid, type DataGridColumnDef } from '@/components/data-grid';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { WorkspacePage } from '@/components/page';
import { WorkspaceHeader, type WorkspaceMetric } from '@/components/workspace';
import { usePermission } from '@/features/authorization';
import { useFormatter } from '@/hooks/use-formatter';

import { ExpenseCategoryFormDialog } from '../components/expense-category-form-dialog';
import { ExpenseDetailDrawer } from '../components/expense-detail-drawer';
import { ExpenseFormDrawer } from '../components/expense-form-drawer';
import { ExpenseStatusBadge } from '../components/expense-badges';
import { useExpenses } from '../hooks/use-finance-expense';
import type { Expense, ExpenseStatus } from '../types/finance-expense';

const STATUS_FILTERS: (ExpenseStatus | 'all')[] = ['all', 'draft', 'approved', 'posted', 'void'];

/**
 * Finance Expenses (TASK-ECOS-FINANCE-UX-REPORTING-CLOSURE-008, FIN-EXEC-05).
 * The canonical capture-and-posting path Task 7 built (Modules\Finance\
 * Expenses) — no other domain owns operational expense approval, so this is
 * the complete workspace: create (maker) → approve → post (checker) →
 * reverse, all backend-authoritative. Status filter is backend-driven (a
 * query param), never a client-side re-filter of an already-fetched list.
 */
export function ExpensesPage() {
  const { t } = useTranslation('finance');
  const fmt = useFormatter();
  const { can } = usePermission();

  const [status, setStatus] = useState<ExpenseStatus | 'all'>('all');
  const expenses = useExpenses(status === 'all' ? {} : { status });

  const [createOpen, setCreateOpen] = useState(false);
  const [categoryOpen, setCategoryOpen] = useState(false);
  const [detailId, setDetailId] = useState<string | null>(null);
  const [detailOpen, setDetailOpen] = useState(false);

  const openDetail = (id: string) => { setDetailId(id); setDetailOpen(true); };

  const list = expenses.data ?? [];
  const metrics = useMemo<WorkspaceMetric[]>(() => {
    const totalAmount = list.reduce((sum, e) => sum + e.amount, 0);
    const pending = list.filter((e) => e.status === 'draft' || e.status === 'approved').length;
    return [
      { id: 'total', icon: Wallet, label: t(($) => $.expense.kpi.total), value: fmt.money(totalAmount), isLoading: expenses.isLoading },
      { id: 'count', icon: Receipt, label: t(($) => $.expense.kpi.count), value: list.length, isLoading: expenses.isLoading },
      { id: 'pending', icon: Receipt, label: t(($) => $.expense.kpi.pending), value: pending, isLoading: expenses.isLoading },
    ];
  }, [list, expenses.isLoading, fmt, t]);

  if (!can('finance.expense.view')) {
    return (
      <>
        <WorkspaceHeader breadcrumbs={[{ label: t(($) => $.breadcrumb.finance) }, { label: t(($) => $.expense.title) }]} title={t(($) => $.expense.title)} />
        <WorkspacePage><NoAccess /></WorkspacePage>
      </>
    );
  }

  const columns: DataGridColumnDef<Expense>[] = [
    { key: 'number', label: t(($) => $.expense.field.number), pin: 'left', cell: (e) => <span className="font-medium">{e.number}</span> },
    { key: 'expense_date', label: t(($) => $.expense.field.date), cell: (e) => fmt.date(e.expense_date) },
    { key: 'amount', label: t(($) => $.expense.field.amount), align: 'end', cell: (e) => <span className="tabular-nums">{fmt.money(e.amount, e.currency)}</span> },
    { key: 'status', label: t(($) => $.expense.field.status), cell: (e) => <ExpenseStatusBadge status={e.status} /> },
    { key: 'source_type', label: t(($) => $.expense.field.source), cell: (e) => (e.source_type ? `${e.source_type}` : '—') },
  ];

  return (
    <>
      <WorkspaceHeader
        breadcrumbs={[{ label: t(($) => $.breadcrumb.finance) }, { label: t(($) => $.expense.title) }]}
        title={t(($) => $.expense.title)}
        description={t(($) => $.expense.subtitle)}
        metrics={metrics}
      />
      <WorkspacePage>
        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
          <Select value={status} onValueChange={(v) => setStatus(v as ExpenseStatus | 'all')}>
            <SelectTrigger className="w-40"><SelectValue /></SelectTrigger>
            <SelectContent>
              {STATUS_FILTERS.map((s) => (
                <SelectItem key={s} value={s}>{s === 'all' ? t(($) => $.expense.filter.all) : t(($) => $.expense.status[s])}</SelectItem>
              ))}
            </SelectContent>
          </Select>

          <div className="flex gap-2">
            {can('finance.expense.category.manage') && (
              <Button variant="outline" onClick={() => setCategoryOpen(true)}>
                <FolderPlus className="me-1.5 size-4" /> {t(($) => $.expense.action.newCategory)}
              </Button>
            )}
            {can('finance.expense.create') && (
              <Button onClick={() => setCreateOpen(true)}>
                <Plus className="me-1.5 size-4" /> {t(($) => $.expense.action.new)}
              </Button>
            )}
          </div>
        </div>

        <UniversalDataGrid
          data={list}
          columns={columns}
          rowId={(e) => e.id}
          loading={expenses.isLoading}
          error={expenses.isError}
          onRowClick={(e) => openDetail(e.id)}
          emptyState={<p className="py-10 text-center text-sm text-muted-foreground">{t(($) => $.expense.empty)}</p>}
        />
      </WorkspacePage>

      <ExpenseFormDrawer open={createOpen} onOpenChange={setCreateOpen} />
      <ExpenseCategoryFormDialog open={categoryOpen} onOpenChange={setCategoryOpen} />
      <ExpenseDetailDrawer expenseId={detailId} open={detailOpen} onOpenChange={setDetailOpen} />
    </>
  );
}

function NoAccess() {
  const { t } = useTranslation('finance');
  return (
    <Card>
      <CardContent className="py-10 text-center text-sm text-muted-foreground">{t(($) => $.gl.statements.noAccess)}</CardContent>
    </Card>
  );
}

export default ExpensesPage;
