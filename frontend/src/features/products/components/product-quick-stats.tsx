import { AlertTriangle, Package, TrendingDown, WifiOff, XCircle } from 'lucide-react';

import { QuickStatCard } from '@/components/ds/quick-stat-card';
import type { ProductStatusFilter, ProductType } from '@/features/products/types/product';

export type StatFilter =
  | { type: 'status'; value: ProductStatusFilter }
  | { type: 'is_published'; value: boolean }
  | { type: 'low_stock'; value: boolean }
  | { type: 'not_synced'; value: boolean }
  | { type: 'product_type'; value: ProductType };

export type ProductStatsData = {
  total: number;
  published: number;
  lowStock: number;
  notSynced: number;
  inactive: number;
};

type ProductQuickStatsProps = {
  stats: ProductStatsData;
  activeFilter: StatFilter | null;
  onFilterChange: (filter: StatFilter | null) => void;
};

export function ProductQuickStats({ stats, activeFilter, onFilterChange }: ProductQuickStatsProps) {
  const toggle = (next: StatFilter) => {
    const isActive =
      activeFilter?.type === next.type && activeFilter.value === next.value;
    onFilterChange(isActive ? null : next);
  };

  const isActive = (filter: StatFilter) =>
    activeFilter?.type === filter.type && activeFilter.value === filter.value;

  return (
    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
      <QuickStatCard
        icon={Package}
        title="Total Products"
        value={stats.total}
        colorClassName="text-primary bg-primary/10"
        active={activeFilter === null}
        onClick={() => onFilterChange(null)}
      />
      <QuickStatCard
        icon={TrendingDown}
        title="Published"
        value={stats.published}
        colorClassName="text-sky-600 bg-sky-100 dark:text-sky-400 dark:bg-sky-900/30"
        active={isActive({ type: 'is_published', value: true })}
        onClick={() => toggle({ type: 'is_published', value: true })}
      />
      <QuickStatCard
        icon={AlertTriangle}
        title="Low Stock"
        value={stats.lowStock}
        colorClassName="text-amber-600 bg-amber-100 dark:text-amber-400 dark:bg-amber-900/30"
        active={isActive({ type: 'low_stock', value: true })}
        onClick={() => toggle({ type: 'low_stock', value: true })}
      />
      <QuickStatCard
        icon={WifiOff}
        title="Not Synced"
        value={stats.notSynced}
        colorClassName="text-red-600 bg-red-100 dark:text-red-400 dark:bg-red-900/30"
        active={isActive({ type: 'not_synced', value: true })}
        onClick={() => toggle({ type: 'not_synced', value: true })}
      />
      <QuickStatCard
        icon={XCircle}
        title="Inactive"
        value={stats.inactive}
        colorClassName="text-muted-foreground bg-muted"
        active={isActive({ type: 'status', value: 'inactive' })}
        onClick={() => toggle({ type: 'status', value: 'inactive' })}
      />
    </div>
  );
}
