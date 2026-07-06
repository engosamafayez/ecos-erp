import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import type { LucideIcon } from 'lucide-react';
import {
  ArrowDown,
  ArrowUp,
  BarChart2,
  Box,
  Calendar,
  DollarSign,
  Factory,
  Loader2,
  Package,
  PackagePlus,
  Pencil,
  RotateCcw,
  ShoppingCart,
  Star,
  Tag,
  TrendingDown,
  TrendingUp,
  Truck,
  Zap,
} from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { Sheet, SheetContent, SheetTitle } from '@/components/ui/sheet';
import { Tabs } from '@/components/ds/tabs';
import type { TabItem } from '@/components/ds/tabs';
import {
  useRawMaterialCostHistory,
  useRawMaterialStockMovements,
} from '@/features/raw-materials/hooks/use-raw-materials';
import type { RawMaterial } from '@/features/raw-materials/types';
import type { MaterialCostHistoryEntry } from '@/features/cost-management/types/pricing-review';
import type { MovementType } from '@/features/stock-ledger/types/stock-movement';
import { PagePagination } from '@/components/page/pagination/page-pagination';
import { useCompany } from '@/features/organization/context/company-context';
import { formatMoney } from '@/lib/format';
import { AddStockWizard } from './add-stock-wizard';
import { getMediaUrl } from '@/lib/media';
import { cn } from '@/lib/utils';
import { resolveMaterialStockStatus } from '@/features/raw-materials/utils/material-stock-status';

// ─── Types ────────────────────────────────────────────────────────────────────

type RawMaterialDetailDrawerProps = {
  material:      RawMaterial | null;
  open:          boolean;
  onOpenChange:  (open: boolean) => void;
  onEdit?:       (material: RawMaterial) => void;
  initialTab?:   string;
};

// ─── Helpers ──────────────────────────────────────────────────────────────────

function stockStatusConfig(availableQty: number | null | undefined, allowNegativeStock: boolean | null | undefined) {
  const status = resolveMaterialStockStatus(availableQty, allowNegativeStock);
  if (status === 'in_stock') {
    return {
      label: 'In Stock',
      dot:   'bg-emerald-500',
      text:  'text-emerald-700 dark:text-emerald-400',
      badge: 'bg-emerald-50 dark:bg-emerald-950/40 border-emerald-200 dark:border-emerald-800',
    };
  }
  return {
    label: 'Out of Stock',
    dot:   'bg-red-500',
    text:  'text-red-700 dark:text-red-400',
    badge: 'bg-red-50 dark:bg-red-950/40 border-red-200 dark:border-red-800',
  };
}

function formatCost(cost: number | null | undefined, unit?: string, currency = 'EGP', locale = 'en-EG'): string {
  if (cost == null) return '—';
  const formatted = formatMoney(cost, currency, locale);
  return unit ? `${formatted} / ${unit}` : formatted;
}

function formatDate(iso: string | null | undefined): string {
  if (!iso) return '—';
  try {
    return new Date(iso).toLocaleDateString('en-EG', {
      year:  'numeric',
      month: 'short',
      day:   'numeric',
    });
  } catch {
    return '—';
  }
}

function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return '—';
  try {
    return new Date(iso).toLocaleString('en-EG', {
      month:  'short',
      day:    'numeric',
      hour:   '2-digit',
      minute: '2-digit',
    });
  } catch {
    return '—';
  }
}

