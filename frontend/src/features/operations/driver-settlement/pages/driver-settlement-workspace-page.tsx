import { useCallback, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { AlertTriangle, ChevronLeft, ChevronRight, ClipboardCheck, Loader2, Truck } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { SmartToolbar } from '@/components/data-grid/smart-toolbar';
import { UniversalDataGrid } from '@/components/data-grid/universal-data-grid';
import type { DataGridColumnDef } from '@/components/data-grid/types';
import { useFormatter } from '@/hooks/use-formatter';
import { ROUTES } from '@/router/routes';
import { useDriverSettlementBoard } from '../hooks/use-driver-settlement';
import type { BoardScope, DaySettlementDriverRow } from '../types/driver-settlement';
import { historyRange, type HistoryPreset } from '../lib/history-range';
import { DaySettlementKpiCards } from '../components/day-settlement-kpis';
import { DaySettlementDriverCard } from '../components/day-settlement-driver-card';

const HISTORY_PRESETS: HistoryPreset[] = [
  'today',
  'this_week',
  'this_month',
  'previous_month',
  'this_year',
  'year_to_date',
  'previous_year',
  'custom',
];

const PER_PAGE = 25;

export function DriverSettlementWorkspacePage() {
  const { t } = useTranslation('logistics');
  const { money } = useFormatter();
  const navigate = useNavigate();

  const [scope, setScope] = useState<BoardScope>('active');
  const [search, setSearch] = useState('');

  // History-only controls.
  const [preset, setPreset] = useState<HistoryPreset>('this_month');
  const [customFrom, setCustomFrom] = useState('');
  const [customTo, setCustomTo] = useState('');
  const [page, setPage] = useState(1);
  const [sort, setSort] = useState<'driver' | 'date' | 'difference' | 'delivery_pct'>('date');
  const [dir, setDir] = useState<'asc' | 'desc'>('desc');

  const range = useMemo(() => historyRange(preset, customFrom, customTo), [preset, customFrom, customTo]);

  const board = useDriverSettlementBoard(
    scope === 'history'
      ? {
          scope: 'history',
          from: range.from,
          to: range.to,
          page,
          per_page: PER_PAGE,
          sort,
          dir,
          search: search || undefined,
        }
      : { scope: 'active', search: search || undefined },
  );

  const { data, isLoading, isFetching, isError, refetch } = board;
  const drivers = data?.drivers ?? [];
  const meta = data?.meta;

  const openReview = useCallback(
    (row: DaySettlementDriverRow) => {
      navigate(
        `${ROUTES.logisticsDriverSettlementDetail.replace(':assignmentId', String(row.assignment_id))}?date=${row.operational_date}`,
      );
    },
    [navigate],
  );

  const onSortChange = useCallback(
    (field: string) => {
      const next = field as typeof sort;
      if (next === sort) {
        setDir((d) => (d === 'asc' ? 'desc' : 'asc'));
      } else {
        setSort(next);
        setDir('desc');
      }
      setPage(1);
    },
    [sort],
  );

  const sortable = scope === 'history';

  const columns: DataGridColumnDef<DaySettlementDriverRow>[] = useMemo(
    () => [
      {
        // Driver / Trip — driver name is primary, trip/custody reference secondary. No vehicle (§1/§4).
        key: 'driver',
        label: t(($) => $.driverSettlement.columns.driverTrip),
        alwaysVisible: true,
        sortable,
        cell: (r) => (
          <div className="min-w-0">
            <span className="block truncate text-sm font-medium">
              {r.driver_name ?? <span className="text-muted-foreground">&mdash;</span>}
            </span>
            <span className="font-mono text-[11px] text-muted-foreground">{r.trip_number ?? r.operational_date}</span>
          </div>
        ),
      },
      {
        key: 'orders',
        label: t(($) => $.driverSettlement.columns.totalOrders),
        defaultVisible: true,
        align: 'end',
        cell: (r) => (
          <div className="text-end">
            <div className="tabular-nums text-sm font-medium">{r.orders}</div>
            <div className="tabular-nums text-[11px] text-muted-foreground">{money(r.orders_value)}</div>
          </div>
        ),
      },
      {
        key: 'delivered',
        label: t(($) => $.driverSettlement.columns.delivered),
        defaultVisible: true,
        align: 'end',
        cell: (r) => (
          <div className="text-end">
            <div className="tabular-nums text-sm font-medium">{r.delivered}</div>
            <div className="tabular-nums text-[11px] text-muted-foreground">{money(r.delivered_value)}</div>
          </div>
        ),
      },
      {
        key: 'failed',
        label: t(($) => $.driverSettlement.columns.failed),
        defaultVisible: true,
        align: 'end',
        cell: (r) => (
          <div className="text-end">
            <div className={`tabular-nums text-sm font-medium ${r.failed > 0 ? 'text-destructive' : ''}`}>{r.failed}</div>
            <div className="tabular-nums text-[11px] text-muted-foreground">{money(r.failed_value)}</div>
          </div>
        ),
      },
      {
        // Delivery Rate — percentage ONLY, never a delivered/total fraction (§15/§22).
        key: 'delivery_pct',
        label: t(($) => $.driverSettlement.columns.deliveryPct),
        defaultVisible: true,
        align: 'end',
        sortable,
        cell: (r) => <span className="tabular-nums text-sm font-medium">{r.delivery_pct}%</span>,
      },
      {
        key: 'total_sales',
        label: t(($) => $.driverSettlement.columns.totalSales),
        defaultVisible: true,
        align: 'end',
        cell: (r) => <span className="tabular-nums text-sm">{money(r.total_sales)}</span>,
      },
      {
        key: 'transfers_paid',
        label: t(($) => $.driverSettlement.columns.transfersPaid),
        defaultVisible: true,
        align: 'end',
        cell: (r) => <span className="tabular-nums text-sm">{money(r.transfers_paid)}</span>,
      },
      {
        // Approved cash-out expenses (canonical DriverTripMovement). Real, no longer "Not available".
        // A pending-review indicator shows when the operator still has movements to approve/reject.
        key: 'expenses',
        label: t(($) => $.driverSettlement.cards.expenses),
        defaultVisible: true,
        align: 'end',
        cell: (r) => (
          <div className="flex flex-col items-end">
            <span className={`tabular-nums text-sm ${r.expenses > 0 ? 'text-destructive' : ''}`}>{money(r.expenses)}</span>
            {r.pending_movements > 0 ? (
              <span className="text-[10px] text-amber-600">
                {t(($) => $.driverSettlement.movements.pendingCount, { count: r.pending_movements })}
              </span>
            ) : null}
          </div>
        ),
      },
      {
        // Net physical cash = cash collected + approved cash-in − approved cash-out (§14). Canonical.
        key: 'net_cash',
        label: t(($) => $.driverSettlement.cards.netCash),
        defaultVisible: true,
        align: 'end',
        cell: (r) => <span className="tabular-nums text-sm font-medium">{money(r.net_cash)}</span>,
      },
      {
        key: 'goods_remaining',
        label: t(($) => $.driverSettlement.columns.goodsRemaining),
        defaultVisible: true,
        align: 'end',
        cell: (r) => <span className="tabular-nums text-sm">{r.goods_on_hand}</span>,
      },
      {
        // Settlement — navigation into the canonical closing detail, NOT an auto-close (§13/§14).
        key: 'action',
        label: t(($) => $.driverSettlement.columns.action),
        alwaysVisible: true,
        align: 'end',
        cell: (r) => (
          <Button variant="ghost" size="sm" className="h-7 gap-1.5 text-xs" onClick={() => openReview(r)}>
            <ClipboardCheck className="h-3.5 w-3.5" />
            {t(($) => $.driverSettlement.settlement)}
          </Button>
        ),
      },
    ],
    [t, money, openReview, sortable],
  );

  return (
    <div className="flex flex-col h-full">
      <SmartToolbar onRefresh={() => void refetch()} isFetching={isFetching} />

      {/* Header + scope tabs */}
      <div className="px-4 py-3 border-b bg-muted/20 flex items-start justify-between gap-3 flex-wrap">
        <div className="flex items-center gap-2">
          <span className="flex h-9 w-9 items-center justify-center rounded-md bg-primary/10 text-primary">
            <Truck className="h-5 w-5" />
          </span>
          <div>
            <h1 className="text-base font-semibold leading-tight">{t(($) => $.driverSettlement.title)}</h1>
            <p className="text-xs text-muted-foreground">{t(($) => $.driverSettlement.subtitle)}</p>
          </div>
        </div>
        <Tabs
          value={scope}
          onValueChange={(v) => {
            setScope(v as BoardScope);
            setPage(1);
          }}
        >
          <TabsList>
            <TabsTrigger value="active">{t(($) => $.driverSettlement.tabsActive)}</TabsTrigger>
            <TabsTrigger value="history">{t(($) => $.driverSettlement.tabsHistory)}</TabsTrigger>
          </TabsList>
        </Tabs>
      </div>

      {/* Filters — directly under the tabs, ABOVE the KPI strip. On History the selected date
          range drives the KPI counts (computed server-side over that range), so the time filter
          leads; Search follows; the KPIs it scopes come next (§5). Active exposes no date filter
          (§6). Mobile stacks the controls full-width; from sm up they wrap into a row (§9). */}
      <div className="px-4 py-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:flex-wrap">
        {scope === 'history' && (
          <>
            <Select
              value={preset}
              onValueChange={(v) => {
                setPreset(v as HistoryPreset);
                setPage(1);
              }}
            >
              <SelectTrigger className="h-8 w-full text-xs sm:w-44">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {HISTORY_PRESETS.map((p) => (
                  <SelectItem key={p} value={p} className="text-xs">
                    {t(($) => $.driverSettlement.presets[p])}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            {preset === 'custom' && (
              <>
                <Input
                  type="date"
                  value={customFrom}
                  onChange={(e) => {
                    setCustomFrom(e.target.value);
                    setPage(1);
                  }}
                  className="h-8 w-full text-xs sm:w-36"
                  aria-label={t(($) => $.driverSettlement.rangeFrom)}
                />
                <Input
                  type="date"
                  value={customTo}
                  onChange={(e) => {
                    setCustomTo(e.target.value);
                    setPage(1);
                  }}
                  className="h-8 w-full text-xs sm:w-36"
                  aria-label={t(($) => $.driverSettlement.rangeTo)}
                />
              </>
            )}
            <span className="text-[11px] text-muted-foreground tabular-nums">
              {range.from} → {range.to}
            </span>
          </>
        )}
        <Input
          value={search}
          onChange={(e) => {
            setSearch(e.target.value);
            setPage(1);
          }}
          placeholder={t(($) => $.driverSettlement.searchPlaceholder)}
          className="h-8 w-full text-xs sm:w-56"
        />
      </div>

      {/* KPIs — scoped by the filter above. A failed read renders a distinct unavailable state,
          never an indefinite skeleton or false zeros (§2). */}
      <div className="px-4 pt-3">
        <DaySettlementKpiCards kpis={data?.kpis} loading={isLoading} error={isError} />
      </div>

      {/* Table */}
      <div className="flex-1 overflow-hidden px-4 pb-4 flex flex-col">
        {isError ? (
          <div className="flex flex-col items-center justify-center h-64 gap-3 text-muted-foreground">
            <AlertTriangle className="h-8 w-8 text-destructive/70" />
            <p className="text-sm">{t(($) => $.driverSettlement.loadError)}</p>
            <Button variant="outline" size="sm" onClick={() => void refetch()}>
              {t(($) => $.driverSettlement.retry)}
            </Button>
          </div>
        ) : isLoading ? (
          <div className="flex items-center justify-center h-64 gap-2 text-muted-foreground">
            <Loader2 className="h-4 w-4 animate-spin" />
            <span className="text-sm">{t(($) => $.driverSettlement.loading)}</span>
          </div>
        ) : (
          <>
            <div className="flex-1 min-h-0 overflow-y-auto lg:overflow-hidden">
              <UniversalDataGrid<DaySettlementDriverRow>
                columns={columns}
                data={drivers}
                rowId={(r) => r.trip_id}
                loading={false}
                onSortChange={sortable ? onSortChange : undefined}
                renderMobileCard={(row) => <DaySettlementDriverCard row={row} onOpen={openReview} />}
                emptyState={
                  <div className="flex flex-col items-center justify-center py-16 gap-2 text-muted-foreground">
                    <Truck className="w-8 h-8 opacity-30" />
                    <p className="text-sm">
                      {scope === 'history'
                        ? t(($) => $.driverSettlement.emptyHistory)
                        : t(($) => $.driverSettlement.empty)}
                    </p>
                  </div>
                }
              />
            </div>

            {/* History pagination — server-side (§20). */}
            {scope === 'history' && meta && meta.total > 0 && (
              <div className="flex items-center justify-between pt-3 text-xs text-muted-foreground">
                <span className="tabular-nums">
                  {t(($) => $.driverSettlement.pageOf, { page: meta.current_page, total: meta.last_page })} ·{' '}
                  {t(($) => $.driverSettlement.totalRows, { count: meta.total })}
                </span>
                <div className="flex items-center gap-1.5">
                  <Button
                    variant="outline"
                    size="sm"
                    className="h-7"
                    disabled={meta.current_page <= 1}
                    onClick={() => setPage((p) => Math.max(1, p - 1))}
                  >
                    <ChevronLeft className="h-3.5 w-3.5" />
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    className="h-7"
                    disabled={meta.current_page >= meta.last_page}
                    onClick={() => setPage((p) => p + 1)}
                  >
                    <ChevronRight className="h-3.5 w-3.5" />
                  </Button>
                </div>
              </div>
            )}
          </>
        )}
      </div>
    </div>
  );
}
