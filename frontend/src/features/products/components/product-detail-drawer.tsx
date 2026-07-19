import { useEffect, useState } from 'react';
import axios from 'axios';
import { zodResolver } from '@hookform/resolvers/zod';
import { useForm } from 'react-hook-form';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import {
  AlertCircle, AlertTriangle, ArrowUpRight, Calendar,
  CheckCircle2, ChefHat, Circle, Edit, Globe, Package,
  Tag, Wifi, WifiOff, X,
} from 'lucide-react';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import {
  Sheet,
  SheetClose,
  SheetContent,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet';
import { EntityForm } from '@/components/crud';
import { StatusBadge } from '@/components/crud/status-badge';
import { Tabs } from '@/components/ds/tabs';
import { Input } from '@/components/ui/input';
import { ChannelCell } from '@/features/products/components/badges/channel-badge';
import { SyncBadge } from '@/features/products/components/badges/sync-badge';
import { ProductFormFields } from '@/features/products/components/product-form';
import {
  productSchema,
  toFormValues,
  toPayload,
  type ProductFormValues,
} from '@/features/products/components/product-form-schema';
import { useCreateProduct, useUpdateProduct } from '@/features/products/hooks/use-products';
import { productsService } from '@/features/products/services/products-service';
import type { Product, ProductType } from '@/features/products/types/product';
import { usePricingReviews } from '@/features/cost-management/hooks/use-pricing-reviews';
import { calcTotalFromStored } from '@/lib/recipe-cost-calculator';
import { getMediaUrl } from '@/lib/media';
import { uploadMaterialImage } from '@/lib/media-upload';
import { ROUTES } from '@/router/routes';
import { cn } from '@/lib/utils';

// ── Types ─────────────────────────────────────────────────────────────────────

export type DrawerMode = 'view' | 'edit';

type ProductDetailDrawerProps = {
  product: Product | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  initialMode?: DrawerMode;
  initialTab?: string;
  defaultType?: ProductType;
};

// ── View helpers ──────────────────────────────────────────────────────────────

function DetailRow({
  label,
  children,
  className,
}: {
  label: string;
  children: React.ReactNode;
  className?: string;
}) {
  return (
    <div className={cn('flex flex-col gap-0.5', className)}>
      <dt className="text-xs font-medium text-muted-foreground">{label}</dt>
      <dd className="text-sm">{children ?? <span className="text-muted-foreground">—</span>}</dd>
    </div>
  );
}

function DetailGrid({ children }: { children: React.ReactNode }) {
  return <dl className="grid grid-cols-2 gap-x-4 gap-y-4">{children}</dl>;
}

function StatCard({
  label,
  value,
  highlight,
}: {
  label: string;
  value: React.ReactNode;
  highlight?: boolean;
}) {
  return (
    <div className={cn('rounded-lg border p-3', highlight ? 'bg-primary/5 border-primary/20' : 'bg-card')}>
      <p className="text-xs text-muted-foreground mb-0.5">{label}</p>
      <p className="text-lg font-semibold tabular-nums">{value}</p>
    </div>
  );
}

function fmtCurrency(n: number | null | undefined): string {
  if (n == null) return '—';
  return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function fmtDateTime(d: string | null): string {
  if (!d) return '—';
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(d));
}

function fmtDate(d: string | null | undefined): string {
  if (!d) return '—';
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(d));
}

function fmtQty(n: number | null | undefined): string {
  if (n == null) return '—';
  return n.toLocaleString(undefined, { maximumFractionDigits: 3 });
}

function computeMargin(
  sellingPrice: number | null | undefined,
  cost: number | null | undefined,
): number | null {
  if (sellingPrice == null || sellingPrice <= 0 || cost == null) return null;
  return ((sellingPrice - cost) / sellingPrice) * 100;
}

function MarginBadge({ pct }: { pct: number }) {
  const cls =
    pct >= 30
      ? 'text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-950/40 border-emerald-200 dark:border-emerald-800'
      : pct >= 10
        ? 'text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-950/40 border-amber-200 dark:border-amber-800'
        : 'text-red-700 dark:text-red-400 bg-red-50 dark:bg-red-950/40 border-red-200 dark:border-red-800';
  return (
    <Badge className={cn('text-sm font-semibold', cls)}>{pct.toFixed(1)}%</Badge>
  );
}

