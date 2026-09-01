import { Link, useLocation } from 'react-router-dom';
import { LayoutGrid, Search } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { cn } from '@/lib/utils';
import { useHeaderContext } from '@/components/layout/header';
import { useNavigation } from '@/features/authorization';
import { useNavLabel } from './use-nav-label';

type MobileBottomNavProps = {
  onOpenMenu: () => void;
};

/**
 * Bottom navigation bar (TASK-ECOS-MOBILE-UX-COMPLETION-002, parent design
 * report §5). Slots are role-aware, not hardcoded: Dashboard is always first
 * (the app's one `ALWAYS_VISIBLE` module), and the next 1–2 slots are simply
 * the user's own first authorized modules from `useNavigation()` — the SAME
 * canonical RBAC-filtered, canonically-ordered list the desktop rail and the
 * Modules launcher use. This fixes the previous hardcoded "Orders" slot that
 * pinned an item to users who may never touch it, without inventing a new
 * per-role priority table: it reuses the existing module order as-is.
 *
 * "Modules" replaces the old "More" — it opens the same `MobileMenu`, now the
 * full Modules launcher rather than a flat accordion (see `mobile-menu.tsx`).
 */
export function MobileBottomNav({ onOpenMenu }: MobileBottomNavProps) {
  const { t } = useTranslation('common');
  const { pathname } = useLocation();
  const { openSearch } = useHeaderContext();
  const { modules } = useNavigation();
  const navLabel = useNavLabel();

  const dashboard = modules.find((m) => m.id === 'dashboard');
  const others = modules.filter((m) => m.id !== 'dashboard').slice(0, 2);
  const pinned = [...(dashboard ? [dashboard] : []), ...others];

  return (
    <nav
      aria-label={t(($) => $.nav.mobileNavigation)}
      className="fixed inset-x-0 bottom-0 z-40 flex h-14 items-stretch border-t bg-background md:hidden"
    >
      {pinned.map((mod) => {
        const Icon = mod.icon;
        const path = mod.defaultPath;
        const isActive = pathname === path || pathname.startsWith(path + '/');
        const label = navLabel.group(mod.id);
        return (
          <Link
            key={mod.id}
            to={path}
            aria-label={label}
            aria-current={isActive ? 'page' : undefined}
            className={cn(
              'flex flex-1 flex-col items-center justify-center gap-0.5 text-[10px] font-medium transition-colors',
              isActive ? 'text-primary' : 'text-muted-foreground hover:text-foreground',
            )}
          >
            <Icon className="size-5" aria-hidden />
            <span className="line-clamp-1 max-w-full break-all">{label}</span>
          </Link>
        );
      })}

      {/* Search — now wired to GlobalSearch dialog via HeaderContext */}
      <button
        type="button"
        onClick={openSearch}
        aria-label={t(($) => $.common.search)}
        className="flex flex-1 flex-col items-center justify-center gap-0.5 text-[10px] font-medium text-muted-foreground transition-colors hover:text-foreground"
      >
        <Search className="size-5" aria-hidden />
        <span>{t(($) => $.common.search)}</span>
      </button>

      <button
        type="button"
        onClick={onOpenMenu}
        aria-label={t(($) => $.nav.modules)}
        className="flex flex-1 flex-col items-center justify-center gap-0.5 text-[10px] font-medium text-muted-foreground transition-colors hover:text-foreground"
      >
        <LayoutGrid className="size-5" aria-hidden />
        <span>{t(($) => $.nav.modules)}</span>
      </button>
    </nav>
  );
}
