import type { ReactNode } from 'react';
import { ArrowLeft } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';

export type MobilePageHeaderProps = {
  /** Current page/section title. */
  title: ReactNode;
  /** Shown when present — a fixed back affordance, never a full nav reset. */
  onBack?: () => void;
  /** Accessible label for the back button. Defaults to "Back". */
  backLabel?: string;
  /** Trailing contextual actions (icon buttons, a menu trigger). */
  actions?: ReactNode;
  className?: string;
};

/**
 * MobilePageHeader — the one sticky header pattern for mobile screens
 * (TASK-ECOS-MOBILE-UX-COMPLETION-002, parent design report §5/§18).
 *
 * Extracted from the Modules launcher's drill-in header (`mobile-module-pages.tsx`)
 * so a future full-screen detail screen (Task 3+: Customer/Product/Order
 * detail) reuses the SAME back+title+actions header instead of a bespoke one
 * per page — the parent design report's §18 "one consistent details pattern"
 * requirement. Presentation-only: no route logic, no business rule.
 */
export function MobilePageHeader({ title, onBack, backLabel, actions, className }: MobilePageHeaderProps) {
  const { t } = useTranslation('common');

  return (
    <div className={cn('flex h-12 shrink-0 items-center gap-2 border-b px-2', className)}>
      {onBack ? (
        <Button
          variant="ghost"
          size="icon"
          onClick={onBack}
          aria-label={backLabel ?? t(($) => $.actions.back)}
          className="shrink-0"
        >
          <ArrowLeft className="size-5" aria-hidden data-flip-rtl />
        </Button>
      ) : null}
      <span className="min-w-0 flex-1 truncate text-base font-semibold text-foreground">{title}</span>
      {actions ? <div className="flex shrink-0 items-center gap-1">{actions}</div> : null}
    </div>
  );
}