function PriceHealthBadge({ margin }: { margin: number | null }) {
  if (margin == null) return <span className="text-muted-foreground text-sm">—</span>;
  const config =
    margin >= 35 ? { label: 'Excellent', cls: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-400' } :
    margin >= 20 ? { label: 'Good',      cls: 'border-green-200 bg-green-50 text-green-700 dark:border-green-800 dark:bg-green-950/40 dark:text-green-400' } :
    margin >= 10 ? { label: 'Low',       cls: 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-400' } :
                   { label: 'Critical',  cls: 'border-red-200 bg-red-50 text-red-700 dark:border-red-800 dark:bg-red-950/40 dark:text-red-400' };
  return (
    <span className={cn('inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold', config.cls)}>
      {config.label}
    </span>
  );
}

// ── Product Completion Indicator ──────────────────────────────────────────────

type CompletionStep = { label: string; done: boolean };

function computeCompletion(product: Product): CompletionStep[] {
  const hasChannel = (product.channels?.length ?? 0) > 0;
  return [
    { label: 'Basic Information', done: Boolean(product.name && product.sku && product.category_id) },
    { label: 'Image',             done: Boolean(product.image_url) },
    { label: 'Brand',             done: Boolean(product.brand_id) },
    { label: 'Channel(s)',        done: hasChannel },
    { label: 'Recipe',            done: Boolean(product.has_recipe) },
    { label: 'Pricing',           done: Boolean(product.regular_price) },
  ];
}

function CompletionCard({ product }: { product: Product }) {
  const steps = computeCompletion(product);
  const done  = steps.filter((s) => s.done).length;
  const total = steps.length;
  const pct   = Math.round((done / total) * 100);
  const isReady = pct === 100;

  const barColor = isReady ? 'bg-emerald-500' : pct >= 60 ? 'bg-amber-500' : 'bg-red-500';

  return (
    <div className="rounded-lg border bg-card p-4">
      <div className="flex items-center justify-between mb-2">
        <p className="text-sm font-medium">Manufacturing Readiness</p>
        <span className={cn('text-xs font-semibold tabular-nums', isReady ? 'text-emerald-600' : 'text-muted-foreground')}>
          {pct}%
        </span>
      </div>
      <div className="h-1.5 w-full rounded-full bg-muted overflow-hidden mb-3">
        <div className={cn('h-full rounded-full transition-all', barColor)} style={{ width: `${pct}%` }} />
      </div>
      <ul className="flex flex-col gap-1.5">
        {steps.map((step) => (
          <li key={step.label} className="flex items-center gap-2 text-xs">
            {step.done
              ? <CheckCircle2 className="size-3.5 shrink-0 text-emerald-500" aria-hidden />
              : <Circle className="size-3.5 shrink-0 text-muted-foreground/40" aria-hidden />}
            <span className={step.done ? 'text-foreground' : 'text-muted-foreground'}>{step.label}</span>
          </li>
        ))}
      </ul>
      <div className={cn(
        'mt-3 flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-xs font-medium',
        isReady
          ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400'
          : 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-400',
      )}>
        {isReady
          ? <><CheckCircle2 className="size-3.5 shrink-0" aria-hidden /> 🟢 Manufacturing Ready</>
          : <><Circle className="size-3.5 shrink-0" aria-hidden /> 🟠 Manufacturing Setup Required</>
        }
      </div>
    </div>
  );
}

// ── Tab: General ──────────────────────────────────────────────────────────────

function GeneralTab({ product }: { product: Product }) {
  return (
    <div className="flex flex-col gap-6 p-4">
      {getMediaUrl(product.image_url) ? (
        <img
          src={getMediaUrl(product.image_url)!}
          alt={product.name}
          className="aspect-square w-full max-h-40 rounded-lg object-cover border"
        />
      ) : (
        <div className="flex h-32 items-center justify-center rounded-lg border bg-muted">
          <Package className="size-10 text-muted-foreground" />
        </div>
      )}

      <DetailGrid>
        <DetailRow label="SKU"><span className="font-mono">{product.sku}</span></DetailRow>
        <DetailRow label="Category">{product.category?.name}</DetailRow>
        <DetailRow label="Type">
          {product.product_type === 'finished_good' ? 'Finished Good' : 'Raw Material'}
        </DetailRow>
        <DetailRow label="Status">
          <StatusBadge status={product.is_active ? 'active' : 'inactive'} />
        </DetailRow>
      </DetailGrid>

      {product.description ? (
        <>
          <Separator />
          <DetailRow label="Description">
            <p className="text-sm leading-relaxed text-muted-foreground">{product.description}</p>
          </DetailRow>
        </>
      ) : null}

      <Separator />
      <CompletionCard product={product} />
    </div>
  );
}

// ── Tab: Pricing Engine ───────────────────────────────────────────────────────

function PricingTab({ product }: { product: Product }) {
  const navigate    = useNavigate();
  const queryClient = useQueryClient();

  // Effective cost: recipe-derived for finished goods with recipe, else material_cost
  const effectiveCost = product.has_recipe
    ? (product.product_cost ?? null)
    : (product.material_cost ?? product.product_cost ?? null);

  // Manual cost inline editing
  const [localCost, setLocalCost] = useState<string>(
    () => (effectiveCost != null ? String(effectiveCost) : ''),
  );
  const [costSaved, setCostSaved] = useState(false);

  const saveCost = useMutation({
    mutationFn: (cost: number) =>
      productsService.patch(product.id, { manual_cost: cost }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['products'] });
      setCostSaved(true);
      setTimeout(() => setCostSaved(false), 2000);
    },
  });

  const handleSaveCost = () => {
    const val = parseFloat(localCost);
    if (!isNaN(val) && val >= 0) saveCost.mutate(val);
  };

  // Markup calculator
  const [markupPct, setMarkupPct] = useState<number>(() => {
    if (effectiveCost && effectiveCost > 0 && product.regular_price) {
      return Math.round(((product.regular_price - effectiveCost) / effectiveCost) * 100);
    }
    return 30;
  });
  const suggestedPrice = effectiveCost != null ? effectiveCost * (1 + markupPct / 100) : null;

  // KPIs
  const grossProfitPct = computeMargin(product.regular_price, effectiveCost);
  const effectivePrice =
    product.sale_price != null && product.sale_price > 0
      ? product.sale_price
      : product.regular_price;
  const finalMarginPct = computeMargin(effectivePrice, effectiveCost);

  // Pricing source
  const pricingSource = product.has_recipe
    ? { label: 'Recipe', date: product.active_recipe?.updated_at ?? product.updated_at }
    : { label: 'Manual Override', date: product.updated_at };

  return (
    <div className="flex flex-col gap-4 p-4">
      {/* 1. Current Product Cost */}
      <div className="rounded-lg border bg-primary/5 border-primary/20 p-4">
        <div className="flex items-center justify-between mb-2">
          <p className="text-xs font-medium text-muted-foreground uppercase tracking-wide">
            Current Product Cost
          </p>
          {product.has_recipe ? (
            <span className="inline-flex items-center gap-1 rounded-full border px-1.5 py-0.5 border-emerald-200 bg-emerald-50 text-[10px] font-medium text-emerald-700 dark:border-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-400">
              <span className="size-1.5 rounded-full bg-emerald-500 dark:bg-emerald-400" aria-hidden />
              🟢 Live from Recipe
            </span>
          ) : (
            <span className="inline-flex items-center gap-1 rounded-full border px-1.5 py-0.5 border-slate-200 bg-slate-50 text-[10px] font-medium text-slate-600 dark:border-slate-700 dark:bg-slate-800/30 dark:text-slate-400">
              ✏ Manual
            </span>
          )}
        </div>
        {product.has_recipe ? (
          <p className="text-2xl font-semibold tabular-nums">{fmtCurrency(effectiveCost)}</p>
        ) : (
          <div className="flex items-center gap-2 mt-1">
            <Input
              type="number"
              min="0"
              step="0.01"
              value={localCost}
              onChange={(e) => setLocalCost(e.target.value)}
              onKeyDown={(e) => e.key === 'Enter' && handleSaveCost()}
              className="h-9 w-36 text-base font-semibold tabular-nums"
              aria-label="Manual product cost"
            />
            <Button
              size="sm"
              variant="outline"
              onClick={handleSaveCost}
              disabled={saveCost.isPending}
              className="shrink-0"
            >
              {saveCost.isPending ? 'Saving…' : costSaved ? '✓ Saved' : 'Save'}
            </Button>
          </div>
        )}
      </div>

      {/* 2 & 3. Markup % → Suggested Selling Price */}
      {effectiveCost != null && (
        <div className="rounded-lg border bg-card p-4">
          <p className="text-xs font-medium text-muted-foreground uppercase tracking-wide mb-3">
            Pricing Calculator
          </p>
          <div className="grid grid-cols-3 gap-4 items-end">
            <div>
              <p className="text-xs text-muted-foreground mb-1">Product Cost</p>
              <p className="text-sm font-semibold tabular-nums">{fmtCurrency(effectiveCost)}</p>
            </div>
            <div>
              <label className="text-xs text-muted-foreground mb-1 block" htmlFor="pricing-markup-pct">
                Markup %
              </label>
              <Input
                id="pricing-markup-pct"
                type="number"
                min="0"
                step="1"
                value={markupPct}
                onChange={(e) => setMarkupPct(Number(e.target.value) || 0)}
                className="h-8 text-sm"
              />
            </div>
            <div>
              <p className="text-xs text-muted-foreground mb-1">Suggested Selling Price</p>
              <p className="text-base font-semibold tabular-nums text-primary">
                {fmtCurrency(suggestedPrice)}
              </p>
            </div>
          </div>
        </div>
      )}

      {/* 4 & 5. Regular Price + Sale Price */}
      <div className="grid grid-cols-2 gap-3">
        <StatCard label="Regular Price" value={fmtCurrency(product.regular_price)} />
        <StatCard label="Sale Price" value={fmtCurrency(product.sale_price)} />
      </div>

      {/* 6. Price Health */}
      <div className="rounded-lg border bg-card px-4 py-3 flex items-center justify-between">
        <div>
          <p className="text-xs text-muted-foreground">Price Health</p>
          <p className="text-sm font-medium mt-0.5">
            {grossProfitPct != null
              ? `${grossProfitPct.toFixed(1)}% gross margin`
              : effectiveCost == null
                ? 'Set a product cost to see health'
                : 'Set a selling price to see health'}
          </p>
        </div>
        <PriceHealthBadge margin={grossProfitPct} />
      </div>

      {/* 7 & 8. Gross Profit % + Final Margin % */}
      <div className="grid grid-cols-2 gap-3">
        <div className="rounded-lg border bg-card p-3">
          <p className="text-xs text-muted-foreground mb-1">Gross Profit %</p>
          <p className={cn(
            'text-xl font-semibold tabular-nums',
            grossProfitPct != null && grossProfitPct < 0 ? 'text-red-600 dark:text-red-400' : '',
          )}>
            {grossProfitPct != null ? `${grossProfitPct.toFixed(1)}%` : '—'}
          </p>
          <p className="text-[10px] text-muted-foreground mt-0.5">(Regular − Cost) / Regular</p>
        </div>
        <div className="rounded-lg border bg-card p-3">
          <p className="text-xs text-muted-foreground mb-1">Final Margin %</p>
          <p className={cn(
            'text-xl font-semibold tabular-nums',
            finalMarginPct != null && finalMarginPct < 0 ? 'text-red-600 dark:text-red-400' : '',
          )}>
            {finalMarginPct != null ? `${finalMarginPct.toFixed(1)}%` : '—'}
          </p>
          <p className="text-[10px] text-muted-foreground mt-0.5">
            {product.sale_price ? '(Sale − Cost) / Sale' : '(Regular − Cost) / Regular'}
          </p>
        </div>
      </div>

      {/* Pricing Source Card */}
      <div className="rounded-lg border bg-card px-4 py-3">
        <div className="flex items-center justify-between">
          <div>
            <p className="text-xs text-muted-foreground">Pricing Source</p>
            <p className="text-sm font-semibold mt-0.5">{pricingSource.label}</p>
          </div>
          <div className="text-end">
            <p className="text-xs text-muted-foreground">Last Updated</p>
            <p className="text-xs text-foreground mt-0.5">{fmtDate(pricingSource.date)}</p>
          </div>
        </div>
      </div>

      {/* Price Review Alert */}
      {product.pending_review ? (
        <div className="flex items-center justify-between gap-3 rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-950/40">
          <div className="flex items-center gap-2">
            <AlertTriangle className="size-4 shrink-0 text-amber-600 dark:text-amber-400" aria-hidden />
            <span className="text-sm font-medium text-amber-700 dark:text-amber-400">
              ⚠ Pending Pricing Review
            </span>
          </div>
          <Button
            size="sm"
            variant="outline"
            className="shrink-0 gap-1"
            onClick={() => navigate(ROUTES.costManagementPriceReview)}
          >
            <ArrowUpRight className="size-3.5" />
            Open Price Review
          </Button>
        </div>
      ) : null}
    </div>
  );
}

