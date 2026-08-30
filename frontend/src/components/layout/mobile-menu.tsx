import { useState } from 'react';
import { ChevronDown, X } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { NavLink } from 'react-router-dom';

import { cn } from '@/lib/utils';
import { BrandLogo } from '@/components/common/brand-logo';
import { Button } from '@/components/ui/button';
import { useNavigation } from '@/features/authorization';
import { moduleNavLinks, type ModuleId } from '@/config/module-navigation';
import { useActiveModule } from '@/hooks/use-active-module';
import { CompanySwitcher } from '@/components/layout/header';
import { WarehouseSwitcher } from '@/components/layout/header';
import { useNavLabel } from './use-nav-label';

type MobileMenuProps = {
  open: boolean;
  onClose: () => void;
};

const ROW_BASE = 'flex items-center gap-3 rounded-xl border p-3.5 text-sm transition-colors';

/**
 * The primary Enterprise mobile menu — nested (accordion) module sub-navigation.
 *
 * Recovered for TASK-ECOS-MOBILE-UX-CORE-CLOSURE-001 from the pre-reconcile
 * preservation evidence (a3201ffd), grafted onto the CURRENT canonical
 * architecture — not a wholesale file restore.
 *
 * Modules come from the canonical RBAC authority `useNavigation()` (the SAME
 * source as the desktop rail), never raw `APP_MODULES`. A module with two or
 * more authorized child pages is an expandable accordion row: tapping it reveals
 * its children INLINE from the same canonical metadata (`moduleNavLinks(mod.items)`)
 * — the children are real tappable routes, not descriptive text. A module with a
 * single destination navigates directly to its `defaultPath` rather than showing
 * a one-item accordion. Single-open accordion: the active module is expanded when
 * the menu opens, opening another collapses it, and selecting a child navigates to
 * its own canonical route and closes the menu.
 *
 * Driver navigation is owned by DriverShell (Lane A) and is intentionally not
 * represented here — this menu is the enterprise shell only.
 */
export function MobileMenu({ open, onClose }: MobileMenuProps) {
  const { t } = useTranslation('common');
  const navLabel = useNavLabel();
  const { modules } = useNavigation();
  const activeModule = useActiveModule();
  const activeId = activeModule?.id ?? null;

  // Single-open accordion state. The active module is expanded whenever the menu
  // (re)opens and whenever the route's module changes — re-synced with React's
  // "adjust state during render" pattern (not an effect, so no extra commit),
  // which also clears any stale child state. Within a single open session the
  // user's manual expand/collapse is preserved.
  const [expandedId, setExpandedId] = useState<ModuleId | null>(activeId);
  const [syncKey, setSyncKey] = useState(`${open}|${activeId}`);
  const nextSyncKey = `${open}|${activeId}`;
  if (syncKey !== nextSyncKey) {
    setSyncKey(nextSyncKey);
    if (open) setExpandedId(activeId);
  }

  if (!open) return null;

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-label={t($ => $.nav.menu)}
      className="fixed inset-0 z-50 flex flex-col bg-background md:hidden"
    >
      {/* Header */}
      <div className="flex h-14 shrink-0 items-center justify-between border-b px-4">
        <BrandLogo />
        <Button variant="ghost" size="icon" onClick={onClose} aria-label={t($ => $.nav.closeMenu)}>
          <X className="size-5" aria-hidden />
        </Button>
      </div>

      {/* Company + Warehouse context — mobile only */}
      <div className="flex shrink-0 items-center gap-2 border-b bg-muted/30 px-4 py-3">
        <CompanySwitcher className="flex-1" />
        <WarehouseSwitcher className="flex-1" />
      </div>

      {/* Module list with inline nested (accordion) child navigation */}
      <div className="flex-1 overflow-y-auto p-4">
        <p className="mb-2 px-1 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
          {t($ => $.nav.workspaces)}
        </p>
        <div className="flex flex-col gap-1">
          {modules.map((mod) => {
            const Icon = mod.icon;
            const children = moduleNavLinks(mod.items);
            const label = navLabel.group(mod.id);

            // Single navigable destination → navigate straight to the module default
            // (no meaningless one-item accordion). Preserves the top-level default-path
            // behaviour for modules such as Dashboard.
            if (children.length < 2) {
              return (
                <NavLink
                  key={mod.id}
                  to={mod.defaultPath}
                  onClick={onClose}
                  className={({ isActive }) =>
                    cn(
                      ROW_BASE,
                      'font-medium',
                      isActive
                        ? 'border-primary bg-primary/5 text-primary'
                        : 'border-border bg-card text-foreground hover:border-primary/40 hover:bg-accent/40',
                    )
                  }
                >
                  <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10">
                    <Icon className="size-5 text-primary" aria-hidden />
                  </span>
                  <span className="min-w-0 flex-1 truncate font-semibold">{label}</span>
                </NavLink>
              );
            }

            const expanded = expandedId === mod.id;
            const isActiveModule = activeId === mod.id;
            return (
              <div key={mod.id} className="flex flex-col gap-1">
                <button
                  type="button"
                  onClick={() => setExpandedId((cur) => (cur === mod.id ? null : mod.id))}
                  aria-expanded={expanded}
                  aria-current={isActiveModule ? 'true' : undefined}
                  className={cn(
                    ROW_BASE,
                    'w-full text-start font-medium',
                    isActiveModule
                      ? 'border-primary/40 bg-primary/5 text-primary'
                      : 'border-border bg-card text-foreground hover:border-primary/40 hover:bg-accent/40',
                  )}
                >
                  <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10">
                    <Icon className="size-5 text-primary" aria-hidden />
                  </span>
                  <span className="min-w-0 flex-1 truncate font-semibold">{label}</span>
                  <ChevronDown
                    className={cn('size-4 shrink-0 transition-transform', !expanded && '-rotate-90')}
                    data-flip-rtl
                    aria-hidden
                  />
                </button>

                {expanded && (
                  <div className="flex flex-col gap-0.5 ps-4">
                    {children.map((child) => {
                      const ChildIcon = child.icon;
                      return (
                        <NavLink
                          key={child.key}
                          to={child.path}
                          onClick={onClose}
                          className={({ isActive }) =>
                            cn(
                              'flex items-center gap-2.5 rounded-lg border-s px-3 py-2.5 text-sm font-medium transition-colors',
                              isActive
                                ? 'bg-primary text-primary-foreground'
                                : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                            )
                          }
                        >
                          <ChildIcon className="size-4 shrink-0" aria-hidden />
                          <span className="truncate">{navLabel.item(child.key)}</span>
                        </NavLink>
                      );
                    })}
                  </div>
                )}
              </div>
            );
          })}
        </div>
      </div>
    </div>
  );
}
