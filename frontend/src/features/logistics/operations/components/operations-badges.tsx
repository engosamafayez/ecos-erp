import { AlertTriangle, Info, Lock, XCircle } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import type {
  ExceptionSeverity,
  ExceptionSource,
  ExceptionStatus,
  PoolMemberStatus,
  PoolStatus,
  ReservationStatus,
} from '../types/operations';

const POOL: Record<PoolStatus, { label: string; className: string }> = {
  draft: { label: 'Draft', className: 'bg-sky-600 hover:bg-sky-600 text-white' },
  active: { label: 'Active', className: 'bg-emerald-600 hover:bg-emerald-600 text-white' },
  suspended: { label: 'Suspended', className: 'bg-amber-500 hover:bg-amber-500 text-white' },
  archived: { label: 'Archived', className: 'bg-muted text-muted-foreground hover:bg-muted' },
};

export function PoolStatusBadge({ status }: { status: PoolStatus }) {
  const { label, className } = POOL[status];

  return <Badge className={`text-xs ${className}`}>{label}</Badge>;
}

const MEMBER: Record<PoolMemberStatus, { label: string; className: string }> = {
  active: { label: 'In pool', className: 'bg-emerald-600 hover:bg-emerald-600 text-white' },
  suspended: { label: 'Held out', className: 'bg-amber-500 hover:bg-amber-500 text-white' },
  withdrawn: { label: 'Removed', className: 'bg-muted text-muted-foreground hover:bg-muted' },
};

export function MemberStatusBadge({ status }: { status: PoolMemberStatus }) {
  const { label, className } = MEMBER[status];

  return <Badge className={`text-xs ${className}`}>{label}</Badge>;
}

const RESERVATION: Record<ReservationStatus, { label: string; className: string }> = {
  pending: { label: 'Pending', className: 'bg-muted text-muted-foreground hover:bg-muted' },
  held: { label: 'Held', className: 'bg-sky-600 hover:bg-sky-600 text-white' },
  confirmed: { label: 'Confirmed', className: 'bg-emerald-600 hover:bg-emerald-600 text-white' },
  released: { label: 'Released', className: 'bg-muted text-muted-foreground hover:bg-muted' },
  failed: {
    label: 'Refused',
    className: 'bg-destructive hover:bg-destructive text-destructive-foreground',
  },
};

export function ReservationStatusBadge({ status }: { status: ReservationStatus }) {
  const { label, className } = RESERVATION[status];

  return <Badge className={`text-xs ${className}`}>{label}</Badge>;
}

const EXCEPTION: Record<ExceptionStatus, { label: string; className: string }> = {
  open: {
    label: 'Open',
    className: 'bg-destructive hover:bg-destructive text-destructive-foreground',
  },
  acknowledged: { label: 'Acknowledged', className: 'bg-amber-500 hover:bg-amber-500 text-white' },
  escalated: {
    label: 'Escalated',
    className: 'bg-destructive hover:bg-destructive text-destructive-foreground',
  },
  resolved: { label: 'Resolved', className: 'bg-emerald-600 hover:bg-emerald-600 text-white' },
  suppressed: { label: 'Suppressed', className: 'bg-muted text-muted-foreground hover:bg-muted' },
  auto_resolved: {
    label: 'Cleared on its own',
    className: 'bg-slate-600 hover:bg-slate-600 text-white',
  },
};

export function ExceptionStatusBadge({ status }: { status: ExceptionStatus }) {
  const { label, className } = EXCEPTION[status];

  return <Badge className={`text-xs ${className}`}>{label}</Badge>;
}

export function SeverityIcon({ severity }: { severity: ExceptionSeverity }) {
  if (severity === 'critical') {
    return <XCircle className="size-3.5 shrink-0 text-destructive" />;
  }

  if (severity === 'warning') {
    return <AlertTriangle className="size-3.5 shrink-0 text-amber-600" />;
  }

  return <Info className="size-3.5 shrink-0 text-muted-foreground" />;
}

/**
 * Which module owns the fact behind an exception.
 *
 * Always shown, because Operations cannot clear another module's fact — an
 * operator needs to know where the fix actually lives before trying.
 */
export function SourceBadge({ source, label }: { source: ExceptionSource; label?: string }) {
  const isOurs = source === 'operations';

  return (
    <Badge variant="outline" className={`gap-1 text-[10px] ${isOurs ? '' : 'border-amber-500'}`}>
      {!isOurs && <Lock className="size-2.5" />}
      {label ?? source}
    </Badge>
  );
}

/** Which module decides a pool member's readiness — never Operations. */
export function AuthorityBadge({ authority }: { authority: 'fleet' | 'drivers' }) {
  return (
    <Badge variant="outline" className="text-[10px] capitalize">
      {authority}
    </Badge>
  );
}
