import { Link, useLocation } from 'react-router-dom';
import { LayoutDashboard, Menu, Search, ShoppingBag } from 'lucide-react';

import { cn } from '@/lib/utils';
import { ROUTES } from '@/router/routes';
import { useHeaderContext } from '@/components/layout/header';

type MobileBottomNavProps = {
  onOpenMenu: () => void;
};

const PINNED = [
  { key: 'dashboard', label: 'Dashboard', icon: LayoutDashboard, path: ROUTES.dashboard },
  { key: 'orders', label: 'Orders', icon: ShoppingBag, path: ROUTES.orders },
] as const;

export function MobileBottomNav({ onOpenMenu }: MobileBottomNavProps) {
  const { pathname } = useLocation();
  const { openSearch } = useHeaderContext();

  return (
    <nav
      aria-label="Mobile navigation"
      className="fixed inset-x-0 bottom-0 z-40 flex h-14 items-stretch border-t bg-background md:hidden"
    >
      {PINNED.map(({ key, label, icon: Icon, path }) => {
        const isActive = pathname === path || pathname.startsWith(path + '/');
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
        aria-label="Search"
        className="flex flex-1 flex-col items-center justify-center gap-0.5 text-[10px] font-medium text-muted-foreground transition-colors hover:text-foreground"
      >
        <Search className="size-5" aria-hidden />
        <span>Search</span>
      </button>

      <button
        type="button"
        onClick={onOpenMenu}
        aria-label="More"
        className="flex flex-1 flex-col items-center justify-center gap-0.5 text-[10px] font-medium text-muted-foreground transition-colors hover:text-foreground"
      >
        <Menu className="size-5" aria-hidden />
        <span>More</span>
      </button>
    </nav>
  );
}
