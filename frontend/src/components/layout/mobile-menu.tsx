import { useState } from 'react';
import { X } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';

import { cn } from '@/lib/utils';
import { BrandLogo } from '@/components/common/brand-logo';
import { Button } from '@/components/ui/button';
import { SheetOverlay, SheetPortal, SheetPrimitive } from '@/components/ui/sheet';
import { useNavigation } from '@/features/authorization';
import type { ModuleId } from '@/config/module-navigation';
import { useActiveModule } from '@/hooks/use-active-module';
import { CompanySwitcher, WarehouseSwitcher } from '@/components/layout/header';
import { MobileModulesLauncher } from './mobile-modules-launcher';
import { MobileModulePages } from './mobile-module-pages';

type MobileMenuProps = {
  open: boolean;
  onClose: () => void;
};

/**
 * The Enterprise mobile navigation shell.
 *
 * TASK-ECOS-MOBILE-NAVIGATION-WORLD-CLASS-REDESIGN-004 — the structural
 * redesign from TASK-ECOS-MOBILE-UX-COMPLETION-002 (launcher + drill-in,
 * search, recents, restored section grouping) is preserved unchanged; this
 * pass replaces the raw `<div role="dialog">` wrapper with the SAME Radix
 * Dialog primitive `components/ui/sheet.tsx` already wraps for every other
 * drawer in the app (`SheetPortal`/`SheetOverlay`/`SheetPrimitive.Content`,
 * now exported for direct composition — see that file's comment). A
 * full-screen "cover" sheet is not one of `SheetContent`'s side variants, so
 * this composes the primitives directly rather than adding a mismatched
 * variant there.
 *
 * That swap is the single highest-leverage fix for this task's interaction
 * requirements — it is not decorative:
 *   - Escape closes the menu (previously no keyboard close existed at all).
 *   - Body scroll is locked while open (previously the page behind it could
 *     still scroll).
 *   - Focus is trapped inside the menu and returned to the triggering button
 *     on close (previously focus management didn't exist).
 *   - Tapping the backdrop closes the menu (a navigation menu has nothing to
 *     lose, so this is the expected, low-risk mobile pattern — not "accidental
 *     dismiss" of unsaved work).
 *   - Open/close now animate (slide-up-from-bottom + backdrop fade) instead of
 *     an instant mount/unmount — the same animation vocabulary
 *     (`data-[state=open]:animate-in` / `slide-in-from-bottom`) every other
 *     Sheet-based drawer in the app already uses, so it reads as one
 *     consistent motion language, not a bespoke one.
 * All of this is inherited from Radix's Dialog behavior — none of it is
 * hand-rolled here.
 *
 * Two views, not an accordion: a full-screen Modules launcher (grouped,
 * searchable, with a Recent row — `MobileModulesLauncher`) and a per-module
 * drill-in page list that restores desktop's section groupings
 * (`MobileModulePages`). The launcher is always the entry point — opening the
 * menu never guesses which module to auto-expand, and "Back" always returns
 * to it, so there is no lost/stale expand state to track across opens.
 *
 * Modules and pages still come exclusively from `useNavigation()` /
 * `AppModule.items` — the SAME canonical RBAC authority the desktop rail and
 * sidebar consume. This shell adds no visibility rule, no new module, and no
 * new route; it only re-presents the same authorized data.
 *
 * Driver navigation is owned by DriverShell (Lane A) and is intentionally not
 * represented here — this menu is the enterprise shell only.
 */
export function MobileMenu({ open, onClose }: MobileMenuProps) {
  const { t } = useTranslation('common');
  const navigate = useNavigate();
  const { modules } = useNavigation();
  const activeModule = useActiveModule();
  const activeId = (activeModule?.id ?? null) as ModuleId | null;

  const [openModuleId, setOpenModuleId] = useState<ModuleId | null>(null);

  // Re-sync to the launcher every time the menu (re)opens — "adjust state
  // during render" so there is no extra commit, and no stale drill-in view
  // left over from the previous time the menu was opened.
  const [syncKey, setSyncKey] = useState(open);
  if (syncKey !== open) {
    setSyncKey(open);
    if (open) setOpenModuleId(null);
  }

  const openModule = modules.find((m) => m.id === openModuleId);

  // The launcher's tiles/recent/search results are plain buttons (not
  // `<Link>`s, since a single row can resolve to either "open this module" or
  // "go straight to its page") — so THIS is what actually performs the route
  // change for them, then closes the menu.
  function handleLauncherNavigate(path: string) {
    navigate(path);
    onClose();
  }

  // The drill-in list uses real `<NavLink>`s, which already perform the route
  // change themselves — this only needs to close the menu afterwards.
  function handleDrillInNavigate() {
    onClose();
  }

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

          {/* Header */}
          <div className="flex h-14 shrink-0 items-center justify-between border-b px-4">
            <BrandLogo />
            <SheetPrimitive.Close asChild>
              <Button variant="ghost" size="icon" aria-label={t(($) => $.nav.closeMenu)}>
                <X className="size-5" aria-hidden />
              </Button>
            </SheetPrimitive.Close>
          </div>

          {/* Company + Warehouse context — mobile only. Stacked full-width rows with
              visible labels (Navigation Problem #2: the desktop switchers hide their
              name/code label below `sm`, so two side-by-side half-width buttons on
              mobile were unlabeled and indistinguishable). */}
          <div className="shrink-0 border-b bg-muted/30 px-4 py-3">
            <p className="mb-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
              {t(($) => $.nav.companyWarehouse)}
            </p>
            <div className="flex flex-col gap-2">
              <CompanySwitcher showLabel className="w-full justify-start" />
              <WarehouseSwitcher showLabel className="w-full justify-start" />
            </div>
          </div>

          {/* Body — Modules launcher or a module's drill-in page list */}
          <div className="flex-1 overflow-hidden">
            {openModule ? (
              <MobileModulePages
                module={openModule}
                onBack={() => setOpenModuleId(null)}
                onNavigate={handleDrillInNavigate}
              />
            ) : (
              <MobileModulesLauncher
                activeModuleId={activeId}
                onOpenModule={setOpenModuleId}
                onNavigate={handleLauncherNavigate}
              />
            )}
          </div>
        </SheetPrimitive.Content>
      </SheetPortal>
    </SheetPrimitive.Root>
  );
}
