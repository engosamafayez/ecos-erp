import { useTranslation } from 'react-i18next';
import { NavLink } from 'react-router-dom';

import { cn } from '@/lib/utils';
import { MobilePageHeader } from '@/components/mobile';
import type { AppModule } from '@/config/module-navigation';
import { useRecentNav } from '@/hooks/use-recent-nav';
import { useNavLabel } from './use-nav-label';

type MobileModulePagesProps = {
  module: AppModule;
  onBack: () => void;
  onNavigate: (path: string) => void;
};

/**
 * Drill-in page list for one module (TASK-ECOS-MOBILE-UX-COMPLETION-002).
 *
 * Renders `module.items` directly — the SAME canonical metadata the desktop
 * `AppSidebar` renders, section dividers included. This is the fix for the
 * design report's Navigation Problem #1 (mobile silently dropped every
 * `isSection` divider via `moduleNavLinks()`, collapsing multi-section modules
 * into one flat undifferentiated list): the drill-in view groups items under
 * their section header exactly as desktop does, instead of filtering headers
 * out. No new data — same `AppModule.items` array, same RBAC-filtered set from
 * `useNavigation()` one level up.
 */
export function MobileModulePages({ module, onBack, onNavigate }: MobileModulePagesProps) {
  const { t } = useTranslation('common');
  const navLabel = useNavLabel();
  const { recordVisit } = useRecentNav();

  return (
    <div className="flex h-full flex-col">
      {/* The back affordance IS the breadcrumb: always returns to the Modules
          grid, never loses "where am I" context. */}
      <MobilePageHeader
        title={navLabel.group(module.id)}
        onBack={onBack}
        backLabel={t(($) => $.nav.backToModules)}
      />

      <nav
        aria-label={navLabel.group(module.id)}
        className="flex-1 overflow-y-auto p-3"
      >
        <div className="flex flex-col gap-1">
          {module.items.map((item) => {
            if (item.isSection) {
              return (
                <p
                  key={item.key}
                  className="mb-1.5 mt-5 px-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground first:mt-1"
                >
                  {navLabel.item(item.key)}
                </p>
              );
            }
            const Icon = item.icon;
            return (
              <NavLink
                key={item.key}
                to={item.path}
                onClick={() => {
                  recordVisit({ moduleId: module.id, itemKey: item.key, path: item.path });
                  onNavigate(item.path);
                }}
                className={({ isActive }) =>
                  cn(
                    'flex min-h-11 items-center gap-3 rounded-xl px-3 py-2.5 text-[15px] font-medium transition-all active:scale-[0.98]',
                    isActive
                      ? 'bg-primary text-primary-foreground shadow-sm'
                      : 'text-foreground hover:bg-accent',
                  )
                }
              >
                {({ isActive }) => (
                  <>
                    <span
                      className={cn(
                        'flex size-8 shrink-0 items-center justify-center rounded-lg',
                        isActive ? 'bg-primary-foreground/15' : 'bg-muted',
                      )}
                    >
                      <Icon className={cn('size-4', isActive ? 'text-primary-foreground' : 'text-muted-foreground')} aria-hidden />
                    </span>
                    <span className="min-w-0 flex-1 truncate">{navLabel.item(item.key)}</span>
                  </>
                )}
              </NavLink>
            );
          })}
        </div>
      </nav>
    </div>
  );
}
