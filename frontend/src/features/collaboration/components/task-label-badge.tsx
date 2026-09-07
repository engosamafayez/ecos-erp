import { cn } from '@/lib/utils';

import type { TaskLabel, TaskLabelColor } from '../types';

const COLOR_CLASS: Record<TaskLabelColor, string> = {
  gray: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
  red: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
  orange: 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400',
  yellow: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
  green: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
  blue: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
  purple: 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400',
};

/** Color alone never carries the only meaning (brief §22 — accessibility): the label's name is always rendered as text too, never a bare color chip. */
export function TaskLabelBadge({ label, onRemove }: { label: TaskLabel; onRemove?: () => void }) {
  return (
    <span
      className={cn(
        'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium',
        'ring-1 ring-inset ring-current/20',
        COLOR_CLASS[label.color],
      )}
    >
      {label.name}
      {onRemove ? (
        <button
          type="button"
          onClick={onRemove}
          className="ms-0.5 rounded-full opacity-70 hover:opacity-100"
          aria-label={label.name}
        >
          ×
        </button>
      ) : null}
    </span>
  );
}
