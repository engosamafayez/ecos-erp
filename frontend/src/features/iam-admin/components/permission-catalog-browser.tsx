import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { EmptyState, ErrorState, LoadingState, SearchInput } from '@/components/crud';
import { Badge } from '@/components/ui/badge';
import { usePermissionCatalogQuery } from '@/features/iam-admin/hooks/use-roles';

/**
 * §11: permissions organized by domain/module (the group_id-based taxonomy is still fully
 * inert per Task 1/2's own findings — grouping here follows the same near-term approach the
 * architecture report recommended: the existing `module` prefix on each permission's own
 * name). Tokens stay visible for administrative clarity (§11: "do not obscure the canonical
 * token entirely") — this is a read-only reference, not a permission-assignment surface;
 * assignment happens by editing a Role Template's definition (§8/§10).
 */
export function PermissionCatalogBrowser() {
  const { t } = useTranslation('iam-admin');
  const query = usePermissionCatalogQuery();
  const [search, setSearch] = useState('');

  const filteredGroups = useMemo(() => {
    if (!query.data) return [];
    const term = search.trim().toLowerCase();
    if (!term) return query.data.groups;
    return query.data.groups
      .map((group) => ({
        ...group,
        permissions: group.permissions.filter(
          (permission) =>
            permission.name.toLowerCase().includes(term) ||
            (permission.description ?? '').toLowerCase().includes(term),
        ),
      }))
      .filter((group) => group.permissions.length > 0);
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
            <h3 className="mb-2 text-sm font-semibold uppercase tracking-wide text-muted-foreground">
              {group.module}
            </h3>
            <div className="grid gap-2 sm:grid-cols-2">
              {group.permissions.map((permission) => (
                <div key={permission.name} className="rounded-md border px-3 py-2">
                  <div className="flex items-center gap-2">
                    <Badge variant="outline" className="font-mono text-xs">
                      {permission.name}
                    </Badge>
                  </div>
                  {permission.description ? (
                    <p className="text-muted-foreground mt-1 text-xs">{permission.description}</p>
                  ) : null}
                </div>
              ))}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
