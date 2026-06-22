import { Loader2 } from 'lucide-react';

import { cn } from '@/lib/utils';

type LoadingStateProps = {
  label?: string;
  className?: string;
};

/**
 * Reusable centered loading indicator.
 */
export function LoadingState({ label = 'Loading…', className }: LoadingStateProps) {
  return (
    <div
      className={cn(
        'text-muted-foreground flex flex-col items-center justify-center gap-2 py-12',
        className,
      )}
    >
      <Loader2 className="size-6 animate-spin" />
      <span className="text-sm">{label}</span>
    </div>
  );
}
