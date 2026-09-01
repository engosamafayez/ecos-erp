import { useMemo, type ReactNode } from 'react';
import { ArrowDown, ArrowUp, ChevronsUpDown } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { EmptyState } from '@/components/crud/empty-state';
import { ErrorState } from '@/components/crud/error-state';
import type { ColumnDef, SortState } from '@/components/crud/types';
import { AutoDataCard } from '@/components/mobile';
import type { AutoCardColumn } from '@/components/mobile';
import { Skeleton } from '@/components/ui/skeleton';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';

type EntityTableProps<T> = {
  columns: ColumnDef<T>[];
  data: T[];
  getRowId: (row: T) => string;
  isLoading?: boolean;
  isError?: boolean;
  sort?: SortState;
  onSortChange?: (field: string) => void;
  /** Renders the row-actions cell (e.g. an ActionMenu). */
  rowActions?: (row: T) => ReactNode;
  /**
   * Bespoke mobile card for a row. When omitted, a conservative auto-card is
   * derived from the column defs (below `lg`) so the actions column is never
   * pushed off-screen. Supply this for business-specific card content.
   */
  renderMobileCard?: (row: T) => ReactNode;
  emptyState?: ReactNode;
  errorState?: ReactNode;
  skeletonRows?: number;
  /** Highlights this row with a focus ring — used for keyboard arrow navigation. */
  focusedRowId?: string | null;
};

const ALIGN_CLASS = {
  left: 'text-start',
  center: 'text-center',
  right: 'text-end',
} as const;

const AUTO_CARD_ALIGN = {
  left: 'start',
  center: 'center',
  right: 'end',
} as const;

/**
 * Generic, responsive data table: sorting, loading skeleton, empty/error
 * states and optional row actions. Holds no business logic — columns and row
 * actions are supplied by the consuming module.
 *
 * Dual layout: a full `<table>` on `lg+` and a `MobileDataCard` list below it,
 * so master-data lists stay usable on a phone (no horizontal-scroll / actions
 * off-screen) without each of the ~22 consuming pages changing. The desktop
 * tree is untouched; the card branch is additive.
 */
export function EntityTable<T>({
  columns,
  data,
  getRowId,
  isLoading = false,
  isError = false,
  sort,
  onSortChange,
  rowActions,
  renderMobileCard,
  emptyState,
  errorState,
  skeletonRows = 5,
  focusedRowId = null,
}: EntityTableProps<T>) {
  const { t } = useTranslation('common');
  const totalColumns = columns.length + (rowActions ? 1 : 0);

  const autoCardColumns = useMemo<AutoCardColumn<T>[]>(
    () =>
      columns.map((column) => ({
        key: column.key,
        label: column.header,
        render: column.cell,
        cardRole: column.cardRole,
        align: column.align ? AUTO_CARD_ALIGN[column.align] : undefined,
      })),
    [columns],
  );

  return (
    <>
      {/* ── Card layout (< lg, i.e. tablet + mobile) ──
          No outer panel box: each row is now its own elevated `MobileDataCard`
          (design report §6/§10), so a wrapping border/bg here would nest a box
          around boxes. */}
      <div className="block lg:hidden">
        {isLoading ? (
          <div className="flex flex-col gap-2">
            {Array.from({ length: 5 }, (_, index) => (
              <div key={index} className="animate-pulse space-y-2 rounded-xl border bg-card p-3.5 shadow-sm">
                <Skeleton className="h-4 w-32" />
                <Skeleton className="h-4 w-48" />
                <div className="mt-1 flex gap-2">
                  <Skeleton className="h-5 w-20 rounded-full" />
                  <Skeleton className="h-4 w-12" />
                </div>
              </div>
            ))}
          </div>
        ) : isError ? (
          errorState ?? <ErrorState />
        ) : data.length === 0 ? (
          emptyState ?? <EmptyState title={t(($) => $.table.noRecords)} />
        ) : renderMobileCard ? (
          <div role="list">
            {data.map((row) => (
              <div key={getRowId(row)}>{renderMobileCard(row)}</div>
            ))}
          </div>
        ) : (
          <div role="list">
            {data.map((row) => {
              const id = getRowId(row);
              return (
                <AutoDataCard
                  key={id}
                  row={row}
                  columns={autoCardColumns}
                  actions={rowActions ? rowActions(row) : undefined}
                  focused={focusedRowId === id}
                />
              );
            })}
          </div>
        )}
      </div>

      {/* ── Desktop (lg+) ── */}
      <div className="hidden rounded-lg border lg:block">
        <Table>
          <TableHeader className="sticky top-0 z-10 bg-muted/60 backdrop-blur-sm">
            <TableRow>
              {columns.map((column) => {
                const isSorted = sort?.field === column.key;
                const SortIcon = isSorted
                  ? sort?.direction === 'asc'
                    ? ArrowUp
                    : ArrowDown
                  : ChevronsUpDown;

                return (
                  <TableHead
                    key={column.key}
                    className={cn(column.align && ALIGN_CLASS[column.align], column.headerClassName)}
                  >
                    {column.sortable && onSortChange ? (
                      <button
                        type="button"
                        onClick={() => onSortChange(column.key)}
                        className="hover:text-foreground inline-flex items-center gap-1.5"
                      >
                        {column.header}
                        <SortIcon className="size-3.5 opacity-70" />
                      </button>
                    ) : (
                      column.header
                    )}
                  </TableHead>
                );
              })}
              {rowActions ? <TableHead className="w-12 text-end">{t($ => $.table.actions)}</TableHead> : null}
            </TableRow>
          </TableHeader>
          <TableBody>
            {isLoading ? (
              Array.from({ length: skeletonRows }, (_, rowIndex) => (
                <TableRow key={`skeleton-${rowIndex}`}>
                  {Array.from({ length: totalColumns }, (_, cellIndex) => (
                    <TableCell key={cellIndex}>
                      <Skeleton className="h-4 w-full" />
                    </TableCell>
                  ))}
                </TableRow>
              ))
            ) : isError ? (
              <TableRow>
                <TableCell colSpan={totalColumns} className="p-0">
                  {errorState ?? <ErrorState />}
                </TableCell>
              </TableRow>
            ) : data.length === 0 ? (
              <TableRow>
                <TableCell colSpan={totalColumns} className="p-0">
                  {emptyState ?? <EmptyState title={t($ => $.table.noRecords)} />}
                </TableCell>
              </TableRow>
            ) : (
              data.map((row) => (
                <TableRow
                  key={getRowId(row)}
                  className={cn(
                    focusedRowId === getRowId(row) && 'outline outline-1 outline-primary/50 bg-accent/30',
                  )}
                >
                  {columns.map((column) => (
                    <TableCell
                      key={column.key}
                      className={cn(column.align && ALIGN_CLASS[column.align], column.cellClassName)}
                    >
                      {column.cell(row)}
                    </TableCell>
                  ))}
                  {rowActions ? (
                    <TableCell className="text-end">{rowActions(row)}</TableCell>
                  ) : null}
                </TableRow>
              ))
            )}
          </TableBody>
        </Table>
      </div>
    </>
  );
}
