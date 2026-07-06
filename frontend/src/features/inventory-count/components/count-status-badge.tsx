import { Badge } from '@/components/ui/badge';
import type { CountSessionStatus } from '../types/inventory-count';

const STATUS_CONFIG: Record<CountSessionStatus, { label: string; className: string }> = {
  draft: {
    label: 'Draft',
    className: 'bg-slate-100 text-slate-700 border-slate-200 dark:bg-slate-800/40 dark:text-slate-300 dark:border-slate-700',
  },
  in_progress: {
    label: 'In Progress',
    className: 'bg-blue-100 text-blue-700 border-blue-200 dark:bg-blue-950/40 dark:text-blue-400 dark:border-blue-800',
  },
  completed: {
    label: 'Pending Approval',
    className: 'bg-amber-100 text-amber-700 border-amber-200 dark:bg-amber-950/40 dark:text-amber-400 dark:border-amber-800',
  },
  approved: {
    label: 'Approved',
    className: 'bg-emerald-100 text-emerald-700 border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-400 dark:border-emerald-800',
  },
  cancelled: {
    label: 'Cancelled',
    className: 'bg-red-100 text-red-700 border-red-200 dark:bg-red-950/40 dark:text-red-400 dark:border-red-800',
  },
};

export function CountStatusBadge({ status }: { status: CountSessionStatus }) {
  const config = STATUS_CONFIG[status] ?? { label: status, className: '' };
  return <Badge className={config.className}>{config.label}</Badge>;
}
