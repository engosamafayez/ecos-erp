import { Bell, Menu, Search } from 'lucide-react';

import { BrandLogo } from '@/components/common/brand-logo';
import { ThemeToggle } from '@/components/common/theme-toggle';
import { CompanySwitcher } from '@/components/layout/company-switcher';
import { UserMenu } from '@/components/layout/user-menu';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type AppTopbarProps = {
  onOpenSidebar: () => void;
};

/**
 * Top navigation bar: logo, company switcher, search, notifications, theme
 * toggle and the user menu. Hosts the mobile sidebar trigger.
 */
export function AppTopbar({ onOpenSidebar }: AppTopbarProps) {
  return (
    <header className="bg-background sticky top-0 z-30 flex h-14 items-center gap-3 border-b px-3 sm:px-4">
      <Button
        variant="ghost"
        size="icon"
        className="md:hidden"
        onClick={onOpenSidebar}
        aria-label="Open navigation"
      >
        <Menu className="size-5" />
      </Button>

      <BrandLogo className="shrink-0" />

      <div className="hidden md:block">
        <CompanySwitcher />
      </div>

      <div className="ml-auto flex items-center gap-1 sm:gap-2">
        <div className="relative hidden sm:block">
          <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2" />
          <Input
            type="search"
            placeholder="Search…"
            aria-label="Search"
            className="w-40 pl-8 md:w-64"
          />
        </div>
        <Button variant="ghost" size="icon" aria-label="Notifications" className="relative">
          <Bell className="size-5" />
          <span className="bg-primary absolute top-1.5 right-1.5 size-2 rounded-full" />
        </Button>
        <ThemeToggle />
        <UserMenu />
      </div>
    </header>
  );
}
