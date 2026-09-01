import { useState } from 'react';
import { X } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';

import { BrandLogo } from '@/components/common/brand-logo';
import { Button } from '@/components/ui/button';
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
 * The Enterprise mobile navigation shell (TASK-ECOS-MOBILE-UX-COMPLETION-002,
 * parent design report §5 — full redesign, supersedes the flat single-open
 * accordion from TASK-ECOS-MOBILE-UX-CORE-CLOSURE-001 that the CTO rejected).
 *
 * Two views, not an accordion: a full-screen Modules launcher (grouped,
 * searchable, with a Recent row — `MobileModulesLauncher`) and a per-module
 * drill-in page list that restores desktop's section groupings
 * (`MobileModulePages`). The launcher is always the entry point — opening the
 * menu never guesses which module to auto-expand, and "Back" always returns to
 * it, so there is no lost/stale expand state to track across opens.
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

  if (!open) return null;

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
    <div
      role="dialog"
      aria-modal="true"
      aria-label={t(($) => $.nav.menu)}
      className="fixed inset-0 z-50 flex flex-col bg-background md:hidden"
    >
      {/* Header */}
      <div className="flex h-14 shrink-0 items-center justify-between border-b px-4">
        <BrandLogo />
        <Button variant="ghost" size="icon" onClick={onClose} aria-label={t(($) => $.nav.closeMenu)}>
          <X className="size-5" aria-hidden />
        </Button>
      </div>

      {/* Company + Warehouse context — mobile only. Stacked full-width rows with
          visible labels (design report Navigation Problem #2: the desktop
          switchers hide their name/code label below `sm`, so two side-by-side
          half-width buttons on mobile were unlabeled and indistinguishable). */}
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
    </div>
  );
}
