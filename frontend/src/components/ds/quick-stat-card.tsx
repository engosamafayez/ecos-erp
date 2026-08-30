import type { LucideIcon } from 'lucide-react';

import { cn } from '@/lib/utils';

type QuickStatCardProps = {
  icon: LucideIcon;
  title: string;
  value: number | string;
  /** Applies an active / selected highlight ring */
  active?: boolean;
  onClick?: () => void;
  colorClassName?: string;
  /**
   * Denser variant for pages carrying several KPI rows.
   *
   * Opt-in on purpose: this component has six consumers, and shrinking it for
   * everyone would silently restyle workspaces this change was never about.
   * Padding, icon and type scale step down together so the card stays legible
   * rather than merely smaller.
   */
  compact?: boolean;
};

/**
 * Clickable KPI card used at the top of list pages (Quick Stats row).
 * Clicking a card applies a filter to the list below it.
 */
export function QuickStatCard({
  icon: Icon,
  title,
  value,
  active = false,
  onClick,
  colorClassName = 'text-primary bg-primary/10',
  compact = false,
}: QuickStatCardProps) {
  const isClickable = Boolean(onClick);

  return (
    <button
      type="button"
      onClick={onClick}
      disabled={!isClickable}
      aria-pressed={active}
      className={cn(
        'flex w-full items-center rounded-xl border bg-card text-start shadow-xs transition-all',
        compact ? 'gap-2.5 p-2.5' : 'gap-3 p-4',
        isClickable && 'cursor-pointer hover:shadow-md hover:border-primary/40',
        active && 'border-primary ring-2 ring-primary/20 shadow-md',
        !isClickable && 'cursor-default',
      )}
    >
      <span
        className={cn(
          'flex shrink-0 items-center justify-center rounded-lg',
          compact ? 'size-8' : 'size-10',
          colorClassName,
        )}
      >
        <Icon className={compact ? 'size-4' : 'size-5'} aria-hidden />
      </span>

      <div className="min-w-0">
        <p className={cn('truncate font-medium text-muted-foreground', compact ? 'text-[11px]' : 'text-xs')}>{title}</p>
        <p className={cn('font-bold tabular-nums text-foreground', compact ? 'text-lg' : 'text-xl')}>{value}</p>
      </div>
    </button>
  );
}
