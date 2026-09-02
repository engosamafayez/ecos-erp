import { AlertTriangle, CheckCircle, Clock } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { MobileDataCard, type MobileDataCardField } from '@/components/mobile';

import type { WasteInvestigation } from '../types/inventory-count';

type Props = {
  investigation: WasteInvestigation;
  onOpen: (investigation: WasteInvestigation) => void;
  onResolve: (investigation: WasteInvestigation) => void;
};

const OUTCOME_LABEL_KEY: Record<string, 'operational' | 'warehouse' | 'supplier' | 'preparation'> = {
  operational_waste: 'operational',
  warehouse_responsibility: 'warehouse',
  supplier_responsibility: 'supplier',
  preparation_responsibility: 'preparation',
};

/**
 * Mobile card for a Waste Investigation row — built on the shared
 * MobileDataCard primitive (TASK-ECOS-MOBILE-REMAINING-PAGES-WAREHOUSE-
 * EXCEPTIONS-002), mirroring the desktop table's own columns 1:1 so no
 * operational data is dropped: product, quantity, value (FIFO-snapshotted
 * when available), damage reason, warehouse, status + SLA badge, outcome.
 * Tapping the card opens the same `WasteInvestigationDetailDrawer` the
 * desktop Eye button opens; Resolve stays a distinct, explicit action since
 * it opens a different (mutating) dialog, not the read-only detail view.
 */
export function WasteInvestigationMobileCard({ investigation: inv, onOpen, onResolve }: Props) {
  const { t } = useTranslation('inventory-count');

  const value = inv.cost_snapshot_total_value ?? inv.total_cost;
  const isResolved = inv.status === 'resolved';

  const fields: MobileDataCardField[] = [
    {
      label: t($ => $.waste.table.quantity),
      align: 'end',
      value: Number(inv.quantity).toFixed(2),
    },
    {
      label: t($ => $.waste.table.value),
      align: 'end',
      value: Number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
    },
    {
      label: t($ => $.waste.table.damageReason),
      fullWidth: true,
      value: inv.damage_reason ?? <span className="text-muted-foreground">—</span>,
    },
    {
      label: t($ => $.waste.table.warehouse),
      value: (inv.warehouse as { name: string } | null)?.name ?? <span className="text-muted-foreground">—</span>,
    },
    ...(inv.outcome
      ? [{
          label: t($ => $.waste.table.outcome),
          value: t($ => $.waste.outcomes[OUTCOME_LABEL_KEY[inv.outcome!] ?? 'operational']),
        } satisfies MobileDataCardField]
      : []),
  ];

  return (
    <MobileDataCard
      title={inv.product?.name ?? '—'}
      subtitle={inv.product?.sku}
      status={
        <div className="flex flex-col items-end gap-1">
          {isResolved ? (
            <Badge variant="outline" className="text-emerald-600 border-emerald-200 bg-emerald-50 dark:bg-emerald-950/20 text-xs">
              <CheckCircle className="size-3 mr-1" />{t($ => $.waste.status.resolved)}
            </Badge>
          ) : (
            <Badge variant="outline" className="text-amber-600 border-amber-200 bg-amber-50 dark:bg-amber-950/20 text-xs">
              <Clock className="size-3 mr-1" />{t($ => $.waste.status.pending)}
            </Badge>
          )}
          {!isResolved && inv.is_overdue_7 && (
            <span className="inline-flex items-center gap-1 rounded-full bg-destructive/10 text-destructive px-2 py-0.5 text-[11px] font-medium">
              <AlertTriangle className="size-2.5" /> {t($ => $.waste.sla.overdue7)}
            </span>
          )}
          {!isResolved && !inv.is_overdue_7 && inv.is_overdue_3 && (
            <span className="inline-flex items-center gap-1 rounded-full bg-amber-100 text-amber-700 dark:bg-amber-950/30 dark:text-amber-300 px-2 py-0.5 text-[11px] font-medium">
              <Clock className="size-2.5" /> {t($ => $.waste.sla.overdue3)}
            </span>
          )}
        </div>
      }
      fields={fields}
      onOpen={() => onOpen(inv)}
      openLabel={t($ => $.waste.viewDetails)}
      actions={
        !isResolved ? (
          <Button size="sm" variant="outline" className="h-7 text-xs" onClick={() => onResolve(inv)}>
            {t($ => $.waste.resolve)}
          </Button>
        ) : undefined
      }
    />
  );
}
