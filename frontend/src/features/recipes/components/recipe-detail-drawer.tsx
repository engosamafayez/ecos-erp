import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { BookOpen, Copy, Loader2, Package, Pencil, TriangleAlert } from 'lucide-react';

import { useRecipeQuery } from '@/features/recipes/hooks/use-recipes';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { Sheet, SheetContent, SheetTitle } from '@/components/ui/sheet';
import { Tabs } from '@/components/ds/tabs';
import type { TabItem } from '@/components/ds/tabs';
import { useCompany } from '@/features/organization/context/company-context';
import { formatMoney } from '@/lib/format';
import { calcRecipeCost } from '@/lib/recipe-cost-calculator';
import { LiveCostBadge } from '@/components/ui/live-cost-badge';
import { getMediaUrl } from '@/lib/media';
import type { Recipe } from '@/features/recipes/types/recipe';
import { ROUTES } from '@/router/routes';

// ─── Helpers ──────────────────────────────────────────────────────────────────

function fmt(n: number, dec = 2): string {
  return n.toLocaleString('en-EG', { minimumFractionDigits: dec, maximumFractionDigits: dec });
}

function fmtCost(n: number, currency = 'EGP', locale = 'en-EG'): string {
  return formatMoney(n, currency, locale);
}

function LabelValue({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-0.5">
      <span className="text-xs font-medium text-muted-foreground uppercase tracking-wide">{label}</span>
      <span className="text-sm">{value ?? '—'}</span>
    </div>
  );
}

function MaterialTypeBadge({ type }: { type: string | undefined }) {
  if (!type) return null;
  if (type === 'packaging_material') {
    return (
      <Badge variant="outline" className="text-[10px] border-violet-300 text-violet-700 dark:border-violet-700 dark:text-violet-400 px-1 py-0">
        Packaging
      </Badge>
    );
  }
  return (
    <Badge variant="outline" className="text-[10px] border-sky-300 text-sky-700 dark:border-sky-700 dark:text-sky-400 px-1 py-0">
      Raw
    </Badge>
  );
}

// ─── Overview Tab ─────────────────────────────────────────────────────────────

function OverviewTab({ recipe }: { recipe: Recipe }) {
  const { currency, locale } = useCompany();
  const { rawMaterialCost, packagingCost, manufacturingCost, otherCosts, recipeCost } =
    calcRecipeCost(recipe.lines ?? [], recipe.manufacturing_cost ?? 0, recipe.other_costs ?? 0);

  return (
    <div className="flex flex-col gap-5 p-6">
      {/* Meta */}
      <div className="grid grid-cols-2 gap-4">
        <LabelValue label="Recipe ID" value={<span className="font-mono text-xs">{recipe.bom_number}</span>} />
        <LabelValue label="SKU" value={<span className="font-mono text-xs">{recipe.product?.sku ?? '—'}</span>} />
        <LabelValue
          label="Status"
          value={
            recipe.is_active ? (
              <Badge className="bg-emerald-100 text-emerald-700 border-emerald-200 text-xs">Active</Badge>
            ) : (
              <Badge variant="outline" className="text-muted-foreground text-xs">Draft</Badge>
            )
          }
        />
        <LabelValue
          label="Total Materials"
          value={(() => { const n = recipe.lines?.length ?? recipe.lines_count ?? 0; return `${n} material${n !== 1 ? 's' : ''}`; })()}
        />
        <LabelValue
          label="Last Updated"
          value={recipe.updated_at ? recipe.updated_at.slice(0, 10) : '—'}
        />
        {recipe.product?.channels?.[0] && (
          <LabelValue label="Channel" value={recipe.product.channels[0].name} />
        )}
      </div>

      <Separator />

      {/* Cost breakdown */}
      <div className="flex flex-col gap-2.5">
        <p className="text-xs font-semibold text-muted-foreground uppercase tracking-wide mb-1">Cost Breakdown</p>

        <div className="flex items-center justify-between text-sm">
          <span className="text-muted-foreground">Raw Materials</span>
          <span className="tabular-nums">{fmtCost(rawMaterialCost, currency, locale)}</span>
        </div>
        <div className="flex items-center justify-between text-sm">
          <span className="text-muted-foreground">Packaging</span>
          <span className="tabular-nums">{fmtCost(packagingCost, currency, locale)}</span>
        </div>
        <div className="flex items-center justify-between text-sm">
          <span className="text-muted-foreground">Manufacturing</span>
          <span className="tabular-nums">{fmtCost(manufacturingCost, currency, locale)}</span>
        </div>
        <div className="flex items-center justify-between text-sm">
          <span className="text-muted-foreground">Other Costs</span>
          <span className="tabular-nums">{fmtCost(otherCosts, currency, locale)}</span>
        </div>
        <Separator />
        <div className="flex items-center justify-between">
          <span className="flex items-center gap-1.5 text-sm font-semibold">
            Recipe Cost
            <LiveCostBadge />
          </span>
          <span className="text-sm font-bold tabular-nums">{fmtCost(recipeCost, currency, locale)}</span>
        </div>
      </div>

      {/* Notes */}
      {recipe.notes && (
        <>
          <Separator />
          <LabelValue label="Recipe Notes" value={<span className="whitespace-pre-wrap">{recipe.notes}</span>} />
        </>
      )}

      {/* Execution Instructions */}
      {recipe.execution_instructions && (
        <>
          <Separator />
          <div className="flex flex-col gap-0.5">
            <span className="text-xs font-medium text-muted-foreground uppercase tracking-wide">Execution Instructions</span>
            <pre className="mt-1 text-xs font-mono text-muted-foreground whitespace-pre-wrap bg-muted/40 rounded-md p-3">
              {recipe.execution_instructions}
            </pre>
          </div>
        </>
      )}
    </div>
  );
}

