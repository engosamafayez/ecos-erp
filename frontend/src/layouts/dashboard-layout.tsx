import { LayoutDashboard } from 'lucide-react';
import { NavLink, Outlet } from 'react-router-dom';

import { ThemeToggle } from '@/components/common/theme-toggle';
import { env } from '@/lib/env';

/**
 * Layout for authenticated/application screens. Provides the top app bar and a
 * content outlet. Sidebar/navigation is added by future modules.
 */
export function DashboardLayout() {
  return (
    <div className="flex min-h-svh flex-col">
      <header className="flex items-center justify-between gap-4 border-b px-4 py-3">
        <div className="flex items-center gap-2 font-semibold">
          <LayoutDashboard className="size-5" />
          <span>{env.appName}</span>
        </div>
        <nav className="flex items-center gap-3">
          <NavLink to="/" className="text-muted-foreground hover:text-foreground text-sm">
            Home
          </NavLink>
          <ThemeToggle />
        </nav>
      </header>
      <main className="flex-1 p-6">
        <Outlet />
      </main>
    </div>
  );
}
