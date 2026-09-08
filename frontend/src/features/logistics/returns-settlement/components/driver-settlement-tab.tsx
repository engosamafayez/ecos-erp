import { useCallback, useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { ArrowUpRight, Users } from 'lucide-react';

import { EmptyState, EntityTable, ErrorState } from '@/components/crud';
import type { ColumnDef } from '@/components/crud/types';
import { Button } from '@/components/ui/button';
import { useFormatter } from '@/hooks/use-formatter';
import { ROUTES } from '@/router/routes';

import { DaySettlementStatusBadge } from '@/features/operations/driver-settlement/components/day-settlement-status-badge';
import { useDriverSettlementBoard } from '@/features/operations/driver-settlement/hooks/use-driver-settlement';
import type { DaySettlementDriverRow } from '@/features/operations/driver-settlement/types/driver-settlement';

/**
 * Driver Settlement tab — the one tab in this workspace backed by a genuine
 * operator-facing aggregate endpoint: the canonical Driver Day Settlement
 * read-only rollup (`GET /logistics/distribution/driver-settlement?scope=active`,
 * via `driverSettlementService.board` / `useDriverSettlementBoard`). Reused
 * unchanged from `@/features/operations/driver-settlement`. Every figure
 * below (cash position, status) is the value the canonical settlement/
 * reconciliation engines already computed — nothing is recalculated here.
 *
 * `scope: 'active'` mirrors the Driver Settlement workspace's own default and
 * is not paginated there either (only its 'history' scope is), so this compact
 * list shows the same live active set inside a scroll region rather than
 * inventing a client-side page size.
 */
export function DriverSettlementTab() {
  const { t } = useTranslation('returns-settlement');
  const fmt = useFormatter();
  const navigate = useNavigate();

  const { data, isLoading, isError, refetch } = useDriverSettlementBoard({ scope: 'active' });
  const drivers = data?.drivers ?? [];

  const openDetail = useCallback(
    (row: DaySettlementDriverRow) => {
      navigate(
        `${ROUTES.logisticsDriverSettlementDetail.replace(':assignmentId', String(row.assignment_id))}?date=${row.operational_date}`,
      );
    },
    [navigate],
  );

  const columns = useMemo<ColumnDef<DaySettlementDriverRow>[]>(
    () => [
      {
        key: 'driver',
        header: t(($) => $.driverSettlement.columns.driver),
        cell: (row) => (
          <div className="min-w-0">
            <span className="block truncate text-sm font-medium">
              {row.driver_name ?? <span className="text-muted-foreground">&mdash;</span>}
            </span>
            <span className="block truncate font-mono text-xs text-muted-foreground">
              {row.trip_number ?? row.operational_date}
            </span>
          </div>
        ),
      },
      {
        key: 'cash_position',
        header: t(($) => $.driverSettlement.columns.cashPosition),
        align: 'right',
        cell: (row) => (
          <div className="text-end">
            <div className="tabular-nums text-sm font-medium">{fmt.money(row.net_cash)}</div>
            <div className="tabular-nums text-xs text-muted-foreground">
              {t(($) => $.driverSettlement.columns.expected)}: {fmt.money(row.cash_expected)}
            </div>
          </div>
        ),
      },
      {
        key: 'status',
        header: t(($) => $.driverSettlement.columns.status),
        cell: (row) => <DaySettlementStatusBadge status={row.settlement_status} />,
      },
    ],
    [t, fmt],
  );

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h3 className="text-sm font-semibold">{t(($) => $.driverSettlement.heading)}</h3>
          <p className="max-w-2xl text-xs text-muted-foreground">
            {t(($) => $.driverSettlement.headingDescription)}
          </p>
        </div>
        <Button size="sm" variant="outline" onClick={() => navigate(ROUTES.logisticsDriverSettlement)}>
          {t(($) => $.driverSettlement.viewAll)}
          <ArrowUpRight className="ms-1.5 size-3.5" />
        </Button>
      </div>

      <div className="max-h-[420px] overflow-y-auto">
        <EntityTable<DaySettlementDriverRow>
          columns={columns}
          data={drivers}
          getRowId={(row) => row.trip_id}
          isLoading={isLoading}
          isError={isError}
          skeletonRows={5}
          rowActions={(row) => (
            <Button variant="ghost" size="sm" className="h-7 text-xs" onClick={() => openDetail(row)}>
              {t(($) => $.driverSettlement.review)}
            </Button>
          )}
          emptyState={
            <EmptyState
              icon={Users}
              title={t(($) => $.driverSettlement.empty.title)}
              description={t(($) => $.driverSettlement.empty.description)}
            />
          }
          errorState={
            <ErrorState
              title={t(($) => $.driverSettlement.error.title)}
              description={t(($) => $.driverSettlement.error.description)}
              onRetry={() => void refetch()}
            />
          }
        />
      </div>
    </div>
  );
}
