import type { LucideIcon } from 'lucide-react';
import { ArrowUpRight } from 'lucide-react';

import { Card } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

export type KpiTileStatus = 'value' | 'loading' | 'error' | 'unavailable';

export type KpiTileCardProps = {
  label: string;
  icon: LucideIcon;
  status: KpiTileStatus;
  /** The real count. Only rendered when `status === 'value'`. */
  value?: number | null;
  /** Shown under the label — an honest caption for why there's no count, or a short hint. */
  caption?: string;
  /** Text read where the count would sit when the read failed. */
  errorLabel?: string;
  onClick: () => void;
  testId?: string;
  /** Visual emphasis only — never changes what data is shown. */
  tone?: 'default' | 'warning' | 'danger';
};

const TONE_VALUE_CLASS: Record<NonNullable<KpiTileCardProps['tone']>, string> = {
  default: 'text-foreground',
  warning: 'text-amber-600 dark:text-amber-400',
  danger: 'text-destructive',
};

/**
 * One Control Tower KPI tile.
 *
 * Every tile navigates on click — a real deep link into the owning workspace —
 * whether or not it carries a count. `status: 'unavailable'` is a deliberate,
 * honest state (no clean cross-entity aggregate exists yet), never a fabricated
 * zero; `status: 'error'` renders a dash rather than pretending the value is 0.
 */
export function KpiTileCard({
  label,
  icon: Icon,
  status,
  value,
  caption,
  errorLabel,
  onClick,
  testId,
  tone = 'default',
}: KpiTileCardProps) {
  return (
    <Card
      role="button"
      tabIndex={0}
      onClick={onClick}
      onKeyDown={(e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          onClick();
        }
      }}
      className="group flex h-full cursor-pointer flex-col gap-2 p-4 text-start transition-colors hover:border-primary/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
      data-testid={testId}
    >
      <div className="flex items-start justify-between gap-2">
        <span className="flex size-8 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
          <Icon className="size-4" aria-hidden />
        </span>
        <ArrowUpRight
          className="size-3.5 shrink-0 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100"
          aria-hidden
        />
      </div>

      <div className="mt-1">
        {status === 'loading' ? (
          <Skeleton className="h-8 w-16" />
        ) : status === 'error' ? (
          <span className="text-2xl font-semibold tabular-nums text-muted-foreground">
            {errorLabel ?? '—'}
          </span>
        ) : status === 'value' ? (
          <span className={cn('text-2xl font-semibold tabular-nums', TONE_VALUE_CLASS[tone])}>
            {value ?? 0}
          </span>
        ) : null}
      </div>

      <p className="text-sm font-medium leading-tight text-foreground">{label}</p>
      {caption ? <p className="text-xs leading-snug text-muted-foreground">{caption}</p> : null}
    </Card>
  );
}
