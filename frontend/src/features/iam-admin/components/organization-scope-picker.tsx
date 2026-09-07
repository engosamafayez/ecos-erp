import { useEffect, useMemo, useRef, useState } from 'react';
import { Star } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { EmptyState, ErrorState, LoadingState } from '@/components/crud';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { useOrganizationDirectoryQuery } from '@/features/iam-admin/hooks/use-users';
import type { OrganizationEntity, OrganizationLevel, OrganizationScopeAssignmentInput } from '@/features/iam-admin/types/user';

/**
 * The hierarchical, CASCADING Organization Scope picker
 * (User-review remediation, Batch 02, item D — replacing the prior flat, all-levels-
 * at-once design TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001 §9 originally shipped).
 *
 * The tier structure below is not a second, hand-authored hierarchy: every level's
 * `parents` map comes straight from `OrganizationScopeDirectory::SOURCES` (backend), which
 * itself reflects the actual foreign keys on `companies` / `brands` / `branches` /
 * `warehouses` / `network_dispatch_regions` / `channels` / `teams` / `business_accounts`.
 * A level whose ONLY declared parent is `company` is a direct child, shown as soon as a
 * company is selected; a level with any OTHER parent (`region` → warehouse/branch;
 * `channel` → brand/business_unit) is a DEPENDENT level, rendered only once — and nested
 * under — whichever of ITS OWN parent entities the administrator has already selected.
 * Nothing here assumes a relationship the backend didn't already report.
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
  // Fetched once, unfiltered — progressive disclosure already keeps each visible tier small,
  // so search happens CLIENT-SIDE per tier (below) rather than re-querying the server and
  // fighting the cascade on every keystroke.
  const query = useOrganizationDirectoryQuery('');

  const selectedKeys = useMemo(
    () => new Set(value.map((a) => `${a.org_type}:${a.org_id ?? ''}`)),
    [value],
  );

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
    onChange(value.map((a) => (a.org_type === type ? { ...a, primary: a.org_id === id ? !a.primary : false } : a)));
  }

  const levels = useMemo(() => (query.data?.levels ?? []).filter((l) => l.available), [query.data]);
  const companyLevel = levels.find((l) => l.type === 'company');
  const rootChildren = levels.filter(
    (l) => l.type !== 'company' && Object.keys(l.parents).every((p) => p === 'company'),
  );
  const dependentLevels = levels.filter(
    (l) => l.type !== 'company' && !rootChildren.some((r) => r.type === l.type),
  );

  // §9's "if only one Company is available and ownership is already fixed, display it
  // clearly rather than making the user browse a redundant list" — a tenant-scoped actor's
  // own hierarchy() call is already narrowed server-side to their one company, so this is
  // the simple, data-driven signal for "fixed": exactly one row came back.
  const companyFixed = (companyLevel?.entities.length ?? 0) <= 1;
  const selectedCompanyIds = value.filter((a) => a.org_type === 'company').map((a) => a.org_id).filter(Boolean) as string[];
  const effectiveCompanyIds = companyFixed
    ? (companyLevel?.entities.map((e) => e.id) ?? [])
    : selectedCompanyIds;

  // "Changing a parent must clear invalid child selections": prune any held assignment whose
  // declared parent(s) are no longer among the currently selected/effective parent ids. This
  // reconciles an EXTERNAL prop (`value`) against the directory, so it belongs in an effect,
  // not a render-time onChange call.
  const lastPruned = useRef<string>('');
  useEffect(() => {
    if (readOnly || levels.length === 0) return;
    const levelByType = new Map(levels.map((l) => [l.type, l]));
    const validCompany = new Set(effectiveCompanyIds);

    const pruned = value.filter((a) => {
      if (a.org_type === 'company') return companyFixed ? true : validCompany.has(a.org_id ?? '');
      const level = levelByType.get(a.org_type);
      if (level === undefined) return true; // a free-form type this directory doesn't govern
      const entity = level.entities.find((e) => e.id === a.org_id);
      if (entity === undefined) return true; // not loaded yet — don't drop on a transient state
      return Object.entries(entity.parents).every(([parentType, parentId]) => {
        if (parentType === 'company') return validCompany.has(parentId);
        return value.some((held) => held.org_type === parentType && held.org_id === parentId);
      });
    });

    const key = pruned.map((a) => `${a.org_type}:${a.org_id}`).sort().join('|');
    if (pruned.length !== value.length && key !== lastPruned.current) {
      lastPruned.current = key;
      onChange(pruned);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps -- reconciling against `levels`/company selection; including `value`/`onChange` would re-fire every render
  }, [levels, effectiveCompanyIds.join('|'), companyFixed, readOnly]);

  if (query.isLoading) return <LoadingState />;
  if (query.isError) {
    return <ErrorState description={query.error instanceof Error ? query.error.message : undefined} />;
  }
  if (levels.length === 0) {
    return <EmptyState title={t(($) => $.users.organization.noLevelsAvailable)} />;
  }

  return (
    <div className="flex flex-col gap-3">
      <TierSection
        level={companyLevel}
        entities={companyLevel?.entities ?? []}
        fixed={companyFixed}
        isSelected={isSelected}
        isPrimary={isPrimary}
        toggle={toggle}
        togglePrimary={togglePrimary}
        readOnly={readOnly}
      />

      {effectiveCompanyIds.length === 0 ? (
        <p className="text-muted-foreground text-xs">{t(($) => $.users.organization.selectCompanyHint)}</p>
      ) : (
        <>
          {rootChildren.map((level) => (
            <TierSection
              key={level.type}
              level={level}
              entities={level.entities.filter((e) => effectiveCompanyIds.includes(e.parents.company ?? ''))}
              isSelected={isSelected}
              isPrimary={isPrimary}
              toggle={toggle}
              togglePrimary={togglePrimary}
              readOnly={readOnly}
            />
          ))}

          {dependentLevels.map((level) => {
            const parentTypes = Object.keys(level.parents);
            const contexts = parentTypes.flatMap((parentType) =>
              value
                .filter((a) => a.org_type === parentType)
                .map((a) => ({ parentType, parentId: a.org_id ?? '', parentName: a.label })),
            );
            if (contexts.length === 0) return null; // hidden until its required parent is selected

            return (
              <div key={level.type} className="flex flex-col gap-2 ps-4">
                {contexts.map((ctx) => (
                  <TierSection
                    key={`${level.type}:${ctx.parentType}:${ctx.parentId}`}
                    level={level}
                    entities={level.entities.filter((e) => e.parents[ctx.parentType] === ctx.parentId)}
                    nestedUnder={ctx.parentName}
                    isSelected={isSelected}
                    isPrimary={isPrimary}
                    toggle={toggle}
                    togglePrimary={togglePrimary}
                    readOnly={readOnly}
                  />
                ))}
              </div>
            );
          })}
        </>
      )}
    </div>
  );
}

/** One collapsible-by-nature level section — company, a root child, or one dependent context. */
function TierSection({
  level,
  entities,
  fixed = false,
  nestedUnder,
  isSelected,
  isPrimary,
  toggle,
  togglePrimary,
  readOnly,
}: {
  level: OrganizationLevel | undefined;
  entities: OrganizationEntity[];
  fixed?: boolean;
  nestedUnder?: string;
  isSelected: (type: string, id: string) => boolean;
  isPrimary: (type: string, id: string) => boolean;
  toggle: (type: string, entity: OrganizationEntity) => void;
  togglePrimary: (type: string, id: string) => void;
  readOnly: boolean;
}) {
  const { t } = useTranslation('iam-admin');
  const [search, setSearch] = useState('');
  if (level === undefined) return null;

  const filtered = search
    ? entities.filter(
        (e) =>
          e.name.toLowerCase().includes(search.toLowerCase()) ||
          (e.code ?? '').toLowerCase().includes(search.toLowerCase()),
      )
    : entities;

  return (
    <div className="rounded-md border">
      <div className="bg-muted/40 flex items-center gap-2 px-3 py-2">
        <span className="text-sm font-semibold">{level.label_ar}</span>
        <span className="text-muted-foreground text-xs">({level.label_en})</span>
        {nestedUnder ? (
          <span className="text-muted-foreground text-xs">
            {/* e.g. "under Acme Brand" — reads naturally in both languages since it is just the parent's own name */}
            — {nestedUnder}
          </span>
        ) : null}
        <span className="text-muted-foreground ms-auto text-xs tabular-nums">{entities.length}</span>
      </div>

      {fixed ? (
        <div className="flex flex-wrap gap-1.5 px-3 py-2">
          {entities.map((entity) => (
            <Badge key={entity.id} variant="secondary">
              {entity.name}
            </Badge>
          ))}
        </div>
      ) : (
        <>
          {entities.length > 6 ? (
            <div className="border-b px-2 py-1.5">
              <Input
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder={t(($) => $.users.organization.searchPlaceholder)}
                className="h-7 text-xs"
              />
            </div>
          ) : null}

          {filtered.length === 0 ? (
            <p className="text-muted-foreground px-3 py-3 text-xs">{t(($) => $.users.organization.noEntities)}</p>
          ) : (
            <div className="flex max-h-56 flex-col divide-y overflow-y-auto">
              {filtered.map((entity) => {
                const selected = isSelected(level.type, entity.id);
                const primary = isPrimary(level.type, entity.id);

                return (
                  <label
                    key={entity.id}
                    className={cn(
                      'flex items-center gap-2 px-3 py-2 text-sm',
                      readOnly ? 'cursor-default' : 'cursor-pointer hover:bg-muted/40',
                    )}
                  >
                    <Checkbox checked={selected} disabled={readOnly} onCheckedChange={() => toggle(level.type, entity)} />
                    <div className="min-w-0 flex-1">
                      <span className="truncate font-medium">{entity.name}</span>
                      {entity.code ? (
                        <span className="text-muted-foreground ms-1.5 font-mono text-[10px]">{entity.code}</span>
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
        </>
      )}
    </div>
  );
}
