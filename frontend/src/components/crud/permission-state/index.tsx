import { ShieldAlert } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { cn } from '@/lib/utils';

type PermissionStateProps = {
  title?: string;
  description?: string;
  className?: string;
};

/**
 * State shown when the current user is authenticated but not authorized to
 * view this data (a backend 403), or the resource is otherwise unavailable
 * to them. Distinct from ErrorState (a read failure — retryable) and
 * EmptyState (no data — the request succeeded).
 *
 * Canonicalized here (TASK-ECOS-V1.1-CORE-01-UI-01-CANONICAL-FOUNDATION-045)
 * from `components/page/states/page-permission-state.tsx`, which now
 * re-exports this implementation.
 */
export function PermissionState({ title, description, className }: PermissionStateProps) {
  const { t } = useTranslation('common');
  const displayTitle = title ?? t($ => $.permission.accessDenied);
  const displayDescription = description ?? t($ => $.permission.description);

  return (
    <div
      className={cn(
        'flex flex-col items-center justify-center gap-3 py-20 text-center',
        className,
      )}
    >
      <span className="flex size-16 items-center justify-center rounded-full bg-warning text-warning-foreground">
        <ShieldAlert className="size-8" aria-hidden />
      </span>
      <div className="space-y-1">
        <p className="text-base font-semibold">{displayTitle}</p>
        <p className="mx-auto max-w-xs text-sm text-muted-foreground">{displayDescription}</p>
      </div>
    </div>
  );
}
