import { AlertTriangle, CheckCircle, XCircle } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import type { FitnessLevel, FleetUnitLifecycle } from '../types/fleet';

const FITNESS: Record<FitnessLevel, { label: string; className: string; Icon: typeof CheckCircle }> = {
  fit: {
    label: 'Fit',
    className: 'bg-emerald-600 hover:bg-emerald-600 text-white',
    Icon: CheckCircle,
  },
  fit_with_warnings: {
    label: 'Warnings',
    className: 'bg-amber-500 hover:bg-amber-500 text-white',
    Icon: AlertTriangle,
  },
  unfit: {
    label: 'Unfit',
    className: 'bg-destructive hover:bg-destructive text-destructive-foreground',
    Icon: XCircle,
  },
};

export function FitnessBadge({ level }: { level: FitnessLevel }) {
  const { label, className, Icon } = FITNESS[level];

  return (
    <Badge className={`gap-1 text-xs ${className}`}>
      <Icon className="size-3" />
      {label}
    </Badge>
  );
}

const LIFECYCLE: Record<FleetUnitLifecycle, { label: string; className: string }> = {
  draft: { label: 'Draft', className: 'bg-muted text-muted-foreground hover:bg-muted' },
  commissioning: { label: 'Commissioning', className: 'bg-sky-600 hover:bg-sky-600 text-white' },
  active: { label: 'Active', className: 'bg-emerald-600 hover:bg-emerald-600 text-white' },
  suspended: { label: 'Suspended', className: 'bg-amber-600 hover:bg-amber-600 text-white' },
  decommissioning: {
    label: 'Decommissioning',
    className: 'bg-orange-500 hover:bg-orange-500 text-white',
  },
  retired: { label: 'Retired', className: 'bg-slate-600 hover:bg-slate-600 text-white' },
};

export function LifecycleBadge({ state }: { state: FleetUnitLifecycle }) {
  const { label, className } = LIFECYCLE[state];

  return <Badge className={`text-xs ${className}`}>{label}</Badge>;
}
