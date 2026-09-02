import { Eye, Package, Pencil } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { getMediaUrl } from '@/lib/media';
import { cn } from '@/lib/utils';
import { marginColorClass } from '@/features/products/lib/pricing-utils';

import { PublishBadge } from './badges/publish-badge';
import { StockStatusCell } from './product-column-defs';
import type { Product } from '../types/product';

// ── Helpers ───────────────────────────────────────────────────────────────────

function formatPrice(n: number | null): string {
  if (n == null) return '—';
  return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// ── Props ─────────────────────────────────────────────────────────────────────

type ProductMobileCardProps = {
  product: Product;
  isSelected?: boolean;
  isFocused?: boolean;
  onView: (product: Product) => void;
  onEdit?: (product: Product) => void;
  onSelect?: (id: string, checked: boolean) => void;
};

// ── Component ─────────────────────────────────────────────────────────────────

/**
 * Mobile-optimised card for a single product.
 * Shown below the `md` breakpoint in UniversalDataGrid's renderMobileCard slot.
 */
export function ProductMobileCard({
  product,
  isSelected = false,
  isFocused = false,
  onView,
  onEdit,
  onSelect,
}: ProductMobileCardProps) {
  const { t } = useTranslation('products');

  const hasSecondary = Boolean(
    product.category?.name || product.brand?.name || (product.channels?.length ?? 0) > 0 || product.final_margin_pct != null,
  );

  return (
    <div
      role="listitem"
      aria-selected={isSelected}
      data-focused={isFocused || undefined}
      className={cn(
        'relative mb-2 rounded-xl border p-3.5 shadow-sm transition-colors last:mb-0',
        isSelected ? 'bg-primary/5' : 'bg-card',
        isFocused && 'outline outline-1 -outline-offset-1 outline-primary/50',
      )}
    >
      {/* Checkbox */}
      {onSelect ? (
        <div className="absolute start-3.5 top-4">
          <input
            type="checkbox"
            checked={isSelected}
            onChange={(e) => onSelect(product.id, e.target.checked)}
            className="size-4 cursor-pointer rounded accent-primary"
            aria-label={`Select ${product.name}`}
          />
        </div>
      ) : null}

      {/* Main content row */}
      <button
        type="button"
        className={cn('flex w-full items-start gap-3 text-start', onSelect && 'ps-7')}
        onClick={() => onView(product)}
        aria-label={`View ${product.name}`}
      >
        {/* Thumbnail */}
        {getMediaUrl(product.image_url) ? (
          <img
            src={getMediaUrl(product.image_url)!}
            alt=""
            className="size-12 shrink-0 rounded border object-cover"
          />
        ) : (
          <div className="flex size-12 shrink-0 items-center justify-center rounded border bg-muted">
            <Package className="size-5 text-muted-foreground" />
          </div>
        )}

        {/* Details */}
        <div className="min-w-0 flex-1">
          {/* Row 1: Name + Price */}
          <div className="mb-0.5 flex items-start justify-between gap-2">
            <p className="truncate text-[15px] font-semibold leading-tight text-foreground" title={product.name}>
              {product.name}
            </p>
            <span className="shrink-0 text-sm font-semibold tabular-nums">
              {formatPrice(product.regular_price)}
            </span>
          </div>

          {/* Row 2: SKU */}
          <p className="mb-1.5 font-mono text-[11px] text-muted-foreground">{product.sku}</p>

          {/* Row 3: PRIMARY badges — canonical availability (fixed: reads the SAME
              product/product_type/availability_state/manufacturing_availability
              branch the desktop list column reads, via the exported StockStatusCell,
              instead of the WooCommerce-only stock_status field). */}
          <div className="flex flex-wrap items-center gap-1.5">
            <StockStatusCell product={product} />
            <PublishBadge published={product.is_published} />
          </div>

          {/* Row 4: SECONDARY tier — category/brand/channels/margin (design report
              §10: not just image+name+price). Read as-is from the server-computed
              fields; never recomputed here (CTO Rule, product.ts line 87). */}
          {hasSecondary ? (
            <div className="mt-1.5 flex flex-wrap items-center gap-x-2.5 gap-y-1 text-[11px] text-muted-foreground">
              {product.category?.name ? <span className="truncate">{product.category.name}</span> : null}
              {product.brand?.name ? <span className="truncate">{product.brand.name}</span> : null}
              {(product.channels?.length ?? 0) > 0 ? (
                <span className="truncate">
                  {product.channels!.length === 1
                    ? product.channels![0].name
                    : t($ => $.mobileCard.channelsCount, { count: product.channels!.length })}
                </span>
              ) : null}
              {product.final_margin_pct != null ? (
                <span className={cn('font-medium tabular-nums', marginColorClass(product.final_margin_pct))}>
                  {t($ => $.mobileCard.marginPercent, { value: product.final_margin_pct.toFixed(0) })}
                </span>
              ) : null}
            </div>
          ) : null}
        </div>
      </button>

      {/* Quick actions */}
      <div className={cn('mt-2.5 flex items-center justify-end gap-0.5', onSelect && 'ps-7')}>
        <Button
          variant="ghost"
          size="icon"
          className="size-7"
          onClick={(e) => { e.stopPropagation(); onView(product); }}
          aria-label={t($ => $.mobileCard.viewProduct)}
        >
          <Eye className="size-3.5" />
        </Button>
        {onEdit ? (
          <Button
            variant="ghost"
            size="icon"
            className="size-7"
            onClick={(e) => { e.stopPropagation(); onEdit(product); }}
            aria-label={t($ => $.mobileCard.editProduct)}
          >
            <Pencil className="size-3.5" />
          </Button>
        ) : null}
      </div>
    </div>
  );
}