// ─── Materials Tab ─────────────────────────────────────────────────────────────

function MaterialsTab({ recipe }: { recipe: Recipe }) {
  const { currency, locale } = useCompany();
  const lineCosts = (recipe.lines ?? []).map((line) => {
    const unitCost     = line.raw_material?.material_cost ?? 0;
    const effectiveQty = line.quantity * (1 + (line.waste_percentage || 0) / 100);
    const lineTotal    = effectiveQty * unitCost;
    const hasCost      = unitCost > 0;
    return { ...line, unitCost, effectiveQty, lineTotal, hasCost };
  });

  const totalCost = lineCosts.reduce((sum, l) => sum + l.lineTotal, 0);

  if ((recipe.lines ?? []).length === 0) {
    return (
      <div className="flex items-center justify-center py-16 text-sm text-muted-foreground">
        No materials defined for this recipe.
      </div>
    );
  }

  const rawLines = lineCosts.filter((l) => l.raw_material?.product_type !== 'packaging_material');
  const pkgLines = lineCosts.filter((l) => l.raw_material?.product_type === 'packaging_material');
  const rawSubtotal = rawLines.reduce((s, l) => s + l.lineTotal, 0);
  const pkgSubtotal = pkgLines.reduce((s, l) => s + l.lineTotal, 0);

  return (
    <div className="flex flex-col gap-4 p-6">
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b text-muted-foreground text-left">
              <th className="pb-2 pe-3 font-medium">Material</th>
              <th className="pb-2 pe-3 font-medium w-16">Type</th>
              <th className="pb-2 pe-3 text-right font-medium w-16">Qty</th>
              <th className="pb-2 pe-3 text-right font-medium w-14">Waste%</th>
              <th className="pb-2 pe-3 text-right font-medium w-16">Eff. Qty</th>
              <th className="pb-2 text-right font-medium w-28">Total Cost</th>
            </tr>
          </thead>
          <tbody className="divide-y">
            {/* Raw Materials group */}
            {rawLines.length > 0 && (
              <>
                <tr>
                  <td colSpan={6} className="py-1.5">
                    <span className="text-[10px] font-semibold text-sky-700 dark:text-sky-400 uppercase tracking-wide">
                      Raw Materials
                    </span>
                  </td>
                </tr>
                {rawLines.map((line) => (
                  <MaterialRow key={line.id} line={line} />
                ))}
                <tr className="bg-muted/30">
                  <td colSpan={5} className="py-1 pe-3 text-end text-xs font-medium text-muted-foreground">Raw Subtotal</td>
                  <td className="py-1 text-end text-xs font-semibold tabular-nums">{fmtCost(rawSubtotal, currency, locale)}</td>
                </tr>
              </>
            )}

            {/* Packaging Materials group */}
            {pkgLines.length > 0 && (
              <>
                <tr>
                  <td colSpan={6} className="py-1.5">
                    <span className="text-[10px] font-semibold text-violet-700 dark:text-violet-400 uppercase tracking-wide">
                      Packaging
                    </span>
                  </td>
                </tr>
                {pkgLines.map((line) => (
                  <MaterialRow key={line.id} line={line} />
                ))}
                <tr className="bg-muted/30">
                  <td colSpan={5} className="py-1 pe-3 text-end text-xs font-medium text-muted-foreground">Packaging Subtotal</td>
                  <td className="py-1 text-end text-xs font-semibold tabular-nums">{fmtCost(pkgSubtotal, currency, locale)}</td>
                </tr>
              </>
            )}
          </tbody>
          <tfoot>
            <tr className="border-t">
              <td colSpan={5} className="pt-3 text-sm font-medium">Materials Total</td>
              <td className="pt-3 text-right text-sm font-bold tabular-nums">{fmtCost(totalCost, currency, locale)}</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  );
}

