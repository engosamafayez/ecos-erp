import { Archive, CheckCircle, Truck, UserCheck, Wrench, XCircle } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import type { VehicleStatus } from '../types/vehicle';

const CONFIG: Record<VehicleStatus, { label: string; className: string; Icon: typeof CheckCircle }> = {
  available: {
    label: 'Available',
    className: 'bg-emerald-600 hover:bg-emerald-600 text-white',
    Icon: CheckCircle,
  },
  assigned: {
    label: 'Assigned',
    className: 'bg-blue-600 hover:bg-blue-600 text-white',
    Icon: UserCheck,
  },
  in_delivery: {
    label: 'In Delivery',
    className: 'bg-indigo-600 hover:bg-indigo-600 text-white',
    Icon: Truck,
  },
  maintenance: {
    label: 'Maintenance',
    className: 'bg-amber-500 hover:bg-amber-500 text-white',
    Icon: Wrench,
  },
  out_of_service: {
    label: 'Out of Service',
    className: 'bg-destructive hover:bg-destructive text-destructive-foreground',
    Icon: XCircle,
  },
  archived: {
    label: 'Archived',
    className: 'bg-muted text-muted-foreground hover:bg-muted',
    Icon: Archive,
  },
};

export function VehicleStatusBadge({ status }: { status: VehicleStatus }) {
  const { label, className, Icon } = CONFIG[status];

  return (
    <Badge className={`gap-1 text-xs ${className}`}>
      <Icon className="size-3" />
      {label}
    </Badge>
  );
}
