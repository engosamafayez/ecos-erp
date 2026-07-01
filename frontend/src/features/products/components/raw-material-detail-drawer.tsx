import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import type { LucideIcon } from 'lucide-react';
import {
  BarChart2,
  Box,
  Calendar,
  DollarSign,
  Factory,
  Package,
  PackagePlus,
  Pencil,
  RotateCcw,
  ShoppingCart,
  Tag,
  TrendingDown,
  TrendingUp,
  Truck,
} from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { Sheet, SheetContent, SheetTitle } from '@/components/ui/sheet';
import { Tabs } from '@/components/ds/tabs';
import type { TabItem } from '@/components/ds/tabs';
import type { Product } from '@/features/products/types/product';
import { cn } from '@/lib/utils';

// ─── Types ────────────────────────────────────────────────────────────────────

type RawMaterialDetailDrawerProps = {
  material: Product | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onEdit?: (material: Product) => void;
  initialTab?: string;
};

// ─── Helpers ──────────────────────────────────────────────────────────────────

function availabilityConfig(status: Product['stock_status']) {
  switch (status) {
    case 'instock':
      return {
        label: 'Available',
        dot: 'bg-emerald-500',
        text: 'text-emerald-700 dark:text-emerald-400',
        badge: 'bg-emerald-50 dark:bg-emerald-950/40 border-emerald-200 dark:border-emerald-800',
      };
    case 'outofstock':
      return {
        label: 'Out of Stock',
        dot: 'bg-red-500',
        text: 'text-red-700 dark:text-red-400',
        badge: 'bg-red-50 dark:bg-red-950/40 border-red-200 dark:border-red-800',
      };
    case 'onbackorder':
      return {
        label: 'Low Stock',
        dot: 'bg-amber-500',
        text: 'text-amber-700 dark:text-amber-400',
        badge: 'bg-amber-50 dark:bg-amber-950/40 border-amber-200 dark:border-amber-800',
      };
    default:
      return {
        label: 'Unknown',
        dot: 'bg-muted-foreground',
        text: 'text-muted-foreground',
        badge: 'bg-muted border-border',
      };
  }
}

function formatPrice(price: number | null | undefined, unit?: string): string {
  if (price == null) return '—';
  const formatted = price.toLocaleString('en-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  return unit ? `${formatted} EGP / ${unit}` : `${formatted} EGP`;
}

function formatDate(iso: string | null | undefined): string {
  if (!iso) return '—';
  try {
    return new Date(iso).toLocaleDateString('en-EG', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
    });
  } catch {
    return '—';
  }
}

// ─── Shared primitives ────────────────────────────────────────────────────────

