import { ChevronLeft, ChevronRight } from 'lucide-react';
import { NavLink, useLocation } from 'react-router-dom';
import { useTranslation } from 'react-i18next';

import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { visibleModuleItems, type AppModule } from '@/config/module-navigation';
import { useAuthorization, usePermission } from '@/features/authorization';
import { usePriceReviewBadge } from '@/features/cost-management/hooks/use-pricing-reviews';
import { useLanguage } from '@/providers/language-context';
import { useNavLabel } from './use-nav-label';

function PriceReviewBadge() {
  const { data } = usePriceReviewBadge();
  const count = data?.pending ?? 0;
  if (count === 0) return null;
  return (
    <span className="ms-auto flex h-4 min-w-4 items-center justify-center rounded-full bg-amber-500 px-1 text-[10px] font-semibold leading-none text-white tabular-nums">
      {count > 99 ? '99+' : count}
    </span>
  );
}

type AppSidebarProps = {
  activeModule: AppModule | undefined;
  collapsed?: boolean;
  onCollapse?: () => void;
  onNavigate?: () => void;
  className?: string;
};

export function AppSidebar({
  activeModule,
  collapsed = false,
  onCollapse,
  onNavigate,
  className,
}: AppSidebarProps) {
  const { dir } = useLanguage();
  const { t } = useTranslation('common');
  const navLabel = useNavLabel();
  const { can } = usePermission();
  const { context } = useAuthorization();
  const { pathname } = useLocation();

  /**
   * §17 — the sidebar renders only the entries this user's PERMISSIONS allow.
   *
   * Module visibility was already permission-driven; items were not, so a role granted one
   * page inside a module was shown every page in it. §17 is written page by page
   * ("HIDDEN COMPLETELY: Orders page, Customers page, Products page"), so the boundary has
   * to exist at this granularity. `can()` short-circuits to true for a system user, so
   * Super Admin still sees everything (§17.1).
   *
   * UX only — every route and every endpoint stays independently gated.
   */
  const items = activeModule ? visibleModuleItems(activeModule.items, can, context.navigationOverrides) : [];

  // A module whose every entry is hidden renders no sidebar at all, exactly as a module
  // with no entries always has.
  if (!activeModule || items.length === 0) return null;

  // In RTL the sidebar is at the inline-end (physical right), so chevron direction flips.
  const ExpandIcon  = dir === 'rtl' ? ChevronLeft  : ChevronRight;
  const CollapseIcon = dir === 'rtl' ? ChevronRight : ChevronLeft;

  if (collapsed) {
    // §5/§8 — a collapsed sidebar that hid every nav item behind a bare
    // expand button was "visually narrower," not "usable": the user lost
    // both wayfinding (which page they're on) and one-click access to any
    // other page in the module until they expanded it again. Icon-only rows
    // below restore both, at the same information density ModuleRail already
    // uses one column over — same active-state treatment, same Tooltip
    // primitive newly shared with it (see module-rail.tsx).
    const tooltipSide = dir === 'rtl' ? 'left' : 'right';
    return (
      <div className={cn('flex w-12 flex-col items-center gap-0.5 bg-sidebar py-2', className)}>
        <Tooltip>
          <TooltipTrigger asChild>
            <Button
              variant="ghost"
              size="icon"
              className="size-8"
              onClick={onCollapse}
              aria-label={t($ => $.nav.expandSidebar)}
            >
              <ExpandIcon className="size-4" />
            </Button>
          </TooltipTrigger>
          <TooltipContent side={tooltipSide}>{t($ => $.nav.expandSidebar)}</TooltipContent>
        </Tooltip>

        <nav
          aria-label={t($ => $.nav.moduleNav, {
            module: navLabel.group(activeModule.id),
          })}
          className="mt-1 flex w-full flex-col items-center gap-0.5 overflow-y-auto"
        >
          {items
            .filter((item) => !item.isSection)
            .map((item) => {
              const Icon = item.icon;
              const label = navLabel.item(item.key);
              // Computed manually, not via NavLink's `className={({isActive}) => ...}`
              // render-prop: TooltipTrigger's `asChild` clones this element through
              // Radix's Slot, which forwards/merges static prop VALUES — it can't
              // invoke a function prop the way NavLink itself does internally, so a
              // function className survives Slot-cloning only as its own
              // `.toString()`. Matches the same pathname-prefix check
              // mobile-bottom-nav.tsx already uses for the identical reason (its
              // active state also has to be computed outside NavLink's own prop).
              const isActive = pathname === item.path || pathname.startsWith(item.path + '/');
              return (
                <Tooltip key={item.key}>
                  <TooltipTrigger asChild>
                    <NavLink
                      to={item.path}
                      onClick={onNavigate}
                      aria-label={label}
                      className={cn(
                        'relative flex size-9 shrink-0 items-center justify-center rounded-md transition-colors',
                        isActive
                          ? 'bg-primary text-primary-foreground'
                          : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                      )}
                    >
                      <Icon className="size-4 shrink-0" aria-hidden />
                      {item.key === 'price-review' && (
                        <span className="absolute end-1 top-1">
                          <PriceReviewBadge />
                        </span>
                      )}
                    </NavLink>
                  </TooltipTrigger>
                  <TooltipContent side={tooltipSide}>{label}</TooltipContent>
                </Tooltip>
              );
            })}
        </nav>
      </div>
    );
  }

  return (
    <div className={cn('flex w-60 flex-col bg-sidebar', className)}>
      {/* Sidebar header */}
      <div className="flex h-11 shrink-0 items-center justify-between border-b px-3">
        <span className="truncate text-sm font-semibold text-foreground">
          {navLabel.group(activeModule.id)}
        </span>
        {onCollapse && (
          <Button
            variant="ghost"
            size="icon"
            className="size-7 shrink-0"
            onClick={onCollapse}
            aria-label={t($ => $.nav.collapseSidebar)}
          >
            <CollapseIcon className="size-4" />
          </Button>
        )}
      </div>

      {/* Nav items */}
      <nav
        aria-label={t($ => $.nav.moduleNav, {
          module: navLabel.group(activeModule.id),
        })}
        className="flex flex-col gap-0.5 overflow-y-auto p-2"
      >
        {items.map((item) => {
          if (item.isSection) {
            return (
              <div key={item.key} className="mb-1 mt-4 px-3 first:mt-0">
                <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                  {navLabel.item(item.key)}
                </p>
              </div>
            );
          }
          const Icon = item.icon;
          return (
            <NavLink
              key={item.key}
              to={item.path}
              onClick={onNavigate}
              className={({ isActive }) =>
                cn(
                  'flex items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium transition-colors',
                  isActive
                    ? 'bg-primary text-primary-foreground'
                    : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                )
              }
            >
              <Icon className="size-4 shrink-0" aria-hidden />
              <span className="truncate">{navLabel.item(item.key)}</span>
              {item.key === 'price-review' && <PriceReviewBadge />}
            </NavLink>
          );
        })}
      </nav>
    </div>
  );
}