// ── Tab: Cost ─────────────────────────────────────────────────────────────────

function CostTab({ product }: { product: Product }) {
  const navigate = useNavigate();
  const { data: reviewsData } = usePricingReviews({
    product_id: product.id,
    status: 'pending',
    per_page: 5,
  });
  const pendingCount = reviewsData?.summary?.pending ?? 0;
  const margin = computeMargin(product.regular_price, product.product_cost);

  // PART 4/5: Markup % calculator — initialize from actual prices if available
  const [markupPct, setMarkupPct] = useState<number>(() => {
    if (product.product_cost && product.product_cost > 0 && product.regular_price) {
      return Math.round(((product.regular_price - product.product_cost) / product.product_cost) * 100);
    }
    return 30;
  });
  const suggestedPrice = product.product_cost != null
    ? product.product_cost * (1 + markupPct / 100)
    : null;

  return (
    <div className="flex flex-col gap-4 p-4">
      {/* PART 3: Product Cost Card with Live from Recipe / Manual badge */}
      <div className="rounded-lg border p-4 bg-primary/5 border-primary/20">
        <div className="flex items-center justify-between mb-2">
          <p className="text-xs text-muted-foreground">Product Cost</p>
          {product.has_recipe ? (
            <span className="inline-flex items-center gap-1 rounded-full border px-1.5 py-0.5 border-emerald-200 bg-emerald-50 text-[10px] font-medium text-emerald-700 dark:border-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-400">
              <span className="size-1.5 rounded-full bg-emerald-500 dark:bg-emerald-400" aria-hidden />
              Live from Recipe
            </span>
          ) : (
            <span className="inline-flex items-center gap-1 rounded-full border px-1.5 py-0.5 border-slate-200 bg-slate-50 text-[10px] font-medium text-slate-600 dark:border-slate-700 dark:bg-slate-800/30 dark:text-slate-400">
              ✏ Manual
            </span>
          )}
        </div>
        <p className="text-2xl font-semibold tabular-nums">{fmtCurrency(product.product_cost)}</p>
      </div>

      {/* Secondary cost stats */}
      <div className="grid grid-cols-3 gap-3">
        <StatCard label="Selling Price" value={fmtCurrency(product.regular_price)} />
        <StatCard
          label="Recipe Cost"
          value={fmtCurrency(
            product.active_recipe
              ? calcTotalFromStored(
                  product.active_recipe.recipe_cost ?? 0,
                  product.active_recipe.manufacturing_cost ?? 0,
                  product.active_recipe.other_costs ?? 0,
                )
              : null,
          )}
        />
        <StatCard label="Material Cost" value={fmtCurrency(product.material_cost)} />
      </div>

      {margin !== null ? (
        <div className="flex items-center justify-between rounded-lg border bg-card px-4 py-3">
          <span className="text-sm font-medium">Margin</span>
          <MarginBadge pct={margin} />
        </div>
      ) : null}

      {/* PARTS 4/5: Markup % → Suggested Selling Price calculator */}
      {product.product_cost != null && (
        <>
          <Separator />
          <div className="rounded-lg border bg-card p-4">
            <p className="text-xs font-medium text-muted-foreground uppercase tracking-wide mb-3">Pricing Calculator</p>
            <div className="grid grid-cols-3 gap-4 items-end">
              <div>
                <p className="text-xs text-muted-foreground mb-1">Product Cost</p>
                <p className="text-sm font-semibold tabular-nums">{fmtCurrency(product.product_cost)}</p>
              </div>
              <div>
                <label className="text-xs text-muted-foreground mb-1 block" htmlFor="cost-markup-pct">
                  Markup %
                </label>
                <Input
                  id="cost-markup-pct"
                  type="number"
                  min="0"
                  step="1"
                  value={markupPct}
                  onChange={(e) => setMarkupPct(Number(e.target.value) || 0)}
                  className="h-8 text-sm"
                />
              </div>
              <div>
                <p className="text-xs text-muted-foreground mb-1">Suggested Price</p>
                <p className="text-base font-semibold tabular-nums text-primary">{fmtCurrency(suggestedPrice)}</p>
              </div>
            </div>
          </div>
        </>
      )}

      <Separator />

      <DetailGrid>
        <DetailRow label="Average Cost">{fmtCurrency(product.average_cost)}</DetailRow>
        <DetailRow label="FIFO Cost">{fmtCurrency(product.current_fifo_cost)}</DetailRow>
        <DetailRow label="Last Purchase">{fmtCurrency(product.last_purchase_cost)}</DetailRow>
      </DetailGrid>

      {pendingCount > 0 ? (
        <>
          <Separator />
          <div className="flex items-center justify-between gap-3 rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-950/40">
            <div className="flex items-center gap-2">
              <AlertTriangle className="size-4 shrink-0 text-amber-600 dark:text-amber-400" />
              <span className="text-sm font-medium text-amber-700 dark:text-amber-400">
                {pendingCount} pending pricing review{pendingCount > 1 ? 's' : ''}
              </span>
            </div>
            <Button
              size="sm"
              variant="outline"
              className="shrink-0 gap-1"
              onClick={() => navigate(ROUTES.costManagementPriceReview)}
            >
              <ArrowUpRight className="size-3.5" />
              Open Review
            </Button>
          </div>
        </>
      ) : null}
    </div>
  );
}

