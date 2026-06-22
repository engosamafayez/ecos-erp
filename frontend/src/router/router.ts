import { createBrowserRouter } from 'react-router-dom';

import { DashboardPage } from '@/features/dashboard/pages/dashboard-page';
import { HomePage } from '@/features/home/pages/home-page';
import { LoginPage } from '@/features/auth/pages/login-page';
import { AuthLayout } from '@/layouts/auth-layout';
import { DashboardLayout } from '@/layouts/dashboard-layout';
import { GuestRoute } from '@/router/guards/guest-route';
import { ProtectedRoute } from '@/router/guards/protected-route';
import { ROUTES } from '@/router/routes';

/**
 * Application router.
 *
 * - `/login` is wrapped by {@link GuestRoute} (authenticated users are bounced
 *   to the dashboard).
 * - `/dashboard` is wrapped by {@link ProtectedRoute} (anonymous users are
 *   bounced to /login).
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
      path: ROUTES.dashboard,
      Component: ProtectedRoute,
      children: [
        { Component: DashboardLayout, children: [{ index: true, Component: DashboardPage }] },
      ],
    },
  ],
  { basename: import.meta.env.BASE_URL },
);
