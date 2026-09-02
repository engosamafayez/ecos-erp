import { useTranslation } from 'react-i18next';

import { Badge } from '@/components/ui/badge';

import type { CostAllocationMethod } from '../types/finance-cost-allocation';

/**
 * Allocation method — fixed amount or percentage split. This is a data
 * classification, not a lifecycle status (cost allocations have no status
 * field), so it uses the plain Badge rather than StatusBadge's dot variants.
 */
export function CostAllocationMethodBadge({ method }: { method: CostAllocationMethod }) {
  const { t } = useTranslation('finance');
  return <Badge variant="outline">{t(($) => $.costAllocation.method[method])}</Badge>;
}

/**
 * Renders a uuid reference (source Expense, or an allocation being reversed)
 * shortened with the exact backend identifier in the tooltip — mirrors
 * SupplierRef (ap-badges.tsx): Finance shows ids verbatim rather than
 * inventing a label for something it cannot resolve.
 */
export function CostAllocationIdRef({ id }: { id: string }) {
  return (
    <span className="font-mono text-xs" title={id}>
      {id.slice(0, 8)}…
    </span>
  );
}
