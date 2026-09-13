import { SearchX } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

type NoResultsStateProps = {
  query?: string;
  description?: string;
  onClear?: () => void;
  className?: string;
};

/**
 * State shown when a search or filter returns zero results. Distinct from
 * EmptyState (no data at all) — this is "data exists but the current
 * query/filter matched nothing." Read failure is a third, separate state —
 * see ErrorState — and must never be rendered as this one or as EmptyState.
 *
 * Canonicalized here (TASK-ECOS-V1.1-CORE-01-UI-01-CANONICAL-FOUNDATION-045)
 * from `components/page/states/page-no-results-state.tsx`, which now
 * re-exports this implementation for its existing consumer.
 *
 * Usage:
 *   <NoResultsState query={search} onClear={clearFilters} />
 */
export function NoResultsState({ query, description, onClear, className }: NoResultsStateProps) {
  const { t } = useTranslation('common');
  const defaultDescription = query
    ? t($ => $.noResults.withQuery, { query })
    : t($ => $.noResults.withoutQuery);

  return (
    <div
      className={cn(
        'flex flex-col items-center justify-center gap-3 py-20 text-center',
        className,
      )}
    >
      <span className="flex size-16 items-center justify-center rounded-full bg-muted text-muted-foreground">
        <SearchX className="size-8" aria-hidden />
      </span>
      <div className="space-y-1">
        <p className="text-base font-semibold">{t($ => $.noResults.title)}</p>
        <p className="mx-auto max-w-xs text-sm text-muted-foreground">
          {description ?? defaultDescription}
        </p>
      </div>
      {onClear ? (
        <Button variant="outline" size="sm" onClick={onClear} className="mt-1">
          {t($ => $.toolbar.clearFilters)}
        </Button>
      ) : null}
    </div>
  );
}
