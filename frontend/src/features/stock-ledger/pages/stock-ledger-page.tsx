import { useMemo, useState } from 'react';

import {
  EntityTable,
  EntityToolbar,
  PageHeader,
  Pagination,
} from '@/components/crud';
import type { ColumnDef } from '@/components/crud/types';
import { Card, CardContent } from '@/components/ui/card';
import { MovementTypeBadge } from '@/features/stock-ledger/components/movement-type-badge';
import { useStockMovementsQuery } from '@/features/stock-ledger/hooks/use-stock-ledger';
import type {
  MovementType,
  StockMovement,
  StockMovementSortField,
} from '@/features/stock-ledger/types/stock-movement';
import { ROUTES } from '@/router/routes';

const PER_PAGE = 15;

const MOVEMENT_TYPES: { value: MovementType | 'all'; label: string }[] = [
  { value: 'all', label: 'All Types' },
  { value: 'purchase_receipt', label: 'Purchase Receipt' },
  { value: 'sales_issue', label: 'Sales Issue' },
  { value: 'adjustment_in', label: 'Adjustment In' },
  { value: 'adjustment_out', label: 'Adjustment Out' },
  { value: 'transfer_in', label: 'Transfer In' },
  { value: 'transfer_out', label: 'Transfer Out' },
];

function fmt(n: number) {
  return n % 1 === 0 ? String(n) : n.toFixed(4).replace(/\.?0+$/, '');
}

export function StockLedgerPage() {
  const [search, setSearch] = useState('');
  const [movementTypeFilter, setMovementTypeFilter] = useState<MovementType | 'all'>('all');
  const [dateFrom, setDateFrom] = useState('');
  const [dateTo, setDateTo] = useState('');
  const [page, setPage] = useState(1);
  const [sort, setSort] = useState<{ field: StockMovementSortField; direction: 'asc' | 'desc' }>({
    field: 'created_at',
    direction: 'desc',
  });

  const params = useMemo(
    () => ({
      search: search || undefined,
      movement_type: movementTypeFilter,
      date_from: dateFrom || undefined,
      date_to: dateTo || undefined,
      page,
      per_page: PER_PAGE,
      sort_by: sort.field,
      sort_dir: sort.direction,
    }),
    [search, movementTypeFilter, dateFrom, dateTo, page, sort],
  );

  const { data, isLoading, isError, isFetching, refetch } = useStockMovementsQuery(params);

  const items = data?.items ?? [];
  const meta = data?.meta;

  const handleSort = (field: string) => {
    setSort((curr) =>
      curr.field === field
        ? { field: field as StockMovementSortField, direction: curr.direction === 'asc' ? 'desc' : 'asc' }
        : { field: field as StockMovementSortField, direction: 'asc' },
    );
    setPage(1);
  };

  const columns: ColumnDef<StockMovement>[] = [
    {
      key: 'movement_date',
      header: 'Date',
      sortable: true,
      cell: (m) => m.movement_date,
    },
    {
      key: 'product',
      header: 'Product',
      cell: (m) => (
        <div>
          <span className="font-medium">{m.product?.name ?? '—'}</span>
          {m.product?.sku && (
            <span className="text-muted-foreground ml-1.5 text-xs">{m.product.sku}</span>
          )}
        </div>
      ),
    },
    {
      key: 'warehouse',
      header: 'Warehouse',
      cell: (m) => m.warehouse?.name ?? '—',
    },
    {
      key: 'movement_type',
      header: 'Type',
      sortable: true,
      cell: (m) => <MovementTypeBadge type={m.movement_type} />,
    },
    {
      key: 'quantity',
      header: 'Quantity',
      sortable: true,
      cell: (m) => (
        <span className="font-mono tabular-nums">{fmt(m.quantity)}</span>
      ),
    },
    {
      key: 'balance_before',
      header: 'Before',
      cell: (m) => (
        <span className="text-muted-foreground font-mono tabular-nums">{fmt(m.balance_before)}</span>
      ),
    },
    {
      key: 'balance_after',
      header: 'After',
      cell: (m) => (
        <span className="font-mono tabular-nums">{fmt(m.balance_after)}</span>
      ),
    },
    {
      key: 'reference_type',
      header: 'Ref. Type',
      cell: (m) =>
        m.reference_type
          ? m.reference_type.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
          : '—',
    },
    {
      key: 'reference_id',
      header: 'Reference',
      cell: (m) =>
        m.reference_id ? (
          <span className="font-mono text-xs">{m.reference_id.slice(0, 8)}…</span>
        ) : (
          '—'
        ),
    },
  ];

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Stock Ledger"
        subtitle="Complete audit history of all inventory movements."
        breadcrumbs={[{ label: 'Home', to: ROUTES.dashboard }, { label: 'Stock Ledger' }]}
      />

      <Card>
        <CardContent className="flex flex-col gap-4 pt-6">
          <EntityToolbar
            searchPlaceholder="Search by product name, SKU, or warehouse…"
            onSearchChange={(v) => { setSearch(v); setPage(1); }}
            onRefresh={() => void refetch()}
            isRefreshing={isFetching}
            onClearFilters={() => {
              setMovementTypeFilter('all');
              setDateFrom('');
              setDateTo('');
              setPage(1);
            }}
            filterPanel={
              <div className="flex flex-col gap-3">
                <div className="flex flex-col gap-1.5">
                  <span className="text-sm font-medium">Movement Type</span>
                  <select
                    value={movementTypeFilter}
                    onChange={(e) => {
                      setMovementTypeFilter(e.target.value as MovementType | 'all');
                      setPage(1);
                    }}
                    className="border-input h-9 rounded-md border bg-transparent px-3 text-sm shadow-xs"
                  >
                    {MOVEMENT_TYPES.map((t) => (
                      <option key={t.value} value={t.value}>
                        {t.label}
                      </option>
                    ))}
                  </select>
                </div>

                <div className="flex flex-col gap-1.5">
                  <span className="text-sm font-medium">Date From</span>
                  <input
                    type="date"
                    value={dateFrom}
                    onChange={(e) => { setDateFrom(e.target.value); setPage(1); }}
                    className="border-input h-9 rounded-md border bg-transparent px-3 text-sm shadow-xs"
                  />
                </div>

                <div className="flex flex-col gap-1.5">
                  <span className="text-sm font-medium">Date To</span>
                  <input
                    type="date"
                    value={dateTo}
                    onChange={(e) => { setDateTo(e.target.value); setPage(1); }}
                    className="border-input h-9 rounded-md border bg-transparent px-3 text-sm shadow-xs"
                  />
                </div>
              </div>
            }
          />

          <EntityTable<StockMovement>
            columns={columns}
            data={items}
            getRowId={(m) => m.id}
            isLoading={isLoading}
            isError={isError}
            sort={sort}
            onSortChange={handleSort}
          />

          {meta ? (
            <Pagination
              meta={{ page: meta.current_page, perPage: meta.per_page, total: meta.total, lastPage: meta.last_page }}
              onPageChange={setPage}
            />
          ) : null}
        </CardContent>
      </Card>
    </div>
  );
}
