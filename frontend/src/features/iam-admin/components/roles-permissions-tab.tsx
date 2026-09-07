import { useMemo, useState } from 'react';
import { Copy, Plus } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { EmptyState, EntityTable, ErrorState, PageHeader } from '@/components/crud';
import { ActionMenu } from '@/components/crud/action-menu';
import type { ActionMenuItem, ColumnDef } from '@/components/crud/types';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Can } from '@/features/authorization';
import { useRolesQuery } from '@/features/iam-admin/hooks/use-roles';
import type { RoleSummary } from '@/features/iam-admin/types/role';

import { PermissionCatalogBrowser } from './permission-catalog-browser';
import { RoleCloneDialog } from './role-clone-dialog';
import { RoleCreateDrawer } from './role-create-drawer';
import { RoleDetailDrawer } from './role-detail-drawer';

/**
 * Roles & Permissions (TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001, §11 + §12 + §13 + §14).
 *
 * Formerly read-only, pointing at Role Templates for every change (ADR-039/040 Decision 2
 * — never violated, see role-detail-drawer.tsx's docblock). Now the primary Role lifecycle
 * surface: Create / Edit / Clone / Archive / Restore / Delete, plus the editable permission
 * matrix, all served by RoleAuthoringService through the one canonical compiler.
 *
 * `name_ar` / `description_ar` (§5/§12) lead the table for a role that is part of the
 * approved fourteen-role business catalogue; the canonical `name` stays the secondary line.
 */

/**
 * `onOpenTemplate` is reserved for a role's detail view deep-linking into its backing Role
 * Template (an is_system role's official ECOS profile) — not yet wired to an affordance,
 * since roles are now edited directly and template-viewing is a secondary path. Kept in the
 * signature so IamWorkspacePage's existing cross-tab wiring (tab switch + template focus)
 * needs no change when that affordance is added.
 */
export function RolesPermissionsTab({
  onOpenTemplate: _onOpenTemplate,
}: {
  onOpenTemplate: (templateKey: string) => void;
}) {
  const { t } = useTranslation('iam-admin');
  const { t: tCommon } = useTranslation('common');
  const [includeArchived, setIncludeArchived] = useState(false);
  const query = useRolesQuery(includeArchived);
  const [createOpen, setCreateOpen] = useState(false);
  const [selectedRoleId, setSelectedRoleId] = useState<string | null>(null);
  const [cloneTarget, setCloneTarget] = useState<{ id: string; name: string } | null>(null);

  const columns = useMemo<ColumnDef<RoleSummary>[]>(
    () => [
      {
        key: 'name',
        header: t(($) => $.roles.columns.name),
        cell: (row) => (
          <button type="button" onClick={() => setSelectedRoleId(row.id)} className="text-start hover:underline">
            <span className="block font-medium">{row.name_ar}</span>
            {row.name_ar !== row.name ? <span className="text-muted-foreground block text-xs">{row.name}</span> : null}
          </button>
        ),
      },
      {
        key: 'type',
        header: t(($) => $.roles.columns.type),
        cell: (row) => (
          <div className="flex flex-wrap gap-1">
            {row.is_system ? (
              <Badge variant="outline">{t(($) => $.roles.systemBadge)}</Badge>
            ) : (
              <Badge variant="secondary">{t(($) => $.roles.customBadge)}</Badge>
            )}
            {row.is_business_catalog ? (
              <Badge className="bg-primary/10 text-primary border-primary/30">{t(($) => $.roles.businessCatalogBadge)}</Badge>
            ) : null}
            {row.archived ? <Badge variant="destructive">{t(($) => $.roles.archivedBadge)}</Badge> : null}
          </div>
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
      <PageHeader
        title={t(($) => $.roles.title)}
        subtitle={t(($) => $.roles.subtitle)}
        actions={
          <Can permission="iam.roles.create">
            <button
              type="button"
              onClick={() => setCreateOpen(true)}
              className="bg-primary text-primary-foreground hover:bg-primary/90 inline-flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition-colors"
            >
              <Plus className="size-4" />
              {t(($) => $.roles.create.trigger)}
            </button>
          </Can>
        }
      />

      <Tabs defaultValue="roles">
        <TabsList>
          <TabsTrigger value="roles">{t(($) => $.roles.tabs.roles)}</TabsTrigger>
          <TabsTrigger value="permissions">{t(($) => $.roles.tabs.permissions)}</TabsTrigger>
        </TabsList>

        <TabsContent value="roles" className="flex flex-col gap-3 pt-4">
          <label className="flex w-fit items-center gap-2 text-sm">
            <Checkbox checked={includeArchived} onCheckedChange={setIncludeArchived} />
            {t(($) => $.roles.showArchived)}
          </label>

          <EntityTable
            columns={columns}
            data={query.data?.data ?? []}
            getRowId={(row) => row.id}
            isLoading={query.isLoading}
            isError={query.isError}
            errorState={<ErrorState description={query.error instanceof Error ? query.error.message : undefined} />}
            emptyState={<EmptyState title={t(($) => $.roles.empty)} />}
            rowActions={(row) => {
              const actions: ActionMenuItem[] = [
                { key: 'view', label: tCommon(($) => $.actions.view), onSelect: () => setSelectedRoleId(row.id) },
                {
                  key: 'clone',
                  label: t(($) => $.roles.cloneAction),
                  icon: Copy,
                  onSelect: () => setCloneTarget({ id: row.id, name: row.name }),
                },
              ];
              return <ActionMenu items={actions} />;
            }}
          />
        </TabsContent>

        <TabsContent value="permissions" className="pt-4">
          <PermissionCatalogBrowser />
        </TabsContent>
      </Tabs>

      <RoleCreateDrawer open={createOpen} onOpenChange={setCreateOpen} />
      <RoleDetailDrawer
        roleId={selectedRoleId}
        open={selectedRoleId !== null}
        onOpenChange={(open) => !open && setSelectedRoleId(null)}
        onClone={(role) => {
          setSelectedRoleId(null);
          setCloneTarget(role);
        }}
      />
      {cloneTarget ? (
        <RoleCloneDialog
          sourceId={cloneTarget.id}
          sourceName={cloneTarget.name}
          open
          onOpenChange={(open) => !open && setCloneTarget(null)}
        />
      ) : null}
    </div>
  );
}
