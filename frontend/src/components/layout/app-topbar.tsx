import { Bell, PanelLeft, Plus, Search } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { BrandLogo } from '@/components/common/brand-logo';
import { LanguageSwitcher } from '@/components/common/language-switcher';
import { ThemeToggle } from '@/components/common/theme-toggle';
import { CompanySwitcher } from '@/components/layout/company-switcher';
import { UserMenu } from '@/components/layout/user-menu';
import { WarehouseSwitcher } from '@/components/layout/warehouse-switcher';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type AppTopbarProps = {
  onOpenSidebar: () => void;
};

export function AppTopbar({ onOpenSidebar }: AppTopbarProps) {
  const { t } = useTranslation('common');

  return (
    <header className="sticky top-0 z-40 flex h-14 shrink-0 items-center gap-2 border-b bg-background px-3 sm:px-4">
      {/* Brand — always visible, is the leftmost element */}
      <BrandLogo />

      {/* Sidebar toggle — tablet only (module rail visible but no persistent sidebar) */}
      <Button
        variant="ghost"
        size="icon"
        className="hidden md:flex lg:hidden"
        onClick={onOpenSidebar}
        aria-label="Toggle navigation"
      >
        <PanelLeft className="size-5" />
      </Button>

      {/* Global search — expanded md+, icon only on mobile */}
      <div className="relative hidden sm:block">
        <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
        <Input
          type="search"
          placeholder={t('topbar.search')}
          aria-label={t('topbar.search')}
          className="w-44 pl-8 md:w-64 lg:w-80"
        />
      </div>

      <div className="ml-auto flex items-center gap-1 sm:gap-1.5">
        {/* Mobile: search icon */}
        <Button variant="ghost" size="icon" className="sm:hidden" aria-label="Search">
          <Search className="size-5" />
        </Button>

        {/* Company + Warehouse switchers — md+ */}
        <div className="hidden md:flex items-center gap-1.5">
          <CompanySwitcher />
          <WarehouseSwitcher />
        </div>

        {/* Quick Create — lg+ */}
        <Button
          variant="outline"
          size="sm"
          className="hidden lg:flex items-center gap-1.5"
          aria-label="Quick create"
        >
          <Plus className="size-4" />
          <span>Create</span>
        </Button>

        {/* Notifications */}
        <Button variant="ghost" size="icon" aria-label="Notifications" className="relative">
          <Bell className="size-5" />
          <span className="absolute right-1.5 top-1.5 size-2 rounded-full bg-primary" aria-hidden />
        </Button>

        {/* Language + Theme — md+ */}
        <div className="hidden md:flex items-center gap-1">
          <LanguageSwitcher />
          <ThemeToggle />
        </div>

        <UserMenu />
      </div>
    </header>
  );
}
