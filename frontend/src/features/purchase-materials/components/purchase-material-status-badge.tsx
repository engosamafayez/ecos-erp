import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import type { PurchaseMaterialStatus } from '../types/purchase-material';

// Styling only — labels come from i18n (common.status.*) so EN/AR both render correctly.
const STATUS_CLASS: Record<PurchaseMaterialStatus, string> = {
  draft: 'bg-slate-100 text-slate-700 border-slate-200 dark:bg-slate-800/40 dark:text-slate-300 dark:border-slate-700',
  under_review: 'bg-blue-100 text-blue-700 border-blue-200 dark:bg-blue-950/40 dark:text-blue-400 dark:border-blue-800',
  waiting_supplier_selection: 'bg-violet-100 text-violet-700 border-violet-200 dark:bg-violet-950/40 dark:text-violet-400 dark:border-violet-800',
  approved: 'bg-emerald-100 text-emerald-700 border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-400 dark:border-emerald-800',
  purchasing: 'bg-cyan-100 text-cyan-700 border-cyan-200 dark:bg-cyan-950/40 dark:text-cyan-400 dark:border-cyan-800',
  receiving: 'bg-teal-100 text-teal-700 border-teal-200 dark:bg-teal-950/40 dark:text-teal-400 dark:border-teal-800',
  completed: 'bg-green-100 text-green-700 border-green-200 dark:bg-green-950/40 dark:text-green-400 dark:border-green-800',
  rejected: 'bg-red-100 text-red-700 border-red-200 dark:bg-red-950/40 dark:text-red-400 dark:border-red-800',
  on_hold: 'bg-amber-100 text-amber-700 border-amber-200 dark:bg-amber-950/40 dark:text-amber-400 dark:border-amber-800',
  cancelled: 'bg-zinc-100 text-zinc-500 border-zinc-200 dark:bg-zinc-800/40 dark:text-zinc-400 dark:border-zinc-700',
};

export function PurchaseMaterialStatusBadge({ status }: { status: PurchaseMaterialStatus }) {
  const { t } = useTranslation('purchase-materials');
  const tAny = t as (key: string) => string;
  const className = STATUS_CLASS[status] ?? '';
  const label = tAny(`common.status.${status}`);
  return <Badge className={className}>{label}</Badge>;
}
