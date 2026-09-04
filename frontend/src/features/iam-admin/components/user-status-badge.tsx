import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type { UserLifecycleStatus } from '@/features/iam-admin/types/user';

/**
 * The shared crud-kit `StatusBadge` only models active/inactive/pending/archived
 * (`StatusVariant`) — the User lifecycle has 9 states (UserStatus.php). Extending that
 * shared component's contract would ripple to every other module using it for a need
 * that's specific to this one entity, so this is a local, IAM-scoped equivalent that
 * reuses the exact same visual language (Badge + colored dot) rather than inventing a
 * new visual system (§26 of the report).
 */
const STATUS_DOT: Record<UserLifecycleStatus, string> = {
  draft: 'bg-muted-foreground',
  invited: 'bg-sky-500',
  pending_activation: 'bg-amber-500',
  active: 'bg-emerald-500',
  inactive: 'bg-muted-foreground',
  suspended: 'bg-amber-600',
  locked: 'bg-red-500',
  archived: 'bg-muted-foreground',
  deleted: 'bg-red-700',
};

const MUTED: Record<UserLifecycleStatus, boolean> = {
  draft: true,
  invited: false,
  pending_activation: false,
  active: false,
  inactive: true,
  suspended: false,
  locked: false,
  archived: true,
  deleted: true,
};

export function UserStatusBadge({
  status,
  label,
  className,
}: {
  status: UserLifecycleStatus;
  /** Server-supplied status_label (already localized server-side text is avoided —
   *  this is the raw display string the API returns, e.g. "Active", "Archived"). */
  label: string;
  className?: string;
}) {
  return (
    <Badge
      variant={MUTED[status] ? 'outline' : 'secondary'}
      className={cn('gap-1.5', MUTED[status] && 'text-muted-foreground', className)}
    >
      <span className={cn('size-1.5 rounded-full', STATUS_DOT[status])} />
      {label}
    </Badge>
  );
}
