import { useTranslation } from 'react-i18next';

import { StatusBadge } from '@/components/crud';

import type { TaskPriority } from '../types';
import { TASK_PRIORITY_TONE } from '../lib/task-meta';

export function TaskPriorityBadge({ priority }: { priority: TaskPriority }) {
  const { t } = useTranslation('collaboration');

  return <StatusBadge tone={TASK_PRIORITY_TONE[priority]} label={t(($) => $.tasks.priority[priority])} />;
}
