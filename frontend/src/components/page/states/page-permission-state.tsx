import { ShieldAlert } from 'lucide-react';

import { cn } from '@/lib/utils';

type PagePermissionStateProps = {
  title?: string;
  description?: string;
  className?: string;
};

/**
 * State shown when the current user lacks permission to view page content.
 *
 * Usage:
 *   <PagePermissionState />
 *   <PagePermissionState title="Finance restricted" description="Contact your admin." />
 */
export function PagePermissionState({
  title = 'Access denied',
  description = "You don't have permission to view this page. Contact your administrator to request access.",
  className,
}: PagePermissionStateProps) {
  return (
    <div
      className={cn(
        'flex flex-col items-center justify-center gap-3 py-20 text-center',
        className,
      )}
    >
      <span className="flex size-16 items-center justify-center rounded-full bg-amber-100 text-amber-600 dark:bg-amber-950/50 dark:text-amber-400">
        <ShieldAlert className="size-8" aria-hidden />
      </span>
      <div className="space-y-1">
        <p className="text-base font-semibold">{title}</p>
        <p className="mx-auto max-w-xs text-sm text-muted-foreground">{description}</p>
      </div>
    </div>
  );
}
