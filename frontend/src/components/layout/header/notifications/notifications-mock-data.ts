export type NotificationCategory =
  | 'orders'
  | 'inventory'
  | 'finance'
  | 'system'
  | 'integrations';

export type Notification = {
  id: string;
  category: NotificationCategory;
  title: string;
  body: string;
  time: string;
  read: boolean;
};

export const MOCK_NOTIFICATIONS: Notification[] = [
  {
    id: 'n1',
    category: 'orders',
    title: 'New Order Received',
    body: 'Order #1042 placed by Ahmed Al Rashidi — AED 480.00',
    time: '2 min ago',
    read: false,
  },
  {
    id: 'n2',
    category: 'orders',
    title: 'Order Shipped',
    body: 'Order #1038 dispatched via Aramex. Tracking: 1Z9999W99999999',
    time: '18 min ago',
    read: false,
  },
  {
    id: 'n3',
    category: 'inventory',
    title: 'Low Stock Alert',
    body: 'Standing Desk 160cm has only 2 units remaining at Main Warehouse',
    time: '1 hr ago',
    read: false,
  },
  {
    id: 'n4',
    category: 'finance',
    title: 'Invoice Overdue',
    body: 'Invoice INV-2024-089 is 7 days overdue — AED 3,200.00',
    time: '3 hr ago',
    read: false,
  },
  {
    id: 'n5',
    category: 'integrations',
    title: 'WooCommerce Sync Complete',
    body: '143 products and 12 orders synced successfully from ECOS Retail store',
    time: '2 hr ago',
    read: true,
  },
  {
    id: 'n6',
    category: 'system',
    title: 'System Update Available',
    body: 'ECOS ERP v2.1.4 is ready — includes performance improvements',
    time: '5 hr ago',
    read: true,
  },
  {
    id: 'n7',
    category: 'orders',
    title: 'Return Request',
    body: 'Sara Al Mansoori submitted a return request for Order #1031',
    time: '1 day ago',
    read: true,
  },
  {
    id: 'n8',
    category: 'inventory',
    title: 'Stock Received',
    body: '50 units of Office Chair Pro X added to Main Warehouse via GR-088',
    time: '1 day ago',
    read: true,
  },
  {
    id: 'n9',
    category: 'integrations',
    title: 'Sync Error',
    body: '3 products failed to sync from ECOS Logistics channel — review required',
    time: '2 days ago',
    read: true,
  },
];

export const CATEGORY_LABELS: Record<NotificationCategory, string> = {
  orders: 'Orders',
  inventory: 'Inventory',
  finance: 'Finance',
  system: 'System',
  integrations: 'Integrations',
};

export const ALL_CATEGORIES = Object.keys(CATEGORY_LABELS) as NotificationCategory[];
