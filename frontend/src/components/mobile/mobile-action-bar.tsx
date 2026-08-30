import type { ReactNode } from 'react';
import { X } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

export type MobileActionBarProps = {
  /** Number of selected rows. The bar renders only when this is > 0. */
  count: number;
  onClear?: () => void;
  /** The 1–2 most common bulk actions (+ an overflow menu for the rest). */
  children: ReactNode;
  className?: string;
};

/**
 * MobileActionBar — sticky selection/bulk-action bar (§12).
 *
 * Appears above the 56px bottom nav (`bottom-14`) on mobile only when rows are
 * selected, showing the count, a clear affordance and the primary bulk actions.
 * Mirrors the grid's existing selection API; holds no business logic. Every
 * action is tap-reachable — nothing hover-gated.
 */
export function MobileActionBar({ count, onClear, children, className }: MobileActionBarProps) {
  const { t } = useTranslation('common');

  if (count <= 0) return null;

  return (
    <div
      role="toolbar"
      aria-label={t(($) => $.selection.bulkActionsFor)}
      className={cn(
        'fixed inset-x-0 bottom-14 z-40 flex items-center gap-2 border-t bg-background p-3 shadow-lg md:hidden',
        'pb-[max(0.75rem,env(safe-area-inset-bottom))]',
        className,
      )}
    >
      {onClear ? (
        <Button
          type="button"
          variant="ghost"
          size="icon"
          onClick={onClear}
          aria-label={t(($) => $.selection.clear)}
        >
          <X className="size-4" />
        </Button>
      ) : null}

      <span className="text-sm font-medium">
        {t(($) => $.mobile.selectedCount, { count })}
      </span>

      <div className="ms-auto flex items-center gap-2">{children}</div>
    </div>
  );
}
