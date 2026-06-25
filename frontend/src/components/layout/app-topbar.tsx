import { Bell, Menu, Search } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { BrandLogo } from '@/components/common/brand-logo';
import { LanguageSwitcher } from '@/components/common/language-switcher';
import { ThemeToggle } from '@/components/common/theme-toggle';
import { UserMenu } from '@/components/layout/user-menu';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type AppTopbarProps = {
  onOpenSidebar: () => void;
};

export function AppTopbar({ onOpenSidebar }: AppTopbarProps) {
  const { t } = useTranslation('common');

  return (
    <header className="bg-card sticky top-0 z-30 flex h-14 items-center gap-3 border-b px-3 sm:px-4">
      {/* Mobile: hamburger */}
      <Button
        variant="ghost"
        size="icon"
        className="md:hidden"
        onClick={onOpenSidebar}
        aria-label="Open navigation"
      >
        <Menu className="size-5" />
      </Button>

      {/* Mobile: logo (desktop logo lives in sidebar) */}
      <div className="md:hidden">
        <BrandLogo />
      </div>

      {/* Global search */}
      <div className="relative ml-auto hidden sm:ml-0 sm:block">
        <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2" />
        <Input
          type="search"
          placeholder={t('topbar.search')}
          aria-label={t('common.search')}
          className="w-44 pl-8 md:w-72"
        />
      </div>

      <div className="ml-auto flex items-center gap-1 sm:gap-1.5">
        {/* Mobile search icon */}
        <Button variant="ghost" size="icon" className="sm:hidden" aria-label="Search">
          <Search className="size-5" />
        </Button>

        {/* Notifications */}
        <Button variant="ghost" size="icon" aria-label="Notifications" className="relative">
          <Bell className="size-5" />
          <span className="bg-primary absolute top-1.5 right-1.5 size-2 rounded-full" />
        </Button>

        <LanguageSwitcher />
        <ThemeToggle />
        <UserMenu />
      </div>
    </header>
  );
}
