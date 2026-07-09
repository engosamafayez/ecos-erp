import { ShoppingCart, CheckCircle, XCircle, TrendingUp, Calendar } from 'lucide-react';

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { CustomerLookupStats } from '@/features/orders/types/order';

function fmt(n: number) {
  return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

type Props = {
  stats: CustomerLookupStats;
  customerName: string;
};

export function OrderCustomerIntelligencePanel({ stats, customerName }: Props) {
  return (
    <Card className="border-blue-200 bg-blue-50/50 dark:border-blue-800 dark:bg-blue-950/20">
      <CardHeader className="pb-3">
        <CardTitle className="text-sm font-medium text-blue-700 dark:text-blue-300">
          Customer Intelligence — {customerName}
        </CardTitle>
      </CardHeader>
      <CardContent>
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
          <IntelStat
            icon={<ShoppingCart className="size-4 text-blue-500" />}
            label="Total Orders"
            value={String(stats.total_orders)}
          />
          <IntelStat
            icon={<CheckCircle className="size-4 text-emerald-500" />}
            label="Delivered"
            value={String(stats.delivered)}
          />
          <IntelStat
            icon={<XCircle className="size-4 text-red-500" />}
            label="Cancelled"
            value={String(stats.cancelled)}
          />
          <IntelStat
            icon={<TrendingUp className="size-4 text-violet-500" />}
            label="Success Rate"
            value={`${stats.success_rate}%`}
            highlight={stats.success_rate >= 80 ? 'good' : stats.success_rate < 50 ? 'bad' : 'neutral'}
          />
          <IntelStat
            icon={<TrendingUp className="size-4 text-amber-500" />}
            label="Lifetime Value"
            value={fmt(stats.lifetime_value)}
          />
          {stats.last_order_date && (
            <IntelStat
              icon={<Calendar className="size-4 text-slate-500" />}
              label="Last Order"
              value={stats.last_order_date}
            />
          )}
        </div>
      </CardContent>
    </Card>
  );
}

function IntelStat({
  icon,
  label,
  value,
  highlight,
}: {
  icon: React.ReactNode;
  label: string;
  value: string;
  highlight?: 'good' | 'bad' | 'neutral';
}) {
  const valueClass =
    highlight === 'good'
      ? 'text-emerald-700 dark:text-emerald-400'
      : highlight === 'bad'
        ? 'text-red-700 dark:text-red-400'
        : 'text-foreground';

  return (
    <div className="flex flex-col gap-0.5 rounded-md bg-background/60 px-3 py-2">
      <div className="flex items-center gap-1.5 text-muted-foreground">
        {icon}
        <span className="text-xs">{label}</span>
      </div>
      <span className={`text-sm font-semibold tabular-nums ${valueClass}`}>{value}</span>
    </div>
  );
}
