import { LogOut, X } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';

import { cn } from '@/lib/utils';
import { Avatar, AvatarFallback, getInitials } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { SheetOverlay, SheetPortal, SheetPrimitive } from '@/components/ui/sheet';
import { useAuthStore } from '@/features/auth/store/auth-store';
import { useNavigation } from '@/features/authorization';
import type { ModuleId } from '@/config/module-navigation';
import { useActiveModule } from '@/hooks/use-active-module';
import { CompanySwitcher, WarehouseSwitcher } from '@/components/layout/header';
import { ROUTES } from '@/router/routes';
import { MobileModulesLauncher } from './mobile-modules-launcher';

type MobileMenuProps = {
  open: boolean;
  onClose: () => void;
};

/**
 * The Enterprise mobile navigation Drawer.
 *
 * TASK-ECOS-MOBILE-NAVIGATION-DRAWER-BOTTOM-TRIGGER-001 — the User reviewed
 * the previous tile-grid "Modules launcher + full-screen drill-in" shell
 * (TASK-ECOS-MOBILE-NAVIGATION-WORLD-CLASS-REDESIGN-004) on DEV and rejected
 * it, picking a Drawer-style concept instead: one continuous scrollable panel
 * with a profile identity area, company/warehouse context, and a grouped,
 * accordion-style navigation list — opened from the SAME fixed bottom-bar
 * icon as before (see `mobile-bottom-nav.tsx`), never a second screen.
 *
 * What carried over unchanged from the prior redesign (still the right
 * answer, not touched here):
 *   - The Radix Dialog primitive composition (`SheetPortal`/`SheetOverlay`/
 *     `SheetPrimitive.Content`) — Escape-to-close, scroll lock, focus trap
 *     and return-focus, backdrop tap-to-close, and the slide-up/fade
 *     animation are all inherited from Radix, not hand-rolled.
 *   - Modules and pages still come exclusively from `useNavigation()` /
 *     `AppModule.items` — the SAME canonical RBAC authority the desktop rail
 *     uses. This shell adds no visibility rule, no new module, no new route.
 *   - Driver navigation is owned by DriverShell (Lane A) and is intentionally
 *     not represented here — this menu is the enterprise shell only.
 *
 * What changed:
 *   - No more `openModuleId` two-view state — `MobileModulesLauncher` now
 *     expands a multi-page module IN PLACE (an accordion), so there is no
 *     drill-in screen to re-sync away from on reopen, and no "Back" button.
 *   - A profile identity card (avatar initials, name, email/role) — reusing
 *     the exact same `useAuthStore` the desktop `UserMenu` already reads —
 *     plus a Logout action, both entirely absent from the previous shell.
 *   - Company/Warehouse context is now visually a "card" (rounded border,
 *     `bg-card`) instead of a flat muted strip, per the new visual direction.
 *     Placement stays inside the Drawer's own header area (not hoisted to a
 *     persistent top-of-screen bar): moving it out would touch the shared
 *     mobile page chrome every other mobile screen renders under, which is
 *     out of this task's scope (navigation-only). Keeping it here, right
 *     below identity, is also where the User's own selected concept keeps it.
 */
