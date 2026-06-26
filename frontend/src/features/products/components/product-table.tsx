import type { ReactNode } from 'react';
import { useRef } from 'react';
import {
  ArrowDown, ArrowUp, ChevronsUpDown, Copy, Edit, Globe,
  MoreHorizontal, Trash2,
} from 'lucide-react';

import { Button } from '@/components/ui/button';
import {
  DropdownMenu, DropdownMenuContent, DropdownMenuItem,
  DropdownMenuSeparator, DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Skeleton } from '@/components/ui/skeleton';
import { StatusBadge } from '@/components/crud/status-badge';
import { ChannelCell } from '@/features/products/components/badges/channel-badge';
import { SyncBadge } from '@/features/products/components/badges/sync-badge';
import { ProductEmptyState } from '@/features/products/components/product-empty-state';
import type { ColumnVisibility, ColumnWidths } from '@/features/products/hooks/use-column-prefs';
import { DEFAULT_COLUMN_WIDTHS } from '@/features/products/hooks/use-column-prefs';
import type { Product, ProductSortField, SortDirection } from '@/features/products/types/product';
import { cn } from '@/lib/utils';

// ── Sub-types ─────────────────────────────────────────────────────────────────

type SortState = { field: ProductSortField; direction: SortDirection };
export type RowDensity = 'compact' | 'comfortable';

// ── Props ─────────────────────────────────────────────────────────────────────

type ProductTableProps = {
  products: Product[];
  isLoading: boolean;
  isError: boolean;
  sort: SortState;
  onSortChange: (field: ProductSortField) => void;
  selectedIds: Set<string>;
  onSelectRow: (id: string, checked: boolean) => void;
  onSelectAll: (checked: boolean) => void;
  onView?: (product: Product) => void;
  onEdit: (product: Product) => void;
  onDuplicate?: (product: Product) => void;
  onPublish?: (product: Product) => void;
  onDelete?: (product: Product) => void;
  hasFilters: boolean;
  onCreateProduct: () => void;
  onImportProducts?: () => void;
  onClearFilters?: () => void;
  // Column prefs (Part 4)
  visible: ColumnVisibility;
  widths: ColumnWidths;
  onWidthChange: (key: string, width: number) => void;
  // Row density (Part 7)
  density: RowDensity;
};

// ── Helpers ───────────────────────────────────────────────────────────────────

function w(widths: ColumnWidths, key: string): number {
  return widths[key] ?? DEFAULT_COLUMN_WIDTHS[key] ?? 100;
}

function SortHeader({ field, label, sort, onSortChange }: {
  field: ProductSortField; label: string; sort: SortState; onSortChange: (f: ProductSortField) => void;
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
      <Icon className="size-3 shrink-0" />
    </button>
  );
}

/** Drag handle at the right edge of a TH for column resize (Part 4). */
function ResizeHandle({ colKey, currentWidth, onWidthChange }: {
  colKey: string; currentWidth: number; onWidthChange: (key: string, width: number) => void;
}) {
  const startRef = useRef<{ x: number; w: number } | null>(null);

  const handleMouseDown = (e: React.MouseEvent) => {
    e.preventDefault();
    e.stopPropagation();
    startRef.current = { x: e.clientX, w: currentWidth };

    const onMove = (me: MouseEvent) => {
      if (!startRef.current) return;
      const delta = me.clientX - startRef.current.x;
      onWidthChange(colKey, startRef.current.w + delta);
    };
    const onUp = () => {
      document.removeEventListener('mousemove', onMove);
      document.removeEventListener('mouseup', onUp);
      startRef.current = null;
    };
    document.addEventListener('mousemove', onMove);
    document.addEventListener('mouseup', onUp);
  };

  return (
    <div
      onMouseDown={handleMouseDown}
      className="absolute right-0 top-0 h-full w-1.5 cursor-col-resize bg-transparent hover:bg-primary/40 transition-colors z-10"
      aria-hidden
    />
  );
}

