import { DollarSign, Package, PackageMinus, PackagePlus, Warehouse } from 'lucide-react';

import { QuickStatCard } from '@/components/ds/quick-stat-card';
import { Skeleton } from '@/components/ui/skeleton';
import { useCompany } from '@/features/organization/context/company-context';
import { useRawMaterialStats } from '@/features/raw-materials/hooks/use-raw-materials';
import { formatMoneyCompact } from '@/lib/format';
import type { MaterialType, RawMaterialsQuery } from '@/features/raw-materials/types';

function fmtQty(n: number): string {
  if (n >= 1_000_000) return `${(n / 1_000_000).toFixed(1)}M`;
  if (n >= 1_000)     return `${(n / 1_000).toFixed(1)}K`;
  return n.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 3 });
}

function materialsLabel(materialType: MaterialType | undefined): string {
  if (materialType === 'raw_material')       return 'Raw Materials';
  if (materialType === 'packaging_material') return 'Packaging Materials';
  return 'All Materials';
}

type StatsQuery = Pick<RawMaterialsQuery, 'material_type' | 'category_id' | 'supplier_id' | 'warehouse_id'>;

export function RawMaterialStats({ query = {} }: { query?: StatsQuery }) {
  const { data, isLoading } = useRawMaterialStats(query);
  const { currency, locale } = useCompany();
  const label = materialsLabel(query.material_type || undefined);

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
      <QuickStatCard
        icon={Package}
        title={label}
        value={data?.total_count ?? 0}
        colorClassName="text-blue-600 bg-blue-100 dark:text-blue-400 dark:bg-blue-900/30"
      />
      <QuickStatCard
        icon={Warehouse}
        title="Total On Hand"
        value={fmtQty(data?.total_on_hand ?? 0)}
        colorClassName="text-emerald-600 bg-emerald-100 dark:text-emerald-400 dark:bg-emerald-900/30"
      />
      <QuickStatCard
        icon={PackageMinus}
        title="Total Reserved"
        value={fmtQty(data?.total_reserved ?? 0)}
        colorClassName="text-amber-600 bg-amber-100 dark:text-amber-400 dark:bg-amber-900/30"
      />
      <QuickStatCard
        icon={PackagePlus}
        title="Total Available"
        value={fmtQty(data?.total_available ?? 0)}
        colorClassName="text-violet-600 bg-violet-100 dark:text-violet-400 dark:bg-violet-900/30"
      />
      <QuickStatCard
        icon={DollarSign}
        title="Total Inventory Value"
        value={formatMoneyCompact(data?.total_inventory_value ?? 0, currency, locale)}
        colorClassName="text-rose-600 bg-rose-100 dark:text-rose-400 dark:bg-rose-900/30"
      />
    </div>
  );
}