type LineCostRow = {
  id: string;
  raw_material: Recipe['lines'][0]['raw_material'];
  quantity: number;
  waste_percentage: number;
  unitCost: number;
  effectiveQty: number;
  lineTotal: number;
  hasCost: boolean;
};

function MaterialRow({ line }: { line: LineCostRow }) {
  const { currency, locale } = useCompany();
  const imgUrl = getMediaUrl(line.raw_material?.image_url ?? null);
  return (
    <tr>
      <td className="py-2.5 pe-3">
        <div className="flex items-center gap-2">
          <div className="size-7 rounded bg-muted flex items-center justify-center shrink-0 overflow-hidden">
            {imgUrl ? (
              <img src={imgUrl} alt={line.raw_material?.name ?? ''} className="size-full object-cover" />
            ) : (
              <Package className="size-3.5 text-muted-foreground" />
            )}
          </div>
          <div className="min-w-0">
            <p className="font-medium truncate">{line.raw_material?.name ?? '—'}</p>
            <p className="text-xs text-muted-foreground font-mono">{line.raw_material?.sku}</p>
          </div>
        </div>
      </td>
      <td className="py-2.5 pe-3">
        <MaterialTypeBadge type={line.raw_material?.product_type} />
      </td>
      <td className="py-2.5 pe-3 text-right tabular-nums text-xs">
        {fmt(line.quantity, 3)}
      </td>
      <td className="py-2.5 pe-3 text-right tabular-nums text-xs text-muted-foreground">
        {fmt(line.waste_percentage || 0, 1)}%
      </td>
      <td className="py-2.5 pe-3 text-right tabular-nums text-xs font-medium">
        {fmt(line.effectiveQty, 3)}
      </td>
      <td className="py-2.5 text-right font-medium tabular-nums">
        {line.hasCost ? (
          fmtCost(line.lineTotal, currency, locale)
        ) : (
          <span className="text-amber-500 text-xs flex items-center justify-end gap-1">
            <TriangleAlert className="size-3" />No cost
          </span>
        )}
      </td>
    </tr>
  );
}

function MetricPill({ label, value, dot = true }: { label: string; value: string; dot?: boolean }) {
  return (
    <div className="flex items-center gap-1">
      {dot && <span className="text-[10px] text-muted-foreground/40 select-none">·</span>}
      <span className="text-[10px] text-muted-foreground uppercase tracking-wide font-medium">{label}</span>
      <span className="text-[10px] font-semibold tabular-nums">{value}</span>
    </div>
  );
}

function PlaceholderTab({ message }: { message: string }) {
  return (
    <div className="flex items-center justify-center py-16 text-sm text-muted-foreground">
      {message}
    </div>
  );
}

// ─── Drawer ───────────────────────────────────────────────────────────────────

type RecipeDetailDrawerProps = {
  recipe:        Recipe | null;
  open:          boolean;
  onOpenChange:  (open: boolean) => void;
  onEdit:        (r: Recipe) => void;
  initialTab?:   string;
};

