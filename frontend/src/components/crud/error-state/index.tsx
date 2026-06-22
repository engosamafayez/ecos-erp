import { AlertTriangle } from 'lucide-react';

import { Button } from '@/components/ui/button';

type ErrorStateProps = {
  title?: string;
  description?: string;
  onRetry?: () => void;
};

/**
 * Reusable error placeholder with an optional retry action.
 */
export function ErrorState({
  title = 'Something went wrong',
  description = 'We could not load the data. Please try again.',
  onRetry,
}: ErrorStateProps) {
  return (
    <div className="flex flex-col items-center justify-center gap-2 py-12 text-center">
      <span className="bg-destructive/10 text-destructive flex size-12 items-center justify-center rounded-full">
        <AlertTriangle className="size-6" />
      </span>
      <p className="font-medium">{title}</p>
      <p className="text-muted-foreground max-w-sm text-sm">{description}</p>
      {onRetry ? (
        <Button variant="outline" size="sm" className="mt-2" onClick={onRetry}>
          Try again
        </Button>
      ) : null}
    </div>
  );
}
