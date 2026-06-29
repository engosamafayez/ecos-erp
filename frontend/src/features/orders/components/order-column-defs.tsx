import type { TFunction } from 'i18next';
import { Edit, ExternalLink, MoreHorizontal, Trash2 } from 'lucide-react';

import type { DataGridColumnDef } from '@/components/data-grid/types';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';

import { OrderAddressCell } from './order-address-cell';
import { OrderCustomerBadge } from './order-customer-badge';
import { OrderPhoneCell } from './order-phone-cell';
import { OrderStatusBadge } from './order-status-badge';
import type { Order } from '../types/order';

// ── Helpers ──────────────────────────────────────────────────────────────────

function formatMoney(n: number): string {
  return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatDate(d: string | null): string {
  if (!d) return '–';
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(d));
}

function AttemptsCell({ count }: { count: number }) {
  const cls =
    count === 0 ? 'text-muted-foreground'
    : count === 1 ? 'text-blue-600 dark:text-blue-400 font-medium'
    : count === 2 ? 'text-orange-500 dark:text-orange-400 font-medium'
    : 'text-red-500 dark:text-red-400 font-semibold';
  return <span className={cn('tabular-nums', cls)}>{count}</span>;
}

// ── Callbacks ─────────────────────────────────────────────────────────────────

export type OrderColumnCallbacks = {
  onView: (order: Order) => void;
  onEdit?: (order: Order) => void;
  onDelete?: (order: Order) => void;
  onStatusChange?: (order: Order) => void;
  onEditLocation?: (order: Order) => void;
  onDeleteLocation?: (order: Order) => void;
};

// ── Factory ───────────────────────────────────────────────────────────────────

/**
 * Returns the 14 DataGrid column definitions for the Orders list.
 * The checkbox selection column is handled automatically by UniversalDataGrid.
 *
 * Call inside useMemo with callbacks as deps to prevent unnecessary re-renders.
 */
export function createOrderColumns(
  callbacks: OrderColumnCallbacks,
  t: TFunction<'orders'>,
): DataGridColumnDef<Order>[] {
  const { onView, onEdit, onDelete, onStatusChange, onEditLocation, onDeleteLocation } = callbacks;

  return [
    {
      key: 'order_number',
      label: t('columns.number'),
      alwaysVisible: true,
      pin: 'left',
      width: 128,
      sortable: true,
      skeletonClassName: 'h-4 w-20',
      cell: (order) => (
        <button
          type="button"
          onClick={() => onView(order)}
          onAuxClick={(e) => { if (e.button === 1) window.open(`/orders/${order.id}`, '_blank'); }}
          className="font-mono text-xs font-medium transition-colors hover:text-primary"
        >
          {order.order_number}
        </button>
      ),
    },
    {
      key: 'store',
      label: t('columns.store'),
      defaultVisible: true,
      skeletonClassName: 'h-4 w-20',
      cell: (order) => (
        <span className="text-xs text-muted-foreground">{order.channel?.name ?? '–'}</span>
      ),
    },
    {
      key: 'customer',
      label: t('columns.customer'),
      defaultVisible: true,
      skeletonClassName: 'h-4 w-28',
      cell: (order) => (
        <div className="flex flex-col gap-0.5">
          <div className="flex items-center gap-1.5">
            <span className="text-xs font-medium">{order.customer?.name ?? '–'}</span>
            {order.customer ? <OrderCustomerBadge customer={order.customer} /> : null}
          </div>
          {order.customer?.code ? (
            <span className="font-mono text-[10px] text-muted-foreground">{order.customer.code}</span>
          ) : null}
        </div>
      ),
    },
    {
      key: 'phone',
      label: t('columns.phone'),
      defaultVisible: true,
      skeletonClassName: 'h-4 w-24',
      cell: (order) => <OrderPhoneCell phone={order.billing_phone} />,
    },
    {
      key: 'status',
      label: t('columns.status'),
      defaultVisible: true,
      sortable: true,
      skeletonClassName: 'h-5 w-20 rounded-full',
      cell: (order) => (
        <OrderStatusBadge
          status={order.status}
          onClick={onStatusChange ? () => onStatusChange(order) : undefined}
        />
      ),
    },
    {
      key: 'total',
      label: t('columns.total'),
      defaultVisible: true,
      sortable: true,
      align: 'end',
      skeletonClassName: 'h-4 w-14',
      cellClassName: 'tabular-nums font-medium',
      cell: (order) => formatMoney(order.total),
    },
    {
      key: 'payment_method',
      label: t('columns.paymentMethod'),
      defaultVisible: true,
      skeletonClassName: 'h-4 w-20',
      cell: (order) => (
        <span className="text-xs text-muted-foreground">
          {order.payment_method_title ?? order.payment_method ?? '–'}
        </span>
      ),
    },
    {
      key: 'products_count',
      label: t('columns.productsCount'),
      defaultVisible: true,
      align: 'center',
      skeletonClassName: 'h-4 w-6',
      cell: (order) => <span className="tabular-nums font-medium">{order.lines.length}</span>,
    },
    {
      key: 'address',
      label: t('columns.address'),
      defaultVisible: true,
      skeletonClassName: 'h-4 w-40',
      cellClassName: 'min-w-56',
      cell: (order) => (
        <OrderAddressCell
          order={order}
          onEditLocation={onEditLocation}
          onDeleteLocation={onDeleteLocation}
        />
      ),
    },
    {
      key: 'shipping_attempts',
      label: t('columns.shippingAttempts'),
      defaultVisible: true,
      align: 'center',
      skeletonClassName: 'h-4 w-6',
      cell: (order) => <AttemptsCell count={order.shipping_attempts ?? 0} />,
    },
    {
      key: 'shipping_company',
      label: t('columns.shippingCompany'),
      defaultVisible: true,
      skeletonClassName: 'h-4 w-20',
      cell: (order) =>
        order.shipping_company_name ? (
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
            <span className="text-xs text-muted-foreground">{order.shipping_company_name}</span>
          )
        ) : (
          <span className="text-muted-foreground">–</span>
        ),
    },
    {
      key: 'created_at',
      label: t('columns.createdAt'),
      defaultVisible: true,
      sortable: true,
      skeletonClassName: 'h-4 w-20',
      cell: (order) => (
        <span className="text-xs text-muted-foreground tabular-nums">{formatDate(order.created_at)}</span>
      ),
    },
    {
      key: 'updated_at',
      label: t('columns.updatedAt'),
      defaultVisible: false,
      skeletonClassName: 'h-4 w-20',
      cell: (order) => (
        <span className="text-xs text-muted-foreground tabular-nums">{formatDate(order.updated_at)}</span>
      ),
    },
    {
      key: 'actions',
      label: '',
      alwaysVisible: true,
      pin: 'right',
      width: 40,
      skeletonClassName: 'h-7 w-7 rounded',
      cellClassName: 'text-end',
      cell: (order) => (
        <div className="flex items-center justify-end opacity-0 transition-opacity group-hover:opacity-100">
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
                  <DropdownMenuItem variant="destructive" onClick={() => onDelete(order)}>
                    <Trash2 className="size-3.5" />
                    {t('actions.delete')}
                  </DropdownMenuItem>
                </>
              ) : null}
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      ),
    },
  ];
}
