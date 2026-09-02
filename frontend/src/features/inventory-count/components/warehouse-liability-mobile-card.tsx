import { CheckCircle, Clock, XCircle } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { MobileDataCard, type MobileDataCardField } from '@/components/mobile';

import type { WarehouseLiability } from '../types/inventory-count';

type Props = {
  liability: WarehouseLiability;
  onOpen: (liability: WarehouseLiability) => void;
  onApprove: (liability: WarehouseLiability) => void;
  onReject: (liability: WarehouseLiability) => void;
};

/**
 * Mobile card for a Warehouse Liability row — built on the shared
 * MobileDataCard primitive (TASK-ECOS-MOBILE-REMAINING-PAGES-WAREHOUSE-
 * EXCEPTIONS-002), mirroring the desktop table's own columns 1:1: product,
 * type, quantity, total cost (FIFO-snapshotted when available), warehouse,
 * manager, status. Tapping the card opens the new
 * `WarehouseLiabilityDetailDrawer` (the desktop table gained a matching Eye
 * button as a parity fix — this page never had a detail view before).
 * Approve/Reject stay explicit actions since they mutate and open a dialog,
 * not the read-only detail view.
 */
export function WarehouseLiabilityMobileCard({ liability: lib, onOpen, onApprove, onReject }: Props) {
  const { t } = useTranslation('inventory-count');

  const value = lib.cost_snapshot_total_value ?? lib.total_cost;
  const isPending = lib.status === 'pending';

  const fields: MobileDataCardField[] = [
    {
      label: t($ => $.liability.table.type),
      value: t($ => $.liability.types[lib.liability_type === 'waste_transferred' ? 'waste_transferred' : 'shortage']),
    },
    {
      label: t($ => $.liability.table.quantity),
      align: 'end',
      value: Number(lib.quantity).toFixed(2),
    },
    {
      label: t($ => $.liability.table.totalCost),
      align: 'end',
      fullWidth: true,
      value: (
        <div className="flex flex-col items-end leading-tight">
          <span>{Number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
          {lib.cost_snapshot_total_value != null && (
            <span className="text-[10px] font-normal text-emerald-600">{t($ => $.liability.fifoSnapshot)}</span>
          )}
        </div>
      ),
    },
    {
      label: t($ => $.liability.table.warehouse),
      value: lib.warehouse?.name ?? <span className="text-muted-foreground">—</span>,
    },
    {
      label: t($ => $.liability.table.manager),
      value: lib.warehouse_manager ?? <span className="text-muted-foreground">—</span>,
    },
  ];

  const statusBadge =
    lib.status === 'approved' ? (
      <Badge variant="outline" className="text-emerald-600 border-emerald-200 bg-emerald-50 dark:bg-emerald-950/20 text-xs">
        <CheckCircle className="size-3 mr-1" />{t($ => $.liability.status.approved)}
      </Badge>
    ) : lib.status === 'rejected' ? (
      <Badge variant="outline" className="text-muted-foreground text-xs">
        <XCircle className="size-3 mr-1" />{t($ => $.liability.status.rejected)}
      </Badge>
    ) : (
      <Badge variant="outline" className="text-amber-600 border-amber-200 bg-amber-50 dark:bg-amber-950/20 text-xs">
        <Clock className="size-3 mr-1" />{t($ => $.liability.status.pending)}
      </Badge>
    );

  return (
    <MobileDataCard
      title={lib.product?.name ?? '—'}
      subtitle={lib.product?.sku}
      status={statusBadge}
      fields={fields}
      onOpen={() => onOpen(lib)}
      openLabel={t($ => $.liability.viewDetails)}
      actions={
        isPending ? (
          <>
            <Button size="sm" variant="default" className="h-7 text-xs" onClick={() => onApprove(lib)}>
              {t($ => $.liability.actions.approve)}
            </Button>
            <Button
              size="sm"
              variant="outline"
              className="h-7 text-xs text-destructive hover:text-destructive"
              onClick={() => onReject(lib)}
            >
              {t($ => $.liability.actions.reject)}
            </Button>
          </>
        ) : undefined
      }
    />
  );
}