function movementTypeMeta(type: MovementType): { label: string; color: string } {
  switch (type) {
    case 'purchase_receipt':
      return { label: 'Purchase',     color: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300' };
    case 'transfer_in':
      return { label: 'Transfer In',  color: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300' };
    case 'transfer_out':
      return { label: 'Transfer Out', color: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300' };
    case 'adjustment_in':
      return { label: 'Adj. In',      color: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300' };
    case 'adjustment_out':
      return { label: 'Adj. Out',     color: 'bg-orange-100 text-orange-800 dark:bg-orange-900/40 dark:text-orange-300' };
    case 'sales_issue':
      return { label: 'Sales Issue',  color: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300' };
    default:
      return { label: type,           color: 'bg-muted text-muted-foreground' };
  }
}

// ─── Shared primitives ────────────────────────────────────────────────────────

function StatTile({
  label,
  value,
  sub,
  icon: Icon,
  iconClass,
}: {
  label:      string;
  value:      ReactNode;
  sub?:       ReactNode;
  icon:       LucideIcon;
  iconClass?: string;
}) {
  return (
    <div className="flex flex-col gap-0.5 min-w-0">
      <div className="flex items-center gap-1.5 text-xs text-muted-foreground font-medium uppercase tracking-wide whitespace-nowrap">
        <Icon className={cn('size-3.5 flex-none', iconClass)} />
        <span className="truncate">{label}</span>
      </div>
      <span className="text-sm font-semibold text-foreground truncate">{value}</span>
      {sub && <span className="text-xs text-muted-foreground truncate">{sub}</span>}
    </div>
  );
}

function SectionTitle({ children }: { children: ReactNode }) {
  return <h3 className="text-sm font-semibold text-foreground mb-3">{children}</h3>;
}

function DetailRow({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div className="flex flex-col gap-0.5">
      <span className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</span>
      <span className="text-sm text-foreground">
        {value ?? <span className="text-muted-foreground">—</span>}
      </span>
    </div>
  );
}

function DetailGrid({ children }: { children: ReactNode }) {
  return <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">{children}</div>;
}

function EmptyState({
  icon: Icon,
  title,
  description,
  action,
}: {
  icon:         LucideIcon;
  title:        string;
  description:  string;
  action?:      ReactNode;
}) {
  return (
    <div className="flex flex-col items-center justify-center py-14 text-center gap-3">
      <div className="size-12 rounded-full bg-muted flex items-center justify-center">
        <Icon className="size-6 text-muted-foreground" />
      </div>
      <div>
        <p className="text-sm font-medium text-foreground">{title}</p>
        <p className="text-sm text-muted-foreground mt-1 max-w-xs">{description}</p>
      </div>
      {action}
    </div>
  );
}

function TabLoading() {
  return (
    <div className="flex items-center justify-center py-16">
      <Loader2 className="size-5 text-muted-foreground animate-spin" />
    </div>
  );
}

// ─── Smart Status Panel ───────────────────────────────────────────────────────

function SmartStatusPanel({
  material,
  latestCostEntry,
}: {
  material:         RawMaterial;
  latestCostEntry?: MaterialCostHistoryEntry | null;
}) {
  const { currency, locale } = useCompany();
  const cost      = latestCostEntry?.new_cost ?? material.manual_cost;
  const changePct = latestCostEntry?.change_pct;
  const updatedAt = latestCostEntry?.occurred_at;
  const source    = latestCostEntry?.source;

  return (
    <div className="grid grid-cols-2 gap-x-8 gap-y-3 sm:grid-cols-3 lg:flex lg:items-center lg:gap-10">
      {/* Material Cost — smart card */}
      <div className="flex flex-col gap-0.5 min-w-0">
        <div className="flex items-center gap-1.5 text-xs text-muted-foreground font-medium uppercase tracking-wide">
          <DollarSign className="size-3.5 flex-none text-blue-500" />
          <span>Material Cost</span>
          {source && (
            <span
              className={cn(
                'ml-1 rounded-full px-1.5 py-0 text-[10px] font-semibold uppercase',
                source === 'purchase_invoice'
                  ? 'bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-300'
                  : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
              )}
            >
              {source === 'purchase_invoice' ? 'PO' : 'Manual'}
            </span>
          )}
        </div>
        <div className="flex items-center gap-1.5">
          <span className="text-sm font-semibold text-foreground truncate">
            {formatCost(cost, material.unit?.name, currency, locale)}
          </span>
          {changePct != null && changePct !== 0 && (
            <span
              className={cn(
                'inline-flex items-center gap-0.5 text-xs font-medium',
                changePct > 0 ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400',
              )}
            >
              {changePct > 0 ? <ArrowUp className="size-3" /> : <ArrowDown className="size-3" />}
              {Math.abs(changePct).toFixed(1)}%
            </span>
          )}
        </div>
        {updatedAt && (
          <span className="text-xs text-muted-foreground">{formatDateTime(updatedAt)}</span>
        )}
      </div>

      <StatTile
        label="Unit of Measure"
        value={material.unit?.name ?? '—'}
        icon={Box}
        iconClass="text-amber-500"
      />
      <StatTile
        label="Category"
        value={material.category?.name ?? '—'}
        icon={Tag}
        iconClass="text-cyan-500"
      />
      <StatTile
        label="Min. Before Purchase"
        value={material.reorder_point != null ? String(material.reorder_point) : '—'}
        sub={material.reorder_point != null ? material.unit?.name : undefined}
        icon={TrendingDown}
        iconClass="text-orange-500"
      />
      <StatTile
        label="Min. Stock"
        value={material.minimum_stock != null ? String(material.minimum_stock) : '—'}
        sub={material.minimum_stock != null ? material.unit?.name : undefined}
        icon={Package}
        iconClass="text-green-500"
      />
      <StatTile
        label="Last Updated"
        value={formatDate(material.updated_at)}
        icon={Calendar}
        iconClass="text-slate-400"
      />
    </div>
  );
}

// ─── Tab: Overview ────────────────────────────────────────────────────────────

function OverviewTab({ material }: { material: RawMaterial }) {
  const { currency, locale } = useCompany();
  const hasDescription = Boolean(
    material.description || material.short_description || material.long_description,
  );
  const hasNotes = Boolean(material.internal_notes);

  return (
    <div className="space-y-7">
      <div>
        <SectionTitle>General Information</SectionTitle>
        <DetailGrid>
          <DetailRow label="Full Name" value={material.name} />
          <DetailRow
            label="SKU"
            value={
              <code className="font-mono text-xs bg-muted px-1.5 py-0.5 rounded">
                {material.sku}
              </code>
            }
          />
          <DetailRow label="Category"        value={material.category?.name} />
          <DetailRow label="Unit of Measure" value={material.unit?.name} />
          <DetailRow label="Product Type"    value="Raw Material" />
          <DetailRow label="Created" value={formatDate(material.created_at)} />
          <DetailRow label="Updated" value={formatDate(material.updated_at)} />
        </DetailGrid>
      </div>

      {hasDescription && (
        <>
          <Separator />
          <div>
            <SectionTitle>Description</SectionTitle>
            {(material.short_description ?? material.description) && (
              <p className="text-sm text-foreground mb-2">
                {material.short_description ?? material.description}
              </p>
            )}
            {material.long_description && (
              <p className="text-sm text-muted-foreground leading-relaxed">
                {material.long_description}
              </p>
            )}
          </div>
        </>
      )}

      <Separator />

      <div>
        <SectionTitle>Inventory Rules</SectionTitle>
        <DetailGrid>
          <DetailRow
            label="Minimum Stock"
            value={material.minimum_stock != null ? `${material.minimum_stock} ${material.unit?.name ?? ''}` : null}
          />
          <DetailRow
            label="Minimum Available Before Purchase"
            value={material.reorder_point != null ? `${material.reorder_point} ${material.unit?.name ?? ''}` : null}
          />
          <DetailRow
            label="Allow Negative Stock"
            value={material.allow_negative_stock ? 'Allowed' : 'Blocked'}
          />
          <DetailRow
            label="Preferred Warehouse"
            value={material.preferred_warehouse_id ?? null}
          />
        </DetailGrid>
      </div>

      <Separator />

      <div>
        <SectionTitle>Cost</SectionTitle>
        <DetailGrid>
          <DetailRow
            label="Current Cost"
            value={formatCost(material.material_cost, material.unit?.name, currency, locale)}
          />
          <DetailRow
            label="Last Updated"
            value={formatDate(material.updated_at)}
          />
          <DetailRow
            label="Source"
            value={
              material.cost_source === 'manual'
                ? 'Manual'
                : material.cost_source === 'purchase'
                  ? 'Purchase Invoice'
                  : '—'
            }
          />
        </DetailGrid>
      </div>

      <Separator />

      <div>
        <SectionTitle>Purchasing</SectionTitle>
        <DetailGrid>
          <DetailRow
            label="Lead Time"
            value={material.purchasing_lead_time_days != null
              ? `${material.purchasing_lead_time_days} days`
              : null}
          />
          <DetailRow
            label="Min. Order Qty"
            value={material.purchasing_minimum_order_qty != null
              ? `${material.purchasing_minimum_order_qty} ${material.unit?.name ?? ''}`
              : null}
          />
        </DetailGrid>
      </div>

      {hasNotes && (
        <>
          <Separator />
          <div>
            <SectionTitle>Internal Notes</SectionTitle>
            <p className="text-sm text-foreground leading-relaxed whitespace-pre-wrap">
              {material.internal_notes}
            </p>
          </div>
        </>
      )}
    </div>
  );
}

// ─── Tab: Inventory ───────────────────────────────────────────────────────────

function fmtQtyStr(n: number | null | undefined, unit?: string): string {
  if (n == null) return '—';
  const fmt = n.toLocaleString('en-EG', { minimumFractionDigits: 0, maximumFractionDigits: 3 });
  return unit ? `${fmt} ${unit}` : fmt;
}

function fmtCostStr(n: number | null | undefined, currency = 'EGP', locale = 'en-EG'): string {
  if (n == null) return '—';
  return formatMoney(n, currency, locale);
}

function InventoryTab({ material }: { material: RawMaterial }) {
  const { currency, locale } = useCompany();
  const avail    = stockStatusConfig(material.available_qty, material.allow_negative_stock);
  const unit     = material.unit?.name;
  const onHand   = material.on_hand_qty   ?? null;
  const reserved = material.reserved_qty  ?? null;
  const available = material.available_qty ?? null;
  const invValue = material.inventory_value ?? null;

  const metrics = [
    {
      label: 'Available',
      value: fmtQtyStr(available, unit),
      sub:   'free to use',
      highlight: available != null && available <= 0 ? 'border-red-200 dark:border-red-800' : undefined,
    },
    {
      label: 'Reserved',
      value: fmtQtyStr(reserved, unit),
      sub:   'allocated to orders',
    },
    {
      label: 'On Hand',
      value: fmtQtyStr(onHand, unit),
      sub:   'total in all warehouses',
    },
    {
      label: 'Inventory Value',
      value: fmtCostStr(invValue, currency, locale),
      sub:   'on hand × material cost',
    },
  ];

  return (
    <div className="space-y-6">
      {/* Status banner */}
      <div className={cn('flex items-center gap-3 rounded-lg border px-4 py-3', avail.badge)}>
        <span className={cn('size-2.5 rounded-full flex-none', avail.dot)} />
        <div>
          <p className={cn('text-sm font-semibold', avail.text)}>{avail.label}</p>
          <p className="text-xs text-muted-foreground mt-0.5">
            Aggregated across all warehouses.
          </p>
        </div>
      </div>

      {/* Inventory snapshot */}
      <div>
        <SectionTitle>Inventory Snapshot</SectionTitle>
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          {metrics.map((card) => (
            <div
              key={card.label}
              className={cn('rounded-lg border bg-card p-3', card.highlight)}
            >
              <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                {card.label}
              </p>
              <p className="mt-1 text-lg font-bold text-foreground tabular-nums">{card.value}</p>
              <p className="text-xs text-muted-foreground mt-0.5">{card.sub}</p>
            </div>
          ))}
        </div>
      </div>

      {/* Inventory rules */}
      <div>
        <SectionTitle>Inventory Rules</SectionTitle>
        <DetailGrid>
          <DetailRow
            label="Allow Negative Stock"
            value={material.allow_negative_stock ? 'Allowed' : 'Blocked'}
          />
          <DetailRow
            label="Minimum Stock Level"
            value={fmtQtyStr(material.minimum_stock, unit) === '—' ? null : fmtQtyStr(material.minimum_stock, unit)}
          />
          <DetailRow
            label="Minimum Available Before Purchase"
            value={fmtQtyStr(material.reorder_point, unit) === '—' ? null : fmtQtyStr(material.reorder_point, unit)}
          />
          <DetailRow
            label="Preferred Warehouse"
            value={material.preferred_warehouse_id ?? null}
          />
        </DetailGrid>
      </div>
    </div>
  );
}

// ─── Tab: Suppliers ───────────────────────────────────────────────────────────

function SuppliersTab({ material }: { material: RawMaterial }) {
  const { currency, locale } = useCompany();
  const suppliers = material.suppliers ?? [];

  if (suppliers.length === 0) {
    return (
      <EmptyState
        icon={Truck}
        title="No suppliers linked"
        description="Link suppliers to this raw material to track purchase prices, lead times, and preferred vendors."
      />
    );
  }

  return (
    <div className="rounded-md border overflow-hidden overflow-x-auto">
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b bg-muted/50">
            {['Supplier', 'Last Cost', 'MOQ', 'Default', 'Active'].map((h) => (
              <th
                key={h}
                className="px-3 py-2.5 text-left text-xs font-medium text-muted-foreground uppercase tracking-wide whitespace-nowrap"
              >
                {h}
              </th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y divide-border">
          {suppliers.map((s, i) => (
            <tr key={s.supplier_id ?? i} className="hover:bg-muted/30 transition-colors">
              <td className="px-3 py-2.5 text-sm font-medium">{s.supplier_id}</td>
              <td className="px-3 py-2.5 text-sm">
                {s.last_purchase_cost != null
                  ? formatMoney(s.last_purchase_cost, currency, locale)
                  : '—'}
              </td>
              <td className="px-3 py-2.5 text-sm">
                {s.minimum_order_qty != null ? s.minimum_order_qty : '—'}
              </td>
              <td className="px-3 py-2.5">
                {s.is_default && (
                  <Star className="size-4 text-amber-500 fill-current" />
                )}
              </td>
              <td className="px-3 py-2.5">
                <span
                  className={cn(
                    'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                    s.is_active
                      ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400'
                      : 'bg-muted text-muted-foreground',
                  )}
                >
                  {s.is_active ? 'Active' : 'Inactive'}
                </span>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

// ─── Tab: Price History ───────────────────────────────────────────────────────

function PriceHistoryTab({ materialId }: { materialId: string }) {
  const { currency, locale } = useCompany();
  const [page, setPage] = useState(1);
  const { data, isLoading } = useRawMaterialCostHistory(materialId, { page, per_page: 15 });

  const entries    = data?.data ?? [];
  const pagination = data?.pagination;

  const allCosts    = entries.map((e) => e.new_cost);
  const highestCost = allCosts.length ? Math.max(...allCosts) : null;
  const lowestCost  = allCosts.length ? Math.min(...allCosts) : null;
  const currentCost = entries[0]?.new_cost ?? null;

  if (isLoading) return <TabLoading />;

  return (
    <div className="space-y-4">
      {/* Summary cards */}
      <div className="grid grid-cols-3 gap-3">
        {[
          { label: 'Current Cost',  value: formatCost(currentCost,  undefined, currency, locale) },
          { label: 'Highest Cost',  value: formatCost(highestCost,  undefined, currency, locale) },
          { label: 'Lowest Cost',   value: formatCost(lowestCost,   undefined, currency, locale) },
        ].map((c) => (
          <div key={c.label} className="rounded-lg border bg-card p-3">
            <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
              {c.label}
            </p>
            <p className="mt-1 text-xl font-bold text-foreground">{c.value}</p>
          </div>
        ))}
      </div>

      {/* History table */}
      {entries.length === 0 ? (
        <EmptyState
          icon={TrendingUp}
          title="No cost history"
          description="Material cost changes will be recorded here automatically each time the cost is updated."
        />
      ) : (
        <>
          <div className="rounded-md border overflow-hidden overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b bg-muted/50">
                  {['Date', 'Previous Cost', 'New Cost', 'Change', 'Source', 'Changed By', 'Recipes'].map((h) => (
                    <th
                      key={h}
                      className="px-3 py-2.5 text-left text-xs font-medium text-muted-foreground uppercase tracking-wide whitespace-nowrap"
                    >
                      {h}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {entries.map((entry) => {
                  const isIncrease = (entry.change_pct ?? 0) > 0;
                  return (
                    <tr key={entry.id} className="hover:bg-muted/30 transition-colors">
                      <td className="px-3 py-2.5 whitespace-nowrap text-xs text-muted-foreground">
                        {formatDateTime(entry.occurred_at)}
                      </td>
                      <td className="px-3 py-2.5 whitespace-nowrap">
                        {formatCost(entry.previous_cost, undefined, currency, locale)}
                      </td>
                      <td className="px-3 py-2.5 whitespace-nowrap font-medium">
                        {formatCost(entry.new_cost, undefined, currency, locale)}
                      </td>
                      <td className="px-3 py-2.5 whitespace-nowrap">
                        {entry.change_pct != null ? (
                          <span
                            className={cn(
                              'inline-flex items-center gap-0.5 text-xs font-semibold',
                              isIncrease
                                ? 'text-red-600 dark:text-red-400'
                                : 'text-emerald-600 dark:text-emerald-400',
                            )}
                          >
                            {isIncrease ? <ArrowUp className="size-3" /> : <ArrowDown className="size-3" />}
                            {Math.abs(entry.change_pct).toFixed(1)}%
                          </span>
                        ) : (
                          <span className="text-muted-foreground">—</span>
                        )}
                      </td>
                      <td className="px-3 py-2.5 whitespace-nowrap">
                        <span
                          className={cn(
                            'rounded-full px-2 py-0.5 text-xs font-medium',
                            entry.source === 'purchase_invoice'
                              ? 'bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-300'
                              : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
                          )}
                        >
                          {entry.source === 'purchase_invoice' ? 'Purchase' : 'Manual'}
                        </span>
                      </td>
                      <td className="px-3 py-2.5 whitespace-nowrap text-xs text-muted-foreground">
                        {entry.updated_by ?? '—'}
                      </td>
                      <td className="px-3 py-2.5 whitespace-nowrap text-xs">
                        {entry.affected_recipe_count > 0 ? (
                          <span className="font-medium">{entry.affected_recipe_count} recipes</span>
                        ) : (
                          <span className="text-muted-foreground">—</span>
                        )}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>

          {pagination && pagination.last_page > 1 && (
            <PagePagination
              page={pagination.current_page}
              perPage={pagination.per_page}
              total={pagination.total}
              lastPage={pagination.last_page}
              onPageChange={setPage}
            />
          )}
        </>
      )}
    </div>
  );
}

// ─── Tab: Stock History ───────────────────────────────────────────────────────

function StockHistoryTab({
  material,
  onAddStock,
}: {
  material:   RawMaterial;
  onAddStock: () => void;
}) {
  const [page, setPage] = useState(1);
  const { data, isLoading } = useRawMaterialStockMovements(material.id, {
    page,
    per_page: 15,
    sort_by:  'created_at',
    sort_dir: 'desc',
  });

  const movements  = data?.items ?? [];
  const pagination = data?.meta;

  if (isLoading) return <TabLoading />;

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <p className="text-sm text-muted-foreground">
          {pagination ? `${pagination.total} movement${pagination.total === 1 ? '' : 's'} total` : ''}
        </p>
        <Button size="sm" className="gap-1.5" onClick={onAddStock}>
          <PackagePlus className="size-4" />
          Add Stock
        </Button>
      </div>

      {movements.length === 0 ? (
        <EmptyState
          icon={RotateCcw}
          title="No stock movements"
          description="Every stock in/out transaction will be recorded here for full traceability."
          action={
            <Button size="sm" onClick={onAddStock} className="gap-1.5 mt-1">
              <PackagePlus className="size-4" />
              Add First Stock Entry
            </Button>
          }
        />
      ) : (
        <>
          <div className="rounded-md border overflow-hidden overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b bg-muted/50">
                  {['Date', 'Warehouse', 'Type', 'Qty', 'Balance After', 'Notes'].map((h) => (
                    <th
                      key={h}
                      className="px-3 py-2.5 text-left text-xs font-medium text-muted-foreground uppercase tracking-wide whitespace-nowrap"
                    >
                      {h}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {movements.map((m) => {
                  const typeMeta = movementTypeMeta(m.movement_type);
                  const isPositive = ['purchase_receipt', 'adjustment_in', 'transfer_in'].includes(m.movement_type);
                  return (
                    <tr key={m.id} className="hover:bg-muted/30 transition-colors">
                      <td className="px-3 py-2.5 whitespace-nowrap text-xs text-muted-foreground">
                        {formatDateTime(m.created_at ?? m.movement_date)}
                      </td>
                      <td className="px-3 py-2.5 whitespace-nowrap text-xs">
                        {m.warehouse?.name ?? '—'}
                      </td>
                      <td className="px-3 py-2.5 whitespace-nowrap">
                        <span className={cn('rounded-full px-2 py-0.5 text-xs font-medium', typeMeta.color)}>
                          {typeMeta.label}
                        </span>
                      </td>
                      <td className="px-3 py-2.5 whitespace-nowrap">
                        <span
                          className={cn(
                            'font-semibold text-xs',
                            isPositive ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400',
                          )}
                        >
                          {isPositive ? '+' : '−'}{m.quantity}
                        </span>
                      </td>
                      <td className="px-3 py-2.5 whitespace-nowrap text-xs font-medium">
                        {m.balance_after}
                      </td>
                      <td className="px-3 py-2.5 text-xs text-muted-foreground max-w-[200px] truncate">
                        {m.notes ?? '—'}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>

          {pagination && pagination.last_page > 1 && (
            <PagePagination
              page={pagination.current_page}
              perPage={pagination.per_page}
              total={pagination.total}
              lastPage={pagination.last_page}
              onPageChange={setPage}
            />
          )}
        </>
      )}
    </div>
  );
}

// ─── Tab: Purchase History ────────────────────────────────────────────────────

function PurchaseHistoryTab() {
  return (
    <EmptyState
      icon={ShoppingCart}
      title="No purchase orders"
      description="Purchase orders for this raw material will appear here once created."
    />
  );
}

// ─── Tab: Manufacturing ───────────────────────────────────────────────────────

function ManufacturingTab() {
  return (
    <EmptyState
      icon={Factory}
      title="Not used in any recipe"
      description="This material will appear here once added to a manufacturing recipe as a component."
    />
  );
}

// ─── Tab: Analytics ───────────────────────────────────────────────────────────

function AnalyticsTab({ material }: { material: RawMaterial }) {
  const { currency, locale } = useCompany();
  const kpis: Array<{
    label:     string;
    value:     string;
    sub:       string;
    icon:      LucideIcon;
    iconClass: string;
  }> = [
    { label: 'Avg Purchase Cost',  value: '—', sub: 'last 12 months',         icon: DollarSign,  iconClass: 'text-blue-500'   },
    { label: 'Monthly Consumption', value: '—', sub: 'units/month avg',        icon: TrendingDown, iconClass: 'text-purple-500' },
    { label: 'Stock Coverage',     value: '—', sub: 'days at current rate',    icon: Calendar,    iconClass: 'text-green-500'  },
    {
      label: 'Unit Cost',
      value: formatCost(material.material_cost, undefined, currency, locale),
      sub:   'current material cost',
      icon:  BarChart2,
      iconClass: 'text-amber-500',
    },
    { label: 'Linked Suppliers',  value: '0', sub: 'active vendor sources',    icon: Truck,       iconClass: 'text-cyan-500'   },
    { label: 'Recipes Using',     value: '0', sub: 'manufacturing recipes',    icon: Factory,     iconClass: 'text-red-500'    },
    { label: 'Stock-Out Events',  value: '—', sub: 'last 90 days',             icon: TrendingDown, iconClass: 'text-orange-500' },
    { label: 'Cost Changes',      value: '—', sub: 'last 12 months',           icon: TrendingUp,  iconClass: 'text-indigo-500' },
    { label: 'Avg Lead Time',     value: '—', sub: 'days from PO to receipt',  icon: Calendar,    iconClass: 'text-teal-500'   },
  ];

  return (
    <div className="space-y-6">
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
        {kpis.map((card) => (
          <div key={card.label} className="rounded-lg border bg-card p-4">
            <div className="flex items-center gap-1.5 mb-2">
              <card.icon className={cn('size-4', card.iconClass)} />
              <p className="text-xs font-medium text-muted-foreground">{card.label}</p>
            </div>
            <p className="text-xl font-bold text-foreground">{card.value}</p>
            <p className="text-xs text-muted-foreground mt-0.5">{card.sub}</p>
          </div>
        ))}
      </div>

      <div className="rounded-lg border border-dashed border-muted-foreground/30 bg-muted/30 px-4 py-6 text-center">
        <BarChart2 className="mx-auto size-8 text-muted-foreground mb-2" />
        <p className="text-sm font-medium text-muted-foreground">Analytics Dashboard Coming Soon</p>
        <p className="text-xs text-muted-foreground mt-1">
          Charts and trend analysis will appear here once connected to the reporting module.
        </p>
      </div>
    </div>
  );
}

// ─── Main Component ───────────────────────────────────────────────────────────

export function RawMaterialDetailDrawer({
  material,
  open,
  onOpenChange,
  onEdit,
  initialTab = 'overview',
}: RawMaterialDetailDrawerProps) {
  const [activeTab,      setActiveTab]      = useState(initialTab);
  const [addStockOpen,   setAddStockOpen]   = useState(false);

  useEffect(() => {
    if (open) setActiveTab(initialTab);
  }, [open, initialTab]);

  // Fetch the latest cost entry for the Smart Status Panel
  const { data: latestCostData } = useRawMaterialCostHistory(
    material?.id,
    { per_page: 1 },
  );
  const latestCostEntry = latestCostData?.data?.[0] ?? null;

  if (!material) return null;

  const avail = stockStatusConfig(material.available_qty, material.allow_negative_stock);

  function openAddStock() {
    setAddStockOpen(true);
  }

  const tabs: TabItem[] = [
    {
      key:     'overview',
      label:   'Overview',
      content: <OverviewTab material={material} />,
    },
    {
      key:     'inventory',
      label:   'Inventory',
      content: <InventoryTab material={material} />,
    },
    {
      key:     'suppliers',
      label:   'Suppliers',
      content: <SuppliersTab material={material} />,
    },
    {
      key:     'price-history',
      label:   'Cost History',
      content: <PriceHistoryTab materialId={material.id} />,
    },
    {
      key:     'stock-history',
      label:   'Stock History',
      content: <StockHistoryTab material={material} onAddStock={openAddStock} />,
    },
    {
      key:     'purchase-history',
      label:   'Purchase History',
      content: <PurchaseHistoryTab />,
    },
    {
      key:     'manufacturing',
      label:   'Used In Recipes',
      content: <ManufacturingTab />,
    },
    {
      key:     'analytics',
      label:   'Analytics',
      content: <AnalyticsTab material={material} />,
    },
  ];

  return (
    <>
      <Sheet open={open} onOpenChange={onOpenChange}>
        <SheetContent
          side="right"
          className="flex flex-col gap-0 overflow-hidden p-0 sm:max-w-none w-full sm:w-[90vw] lg:w-[70vw]"
          style={{ maxWidth: 1400 }}
        >
          <SheetTitle className="sr-only">{material.name} — Raw Material Details</SheetTitle>

          {/* ── Drawer Header ── */}
          <div className="flex items-start gap-4 border-b px-6 py-5 flex-none pr-14">
            {/* Thumbnail */}
            <div className="size-16 shrink-0 rounded-lg border overflow-hidden bg-muted flex items-center justify-center">
              {getMediaUrl(material.image_url) ? (
                <img src={getMediaUrl(material.image_url)!} alt={material.name} className="size-full object-cover" />
              ) : (
                <Package className="size-8 text-muted-foreground" />
              )}
            </div>

            {/* Identity */}
            <div className="flex-1 min-w-0 pt-0.5">
              <div className="flex flex-wrap items-center gap-2 mb-1">
                <span
                  className={cn(
                    'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-medium',
                    avail.badge,
                    avail.text,
                  )}
                >
                  <span className={cn('size-1.5 rounded-full', avail.dot)} />
                  {avail.label}
                </span>

                {material.category && (
                  <Badge variant="outline" className="text-xs">
                    {material.category.name}
                  </Badge>
                )}


              </div>

              <h2 className="text-lg font-semibold text-foreground leading-tight">{material.name}</h2>

              <div className="flex flex-wrap items-center gap-2 mt-1 text-xs text-muted-foreground">
                <code className="font-mono bg-muted px-1.5 py-0.5 rounded">{material.sku}</code>
                {material.unit && (
                  <>
                    <span aria-hidden>·</span>
                    <span>{material.unit.name}</span>
                  </>
                )}
              </div>
            </div>

            {/* Actions */}
            <div className="shrink-0 pt-0.5 flex items-center gap-2">
              <Button
                size="sm"
                className="gap-1.5"
                onClick={openAddStock}
              >
                <Zap className="size-3.5" />
                Add Stock
              </Button>
              {onEdit && (
                <Button
                  size="sm"
                  variant="outline"
                  className="gap-1.5"
                  onClick={() => {
                    onOpenChange(false);
                    onEdit(material);
                  }}
                >
                  <Pencil className="size-3.5" />
                  Edit
                </Button>
              )}
            </div>
          </div>

          {/* ── Smart Status Panel ── */}
          <div className="border-b bg-muted/20 px-6 py-3 flex-none">
            <SmartStatusPanel material={material} latestCostEntry={latestCostEntry} />
          </div>

          {/* ── Tabs ── */}
          <div className="flex-1 min-h-0 overflow-hidden">
            <Tabs
              tabs={tabs}
              activeKey={activeTab}
              onTabChange={setActiveTab}
              className="h-full"
              contentClassName="overflow-y-auto py-6 px-6 min-h-0"
            />
          </div>
        </SheetContent>
      </Sheet>

      {/* Add Stock wizard — rendered outside Sheet to avoid stacking context issues */}
      <AddStockWizard
        material={material}
        open={addStockOpen}
        onOpenChange={setAddStockOpen}
        onSuccess={() => {
          // Stay on stock-history tab after adding stock
          setActiveTab('stock-history');
        }}
      />
    </>
  );
}
