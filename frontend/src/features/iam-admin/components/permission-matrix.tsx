import { useMemo, useState } from 'react';
import { ChevronDown, ChevronRight, ShieldAlert, ShieldQuestion } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { EmptyState, ErrorState, LoadingState, SearchInput } from '@/components/crud';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { ScrollArea } from '@/components/ui/scroll-area';
import { cn } from '@/lib/utils';
import { usePermissionCatalogQuery } from '@/features/iam-admin/hooks/use-roles';
import type { PermissionEntry, PermissionGroupEntry, PermissionSensitivity } from '@/features/iam-admin/types/role';

/**
 * The editable, grouped Permission Matrix
 * (TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001, §14 + §16).
 *
 * ONE component, used by BOTH the Role permission editor and the Role Template
 * create/edit drawer (§16: "Use the SAME grouped editable Permission Matrix component as
 * Roles where practical") — so a fix to search, scrolling or grouping here fixes both
 * surfaces at once, and the two can never drift into inconsistent UX.
 *
 * Fixes the named defects directly:
 *   • search not working      → `SearchInput` filters every group's permissions AND hides
 *                                a group left with nothing, so a match is never buried
 *                                under 30 unrelated modules.
 *   • scrolling unusable       → ONE `ScrollArea` with a bounded `max-h`, not the drawer's
 *                                own overflow — the search bar and toolbar stay pinned
 *                                above it.
 *   • raw-key-only selection   → every row leads with the Arabic business label (§13);
 *                                the canonical key is a small `font-mono` secondary line.
 *   • large drawer/layout      → the whole matrix is a single flex column that fills its
 *                                container's height rather than growing it, so it drops
 *                                into a drawer, a dialog or a full page without redesign.
 *
 * `value`/`onChange` carry CANONICAL permission names only — the same tokens the backend
 * catalogue and RoleTemplateCompiler both use. This component performs no writes and
 * invents no token: it is a selection surface over `GET /iam/permissions`.
 */
export function PermissionMatrix({
  value,
  onChange,
  readOnly = false,
}: {
  value: string[];
  onChange: (next: string[]) => void;
  readOnly?: boolean;
}) {
  const { t } = useTranslation('iam-admin');
  const query = usePermissionCatalogQuery();
  const [search, setSearch] = useState('');
  const [sensitiveOnly, setSensitiveOnly] = useState(false);
  const [collapsed, setCollapsed] = useState<Set<string>>(new Set());

  const selected = useMemo(() => new Set(value), [value]);

  const filteredGroups = useMemo((): PermissionGroupEntry[] => {
    if (!query.data) return [];

    const term = search.trim().toLowerCase();

    return query.data.groups
      .map((group) => {
        const permissions = group.permissions.filter((permission) => {
          if (sensitiveOnly && permission.sensitivity === 'normal') return false;
          if (!term) return true;
          return (
            permission.label_ar.toLowerCase().includes(term) ||
            permission.label_en.toLowerCase().includes(term) ||
            permission.name.toLowerCase().includes(term) ||
            permission.description_ar.toLowerCase().includes(term)
          );
        });
        return { ...group, permissions };
      })
      .filter((group) => group.permissions.length > 0)
      .sort((a, b) => a.sort - b.sort);
  }, [query.data, search, sensitiveOnly]);

  const searching = search.trim().length > 0 || sensitiveOnly;

  function toggle(name: string) {
    if (readOnly) return;
    const next = new Set(selected);
    if (next.has(name)) next.delete(name);
    else next.add(name);
    onChange(Array.from(next));
  }

  function selectAllInGroup(group: PermissionGroupEntry) {
    if (readOnly) return;
    const next = new Set(selected);
    for (const p of group.permissions) next.add(p.name);
    onChange(Array.from(next));
  }

  function clearGroup(group: PermissionGroupEntry) {
    if (readOnly) return;
    const groupNames = new Set(group.permissions.map((p) => p.name));
    onChange(value.filter((name) => !groupNames.has(name)));
  }

  function toggleCollapsed(module: string) {
    setCollapsed((prev) => {
      const next = new Set(prev);
      if (next.has(module)) next.delete(module);
      else next.add(module);
      return next;
    });
  }

  if (query.isLoading) return <LoadingState />;
  if (query.isError) {
    return <ErrorState description={query.error instanceof Error ? query.error.message : undefined} />;
  }
  if (!query.data || query.data.total === 0) {
    return <EmptyState title={t(($) => $.permissions.empty)} />;
  }

  return (
    <div className="flex h-full min-h-0 flex-col gap-3">
      <div className="flex flex-wrap items-center gap-2">
        <div className="min-w-[220px] flex-1">
          <SearchInput onChange={setSearch} placeholder={t(($) => $.permissions.searchPlaceholder)} />
        </div>
        <Button
          type="button"
          variant={sensitiveOnly ? 'default' : 'outline'}
          size="sm"
          onClick={() => setSensitiveOnly((v) => !v)}
          className="gap-1.5"
        >
          <ShieldAlert className="size-3.5" />
          {t(($) => $.permissions.sensitiveOnly)}
        </Button>
        <span className="text-muted-foreground text-xs tabular-nums">
          {t(($) => $.permissions.selectedCount, { count: value.length })}
        </span>
      </div>

      {filteredGroups.length === 0 ? (
        <EmptyState title={t(($) => $.permissions.noMatches)} />
      ) : (
        <ScrollArea className="min-h-0 flex-1 max-h-[60vh] rounded-md border">
          <div className="flex flex-col divide-y">
            {filteredGroups.map((group) => {
              const isCollapsed = !searching && collapsed.has(group.module);
              const groupSelectedCount = group.permissions.filter((p) => selected.has(p.name)).length;
              const allSelected = groupSelectedCount === group.permissions.length;

              return (
                <div key={group.module}>
                  <div className="bg-muted/40 sticky top-0 z-10 flex items-center gap-2 px-3 py-2">
                    <button
                      type="button"
                      onClick={() => toggleCollapsed(group.module)}
                      className="text-muted-foreground flex items-center gap-1.5 hover:text-foreground"
                    >
                      {isCollapsed ? <ChevronRight className="size-4" /> : <ChevronDown className="size-4" />}
                      <span className="text-sm font-semibold">{group.label_ar}</span>
                      <span className="text-muted-foreground text-xs">({group.label_en})</span>
                    </button>

                    {group.sensitive_count > 0 ? (
                      <Badge variant="outline" className="gap-1 border-amber-400 text-amber-600 dark:text-amber-400">
                        <ShieldAlert className="size-3" />
                        {group.sensitive_count}
                      </Badge>
                    ) : null}

                    <span className="text-muted-foreground ms-auto text-xs tabular-nums">
                      {groupSelectedCount}/{group.permissions.length}
                    </span>

                    {!readOnly ? (
                      <div className="flex gap-1">
                        <Button
                          type="button"
                          variant="ghost"
                          size="sm"
                          className="h-7 px-2 text-xs"
                          disabled={allSelected}
                          onClick={() => selectAllInGroup(group)}
                        >
                          {t(($) => $.permissions.selectAll)}
                        </Button>
                        <Button
                          type="button"
                          variant="ghost"
                          size="sm"
                          className="h-7 px-2 text-xs"
                          disabled={groupSelectedCount === 0}
                          onClick={() => clearGroup(group)}
                        >
                          {t(($) => $.permissions.clearGroup)}
                        </Button>
                      </div>
                    ) : null}
                  </div>

                  {!isCollapsed ? (
                    <div className="grid gap-1 p-2 sm:grid-cols-2">
                      {group.permissions.map((permission) => (
                        <PermissionRow
                          key={permission.name}
                          permission={permission}
                          checked={selected.has(permission.name)}
                          readOnly={readOnly}
                          onToggle={() => toggle(permission.name)}
                        />
                      ))}
                    </div>
                  ) : null}
                </div>
              );
            })}
          </div>
        </ScrollArea>
      )}
    </div>
  );
}