function StatTile({ label, value, icon: Icon, iconClass }: { label: string; value: string; icon: LucideIcon; iconClass?: string }) {
  return (
    <div className="flex flex-col gap-0.5 min-w-0">
      <div className="flex items-center gap-1.5 text-xs text-muted-foreground font-medium uppercase tracking-wide whitespace-nowrap">
        <Icon className={cn('size-3.5 flex-none', iconClass)} />
        <span className="truncate">{label}</span>
      </div>
      <span className="text-sm font-semibold text-foreground truncate">{value}</span>
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

function EmptyState({ icon: Icon, title, description }: { icon: LucideIcon; title: string; description: string }) {
  return (
    <div className="flex flex-col items-center justify-center py-14 text-center gap-3">
      <div className="size-12 rounded-full bg-muted flex items-center justify-center">
        <Icon className="size-6 text-muted-foreground" />
      </div>
      <div>
        <p className="text-sm font-medium text-foreground">{title}</p>
        <p className="text-sm text-muted-foreground mt-1 max-w-xs">{description}</p>
      </div>
    </div>
  );
}

function TableShell({ headers, colSpan, empty }: { headers: string[]; colSpan: number; empty: ReactNode }) {
  return (
    <div className="rounded-md border overflow-hidden">
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b bg-muted/50">
            {headers.map((h) => (
              <th
                key={h}
                className="px-3 py-2.5 text-left text-xs font-medium text-muted-foreground uppercase tracking-wide whitespace-nowrap"
              >
                {h}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          <tr>
            <td colSpan={colSpan}>{empty}</td>
          </tr>
        </tbody>
      </table>
    </div>
  );
}

// ─── Smart Status Panel ───────────────────────────────────────────────────────

function SmartStatusPanel({ material }: { material: Product }) {
  return (
    <div className="grid grid-cols-2 gap-x-8 gap-y-3 sm:grid-cols-3 lg:flex lg:items-center lg:gap-10">
      <StatTile
        label="Unit Price"
        value={formatPrice(material.regular_price, material.unit?.name)}
        icon={DollarSign}
        iconClass="text-blue-500"
      />
      <StatTile
        label="Sale Price"
        value={material.sale_price != null ? formatPrice(material.sale_price) : 'No Sale'}
        icon={TrendingDown}
        iconClass="text-purple-500"
      />
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
        label="Last Updated"
        value={formatDate(material.updated_at)}
        icon={Calendar}
        iconClass="text-slate-400"
      />
      <StatTile
        label="Current Stock"
        value="— Connect Inventory"
        icon={Package}
        iconClass="text-green-500"
      />
    </div>
  );
}

// ─── Tab: Overview ────────────────────────────────────────────────────────────

function OverviewTab({ material }: { material: Product }) {
  const hasDescription = Boolean(
    material.description || material.short_description || material.long_description,
  );

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
          <DetailRow label="Barcode" value={material.barcode} />
          <DetailRow label="Category" value={material.category?.name} />
          <DetailRow label="Unit of Measure" value={material.unit?.name} />
          <DetailRow label="Product Type" value="Raw Material" />
          <DetailRow
            label="Status"
            value={
              <span
                className={cn(
                  'inline-flex items-center gap-1.5 text-xs font-medium',
                  material.is_active ? 'text-emerald-600' : 'text-red-600',
                )}
              >
                <span
                  className={cn(
                    'size-1.5 rounded-full',
                    material.is_active ? 'bg-emerald-500' : 'bg-red-500',
                  )}
                />
                {material.is_active ? 'Active' : 'Inactive'}
              </span>
            }
          />
          <DetailRow label="Created" value={formatDate(material.created_at)} />
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
        <SectionTitle>Pricing</SectionTitle>
        <DetailGrid>
          <DetailRow
            label="Regular Price"
            value={formatPrice(material.regular_price, material.unit?.name)}
          />
          <DetailRow
            label="Sale Price"
            value={
              material.sale_price != null
                ? formatPrice(material.sale_price)
                : 'No sale price set'
            }
          />
          <DetailRow
            label="Price Discount"
            value={
              material.regular_price && material.sale_price
                ? `${(((material.regular_price - material.sale_price) / material.regular_price) * 100).toFixed(1)}% off`
                : '—'
            }
          />
        </DetailGrid>
      </div>

      <Separator />

      <div>
        <SectionTitle>Inventory Rules</SectionTitle>
        <DetailGrid>
          <DetailRow
            label="Reorder Point"
            value={<span className="text-muted-foreground text-xs italic">Not configured</span>}
          />
          <DetailRow
            label="Preferred Warehouse"
            value={<span className="text-muted-foreground text-xs italic">Not assigned</span>}
          />
          <DetailRow
            label="Preferred Supplier"
            value={<span className="text-muted-foreground text-xs italic">Not assigned</span>}
          />
          <DetailRow
            label="Allow Negative Stock"
            value={<span className="text-muted-foreground text-xs italic">System default</span>}
          />
        </DetailGrid>
      </div>
    </div>
  );
}

// ─── Tab: Inventory ───────────────────────────────────────────────────────────

function InventoryTab({ material }: { material: Product }) {
  const avail = availabilityConfig(material.stock_status);

  const metrics = [
    { label: 'Available Qty', value: '—', sub: 'units free to use' },
    { label: 'Reserved Qty', value: '—', sub: 'allocated to orders' },
    { label: 'On Hand Qty', value: '—', sub: 'total in warehouses' },
    { label: 'Incoming Qty', value: '—', sub: 'pending purchases' },
    { label: 'In Manufacturing', value: '—', sub: 'allocated to recipes' },
    { label: 'Inventory Value', value: '—', sub: 'at regular price' },
    { label: 'Last Count', value: '—', sub: 'inventory count date' },
    { label: 'Last Movement', value: '—', sub: 'stock in/out date' },
  ] as const;

  return (
    <div className="space-y-6">
      <div
        className={cn(
          'flex items-center gap-3 rounded-lg border px-4 py-3',
          avail.badge,
        )}
      >
        <span className={cn('size-2.5 rounded-full flex-none', avail.dot)} />
        <div>
          <p className={cn('text-sm font-semibold', avail.text)}>{avail.label}</p>
          <p className="text-xs text-muted-foreground mt-0.5">
            Stock status from the product record. Connect to the Inventory module for real-time quantities.
          </p>
        </div>
      </div>

      <div>
        <SectionTitle>Inventory Snapshot</SectionTitle>
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          {metrics.map((card) => (
            <div key={card.label} className="rounded-lg border bg-card p-3">
              <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                {card.label}
              </p>
              <p className="mt-1 text-xl font-bold text-foreground">{card.value}</p>
              <p className="text-xs text-muted-foreground mt-0.5">{card.sub}</p>
            </div>
          ))}
        </div>
      </div>

      <div className="rounded-lg border border-dashed border-muted-foreground/30 bg-muted/30 px-4 py-6 text-center">
        <Package className="mx-auto size-8 text-muted-foreground mb-2" />
        <p className="text-sm font-medium text-muted-foreground">Inventory Module Integration Pending</p>
        <p className="text-xs text-muted-foreground mt-1">
          Real-time quantities will appear here once the Inventory module API is connected.
        </p>
      </div>
    </div>
  );
}

// ─── Tab: Suppliers ───────────────────────────────────────────────────────────

function SuppliersTab() {
  return (
    <div className="space-y-4">
      <TableShell
        headers={['Supplier', 'Supplier SKU', 'Last Price', 'MOQ', 'Lead Time', 'Preferred', 'Last Purchase']}
        colSpan={7}
        empty={
          <EmptyState
            icon={Truck}
            title="No suppliers linked"
            description="Link suppliers to this raw material to track purchase prices, lead times, and preferred vendors."
          />
        }
      />
      <Button variant="outline" size="sm" className="gap-1.5">
        <PackagePlus className="size-4" />
        Add Supplier
      </Button>
    </div>
  );
}

// ─── Tab: Price History ───────────────────────────────────────────────────────

function PriceHistoryTab({ material }: { material: Product }) {
  return (
    <div className="space-y-4">
      <div className="grid grid-cols-3 gap-3">
        {[
          { label: 'Current Price', value: formatPrice(material.regular_price) },
          { label: 'Highest Price', value: '—' },
          { label: 'Lowest Price', value: '—' },
        ].map((c) => (
          <div key={c.label} className="rounded-lg border bg-card p-3">
            <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
              {c.label}
            </p>
            <p className="mt-1 text-xl font-bold text-foreground">{c.value}</p>
          </div>
        ))}
      </div>

      <TableShell
        headers={['Date', 'Previous Price', 'New Price', 'Change', 'Changed By', 'Reason']}
        colSpan={6}
        empty={
          <EmptyState
            icon={TrendingUp}
            title="No price history"
            description="Price changes will be recorded here automatically each time the price is updated."
          />
        }
      />
    </div>
  );
}

// ─── Tab: Stock History ───────────────────────────────────────────────────────

function StockHistoryTab() {
  return (
    <TableShell
      headers={['Date', 'Warehouse', 'Movement Type', 'Quantity', 'Balance After', 'Reference', 'Created By']}
      colSpan={7}
      empty={
        <EmptyState
          icon={RotateCcw}
          title="No stock movements"
          description="Every stock in/out transaction will be recorded here for full traceability."
        />
      }
    />
  );
}

// ─── Tab: Purchase History ────────────────────────────────────────────────────

function PurchaseHistoryTab() {
  return (
    <TableShell
      headers={['PO Number', 'Supplier', 'Date', 'Qty', 'Unit Price', 'Warehouse', 'Status', 'Received Qty']}
      colSpan={8}
      empty={
        <EmptyState
          icon={ShoppingCart}
          title="No purchase orders"
          description="Purchase orders for this raw material will appear here once created."
        />
      }
    />
  );
}

// ─── Tab: Manufacturing ───────────────────────────────────────────────────────

function ManufacturingTab() {
  return (
    <TableShell
      headers={['Recipe', 'Finished Product', 'Required Qty', 'Unit', 'Component Role', 'Waste %']}
      colSpan={6}
      empty={
        <EmptyState
          icon={Factory}
          title="Not used in any recipe"
          description="This material will appear here once added to a manufacturing recipe as a component."
        />
      }
    />
  );
}

// ─── Tab: Analytics ───────────────────────────────────────────────────────────

function AnalyticsTab({ material }: { material: Product }) {
  const kpis: Array<{ label: string; value: string; sub: string; icon: LucideIcon; iconClass: string }> = [
    { label: 'Avg Purchase Price', value: '—', sub: 'last 12 months', icon: DollarSign, iconClass: 'text-blue-500' },
    { label: 'Monthly Consumption', value: '—', sub: 'units/month avg', icon: TrendingDown, iconClass: 'text-purple-500' },
    { label: 'Stock Coverage', value: '—', sub: 'days at current rate', icon: Calendar, iconClass: 'text-green-500' },
    {
      label: 'Unit Inventory Value',
      value: material.regular_price != null ? formatPrice(material.regular_price) : '—',
      sub: 'at regular price',
      icon: BarChart2,
      iconClass: 'text-amber-500',
    },
    { label: 'Linked Suppliers', value: '0', sub: 'active vendor sources', icon: Truck, iconClass: 'text-cyan-500' },
    { label: 'Recipes Using', value: '0', sub: 'manufacturing recipes', icon: Factory, iconClass: 'text-red-500' },
    { label: 'Stock-Out Events', value: '—', sub: 'last 90 days', icon: TrendingDown, iconClass: 'text-orange-500' },
    { label: 'Price Changes', value: '—', sub: 'last 12 months', icon: TrendingUp, iconClass: 'text-indigo-500' },
    { label: 'Avg Lead Time', value: '—', sub: 'days from PO to receipt', icon: Calendar, iconClass: 'text-teal-500' },
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
  const [activeTab, setActiveTab] = useState(initialTab);

  useEffect(() => {
    if (open) setActiveTab(initialTab);
  }, [open, initialTab]);

  if (!material) return null;

  const avail = availabilityConfig(material.stock_status);

  const tabs: TabItem[] = [
    { key: 'overview', label: 'Overview', content: <OverviewTab material={material} /> },
    { key: 'inventory', label: 'Inventory', content: <InventoryTab material={material} /> },
    { key: 'suppliers', label: 'Suppliers', content: <SuppliersTab /> },
    { key: 'price-history', label: 'Price History', content: <PriceHistoryTab material={material} /> },
    { key: 'stock-history', label: 'Stock History', content: <StockHistoryTab /> },
    { key: 'purchase-history', label: 'Purchase History', content: <PurchaseHistoryTab /> },
    { key: 'manufacturing', label: 'Used In Recipes', content: <ManufacturingTab /> },
    { key: 'analytics', label: 'Analytics', content: <AnalyticsTab material={material} /> },
  ];

  return (
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
            {material.image_url ? (
              <img src={material.image_url} alt={material.name} className="size-full object-cover" />
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

              {!material.is_active && (
                <Badge variant="secondary" className="text-xs">
                  Inactive
                </Badge>
              )}
            </div>

            <h2 className="text-lg font-semibold text-foreground leading-tight">{material.name}</h2>

            <div className="flex flex-wrap items-center gap-2 mt-1 text-xs text-muted-foreground">
              <code className="font-mono bg-muted px-1.5 py-0.5 rounded">{material.sku}</code>
              {material.barcode && (
                <>
                  <span aria-hidden>·</span>
                  <span>{material.barcode}</span>
                </>
              )}
              {material.unit && (
                <>
                  <span aria-hidden>·</span>
                  <span>{material.unit.name}</span>
                </>
              )}
            </div>
          </div>

          {/* Actions */}
          {onEdit && (
            <div className="shrink-0 pt-0.5">
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
            </div>
          )}
        </div>

        {/* ── Smart Status Panel ── */}
        <div className="border-b bg-muted/20 px-6 py-3 flex-none">
          <SmartStatusPanel material={material} />
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
  );
}