export function MobileMenu({ open, onClose }: MobileMenuProps) {
  const { t } = useTranslation('common');
  const navigate = useNavigate();
  const { modules } = useNavigation();
  const activeModule = useActiveModule();
  const activeId = (activeModule?.id ?? null) as ModuleId | null;

  const user = useAuthStore((state) => state.user);
  const logout = useAuthStore((state) => state.logout);
  const name = user?.name ?? t(($) => $.userMenu.fallbackName);
  const email = user?.email ?? '';
  const role = t(($) => $.userMenu.role);

  async function handleLogout() {
    await logout();
    onClose();
    navigate(ROUTES.login, { replace: true });
  }

  // The launcher's rows are plain buttons/NavLinks (not all `<Link>`s, since a
  // row can resolve to either "open this module" or "go straight to its
  // page") — this performs the route change for the button-driven ones, then
  // closes the menu. NavLink-driven rows already navigate themselves.
  function handleNavigate(path: string) {
    navigate(path);
    onClose();
  }

  // Nothing to render for a company with zero authorized modules — avoids a
  // permanently-empty Drawer body for an edge-case account.
  const hasModules = modules.length > 0;

  return (
    <SheetPrimitive.Root open={open} onOpenChange={(next) => { if (!next) onClose(); }}>
      <SheetPortal>
        <SheetOverlay />
        <SheetPrimitive.Content
          aria-label={t(($) => $.nav.menu)}
          className={cn(
            'fixed inset-0 z-50 flex h-[100dvh] w-full flex-col bg-background md:hidden',
            'data-[state=open]:animate-in data-[state=closed]:animate-out',
            'data-[state=closed]:slide-out-to-bottom data-[state=open]:slide-in-from-bottom',
            'data-[state=closed]:duration-300 data-[state=open]:duration-500',
          )}
          onOpenAutoFocus={(e) => {
            // Land focus on the search field (the launcher's most useful
            // first action) instead of Radix's default of the first
            // focusable element (which would be the close button).
            e.preventDefault();
            document.getElementById('mobile-menu-search')?.focus();
          }}
        >
          <SheetPrimitive.Description className="sr-only">
            {t(($) => $.nav.menuDescription)}
          </SheetPrimitive.Description>

          {/* Header — title + close only; the profile card below already
              carries the "who/where" identity, so a duplicate brand mark here
              would compete with it rather than support it. */}
          <div className="flex h-12 shrink-0 items-center justify-between border-b px-4">
            <span className="text-sm font-semibold text-foreground">{t(($) => $.nav.menu)}</span>
            <SheetPrimitive.Close asChild>
              <Button variant="ghost" size="icon" aria-label={t(($) => $.nav.closeMenu)}>
                <X className="size-5" aria-hidden />
              </Button>
            </SheetPrimitive.Close>
          </div>

          {/* Profile identity + Company/Warehouse context — both card-like
              surfaces on a subtly-tinted backdrop, visually separating "who
              I am / where I am" from the scrollable module list below. */}
          <div className="shrink-0 border-b bg-muted/20 p-3">
            <div className="flex items-center gap-3 rounded-xl border bg-card p-3 shadow-sm">
              <Avatar className="size-11">
                <AvatarFallback className="text-sm font-bold">{getInitials(name)}</AvatarFallback>
              </Avatar>
              <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-semibold text-foreground">{name}</p>
                <p className="truncate text-xs text-muted-foreground">{email}</p>
              </div>
              <span className="shrink-0 rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-primary">
                {role}
              </span>
            </div>

            <div className="mt-2 rounded-xl border bg-card p-2.5 shadow-sm">
              <p className="mb-2 px-0.5 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                {t(($) => $.nav.companyWarehouse)}
              </p>
              <div className="flex flex-col gap-2">
                <CompanySwitcher showLabel className="w-full justify-start" />
                <WarehouseSwitcher showLabel className="w-full justify-start" />
              </div>
            </div>
          </div>

          {/* Body — the grouped, accordion-style navigation list */}
          <div className="flex-1 overflow-hidden">
            {hasModules ? (
              <MobileModulesLauncher activeModuleId={activeId} onNavigate={handleNavigate} />
            ) : (
              <p className="p-6 text-center text-sm text-muted-foreground">
                {t(($) => $.nav.noModuleMatches)}
              </p>
            )}
          </div>

          {/* Footer — Logout, always reachable without scrolling the list */}
          <div className="shrink-0 border-t p-3">
            <Button
              type="button"
              variant="ghost"
              onClick={() => void handleLogout()}
              className="w-full justify-start gap-2.5 text-destructive hover:bg-destructive/10 hover:text-destructive"
            >
              <LogOut className="size-4" aria-hidden />
              {t(($) => $.userMenu.logout)}
            </Button>
          </div>
        </SheetPrimitive.Content>
      </SheetPortal>
    </SheetPrimitive.Root>
  );
}
