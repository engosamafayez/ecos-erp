import { useState } from 'react';
import { LogOut, Search, User as UserIcon, X } from 'lucide-react';
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
 *
 * TASK-ECOS-MOBILE-POST-DEV-UX-REVIEW-001 (§5) — the User reviewed the above
 * on DEV: the identity + company/warehouse block was permanently expanded and
 * too dominant, competing with the navigation list for the Drawer's limited
 * vertical space every time it opened. It is now a compact summary row
 * (avatar + name + role, one line) that expands ON DEMAND — collapsed by
 * default — to reveal email and the Company/Warehouse switchers underneath.
 * Nothing was removed: every field and control that existed before still
 * exists, one tap away, never buried behind a second screen or a nested menu.
 *
 * TASK-ECOS-MOBILE-MENU-NAVIGATION-FINAL-REMEDIATION-001 — three further,
 * narrow fixes from a fresh User review, none a redesign:
 *
 *   (1) SEARCH NO LONGER AUTOFOCUSES. The Drawer used to override Radix's
 *       `onOpenAutoFocus` to force focus (and therefore the software
 *       keyboard) straight into the search input on every open — see the old
 *       comment this replaced. That override is gone; opening the Drawer now
 *       leaves focus exactly where Radix's own Dialog behavior puts it
 *       (the first focusable header control), per the task's own instruction
 *       to trust "the existing accessibility library" rather than invent a
 *       replacement target. Search still works exactly as before — it is
 *       simply no longer forced open on every Drawer open (see (2)).
 *
 *   (2) The summary row (which was STILL an always-rendered, one-line block
 *       every time the Drawer opened, even collapsed) is replaced by a
 *       compact `User` icon here in the header, alongside a matching
 *       `Search` icon. Tapping either opens exactly the same content as
 *       before — this file's identity/Company/Warehouse panel, or the
 *       launcher's own search row+results — one tap away, nothing removed,
 *       now costing ZERO height until the User actually asks for either.
 *       `activePanel` replaces the old `contextExpanded` boolean with a
 *       three-way switch (`'search' | 'identity' | null`) so opening one
 *       compact panel closes the other rather than both trying to occupy the
 *       same limited vertical space at once.
 *
 *   (3) Recent is now capped at 3 (was 5) — see `mobile-modules-launcher.tsx`.
 *
 * TASK-ECOS-MOBILE-NAVIGATION-WORLD-CLASS-DESIGN-CLOSURE-002 — a visual-only
 * pass on top of the Bottom-Navigation-+-Fullscreen-Menu architecture and the
 * Task 001 interaction fixes above, neither of which changed: this Drawer is
 * still `inset-0`/`h-[100dvh]` (already fullscreen — nothing to restructure
 * there), `activePanel` still gates Search/Account exactly as Task 001 left
 * it. What changed is purely the identity panel's own surface treatment
 * (`rounded-2xl bg-muted/40`, no border/shadow) to match the flatter list
 * style now used throughout `mobile-modules-launcher.tsx`, and the title's
 * type scale (`text-base`, up from `text-sm`) for a clearer screen-title vs.
 * section-label hierarchy. See that file's own docblock for the primary
 * navigation list's redesign (the bulk of this task's visual work).
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

  // Neither panel is open by default — both the search row and the identity
  // card now cost zero height until the User taps their compact icon in the
  // header. A single three-way switch (rather than two independent booleans)
  // keeps the two mutually exclusive: opening one closes the other, so they
  // never compete for the Drawer's limited vertical space at once. Because
  // Radix unmounts `SheetPrimitive.Content` when the Drawer closes, this
  // resets to `null` on every close automatically — reopening never restores
  // a previously-open panel (or its focus) on its own, matching the task's
  // own "do not automatically restore search focus on reopen" requirement.
  const [activePanel, setActivePanel] = useState<'search' | 'identity' | null>(null);
  const toggleSearch = () => setActivePanel((p) => (p === 'search' ? null : 'search'));
  const toggleIdentity = () => setActivePanel((p) => (p === 'identity' ? null : 'identity'));

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
        >
          <SheetPrimitive.Description className="sr-only">
            {t(($) => $.nav.menuDescription)}
          </SheetPrimitive.Description>

          {/* Header — title, compact Search/Account actions, close. No
              `onOpenAutoFocus` override: focus lands wherever Radix's own
              Dialog behavior puts it (the first focusable control here),
              never forced into search. */}
          <div className="flex h-12 shrink-0 items-center justify-between gap-1 border-b px-2">
            {/* A screen TITLE, not a label — bumped a step above the section/
                route labels below it so the hierarchy in task §15 actually
                reads: title > section label > route label > metadata. The
                header itself stays exactly the same height (task §14/§17
                target the scrollable body's repeated rows, not this one
                fixed, single-instance chrome row). */}
            <span className="px-2 text-base font-semibold text-foreground">{t(($) => $.nav.menu)}</span>
            <div className="flex items-center gap-0.5">
              <Button
                type="button"
                variant="ghost"
                size="icon"
                aria-label={t(($) => $.nav.search)}
                aria-pressed={activePanel === 'search'}
                onClick={toggleSearch}
              >
                <Search className="size-5" aria-hidden />
              </Button>
              <Button
                type="button"
                variant="ghost"
                size="icon"
                aria-label={t(($) => $.userMenu.ariaLabel, { name })}
                aria-expanded={activePanel === 'identity'}
                onClick={toggleIdentity}
              >
                <UserIcon className="size-5" aria-hidden />
              </Button>
              <SheetPrimitive.Close asChild>
                <Button variant="ghost" size="icon" aria-label={t(($) => $.nav.closeMenu)}>
                  <X className="size-5" aria-hidden />
                </Button>
              </SheetPrimitive.Close>
            </div>
          </div>

          {/* Profile identity + Company/Warehouse context — revealed only by
              the Account icon above (§ FINAL-REMEDIATION-001); zero height
              the rest of the time. Same fields/controls as before nothing
              removed, just no longer an always-rendered summary row. */}
          {activePanel === 'identity' ? (
            <div className="shrink-0 border-b p-3">
              {/* Same flat, borderless treatment as the module list/search
                  results below (task §4/§24) — a tinted region, not a
                  bordered+shadowed card floating inside another container. */}
              <div className="flex items-center gap-3 rounded-2xl bg-muted/40 p-3 text-start">
                <Avatar className="size-9">
                  <AvatarFallback className="text-xs font-bold">{getInitials(name)}</AvatarFallback>
                </Avatar>
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-semibold text-foreground">{name}</p>
                  <p className="truncate text-[11px] text-muted-foreground">{role}</p>
                </div>
              </div>

              <div className="mt-2 flex flex-col gap-2">
                {email ? <p className="truncate px-1 text-xs text-muted-foreground">{email}</p> : null}
                <div className="rounded-2xl bg-muted/40 p-3">
                  <p className="mb-2 px-0.5 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                    {t(($) => $.nav.companyWarehouse)}
                  </p>
                  <div className="flex flex-col gap-2">
                    <CompanySwitcher showLabel className="w-full justify-start" />
                    <WarehouseSwitcher showLabel className="w-full justify-start" />
                  </div>
                </div>
              </div>
            </div>
          ) : null}

          {/* Body — the grouped, accordion-style navigation list */}
          <div className="flex-1 overflow-hidden">
            {hasModules ? (
              <MobileModulesLauncher
                activeModuleId={activeId}
                onNavigate={handleNavigate}
                searchOpen={activePanel === 'search'}
              />
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
