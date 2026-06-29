import type { ReactNode } from 'react';
import { ArrowDown, ArrowUp, ChevronsUpDown, Edit, ExternalLink, MoreHorizontal, Trash2 } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Skeleton } from '@/components/ui/skeleton';
import { OrderAddressCell } from '@/features/orders/components/order-address-cell';
import { OrderCustomerBadge } from '@/features/orders/components/order-customer-badge';
import { OrderPhoneCell } from '@/features/orders/components/order-phone-cell';
import { OrderStatusBadge } from '@/features/orders/components/order-status-badge';
import type { Order, OrderSortField, SortDirection } from '@/features/orders/types/order';
import { cn } from '@/lib/utils';

// ── Sort types ────────────────────────────────────────────────────────────────

type SortState = { field: OrderSortField; direction: SortDirection };

// ── Sub-components ────────────────────────────────────────────────────────────

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
  const Icon = isSorted ? (sort.direction === 'asc' ? ArrowUp : ArrowDown) : ChevronsUpDown;
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

function Th({ children, className }: { children?: ReactNode; className?: string }) {
  return (
    <th
      scope="col"
      className={cn(
        'h-10 px-3 text-start text-xs font-medium text-muted-foreground first:ps-4 last:pe-4',
        className,
      )}
    >
      {children}
    </th>
  );
}

function Td({ children, className }: { children?: ReactNode; className?: string }) {
  return (
    <td className={cn('px-3 py-2.5 text-sm first:ps-4 last:pe-4 align-top', className)}>
      {children}
    </td>
  );
}

function SkeletonRows({ count }: { count: number }) {
  return Array.from({ length: count }, (_, i) => (
    <tr key={i} className="border-b last:border-0">
      <Td><Skeleton className="size-4 rounded" /></Td>          {/* checkbox */}
      <Td><Skeleton className="h-4 w-20" /></Td>               {/* order # */}
      <Td><Skeleton className="h-4 w-20" /></Td>               {/* store */}
      <Td><Skeleton className="h-4 w-28" /></Td>               {/* customer */}
      <Td><Skeleton className="h-4 w-24" /></Td>               {/* phone */}
      <Td><Skeleton className="h-5 w-20 rounded-full" /></Td>  {/* status */}
      <Td><Skeleton className="h-4 w-16" /></Td>               {/* total */}
      <Td><Skeleton className="h-4 w-20" /></Td>               {/* payment */}
      <Td><Skeleton className="h-4 w-8" /></Td>                {/* products */}
      <Td><Skeleton className="h-4 w-40" /></Td>               {/* address */}
      <Td><Skeleton className="h-4 w-8" /></Td>                {/* attempts */}
      <Td><Skeleton className="h-4 w-20" /></Td>               {/* shipping co */}
      <Td><Skeleton className="size-7 w-7 rounded" /></Td>     {/* actions */}
    </tr>
  ));
}

/** Shipping attempts color rule: 0=gray, 1=blue, 2=orange, 3+=red */
function AttemptsCell({ count }: { count: number }) {
  const cls =
    count === 0
      ? 'text-muted-foreground'
      : count === 1
        ? 'text-blue-600 dark:text-blue-400 font-medium'
        : count === 2
          ? 'text-orange-500 dark:text-orange-400 font-medium'
          : 'text-red-500 dark:text-red-400 font-semibold';

  return <span className={cn('tabular-nums', cls)}>{count}</span>;
}

function formatTotal(total: number): string {
  return total.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// ── Props ─────────────────────────────────────────────────────────────────────

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
};

const COL_COUNT = 13;

// ── Component ─────────────────────────────────────────────────────────────────

