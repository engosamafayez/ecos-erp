import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import {
  Boxes,
  CalendarClock,
  HandCoins,
  MapPin,
  Package,
  PackageCheck,
  Phone,
  RotateCcw,
  Truck,
  UserCheck,
  Wallet,
  Warehouse,
  XCircle,
} from 'lucide-react';

import { ROUTES } from '@/router/routes';
import type { ExceptionSeverity } from '@/features/logistics/operations/types/operations';

import { KpiTileCard, type KpiTileCardProps } from './kpi-tile-card';
import { NeedsAttentionSection } from './needs-attention-section';
import { ActiveExecutionSection } from './active-execution-section';
import { useControlTowerKpis, type KpiQueryState } from '../hooks/use-control-tower-kpis';

type SeverityFilter = ExceptionSeverity | 'all';

/** Turns one KpiQueryState into the tile's visual status. */
function tileStatus(kpi: KpiQueryState): KpiTileCardProps['status'] {
  if (kpi.isLoading) return 'loading';
  if (kpi.isError) return 'error';
  if (kpi.value !== null) return 'value';
  return 'unavailable';
}

/**
 * Primary "Operational" view — the default Control Tower landing surface.
 *
 * TASK-ECOS-SHIPPING-OS-REDESIGN-002 §5: an explicit operational hierarchy,
 * Needs Attention FIRST (the task's own "most important section" — §6),
 * then Ready/Waiting, Active Execution, Failures/Exceptions, and a Returns &
 * Settlement summary. Every tile/section either shows a real count from an
 * existing canonical endpoint or an honest deep-link-only card — never a
 * fabricated number (see `use-control-tower-kpis.ts` for the exact source of
 * every field). Deep links route through the most PRECISE surface available
 * — a `?tab=`/`?classification=` on Dispatch & Execution, Returns &
 * Settlement or Shipping Orders wherever that target supports one (task
 * §11), not a generic module home.
 */
