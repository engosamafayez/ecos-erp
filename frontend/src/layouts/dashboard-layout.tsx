import { LayoutDashboard, LogOut } from 'lucide-react';
import { Outlet, useNavigate } from 'react-router-dom';

import { ThemeToggle } from '@/components/common/theme-toggle';
import { Button } from '@/components/ui/button';
import { useAuthStore } from '@/features/auth/store/auth-store';
import { ROUTES } from '@/router/routes';
import { env } from '@/lib/env';

/**
 * Layout for authenticated/application screens. Provides the top app bar, the
 * current user, a logout action, and a content outlet.
 */
export function DashboardLayout() {
  const navigate = useNavigate();
  const user = useAuthStore((state) => state.user);
  const logout = useAuthStore((state) => state.logout);

  const handleLogout = async () => {
    await logout();
    navigate(ROUTES.login, { replace: true });
  };

  return (
    <div className="flex min-h-svh flex-col">
      <header className="flex items-center justify-between gap-4 border-b px-4 py-3">
        <div className="flex items-center gap-2 font-semibold">
          <LayoutDashboard className="size-5" />
          <span>{env.appName}</span>
        </div>
        <nav className="flex items-center gap-3">
          {user ? <span className="text-muted-foreground text-sm">{user.name}</span> : null}
          <ThemeToggle />
          <Button variant="outline" size="sm" onClick={handleLogout}>
            <LogOut className="size-4" />
            Logout
          </Button>
        </nav>
      </header>
      <main className="flex-1 p-6">
        <Outlet />
      </main>
    </div>
  );
}
