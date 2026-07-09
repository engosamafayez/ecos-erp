import { cn } from '@/lib/utils';
import type { ConnectionStatus } from '../types/marketing';

const STATUS_CLASS: Record<ConnectionStatus, string> = {
  active:       'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
  expired:      'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
  disconnected: 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400',
  error:        'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
};

const STATUS_LABEL: Record<ConnectionStatus, string> = {
  active:       'Active',
  expired:      'Token Expired',
  disconnected: 'Disconnected',
  error:        'Error',
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