export function OperationalView() {
  const { t } = useTranslation('control-tower');
  const navigate = useNavigate();
  const kpis = useControlTowerKpis();

  const [severityFilter, setSeverityFilter] = useState<SeverityFilter>('all');

  const readyWaitingTiles: KpiTileCardProps[] = [
    {
      label: t(($) => $.kpis.readyForDistribution.label),
      icon: Package,
      status: tileStatus(kpis.readyForDistribution),
      value: kpis.readyForDistribution.value,
      caption:
        tileStatus(kpis.readyForDistribution) === 'unavailable'
          ? t(($) => $.kpis.readyForDistribution.noWindowCaption)
          : t(($) => $.kpis.readyForDistribution.hint),
      // No Dispatch & Execution tab covers pre-Group eligible orders — this
      // is genuinely Distribution Workspace's own first step (task §11 asks
      // for the MOST precise surface; here that IS the direct workspace).
      onClick: () => navigate(ROUTES.logisticsDistributionWorkspace),
      testId: 'kpi-ready-for-distribution',
    },
    {
      label: t(($) => $.kpis.groupsWaitingVehicle.label),
      icon: Truck,
      status: 'unavailable',
      caption: t(($) => $.kpis.groupsWaitingVehicle.caption),
      onClick: () => navigate(`${ROUTES.shippingDispatchExecution}?tab=assignment`),
      testId: 'kpi-groups-waiting-vehicle',
    },
    {
      label: t(($) => $.kpis.groupsWaitingDriver.label),
      icon: UserCheck,
      status: 'unavailable',
      caption: t(($) => $.kpis.groupsWaitingDriver.caption),
      onClick: () => navigate(`${ROUTES.shippingDispatchExecution}?tab=assignment`),
      testId: 'kpi-groups-waiting-driver',
    },
    {
      label: t(($) => $.kpis.loadingInProgress.label),
      icon: Boxes,
      status: tileStatus(kpis.loadingInProgress),
      value: kpis.loadingInProgress.value,
      caption: t(($) => $.kpis.loadingInProgress.hint),
      onClick: () => navigate(`${ROUTES.shippingDispatchExecution}?tab=loading`),
      testId: 'kpi-loading-in-progress',
    },
    {
      label: t(($) => $.kpis.readyForHandover.label),
      icon: PackageCheck,
      status: tileStatus(kpis.readyForDriverHandover),
      value: kpis.readyForDriverHandover.value,
      caption: t(($) => $.kpis.readyForHandover.hint),
      onClick: () => navigate(`${ROUTES.shippingDispatchExecution}?tab=handover`),
      testId: 'kpi-ready-for-handover',
    },
  ];

  const failureTiles: KpiTileCardProps[] = [
    {
      label: t(($) => $.kpis.deliveriesFailedToday.label),
      icon: XCircle,
      status: tileStatus(kpis.deliveriesFailedToday),
      value: kpis.deliveriesFailedToday.value,
      caption: t(($) => $.kpis.deliveriesFailedToday.hint),
      onClick: () => navigate(`${ROUTES.shippingOrders}?classification=cancelled`),
      tone: 'danger',
      testId: 'kpi-deliveries-failed-today',
    },
    {
      label: t(($) => $.kpis.noAnswer.label),
      icon: Phone,
      status: tileStatus(kpis.noAnswer),
      value: kpis.noAnswer.value,
      caption: t(($) => $.kpis.noAnswer.hint),
      onClick: () => navigate(`${ROUTES.shippingOrders}?classification=no_answer`),
      tone: 'warning',
      testId: 'kpi-no-answer',
    },
    {
      label: t(($) => $.kpis.postponed.label),
      icon: CalendarClock,
      status: tileStatus(kpis.postponed),
      value: kpis.postponed.value,
      caption: t(($) => $.kpis.postponed.hint),
      onClick: () => navigate(`${ROUTES.shippingOrders}?classification=postponed`),
      tone: 'warning',
      testId: 'kpi-postponed',
    },
    {
      label: t(($) => $.kpis.retriesRequired.label),
      icon: RotateCcw,
      status: tileStatus(kpis.retriesRequired),
      value: kpis.retriesRequired.value,
      // Sum of two classifications (postponed + no_answer) — no single tab
      // filter represents an OR of both, so this links to the unfiltered
      // list rather than picking one and silently hiding the other.
      caption: t(($) => $.kpis.retriesRequired.hint),
      onClick: () => navigate(ROUTES.shippingOrders),
      testId: 'kpi-retries-required',
    },
  ];

  const returnsSettlementTiles: KpiTileCardProps[] = [
    {
      label: t(($) => $.kpis.returnsExpected.label),
      icon: MapPin,
      status: 'unavailable',
      caption: t(($) => $.kpis.returnsExpected.caption),
      onClick: () => navigate(`${ROUTES.shippingReturnsSettlement}?tab=expected-returns`),
      testId: 'kpi-returns-expected',
    },
    {
      label: t(($) => $.kpis.returnsAwaitingReceipt.label),
      icon: Warehouse,
      status: 'unavailable',
      caption: t(($) => $.kpis.returnsAwaitingReceipt.caption),
      onClick: () => navigate(`${ROUTES.shippingReturnsSettlement}?tab=warehouse-receipt`),
      testId: 'kpi-returns-awaiting-receipt',
    },
    {
      label: t(($) => $.kpis.driversAwaitingSettlement.label),
      icon: Wallet,
      status: tileStatus(kpis.driversAwaitingSettlement),
      value: kpis.driversAwaitingSettlement.value,
      caption: t(($) => $.kpis.driversAwaitingSettlement.hint),
      onClick: () => navigate(`${ROUTES.shippingReturnsSettlement}?tab=driver-settlement`),
      testId: 'kpi-drivers-awaiting-settlement',
    },
    {
      label: t(($) => $.kpis.cashHandoverPending.label),
      icon: HandCoins,
      status: 'unavailable',
      caption: t(($) => $.kpis.cashHandoverPending.caption),
      onClick: () => navigate(`${ROUTES.shippingReturnsSettlement}?tab=cash-handover`),
      testId: 'kpi-cash-handover-pending',
    },
  ];

  return (
    <div className="flex flex-col gap-6">
      {/* A — Needs Attention (§6, primary) */}
      <NeedsAttentionSection
        alerts={kpis.alerts.items}
        isLoading={kpis.alerts.isLoading}
        isError={kpis.alerts.isError}
        severityFilter={severityFilter}
        onSeverityFilterChange={setSeverityFilter}
        health={kpis.health}
        loadingNeedsReview={kpis.loadingNeedsReview}
      />

      {/* B — Ready / Waiting (§7) */}
      <section className="space-y-3">
        <h2 className="text-lg font-semibold">{t(($) => $.sections.readyWaiting)}</h2>
        <div
          className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5"
          data-testid="control-tower-ready-waiting-grid"
        >
          {readyWaitingTiles.map((tile) => (
            <KpiTileCard key={tile.testId} {...tile} />
          ))}
        </div>
      </section>

      {/* C — Active Execution (§8) */}
      <ActiveExecutionSection tripsActive={kpis.tripsActive} />

      {/* D — Failures / Exceptions (§9) */}
      <section className="space-y-3">
        <h2 className="text-lg font-semibold">{t(($) => $.sections.failuresExceptions)}</h2>
        <div
          className="grid grid-cols-2 gap-3 sm:grid-cols-4"
          data-testid="control-tower-failures-grid"
        >
          {failureTiles.map((tile) => (
            <KpiTileCard key={tile.testId} {...tile} />
          ))}
        </div>
      </section>

      {/* E — Returns & Settlement summary (§10) — summarizes and deep-links only;
          the dedicated Returns & Settlement workspace owns the real implementation. */}
      <section className="space-y-3">
        <h2 className="text-lg font-semibold">{t(($) => $.sections.returnsSettlement)}</h2>
        <div
          className="grid grid-cols-2 gap-3 sm:grid-cols-4"
          data-testid="control-tower-returns-settlement-grid"
        >
          {returnsSettlementTiles.map((tile) => (
            <KpiTileCard key={tile.testId} {...tile} />
          ))}
        </div>
      </section>
    </div>
  );
}
