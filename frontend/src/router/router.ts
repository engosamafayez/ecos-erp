import { createBrowserRouter } from 'react-router-dom';

import { ComingSoonPage } from '@/components/common/coming-soon-page';
import { AppShell } from '@/components/layout/app-shell';
import { LoginPage } from '@/features/auth/pages/login-page';
import { BranchesPage } from '@/features/branches/pages/branches-page';
import { CategoriesPage } from '@/features/categories/pages/categories-page';
import { ChannelsPage } from '@/features/channels/pages/channels-page';
import { CompaniesPage } from '@/features/companies/pages/companies-page';
import { DashboardPage } from '@/features/dashboard/pages/dashboard-page';
import { HomePage } from '@/features/home/pages/home-page';
import { InventoryProductsWorkspace } from '@/features/inventory/pages/inventory-products-workspace';
import { OrganizationWorkspace } from '@/features/organization/pages/organization-workspace';
import { ProductsPage } from '@/features/products/pages/products-page';
import { RawMaterialsPage } from '@/features/products/pages/raw-materials-page';
import { UnitsPage } from '@/features/units/pages/units-page';
import { WarehousesPage } from '@/features/warehouses/pages/warehouses-page';
import { CreatePurchaseOrderPage } from '@/features/purchase-orders/pages/create-purchase-order-page';
import { EditPurchaseOrderPage } from '@/features/purchase-orders/pages/edit-purchase-order-page';
import { PurchaseOrdersPage } from '@/features/purchase-orders/pages/purchase-orders-page';
import { ViewPurchaseOrderPage } from '@/features/purchase-orders/pages/view-purchase-order-page';
import { ProductMappingsPage } from '@/features/product-mappings/pages/product-mappings-page';
import { OrdersPage } from '@/features/orders/pages/orders-page';
import { OrderWorkspacePage } from '@/features/orders/pages/order-workspace-page';
import { FulfillmentsPage } from '@/features/fulfillments/pages/fulfillments-page';
import { CreateFulfillmentPage } from '@/features/fulfillments/pages/create-fulfillment-page';
import { ViewFulfillmentPage } from '@/features/fulfillments/pages/view-fulfillment-page';
import { SyncLogsPage } from '@/features/sync-logs/pages/sync-logs-page';
import { BomsPage } from '@/features/boms/pages/boms-page';
import { BomWorkspacePage } from '@/features/boms/pages/bom-workspace-page';
import { CustomersPage } from '@/features/customers/pages/customers-page';
import { StockLedgerPage } from '@/features/stock-ledger/pages/stock-ledger-page';
import { InventoryDashboardPage } from '@/features/inventory-control/pages/inventory-dashboard-page';
import { AbcClassificationPage } from '@/features/inventory-control/pages/abc-classification-page';
import { CycleCountPlannerPage } from '@/features/inventory-control/pages/cycle-count-planner-page';
import { VarianceAnalyticsPage } from '@/features/inventory-control/pages/variance-analytics-page';
import { WarehousePerformancePage } from '@/features/inventory-control/pages/warehouse-performance-page';
import { CreateGoodsReceiptPage } from '@/features/goods-receipts/pages/create-goods-receipt-page';
import { EditGoodsReceiptPage } from '@/features/goods-receipts/pages/edit-goods-receipt-page';
import { GoodsReceiptsPage } from '@/features/goods-receipts/pages/goods-receipts-page';
import { ViewGoodsReceiptPage } from '@/features/goods-receipts/pages/view-goods-receipt-page';
import { SuppliersPage } from '@/features/suppliers/pages/suppliers-page';
import { ViewSupplierPage } from '@/features/suppliers/pages/view-supplier-page';
import { AuthLayout } from '@/layouts/auth-layout';
import { GuestRoute } from '@/router/guards/guest-route';
import { ProtectedRoute } from '@/router/guards/protected-route';
import { ROUTES } from '@/router/routes';

const moduleRoutes = [
  ROUTES.purchasing,
  ROUTES.sales,
  ROUTES.accounting,
  ROUTES.crm,
  ROUTES.hr,
  ROUTES.reports,
  ROUTES.settings,
].map((path) => ({ path, Component: ComingSoonPage }));

