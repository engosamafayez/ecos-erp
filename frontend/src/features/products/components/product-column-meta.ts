import type { ColumnMeta } from '@/components/data-grid/types';

/**
 * Canonical column metadata for the Products workspace.
 * Drives both useColumnVisibility (persistence) and ColumnVisibilityMenu (toggle UI).
 * Columns marked alwaysVisible are excluded from the toggle menu automatically.
 */
export const PRODUCT_COLUMN_META: ColumnMeta[] = [
  { key: 'image',         label: 'Image',       alwaysVisible: false, defaultVisible: false },
  { key: 'sku',           label: 'SKU',          alwaysVisible: true },
  { key: 'name',          label: 'Name',         alwaysVisible: true },
  { key: 'type',          label: 'Type',         defaultVisible: true },
  { key: 'category',      label: 'Category',     defaultVisible: true },
  { key: 'channels',      label: 'Channels',     defaultVisible: true },
  { key: 'regular_price', label: 'Price',        defaultVisible: true },
  { key: 'sale_price',    label: 'Sale Price',   defaultVisible: false },
  { key: 'stock_status',  label: 'Stock',        defaultVisible: true },
  { key: 'is_published',  label: 'Published',    defaultVisible: true },
  { key: 'sync_status',   label: 'Sync',         defaultVisible: false },
  { key: 'is_active',     label: 'Status',       defaultVisible: true },
  { key: 'updated_at',    label: 'Updated',      defaultVisible: true },
  { key: 'actions',       label: '',             alwaysVisible: true },
];
