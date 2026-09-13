import { PanelLeft } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { BrandLogo } from '@/components/common/brand-logo';

import {
  BrandSwitcher,
  CompanySwitcher,
  GlobalSearch,
  NotificationCenter,
  SmartCreate,
  UserMenu,
  WarehouseSwitcher,
} from './header';

type AppTopbarProps = {
  onOpenSidebar: () => void;
};

/**
 * Global ERP command bar — sticky, z-40, h-14.
 *
 * Layout (left → right):
 *   BrandLogo | SidebarToggle(md-only) | GlobalSearch(flex-1, sm+)
 *   | SearchIcon(mobile) | Company(xl+) | Brand(xl+) | Warehouse(xl+)
 *   | Separator(xl+) | SmartCreate(xl+) | Notifications | UserMenu
 *
 * Language + Theme are accessible via the UserMenu dropdown on all screen sizes.
 *
 * TASK-ECOS-V1.1-CORE-01-UI-02-FINAL-TABLET-CLOSURE-046-R1 — the Company +
 * Brand + Warehouse switchers, the separator, and Smart Create moved from
 * `md:` (768px) to `xl:` (1280px). Measured at 768px and 1024px: this group
 * alone is ~600-660px wide (each switcher shows a full label; UserMenu also
 * grows at `lg:`), which does not fit alongside a flex-1 search bar in either
 * width — real, measured horizontal overflow (~250px at 768px, ~85px at
 * 1024px), predating this fix (only ~7px of it was the new Separator).
 * `xl:` is the first breakpoint with enough width for the whole cluster.
 */
export function AppTopbar({ onOpenSidebar }: AppTopbarProps) {
  const { t } = useTranslation('common');

  return (
    <header className="no-print sticky top-0 z-40 flex h-14 shrink-0 items-center gap-2 border-b bg-background/95 backdrop-blur-sm px-3 sm:px-4">

      {/* ── Left: Brand + sidebar toggle ── */}
      <BrandLogo />

      <Button
        variant="ghost"
        size="icon"
        className="hidden md:flex lg:hidden shrink-0"
        onClick={onOpenSidebar}
        aria-label={t($ => $.nav.toggleSidebar)}
      >
        <PanelLeft className="size-5" aria-hidden data-flip-rtl />
      </Button>

      {/* ── Center: Global search (flex-1 so it fills available space) ── */}
      <div className="hidden flex-1 sm:flex">
        <GlobalSearch />
      </div>

      {/* ── Right section ── */}
      <div className="ms-auto flex items-center gap-1 sm:gap-1.5">

        {/* Search icon — mobile only (dialog triggered via context) */}
        <div className="sm:hidden">
          <GlobalSearch />
        </div>

        {/* Company + Brand + Warehouse switchers — xl+ only (§046-R1: not
            enough width alongside search at md/lg, see file docblock) */}
        <div className="hidden xl:flex items-center gap-1.5">
          <CompanySwitcher />
          <BrandSwitcher />
          <WarehouseSwitcher />
        </div>

        {/* §7 — a visual boundary between "context" (which company/brand/
            warehouse) and "actions" (create, notifications, account) reads
            as one grouped cluster otherwise, especially once the switchers
            grow to 3 items. Canonical Separator, not a hardcoded border. */}
        <Separator orientation="vertical" className="hidden h-5 xl:block" />

        {/* Smart Create — xl+ (moved with the switcher group, §046-R1) */}
        <div className="hidden xl:block">
          <SmartCreate />
        </div>

        {/* Notifications — always visible */}
        <NotificationCenter />

        {/* User menu — always visible (lang+theme inside dropdown) */}
        <UserMenu />
      </div>
    </header>
  );
}
