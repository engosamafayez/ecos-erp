import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import {
  AlertTriangle,
  BookOpen,
  ChevronDown,
  ChevronUp,
  Clock,
  Copy,
  Loader2,
  Package,
  Pencil,
  TriangleAlert,
} from 'lucide-react';

import { useRecipeCostHistoryQuery, useRecipeQuery } from '@/features/recipes/hooks/use-recipes';

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
import type { CostSource, Recipe, RecipeCostHistoryEntry, RecipeLine } from '@/features/recipes/types/recipe';
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
        تغليف
      </Badge>
    );
  }
  return (
    <Badge variant="outline" className="text-[10px] border-sky-300 text-sky-700 dark:border-sky-700 dark:text-sky-400 px-1 py-0">
      خام
    </Badge>
  );
}

const COST_SOURCE_LABELS: Record<CostSource, string> = {
  fifo:          'FIFO',
  average:       'متوسط',
  last_purchase: 'آخر PO',
  manual:        'يدوي',
  missing:       'بدون تكلفة',
};

const COST_SOURCE_CLASSES: Record<CostSource, string> = {
  fifo:          'border-sky-300 text-sky-700 dark:border-sky-700 dark:text-sky-400',
  average:       'border-teal-300 text-teal-700 dark:border-teal-700 dark:text-teal-400',
  last_purchase: 'border-amber-300 text-amber-700 dark:border-amber-700 dark:text-amber-400',
  manual:        'border-violet-300 text-violet-700 dark:border-violet-700 dark:text-violet-400',
  missing:       'border-red-300 text-red-600 dark:border-red-700 dark:text-red-400',
};

function CostSourceBadge({ source }: { source: CostSource }) {
  return (
    <Badge variant="outline" className={`text-[10px] px-1 py-0 ${COST_SOURCE_CLASSES[source]}`}>
      {COST_SOURCE_LABELS[source]}
    </Badge>
  );
}

// ─── Overview Tab ─────────────────────────────────────────────────────────────

