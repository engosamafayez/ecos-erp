import type { ReactNode } from 'react';

import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
  SheetFooter,
} from '@/components/ui/sheet';
import { cn } from '@/lib/utils';

// ── Detail sheet ─────────────────────────────────────────────────────────────

export type MobileDetailSheetProps = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: ReactNode;
  /** Muted line under the title (code / date / secondary id). */
  subtitle?: ReactNode;
  /** Status slot next to the title — the consumer supplies its own badge. */
  status?: ReactNode;
  /** Sticky footer action bar (primary action + overflow). */
  footer?: ReactNode;
  /** Stacked sections — use MobileDetailSection / MobileDetailFacts. */
  children: ReactNode;
  className?: string;
};

/**
 * MobileDetailSheet — the standard record detail container (§13).
 *
 * A composition over `Sheet side="right"` (already full-width on mobile). It
 * standardises the CHROME of a detail view — scrollable stacked body + sticky
 * footer actions — while the page supplies the sections. Dense two-column facts
 * and `<table>` line-items should be rendered as `MobileDetailFacts` and card
 * lists rather than tables.
 */
export function MobileDetailSheet({
  open,
  onOpenChange,
  title,
  subtitle,
  status,
  footer,
  children,
  className,
}: MobileDetailSheetProps) {
  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent side="right" className={cn('gap-0 p-0', className)}>
        <SheetHeader className="border-b">
          <div className="flex items-start justify-between gap-2 pe-8">
            <SheetTitle className="text-lg">{title}</SheetTitle>
            {status ? <span className="shrink-0">{status}</span> : null}
          </div>
          {subtitle ? <SheetDescription>{subtitle}</SheetDescription> : null}
        </SheetHeader>

        <div className="flex-1 overflow-y-auto">{children}</div>

        {footer ? <SheetFooter className="border-t">{footer}</SheetFooter> : null}
      </SheetContent>
    </Sheet>
  );
}

// ── Section ──────────────────────────────────────────────────────────────────

export type MobileDetailSectionProps = {
  title?: ReactNode;
  children: ReactNode;
  className?: string;
};

/** A titled block inside a MobileDetailSheet. */
export function MobileDetailSection({ title, children, className }: MobileDetailSectionProps) {
  return (
    <section className={cn('border-b p-4 last:border-0', className)}>
      {title ? (
        <h3 className="mb-2 text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
          {title}
        </h3>
      ) : null}
      {children}
    </section>
  );
}

// ── Facts ────────────────────────────────────────────────────────────────────

export type MobileDetailFact = {
  label: ReactNode;
  value: ReactNode;
  align?: 'start' | 'end';
};

export type MobileDetailFactsProps = {
  facts: MobileDetailFact[];
  className?: string;
};

/**
 * A label/value definition list — the mobile replacement for a dense
 * two-column facts table. Labels sit at the start, values at the end so long
 * records stay scannable; RTL mirrors automatically.
 */
export function MobileDetailFacts({ facts, className }: MobileDetailFactsProps) {
  return (
    <dl className={cn('grid grid-cols-[auto_1fr] gap-x-4 gap-y-2.5', className)}>
      {facts.map((fact, index) => (
        <div key={index} className="col-span-2 grid grid-cols-subgrid items-baseline">
          <dt className="text-sm text-muted-foreground">{fact.label}</dt>
          <dd
            className={cn(
              'text-sm',
              fact.align === 'end' ? 'text-end tabular-nums' : 'text-start',
            )}
          >
            {fact.value}
          </dd>
        </div>
      ))}
    </dl>
  );
}
