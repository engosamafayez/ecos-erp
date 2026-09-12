import { AlertTriangle } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';

type ErrorStateProps = {
  title?: string;
  description?: string;
  onRetry?: () => void;
};

/**
 * Reusable error placeholder for a *read failure* — distinct from EmptyState
 * (the request succeeded, there's just no data) per
 * TASK-ECOS-V1.1-CORE-01-UI-01-CANONICAL-FOUNDATION-045 (ticket §6): a read
 * failure must never be rendered as an empty dataset. Deliberately exposes
 * only `onRetry` (a read retry) — there is no generic `action` slot here, so
 * a mutation CTA cannot become available merely because a read failed.
 */
export function ErrorState({ title, description, onRetry }: ErrorStateProps) {
  const { t } = useTranslation('common');
  const displayTitle = title ?? t($ => $.error.title);
  const displayDescription = description ?? t($ => $.error.description);

  return (
    <div className="flex flex-col items-center justify-center gap-2 py-12 text-center">
      <span className="bg-destructive/10 text-destructive flex size-12 items-center justify-center rounded-full">
        <AlertTriangle className="size-6" />
      </span>
      <p className="font-medium">{displayTitle}</p>
      <p className="text-muted-foreground max-w-sm text-sm">{displayDescription}</p>
      {onRetry ? (
        <Button variant="outline" size="sm" className="mt-2" onClick={onRetry}>
          {t($ => $.error.retry)}
        </Button>
      ) : null}
    </div>
  );
}