export const router = createBrowserRouter(
  [
    { path: ROUTES.home, Component: HomePage },
    {
      path: ROUTES.login,
      Component: GuestRoute,
      children: [{ Component: AuthLayout, children: [{ index: true, Component: LoginPage }] }],
    },
    {
      Component: ProtectedRoute,
      children: [
        {
          Component: AppShell,
          children: [
            { path: ROUTES.dashboard, Component: DashboardPage },
            // Organization workspace + sub-pages
            { path: ROUTES.organization, Component: OrganizationWorkspace },
            { path: ROUTES.companies, Component: CompaniesPage },
            { path: ROUTES.branches, Component: BranchesPage },
            { path: ROUTES.warehouses, Component: WarehousesPage },
            { path: ROUTES.channels, Component: ChannelsPage },
            // Inventory workspace + sub-pages
            { path: ROUTES.inventoryProducts, Component: InventoryProductsWorkspace },
            { path: ROUTES.products, Component: ProductsPage },
            { path: ROUTES.rawMaterials, Component: RawMaterialsPage },
            { path: ROUTES.categories, Component: CategoriesPage },
            { path: ROUTES.units, Component: UnitsPage },
            { path: ROUTES.stockLedger, Component: StockLedgerPage },
            // Inventory Control
            { path: ROUTES.inventoryDashboard, Component: InventoryDashboardPage },
            { path: ROUTES.inventoryAbcClassifications, Component: AbcClassificationPage },
            { path: ROUTES.inventoryCycleCountPlanner, Component: CycleCountPlannerPage },
            { path: ROUTES.inventoryVarianceAnalytics, Component: VarianceAnalyticsPage },
            { path: ROUTES.inventoryWarehousePerformance, Component: WarehousePerformancePage },
            // Purchasing
            { path: ROUTES.suppliers, Component: SuppliersPage },
            { path: `${ROUTES.suppliers}/:id`, Component: ViewSupplierPage },
            { path: ROUTES.purchaseOrders, Component: PurchaseOrdersPage },
            { path: ROUTES.purchaseOrdersNew, Component: CreatePurchaseOrderPage },
            { path: `${ROUTES.purchaseOrders}/:id`, Component: ViewPurchaseOrderPage },
            { path: `${ROUTES.purchaseOrders}/:id/edit`, Component: EditPurchaseOrderPage },
            { path: ROUTES.goodsReceipts, Component: GoodsReceiptsPage },
            { path: ROUTES.goodsReceiptsNew, Component: CreateGoodsReceiptPage },
            { path: `${ROUTES.goodsReceipts}/:id`, Component: ViewGoodsReceiptPage },
            { path: `${ROUTES.goodsReceipts}/:id/edit`, Component: EditGoodsReceiptPage },
            // Sales
            { path: ROUTES.orders, Component: OrdersPage },
            { path: ROUTES.ordersNew, Component: OrderWorkspacePage },
            { path: `${ROUTES.orders}/:id/edit`, Component: OrderWorkspacePage },
            { path: `${ROUTES.orders}/:id`, Component: OrderWorkspacePage },
            { path: ROUTES.fulfillments, Component: FulfillmentsPage },
            { path: ROUTES.fulfillmentsNew, Component: CreateFulfillmentPage },
            { path: `${ROUTES.fulfillments}/:id`, Component: ViewFulfillmentPage },
            { path: ROUTES.customers, Component: CustomersPage },
            // Commerce
            { path: ROUTES.productMappings, Component: ProductMappingsPage },
            { path: ROUTES.syncLogs, Component: SyncLogsPage },
            // Manufacturing
            { path: ROUTES.boms, Component: BomsPage },
            { path: ROUTES.bomsNew, Component: BomWorkspacePage },
            { path: `${ROUTES.boms}/:id/edit`, Component: BomWorkspacePage },
            { path: `${ROUTES.boms}/:id`, Component: BomWorkspacePage },
            ...moduleRoutes,
          ],
        },
      ],
    },
  ],
  { basename: import.meta.env.BASE_URL },
);