function OverviewTab({ recipe }: { recipe: Recipe }) {
  const { currency, locale } = useCompany();
  const [breakdownOpen, setBreakdownOpen] = useState(false);

  // Use server summary when available; fall back to live calculator
  const hasSummary = recipe.cost_summary !== null;
  const rawMaterialCost  = hasSummary ? recipe.cost_summary!.raw_material_cost  : calcRecipeCost(recipe.lines ?? [], 0, 0).rawMaterialCost;
  const packagingCost    = hasSummary ? recipe.cost_summary!.packaging_cost      : calcRecipeCost(recipe.lines ?? [], 0, 0).packagingCost;
  const manufacturingCost = recipe.manufacturing_cost ?? 0;
  const otherCosts        = recipe.other_costs ?? 0;
  const totalCost         = hasSummary
    ? recipe.cost_summary!.recipe_cost
    : rawMaterialCost + packagingCost + manufacturingCost + otherCosts;

  return (
    <div className="flex flex-col gap-5 p-6">
      {/* Missing cost warning */}
      {recipe.cost_pending && (
        <div className="flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 dark:border-amber-800 dark:bg-amber-900/20 px-3 py-2.5 text-xs text-amber-700 dark:text-amber-400">
          <AlertTriangle className="size-3.5 mt-0.5 shrink-0" />
          <span>
            {recipe.cost_summary?.missing_material_count ?? '?'} مادة (مواد) بدون تكلفة محددة.
            {' '}التكلفة الإجمالية أدناه تقدير جزئي.
          </span>
        </div>
      )}

      {/* Meta */}
      <div className="grid grid-cols-2 gap-4">
        <LabelValue label="رقم الوصفة" value={<span className="font-mono text-xs">{recipe.bom_number}</span>} />
        <LabelValue label="SKU" value={<span className="font-mono text-xs">{recipe.product?.sku ?? '—'}</span>} />
        <LabelValue
          label="الحالة"
          value={
            recipe.is_active ? (
              <Badge className="bg-emerald-100 text-emerald-700 border-emerald-200 text-xs">نشط</Badge>
            ) : (
              <Badge variant="outline" className="text-muted-foreground text-xs">مسودة</Badge>
            )
          }
        />
        <LabelValue
          label="إجمالي المواد"
          value={(() => { const n = recipe.lines?.length ?? recipe.lines_count ?? 0; return `${n} مادة`; })()}
        />
        <LabelValue
          label="آخر تحديث"
          value={recipe.updated_at ? recipe.updated_at.slice(0, 10) : '—'}
        />
        {recipe.cost_summary?.last_calculated_at && (
          <LabelValue
            label="تاريخ حساب التكلفة"
            value={recipe.cost_summary.last_calculated_at.slice(0, 10)}
          />
        )}
        {recipe.product?.channels?.[0] && (
          <LabelValue label="القناة" value={recipe.product.channels[0].name} />
        )}
      </div>

      <Separator />

      {/* Cost breakdown — expandable */}
      <div className="flex flex-col gap-1">
        <button
          type="button"
          onClick={() => setBreakdownOpen((v) => !v)}
          className="flex items-center justify-between w-full text-start"
        >
          <p className="text-xs font-semibold text-muted-foreground uppercase tracking-wide">تفاصيل التكلفة</p>
          {breakdownOpen
            ? <ChevronUp className="size-3.5 text-muted-foreground" />
            : <ChevronDown className="size-3.5 text-muted-foreground" />}
        </button>

        {breakdownOpen && (
          <div className="flex flex-col gap-2.5 pt-2">
            <div className="flex items-center justify-between text-sm">
              <span className="text-muted-foreground">مواد خام</span>
              <span className="tabular-nums">{fmtCost(rawMaterialCost, currency, locale)}</span>
            </div>
            <div className="flex items-center justify-between text-sm">
              <span className="text-muted-foreground">تغليف</span>
              <span className="tabular-nums">{fmtCost(packagingCost, currency, locale)}</span>
            </div>
            <div className="flex items-center justify-between text-sm">
              <span className="text-muted-foreground">تصنيع</span>
              <span className="tabular-nums">{fmtCost(manufacturingCost, currency, locale)}</span>
            </div>
            <div className="flex items-center justify-between text-sm">
              <span className="text-muted-foreground">تكاليف أخرى</span>
              <span className="tabular-nums">{fmtCost(otherCosts, currency, locale)}</span>
            </div>
            <Separator />
          </div>
        )}

        <div className="flex items-center justify-between mt-1">
          <span className="flex items-center gap-1.5 text-sm font-semibold">
            إجمالي التكلفة
            <LiveCostBadge />
          </span>
          <span className="text-sm font-bold tabular-nums">{fmtCost(totalCost, currency, locale)}</span>
        </div>
      </div>

      {/* Notes */}
      {recipe.notes && (
        <>
          <Separator />
          <LabelValue label="ملاحظات الوصفة" value={<span className="whitespace-pre-wrap">{recipe.notes}</span>} />
        </>
      )}

      {/* Execution Instructions */}
      {recipe.execution_instructions && (
        <>
          <Separator />
          <div className="flex flex-col gap-0.5">
            <span className="text-xs font-medium text-muted-foreground uppercase tracking-wide">تعليمات التنفيذ</span>
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
  const lines = recipe.lines ?? [];

  if (lines.length === 0) {
    return (
      <div className="flex items-center justify-center py-16 text-sm text-muted-foreground">
        لا توجد مواد محددة لهذه الوصفة.
      </div>
    );
  }

  const rawLines = lines.filter((l) => l.raw_material?.product_type !== 'packaging_material');
  const pkgLines = lines.filter((l) => l.raw_material?.product_type === 'packaging_material');
  const rawSubtotal = rawLines.reduce((s, l) => s + (l.line_total ?? 0), 0);
  const pkgSubtotal = pkgLines.reduce((s, l) => s + (l.line_total ?? 0), 0);
  const totalCost   = rawSubtotal + pkgSubtotal;
  const hasMissing  = lines.some((l) => l.cost_status === 'missing');

  return (
    <div className="flex flex-col gap-4 p-6">
      {hasMissing && (
        <div className="flex items-center gap-2 rounded-md border border-amber-200 bg-amber-50 dark:border-amber-800 dark:bg-amber-900/20 px-3 py-2 text-xs text-amber-700 dark:text-amber-400">
          <TriangleAlert className="size-3.5 shrink-0" />
          بعض المواد ليس لها تكلفة محددة — الإجماليات تقديرية جزئية.
        </div>
      )}
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b text-muted-foreground text-start">
              <th className="pb-2 pe-3 font-medium">المادة</th>
              <th className="pb-2 pe-3 font-medium w-16">النوع</th>
              <th className="pb-2 pe-3 text-end font-medium w-16">الكمية</th>
              <th className="pb-2 pe-3 text-end font-medium w-14">هدر%</th>
              <th className="pb-2 pe-3 text-end font-medium w-16">الكمية الفعلية</th>
              <th className="pb-2 text-end font-medium w-32">إجمالي التكلفة</th>
            </tr>
          </thead>
          <tbody className="divide-y">
            {rawLines.length > 0 && (
              <>
                <tr>
                  <td colSpan={6} className="py-1.5">
                    <span className="text-[10px] font-semibold text-sky-700 dark:text-sky-400 uppercase tracking-wide">
                      مواد خام
                    </span>
                  </td>
                </tr>
                {rawLines.map((line) => (
                  <MaterialRow key={line.id} line={line} />
                ))}
                <tr className="bg-muted/30">
                  <td colSpan={5} className="py-1 pe-3 text-end text-xs font-medium text-muted-foreground">مجموع المواد الخام</td>
                  <td className="py-1 text-end text-xs font-semibold tabular-nums">{fmtCost(rawSubtotal, currency, locale)}</td>
                </tr>
              </>
            )}

            {pkgLines.length > 0 && (
              <>
                <tr>
                  <td colSpan={6} className="py-1.5">
                    <span className="text-[10px] font-semibold text-violet-700 dark:text-violet-400 uppercase tracking-wide">
                      تغليف
                    </span>
                  </td>
                </tr>
                {pkgLines.map((line) => (
                  <MaterialRow key={line.id} line={line} />
                ))}
                <tr className="bg-muted/30">
                  <td colSpan={5} className="py-1 pe-3 text-end text-xs font-medium text-muted-foreground">مجموع مواد التغليف</td>
                  <td className="py-1 text-end text-xs font-semibold tabular-nums">{fmtCost(pkgSubtotal, currency, locale)}</td>
                </tr>
              </>
            )}
          </tbody>
          <tfoot>
            <tr className="border-t">
              <td colSpan={5} className="pt-3 text-sm font-medium">إجمالي المواد</td>
              <td className="pt-3 text-end text-sm font-bold tabular-nums">{fmtCost(totalCost, currency, locale)}</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  );
}

function MaterialRow({ line }: { line: RecipeLine }) {
  const { currency, locale } = useCompany();
  const imgUrl = getMediaUrl(line.raw_material?.image_url ?? null);
  const hasCost = line.cost_status === 'available' && line.line_total !== null;

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
      <td className="py-2.5 pe-3 text-end tabular-nums text-xs">
        {fmt(line.quantity, 3)}
      </td>
      <td className="py-2.5 pe-3 text-end tabular-nums text-xs text-muted-foreground">
        {fmt(line.waste_percentage || 0, 1)}%
      </td>
      <td className="py-2.5 pe-3 text-end tabular-nums text-xs font-medium">
        {fmt(line.effective_qty, 3)}
      </td>
      <td className="py-2.5 text-end">
        {hasCost ? (
          <div className="flex flex-col items-end gap-0.5">
            <span className="font-medium tabular-nums">{fmtCost(line.line_total!, currency, locale)}</span>
            <CostSourceBadge source={line.cost_source} />
          </div>
        ) : (
          <div className="flex flex-col items-end gap-0.5">
            <CostSourceBadge source="missing" />
          </div>
        )}
      </td>
    </tr>
  );
}

// ─── Cost History Tab ─────────────────────────────────────────────────────────

function CostHistoryTab({ recipeId }: { recipeId: string }) {
  const { currency, locale } = useCompany();
  const { data, isLoading } = useRecipeCostHistoryQuery(recipeId);

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-16 gap-2 text-sm text-muted-foreground">
        <Loader2 className="size-4 animate-spin" />
        جارٍ تحميل سجل التكلفة…
      </div>
    );
  }

  const items = data?.items ?? [];

  if (items.length === 0) {
    return (
      <div className="flex flex-col items-center justify-center py-16 gap-2 text-sm text-muted-foreground">
        <Clock className="size-5 opacity-40" />
        لا توجد إعادة حسابات تكلفة مسجّلة بعد.
      </div>
    );
  }

  return (
    <div className="flex flex-col divide-y p-0">
      {items.map((entry) => (
        <CostHistoryRow key={entry.id} entry={entry} currency={currency} locale={locale} />
      ))}
    </div>
  );
}

