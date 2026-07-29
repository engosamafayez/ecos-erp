import { Ban, CheckCircle, FileText, PauseCircle } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import type { ServiceAreaStatus } from '../types/network';

const CONFIG: Record<
  ServiceAreaStatus,
  { label: string; className: string; Icon: typeof CheckCircle }
> = {
  draft: {
    label: 'Draft',
    className: 'bg-sky-600 hover:bg-sky-600 text-white',
    Icon: FileText,
  },
  active: {
    label: 'Active',
    className: 'bg-emerald-600 hover:bg-emerald-600 text-white',
    Icon: CheckCircle,
  },
  // Paused still SERVES existing commitments — it only stops new ones.
  paused: {
    label: 'Paused',
    className: 'bg-amber-500 hover:bg-amber-500 text-white',
    Icon: PauseCircle,
  },
  closed: {
    label: 'Closed',
    className: 'bg-muted text-muted-foreground hover:bg-muted',
    Icon: Ban,
  },
};

export function AreaStatusBadge({ status }: { status: ServiceAreaStatus }) {
  const { label, className, Icon } = CONFIG[status];

  return (
    <Badge className={`gap-1 text-xs ${className}`}>
      <Icon className="size-3" />
      {label}
    </Badge>
  );
}
