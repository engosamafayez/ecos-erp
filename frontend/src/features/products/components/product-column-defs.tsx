import { Edit, Eye, MoreHorizontal, Package, Power, Trash2 } from 'lucide-react';

import type { DataGridColumnDef } from '@/components/data-grid/types';
import { StatusBadge } from '@/components/crud';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

import { ChannelCell } from './badges/channel-badge';
import { ProductTypeBadge } from './badges/product-type-badge';
import { PublishBadge } from './badges/publish-badge';
import { SyncBadge } from './badges/sync-badge';
import { StockStatusBadge } from './stock-status-badge';
import type { Product } from '../types/product';

// ── Helpers ───────────────────────────────────────────────────────────────────

function formatPrice(n: number | null): string {
  if (n == null) return '—';
  return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatDate(d: string | null): string {
  if (!d) return '—';
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(d));
}

// ── Callbacks ─────────────────────────────────────────────────────────────────

export type ProductColumnCallbacks = {
  onView: (product: Product) => void;
  onEdit: (product: Product) => void;
  onDelete: (product: Product) => void;
  onStatusToggle: (product: Product) => void;
};

// ── Factory ───────────────────────────────────────────────────────────────────

/**
 * Returns DataGrid column definitions for the Products workspace.
 * The checkbox selection column is handled automatically by UniversalDataGrid.
 * Call inside useMemo with callbacks as deps.
 */
