import { useTranslation } from 'react-i18next';

import { cn } from '@/lib/utils';

import type { TaskPriority } from '../types';

const PRIORITY_CLASS: Record<TaskPriority, string> = {
  low: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400',
  normal: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
  high: 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400',
  urgent: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
};

export function TaskPriorityBadge({ priority }: { priority: TaskPriority }) {
  const { t } = useTranslation('collaboration');

  return (
    <span
      className={cn(
        'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
        'ring-1 ring-inset ring-current/20',
        PRIORITY_CLASS[priority],
      )}
    >
      {t(($) => $.tasks.priority[priority])}
    </span>
  );
}
