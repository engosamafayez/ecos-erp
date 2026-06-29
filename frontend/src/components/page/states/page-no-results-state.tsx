import { SearchX } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

type PageNoResultsStateProps = {
  query?: string;
  description?: string;
  onClear?: () => void;
  className?: string;
};

/**
 * State shown when a search or filter returns zero results.
 * Distinct from PageEmptyState (no data at all) — this is "data exists but
 * current query/filter matched nothing."
 *
 * Usage:
 *   <PageNoResultsState query={search} onClear={clearFilters} />
 */
export function PageNoResultsState({
  query,
  description,
  onClear,
  className,
}: PageNoResultsStateProps) {
  const defaultDescription = query
    ? `No matches for "${query}". Try different keywords or clear your filters.`
    : 'No items match your current filters. Try adjusting or clearing them.';

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
        <p className="text-base font-semibold">No results found</p>
        <p className="mx-auto max-w-xs text-sm text-muted-foreground">
          {description ?? defaultDescription}
        </p>
      </div>
      {onClear ? (
        <Button variant="outline" size="sm" onClick={onClear} className="mt-1">
          Clear filters
        </Button>
      ) : null}
    </div>
  );
}
