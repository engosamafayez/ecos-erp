import type { ColumnMeta } from '@/components/data-grid/types';

export const ORDER_COLUMN_META: ColumnMeta[] = [
  { key: 'checkbox',          label: '',        alwaysVisible: true },
  { key: 'order_number',      label: 'Order #', alwaysVisible: true },
  { key: 'store',             label: 'Store',   defaultVisible: true },
  { key: 'customer',          label: 'Customer', defaultVisible: true },
  { key: 'phone',             label: 'Phone',   defaultVisible: true },
  { key: 'status',            label: 'Status',  defaultVisible: true },
  { key: 'total',             label: 'Total',   defaultVisible: true },
  { key: 'payment_method',    label: 'Payment', defaultVisible: true },
  { key: 'products_count',    label: 'Items',   defaultVisible: true },
  { key: 'address',           label: 'Address', defaultVisible: true },
  { key: 'shipping_attempts', label: 'Attempts', defaultVisible: true },
  { key: 'shipping_company',  label: 'Carrier', defaultVisible: true },
  { key: 'created_at',        label: 'Created', defaultVisible: true },
  { key: 'updated_at',        label: 'Updated', defaultVisible: false },
  { key: 'actions',           label: '',        alwaysVisible: true },
];
