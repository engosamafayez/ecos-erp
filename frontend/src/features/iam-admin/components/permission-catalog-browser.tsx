import { useMemo, useState } from 'react';
import { ShieldAlert, ShieldQuestion } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { EmptyState, ErrorState, LoadingState, SearchInput } from '@/components/crud';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { usePermissionCatalogQuery } from '@/features/iam-admin/hooks/use-roles';

/**
 * The Permission Directory (TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001, §13).
 *
 * A read-only reference — permission ASSIGNMENT happens on the editable matrix
 * (Roles / Role Templates, §14/§16), which is the same catalogue presented as
 * selectable checkboxes instead of a browse list.
 *
 * §13 redesign: an administrator now sees the Arabic business name and description first,
 * grouped by business module, with a sensitivity indicator on anything elevated or
 * critical. The canonical key stays visible — "raw canonical permission key should appear
 * as secondary technical detail", never removed, only demoted.
 */
export function PermissionCatalogBrowser() {
  const { t } = useTranslation('iam-admin');
  const query = usePermissionCatalogQuery();
  const [search, setSearch] = useState('');

  const filteredGroups = useMemo(() => {
    if (!query.data) return [];
    const term = search.trim().toLowerCase();
    const groups = !term
      ? query.data.groups
      : query.data.groups
          .map((group) => ({
            ...group,
            permissions: group.permissions.filter(
              (permission) =>
                permission.label_ar.toLowerCase().includes(term) ||
                permission.label_en.toLowerCase().includes(term) ||
                permission.name.toLowerCase().includes(term) ||
                permission.description_ar.toLowerCase().includes(term),
            ),
          }))
          .filter((group) => group.permissions.length > 0);

    return [...groups].sort((a, b) => a.sort - b.sort);
  }, [query.data, search]);

  if (query.isLoading) return <LoadingState />;
  if (query.isError) {
    return <ErrorState description={query.error instanceof Error ? query.error.message : undefined} />;
  }
  if (!query.data || query.data.total === 0) {
    return <EmptyState title={t(($) => $.permissions.empty)} />;
  }

  return (
    <div className="flex flex-col gap-4">
      <SearchInput onChange={setSearch} placeholder={t(($) => $.permissions.searchPlaceholder)} />
      <div className="flex flex-col gap-4">
        {filteredGroups.map((group) => (
          <div key={group.module}>
            <h3 className="mb-2 flex items-center gap-2 text-sm font-semibold">
              <span>{group.label_ar}</span>
              <span className="text-muted-foreground text-xs font-normal uppercase tracking-wide">
                {group.label_en}
              </span>
              {group.sensitive_count > 0 ? (
                <Badge variant="outline" className="gap-1 border-amber-400 text-amber-600 dark:text-amber-400">
                  <ShieldAlert className="size-3" />
                  {group.sensitive_count}
                </Badge>
              ) : null}
            </h3>
            <div className="grid gap-2 sm:grid-cols-2">
              {group.permissions.map((permission) => (
                <div key={permission.name} className="rounded-md border px-3 py-2">
                  <div className="flex flex-wrap items-center gap-1.5">
                    <span className="text-sm font-medium">{permission.label_ar}</span>
                    {permission.sensitivity !== 'normal' ? (
                      <Badge
                        variant="outline"
                        className={cn(
                          'gap-1 text-[10px]',
                          permission.sensitivity === 'critical'
                            ? 'border-red-400 text-red-600 dark:text-red-400'
                            : 'border-amber-400 text-amber-600 dark:text-amber-400',
                        )}
                      >
                        <ShieldQuestion className="size-3" />
                        {permission.sensitivity === 'critical'
                          ? t(($) => $.permissions.criticalBadge)
                          : t(($) => $.permissions.elevatedBadge)}
                      </Badge>
                    ) : null}
                  </div>
                  {permission.description_ar ? (
                    <p className="text-muted-foreground mt-1 text-xs">{permission.description_ar}</p>
                  ) : null}
                  {/* Secondary technical detail (§13) — never the leading label. */}
                  <p className="text-muted-foreground/70 mt-1 font-mono text-[10px]">{permission.name}</p>
                </div>
              ))}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
