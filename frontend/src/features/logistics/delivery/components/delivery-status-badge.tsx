import {
  AlertTriangle,
  Ban,
  CheckCircle,
  Clock,
  MapPin,
  PackageCheck,
  RotateCcw,
  Truck,
  Undo2,
  XCircle,
} from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import type { DeliveryStatus } from '../types/delivery';

const CONFIG: Record<DeliveryStatus, { label: string; className: string; Icon: typeof CheckCircle }> = {
  pending: {
    label: 'Pending',
    className: 'bg-muted text-muted-foreground hover:bg-muted',
    Icon: Clock,
  },
  scheduled: {
    label: 'Scheduled',
    className: 'bg-sky-600 hover:bg-sky-600 text-white',
    Icon: Clock,
  },
  in_transit: {
    label: 'In Transit',
    className: 'bg-blue-600 hover:bg-blue-600 text-white',
    Icon: Truck,
  },
  out_for_delivery: {
    label: 'Out for Delivery',
    className: 'bg-indigo-600 hover:bg-indigo-600 text-white',
    Icon: MapPin,
  },
  attempted: {
    label: 'Attempted',
    className: 'bg-amber-500 hover:bg-amber-500 text-white',
    Icon: AlertTriangle,
  },
  delivered: {
    label: 'Delivered',
    className: 'bg-emerald-600 hover:bg-emerald-600 text-white',
    Icon: CheckCircle,
  },
  partially_delivered: {
    label: 'Partially Delivered',
    className: 'bg-amber-600 hover:bg-amber-600 text-white',
    Icon: PackageCheck,
  },
  failed: {
    label: 'Failed',
    className: 'bg-destructive hover:bg-destructive text-destructive-foreground',
    Icon: XCircle,
  },
  awaiting_retry: {
    label: 'Awaiting Retry',
    className: 'bg-orange-500 hover:bg-orange-500 text-white',
    Icon: RotateCcw,
  },
  returning: {
    label: 'Returning',
    className: 'bg-purple-600 hover:bg-purple-600 text-white',
    Icon: Undo2,
  },
  returned: {
    label: 'Returned',
    className: 'bg-slate-600 hover:bg-slate-600 text-white',
    Icon: Undo2,
  },
  cancelled: {
    label: 'Cancelled',
    className: 'bg-muted text-muted-foreground hover:bg-muted',
    Icon: Ban,
  },
};

export function DeliveryStatusBadge({ status }: { status: DeliveryStatus }) {
  const { label, className, Icon } = CONFIG[status];

  return (
    <Badge className={`gap-1 text-xs ${className}`}>
      <Icon className="size-3" />
      {label}
    </Badge>
  );
}
