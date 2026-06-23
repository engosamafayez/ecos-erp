import { createBrowserRouter } from 'react-router-dom';

import { ComingSoonPage } from '@/components/common/coming-soon-page';
import { AppShell } from '@/components/layout/app-shell';
import { LoginPage } from '@/features/auth/pages/login-page';
import { BranchesPage } from '@/features/branches/pages/branches-page';
import { CategoriesPage } from '@/features/categories/pages/categories-page';
import { CompaniesPage } from '@/features/companies/pages/companies-page';
import { DashboardPage } from '@/features/dashboard/pages/dashboard-page';
import { HomePage } from '@/features/home/pages/home-page';
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
            { path: ROUTES.warehouses, Component: WarehousesPage },
            { path: ROUTES.categories, Component: CategoriesPage },
            { path: ROUTES.units, Component: UnitsPage },
            ...moduleRoutes,
          ],
        },
      ],
    },
  ],
  { basename: import.meta.env.BASE_URL },
);