function Th({ children, className, style, resizeKey, widths, onWidthChange }: {
  children?: ReactNode; className?: string; style?: React.CSSProperties;
  resizeKey?: string; widths?: ColumnWidths; onWidthChange?: (key: string, w: number) => void;
}) {
  return (
    <th
      scope="col"
      className={cn(
        'relative h-10 px-3 text-start text-xs font-medium text-muted-foreground',
        'first:ps-4 last:pe-4 select-none',
        className,
      )}
      style={style}
    >
      {children}
      {resizeKey && widths && onWidthChange ? (
        <ResizeHandle colKey={resizeKey} currentWidth={w(widths, resizeKey)} onWidthChange={onWidthChange} />
      ) : null}
    </th>
  );
}

function Td({ children, className, style }: { children?: ReactNode; className?: string; style?: React.CSSProperties }) {
  return (
    <td className={cn('px-3 text-sm first:ps-4 last:pe-4 align-middle', className)} style={style}>
      {children}
    </td>
  );
}

function formatDate(d: string | null): string {
  if (!d) return '—';
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(d));
}

// ── Skeleton rows ─────────────────────────────────────────────────────────────

function SkeletonRows({ count, visible, density }: { count: number; visible: ColumnVisibility; density: RowDensity }) {
  const py = density === 'compact' ? 'py-1.5' : 'py-2.5';
  return Array.from({ length: count }, (_, i) => (
    <tr key={i} className="border-b last:border-0">
      <Td className={py}><Skeleton className="size-4 rounded" /></Td>
      {visible.image      && <Td className={py}><Skeleton className="size-10 rounded" /></Td>}
      <Td className={py}><Skeleton className="h-4 w-40" /></Td>
      {visible.category   && <Td className={py}><Skeleton className="h-4 w-24" /></Td>}
      {visible.price      && <Td className={py}><Skeleton className="h-4 w-16" /></Td>}
      {visible.sale_price && <Td className={py}><Skeleton className="h-4 w-16" /></Td>}
      {visible.status     && <Td className={py}><Skeleton className="h-5 w-16 rounded-md" /></Td>}
      {visible.channels   && <Td className={py}><Skeleton className="h-5 w-20 rounded-md" /></Td>}
      {visible.sync       && <Td className={py}><Skeleton className="h-5 w-16 rounded-md" /></Td>}
      {visible.updated_at && <Td className={py}><Skeleton className="h-4 w-20" /></Td>}
      {visible.sku        && <Td className={py}><Skeleton className="h-4 w-24" /></Td>}
      <Td className={py}><Skeleton className="size-7 rounded" /></Td>
    </tr>
  ));
}

// ── Component ─────────────────────────────────────────────────────────────────

