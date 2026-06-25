import { useState } from 'react';
import { Outlet } from 'react-router-dom';

import { AppBreadcrumbs } from '@/components/layout/app-breadcrumbs';
import { AppFooter } from '@/components/layout/app-footer';
import { AppSidebar } from '@/components/layout/app-sidebar';
import { AppTopbar } from '@/components/layout/app-topbar';
import { Sheet, SheetContent, SheetTitle } from '@/components/ui/sheet';

export function AppShell() {
  const [mobileOpen, setMobileOpen] = useState(false);

  return (
    <div className="flex min-h-svh flex-col">
      <AppTopbar onOpenSidebar={() => setMobileOpen(true)} />

      <div className="flex flex-1">
        {/* Desktop sidebar */}
        <aside className="bg-sidebar hidden w-64 shrink-0 border-r md:flex md:flex-col">
          <div className="sticky top-14 flex h-[calc(100svh-3.5rem)] flex-col">
            <AppSidebar />
          </div>
        </aside>

        {/* Content column */}
        <div className="flex min-w-0 flex-1 flex-col bg-background">
          <AppBreadcrumbs />
          <main className="flex-1 p-4 sm:p-6">
            <Outlet />
          </main>
          <AppFooter />
        </div>
      </div>

      {/* Mobile drawer — AppSidebar owns its own header (logo + company switcher) */}
      <Sheet open={mobileOpen} onOpenChange={setMobileOpen}>
        <SheetContent side="left" className="w-72 p-0 bg-sidebar flex flex-col">
          {/* Visually hidden title for accessibility */}
          <SheetTitle className="sr-only">Navigation</SheetTitle>
          <div className="flex-1 overflow-y-auto">
            <AppSidebar onNavigate={() => setMobileOpen(false)} />
          </div>
        </SheetContent>
      </Sheet>
    </div>
  );
}