/**
 * DD-012 — Configurable table with all 13 columns in exact spec order.
 * Column order: Checkbox | Order Number | Store | Customer | Phone | Status |
 *               Total | Payment Method | Products Count | Address |
 *               Shipping Attempts | Shipping Company | Actions
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
}: OrderTableProps) {
  const { t } = useTranslation('orders');

  const allSelected = orders.length > 0 && orders.every((o) => selectedIds.has(o.id));
  const someSelected = !allSelected && orders.some((o) => selectedIds.has(o.id));

  return (
    <div className="overflow-hidden rounded-lg border bg-card">
      <div className="overflow-x-auto">
        <table className="w-full caption-bottom text-sm">
          {/* ── Sticky header ── */}
          <thead className="sticky top-0 z-10 bg-muted/60 backdrop-blur-sm border-b">
            <tr>
              {/* 1. Checkbox */}
              <Th className="w-10">
                <input
                  type="checkbox"
                  aria-label={t('table.selectAll')}
                  checked={allSelected}
                  ref={(el) => { if (el) el.indeterminate = someSelected; }}
                  onChange={(e) => onSelectAll(e.target.checked)}
                  className="size-4 cursor-pointer rounded accent-primary"
                />
              </Th>
              {/* 2. Order Number */}
              <Th className="min-w-32">
                <SortHeader field="order_number" label={t('columns.number')} sort={sort} onSortChange={onSortChange} />
              </Th>
              {/* 3. Store */}
              <Th className="min-w-28">{t('columns.store')}</Th>
              {/* 4. Customer */}
              <Th className="min-w-36">{t('columns.customer')}</Th>
              {/* 5. Phone */}
              <Th className="min-w-32">{t('columns.phone')}</Th>
              {/* 6. Status */}
              <Th className="min-w-36">
                <SortHeader field="status" label={t('columns.status')} sort={sort} onSortChange={onSortChange} />
              </Th>
              {/* 7. Total */}
              <Th className="min-w-24 text-end">
                <SortHeader field="total" label={t('columns.total')} sort={sort} onSortChange={onSortChange} />
              </Th>
              {/* 8. Payment Method */}
              <Th className="min-w-32">{t('columns.paymentMethod')}</Th>
              {/* 9. Products Count */}
              <Th className="min-w-20 text-center">{t('columns.productsCount')}</Th>
              {/* 10. Address */}
              <Th className="min-w-56">{t('columns.address')}</Th>
              {/* 11. Shipping Attempts */}
              <Th className="min-w-24 text-center">{t('columns.shippingAttempts')}</Th>
              {/* 12. Shipping Company */}
              <Th className="min-w-32">{t('columns.shippingCompany')}</Th>
              {/* 13. Actions */}
              <Th className="w-10 text-end">{t('columns.actions')}</Th>
            </tr>
          </thead>

          <tbody className="divide-y">
            {isLoading ? (
              <SkeletonRows count={8} />
            ) : isError ? (
              <tr>
                <td colSpan={COL_COUNT} className="py-16 text-center text-sm text-muted-foreground">
                  {t('table.error')}
                </td>
              </tr>
            ) : orders.length === 0 ? (
              <tr>
                <td colSpan={COL_COUNT} className="py-16 text-center text-sm text-muted-foreground">
                  {t('table.empty')}
                </td>
              </tr>
            ) : (
              orders.map((order) => {
                const isSelected = selectedIds.has(order.id);
                const isFocused = focusedRowId === order.id;
                const phone = order.billing_phone;
                const productsCount = order.lines.length;

                return (
                  <tr
                    key={order.id}
                    data-focused={isFocused || undefined}
                    className={cn(
                      'group transition-colors hover:bg-accent/40',
                      isSelected && 'bg-primary/5',
                      isFocused && 'outline outline-1 outline-primary/50 bg-accent/30',
                    )}
                  >
                    {/* 1. Checkbox */}
                    <Td>
                      <input
                        type="checkbox"
                        aria-label={`Select ${order.order_number}`}
                        checked={isSelected}
                        onChange={(e) => onSelectRow(order.id, e.target.checked)}
                        className="size-4 cursor-pointer rounded accent-primary"
                      />
                    </Td>

                    {/* 2. Order Number — click opens drawer, middle-click opens new tab */}
                    <Td>
                      <button
                        type="button"
                        onClick={() => onView(order)}
                        onAuxClick={(e) => {
                          if (e.button === 1) {
                            window.open(`/orders/${order.id}`, '_blank');
                          }
                        }}
                        className="font-medium font-mono text-xs hover:text-primary transition-colors"
                      >
                        {order.order_number}
                      </button>
                    </Td>

                    {/* 3. Store */}
                    <Td>
                      <span className="text-muted-foreground text-xs">
                        {order.channel?.name ?? '—'}
                      </span>
                    </Td>

                    {/* 4. Customer */}
                    <Td>
                      <div className="flex flex-col gap-0.5">
                        <div className="flex items-center gap-1.5">
                          <span className="font-medium text-xs">
                            {order.customer?.name ?? '—'}
                          </span>
                          {order.customer ? (
                            <OrderCustomerBadge customer={order.customer} />
                          ) : null}
                        </div>
                        {order.customer?.code ? (
                          <span className="text-[10px] text-muted-foreground font-mono">
                            {order.customer.code}
                          </span>
                        ) : null}
                      </div>
                    </Td>

                    {/* 5. Phone */}
                    <Td>
                      <OrderPhoneCell phone={phone} />
                    </Td>

                    {/* 6. Status — click opens quick status change (DD-013) */}
                    <Td>
                      <OrderStatusBadge
                        status={order.status}
                        onClick={onStatusChange ? () => onStatusChange(order) : undefined}
                      />
                    </Td>

                    {/* 7. Total */}
                    <Td className="text-end tabular-nums font-medium">
                      {formatTotal(order.total)}
                    </Td>

                    {/* 8. Payment Method */}
                    <Td>
                      <span className="text-xs text-muted-foreground">
                        {order.payment_method_title ?? order.payment_method ?? '—'}
                      </span>
                    </Td>

                    {/* 9. Products Count */}
                    <Td className="text-center">
                      <span className="tabular-nums font-medium">{productsCount}</span>
                    </Td>

                    {/* 10. Address (DD-014 / DD-015) */}
                    <Td>
                      <OrderAddressCell
                        order={order}
                        onEditLocation={onEditLocation}
                        onDeleteLocation={onDeleteLocation}
                      />
                    </Td>

                    {/* 11. Shipping Attempts */}
                    <Td className="text-center">
                      <AttemptsCell count={order.shipping_attempts ?? 0} />
                    </Td>

                    {/* 12. Shipping Company — click opens tracking */}
                    <Td>
                      {order.shipping_company_name ? (
                        order.tracking_number ? (
                          <a
                            href={`https://www.google.com/search?q=${encodeURIComponent(`${order.shipping_company_name} ${order.tracking_number}`)}`}
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
                        <span className="text-muted-foreground">—</span>
                      )}
                    </Td>

                    {/* 13. Actions (DD-017 — smart, status-dependent) */}
                    <Td className="text-end">
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
  );
}
