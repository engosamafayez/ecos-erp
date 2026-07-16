import type { ColumnMeta } from '@/components/data-grid/types';

// Enterprise column layout:
//   Always visible:  Order #, Actions ⋮
//   Visible default: Customer, Status, Address, Zone, Location,
//                    Confirmation (Reservation inline), Items, Payment,
//                    Payment Proof, Total, Customer Notes,
//                    Created, Sales Rep, Driver, Store, Attempts, Updated
//   Hidden:          Delivery Window
//   Removed:         Carrier (shipping_company), Reservation (reservation_status)

export const ORDER_COLUMN_META: ColumnMeta[] = [
  { key: 'checkbox',            label: '',                  alwaysVisible: true },
  { key: 'order_number',        label: 'Order #',           alwaysVisible: true },
  { key: 'customer',            label: 'Customer',          defaultVisible: true },
  { key: 'status',              label: 'Status',            defaultVisible: true },
  { key: 'address',             label: 'Delivery Address',  defaultVisible: true },
  { key: 'zone',                label: 'Zone',              defaultVisible: true },
  { key: 'location',            label: 'GPS Location',      defaultVisible: true },
  { key: 'customer_confirmed',  label: 'Confirmation',      defaultVisible: true },
  { key: 'products_count',      label: 'Items',             defaultVisible: true },
  { key: 'payment_method',      label: 'Payment',           defaultVisible: true },
  { key: 'payment_proof',       label: 'Payment Proof',     defaultVisible: true },
  { key: 'total',               label: 'Total',             defaultVisible: true },
  { key: 'customer_note',       label: 'Customer Notes',    defaultVisible: true },
  { key: 'created_at',          label: 'Created',           defaultVisible: true },
  { key: 'sales_rep',           label: 'Sales Rep',         defaultVisible: true },
  { key: 'delivery_driver',     label: 'Driver',            defaultVisible: true },
  { key: 'store',               label: 'Store',             defaultVisible: true },
  { key: 'shipping_attempts',   label: 'Attempts',          defaultVisible: true },
  { key: 'updated_at',          label: 'Updated',           defaultVisible: true },
  { key: 'actions',             label: '',                  alwaysVisible: true },
  { key: 'delivery_window',     label: 'Delivery Window',   defaultVisible: false },
];
