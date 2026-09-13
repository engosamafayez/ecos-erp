import { useTranslation } from 'react-i18next';

import { StatusBadge } from '@/components/crud';

import type { TaskStatus } from '../types';
import { TASK_STATUS_TONE } from '../lib/task-meta';

export function TaskStatusBadge({ status }: { status: TaskStatus }) {
  const { t } = useTranslation('collaboration');

  return <StatusBadge tone={TASK_STATUS_TONE[status]} label={t(($) => $.tasks.status[status])} />;
}
