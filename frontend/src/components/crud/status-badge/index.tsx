import { useTranslation } from 'react-i18next';

import { Badge } from '@/components/ui/badge';
import type { StatusVariant } from '@/components/crud/types';
import { cn } from '@/lib/utils';

type StatusConfig = {
  /** i18n key inside the `common` namespace. */
  labelKey: string;
  dot: string;
  variant: 'secondary' | 'outline';
  muted?: boolean;
};

const STATUS_CONFIG: Record<StatusVariant, StatusConfig> = {
  active: { labelKey: 'status.active', dot: 'bg-emerald-500', variant: 'secondary' },
  pending: { labelKey: 'status.pending', dot: 'bg-amber-500', variant: 'secondary' },
  inactive: {
    labelKey: 'status.inactive',
    dot: 'bg-muted-foreground',
    variant: 'outline',
    muted: true,
  },
  archived: {
    labelKey: 'status.archived',
    dot: 'bg-muted-foreground',
    variant: 'outline',
    muted: true,
  },
};

type StatusBadgeProps = {
  status: StatusVariant;
  label?: string;
  className?: string;
};

/**
 * Reusable status badge supporting active / inactive / pending / archived.
 */
export function StatusBadge({ status, label, className }: StatusBadgeProps) {
  const { t } = useTranslation('common');
  const config = STATUS_CONFIG[status];

  return (
    <Badge
      variant={config.variant}
      className={cn('gap-1.5', config.muted && 'text-muted-foreground', className)}
    >
      <span className={cn('size-1.5 rounded-full', config.dot)} />
      {label ?? t(config.labelKey)}
    </Badge>
  );
}
