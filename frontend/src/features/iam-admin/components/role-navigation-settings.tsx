import { useMemo, useState } from 'react';
import { ChevronDown, ChevronRight, Eye, EyeOff, Minus } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { APP_MODULES, isNavItemVisible, visibleModuleItems, type ModuleNavLink } from '@/config/module-navigation';
import { useNavLabel } from '@/components/layout/use-nav-label';
import { useUpdateRoleNavigationMutation } from '@/features/iam-admin/hooks/use-roles';
import type { RoleDetail } from '@/features/iam-admin/types/role';

type OverrideState = 'inherit' | 'visible' | 'hidden';

function stateOf(overrides: Record<string, string>, key: string): OverrideState {
  return overrides[key] === 'visible' ? 'visible' : overrides[key] === 'hidden' ? 'hidden' : 'inherit';
}

/**
 * "القائمة والتنقل" (Menu and navigation) — per-Role nav item visibility settings
 * (User-review remediation, Batch 02, items I/12 and 13).
 *
 * Reuses `APP_MODULES` (the one canonical navigation registry) and the SAME
 * `isNavItemVisible`/`visibleModuleItems` functions the real sidebar calls — this component
 * does not compute visibility itself anywhere, it only edits the override map those
 * functions already know how to combine with a permission gate. There is no second
 * navigation model and no second gating algorithm here.
 *
 * CRITICAL SECURITY PROPERTY, restated for whoever edits this file next: this tab writes
 * to `roles.navigation_overrides`, a column no authorization check anywhere reads. Every
 * preview below is computed from the role's OWN `permissions` (already fetched with the
 * role) plus the pending overrides — it can only ever HIDE something the permission gate
 * would otherwise show, never grant access to anything.
 */
export function RoleNavigationSettings({ role }: { role: RoleDetail }) {
  const { t } = useTranslation('iam-admin');
  const navLabel = useNavLabel();
  const updateNavigation = useUpdateRoleNavigationMutation(role.id);

  const [pending, setPending] = useState<Record<string, string>>(role.navigation_overrides);
  const [expanded, setExpanded] = useState<Set<string>>(new Set());

  // Reset local edits when a different role's data arrives — same "adjusted during render"
  // pattern this codebase already uses (role-detail-drawer.tsx) rather than a useEffect.
  const [prevRole, setPrevRole] = useState(role);
  if (role !== prevRole) {
    setPrevRole(role);
    setPending(role.navigation_overrides);
  }

  const dirty = JSON.stringify(pending) !== JSON.stringify(role.navigation_overrides);

  // The role's OWN grant set decides the preview — never the acting administrator's.
  const rolePermissions = useMemo(() => new Set(role.permissions), [role.permissions]);
  const canForRole = (permission: string) => rolePermissions.has(permission);

  function setState(key: string, next: OverrideState) {
    setPending((prev) => {
      const copy = { ...prev };
      if (next === 'inherit') delete copy[key];
      else copy[key] = next;
      return copy;
    });
  }

  function toggleExpanded(moduleId: string) {
    setExpanded((prev) => {
      const next = new Set(prev);
      if (next.has(moduleId)) next.delete(moduleId);
      else next.add(moduleId);
      return next;
    });
  }

  return (
    <div className="flex flex-col gap-4">
      <p className="text-muted-foreground text-xs">{t(($) => $.roles.detail.navigation.hint)}</p>

      <div className="flex flex-col divide-y rounded-md border">
        {APP_MODULES.map((module) => {
          const links = module.items.filter((item): item is ModuleNavLink => !item.isSection);
          const overrideCount = links.filter((l) => pending[l.key] !== undefined).length;
          const isOpen = expanded.has(module.id) || overrideCount > 0;

          return (
            <div key={module.id}>
              <button
                type="button"
                onClick={() => toggleExpanded(module.id)}
                className="hover:bg-muted/40 flex w-full items-center gap-2 px-3 py-2 text-start"
              >
                {isOpen ? <ChevronDown className="size-4" /> : <ChevronRight className="size-4" />}
                <span className="text-sm font-medium">{navLabel.group(module.id)}</span>
                {overrideCount > 0 ? (
                  <span className="text-muted-foreground ms-auto text-xs">
                    {t(($) => $.roles.detail.navigation.overridesCount, { count: overrideCount })}
                  </span>
                ) : null}
              </button>

              {isOpen ? (
                <div className="flex flex-col gap-1 px-3 pb-3">
                  {links.map((link) => {
                    const state = stateOf(pending, link.key);
                    return (
                      <div key={link.key} className="flex items-center justify-between gap-2 py-1">
                        <span className="truncate text-sm">{navLabel.item(link.key)}</span>
                        <div className="flex shrink-0 gap-1">
                          <StateButton active={state === 'inherit'} onClick={() => setState(link.key, 'inherit')} label={t(($) => $.roles.detail.navigation.inherit)}>
                            <Minus className="size-3.5" />
                          </StateButton>
                          <StateButton active={state === 'visible'} onClick={() => setState(link.key, 'visible')} label={t(($) => $.roles.detail.navigation.visible)}>
                            <Eye className="size-3.5" />
                          </StateButton>
                          <StateButton active={state === 'hidden'} onClick={() => setState(link.key, 'hidden')} label={t(($) => $.roles.detail.navigation.hidden)}>
                            <EyeOff className="size-3.5" />
                          </StateButton>
                        </div>
                      </div>
                    );
                  })}
                </div>
              ) : null}
            </div>
          );
        })}
      </div>

      <div className="flex items-center justify-end border-t pt-3">
        <Button
          type="button"
          disabled={!dirty || updateNavigation.isPending}
          onClick={() => updateNavigation.mutate(pending)}
        >
          {updateNavigation.isPending ? t(($) => $.roles.detail.navigation.saving) : t(($) => $.roles.detail.navigation.save)}
        </Button>
      </div>

      <div className="rounded-md border">
        <div className="bg-muted/40 px-3 py-2 text-sm font-semibold">{t(($) => $.roles.detail.navigation.previewTitle)}</div>
        <div className="flex flex-col divide-y">
          {APP_MODULES.filter((module) => visibleModuleItems(module.items, canForRole, pending).length > 0).map((module) => (
            <div key={module.id} className="px-3 py-2">
              <p className="text-sm font-medium">{navLabel.group(module.id)}</p>
              <div className="mt-1 flex flex-wrap gap-1.5">
                {module.items
                  .filter((item): item is ModuleNavLink => !item.isSection && isNavItemVisible(item, canForRole, pending))
                  .map((link) => (
                    <span key={link.key} className="bg-secondary text-secondary-foreground rounded px-1.5 py-0.5 text-xs">
                      {navLabel.item(link.key)}
                    </span>
                  ))}
              </div>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}

function StateButton({
  active,
  onClick,
  label,
  children,
}: {
  active: boolean;
  onClick: () => void;
  label: string;
  children: React.ReactNode;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      title={label}
      aria-label={label}
      aria-pressed={active}
      className={cn(
        'rounded p-1.5',
        active ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted',
      )}
    >
      {children}
    </button>
  );
}
