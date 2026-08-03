import { Link, useLocation } from 'react-router-dom';
import { LayoutDashboard, Menu, Search, ShoppingBag } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { cn } from '@/lib/utils';
import { ROUTES } from '@/router/routes';
import { useHeaderContext } from '@/components/layout/header';

type MobileBottomNavProps = {
  onOpenMenu: () => void;
};

const PINNED = [
  {
    key: 'dashboard',
    labelKey: 'nav.items.dashboard',
    icon: LayoutDashboard,
    path: ROUTES.dashboard,
  },
  { key: 'orders', labelKey: 'nav.items.orders', icon: ShoppingBag, path: ROUTES.orders },
] as const;

export function MobileBottomNav({ onOpenMenu }: MobileBottomNavProps) {
  const { t } = useTranslation('common');
  const { pathname } = useLocation();
  const { openSearch } = useHeaderContext();

  return (
    <nav
      aria-label={t($ => $.nav.mobileNavigation)}
      className="fixed inset-x-0 bottom-0 z-40 flex h-14 items-stretch border-t bg-background md:hidden"
    >
      {PINNED.map(({ key, labelKey, icon: Icon, path }) => {
        const isActive = pathname === path || pathname.startsWith(path + '/');
        const label = t(labelKey);
        return (
          <Link
            key={key}
            to={path}
            aria-label={label}
            aria-current={isActive ? 'page' : undefined}
            className={cn(
              'flex flex-1 flex-col items-center justify-center gap-0.5 text-[10px] font-medium transition-colors',
              isActive ? 'text-primary' : 'text-muted-foreground hover:text-foreground',
            )}
          >
            <Icon className="size-5" aria-hidden />
            <span>{label}</span>
          </Link>
        );
      })}

      {/* Search — now wired to GlobalSearch dialog via HeaderContext */}
      <button
        type="button"
        onClick={openSearch}
        aria-label={t($ => $.common.search)}
        className="flex flex-1 flex-col items-center justify-center gap-0.5 text-[10px] font-medium text-muted-foreground transition-colors hover:text-foreground"
      >
        <Search className="size-5" aria-hidden />
        <span>{t($ => $.common.search)}</span>
      </button>

      <button
        type="button"
        onClick={onOpenMenu}
        aria-label={t($ => $.actions.more)}
        className="flex flex-1 flex-col items-center justify-center gap-0.5 text-[10px] font-medium text-muted-foreground transition-colors hover:text-foreground"
      >
        <Menu className="size-5" aria-hidden />
        <span>{t($ => $.actions.more)}</span>
      </button>
    </nav>
  );
}
