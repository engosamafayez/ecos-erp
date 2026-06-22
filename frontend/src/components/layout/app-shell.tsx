import { useState } from 'react';
import { Outlet } from 'react-router-dom';

import { BrandLogo } from '@/components/common/brand-logo';
import { AppBreadcrumbs } from '@/components/layout/app-breadcrumbs';
import { AppFooter } from '@/components/layout/app-footer';
import { AppSidebar } from '@/components/layout/app-sidebar';
import { AppTopbar } from '@/components/layout/app-topbar';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet';

/**
 * Responsive application shell that hosts every ERP module:
 * top bar + (desktop) sidebar / (mobile) drawer + breadcrumb + content + footer.
 */
export function AppShell() {
  const [mobileOpen, setMobileOpen] = useState(false);

  return (
    <div className="flex min-h-svh flex-col">
      <AppTopbar onOpenSidebar={() => setMobileOpen(true)} />

      <div className="flex flex-1">
        {/* Desktop sidebar */}
        <aside className="hidden w-64 shrink-0 border-r md:block">
          <div className="sticky top-14 max-h-[calc(100svh-3.5rem)] overflow-y-auto">
            <AppSidebar />
          </div>
        </aside>

        {/* Content column */}
        <div className="flex min-w-0 flex-1 flex-col">
          <AppBreadcrumbs />
          <main className="flex-1 p-4 sm:p-6">
            <Outlet />
          </main>
          <AppFooter />
        </div>
      </div>

      {/* Mobile drawer */}
      <Sheet open={mobileOpen} onOpenChange={setMobileOpen}>
        <SheetContent side="left" className="w-72 p-0">
          <SheetHeader className="border-b">
            <SheetTitle className="flex items-center">
              <BrandLogo />
            </SheetTitle>
          </SheetHeader>
          <div className="overflow-y-auto">
            <AppSidebar onNavigate={() => setMobileOpen(false)} />
          </div>
        </SheetContent>
      </Sheet>
    </div>
  );
}
