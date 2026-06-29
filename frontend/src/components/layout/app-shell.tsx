import { useState } from 'react';
import { Outlet } from 'react-router-dom';

import { CommandProvider } from '@/components/command-center';
import { AppFooter } from '@/components/layout/app-footer';
import { AppSidebar } from '@/components/layout/app-sidebar';
import { AppTopbar } from '@/components/layout/app-topbar';
import { HeaderProvider } from '@/components/layout/header';
import { MobileBottomNav } from '@/components/layout/mobile-bottom-nav';
import { MobileMenu } from '@/components/layout/mobile-menu';
import { ModuleRail } from '@/components/layout/module-rail';
import { Sheet, SheetContent, SheetTitle } from '@/components/ui/sheet';
import { useActiveModule } from '@/hooks/use-active-module';

export function AppShell() {
  const activeModule = useActiveModule();
  const [sidebarCollapsed, setSidebarCollapsed] = useState(false);
  const [tabletSidebarOpen, setTabletSidebarOpen] = useState(false);
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);

  const hasSidebarItems = (activeModule?.items.length ?? 0) > 0;

  return (
    <HeaderProvider>
      <CommandProvider>
      <div className="flex min-h-svh flex-col">
        <AppTopbar onOpenSidebar={() => setTabletSidebarOpen(true)} />

        <div className="flex flex-1 overflow-hidden">
          {/* Module Rail — tablet+ (md+) */}
          <ModuleRail activeModule={activeModule} className="hidden md:flex" />

          {/* Context Sidebar — laptop+ (lg+), persistent + collapsible */}
          {hasSidebarItems && (
            <aside className="hidden lg:block border-r">
              <AppSidebar
                activeModule={activeModule}
                collapsed={sidebarCollapsed}
                onCollapse={() => setSidebarCollapsed((v) => !v)}
              />
            </aside>
          )}

          {/* Main content */}
          <div className="flex min-w-0 flex-1 flex-col bg-background">
            <main className="flex-1 overflow-y-auto p-4 pb-[calc(1rem+3.5rem)] sm:p-6 sm:pb-[calc(1.5rem+3.5rem)] md:pb-6">
              <Outlet />
            </main>
            <AppFooter />
          </div>
        </div>

        {/* Tablet sidebar overlay — md to lg */}
        <Sheet open={tabletSidebarOpen} onOpenChange={setTabletSidebarOpen}>
          <SheetContent side="left" className="w-64 p-0 bg-sidebar flex flex-col lg:hidden">
            <SheetTitle className="sr-only">Navigation</SheetTitle>
            <AppSidebar
              activeModule={activeModule}
              onNavigate={() => setTabletSidebarOpen(false)}
              className="w-full"
            />
          </SheetContent>
        </Sheet>

        {/* Mobile fullscreen menu */}
        <MobileMenu open={mobileMenuOpen} onClose={() => setMobileMenuOpen(false)} />

        {/* Mobile bottom nav */}
        <MobileBottomNav onOpenMenu={() => setMobileMenuOpen(true)} />
      </div>
      </CommandProvider>
    </HeaderProvider>
  );
}
