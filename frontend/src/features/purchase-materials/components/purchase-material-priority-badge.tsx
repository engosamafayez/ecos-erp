import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import type { PurchaseMaterialPriority } from '../types/purchase-material';

// Styling only — labels come from i18n (common.priority.*) so EN/AR both render correctly.
const PRIORITY_CLASS: Record<PurchaseMaterialPriority, string> = {
  low: 'bg-slate-100 text-slate-600 border-slate-200 dark:bg-slate-800/40 dark:text-slate-400 dark:border-slate-700',
  normal: 'bg-blue-50 text-blue-600 border-blue-200 dark:bg-blue-950/30 dark:text-blue-400 dark:border-blue-800',
  high: 'bg-amber-100 text-amber-700 border-amber-200 dark:bg-amber-950/40 dark:text-amber-400 dark:border-amber-800',
  urgent: 'bg-red-100 text-red-700 border-red-200 dark:bg-red-950/40 dark:text-red-400 dark:border-red-800',
};

export function PurchaseMaterialPriorityBadge({ priority }: { priority: PurchaseMaterialPriority }) {
  const { t } = useTranslation('purchase-materials');
  const tAny = t as (key: string) => string;
  const className = PRIORITY_CLASS[priority] ?? '';
  const label = tAny(`common.priority.${priority}`);
  return <Badge className={className}>{label}</Badge>;
}
