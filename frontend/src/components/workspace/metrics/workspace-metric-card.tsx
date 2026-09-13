import { Minus, TrendingDown, TrendingUp } from 'lucide-react';

import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';
import type { WorkspaceMetric } from '../types';

type Props = WorkspaceMetric;

function TrendBadge({ trend }: { trend: NonNullable<WorkspaceMetric['trend']> }) {
  const TrendIcon =
    trend.direction === 'up' ? TrendingUp : trend.direction === 'down' ? TrendingDown : Minus;
  return (
    <span
      className={cn(
        'inline-flex items-center gap-0.5 text-[10px] font-semibold',
        trend.direction === 'up' && 'text-emerald-600 dark:text-emerald-400',
        trend.direction === 'down' && 'text-destructive',
        trend.direction === 'neutral' && 'text-muted-foreground',
      )}
    >
      <TrendIcon className="size-3" aria-hidden />
      {Math.abs(trend.value)}%
    </span>
  );
}

export function WorkspaceMetricCard({
  icon: Icon,
  label,
  value,
  trend,
  colorClass = 'bg-primary/10 text-primary',
  onClick,
  active = false,
  isLoading = false,
  compact = false,
}: Props) {
  if (isLoading) {
    return (
      <div className={cn('flex items-center rounded-xl border bg-card', compact ? 'gap-2.5 p-2.5' : 'gap-3 p-4')}>
        <Skeleton className={cn('shrink-0 rounded-lg', compact ? 'size-8' : 'size-10')} />
        <div className="flex-1 space-y-2">
          <Skeleton className="h-3 w-20" />
          <Skeleton className={compact ? 'h-5 w-12' : 'h-6 w-14'} />
        </div>
      </div>
    );
  }

  const isClickable = Boolean(onClick);

  return (
    <button
      type="button"
      onClick={onClick}
      disabled={!isClickable}
      aria-pressed={isClickable ? active : undefined}
      className={cn(
        'flex w-full items-center rounded-xl border bg-card text-start transition-all',
        compact ? 'gap-2.5 p-2.5' : 'gap-3 p-4',
        isClickable && 'cursor-pointer hover:border-primary/40 hover:shadow-md',
        active && 'border-primary shadow-md ring-2 ring-primary/20',
        !isClickable && 'cursor-default',
      )}
    >
      <span
        className={cn(
          'flex shrink-0 items-center justify-center rounded-lg',
          compact ? 'size-8' : 'size-10',
          colorClass,
        )}
        aria-hidden
      >
        <Icon className={compact ? 'size-4' : 'size-5'} />
      </span>

      <div className="min-w-0 flex-1">
        <p className={cn('truncate font-medium text-muted-foreground', compact ? 'text-[11px]' : 'text-xs')}>{label}</p>
        <div className="flex items-baseline gap-1.5">
          <p className={cn('font-bold tabular-nums', compact ? 'text-lg' : 'text-xl')}>{value}</p>
          {trend ? <TrendBadge trend={trend} /> : null}
        </div>
      </div>
    </button>
  );
}
