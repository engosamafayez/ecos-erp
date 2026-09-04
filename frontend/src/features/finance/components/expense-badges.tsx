import { useTranslation } from 'react-i18next';

import { StatusBadge } from '@/components/crud';

import type { ExpenseStatus } from '../types/finance-expense';

/** Expense lifecycle: draft → approved → posted (maker/checker, mirrors AP payments). */
const EXPENSE_VARIANT: Record<ExpenseStatus, 'active' | 'inactive' | 'pending' | 'archived'> = {
  draft: 'pending',
  approved: 'pending',
  posted: 'active',
  void: 'archived',
};

export function ExpenseStatusBadge({ status }: { status: ExpenseStatus }) {
  const { t } = useTranslation('finance');
  return <StatusBadge status={EXPENSE_VARIANT[status]} label={t(($) => $.expense.status[status])} />;
}