export function ProductTable({
  products, isLoading, isError, sort, onSortChange,
  selectedIds, onSelectRow, onSelectAll,
  onView, onEdit, onDuplicate, onPublish, onDelete,
  hasFilters, onCreateProduct, onImportProducts, onClearFilters,
  visible, widths, onWidthChange, density,
}: ProductTableProps) {
  const allSelected = products.length > 0 && products.every((p) => selectedIds.has(p.id));
  const someSelected = !allSelected && products.some((p) => selectedIds.has(p.id));

  // Dynamic colSpan based on visible columns
  const colSpan =
    2 + // checkbox + actions (always)
    1 + // name (always)
    (visible.image      ? 1 : 0) +
    (visible.category   ? 1 : 0) +
    (visible.price      ? 1 : 0) +
    (visible.sale_price ? 1 : 0) +
    (visible.status     ? 1 : 0) +
    (visible.channels   ? 1 : 0) +
    (visible.sync       ? 1 : 0) +
    (visible.updated_at ? 1 : 0) +
    (visible.sku        ? 1 : 0);

  // Row padding per density
  const cellPy = density === 'compact' ? 'py-1.5' : 'py-2.5';

  return (
    <div className="overflow-hidden rounded-lg border bg-card">
      <div className="overflow-x-auto">
        <table className="caption-bottom text-sm" style={{ tableLayout: 'fixed', width: 'max-content', minWidth: '100%' }}>
          {/* ── Fixed-width colgroup ── */}
          <colgroup>
            <col style={{ width: w(widths, 'checkbox') }} />
            {visible.image      && <col style={{ width: w(widths, 'image')      }} />}
            <col style={{ width: w(widths, 'name')      }} />
            {visible.category   && <col style={{ width: w(widths, 'category')   }} />}
            {visible.price      && <col style={{ width: w(widths, 'price')      }} />}
            {visible.sale_price && <col style={{ width: w(widths, 'sale_price') }} />}
            {visible.status     && <col style={{ width: w(widths, 'status')     }} />}
            {visible.channels   && <col style={{ width: w(widths, 'channels')   }} />}
            {visible.sync       && <col style={{ width: w(widths, 'sync')       }} />}
            {visible.updated_at && <col style={{ width: w(widths, 'updated_at') }} />}
            {visible.sku        && <col style={{ width: w(widths, 'sku')        }} />}
            <col style={{ width: w(widths, 'actions')  }} />
          </colgroup>

          {/* ── Sticky header ── */}
          <thead className="sticky top-0 z-10 bg-muted/60 backdrop-blur-sm border-b">
            <tr>
              <Th><input type="checkbox" aria-label="Select all" checked={allSelected} ref={(el) => { if (el) el.indeterminate = someSelected; }} onChange={(e) => onSelectAll(e.target.checked)} className="size-4 cursor-pointer rounded accent-primary" /></Th>
              {visible.image      && <Th resizeKey="image"      widths={widths} onWidthChange={onWidthChange}>Image</Th>}
              <Th resizeKey="name" widths={widths} onWidthChange={onWidthChange} className="min-w-0">
                <SortHeader field="name" label="Product Name" sort={sort} onSortChange={onSortChange} />
              </Th>
              {visible.category   && <Th resizeKey="category"   widths={widths} onWidthChange={onWidthChange}>Category</Th>}
              {visible.price      && <Th resizeKey="price"      widths={widths} onWidthChange={onWidthChange} className="text-end"><SortHeader field="regular_price" label="Price" sort={sort} onSortChange={onSortChange} /></Th>}
              {visible.sale_price && <Th resizeKey="sale_price" widths={widths} onWidthChange={onWidthChange} className="text-end">Discount Price</Th>}
              {visible.status     && <Th resizeKey="status"     widths={widths} onWidthChange={onWidthChange}>Status</Th>}
              {visible.channels   && <Th resizeKey="channels"   widths={widths} onWidthChange={onWidthChange}><span className="inline-flex items-center gap-1"><Globe className="size-3" />Channels</span></Th>}
              {visible.sync       && <Th resizeKey="sync"       widths={widths} onWidthChange={onWidthChange}>Sync</Th>}
              {visible.updated_at && <Th resizeKey="updated_at" widths={widths} onWidthChange={onWidthChange}><SortHeader field="updated_at" label="Last Updated" sort={sort} onSortChange={onSortChange} /></Th>}
              {visible.sku        && <Th resizeKey="sku"        widths={widths} onWidthChange={onWidthChange}>SKU</Th>}
              <Th className="text-end">Actions</Th>
            </tr>
          </thead>

          {/* ── Body ── */}
          <tbody className="divide-y">
            {isLoading ? (
              <SkeletonRows count={8} visible={visible} density={density} />
            ) : isError ? (
              <tr>
                <td colSpan={colSpan} className="py-16 text-center text-sm text-muted-foreground">
                  Failed to load products. Please try again.
                </td>
              </tr>
            ) : products.length === 0 ? (
              <tr>
                <td colSpan={colSpan} className="p-0">
                  <ProductEmptyState
                    hasFilters={hasFilters}
                    onCreateProduct={onCreateProduct}
                    onImportProducts={onImportProducts}
                    onClearFilters={onClearFilters}
                  />
                </td>
              </tr>
            ) : (
              products.map((product) => {
                const isSelected = selectedIds.has(product.id);
                return (
                  <tr
                    key={product.id}
                    className={cn('group transition-colors hover:bg-accent/40', isSelected && 'bg-primary/5')}
                  >
                    {/* Checkbox */}
                    <Td className={cellPy}>
                      <input type="checkbox" aria-label={`Select ${product.name}`} checked={isSelected} onChange={(e) => onSelectRow(product.id, e.target.checked)} className="size-4 cursor-pointer rounded accent-primary" />
                    </Td>

                    {/* Image */}
                    {visible.image && (
                      <Td className={cellPy}>
                        {product.image_url ? (
                          <img src={product.image_url} alt={product.name} className="size-10 rounded-md object-cover ring-1 ring-border" />
                        ) : (
                          <div className="flex size-10 items-center justify-center rounded-md bg-muted ring-1 ring-border">
                            <span className="text-[10px] font-bold uppercase text-muted-foreground">{product.name.slice(0, 2)}</span>
                          </div>
                        )}
                      </Td>
                    )}

                    {/* Product Name */}
                    <Td className={cellPy}>
                      <button type="button" onClick={() => (onView ?? onEdit)(product)} className="text-start font-medium hover:text-primary transition-colors truncate block w-full">
                        {product.name}
                      </button>
                      {product.short_description ? (
                        <p className="truncate text-xs text-muted-foreground">{product.short_description}</p>
                      ) : null}
                    </Td>

                    {/* Category */}
                    {visible.category && <Td className={cellPy}><span className="text-muted-foreground truncate block">{product.category?.name ?? '—'}</span></Td>}

                    {/* Price */}
                    {visible.price && (
                      <Td className={cn(cellPy, 'text-end tabular-nums')}>
                        {product.regular_price != null ? product.regular_price.toFixed(2) : <span className="text-muted-foreground">—</span>}
                      </Td>
                    )}

                    {/* Sale Price */}
                    {visible.sale_price && (
                      <Td className={cn(cellPy, 'text-end tabular-nums')}>
                        {product.sale_price != null
                          ? <span className="text-emerald-600 dark:text-emerald-400 font-medium">{product.sale_price.toFixed(2)}</span>
                          : <span className="text-muted-foreground">—</span>}
                      </Td>
                    )}

                    {/* Status */}
                    {visible.status && <Td className={cellPy}><StatusBadge status={product.is_active ? 'active' : 'inactive'} /></Td>}

                    {/* Channels */}
                    {visible.channels && <Td className={cellPy}><ChannelCell channels={product.channels} /></Td>}

                    {/* Sync */}
                    {visible.sync && <Td className={cellPy}><SyncBadge status={product.sync_status} /></Td>}

                    {/* Last Updated */}
                    {visible.updated_at && <Td className={cellPy}><span className="text-muted-foreground text-xs">{formatDate(product.updated_at)}</span></Td>}

                    {/* SKU */}
                    {visible.sku && <Td className={cellPy}><span className="font-mono text-xs text-muted-foreground">{product.sku}</span></Td>}

                    {/* Actions */}
                    <Td className={cn(cellPy, 'text-end')}>
                      <div className="flex items-center justify-end gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                        <Button variant="ghost" size="icon" className="size-7" aria-label={`Edit ${product.name}`} onClick={() => onEdit(product)}>
                          <Edit className="size-3.5" />
                        </Button>
                        {onDuplicate ? (
                          <Button variant="ghost" size="icon" className="size-7" aria-label={`Duplicate ${product.name}`} onClick={() => onDuplicate(product)}>
                            <Copy className="size-3.5" />
                          </Button>
                        ) : null}
                        <DropdownMenu>
                          <DropdownMenuTrigger asChild>
                            <Button variant="ghost" size="icon" className="size-7" aria-label={`More actions for ${product.name}`}>
                              <MoreHorizontal className="size-3.5" />
                            </Button>
                          </DropdownMenuTrigger>
                          <DropdownMenuContent align="end" className="w-40">
                            <DropdownMenuItem onClick={() => onEdit(product)}><Edit className="size-3.5" />Edit</DropdownMenuItem>
                            {onDuplicate ? <DropdownMenuItem onClick={() => onDuplicate(product)}><Copy className="size-3.5" />Duplicate</DropdownMenuItem> : null}
                            {onPublish ? <DropdownMenuItem onClick={() => onPublish(product)}><Globe className="size-3.5" />{product.is_published ? 'Unpublish' : 'Publish'}</DropdownMenuItem> : null}
                            {onDelete ? (
                              <><DropdownMenuSeparator /><DropdownMenuItem variant="destructive" onClick={() => onDelete(product)}><Trash2 className="size-3.5" />Archive</DropdownMenuItem></>
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