function PermissionRow({
  permission,
  checked,
  readOnly,
  onToggle,
}: {
  permission: PermissionEntry;
  checked: boolean;
  readOnly: boolean;
  onToggle: () => void;
}) {
  const sensitivityStyle = sensitivityBadge(permission.sensitivity);

  return (
    <label
      className={cn(
        'flex items-start gap-2 rounded-md border px-2.5 py-2 text-start transition-colors',
        checked ? 'border-primary/40 bg-primary/5' : 'border-transparent hover:bg-muted/50',
        readOnly ? 'cursor-default' : 'cursor-pointer',
      )}
    >
      <Checkbox checked={checked} onCheckedChange={onToggle} disabled={readOnly} className="mt-0.5" />
      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-1.5">
          <span className="truncate text-sm font-medium">{permission.label_ar}</span>
          {sensitivityStyle ? (
            <Badge variant="outline" className={cn('gap-1 text-[10px]', sensitivityStyle.className)}>
              {sensitivityStyle.icon}
              {sensitivityStyle.label}
            </Badge>
          ) : null}
        </div>
        {permission.description_ar ? (
          <p className="text-muted-foreground mt-0.5 line-clamp-2 text-xs">{permission.description_ar}</p>
        ) : null}
        {/* Raw canonical key — secondary technical detail (§13), never the primary label. */}
        <p className="text-muted-foreground/70 mt-0.5 truncate font-mono text-[10px]">{permission.name}</p>
      </div>
    </label>
  );
}

function sensitivityBadge(
  level: PermissionSensitivity,
): { label: string; className: string; icon: React.ReactNode } | null {
  if (level === 'critical') {
    return {
      label: 'حرجة',
      className: 'border-red-400 text-red-600 dark:text-red-400',
      icon: <ShieldAlert className="size-3" />,
    };
  }
  if (level === 'elevated') {
    return {
      label: 'حساسة',
      className: 'border-amber-400 text-amber-600 dark:text-amber-400',
      icon: <ShieldQuestion className="size-3" />,
    };
  }
  return null;
}
