import type { LucideIcon } from 'lucide-react';

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from '@/lib/utils';

type KpiCardProps = {
  title: string;
  value: string;
  delta?: string;
  trend?: 'up' | 'down' | 'neutral';
  icon: LucideIcon;
};

/**
 * Reusable KPI metric card (placeholder data only).
 */
export function KpiCard({ title, value, delta, trend = 'neutral', icon: Icon }: KpiCardProps) {
  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between gap-2 pb-2">
        <CardTitle className="text-muted-foreground text-sm font-medium">{title}</CardTitle>
        <span className="bg-muted text-muted-foreground flex size-8 items-center justify-center rounded-md">
          <Icon className="size-4" />
        </span>
      </CardHeader>
      <CardContent>
        <div className="text-2xl font-semibold">{value}</div>
        {delta ? (
          <p
            className={cn(
              'mt-1 text-xs',
              trend === 'up' && 'text-emerald-600 dark:text-emerald-400',
              trend === 'down' && 'text-destructive',
              trend === 'neutral' && 'text-muted-foreground',
            )}
          >
            {delta}
          </p>
        ) : null}
      </CardContent>
    </Card>
  );
}