// ── Tab: Recipe (PART 9) ──────────────────────────────────────────────────────

function RecipeTab({ product }: { product: Product }) {
  const navigate = useNavigate();

  if (product.product_type !== 'finished_good') {
    return (
      <div className="flex flex-col items-center justify-center p-8 text-center text-muted-foreground">
        <Tag className="size-10 mb-3" />
        <p className="text-sm">Raw materials do not have a bill of materials (recipe).</p>
      </div>
    );
  }

  if (!product.has_recipe || !product.active_recipe) {
    return (
      <div className="flex flex-col gap-4 p-4">
        {/* Recipe Status card */}
        <div className="flex items-center gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950/30">
          <AlertTriangle className="size-5 shrink-0 text-amber-600 dark:text-amber-400" aria-hidden />
          <div className="flex-1 min-w-0">
            <p className="text-sm font-semibold text-amber-800 dark:text-amber-300">Recipe Status</p>
            <p className="text-xs text-amber-700 dark:text-amber-400 mt-0.5">⚠ No Recipe Assigned</p>
          </div>
        </div>
        <p className="text-sm text-muted-foreground">
          This product cannot be manufactured until a recipe is assigned.
        </p>
        <Button
          size="sm"
          className="w-fit gap-2"
          onClick={() => navigate(ROUTES.recipesNew, { state: { product_id: product.id } })}
        >
          <ChefHat className="size-4" aria-hidden />
          Create Recipe
        </Button>
      </div>
    );
  }

  const r = product.active_recipe;

  return (
    <div className="flex flex-col gap-4 p-4">
      {/* Recipe Status card */}
      <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-800 dark:bg-emerald-950/30">
        <div className="flex items-center gap-3">
          <CheckCircle2 className="size-5 shrink-0 text-emerald-600 dark:text-emerald-400" aria-hidden />
          <div className="flex-1 min-w-0">
            <p className="text-sm font-semibold text-emerald-800 dark:text-emerald-300">Recipe Status</p>
            <p className="text-xs text-emerald-700 dark:text-emerald-400 mt-0.5">✅ Recipe Available</p>
          </div>
          <Button
            size="sm"
            variant="outline"
            className="shrink-0 gap-1.5"
            onClick={() => navigate(`${ROUTES.recipes}/${r.id}`)}
          >
            <ArrowUpRight className="size-3.5" />
            View Recipe
          </Button>
        </div>
        <div className="mt-3 grid grid-cols-2 gap-3 border-t border-emerald-200 pt-3 dark:border-emerald-800">
          <div>
            <p className="text-[10px] text-emerald-600 dark:text-emerald-500 mb-0.5">Recipe Cost</p>
            <p className="text-sm font-semibold text-emerald-800 dark:text-emerald-300 tabular-nums">
              {fmtCurrency(calcTotalFromStored(r.recipe_cost ?? 0, r.manufacturing_cost ?? 0, r.other_costs ?? 0))}
            </p>
          </div>
          <div>
            <p className="text-[10px] text-emerald-600 dark:text-emerald-500 mb-0.5">Last Updated</p>
            <p className="text-sm text-emerald-700 dark:text-emerald-400">{fmtDate(r.updated_at)}</p>
          </div>
        </div>
      </div>

      <DetailGrid>
        <DetailRow label="BOM Number"><span className="font-mono text-xs">{r.bom_number}</span></DetailRow>
        <DetailRow label="Version">{r.version}</DetailRow>
        <DetailRow label="Recipe Cost">
          {fmtCurrency(calcTotalFromStored(r.recipe_cost ?? 0, r.manufacturing_cost ?? 0, r.other_costs ?? 0))}
        </DetailRow>
        <DetailRow label="Yield Quantity">{r.yield_quantity != null ? fmtQty(r.yield_quantity) : null}</DetailRow>
        <DetailRow label="Total Materials">{String(r.component_count)}</DetailRow>
        <DetailRow label="Last Updated">{fmtDate(r.updated_at)}</DetailRow>
      </DetailGrid>

      {r.notes ? (
        <>
          <Separator />
          <DetailRow label="Notes">
            <p className="text-sm leading-relaxed">{r.notes}</p>
          </DetailRow>
        </>
      ) : null}

      {/* PART 8: Component availability indicators */}
      {product.recipe_components && product.recipe_components.length > 0 ? (
        <>
          <Separator />
          <div>
            <p className="text-xs font-medium text-muted-foreground mb-2 uppercase tracking-wide">
              Material Availability ({product.recipe_components.length})
            </p>
            <div className="rounded-lg border overflow-hidden">
              <table className="w-full text-xs">
                <thead>
                  <tr className="border-b bg-muted/40">
                    <th className="px-3 py-2 text-start font-medium text-muted-foreground">Material</th>
                    <th className="px-3 py-2 text-end font-medium text-muted-foreground">Qty Needed</th>
                    <th className="px-3 py-2 text-end font-medium text-muted-foreground">Available</th>
                  </tr>
                </thead>
                <tbody className="divide-y">
                  {product.recipe_components.map((comp) => (
                    <tr key={comp.id} className="bg-card">
                      <td className="px-3 py-2">
                        <div className="flex items-center gap-1.5">
                          <span aria-label={comp.is_available ? 'Available' : 'Unavailable'}>
                            {comp.is_available ? '🟢' : '🔴'}
                          </span>
                          <div>
                            <p className="font-medium leading-tight">{comp.name}</p>
                            <p className="text-[10px] text-muted-foreground font-mono">{comp.sku}</p>
                          </div>
                        </div>
                      </td>
                      <td className="px-3 py-2 text-end tabular-nums text-muted-foreground">
                        {fmtQty(comp.quantity)}
                      </td>
                      <td className={cn(
                        'px-3 py-2 text-end tabular-nums font-medium',
                        comp.is_available ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400',
                      )}>
                        {fmtQty(comp.available_qty)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </>
      ) : null}
    </div>
  );
}

// ── Tab: Inventory ────────────────────────────────────────────────────────────

function InventoryTab({ product }: { product: Product }) {
  const hasLiveQty = product.on_hand_qty != null;

  let stockBadge: React.ReactNode;
  if (product.stock_status === 'instock')
    stockBadge = <Badge className="bg-emerald-100 text-emerald-700 border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-400">In Stock</Badge>;
  else if (product.stock_status === 'onbackorder')
    stockBadge = <Badge className="bg-amber-100 text-amber-700 border-amber-200 dark:bg-amber-950/40 dark:text-amber-400">Backorder Allowed</Badge>;
  else if (product.stock_status === 'outofstock')
    stockBadge = <Badge className="bg-red-100 text-red-700 border-red-200 dark:bg-red-950/40 dark:text-red-400">Out of Stock</Badge>;
  else
    stockBadge = <span className="text-muted-foreground">—</span>;

  return (
    <div className="flex flex-col gap-4 p-4">
      <DetailGrid>
        <DetailRow label="Stock Status">{stockBadge}</DetailRow>
        <DetailRow label="Allow Backorder">
          {product.allow_negative_stock ? 'Yes' : 'No'}
        </DetailRow>
        {hasLiveQty ? (
          <>
            <DetailRow label="On Hand">{fmtQty(product.on_hand_qty)}</DetailRow>
            <DetailRow label="Reserved">{fmtQty(product.reserved_qty)}</DetailRow>
            <DetailRow label="Available">{fmtQty(product.available_qty)}</DetailRow>
            <DetailRow label="Inventory Value" className="col-span-2">
              {fmtCurrency(product.inventory_value)}
            </DetailRow>
          </>
        ) : null}
      </DetailGrid>

      {!hasLiveQty ? (
        <>
          <Separator />
          <div className="rounded-lg border bg-muted/30 p-4 text-sm text-muted-foreground">
            <p className="flex items-center gap-2">
              <Package className="size-4 shrink-0" />
              Live inventory quantities are tracked in the{' '}
              <strong className="text-foreground">Inventory</strong> module.
            </p>
          </div>
        </>
      ) : null}
    </div>
  );
}

// ── Tab: WooCommerce ──────────────────────────────────────────────────────────

function WooCommerceTab({ product }: { product: Product }) {
  const channels = product.channels ?? [];
  if (channels.length === 0) {
    return (
      <div className="flex flex-col items-center justify-center p-8 text-center text-muted-foreground">
        <Globe className="size-10 mb-3" />
        <p className="text-sm">This product is not mapped to any sales channels.</p>
      </div>
    );
  }
  return (
    <div className="flex flex-col gap-4 p-4">
      <DetailGrid>
        <DetailRow label="Sync Status"><SyncBadge status={product.sync_status} /></DetailRow>
        {product.woo_sku ? (
          <DetailRow label="WooCommerce SKU">
            <span className="font-mono text-xs">{product.woo_sku}</span>
          </DetailRow>
        ) : null}
      </DetailGrid>
      <Separator />
      <div className="flex flex-col gap-2">
        <span className="text-xs font-medium text-muted-foreground">Channels</span>
        <ul className="flex flex-col gap-2">
          {channels.map((ch) => (
            <li
              key={ch.id}
              className="flex items-center justify-between rounded-md border bg-card p-3"
            >
              <div className="flex items-center gap-2.5">
                <Globe className="size-4 text-muted-foreground" />
                <div>
                  <p className="text-sm font-medium">{ch.name}</p>
                  <p className="text-xs text-muted-foreground capitalize">{ch.company_name ?? ch.platform}</p>
                </div>
              </div>
              <div className="flex flex-col items-end gap-0.5">
                {ch.is_synced ? (
                  <span className="flex items-center gap-1 text-xs text-emerald-600">
                    <Wifi className="size-3" />Synced
                  </span>
                ) : (
                  <span className="flex items-center gap-1 text-xs text-muted-foreground">
                    <WifiOff className="size-3" />Not synced
                  </span>
                )}
                {ch.last_synced_at ? (
                  <span className="text-[10px] text-muted-foreground">
                    {fmtDateTime(ch.last_synced_at)}
                  </span>
                ) : null}
              </div>
            </li>
          ))}
        </ul>
      </div>
    </div>
  );
}

// ── Tab: History ──────────────────────────────────────────────────────────────

function HistoryTab({ product }: { product: Product }) {
  return (
    <div className="p-4">
      <DetailGrid>
        <DetailRow label="Created">
          <span className="flex items-center gap-1.5">
            <Calendar className="size-3.5 text-muted-foreground" />
            {fmtDateTime(product.created_at)}
          </span>
        </DetailRow>
        <DetailRow label="Last Updated">
          <span className="flex items-center gap-1.5">
            <Calendar className="size-3.5 text-muted-foreground" />
            {fmtDateTime(product.updated_at)}
          </span>
        </DetailRow>
        <DetailRow label="Product ID" className="col-span-2">
          <span className="font-mono text-xs text-muted-foreground">{product.id}</span>
        </DetailRow>
      </DetailGrid>
    </div>
  );
}

// ── Tab: Final Margin (PARTS 6/7) ────────────────────────────────────────────

function MarginTab({ product }: { product: Product }) {
  const cost        = product.product_cost ?? null;
  const sellPrice   = product.regular_price ?? null;
  const salePrice   = product.sale_price ?? null;
  const margin      = computeMargin(sellPrice, cost);
  const saleMargin  = computeMargin(salePrice, cost);
  const grossProfit     = sellPrice != null && cost != null ? sellPrice - cost : null;
  const saleGrossProfit = salePrice != null && cost != null ? salePrice - cost : null;
  const hasSale         = salePrice != null;

  return (
    <div className="flex flex-col gap-4 p-4">
      {/* PART 7: Price Health indicator */}
      <div className="rounded-lg border bg-card px-4 py-3 flex items-center justify-between">
        <div>
          <p className="text-xs text-muted-foreground">Price Health</p>
          <p className="text-sm font-medium mt-0.5">
            {margin != null ? `${margin.toFixed(1)}% margin` : 'No cost data yet'}
          </p>
        </div>
        <PriceHealthBadge margin={margin} />
      </div>

      {/* PART 6: Final Margin breakdown table */}
      <div className="rounded-lg border overflow-hidden">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b bg-muted/40">
              <th className="px-4 py-2.5 text-start text-xs font-medium text-muted-foreground">Metric</th>
              <th className="px-4 py-2.5 text-end text-xs font-medium text-muted-foreground">Regular</th>
              {hasSale && (
                <th className="px-4 py-2.5 text-end text-xs font-medium text-emerald-700 dark:text-emerald-400">Sale</th>
              )}
            </tr>
          </thead>
          <tbody className="divide-y">
            <tr>
              <td className="px-4 py-3 text-xs text-muted-foreground">Product Cost</td>
              <td className="px-4 py-3 text-end tabular-nums">{fmtCurrency(cost)}</td>
              {hasSale && <td className="px-4 py-3 text-end tabular-nums">{fmtCurrency(cost)}</td>}
            </tr>
            <tr>
              <td className="px-4 py-3 text-xs text-muted-foreground">Selling Price</td>
              <td className="px-4 py-3 text-end tabular-nums font-medium">{fmtCurrency(sellPrice)}</td>
              {hasSale && (
                <td className="px-4 py-3 text-end tabular-nums font-medium text-emerald-600 dark:text-emerald-400">
                  {fmtCurrency(salePrice)}
                </td>
              )}
            </tr>
            <tr className="bg-muted/20">
              <td className="px-4 py-3 text-xs font-medium">Gross Profit / Unit</td>
              <td className={cn(
                'px-4 py-3 text-end tabular-nums font-semibold',
                grossProfit != null && grossProfit >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400',
              )}>
                {fmtCurrency(grossProfit)}
              </td>
              {hasSale && (
                <td className={cn(
                  'px-4 py-3 text-end tabular-nums font-semibold',
                  saleGrossProfit != null && saleGrossProfit >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400',
                )}>
                  {fmtCurrency(saleGrossProfit)}
                </td>
              )}
            </tr>
            <tr className="bg-muted/20">
              <td className="px-4 py-3 text-xs font-medium">Final Margin</td>
              <td className="px-4 py-3 text-end">
                {margin != null ? <MarginBadge pct={margin} /> : <span className="text-muted-foreground">—</span>}
              </td>
              {hasSale && (
                <td className="px-4 py-3 text-end">
                  {saleMargin != null ? <MarginBadge pct={saleMargin} /> : <span className="text-muted-foreground">—</span>}
                </td>
              )}
            </tr>
          </tbody>
        </table>
      </div>

      {cost == null && (
        <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 flex items-center gap-2 text-sm text-amber-700 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-400">
          <AlertTriangle className="size-4 shrink-0" aria-hidden />
          No product cost set. Create a recipe or set a selling price to see margin analysis.
        </div>
      )}
    </div>
  );
}

// ── Tab: Operations (PART 8 — Cross-Module Navigation) ───────────────────────

function OperationsTab({ product }: { product: Product }) {
  const navigate = useNavigate();

  return (
    <div className="flex flex-col gap-3 p-4">
      {/* Recipe */}
      <div className="rounded-lg border bg-card p-4">
        <div className="flex items-center justify-between mb-1">
          <p className="text-sm font-semibold">Recipe</p>
          {product.has_recipe
            ? <Badge className="text-[11px] px-2 py-0.5 bg-emerald-100 text-emerald-700 border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-400 dark:border-emerald-800">✅ Available</Badge>
            : <Badge className="text-[11px] px-2 py-0.5 bg-amber-100 text-amber-700 border-amber-200 dark:bg-amber-950/40 dark:text-amber-400 dark:border-amber-800">⚠ Missing</Badge>
          }
        </div>
        <p className="text-xs text-muted-foreground mb-3">
          {product.has_recipe
            ? 'An active recipe is assigned. View or update the bill of materials.'
            : 'This product cannot be manufactured until a recipe is created.'}
        </p>
        <Button
          size="sm"
          variant={product.has_recipe ? 'outline' : 'default'}
          className="gap-1.5"
          onClick={() =>
            product.has_recipe && product.active_recipe
              ? navigate(`${ROUTES.recipes}/${product.active_recipe.id}`)
              : navigate(ROUTES.recipesNew, { state: { product_id: product.id } })
          }
        >
          <ArrowUpRight className="size-3.5" />
          {product.has_recipe ? 'View Recipe' : 'Create Recipe'}
        </Button>
      </div>

      {/* Pricing */}
      <div className="rounded-lg border bg-card p-4">
        <div className="flex items-center justify-between mb-1">
          <p className="text-sm font-semibold">Pricing Review</p>
          {product.pending_review === true
            ? <Badge className="text-[11px] px-2 py-0.5 bg-amber-100 text-amber-700 border-amber-200 dark:bg-amber-950/40 dark:text-amber-400 dark:border-amber-800">🟠 Review Required</Badge>
            : product.pending_review === false
              ? <Badge className="text-[11px] px-2 py-0.5 bg-emerald-100 text-emerald-700 border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-400 dark:border-emerald-800">🟢 OK</Badge>
              : null
          }
        </div>
        <p className="text-xs text-muted-foreground mb-3">
          Manage selling price decisions when product cost changes.
        </p>
        <Button
          size="sm"
          variant="outline"
          className="gap-1.5"
          onClick={() => navigate(ROUTES.costManagementPriceReview)}
        >
          <ArrowUpRight className="size-3.5" />
          Price Review Center
        </Button>
      </div>

      {/* Manufacturing Availability (PART 7) */}
      <div className="rounded-lg border bg-card p-4">
        <div className="flex items-center justify-between mb-1">
          <p className="text-sm font-semibold">Manufacturing Availability</p>
          {product.manufacturing_availability === 'instock' ? (
            <Badge className="text-[11px] px-2 py-0.5 bg-emerald-100 text-emerald-700 border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-400 dark:border-emerald-800">🟢 Available</Badge>
          ) : product.manufacturing_availability === 'outofstock' ? (
            <Badge className="text-[11px] px-2 py-0.5 bg-red-100 text-red-700 border-red-200 dark:bg-red-950/40 dark:text-red-400 dark:border-red-800">🔴 Blocked</Badge>
          ) : product.manufacturing_availability === 'recipe_missing' ? (
            <Badge className="text-[11px] px-2 py-0.5 bg-slate-100 text-slate-600 border-slate-200 dark:bg-slate-800/40 dark:text-slate-400 dark:border-slate-700">⚪ Recipe Missing</Badge>
          ) : product.has_recipe ? (
            <Badge className="text-[11px] px-2 py-0.5 bg-emerald-100 text-emerald-700 border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-400 dark:border-emerald-800">✅ Recipe Available</Badge>
          ) : (
            <Badge className="text-[11px] px-2 py-0.5 bg-orange-100 text-orange-700 border-orange-200 dark:bg-orange-950/40 dark:text-orange-400 dark:border-orange-800">🟠 No Recipe</Badge>
          )}
        </div>
        <p className="text-xs text-muted-foreground mb-3">
          {product.manufacturing_availability === 'instock'
            ? 'All required materials are available. Ready to manufacture.'
            : product.manufacturing_availability === 'outofstock'
              ? 'Some materials are unavailable. See blocking materials below.'
              : product.manufacturing_availability === 'recipe_missing'
                ? 'No active recipe assigned. Create a recipe first.'
                : 'Production orders and manufacturing workflows.'}
        </p>

        {/* Blocking materials list */}
        {product.blocking_materials && product.blocking_materials.length > 0 ? (
          <div className="mb-3 rounded-md border border-red-200 bg-red-50 p-3 dark:border-red-800 dark:bg-red-950/30">
            <p className="text-xs font-medium text-red-700 dark:text-red-400 mb-1.5">
              Blocking Materials ({product.blocking_materials.length})
            </p>
            <ul className="flex flex-col gap-1">
              {product.blocking_materials.map((m) => (
                <li key={m.id} className="flex items-center justify-between text-xs">
                  <span className="text-red-700 dark:text-red-400">🔴 {m.name}</span>
                  <span className="tabular-nums text-red-600 dark:text-red-500 font-mono">
                    {m.available_qty.toFixed(2)} avail
                  </span>
                </li>
              ))}
            </ul>
          </div>
        ) : null}

        <Button size="sm" variant="outline" className="gap-1.5" disabled>
          <ArrowUpRight className="size-3.5" />
          Manufacturing Module
          <span className="text-[10px] text-muted-foreground">(coming soon)</span>
        </Button>
      </div>
    </div>
  );
}

// ── Tab builder ───────────────────────────────────────────────────────────────

function viewTabs(product: Product) {
  return [
    { key: 'general',     label: 'General',     content: <GeneralTab product={product} /> },
    { key: 'pricing',     label: 'Pricing',     content: <PricingTab product={product} /> },
    { key: 'margin',      label: 'Margin',      content: <MarginTab product={product} /> },
    { key: 'cost',        label: 'Cost',        content: <CostTab product={product} /> },
    { key: 'inventory',   label: 'Inventory',   content: <InventoryTab product={product} /> },
    { key: 'recipe',      label: 'Recipe',      content: <RecipeTab product={product} /> },
    { key: 'woocommerce', label: 'Channels',    content: <WooCommerceTab product={product} /> },
    { key: 'operations',  label: 'Operations',  content: <OperationsTab product={product} /> },
    { key: 'history',     label: 'History',     content: <HistoryTab product={product} /> },
  ];
}

// ── Edit form ─────────────────────────────────────────────────────────────────

const FORM_ID = 'product-drawer-form';

function extractMessage(error: unknown): string {
  return axios.isAxiosError(error) && typeof error.response?.data?.message === 'string'
    ? error.response.data.message
    : 'Something went wrong. Please try again.';
}

// ── Main component ────────────────────────────────────────────────────────────

export function ProductDetailDrawer({
  product,
  open,
  onOpenChange,
  initialMode,
  initialTab,
  defaultType = 'finished_good',
}: ProductDetailDrawerProps) {
  const isNew = product === null;
  const [mode, setMode]               = useState<DrawerMode>(initialMode ?? (isNew ? 'edit' : 'view'));
  const [activeTab, setActiveTab]     = useState(initialTab ?? 'general');
  const [serverError, setServerError] = useState<string | null>(null);
  const [imageFile, setImageFile]     = useState<File | null>(null);
  const [isUploading, setIsUploading] = useState(false);
  // Track the latest saved product so view mode shows fresh data without closing/reopening
  const [localProduct, setLocalProduct] = useState<Product | null>(product);

  const createProduct = useCreateProduct();
  const updateProduct = useUpdateProduct();
  const isMutating    = createProduct.isPending || updateProduct.isPending;
  const isPending     = isMutating || isUploading;

  const form = useForm<ProductFormValues>({
    resolver: zodResolver(productSchema),
    defaultValues: toFormValues(product, defaultType),
  });

  useEffect(() => {
    if (open) {
      setLocalProduct(product);
      setMode(initialMode ?? (product === null ? 'edit' : 'view'));
      setActiveTab(initialTab ?? 'general');
      setServerError(null);
      setImageFile(null);
      form.reset(toFormValues(product, defaultType));
    }
  }, [open, product, initialMode, initialTab, defaultType, form]);

  const handleClose = () => {
    setServerError(null);
    onOpenChange(false);
  };

  const switchToEdit = () => {
    setServerError(null);
    setImageFile(null);
    form.reset(toFormValues(localProduct ?? product, defaultType));
    setMode('edit');
  };

  const cancelEdit = () => {
    setServerError(null);
    setImageFile(null);
    if (isNew) handleClose();
    else setMode('view');
  };

  const handleSubmit = async (values: ProductFormValues) => {
    setServerError(null);
    const payload = toPayload(values);

    if (imageFile) {
      setIsUploading(true);
      try {
        const uploaded = await uploadMaterialImage(imageFile, 'products');
        payload.image_url = uploaded.path;
      } catch {
        setServerError('Image upload failed. Please try again.');
        setIsUploading(false);
        return;
      }
      setIsUploading(false);
    }

    const handlers = {
      onSuccess: (updatedProduct: Product) => {
        if (isNew) handleClose();
        else {
          setLocalProduct(updatedProduct);
          setMode('view');
        }
      },
      onError: (error: unknown) => setServerError(extractMessage(error)),
    };

    if (!isNew && product) {
      updateProduct.mutate({ id: product.id, payload }, handlers);
    } else {
      createProduct.mutate(payload, handlers);
    }
  };

  if (!open) return null;

  const displayProduct = localProduct ?? product;
  const tabs  = displayProduct ? viewTabs(displayProduct) : [];
  const title = mode === 'edit'
    ? (isNew ? 'New Product' : 'Edit Product')
    : (displayProduct?.name ?? '');

  return (
    <Sheet open={open} onOpenChange={handleClose}>
      <SheetContent
        side="right"
        className="flex w-full flex-col gap-0 p-0 sm:max-w-none"
        style={{ width: '48%', minWidth: 520, maxWidth: 900 }}
      >
        {/* Header */}
        <SheetHeader className="border-b px-4 py-3">
          <div className="flex items-center gap-3">
            {mode === 'view' && displayProduct ? (
              getMediaUrl(displayProduct.image_url) ? (
                <img
                  src={getMediaUrl(displayProduct.image_url)!}
                  alt={displayProduct.name}
                  className="size-10 shrink-0 rounded-md object-cover border"
                />
              ) : (
                <div className="flex size-10 shrink-0 items-center justify-center rounded-md bg-muted border">
                  <span className="text-[10px] font-bold uppercase text-muted-foreground">
                    {displayProduct.name.slice(0, 2)}
                  </span>
                </div>
              )
            ) : null}

            <div className="flex-1 min-w-0">
              <SheetTitle className="truncate text-base">{title}</SheetTitle>
              {mode === 'view' && displayProduct ? (
                <div className="flex items-center gap-2 mt-0.5">
                  <span className="font-mono text-xs text-muted-foreground">{displayProduct.sku}</span>
                  <ChannelCell channels={displayProduct.channels} />
                </div>
              ) : null}
            </div>

            <div className="flex shrink-0 items-center gap-1.5">
              {mode === 'view' && (
                <Button size="sm" variant="outline" onClick={switchToEdit}>
                  <Edit className="size-3.5" />
                  Edit
                </Button>
              )}
              <SheetClose asChild>
                <Button size="icon" variant="ghost" className="size-8">
                  <X className="size-4" />
                </Button>
              </SheetClose>
            </div>
          </div>
        </SheetHeader>

        {/* Body */}
        {mode === 'view' && displayProduct ? (
          <div className="flex-1 overflow-hidden">
            <Tabs
              tabs={tabs}
              activeKey={activeTab}
              onTabChange={setActiveTab}
              className="h-full"
              contentClassName="overflow-y-auto"
            />
          </div>
        ) : (
          <div className="flex flex-1 flex-col overflow-hidden">
            <div className="flex-1 overflow-y-auto p-4">
              {serverError ? (
                <Alert variant="destructive" className="mb-4">
                  <AlertCircle className="size-4" />
                  <AlertTitle>Unable to save</AlertTitle>
                  <AlertDescription>{serverError}</AlertDescription>
                </Alert>
              ) : null}
              <EntityForm form={form} id={FORM_ID} onSubmit={handleSubmit}>
                <ProductFormFields isEdit={!isNew} existingProduct={product} onImageChange={setImageFile} />
              </EntityForm>
            </div>

            <div className="flex items-center justify-end gap-2 border-t bg-background p-4">
              <Button type="button" variant="outline" onClick={cancelEdit} disabled={isPending}>
                {isNew ? 'Cancel' : 'Back to view'}
              </Button>
              <Button type="submit" form={FORM_ID} disabled={isPending}>
                {isUploading ? 'Uploading…' : isMutating ? 'Saving…' : isNew ? 'Create product' : 'Save changes'}
              </Button>
            </div>
          </div>
        )}
      </SheetContent>
    </Sheet>
  );
}
