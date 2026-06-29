import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';

import { EmptyState, ErrorState } from '@/components/crud';
import { UniversalDataGrid } from '@/components/data-grid';
import type {
  ColumnVisibilityState,
  GridPaginationConfig,
  GridSelectionAPI,
  GridSortState,
} from '@/components/data-grid/types';

import { OrderMobileCard } from './order-mobile-card';
import { createOrderColumns } from './order-column-defs';
import type { Order } from '../types/order';

// ── Props ─────────────────────────────────────────────────────────────────────

export type OrderTableProps = {
  orders: Order[];
  isLoading: boolean;
  isError: boolean;
  sort: GridSortState;
  onSortChange: (field: string) => void;
  selection: GridSelectionAPI;
  onView: (order: Order) => void;
  onEdit?: (order: Order) => void;
  onDelete?: (order: Order) => void;
  onStatusChange?: (order: Order) => void;
  onEditLocation?: (order: Order) => void;
  onDeleteLocation?: (order: Order) => void;
  focusedRowId?: string | null;
  columnVisibility?: ColumnVisibilityState;
  pagination?: GridPaginationConfig;
};

// ── Component ─────────────────────────────────────────────────────────────────

export function OrderTable({
  orders,
  isLoading,
  isError,
  sort,
  onSortChange,
  selection,
  onView,
  onEdit,
  onDelete,
  onStatusChange,
  onEditLocation,
  onDeleteLocation,
  focusedRowId,
  columnVisibility,
  pagination,
}: OrderTableProps) {
  const { t } = useTranslation('orders');

  const columns = useMemo(
    () =>
      createOrderColumns(
        { onView, onEdit, onDelete, onStatusChange, onEditLocation, onDeleteLocation },
        t,
      ),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [onView, onEdit, onDelete, onStatusChange, onEditLocation, onDeleteLocation],
  );

  return (
    <UniversalDataGrid<Order>
      data={orders}
      columns={columns}
      rowId={(o) => o.id}
      loading={isLoading}
      error={isError}
      sort={sort}
      onSortChange={onSortChange}
      selection={selection}
      focusedRowId={focusedRowId}
      columnVisibility={columnVisibility}
      pagination={pagination}
      skeletonRows={8}
      emptyState={<EmptyState title={t('table.empty')} />}
      errorState={<ErrorState />}
      renderMobileCard={(order, sel) => (
        <OrderMobileCard
          order={order}
          isSelected={sel?.isSelected(order.id) ?? false}
          isFocused={focusedRowId === order.id}
          onView={onView}
          onSelect={sel?.selectRow ?? (() => {})}
          onStatusChange={onStatusChange}
        />
      )}
    />
  );
}
