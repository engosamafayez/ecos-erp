import { AlertTriangle, Lock, XCircle } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import type {
  ConflictAuthority,
  QueueItemStatus,
  QueuePriority,
  SessionStatus,
} from '../types/dispatch-ops';

const SESSION: Record<SessionStatus, { label: string; className: string }> = {
  open: { label: 'Open', className: 'bg-emerald-600 hover:bg-emerald-600 text-white' },
  paused: { label: 'Paused', className: 'bg-amber-500 hover:bg-amber-500 text-white' },
  closing: { label: 'Closing', className: 'bg-amber-600 hover:bg-amber-600 text-white' },
  closed: { label: 'Closed', className: 'bg-muted text-muted-foreground hover:bg-muted' },
  abandoned: {
    label: 'Abandoned',
    className: 'bg-destructive hover:bg-destructive text-destructive-foreground',
  },
};

export function SessionStatusBadge({ status }: { status: SessionStatus }) {
  const { label, className } = SESSION[status];

  return <Badge className={`text-xs ${className}`}>{label}</Badge>;
}

const QUEUE: Record<QueueItemStatus, { label: string; className: string }> = {
  waiting: { label: 'Waiting', className: 'bg-muted text-muted-foreground hover:bg-muted' },
  claimed: { label: 'Claimed', className: 'bg-sky-600 hover:bg-sky-600 text-white' },
  assigned: { label: 'Assigned', className: 'bg-emerald-600 hover:bg-emerald-600 text-white' },
  blocked: {
    label: 'Blocked',
    className: 'bg-destructive hover:bg-destructive text-destructive-foreground',
  },
  deferred: { label: 'Deferred', className: 'bg-amber-500 hover:bg-amber-500 text-white' },
  completed: { label: 'Completed', className: 'bg-slate-600 hover:bg-slate-600 text-white' },
  cancelled: { label: 'Cancelled', className: 'bg-muted text-muted-foreground hover:bg-muted' },
};

export function QueueStatusBadge({ status }: { status: QueueItemStatus }) {
  const { label, className } = QUEUE[status];

  return <Badge className={`text-xs ${className}`}>{label}</Badge>;
}

const PRIORITY: Record<QueuePriority, { label: string; className: string }> = {
  critical: {
    label: 'Critical',
    className: 'bg-destructive hover:bg-destructive text-destructive-foreground',
  },
  high: { label: 'High', className: 'bg-amber-500 hover:bg-amber-500 text-white' },
  normal: { label: 'Normal', className: 'bg-sky-600 hover:bg-sky-600 text-white' },
  low: { label: 'Low', className: 'bg-muted text-muted-foreground hover:bg-muted' },
};

export function PriorityBadge({ priority }: { priority: QueuePriority }) {
  const { label, className } = PRIORITY[priority];

  return <Badge className={`text-xs ${className}`}>{label}</Badge>;
}

/**
 * Which module owns the fact behind a conflict.
 *
 * Shown next to every conflict because Dispatch may not overrule another
 * authority — a dispatcher needs to know where the fix actually lives.
 */
const AUTHORITY: Record<ConflictAuthority, string> = {
  fleet: 'Fleet',
  drivers: 'Drivers',
  network: 'Network',
  distribution: 'Distribution',
  dispatch: 'Dispatch',
};

export function AuthorityBadge({ authority }: { authority: ConflictAuthority }) {
  const isOurs = authority === 'dispatch';

  return (
    <Badge variant="outline" className={`gap-1 text-[10px] ${isOurs ? '' : 'border-amber-500'}`}>
      {!isOurs && <Lock className="size-2.5" />}
      {AUTHORITY[authority]}
    </Badge>
  );
}

export function SeverityIcon({ severity }: { severity: 'blocking' | 'advisory' }) {
  return severity === 'blocking' ? (
    <XCircle className="size-3.5 shrink-0 text-destructive" />
  ) : (
    <AlertTriangle className="size-3.5 shrink-0 text-amber-600" />
  );
}
