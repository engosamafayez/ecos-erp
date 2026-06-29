import type { ReactNode } from 'react';
import {
  ArrowDown,
  ArrowUp,
  ChevronsUpDown,
  Edit,
  ExternalLink,
  MoreHorizontal,
  Trash2,
} from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { EmptyState, ErrorState } from '@/components/crud';
import type { ColumnVisibilityState } from '@/components/data-grid/types';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

import { OrderAddressCell } from './order-address-cell';
import { OrderCustomerBadge } from './order-customer-badge';
import { OrderMobileCard } from './order-mobile-card';
import { OrderPhoneCell } from './order-phone-cell';
import { OrderStatusBadge } from './order-status-badge';
import type { Order, OrderSortField, SortDirection } from '../types/order';
import { ORDER_COLUMN_META } from './order-column-meta';

// â”€â”€ Helpers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

type SortState = { field: OrderSortField; direction: SortDirection };

function SortHeader({
  field,
  label,
  sort,
  onSortChange,
}: {
  field: OrderSortField;
  label: string;
  sort: SortState;
  onSortChange: (f: OrderSortField) => void;
}) {
  const isSorted = sort.field === field;
  const Icon = isSorted
    ? sort.direction === 'asc' ? ArrowUp : ArrowDown
    : ChevronsUpDown;
  return (
    <button
      type="button"
      onClick={() => onSortChange(field)}
      className="inline-flex items-center gap-1 text-xs font-medium text-muted-foreground hover:text-foreground transition-colors"
    >
      {label}
      <Icon className="size-3" />
    </button>
  );
}

/* Th / Td with optional horizontal sticky support */
function Th({
  children,
  className,
  sticky,
}: {
  children?: ReactNode;
  className?: string;
  sticky?: 'left' | 'right';
}) {
  return (
    <th
      scope="col"
      className={cn(
        'h-10 px-3 text-start text-xs font-medium text-muted-foreground first:ps-4 last:pe-4',
        sticky && 'sticky z-20 bg-muted/60 backdrop-blur-sm',
        sticky === 'right' && 'right-0',
        className,
      )}
    >
      {children}
    </th>
  );
}

function Td({
  children,
  className,
  sticky,
  isSelected = false,
}: {
  children?: ReactNode;
  className?: string;
  sticky?: 'left' | 'right';
  isSelected?: boolean;
}) {
  return (
    <td
      className={cn(
        'px-3 py-2.5 text-sm first:ps-4 last:pe-4 align-top',
        sticky && 'sticky z-[5] transition-colors',
        sticky === 'right' && 'right-0',
        sticky && (isSelected
          ? 'bg-primary/5 group-hover:bg-primary/10'
          : 'bg-card group-hover:bg-accent/40'),
        className,
      )}
    >
      {children}
    </td>
  );
}

function SkeletonRows({ count, colCount }: { count: number; colCount: number }) {
  const skeletons = [
    'size-4 rounded',     // checkbox
    'h-4 w-20',           // order #
    'h-4 w-20',           // store
    'h-4 w-28',           // customer
    'h-4 w-24',           // phone
    'h-5 w-20 rounded-full', // status
    'h-4 w-14',           // total
    'h-4 w-20',           // payment
    'h-4 w-6',            // items
    'h-4 w-40',           // address
    'h-4 w-6',            // attempts
    'h-4 w-20',           // carrier
    'h-4 w-20',           // created
    'h-4 w-20',           // updated
    'h-7 w-7 rounded',    // actions
  ];
  return (
    <>
      {Array.from({ length: count }, (_, i) => (
        <tr key={i} className="border-b last:border-0">
          {skeletons.slice(0, colCount).map((cls, j) => (
            <td key={j} className="px-3 py-2.5 first:ps-4 last:pe-4">
              <Skeleton className={cls} />
            </td>
          ))}
        </tr>
      ))}
    </>
  );
}

/** Shipping attempts colour rule: 0=gray, 1=blue, 2=orange, 3+=red */
function AttemptsCell({ count }: { count: number }) {
  const cls =
    count === 0 ? 'text-muted-foreground'
    : count === 1 ? 'text-blue-600 dark:text-blue-400 font-medium'
    : count === 2 ? 'text-orange-500 dark:text-orange-400 font-medium'
    : 'text-red-500 dark:text-red-400 font-semibold';
  return <span className={cn('tabular-nums', cls)}>{count}</span>;
}

