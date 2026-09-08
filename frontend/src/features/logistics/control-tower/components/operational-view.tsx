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
  Route,
  ShieldAlert,
  Truck,
  UserCheck,
  Wallet,
  Warehouse,
  XCircle,
} from 'lucide-react';

import { ROUTES } from '@/router/routes';
import type { ExceptionSeverity } from '@/features/logistics/operations/types/operations';

import { KpiTileCard, type KpiTileCardProps } from './kpi-tile-card';
import { NeedsAttentionSection, NEEDS_ATTENTION_SECTION_ID } from './needs-attention-section';
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
 * A grid of KPI tiles (real counts where a clean canonical source exists,
 * honest deep-link-only cards where it does not) plus the Needs Attention
 * panel. Every tile navigates to the EXISTING full page that owns the
 * underlying action — Control Tower recreates none of it.
 */
export function OperationalView() {
  const { t } = useTranslation('control-tower');
  const navigate = useNavigate();
  const kpis = useControlTowerKpis();

  const [severityFilter, setSeverityFilter] = useState<SeverityFilter>('all');

  function focusNeedsAttention(severity: SeverityFilter) {
    setSeverityFilter(severity);
    document
      .getElementById(NEEDS_ATTENTION_SECTION_ID)
      ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  const tiles: KpiTileCardProps[] = [
    {
      label: t(($) => $.kpis.readyForDistribution.label),
      icon: Package,
      status: tileStatus(kpis.readyForDistribution),
      value: kpis.readyForDistribution.value,
      caption:
        tileStatus(kpis.readyForDistribution) === 'unavailable'
          ? t(($) => $.kpis.readyForDistribution.noWindowCaption)
          : t(($) => $.kpis.readyForDistribution.hint),
      onClick: () => navigate(ROUTES.logisticsDistributionWorkspace),
      testId: 'kpi-ready-for-distribution',
    },
    {
      label: t(($) => $.kpis.groupsWaitingVehicle.label),
      icon: Truck,
      status: 'unavailable',
      caption: t(($) => $.kpis.groupsWaitingVehicle.caption),
      onClick: () => navigate(ROUTES.logisticsDistributionWorkspace),
      testId: 'kpi-groups-waiting-vehicle',
    },
    {
      label: t(($) => $.kpis.groupsWaitingDriver.label),
      icon: UserCheck,
      status: 'unavailable',
      caption: t(($) => $.kpis.groupsWaitingDriver.caption),
      onClick: () => navigate(ROUTES.logisticsDistributionWorkspace),
      testId: 'kpi-groups-waiting-driver',
    },
    {
      label: t(($) => $.kpis.loadingInProgress.label),
      icon: Boxes,
      status: tileStatus(kpis.loadingInProgress),
      value: kpis.loadingInProgress.value,
      caption: t(($) => $.kpis.loadingInProgress.hint),
      onClick: () => navigate(ROUTES.loadingOsWorkspace),
      testId: 'kpi-loading-in-progress',
    },
    {
      label: t(($) => $.kpis.readyForHandover.label),
      icon: PackageCheck,
      status: tileStatus(kpis.readyForDriverHandover),
      value: kpis.readyForDriverHandover.value,
      caption: t(($) => $.kpis.readyForHandover.hint),
      onClick: () => navigate(ROUTES.loadingOsWorkspace),
      testId: 'kpi-ready-for-handover',
    },
    {
      label: t(($) => $.kpis.tripsActive.label),
      icon: Route,
      status: tileStatus(kpis.tripsActive),
      value: kpis.tripsActive.value,
      caption: t(($) => $.kpis.tripsActive.hint),
      onClick: () => navigate(ROUTES.logisticsTrips),
      testId: 'kpi-trips-active',
    },
    {
      label: t(($) => $.kpis.deliveriesFailedToday.label),
      icon: XCircle,
      status: tileStatus(kpis.deliveriesFailedToday),
      value: kpis.deliveriesFailedToday.value,
      caption: t(($) => $.kpis.deliveriesFailedToday.hint),
      onClick: () => navigate(ROUTES.shippingOrders),
      tone: 'danger',
      testId: 'kpi-deliveries-failed-today',
    },
    {
      label: t(($) => $.kpis.noAnswer.label),
      icon: Phone,
      status: tileStatus(kpis.noAnswer),
      value: kpis.noAnswer.value,
      caption: t(($) => $.kpis.noAnswer.hint),
      onClick: () => navigate(ROUTES.shippingOrders),
      tone: 'warning',
      testId: 'kpi-no-answer',
    },
    {
      label: t(($) => $.kpis.postponed.label),
      icon: CalendarClock,
      status: tileStatus(kpis.postponed),
      value: kpis.postponed.value,
      caption: t(($) => $.kpis.postponed.hint),
      onClick: () => navigate(ROUTES.shippingOrders),
      tone: 'warning',
      testId: 'kpi-postponed',
    },
    {
      label: t(($) => $.kpis.retriesRequired.label),
      icon: RotateCcw,
      status: tileStatus(kpis.retriesRequired),
      value: kpis.retriesRequired.value,
      caption: t(($) => $.kpis.retriesRequired.hint),
      onClick: () => navigate(ROUTES.shippingOrders),
      testId: 'kpi-retries-required',
    },
    {
      label: t(($) => $.kpis.returnsExpected.label),
      icon: MapPin,
      status: 'unavailable',
      caption: t(($) => $.kpis.returnsExpected.caption),
      onClick: () => navigate(ROUTES.logisticsTrips),
      testId: 'kpi-returns-expected',
    },
    {
      label: t(($) => $.kpis.returnsAwaitingReceipt.label),
      icon: Warehouse,
      status: 'unavailable',
      caption: t(($) => $.kpis.returnsAwaitingReceipt.caption),
      onClick: () => navigate(ROUTES.logisticsTrips),
      testId: 'kpi-returns-awaiting-receipt',
    },
    {
      label: t(($) => $.kpis.driversAwaitingSettlement.label),
      icon: Wallet,
      status: tileStatus(kpis.driversAwaitingSettlement),
      value: kpis.driversAwaitingSettlement.value,
      caption: t(($) => $.kpis.driversAwaitingSettlement.hint),
      onClick: () => navigate(ROUTES.logisticsDriverSettlement),
      testId: 'kpi-drivers-awaiting-settlement',
    },
    {
      label: t(($) => $.kpis.cashHandoverPending.label),
      icon: HandCoins,
      status: 'unavailable',
      caption: t(($) => $.kpis.cashHandoverPending.caption),
      onClick: () => navigate(ROUTES.logisticsTrips),
      testId: 'kpi-cash-handover-pending',
    },
    {
      label: t(($) => $.kpis.criticalBlockers.label),
      icon: ShieldAlert,
      status: tileStatus(kpis.criticalBlockers),
      value: kpis.criticalBlockers.value,
      caption: t(($) => $.kpis.criticalBlockers.hint),
      onClick: () => focusNeedsAttention('critical'),
      tone: 'danger',
      testId: 'kpi-critical-blockers',
    },
  ];

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h2 className="text-lg font-semibold">{t(($) => $.kpis.sectionTitle)}</h2>
        <div
          className="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5"
          data-testid="control-tower-kpi-grid"
        >
          {tiles.map((tile) => (
            <KpiTileCard key={tile.testId} {...tile} />
          ))}
        </div>
      </div>

      <NeedsAttentionSection
        alerts={kpis.alerts.items}
        isLoading={kpis.alerts.isLoading}
        isError={kpis.alerts.isError}
        severityFilter={severityFilter}
        onSeverityFilterChange={setSeverityFilter}
      />
    </div>
  );
}
