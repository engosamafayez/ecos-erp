import i18n from 'i18next';
import LanguageDetector from 'i18next-browser-languagedetector';
import { initReactI18next } from 'react-i18next';

import arInventoryControl from '@/i18n/locales/ar/inventory-control.json';
import arOperations from '@/i18n/locales/ar/operations.json';
import arBoms from '@/i18n/locales/ar/boms.json';
import arSyncLogs from '@/i18n/locales/ar/sync-logs.json';
import arAuth from '@/i18n/locales/ar/auth.json';
import arBranches from '@/i18n/locales/ar/branches.json';
import arCategories from '@/i18n/locales/ar/categories.json';
import arChannels from '@/i18n/locales/ar/channels.json';
import arCommon from '@/i18n/locales/ar/common.json';
import arCustomers from '@/i18n/locales/ar/customers.json';
import arDashboard from '@/i18n/locales/ar/dashboard.json';
import arCompanies from '@/i18n/locales/ar/companies.json';
import arFulfillments from '@/i18n/locales/ar/fulfillments.json';
import arGoodsReceipts from '@/i18n/locales/ar/goods-receipts.json';
import arOrders from '@/i18n/locales/ar/orders.json';
import arProducts from '@/i18n/locales/ar/products.json';
import arPurchaseOrders from '@/i18n/locales/ar/purchase-orders.json';
import arSettings from '@/i18n/locales/ar/settings.json';
import arStockLedger from '@/i18n/locales/ar/stock-ledger.json';
import arStockSync from '@/i18n/locales/ar/stock-sync.json';
import arSuppliers from '@/i18n/locales/ar/suppliers.json';
import arUnits from '@/i18n/locales/ar/units.json';
import arWarehouses from '@/i18n/locales/ar/warehouses.json';
import enInventoryControl from '@/i18n/locales/en/inventory-control.json';
import enOperations from '@/i18n/locales/en/operations.json';
import enBoms from '@/i18n/locales/en/boms.json';
import enSyncLogs from '@/i18n/locales/en/sync-logs.json';
import enAuth from '@/i18n/locales/en/auth.json';
import enBranches from '@/i18n/locales/en/branches.json';
import enCategories from '@/i18n/locales/en/categories.json';
import enChannels from '@/i18n/locales/en/channels.json';
import enCommon from '@/i18n/locales/en/common.json';
import enCustomers from '@/i18n/locales/en/customers.json';
import enDashboard from '@/i18n/locales/en/dashboard.json';
import enCompanies from '@/i18n/locales/en/companies.json';
import enFulfillments from '@/i18n/locales/en/fulfillments.json';
import enGoodsReceipts from '@/i18n/locales/en/goods-receipts.json';
import enOrders from '@/i18n/locales/en/orders.json';
import enProducts from '@/i18n/locales/en/products.json';
import enPurchaseOrders from '@/i18n/locales/en/purchase-orders.json';
import enSettings from '@/i18n/locales/en/settings.json';
import enStockLedger from '@/i18n/locales/en/stock-ledger.json';
import enStockSync from '@/i18n/locales/en/stock-sync.json';
import enSuppliers from '@/i18n/locales/en/suppliers.json';
import enUnits from '@/i18n/locales/en/units.json';
import enWarehouses from '@/i18n/locales/en/warehouses.json';

void i18n
  .use(LanguageDetector)
  .use(initReactI18next)
  .init({
    resources: {
      en: {
        common: enCommon,
        auth: enAuth,
        dashboard: enDashboard,
        companies: enCompanies,
        branches: enBranches,
        warehouses: enWarehouses,
        categories: enCategories,
        units: enUnits,
        products: enProducts,
        customers: enCustomers,
        suppliers: enSuppliers,
        'purchase-orders': enPurchaseOrders,
        'goods-receipts': enGoodsReceipts,
        channels: enChannels,
        orders: enOrders,
        fulfillments: enFulfillments,
        'stock-ledger': enStockLedger,
        'stock-sync': enStockSync,
        boms: enBoms,
        'sync-logs': enSyncLogs,
        'inventory-control': enInventoryControl,
        operations: enOperations,
        settings: enSettings,
      },
      ar: {
        common: arCommon,
        auth: arAuth,
        dashboard: arDashboard,
        companies: arCompanies,
        branches: arBranches,
        warehouses: arWarehouses,
        categories: arCategories,
        units: arUnits,
        products: arProducts,
        customers: arCustomers,
        suppliers: arSuppliers,
        'purchase-orders': arPurchaseOrders,
        'goods-receipts': arGoodsReceipts,
        channels: arChannels,
        orders: arOrders,
        fulfillments: arFulfillments,
        'stock-ledger': arStockLedger,
        'stock-sync': arStockSync,
        boms: arBoms,
        'sync-logs': arSyncLogs,
        'inventory-control': arInventoryControl,
        operations: arOperations,
        settings: arSettings,
      },
    },
    defaultNS: 'common',
    fallbackLng: 'en',
    supportedLngs: ['en', 'ar'],
    detection: {
      order: ['localStorage'],
      lookupLocalStorage: 'language',
      caches: ['localStorage'],
    },
    interpolation: { escapeValue: false },
  });

export default i18n;
