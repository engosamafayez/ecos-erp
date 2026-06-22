import { createBrowserRouter } from 'react-router-dom';

import { DashboardPage } from '@/features/dashboard/pages/dashboard-page';
import { HomePage } from '@/features/home/pages/home-page';
import { LoginPage } from '@/features/auth/pages/login-page';
import { AuthLayout } from '@/layouts/auth-layout';
import { DashboardLayout } from '@/layouts/dashboard-layout';
import { ROUTES } from '@/router/routes';

/**
 * Application router. Layout routes wrap their children via <Outlet />.
 */
export const router = createBrowserRouter(
  [
    { path: ROUTES.home, Component: HomePage },
    {
      path: ROUTES.login,
      Component: AuthLayout,
      children: [{ index: true, Component: LoginPage }],
    },
    {
      path: ROUTES.dashboard,
      Component: DashboardLayout,
      children: [{ index: true, Component: DashboardPage }],
    },
  ],
  { basename: import.meta.env.BASE_URL },
);
