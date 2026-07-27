import { AlertTriangle, BadgeCheck, CircleSlash, ShieldAlert } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import type { LicenseStatus } from '../types/driver';

const CONFIG: Record<
  LicenseStatus,
  { label: string; className: string; Icon: typeof BadgeCheck }
> = {
  valid: {
    label: 'Valid',
    className: 'bg-emerald-600 hover:bg-emerald-600 text-white',
    Icon: BadgeCheck,
  },
  expiring_soon: {
    label: 'Expiring soon',
    className: 'bg-amber-500 hover:bg-amber-500 text-white',
    Icon: AlertTriangle,
  },
  expired: {
    label: 'Expired',
    className: 'bg-destructive hover:bg-destructive text-destructive-foreground',
    Icon: ShieldAlert,
  },
  missing: {
    label: 'No licence',
    className: 'bg-muted text-muted-foreground hover:bg-muted',
    Icon: CircleSlash,
  },
};

/**
 * Licence state, derived server-side. `daysRemaining` turns the badge into an
 * explicit countdown so the expiry warning is legible at a glance.
 */
export function LicenseStatusBadge({
  status,
  daysRemaining,
  showCountdown = false,
}: {
  status: LicenseStatus;
  daysRemaining?: number | null;
  showCountdown?: boolean;
}) {
  const { label, className, Icon } = CONFIG[status];

  let text = label;
  if (showCountdown && daysRemaining != null) {
    if (status === 'expiring_soon') {
      text = daysRemaining === 0 ? 'Expires today' : `Expires in ${daysRemaining}d`;
    } else if (status === 'expired') {
      const days = Math.abs(daysRemaining);
      text = `Expired ${days}d ago`;
    }
  }

  return (
    <Badge className={`gap-1 text-xs ${className}`}>
      <Icon className="size-3" />
      {text}
    </Badge>
  );
}
