import type { ComponentType, ReactNode } from 'react';
import { useTranslation } from 'react-i18next';

import { EmptyState } from '@/components/crud/empty-state';
import { ErrorState } from '@/components/crud/error-state';
import { Badge } from '@/components/ui/badge';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

export type QueueSectionProps<T> = {
  /** Section title (e.g. "Today's Trips", "Unassigned Orders"). */
  title: ReactNode;
  /** Optional leading icon, shown before the title. */
  icon?: ComponentType<{ className?: string }>;
  /** Item count, shown as a badge next to the title. Omitted (not zero) hides the badge — e.g. while loading. */
  count?: number;
  /** Action slot — e.g. an "Add" button — rendered at the end of the header, never inside the scrollable list. */
  action?: ReactNode;
  items: T[];
  getItemKey: (item: T) => string;
  /** Renders one item. The feature owns this entirely — its business meaning, status, and actions. */
  renderItem: (item: T) => ReactNode;
  loading?: boolean;
  skeletonCount?: number;
  error?: boolean;
  errorState?: ReactNode;
  emptyState?: ReactNode;
  className?: string;
};

/**
 * QueueSection — the canonical shared shell for an operational queue/list
 * panel (TASK-ECOS-V1.1-CORE-01-UI-03-LIST-TABLE-FILTER-WORK-QUEUE-047).
 *
 * Presentation / interaction composition only — header (title + count +
 * action slot), a scrollable item list, and loading/empty/error states. It
 * owns none of an item's business meaning: `renderItem` is supplied by the
 * feature (a Distribution `TripCard`, an `OrderCard`, or anything else), and
 * this component never inspects an item's fields, status, or workflow state,
 * never computes a count or grouping itself, and never fabricates data when
 * the feature has none to show.
 *
 * Derived from two real call sites that already shared this exact shape
 * independently before either was extracted onto this primitive — the
 * Distribution Board's Orders Pool panel and its Today's Trips panel. Only
 * the capabilities those two pages actually use are implemented; multiple
 * simultaneously-stacked "group" sections were not something either proved a
 * need for (Distribution Board shows one zone's trips at a time, switched via
 * its own existing zone tabs) — a page can render more than one QueueSection
 * side by side, which is what "queue/group sections" means here, rather than
 * this component owning a multi-group API nothing yet needs.
 */
export function QueueSection<T>({
  title,
  icon: Icon,
  count,
  action,
  items,
  getItemKey,
  renderItem,
  loading = false,
  skeletonCount = 6,
  error = false,
  errorState,
  emptyState,
  className,
}: QueueSectionProps<T>) {
  const { t } = useTranslation('common');

  return (
    <div className={cn('flex h-full min-h-0 flex-col', className)}>
      {/* Header */}
      <div className="flex shrink-0 items-center justify-between gap-2 border-b px-3 py-2.5">
        <div className="flex min-w-0 items-center gap-2">
          {Icon ? <Icon className="size-4 shrink-0 text-muted-foreground" /> : null}
          <span className="truncate text-sm font-medium">{title}</span>
          {!loading && count !== undefined ? (
            <Badge variant={count > 0 ? 'secondary' : 'outline'} className="shrink-0 text-xs tabular-nums">
              {count}
            </Badge>
          ) : null}
        </div>
        {action ? <div className="flex shrink-0 items-center gap-2">{action}</div> : null}
      </div>

      {/* Content */}
      <div className="min-h-0 flex-1 overflow-y-auto">
        <div className="p-2">
          {loading ? (
            <div className="space-y-1.5">
              {Array.from({ length: skeletonCount }, (_, i) => (
                <Skeleton key={i} className="h-20 w-full rounded-lg" />
              ))}
            </div>
          ) : error ? (
            errorState ?? <ErrorState />
          ) : items.length === 0 ? (
            emptyState ?? <EmptyState title={t($ => $.table.noRecords)} />
          ) : (
            <div role="list" className="space-y-1.5">
              {items.map((item) => (
                <div role="listitem" key={getItemKey(item)}>
                  {renderItem(item)}
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
