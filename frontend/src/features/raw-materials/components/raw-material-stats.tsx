import { DollarSign, Package, PackageMinus, PackagePlus, Warehouse } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { Skeleton } from '@/components/ui/skeleton';
import { WorkspaceMetricCard } from '@/components/workspace';
import { useCompany } from '@/features/organization/context/company-context';
import { useRawMaterialStats } from '@/features/raw-materials/hooks/use-raw-materials';
import { formatMoneyCompact } from '@/lib/format';
import type { MaterialType, RawMaterialsQuery } from '@/features/raw-materials/types';

function fmtQty(n: number): string {
  if (n >= 1_000_000) return `${(n / 1_000_000).toFixed(1)}M`;
  if (n >= 1_000)     return `${(n / 1_000).toFixed(1)}K`;
  return n.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 3 });
}

type StatsQuery = Pick<RawMaterialsQuery, 'material_type' | 'category_id' | 'supplier_id' | 'warehouse_id'>;

export function RawMaterialStats({ query = {} }: { query?: StatsQuery }) {
  const { t } = useTranslation('raw-materials');
  const { data, isLoading } = useRawMaterialStats(query);
  const { currency, locale } = useCompany();

  const materialType = query.material_type as MaterialType | undefined;
  const label = materialType === 'raw_material'
    ? t($ => $.stats.labelRaw)
    : materialType === 'packaging_material'
      ? t($ => $.stats.labelPackaging)
      : t($ => $.stats.labelAll);

  if (isLoading) {
    return (
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
        {Array.from({ length: 5 }, (_, i) => (
          <Skeleton key={i} className="h-[72px] rounded-xl" />
        ))}
      </div>
    );
  }

  return (
    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
      <WorkspaceMetricCard
        id="total"
        icon={Package}
        label={label}
        value={data?.total_count ?? 0}
        colorClass="text-blue-600 bg-blue-100 dark:text-blue-400 dark:bg-blue-900/30"
      />
      <WorkspaceMetricCard
        id="totalOnHand"
        icon={Warehouse}
        label={t($ => $.stats.totalOnHand)}
        value={fmtQty(data?.total_on_hand ?? 0)}
        colorClass="text-emerald-600 bg-emerald-100 dark:text-emerald-400 dark:bg-emerald-900/30"
      />
      <WorkspaceMetricCard
        id="totalReserved"
        icon={PackageMinus}
        label={t($ => $.stats.totalReserved)}
        value={fmtQty(data?.total_reserved ?? 0)}
        colorClass="text-amber-600 bg-amber-100 dark:text-amber-400 dark:bg-amber-900/30"
      />
      <WorkspaceMetricCard
        id="totalAvailable"
        icon={PackagePlus}
        label={t($ => $.stats.totalAvailable)}
        value={fmtQty(data?.total_available ?? 0)}
        colorClass="text-violet-600 bg-violet-100 dark:text-violet-400 dark:bg-violet-900/30"
      />
      <WorkspaceMetricCard
        id="totalInventoryValue"
        icon={DollarSign}
        label={t($ => $.stats.totalInventoryValue)}
        value={formatMoneyCompact(data?.total_inventory_value ?? 0, currency, locale)}
        colorClass="text-rose-600 bg-rose-100 dark:text-rose-400 dark:bg-rose-900/30"
      />
    </div>
  );
}
