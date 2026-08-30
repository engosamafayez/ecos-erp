import { useTranslation } from 'react-i18next';

import { Badge } from '@/components/ui/badge';
import type { ClosingStage, DaySettlementStatus } from '../types/driver-settlement';

const STATUS_CLASS: Record<DaySettlementStatus, string> = {
  needs_review: 'bg-muted text-muted-foreground',
  under_review: 'bg-blue-500/10 text-blue-600 dark:text-blue-400',
  disputed: 'bg-destructive/10 text-destructive',
  settled: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
};

/**
 * Aggregate money-settlement status for a driver. A DERIVED rollup label over the canonical
 * per-trip SettlementStatus — not a new status vocabulary, never written anywhere.
 */
export function DaySettlementStatusBadge({ status }: { status: DaySettlementStatus }) {
  const { t } = useTranslation('logistics');

  const label: Record<DaySettlementStatus, string> = {
    needs_review: t(($) => $.driverSettlement.status.needs_review),
    under_review: t(($) => $.driverSettlement.status.under_review),
    disputed: t(($) => $.driverSettlement.status.disputed),
    settled: t(($) => $.driverSettlement.status.settled),
  };

  return <Badge className={`${STATUS_CLASS[status]} hover:${STATUS_CLASS[status]}`}>{label[status]}</Badge>;
}

const STAGE_CLASS: Record<ClosingStage, string> = {
  open_custody: 'bg-slate-500/10 text-slate-600 dark:text-slate-300',
  in_operation: 'bg-blue-500/10 text-blue-600 dark:text-blue-400',
  ready_for_return: 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400',
  warehouse_counting: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
  needs_review: 'bg-orange-500/10 text-orange-600 dark:text-orange-400',
  ready_for_closing: 'bg-teal-500/10 text-teal-600 dark:text-teal-400',
  closed: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
};

/**
 * The derived operational closing stage (§13) — a read-only rollup over canonical facts
 * (settlement status + reconciliation status + delivery/custody state). Never persisted.
 */
export function ClosingStageBadge({ stage }: { stage: ClosingStage }) {
  const { t } = useTranslation('logistics');

  return (
    <Badge className={`${STAGE_CLASS[stage]} hover:${STAGE_CLASS[stage]}`}>
      {t(($) => $.driverSettlement.stage[stage])}
    </Badge>
  );
}
