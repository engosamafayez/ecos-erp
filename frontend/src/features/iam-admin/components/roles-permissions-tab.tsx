import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { EmptyState, EntityTable, ErrorState, PageHeader } from '@/components/crud';
import type { ColumnDef } from '@/components/crud/types';
import { Badge } from '@/components/ui/badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useRolesQuery } from '@/features/iam-admin/hooks/use-roles';
import type { RoleSummary } from '@/features/iam-admin/types/role';

import { PermissionCatalogBrowser } from './permission-catalog-browser';
import { RoleDetailDrawer } from './role-detail-drawer';

/**
 * §10 of the workspace instructions asks for "create custom role" / "add/remove permissions"
 * as if roles were directly editable — the report's §14 explains why that would violate the
 * canonical architecture (ADR-039/040 Decision 2: roles are compiled from Role Templates
 * only). This tab therefore stays read-only for Roles, with a clear pointer into Role
 * Templates for the actual authoring surface, and folds the Permission catalog (§11) in as
 * an internal sub-tab so nothing new appears in the top-level navigation.
 */
export function RolesPermissionsTab({ onOpenTemplate }: { onOpenTemplate: (templateKey: string) => void }) {
  const { t } = useTranslation('iam-admin');
  const query = useRolesQuery();
  const [selectedRoleId, setSelectedRoleId] = useState<string | null>(null);

  const columns = useMemo<ColumnDef<RoleSummary>[]>(
    () => [
      {
        key: 'name',
        header: t(($) => $.roles.columns.name),
        cell: (row) => (
          <button type="button" onClick={() => setSelectedRoleId(row.id)} className="font-medium hover:underline">
            {row.name}
          </button>
        ),
      },
      {
        key: 'type',
        header: t(($) => $.roles.columns.type),
        cell: (row) =>
          row.is_system ? (
            <Badge variant="outline">{t(($) => $.roles.systemBadge)}</Badge>
          ) : (
            <Badge variant="secondary">{t(($) => $.roles.customBadge)}</Badge>
          ),
      },
      {
        key: 'template',
        header: t(($) => $.roles.columns.template),
        cell: (row) => row.template?.name ?? '—',
      },
      {
        key: 'user_count',
        header: t(($) => $.roles.columns.userCount),
        cell: (row) => row.user_count ?? '—',
        align: 'right',
      },
    ],
    [t],
  );

  return (
    <div className="flex flex-col gap-4">
      <PageHeader title={t(($) => $.roles.title)} subtitle={t(($) => $.roles.subtitle)} />

      <Tabs defaultValue="roles">
        <TabsList>
          <TabsTrigger value="roles">{t(($) => $.roles.tabs.roles)}</TabsTrigger>
          <TabsTrigger value="permissions">{t(($) => $.roles.tabs.permissions)}</TabsTrigger>
        </TabsList>

        <TabsContent value="roles" className="pt-4">
          <EntityTable
            columns={columns}
            data={query.data ?? []}
            getRowId={(row) => row.id}
            isLoading={query.isLoading}
            isError={query.isError}
            errorState={<ErrorState description={query.error instanceof Error ? query.error.message : undefined} />}
            emptyState={<EmptyState title={t(($) => $.roles.empty)} />}
          />
        </TabsContent>

        <TabsContent value="permissions" className="pt-4">
          <PermissionCatalogBrowser />
        </TabsContent>
      </Tabs>

      <RoleDetailDrawer
        roleId={selectedRoleId}
        open={selectedRoleId !== null}
        onOpenChange={(open) => !open && setSelectedRoleId(null)}
        onOpenTemplate={(templateKey) => {
          setSelectedRoleId(null);
          onOpenTemplate(templateKey);
        }}
      />
    </div>
  );
}
