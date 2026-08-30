import { CheckCircle2, Clock, Package, ShieldAlert, WifiOff } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { QuickStatCard } from '@/components/ds/quick-stat-card';
import type { ProductStatusFilter, ProductType } from '@/features/products/types/product';

export type StatFilter =
  | { type: 'status'; value: ProductStatusFilter }
  | { type: 'is_published'; value: boolean }
  | { type: 'low_stock'; value: boolean }
  | { type: 'not_synced'; value: boolean }
  | { type: 'product_type'; value: ProductType }
  | { type: 'manufacturing_ready'; value: boolean }
  | { type: 'missing_recipe'; value: boolean }
  | { type: 'needs_pricing_review'; value: boolean }
  | { type: 'low_margin'; value: boolean }
  | { type: 'mfg_instock'; value: true }
  | { type: 'mfg_outofstock'; value: true }
  | { type: 'mfg_recipe_missing'; value: true };

/**
 * TASK-PRODUCTS-WORKSPACE-COST-KPI-REFINEMENT-001 — seven metrics removed:
 * published, inactive, manufacturingReady, lowMargin, mfgRecipeMissing,
 * missingRecipe (duplicated in the Pricing tab) and lowStock.
 *
 * Each was backed by its own `useProductStats` list query, so dropping them
 * removes SEVEN network round-trips per page load (12 → 5), not just seven cards.
 *
 * `is_published` survives as a FILTER and a COLUMN — only the KPI card is gone.
 */
export type ProductStatsData = {
  total: number;
  notSynced: number;
  needsPricingReview: number;
  mfgInStock: number;
  mfgOutOfStock: number;
};

type ProductQuickStatsProps = {
  stats: ProductStatsData;
  activeFilter: StatFilter | null;
  onFilterChange: (filter: StatFilter | null) => void;
};

export function ProductQuickStats({ stats, activeFilter, onFilterChange }: ProductQuickStatsProps) {
  const { t } = useTranslation('products');

  const toggle = (next: StatFilter) => {
    const isActive =
      activeFilter?.type === next.type && activeFilter.value === next.value;
    onFilterChange(isActive ? null : next);
  };

  const isActive = (filter: StatFilter) =>
    activeFilter?.type === filter.type && activeFilter.value === filter.value;

  return (
    /*
     * Single row on desktop (PART 1). 2 → 3 → 5 columns as width allows, so the
     * five cards share the width evenly at `lg` and never wrap to a second row
     * or force horizontal scrolling. `compact` is the opt-in DS variant, so the
     * six other QuickStatCard consumers are untouched.
     */
    <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
        <QuickStatCard
          compact
          icon={Package}
          title={t($ => $.quickStats.totalProducts)}
          value={stats.total}
          colorClassName="text-primary bg-primary/10"
          active={activeFilter === null}
          onClick={() => onFilterChange(null)}
        />
        <QuickStatCard
          compact
          icon={WifiOff}
          title={t($ => $.quickStats.notSynced)}
          value={stats.notSynced}
          colorClassName="text-red-600 bg-red-100 dark:text-red-400 dark:bg-red-900/30"
          active={isActive({ type: 'not_synced', value: true })}
          onClick={() => toggle({ type: 'not_synced', value: true })}
        />
        <QuickStatCard
          compact
          icon={Clock}
          title={t($ => $.quickStats.pendingReview)}
          value={stats.needsPricingReview}
          colorClassName="text-violet-600 bg-violet-100 dark:text-violet-400 dark:bg-violet-900/30"
          active={isActive({ type: 'needs_pricing_review', value: true })}
          onClick={() => toggle({ type: 'needs_pricing_review', value: true })}
        />
        <QuickStatCard
          compact
          icon={CheckCircle2}
          title={t($ => $.quickStats.mfgInStock)}
          value={stats.mfgInStock}
          colorClassName="text-emerald-600 bg-emerald-100 dark:text-emerald-400 dark:bg-emerald-900/30"
          active={isActive({ type: 'mfg_instock', value: true })}
          onClick={() => toggle({ type: 'mfg_instock', value: true })}
        />
        <QuickStatCard
          compact
          icon={ShieldAlert}
          title={t($ => $.quickStats.mfgOutOfStock)}
          value={stats.mfgOutOfStock}
          colorClassName="text-red-600 bg-red-100 dark:text-red-400 dark:bg-red-900/30"
          active={isActive({ type: 'mfg_outofstock', value: true })}
          onClick={() => toggle({ type: 'mfg_outofstock', value: true })}
        />
    </div>
  );
}
