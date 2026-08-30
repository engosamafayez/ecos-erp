import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import {
  Sheet,
  SheetContent,
  SheetFooter,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet';

export type MobileFilterSheetProps = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** The same filter controls a page renders inline via FilterPanel on desktop. */
  children: ReactNode;
  title?: ReactNode;
  /** Number of active filters — shown on the Apply button. */
  activeCount?: number;
  onClear?: () => void;
  /** Called before the sheet closes on Apply. Filtering is usually live, so this
   *  is optional; when omitted, Apply simply dismisses the sheet. */
  onApply?: () => void;
};

/**
 * MobileFilterSheet — filters in a dismissible bottom sheet (§11).
 *
 * A composition over `Sheet side="bottom"`; no new dependency. Pages branch to
 * it on mobile (via `useIsMobile`) while keeping the inline `FilterPanel` on
 * desktop. Presentation-only: the filter state and controls stay owned by the
 * page.
 */
export function MobileFilterSheet({
  open,
  onOpenChange,
  children,
  title,
  activeCount,
  onClear,
  onApply,
}: MobileFilterSheetProps) {
  const { t } = useTranslation('common');

  const handleApply = () => {
    onApply?.();
    onOpenChange(false);
  };

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent side="bottom" className="max-h-[85vh]">
        <SheetHeader>
          <SheetTitle>{title ?? t(($) => $.toolbar.filters)}</SheetTitle>
        </SheetHeader>

        <div className="flex flex-col gap-4 overflow-y-auto px-4 pb-2">{children}</div>

        <SheetFooter className="justify-between">
          {onClear ? (
            <Button type="button" variant="ghost" onClick={onClear}>
              {t(($) => $.mobile.clearAll)}
            </Button>
          ) : (
            <span />
          )}
          <Button type="button" onClick={handleApply}>
            {activeCount && activeCount > 0
              ? t(($) => $.mobile.applyWithCount, { count: activeCount })
              : t(($) => $.mobile.apply)}
          </Button>
        </SheetFooter>
      </SheetContent>
    </Sheet>
  );
}