export function createProductColumns(
  callbacks: ProductColumnCallbacks,
): DataGridColumnDef<Product>[] {
  const { onView, onEdit, onDelete, onStatusToggle } = callbacks;

  return [
    // ── Image ─────────────────────────────────────────────────────────────────
    {
      key: 'image',
      label: 'Image',
      alwaysVisible: false,
      defaultVisible: false,
      width: 56,
      skeletonClassName: 'size-9 rounded',
      cell: (product) =>
        product.image_url ? (
          <img
            src={product.image_url}
            alt=""
            className="size-9 rounded border object-cover"
          />
        ) : (
          <div className="flex size-9 items-center justify-center rounded border bg-muted">
            <Package className="size-4 text-muted-foreground" />
          </div>
        ),
    },

    // ── SKU (pinned left) ─────────────────────────────────────────────────────
    {
      key: 'sku',
      label: 'SKU',
      alwaysVisible: true,
      pin: 'left',
      width: 108,
      sortable: true,
      skeletonClassName: 'h-4 w-16',
      cell: (product) => (
        <button
          type="button"
          onClick={() => onView(product)}
          className="font-mono text-xs font-medium transition-colors hover:text-primary"
        >
          {product.sku}
        </button>
      ),
    },

    // ── Name (pinned left) ────────────────────────────────────────────────────
    {
      key: 'name',
      label: 'Name',
      alwaysVisible: true,
      pin: 'left',
      width: 220,
      sortable: true,
      skeletonClassName: 'h-4 w-36',
      cell: (product) => (
        <div className="flex flex-col gap-0.5">
          <button
            type="button"
            onClick={() => onView(product)}
            className="max-w-[200px] truncate text-start text-sm font-medium transition-colors hover:text-primary"
            title={product.name}
          >
            {product.name}
          </button>
          {product.barcode ? (
            <span className="font-mono text-[10px] text-muted-foreground">
              {product.barcode}
            </span>
          ) : null}
        </div>
      ),
    },

    // ── Product Type ──────────────────────────────────────────────────────────
    {
      key: 'type',
      label: 'Type',
      defaultVisible: true,
      skeletonClassName: 'h-5 w-24 rounded-md',
      cell: (product) => <ProductTypeBadge type={product.product_type} />,
    },

    // ── Category ──────────────────────────────────────────────────────────────
    {
      key: 'category',
      label: 'Category',
      defaultVisible: true,
      skeletonClassName: 'h-4 w-20',
      cell: (product) => (
        <span className="text-xs text-muted-foreground">
          {product.category?.name ?? '—'}
        </span>
      ),
    },

    // ── Channels ──────────────────────────────────────────────────────────────
    {
      key: 'channels',
      label: 'Channels',
      defaultVisible: true,
      skeletonClassName: 'h-5 w-28',
      cell: (product) => <ChannelCell channels={product.channels} />,
    },

    // ── Regular Price ─────────────────────────────────────────────────────────
    {
      key: 'regular_price',
      label: 'Price',
      defaultVisible: true,
      sortable: true,
      align: 'end',
      skeletonClassName: 'h-4 w-14',
      cellClassName: 'tabular-nums',
      cell: (product) => (
        <span className="font-medium">{formatPrice(product.regular_price)}</span>
      ),
    },

    // ── Sale Price ────────────────────────────────────────────────────────────
    {
      key: 'sale_price',
      label: 'Sale Price',
      defaultVisible: false,
      align: 'end',
      skeletonClassName: 'h-4 w-14',
      cellClassName: 'tabular-nums',
      cell: (product) =>
        product.sale_price != null ? (
          <span className="font-medium text-emerald-600 dark:text-emerald-400">
            {formatPrice(product.sale_price)}
          </span>
        ) : (
          <span className="text-muted-foreground">—</span>
        ),
    },

    // ── Stock Status ──────────────────────────────────────────────────────────
    {
      key: 'stock_status',
      label: 'Stock',
      defaultVisible: true,
      skeletonClassName: 'h-5 w-20 rounded-full',
      cell: (product) => <StockStatusBadge status={product.stock_status} />,
    },

    // ── Published ─────────────────────────────────────────────────────────────
    {
      key: 'is_published',
      label: 'Published',
      defaultVisible: true,
      skeletonClassName: 'h-5 w-20 rounded-md',
      cell: (product) => <PublishBadge published={product.is_published} />,
    },

    // ── Sync Status ───────────────────────────────────────────────────────────
    {
      key: 'sync_status',
      label: 'Sync',
      defaultVisible: false,
      skeletonClassName: 'h-5 w-16 rounded-md',
      cell: (product) => <SyncBadge status={product.sync_status} />,
    },

    // ── Active Status (clickable toggle) ──────────────────────────────────────
    {
      key: 'is_active',
      label: 'Status',
      defaultVisible: true,
      sortable: true,
      skeletonClassName: 'h-5 w-16 rounded-full',
      cell: (product) => (
        <button
          type="button"
          onClick={() => onStatusToggle(product)}
          title={product.is_active ? 'Click to deactivate' : 'Click to activate'}
          aria-label={`${product.is_active ? 'Deactivate' : 'Activate'} ${product.name}`}
          className="cursor-pointer transition-opacity hover:opacity-80"
        >
          <StatusBadge status={product.is_active ? 'active' : 'inactive'} />
        </button>
      ),
    },

    // ── Updated At ────────────────────────────────────────────────────────────
    {
      key: 'updated_at',
      label: 'Updated',
      defaultVisible: true,
      sortable: true,
      skeletonClassName: 'h-4 w-20',
      cell: (product) => (
        <span className="text-xs text-muted-foreground tabular-nums">
          {formatDate(product.updated_at)}
        </span>
      ),
    },

    // ── Row Actions (pinned right) ────────────────────────────────────────────
    {
      key: 'actions',
      label: '',
      alwaysVisible: true,
      pin: 'right',
      width: 40,
      skeletonClassName: 'h-7 w-7 rounded',
      cellClassName: 'text-end',
      cell: (product) => (
        <div className="flex items-center justify-end opacity-0 transition-opacity group-hover:opacity-100">
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button
                variant="ghost"
                size="icon"
                className="size-7"
                aria-label={`Actions for ${product.name}`}
              >
                <MoreHorizontal className="size-3.5" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-44">
              <DropdownMenuItem onClick={() => onView(product)}>
                <Eye className="size-3.5" />
                View
              </DropdownMenuItem>
              <DropdownMenuItem onClick={() => onEdit(product)}>
                <Edit className="size-3.5" />
                Edit
              </DropdownMenuItem>
              <DropdownMenuSeparator />
              <DropdownMenuItem onClick={() => onStatusToggle(product)}>
                <Power className="size-3.5" />
                {product.is_active ? 'Deactivate' : 'Activate'}
              </DropdownMenuItem>
              <DropdownMenuSeparator />
              <DropdownMenuItem
                variant="destructive"
                onClick={() => onDelete(product)}
              >
                <Trash2 className="size-3.5" />
                Delete
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      ),
    },
  ];
}
