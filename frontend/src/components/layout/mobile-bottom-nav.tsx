import { Link, useLocation } from 'react-router-dom';
import { Menu, Search } from 'lucide-react';
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
 * This IS the Drawer trigger (TASK-ECOS-MOBILE-NAVIGATION-DRAWER-BOTTOM-
 * TRIGGER-001 §5: "reuse it" rather than adding a second, confusing entry
 * point) — tapping it opens `MobileMenu`. The icon changed from a tile-grid
 * glyph (`LayoutGrid`, which specifically signaled the now-rejected tile
 * launcher) to a plain hamburger (`Menu`) — the universal "open a navigation
 * drawer" glyph — for a more obvious trigger (§13); the label, position, and
 * every other slot are unchanged. It never gains an active/highlighted state:
 * it has no route of its own, so it must never falsely read as the current
 * page (§9) — unlike the pinned module icons to its left, which do.
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
      className="fixed inset-x-0 bottom-0 z-40 flex h-16 items-stretch border-t bg-background/95 backdrop-blur-sm md:hidden"
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
            className="flex flex-1 flex-col items-center justify-center gap-1 py-2"
          >
            <span
              className={cn(
                'flex items-center justify-center rounded-full px-3.5 py-1 transition-colors',
                isActive && 'bg-primary/10',
              )}
            >
              <Icon className={cn('size-5', isActive ? 'text-primary' : 'text-muted-foreground')} aria-hidden />
            </span>
            <span
              className={cn(
                'line-clamp-1 max-w-full break-all text-[10px] font-medium transition-colors',
                isActive ? 'text-primary' : 'text-muted-foreground',
              )}
            >
              {label}
            </span>
          </Link>
        );
      })}

      {/* Search — now wired to GlobalSearch dialog via HeaderContext */}
      <button
        type="button"
        onClick={openSearch}
        aria-label={t(($) => $.common.search)}
        className="flex flex-1 flex-col items-center justify-center gap-1 py-2 text-muted-foreground transition-colors active:text-foreground"
      >
        <span className="flex items-center justify-center rounded-full px-3.5 py-1">
          <Search className="size-5" aria-hidden />
        </span>
        <span className="text-[10px] font-medium">{t(($) => $.common.search)}</span>
      </button>

      <button
        type="button"
        onClick={onOpenMenu}
        aria-label={t(($) => $.nav.modules)}
        className="flex flex-1 flex-col items-center justify-center gap-1 py-2 text-muted-foreground transition-colors active:text-foreground"
      >
        <span className="flex items-center justify-center rounded-full px-3.5 py-1">
          <Menu className="size-5" aria-hidden />
        </span>
        <span className="text-[10px] font-medium">{t(($) => $.nav.modules)}</span>
      </button>
    </nav>
  );
}