function formatMoney(n: number): string {
  return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatDate(d: string | null): string {
  if (!d) return 'â€”';
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(d));
}

// â”€â”€ Props â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

type OrderTableProps = {
  orders: Order[];
  isLoading: boolean;
  isError: boolean;
  sort: SortState;
  onSortChange: (field: OrderSortField) => void;
  selectedIds: Set<string>;
  onSelectRow: (id: string, checked: boolean) => void;
  onSelectAll: (checked: boolean) => void;
  onView: (order: Order) => void;
  onEdit?: (order: Order) => void;
  onDelete?: (order: Order) => void;
  onStatusChange?: (order: Order) => void;
  onEditLocation?: (order: Order) => void;
  onDeleteLocation?: (order: Order) => void;
  focusedRowId?: string | null;
  /** Column visibility state from useColumnVisibility at the page level. */
  columnVisibility?: ColumnVisibilityState;
};

// â”€â”€ Component â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

/**
 * DD-012 â€” Orders data grid with 15 configurable columns.
 * Column order: Checkbox | Order # | Store | Customer | Phone | Status |
 *   Total | Payment | Items | Address | Attempts | Carrier | Created | Updated | Actions
 *
 * Sticky columns: Checkbox and Order # on the left, Actions on the right.
 * Mobile (<md): renders OrderMobileCard list instead of the table.
 */
