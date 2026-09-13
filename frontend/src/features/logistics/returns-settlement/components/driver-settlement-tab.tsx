import { useCallback, useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { ArrowUpRight, Users } from 'lucide-react';

import { EmptyState, ErrorState } from '@/components/crud';
import type { DataGridColumnDef } from '@/components/data-grid';
import { UniversalDataGrid } from '@/components/data-grid';
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

  const columns = useMemo<DataGridColumnDef<DaySettlementDriverRow>[]>(
    () => [
      {
        key: 'driver',
        label: t(($) => $.driverSettlement.columns.driver),
        cardRole: 'title',
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
        label: t(($) => $.driverSettlement.columns.cashPosition),
        align: 'end',
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
        label: t(($) => $.driverSettlement.columns.status),
        cardRole: 'status',
        cell: (row) => <DaySettlementStatusBadge status={row.settlement_status} />,
      },
      {
        key: 'rowActions',
        label: '',
        align: 'end',
        alwaysVisible: true,
        cell: (row) => (
          <Button variant="ghost" size="sm" className="h-7 text-xs" onClick={() => openDetail(row)}>
            {t(($) => $.driverSettlement.review)}
          </Button>
        ),
      },
    ],
    [t, fmt, openDetail],
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
        <UniversalDataGrid<DaySettlementDriverRow>
          data={drivers}
          columns={columns}
          rowId={(row) => row.trip_id}
          loading={isLoading}
          error={isError}
          skeletonRows={5}
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
