import { useState } from 'react';
import { ChevronDown, ChevronUp, SlidersHorizontal, X } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { CategorySelect } from '@/features/products/components/category-select';
import { useChannelOptions } from '@/features/channels/hooks/use-channel-options';
import { useWarehouseOptions } from '@/features/products/hooks/use-warehouse-options';
import type { ProductStatusFilter, ProductType } from '@/features/products/types/product';
import { cn } from '@/lib/utils';

export type ProductFilters = {
  category_id: string | null;
  warehouse_id: string | null;
  channel_id: string | null;
  status: ProductStatusFilter;
  product_type: ProductType | null;
  is_published: boolean | null;
  low_stock: boolean;
  out_of_stock: boolean;
  has_images: boolean | null;
  not_synced: boolean;
};

export const DEFAULT_FILTERS: ProductFilters = {
  category_id: null,
  warehouse_id: null,
  channel_id: null,
  status: 'all',
  product_type: null,
  is_published: null,
  low_stock: false,
  out_of_stock: false,
  has_images: null,
  not_synced: false,
};

type ProductFilterBarProps = {
  filters: ProductFilters;
  onChange: (f: Partial<ProductFilters>) => void;
  onClear: () => void;
};

function hasActiveFilters(f: ProductFilters): boolean {
  return (
    f.category_id !== null ||
    f.warehouse_id !== null ||
    f.channel_id !== null ||
    f.status !== 'all' ||
    f.product_type !== null ||
    f.is_published !== null ||
    f.low_stock ||
    f.out_of_stock ||
    f.has_images !== null ||
    f.not_synced
  );
}

type NativeSelectProps = {
  label: string;
  value: string;
  onChange: (v: string) => void;
  children: React.ReactNode;
};

function FilterSelect({ label, value, onChange, children }: NativeSelectProps) {
  return (
    <div className="flex flex-col gap-1">
      <label className="text-xs font-medium text-muted-foreground">{label}</label>
      <select
        value={value}
        onChange={(e) => onChange(e.target.value)}
        className={cn(
          'h-8 rounded-md border border-input bg-background px-2.5 text-sm shadow-xs',
          'focus:outline-none focus:ring-2 focus:ring-ring/50',
          'dark:bg-input/30',
        )}
      >
        {children}
      </select>
    </div>
  );
}

type ToggleChipProps = {
  label: string;
  active: boolean;
  onClick: () => void;
};

function ToggleChip({ label, active, onClick }: ToggleChipProps) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={active}
      className={cn(
        'inline-flex h-8 items-center rounded-full border px-3 text-xs font-medium transition-colors',
        active
          ? 'border-primary bg-primary text-primary-foreground'
          : 'border-border bg-background text-foreground hover:bg-accent',
      )}
    >
      {label}
    </button>
  );
}

export function ProductFilterBar({ filters, onChange, onClear }: ProductFilterBarProps) {
  const [showMore, setShowMore] = useState(false);
  const { data: channelOptions = [] } = useChannelOptions();
  const { data: warehouseOptions = [] } = useWarehouseOptions();
  const active = hasActiveFilters(filters);

  return (
    <div className="rounded-lg border bg-card p-3">
      {/* Primary row — always visible */}
      <div className="flex flex-wrap items-end gap-3">
        <div className="flex flex-col gap-1">
          <label className="text-xs font-medium text-muted-foreground">Category</label>
          <CategorySelect
            value={filters.category_id}
            onChange={(v) => onChange({ category_id: v })}
            placeholder="All categories"
            className="h-8 text-sm"
          />
        </div>

        <FilterSelect
          label="Warehouse"
          value={filters.warehouse_id ?? ''}
          onChange={(v) => onChange({ warehouse_id: v || null })}
        >
          <option value="">All warehouses</option>
          {warehouseOptions.map((o) => (
            <option key={o.value} value={o.value}>{o.label}</option>
          ))}
        </FilterSelect>

        <FilterSelect
          label="Channel"
          value={filters.channel_id ?? ''}
          onChange={(v) => onChange({ channel_id: v || null })}
        >
          <option value="">All channels</option>
          {channelOptions.map((o) => (
            <option key={o.value} value={o.value}>{o.label}</option>
          ))}
        </FilterSelect>

        <FilterSelect
          label="Status"
          value={filters.status}
          onChange={(v) => onChange({ status: v as ProductStatusFilter })}
        >
          <option value="all">All statuses</option>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </FilterSelect>

        <FilterSelect
          label="Type"
          value={filters.product_type ?? ''}
          onChange={(v) => onChange({ product_type: (v || null) as ProductType | null })}
        >
          <option value="">All types</option>
          <option value="finished_good">Finished Good</option>
          <option value="raw_material">Raw Material</option>
        </FilterSelect>

        {/* Toggle chips */}
        <div className="flex flex-col gap-1">
          <span className="text-xs font-medium text-muted-foreground">Quick filters</span>
          <div className="flex flex-wrap items-center gap-1.5">
            <ToggleChip
              label="Low Stock"
              active={filters.low_stock}
              onClick={() => onChange({ low_stock: !filters.low_stock })}
            />
            <ToggleChip
              label="Out of Stock"
              active={filters.out_of_stock}
              onClick={() => onChange({ out_of_stock: !filters.out_of_stock })}
            />
          </div>
        </div>

        {/* Action buttons */}
        <div className="ms-auto flex items-end gap-2">
          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={() => setShowMore((v) => !v)}
          >
            <SlidersHorizontal className="size-3.5" />
            More
            {showMore ? <ChevronUp className="size-3" /> : <ChevronDown className="size-3" />}
          </Button>
          {active ? (
            <Button type="button" variant="ghost" size="sm" onClick={onClear}>
              <X className="size-3.5" />
              Clear
            </Button>
          ) : null}
        </div>
      </div>

      {/* Secondary row — "More Filters" */}
      {showMore ? (
        <div className="mt-3 flex flex-wrap items-end gap-3 border-t pt-3">
          <FilterSelect
            label="Published"
            value={filters.is_published === null ? '' : String(filters.is_published)}
            onChange={(v) =>
              onChange({ is_published: v === '' ? null : v === 'true' })
            }
          >
            <option value="">All</option>
            <option value="true">Published</option>
            <option value="false">Unpublished</option>
          </FilterSelect>

          <FilterSelect
            label="Has Images"
            value={filters.has_images === null ? '' : String(filters.has_images)}
            onChange={(v) =>
              onChange({ has_images: v === '' ? null : v === 'true' })
            }
          >
            <option value="">All</option>
            <option value="true">With images</option>
            <option value="false">Without images</option>
          </FilterSelect>
        </div>
      ) : null}
    </div>
  );
}