export function RecipeDetailDrawer({
  recipe, open, onOpenChange, onEdit, initialTab,
}: RecipeDetailDrawerProps) {
  const navigate   = useNavigate();
  const { currency, locale } = useCompany();
  const [activeTab, setActiveTab] = useState(initialTab ?? 'overview');

  useEffect(() => {
    if (open) setActiveTab(initialTab ?? 'overview');
  }, [open, initialTab]);

  // Fetch full detail (with lines) — list response only has lines_count
  const { data: fullRecipe, isFetching: isDetailFetching } = useRecipeQuery(recipe?.id ?? '');
  const display = fullRecipe ?? recipe;

  function handleCreateFrom() {
    if (recipe) {
      navigate(ROUTES.recipesNew, { state: { sourceRecipeId: recipe.id } });
      onOpenChange(false);
    }
  }

  const tabs: TabItem[] = display
    ? [
        { key: 'overview',           label: 'Overview',           content: <OverviewTab recipe={display} /> },
        { key: 'materials',          label: 'Materials',          content: <MaterialsTab recipe={display} />, badge: display.lines?.length ?? display.lines_count },
        { key: 'cost-history',       label: 'Cost History',       content: <PlaceholderTab message="Cost history is not yet available." /> },
        { key: 'production-history', label: 'Production History', content: <PlaceholderTab message="Production history is not yet available." /> },
      ]
    : [];

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent side="right" className="w-full sm:max-w-xl p-0 flex flex-col gap-0">
        <SheetTitle className="sr-only">
          {recipe ? `Recipe: ${recipe.product?.name ?? recipe.bom_number}` : 'Recipe Details'}
        </SheetTitle>

        {/* Summary Header */}
        <div className="border-b px-5 pt-4 pb-3 shrink-0">
          {/* Row 1: Identity + Actions */}
          <div className="flex items-start justify-between gap-3 mb-2.5">
            <div className="flex items-center gap-3 min-w-0">
              {/* Product image */}
              <div className="size-11 rounded-lg border bg-muted flex items-center justify-center shrink-0 overflow-hidden">
                {getMediaUrl(display?.product?.image_url) ? (
                  <img
                    src={getMediaUrl(display!.product!.image_url)!}
                    alt={display!.product!.name}
                    className="size-full object-cover"
                  />
                ) : (
                  <BookOpen className="size-4 text-muted-foreground" />
                )}
              </div>
              {/* Name + category + bom# */}
              <div className="min-w-0">
                <div className="flex items-center gap-1.5 mb-0.5 flex-wrap">
                  {display?.is_active ? (
                    <Badge className="text-[10px] px-1.5 py-0 h-4 leading-none bg-emerald-100 text-emerald-700 border border-emerald-200 dark:bg-emerald-900/30 dark:text-emerald-400 dark:border-emerald-800">
                      Active
                    </Badge>
                  ) : (
                    <Badge variant="outline" className="text-[10px] px-1.5 py-0 h-4 leading-none text-muted-foreground">
                      Draft
                    </Badge>
                  )}
                  <span className="text-[10px] text-muted-foreground font-mono">{display?.bom_number}</span>
                </div>
                <p className="font-semibold text-sm leading-tight truncate">{display?.product?.name ?? '—'}</p>
                {display?.product?.category && (
                  <p className="text-xs text-muted-foreground truncate">{display.product.category.name}</p>
                )}
              </div>
            </div>
            {/* Actions */}
            {recipe && (
              <div className="flex items-center gap-1.5 shrink-0">
                {isDetailFetching && (
                  <Loader2 className="size-3.5 text-muted-foreground animate-spin" aria-hidden />
                )}
                <Button
                  size="sm"
                  variant="outline"
                  className="h-7 gap-1 px-2 text-xs"
                  onClick={handleCreateFrom}
                  aria-label={`Clone recipe ${display?.bom_number ?? ''}`}
                >
                  <Copy className="size-3" aria-hidden />
                  Clone
                </Button>
                <Button
                  size="sm"
                  variant="outline"
                  className="h-7 gap-1 px-2 text-xs"
                  onClick={() => onEdit(recipe)}
                  aria-label={`Edit recipe ${display?.bom_number ?? ''}`}
                >
                  <Pencil className="size-3" aria-hidden />
                  Edit
                </Button>
              </div>
            )}
          </div>

          {/* Row 2: Key Metrics */}
          {display && (
            <div className="flex items-center flex-wrap">
              <MetricPill
                dot={false}
                label="Cost"
                value={fmtCost((display.recipe_cost ?? 0) + (display.manufacturing_cost ?? 0) + (display.other_costs ?? 0), currency, locale)}
              />
              {(recipe?.total_waste_pct ?? 0) > 0 && (
                <MetricPill
                  label="Waste"
                  value={`${(recipe?.total_waste_pct ?? 0).toFixed(2)}%`}
                />
              )}
              <MetricPill
                label="Materials"
                value={String(display.lines?.length ?? display.lines_count ?? 0)}
              />
              {display.product?.channels?.[0] && (
                <MetricPill label="Channel" value={display.product.channels[0].name} />
              )}
              {display.product?.channels?.[0]?.company_name && (
                <MetricPill label="Company" value={display.product.channels[0].company_name} />
              )}
            </div>
          )}
        </div>

        {/* Tabs */}
        {recipe ? (
          <Tabs
            tabs={tabs}
            activeKey={activeTab}
            onTabChange={setActiveTab}
            className="flex-1 overflow-hidden"
            contentClassName="overflow-y-auto h-full"
          />
        ) : (
          <div className="flex-1 flex items-center justify-center text-sm text-muted-foreground">
            Select a recipe to view details.
          </div>
        )}
      </SheetContent>
    </Sheet>
  );
}
