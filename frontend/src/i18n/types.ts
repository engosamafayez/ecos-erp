/**
 * ECOS i18n TypeScript Augmentation
 *
 * Declares CustomTypeOptions so that useTranslation() is fully typed:
 * key autocomplete, unknown-key errors, and interpolation variable checks.
 *
 * RULE: Only include namespaces with real translation content here.
 *       Stub namespaces (empty JSON) must NOT be listed — omitting them
 *       lets i18next fall back to `string`, so t('key') still compiles.
 *       Once a stub is populated, add it here for strict key checking.
 *
 * English locale files are the canonical key source.
 * Arabic keys must mirror the English structure exactly.
 */
import 'i18next';

import type enAuth            from '@/i18n/locales/en/auth.json';
import type enBoms            from '@/i18n/locales/en/boms.json';
import type enBranches        from '@/i18n/locales/en/branches.json';
import type enCategories      from '@/i18n/locales/en/categories.json';
import type enChannels        from '@/i18n/locales/en/channels.json';
import type enCommon          from '@/i18n/locales/en/common.json';
import type enCompanies       from '@/i18n/locales/en/companies.json';
import type enCustomers       from '@/i18n/locales/en/customers.json';
import type enDashboard       from '@/i18n/locales/en/dashboard.json';
import type enFulfillments    from '@/i18n/locales/en/fulfillments.json';
import type enGoodsReceipts   from '@/i18n/locales/en/goods-receipts.json';
import type enInventoryControl from '@/i18n/locales/en/inventory-control.json';
import type enOperations      from '@/i18n/locales/en/operations.json';
import type enOrders          from '@/i18n/locales/en/orders.json';
import type enProducts        from '@/i18n/locales/en/products.json';
import type enPurchaseOrders  from '@/i18n/locales/en/purchase-orders.json';
import type enSettings        from '@/i18n/locales/en/settings.json';
import type enStockLedger     from '@/i18n/locales/en/stock-ledger.json';
import type enStockSync       from '@/i18n/locales/en/stock-sync.json';
import type enSuppliers       from '@/i18n/locales/en/suppliers.json';
import type enSyncLogs        from '@/i18n/locales/en/sync-logs.json';
import type enUnits           from '@/i18n/locales/en/units.json';
import type enWarehouses      from '@/i18n/locales/en/warehouses.json';

declare module 'i18next' {
  interface CustomTypeOptions {
    defaultNS: 'common';
    resources: {
      auth:              typeof enAuth;
      boms:              typeof enBoms;
      branches:          typeof enBranches;
      categories:        typeof enCategories;
      channels:          typeof enChannels;
      common:            typeof enCommon;
      companies:         typeof enCompanies;
      customers:         typeof enCustomers;
      dashboard:         typeof enDashboard;
      fulfillments:      typeof enFulfillments;
      'goods-receipts':  typeof enGoodsReceipts;
      'inventory-control': typeof enInventoryControl;
      operations:        typeof enOperations;
      orders:            typeof enOrders;
      products:          typeof enProducts;
      'purchase-orders': typeof enPurchaseOrders;
      settings:          typeof enSettings;
      'stock-ledger':    typeof enStockLedger;
      'stock-sync':      typeof enStockSync;
      suppliers:         typeof enSuppliers;
      'sync-logs':       typeof enSyncLogs;
      units:             typeof enUnits;
      warehouses:        typeof enWarehouses;
    };
  }
}