const TRIGGER_LABELS: Record<string, string> = {
  recipe_edit:          'تم حفظ الوصفة',
  material_cost_update: 'تم تحديث تكلفة المادة',
};

function CostHistoryRow({
  entry,
  currency,
  locale,
}: {
  entry: RecipeCostHistoryEntry;
  currency: string;
  locale: string;
}) {
  const diff = entry.difference ?? 0;
  const isIncrease = diff > 0;
  const isDecrease = diff < 0;

  return (
    <div className="flex flex-col gap-1.5 px-6 py-3.5">
      <div className="flex items-center justify-between gap-2">
        <span className="text-xs font-medium">
          {TRIGGER_LABELS[entry.trigger_type] ?? entry.trigger_type}
        </span>
        <span className="text-[10px] text-muted-foreground tabular-nums">
          {new Date(entry.occurred_at).toLocaleString('en-EG', { dateStyle: 'medium', timeStyle: 'short' })}
        </span>
      </div>

      <div className="flex items-center gap-3 text-xs">
        {entry.previous_materials_cost !== null ? (
          <span className="text-muted-foreground tabular-nums">
            {fmtCost(entry.previous_materials_cost, currency, locale)}
          </span>
        ) : (
          <span className="text-muted-foreground italic">—</span>
        )}
        <span className="text-muted-foreground/50">→</span>
        <span className="font-semibold tabular-nums">{fmtCost(entry.new_materials_cost, currency, locale)}</span>

        {diff !== 0 && (
          <span
            className={`tabular-nums font-medium ${
              isIncrease ? 'text-red-600 dark:text-red-400' : isDecrease ? 'text-emerald-600 dark:text-emerald-400' : ''
            }`}
          >
            {isIncrease ? '+' : ''}{fmtCost(diff, currency, locale)}
          </span>
        )}

        {entry.has_missing_costs && (
          <Badge variant="outline" className="text-[10px] px-1 py-0 border-amber-300 text-amber-600 dark:border-amber-700 dark:text-amber-400">
            جزئي
          </Badge>
        )}
      </div>

      {entry.trigger_source && (
        <p className="text-[10px] text-muted-foreground font-mono">{entry.trigger_source}</p>
      )}
    </div>
  );
}

