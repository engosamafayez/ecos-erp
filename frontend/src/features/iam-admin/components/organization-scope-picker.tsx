import { useMemo, useState } from 'react';
import { Star } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { EmptyState, ErrorState, LoadingState, SearchInput } from '@/components/crud';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { cn } from '@/lib/utils';
import { useOrganizationDirectoryQuery } from '@/features/iam-admin/hooks/use-users';
import type { OrganizationEntity, OrganizationScopeAssignmentInput } from '@/features/iam-admin/types/user';

/**
 * The hierarchical Organization Scope picker
 * (TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001, §9).
 *
 * Replaces the manual "Type / ID / Label" workflow with real entity selection over the
 * CANONICAL organization hierarchy — Company → Brand → Branch → Warehouse → Region →
 * Channel → Team → Business Unit, read from `OrganizationScopeDirectory` (one query, every
 * level, server-scoped to the actor's own company unless they hold system authority).
 *
 * Requirements this satisfies directly:
 *   • search             — one search box, forwarded to the backend, filters every level
 *                           at once (name or code).
 *   • multi-select        — every level is an independent checklist; an administrator can
 *                           select several companies, several brands, several warehouses.
 *   • hierarchical combinations — each entity row shows the parent(s) it belongs to (e.g.
 *                           a Brand row shows its Company), resolved from the SAME response
 *                           rather than a second lookup, so a "company + selected brand(s)"
 *                           combination reads naturally without a nested tree widget.
 *   • never raw IDs       — the value this component edits is
 *                           `{ org_type, org_id, label }[]`, and `org_id`/`label` always
 *                           come from a selected row, never typed by hand.
 *
 * A level whose canonical table does not exist in this installation (`available: false`)
 * is never rendered — offering a picker for an entity this model does not have would be
 * exactly the confusion §9 is trying to remove.
 */
export function OrganizationScopePicker({
  value,
  onChange,
  readOnly = false,
}: {
  value: OrganizationScopeAssignmentInput[];
  onChange: (next: OrganizationScopeAssignmentInput[]) => void;
  readOnly?: boolean;
}) {
  const { t } = useTranslation('iam-admin');
  const [search, setSearch] = useState('');
  const query = useOrganizationDirectoryQuery(search);

  const selectedKeys = useMemo(
    () => new Set(value.map((a) => `${a.org_type}:${a.org_id ?? ''}`)),
    [value],
  );

  // A flat id -> display name map across every level, so a child row can show its parent's
  // NAME instead of a bare id, without a second request.
  const namesById = useMemo(() => {
    const map = new Map<string, string>();
    for (const level of query.data?.levels ?? []) {
      for (const entity of level.entities) {
        map.set(entity.id, entity.name);
      }
    }
    return map;
  }, [query.data]);

  function isSelected(type: string, id: string): boolean {
    return selectedKeys.has(`${type}:${id}`);
  }

  function isPrimary(type: string, id: string): boolean {
    return value.some((a) => a.org_type === type && a.org_id === id && a.primary);
  }

  function toggle(type: string, entity: OrganizationEntity) {
    if (readOnly) return;
    const key = `${type}:${entity.id}`;
    if (selectedKeys.has(key)) {
      onChange(value.filter((a) => !(a.org_type === type && a.org_id === entity.id)));
    } else {
      onChange([...value, { org_type: type, org_id: entity.id, label: entity.name, primary: false }]);
    }
  }

  function togglePrimary(type: string, id: string) {
    if (readOnly) return;
    onChange(
      value.map((a) =>
        a.org_type === type
          ? { ...a, primary: a.org_id === id ? !a.primary : false }
          : a,
      ),
    );
  }

  if (query.isLoading) return <LoadingState />;
  if (query.isError) {
    return <ErrorState description={query.error instanceof Error ? query.error.message : undefined} />;
  }

  const levels = (query.data?.levels ?? []).filter((level) => level.available);

  if (levels.length === 0) {
    return <EmptyState title={t(($) => $.users.organization.noLevelsAvailable)} />;
  }

  return (
    <div className="flex flex-col gap-3">
      <SearchInput onChange={setSearch} placeholder={t(($) => $.users.organization.searchPlaceholder)} />

      <div className="flex flex-col gap-3">
        {levels.map((level) => (
          <div key={level.type} className="rounded-md border">
            <div className="bg-muted/40 flex items-center gap-2 px-3 py-2">
              <span className="text-sm font-semibold">{level.label_ar}</span>
              <span className="text-muted-foreground text-xs">({level.label_en})</span>
              <span className="text-muted-foreground ms-auto text-xs tabular-nums">{level.total}</span>
            </div>

            {level.entities.length === 0 ? (
              <p className="text-muted-foreground px-3 py-3 text-xs">
                {t(($) => $.users.organization.noEntities)}
              </p>
            ) : (
              <div className="flex max-h-56 flex-col divide-y overflow-y-auto">
                {level.entities.map((entity) => {
                  const selected = isSelected(level.type, entity.id);
                  const primary = isPrimary(level.type, entity.id);
                  const parentNames = Object.entries(entity.parents)
                    .map(([, parentId]) => namesById.get(parentId))
                    .filter((name): name is string => Boolean(name));

                  return (
                    <label
                      key={entity.id}
                      className={cn(
                        'flex items-center gap-2 px-3 py-2 text-sm',
                        readOnly ? 'cursor-default' : 'cursor-pointer hover:bg-muted/40',
                      )}
                    >
                      <Checkbox
                        checked={selected}
                        disabled={readOnly}
                        onCheckedChange={() => toggle(level.type, entity)}
                      />
                      <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-1.5">
                          <span className="truncate font-medium">{entity.name}</span>
                          {entity.code ? (
                            <span className="text-muted-foreground font-mono text-[10px]">{entity.code}</span>
                          ) : null}
                        </div>
                        {parentNames.length > 0 ? (
                          <div className="mt-0.5 flex flex-wrap gap-1">
                            {parentNames.map((name) => (
                              <Badge key={name} variant="outline" className="text-[10px]">
                                {name}
                              </Badge>
                            ))}
                          </div>
                        ) : null}
                      </div>
                      {selected && !readOnly ? (
                        <button
                          type="button"
                          onClick={(e) => {
                            e.preventDefault();
                            togglePrimary(level.type, entity.id);
                          }}
                          title={t(($) => $.users.organization.primaryToggle)}
                          className={cn(
                            'shrink-0 rounded p-1',
                            primary ? 'text-amber-500' : 'text-muted-foreground/40 hover:text-muted-foreground',
                          )}
                        >
                          <Star className="size-3.5" fill={primary ? 'currentColor' : 'none'} />
                        </button>
                      ) : null}
                    </label>
                  );
                })}
              </div>
            )}
          </div>
        ))}
      </div>
    </div>
  );
}
