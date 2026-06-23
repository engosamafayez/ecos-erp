/**
 * Centralized route path constants. Reference these instead of string literals.
 */
export const ROUTES = {
  home: '/',
  login: '/login',
  dashboard: '/dashboard',
  companies: '/companies',
  branches: '/branches',
  products: '/products',
  rawMaterials: '/raw-materials',
  warehouses: '/warehouses',
  categories: '/categories',
  units: '/units',
  suppliers: '/suppliers',
  purchaseOrders: '/purchase-orders',
  purchaseOrdersNew: '/purchase-orders/new',
  goodsReceipts: '/goods-receipts',
  goodsReceiptsNew: '/goods-receipts/new',
  stockLedger: '/stock-ledger',
  customers: '/customers',
  channels: '/channels',
  productMappings: '/product-mappings',
  orders: '/orders',
  ordersNew: '/orders/new',
  fulfillments: '/fulfillments',
  fulfillmentsNew: '/fulfillments/new',
  stockSyncLogs: '/stock-sync-logs',
  inventory: '/inventory',
  purchasing: '/purchasing',
  sales: '/sales',
  accounting: '/accounting',
  crm: '/crm',
  hr: '/hr',
  reports: '/reports',
  settings: '/settings',
} as const;

export type RoutePath = (typeof ROUTES)[keyof typeof ROUTES];
