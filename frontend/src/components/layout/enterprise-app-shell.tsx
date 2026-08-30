import { Navigate } from 'react-router-dom';

import { AppShell } from '@/components/layout/app-shell';
import { isDriverOnly } from '@/features/auth/post-login-landing';
import { useAuthStore } from '@/features/auth/store/auth-store';
import { ROUTES } from '@/router/routes';

/**
 * The enterprise `AppShell`, guarded to keep driver-only users out of ERP chrome.
 *
 * A driver-only user who deep-links to any enterprise route is redirected to the driver home,
 * reusing the SAME `isDriverOnly` predicate as `resolvePostLoginPath` — so the login boundary
 * and the deep-link boundary cannot diverge and no role list is hardcoded here. Enterprise and
 * mixed (enterprise + driver) users render `AppShell` normally; its `<Outlet />` continues to
 * resolve the enterprise child routes. This is the UX half of driver isolation (the DriverShell
 * is the other half); every API route stays independently permission-gated, so this changes
 * reachability, never authority.
 */
export function EnterpriseAppShell() {
  const user = useAuthStore((state) => state.user);

  if (isDriverOnly(user)) {
    return <Navigate to={ROUTES.driverHome} replace />;
  }

  return <AppShell />;
}
