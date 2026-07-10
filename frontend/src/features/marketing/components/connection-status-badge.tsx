import { cn } from '@/lib/utils';
import type { ConnectionStatus } from '../types/marketing';

const STATUS_CLASS: Record<ConnectionStatus, string> = {
  pending:        'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400',
  authenticating: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
  connected:      'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
  validating:     'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
  synchronizing:  'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
  healthy:        'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
  warning:        'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
  degraded:       'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400',
  disconnected:   'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400',
  archived:       'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-500',
  // legacy
  active:         'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
  expired:        'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
  error:          'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
};

const STATUS_LABEL: Record<ConnectionStatus, string> = {
  pending:        'Pending',
  authenticating: 'Authenticating',
  connected:      'Connected',
  validating:     'Validating',
  synchronizing:  'Syncing',
  healthy:        'Healthy',
  warning:        'Warning',
  degraded:       'Degraded',
  disconnected:   'Disconnected',
  archived:       'Archived',
  // legacy
  active:         'Active',
  expired:        'Token Expired',
  error:          'Error',
};

interface Props {
  status: ConnectionStatus;
  className?: string;
}

export function ConnectionStatusBadge({ status, className }: Props) {
  return (
    <span
      className={cn(
        'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
        STATUS_CLASS[status],
        className,
      )}
    >
      {STATUS_LABEL[status]}
    </span>
  );
}
