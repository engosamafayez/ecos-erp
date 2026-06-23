import { createBrowserRouter } from 'react-router-dom';

import { ComingSoonPage } from '@/components/common/coming-soon-page';
import { AppShell } from '@/components/layout/app-shell';
import { LoginPage } from '@/features/auth/pages/login-page';
import { BranchesPage } from '@/features/branches/pages/branches-page';
import { CategoriesPage } from '@/features/categories/pages/categories-page';
import { CompaniesPage } from '@/features/companies/pages/companies-page';
import { DashboardPage } from '@/features/dashboard/pages/dashboard-page';
import { HomePage } from '@/features/home/pages/home-page';
import { ProductsPage } from '@/features/products/pages/products-page';
import { RawMaterialsPage } from '@/features/products/pages/raw-materials-page';
import { CreatePurchaseOrderPage } from '@/features/purchase-orders/pages/create-purchase-order-page';
import { EditPurchaseOrderPage } from '@/features/purchase-orders/pages/edit-purchase-order-page';
import { PurchaseOrdersPage } from '@/features/purchase-orders/pages/purchase-orders-page';
import { ViewPurchaseOrderPage } from '@/features/purchase-orders/pages/view-purchase-order-page';
import { CreateGoodsReceiptPage } from '@/features/goods-receipts/pages/create-goods-receipt-page';
import { EditGoodsReceiptPage } from '@/features/goods-receipts/pages/edit-goods-receipt-page';
import { GoodsReceiptsPage } from '@/features/goods-receipts/pages/goods-receipts-page';
import { ViewGoodsReceiptPage } from '@/features/goods-receipts/pages/view-goods-receipt-page';
import { SuppliersPage } from '@/features/suppliers/pages/suppliers-page';
import { UnitsPage } from '@/features/units/pages/units-page';
import { WarehousesPage } from '@/features/warehouses/pages/warehouses-page';
import { AuthLayout } from '@/layouts/auth-layout';
import { GuestRoute } from '@/router/guards/guest-route';
import { ProtectedRoute } from '@/router/guards/protected-route';
import { ROUTES } from '@/router/routes';

// Every module route renders the same reusable "Coming Soon" placeholder,
// which derives its title from the active navigation item (no duplicated pages).
const moduleRoutes = [
  ROUTES.inventory,
  ROUTES.purchasing,
  ROUTES.sales,
  ROUTES.accounting,
  ROUTES.crm,
  ROUTES.hr,
  ROUTES.reports,
  ROUTES.settings,
].map((path) => ({ path, Component: ComingSoonPage }));

/**
 * Application router.
 *
 * - `/login` is guest-only ({@link GuestRoute}).
 * - All application routes are protected ({@link ProtectedRoute}) and rendered
 *   inside the {@link AppShell}.
 */
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
            { path: ROUTES.companies, Component: CompaniesPage },
            { path: ROUTES.branches, Component: BranchesPage },
            { path: ROUTES.products, Component: ProductsPage },
            { path: ROUTES.rawMaterials, Component: RawMaterialsPage },
            { path: ROUTES.warehouses, Component: WarehousesPage },
            { path: ROUTES.categories, Component: CategoriesPage },
            { path: ROUTES.units, Component: UnitsPage },
            { path: ROUTES.suppliers, Component: SuppliersPage },
            { path: ROUTES.purchaseOrders, Component: PurchaseOrdersPage },
            { path: ROUTES.purchaseOrdersNew, Component: CreatePurchaseOrderPage },
            { path: `${ROUTES.purchaseOrders}/:id`, Component: ViewPurchaseOrderPage },
            { path: `${ROUTES.purchaseOrders}/:id/edit`, Component: EditPurchaseOrderPage },
            { path: ROUTES.goodsReceipts, Component: GoodsReceiptsPage },
            { path: ROUTES.goodsReceiptsNew, Component: CreateGoodsReceiptPage },
            { path: `${ROUTES.goodsReceipts}/:id`, Component: ViewGoodsReceiptPage },
            { path: `${ROUTES.goodsReceipts}/:id/edit`, Component: EditGoodsReceiptPage },
            ...moduleRoutes,
          ],
        },
      ],
    },
  ],
  { basename: import.meta.env.BASE_URL },
);
