import { useTranslation } from 'react-i18next';

import { cn } from '@/lib/utils';

import type { TaskStatus } from '../types';

const STATUS_CLASS: Record<TaskStatus, string> = {
  todo: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
  in_progress: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
  done: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
  cancelled: 'bg-rose-100 text-rose-800 dark:bg-rose-900/30 dark:text-rose-400',
};

export function TaskStatusBadge({ status }: { status: TaskStatus }) {
  const { t } = useTranslation('collaboration');

  return (
    <span
      className={cn(
        'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
        'ring-1 ring-inset ring-current/20',
        STATUS_CLASS[status],
      )}
    >
      {t(($) => $.tasks.status[status])}
    </span>
  );
}
