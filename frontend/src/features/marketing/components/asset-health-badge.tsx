import { cn } from '@/lib/utils';
import type { AssetHealth } from '../types/marketing';

const HEALTH_CLASS: Record<AssetHealth, string> = {
  healthy:            'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
  warning:            'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
  disconnected:       'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400',
  expired_token:      'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400',
  permission_missing: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
  sync_failed:        'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
  inactive:           'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-500',
  unknown:            'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-500',
};

const HEALTH_LABEL: Record<AssetHealth, string> = {
  healthy:            'Healthy',
  warning:            'Warning',
  disconnected:       'Disconnected',
  expired_token:      'Token Expired',
  permission_missing: 'Missing Permission',
  sync_failed:        'Sync Failed',
  inactive:           'Inactive',
  unknown:            'Unknown',
};

interface Props {
  health: AssetHealth;
  className?: string;
}

export function AssetHealthBadge({ health, className }: Props) {
  return (
    <span
      className={cn(
        'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
        HEALTH_CLASS[health],
        className,
      )}
    >
      {HEALTH_LABEL[health]}
    </span>
  );
}