export function OrderTable({
  orders,
  isLoading,
  isError,
  sort,
  onSortChange,
  selectedIds,
  onSelectRow,
  onSelectAll,
  onView,
  onEdit,
  onDelete,
  onStatusChange,
  onEditLocation,
  onDeleteLocation,
  focusedRowId,
  columnVisibility = {},
}: OrderTableProps) {
  const { t } = useTranslation('orders');

  /* Resolve visibility for a column key */
  function col(key: string): boolean {
    const meta = ORDER_COLUMN_META.find((c) => c.key === key);
    if (meta?.alwaysVisible) return true;
    if (key in columnVisibility) return columnVisibility[key];
    return meta?.defaultVisible !== false;
  }

  const allSelected = orders.length > 0 && orders.every((o) => selectedIds.has(o.id));
  const someSelected = !allSelected && orders.some((o) => selectedIds.has(o.id));

  /* colSpan for full-width cells (error / empty) â€” always 2 sticky + visible optional cols */
  const visibleColCount = ORDER_COLUMN_META.filter((m) => col(m.key)).length;

  return (
    <>
      {/* â”€â”€ Mobile card list (< md) â”€â”€ */}
      <div className="block md:hidden overflow-hidden rounded-lg border bg-card">
        {isLoading ? (
          <div className="divide-y">
            {Array.from({ length: 5 }, (_, i) => (
              <div key={i} className="p-3.5 space-y-2 animate-pulse">
                <Skeleton className="h-4 w-32" />
                <Skeleton className="h-4 w-48" />
                <div className="flex gap-2 mt-1">
                  <Skeleton className="h-5 w-20 rounded-full" />
                  <Skeleton className="h-4 w-12" />
                </div>
              </div>
            ))}
          </div>
        ) : isError ? (
          <ErrorState />
        ) : orders.length === 0 ? (
          <EmptyState title={t('table.empty')} />
        ) : (
          <div role="list">
            {orders.map((order) => (
              <OrderMobileCard
                key={order.id}
                order={order}
                isSelected={selectedIds.has(order.id)}
                isFocused={focusedRowId === order.id}
                onView={onView}
                onSelect={onSelectRow}
                onStatusChange={onStatusChange}
              />
            ))}
          </div>
        )}
      </div>

      {/* â”€â”€ Desktop table (md+) â”€â”€ */}
      <div className="hidden md:block overflow-hidden rounded-lg border bg-card">
        <div className="overflow-x-auto">
          <table className="w-full caption-bottom text-sm">
            {/* Sticky header â€” top-0 keeps it visible on page scroll */}
            <thead className="sticky top-0 z-30 bg-muted/60 backdrop-blur-sm border-b">
              <tr>
                {/* 1. Checkbox â€” sticky left-0 */}
                <Th sticky="left" className="w-10 left-0">
                  <input
                    type="checkbox"
                    aria-label={t('table.selectAll')}
                    checked={allSelected}
                    ref={(el) => { if (el) el.indeterminate = someSelected; }}
                    onChange={(e) => onSelectAll(e.target.checked)}
                    className="size-4 cursor-pointer rounded accent-primary"
                  />
                </Th>
                {/* 2. Order # â€” sticky left-10 (40px = checkbox width) */}
                <Th sticky="left" className="min-w-32 left-10">
                  <SortHeader
                    field="order_number"
                    label={t('columns.number')}
                    sort={sort}
                    onSortChange={onSortChange}
                  />
                </Th>
                {/* 3â€“14: Optional columns */}
                {col('store') && <Th className="min-w-28">{t('columns.store')}</Th>}
                {col('customer') && <Th className="min-w-36">{t('columns.customer')}</Th>}
                {col('phone') && <Th className="min-w-32">{t('columns.phone')}</Th>}
                {col('status') && (
                  <Th className="min-w-36">
                    <SortHeader
                      field="status"
                      label={t('columns.status')}
                      sort={sort}
                      onSortChange={onSortChange}
                    />
                  </Th>
                )}
                {col('total') && (
                  <Th className="min-w-24 text-end">
                    <SortHeader
                      field="total"
                      label={t('columns.total')}
                      sort={sort}
                      onSortChange={onSortChange}
                    />
                  </Th>
                )}
                {col('payment_method') && <Th className="min-w-32">{t('columns.paymentMethod')}</Th>}
                {col('products_count') && <Th className="min-w-16 text-center">{t('columns.productsCount')}</Th>}
                {col('address') && <Th className="min-w-56">{t('columns.address')}</Th>}
                {col('shipping_attempts') && <Th className="min-w-20 text-center">{t('columns.shippingAttempts')}</Th>}
                {col('shipping_company') && <Th className="min-w-32">{t('columns.shippingCompany')}</Th>}
                {col('created_at') && (
                  <Th className="min-w-28">
                    <SortHeader
                      field="created_at"
                      label={t('columns.createdAt')}
                      sort={sort}
                      onSortChange={onSortChange}
                    />
                  </Th>
                )}
                {col('updated_at') && <Th className="min-w-28">{t('columns.updatedAt')}</Th>}
                {/* 15. Actions â€” sticky right-0 */}
                <Th sticky="right" className="w-10 right-0 text-end">
                  {t('columns.actions')}
                </Th>
              </tr>
            </thead>

            <tbody className="divide-y">
              {isLoading ? (
                <SkeletonRows count={8} colCount={visibleColCount} />
              ) : isError ? (
                <tr>
                  <td colSpan={visibleColCount} className="p-0">
                    <ErrorState />
                  </td>
                </tr>
              ) : orders.length === 0 ? (
                <tr>
                  <td colSpan={visibleColCount} className="p-0">
                    <EmptyState title={t('table.empty')} />
                  </td>
                </tr>
              ) : (
                orders.map((order) => {
                  const isSelected = selectedIds.has(order.id);
                  const isFocused = focusedRowId === order.id;

                  return (
                    <tr
                      key={order.id}
                      data-focused={isFocused || undefined}
                      className={cn(
                        'group transition-colors hover:bg-accent/40',
                        isSelected && 'bg-primary/5',
                        isFocused && 'outline outline-1 -outline-offset-1 outline-primary/50 bg-accent/30',
                      )}
                    >
                      {/* 1. Checkbox */}
                      <Td sticky="left" isSelected={isSelected} className="w-10 left-0">
                        <input
                          type="checkbox"
                          aria-label={`Select ${order.order_number}`}
                          checked={isSelected}
                          onChange={(e) => onSelectRow(order.id, e.target.checked)}
                          className="size-4 cursor-pointer rounded accent-primary"
                        />
                      </Td>

                      {/* 2. Order Number */}
                      <Td sticky="left" isSelected={isSelected} className="min-w-32 left-10">
                        <button
                          type="button"
                          onClick={() => onView(order)}
                          onAuxClick={(e) => {
                            if (e.button === 1) window.open(`/orders/${order.id}`, '_blank');
                          }}
                          className="font-mono text-xs font-medium hover:text-primary transition-colors"
                        >
                          {order.order_number}
                        </button>
                      </Td>

                      {/* 3. Store */}
                      {col('store') && (
                        <Td>
                          <span className="text-xs text-muted-foreground">
                            {order.channel?.name ?? 'â€”'}
                          </span>
                        </Td>
                      )}

                      {/* 4. Customer */}
                      {col('customer') && (
                        <Td>
                          <div className="flex flex-col gap-0.5">
                            <div className="flex items-center gap-1.5">
                              <span className="text-xs font-medium">
                                {order.customer?.name ?? 'â€”'}
                              </span>
                              {order.customer ? (
                                <OrderCustomerBadge customer={order.customer} />
                              ) : null}
                            </div>
                            {order.customer?.code ? (
                              <span className="font-mono text-[10px] text-muted-foreground">
                                {order.customer.code}
                              </span>
                            ) : null}
                          </div>
                        </Td>
                      )}

                      {/* 5. Phone */}
                      {col('phone') && (
                        <Td>
                          <OrderPhoneCell phone={order.billing_phone} />
                        </Td>
                      )}

                      {/* 6. Status */}
                      {col('status') && (
                        <Td>
                          <OrderStatusBadge
                            status={order.status}
                            onClick={onStatusChange ? () => onStatusChange(order) : undefined}
                          />
                        </Td>
                      )}

                      {/* 7. Total */}
                      {col('total') && (
                        <Td className="text-end tabular-nums font-medium">
                          {formatMoney(order.total)}
                        </Td>
                      )}

                      {/* 8. Payment Method */}
                      {col('payment_method') && (
                        <Td>
                          <span className="text-xs text-muted-foreground">
                            {order.payment_method_title ?? order.payment_method ?? 'â€”'}
                          </span>
                        </Td>
                      )}

                      {/* 9. Products Count */}
                      {col('products_count') && (
                        <Td className="text-center">
                          <span className="tabular-nums font-medium">{order.lines.length}</span>
                        </Td>
                      )}

                      {/* 10. Address */}
                      {col('address') && (
                        <Td>
                          <OrderAddressCell
                            order={order}
                            onEditLocation={onEditLocation}
                            onDeleteLocation={onDeleteLocation}
                          />
                        </Td>
                      )}

                      {/* 11. Shipping Attempts */}
                      {col('shipping_attempts') && (
                        <Td className="text-center">
                          <AttemptsCell count={order.shipping_attempts ?? 0} />
                        </Td>
                      )}

                      {/* 12. Shipping Company */}
                      {col('shipping_company') && (
                        <Td>
                          {order.shipping_company_name ? (
                            order.tracking_number ? (
                              <a
                                href={`https://www.google.com/search?q=${encodeURIComponent(
                                  `${order.shipping_company_name} ${order.tracking_number}`,
                                )}`}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="inline-flex items-center gap-1 text-xs text-primary hover:underline"
                              >
                                {order.shipping_company_name}
                                <ExternalLink className="size-2.5" />
                              </a>
                            ) : (
                              <span className="text-xs text-muted-foreground">
                                {order.shipping_company_name}
                              </span>
                            )
                          ) : (
                            <span className="text-muted-foreground">â€”</span>
                          )}
                        </Td>
                      )}

                      {/* 13. Created At */}
                      {col('created_at') && (
                        <Td>
                          <span className="text-xs text-muted-foreground tabular-nums">
                            {formatDate(order.created_at)}
                          </span>
                        </Td>
                      )}

                      {/* 14. Updated At */}
                      {col('updated_at') && (
                        <Td>
                          <span className="text-xs text-muted-foreground tabular-nums">
                            {formatDate(order.updated_at)}
                          </span>
                        </Td>
                      )}

                      {/* 15. Actions */}
                      <Td sticky="right" isSelected={isSelected} className="w-10 right-0 text-end">
                        <div className="flex items-center justify-end opacity-0 group-hover:opacity-100 transition-opacity">
                          <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                              <Button
                                variant="ghost"
                                size="icon"
                                className="size-7"
                                aria-label={`Actions for ${order.order_number}`}
                              >
                                <MoreHorizontal className="size-3.5" />
                              </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-44">
                              <DropdownMenuItem onClick={() => onView(order)}>
                                {t('actions.view')}
                              </DropdownMenuItem>
                              {onEdit ? (
                                <DropdownMenuItem onClick={() => onEdit(order)}>
                                  <Edit className="size-3.5" />
                                  {t('actions.edit')}
                                </DropdownMenuItem>
                              ) : null}
                              {onDelete ? (
                                <>
                                  <DropdownMenuSeparator />
                                  <DropdownMenuItem
                                    variant="destructive"
                                    onClick={() => onDelete(order)}
                                  >
                                    <Trash2 className="size-3.5" />
                                    {t('actions.delete')}
                                  </DropdownMenuItem>
                                </>
                              ) : null}
                            </DropdownMenuContent>
                          </DropdownMenu>
                        </div>
                      </Td>
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>
      </div>
    </>
  );
}