// ─── Shared ───────────────────────────────────────────────────────────────────

function MetricPill({ label, value, dot = true, warn = false }: { label: string; value: string; dot?: boolean; warn?: boolean }) {
  return (
    <div className="flex items-center gap-1">
      {dot && <span className="text-[10px] text-muted-foreground/40 select-none">·</span>}
      <span className="text-[10px] text-muted-foreground uppercase tracking-wide font-medium">{label}</span>
      <span className={`text-[10px] font-semibold tabular-nums ${warn ? 'text-amber-600 dark:text-amber-400' : ''}`}>{value}</span>
      {warn && <TriangleAlert className="size-3 text-amber-500" />}
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

  const { data: fullRecipe, isFetching: isDetailFetching } = useRecipeQuery(recipe?.id ?? '');
  const display = fullRecipe ?? recipe;

  // Derive total cost for the header metric pill
  const headerCost = display
    ? (display.cost_summary?.recipe_cost
        ?? ((display.recipe_cost ?? 0) + (display.manufacturing_cost ?? 0) + (display.other_costs ?? 0)))
    : 0;

  function handleCreateFrom() {
    if (recipe) {
      navigate(ROUTES.recipesNew, { state: { sourceRecipeId: recipe.id } });
      onOpenChange(false);
    }
  }

  const tabs: TabItem[] = display
    ? [
        { key: 'overview',           label: 'نظرة عامة',           content: <OverviewTab recipe={display} /> },
        { key: 'materials',          label: 'المواد',          content: <MaterialsTab recipe={display} />, badge: display.lines?.length ?? display.lines_count },
        { key: 'cost-history',       label: 'سجل التكلفة',       content: <CostHistoryTab recipeId={display.id} /> },
        { key: 'production-history', label: 'سجل الإنتاج', content: <PlaceholderTab message="سجل الإنتاج غير متاح بعد." /> },
      ]
    : [];

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent side="right" className="w-full sm:max-w-xl p-0 flex flex-col gap-0">
        <SheetTitle className="sr-only">
          {recipe ? `وصفة: ${recipe.product?.name ?? recipe.bom_number}` : 'تفاصيل الوصفة'}
        </SheetTitle>

        {/* Summary Header */}
        <div className="border-b px-5 pt-4 pb-3 shrink-0">
          {/* Row 1: Identity + Actions */}
          <div className="flex items-start justify-between gap-3 mb-2.5">
            <div className="flex items-center gap-3 min-w-0">
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
              <div className="min-w-0">
                <div className="flex items-center gap-1.5 mb-0.5 flex-wrap">
                  {display?.is_active ? (
                    <Badge className="text-[10px] px-1.5 py-0 h-4 leading-none bg-emerald-100 text-emerald-700 border border-emerald-200 dark:bg-emerald-900/30 dark:text-emerald-400 dark:border-emerald-800">
                      نشط
                    </Badge>
                  ) : (
                    <Badge variant="outline" className="text-[10px] px-1.5 py-0 h-4 leading-none text-muted-foreground">
                      مسودة
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
                  نسخ
                </Button>
                <Button
                  size="sm"
                  variant="outline"
                  className="h-7 gap-1 px-2 text-xs"
                  onClick={() => onEdit(recipe)}
                  aria-label={`Edit recipe ${display?.bom_number ?? ''}`}
                >
                  <Pencil className="size-3" aria-hidden />
                  تعديل
                </Button>
              </div>
            )}
          </div>

          {/* Row 2: Key Metrics */}
          {display && (
            <div className="flex items-center flex-wrap">
              <MetricPill
                dot={false}
                label="التكلفة"
                value={fmtCost(headerCost, currency, locale)}
                warn={display.cost_pending}
              />
              {(display.total_waste_pct ?? 0) > 0 && (
                <MetricPill
                  label="الهدر"
                  value={`${(display.total_waste_pct ?? 0).toFixed(2)}%`}
                />
              )}
              <MetricPill
                label="المواد"
                value={String(display.lines?.length ?? display.lines_count ?? 0)}
              />
              {display.product?.channels?.[0] && (
                <MetricPill label="القناة" value={display.product.channels[0].name} />
              )}
              {display.product?.channels?.[0]?.company_name && (
                <MetricPill label="الشركة" value={display.product.channels[0].company_name} />
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
            اختر وصفة لعرض التفاصيل.
          </div>
        )}
      </SheetContent>
    </Sheet>
  );
}
