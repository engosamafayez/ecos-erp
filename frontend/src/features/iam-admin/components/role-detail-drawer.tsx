import { useState } from 'react';
import axios from 'axios';
import { useTranslation } from 'react-i18next';

import { EmptyState, ConfirmDialog, EntityDrawer, ErrorState, LoadingState } from '@/components/crud';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { FormField } from '@/components/ui/forms/form-field';
import { Can } from '@/features/authorization';
import {
  useArchiveRoleMutation,
  useDeleteRoleMutation,
  useRestoreRoleMutation,
  useRoleQuery,
  useUpdateRoleMutation,
  useUpdateRolePermissionsMutation,
} from '@/features/iam-admin/hooks/use-roles';
import type { RoleDetail, UpdateRolePayload } from '@/features/iam-admin/types/role';

import { PermissionMatrix } from './permission-matrix';
import { RoleNavigationSettings } from './role-navigation-settings';
import { UserStatusBadge } from './user-status-badge';

/**
 * Role detail / editor (TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001, §11 + §14).
 *
 * Previously read-only over compiled permissions, pointing at Role Templates for any
 * change. Now the primary editing surface: metadata and the permission matrix save
 * directly, through RoleAuthoringService → the one canonical RoleTemplateCompiler — no
 * `role_permissions` write happens anywhere in this component (see roles-service.ts).
 *
 * `editable` is server-computed (RoleController::isEditable()) and drives EVERYTHING here:
 * a system role or a role backed by an immutable ECOS system template still gets View +
 * Clone, matching §15, with an explicit banner explaining why and a Clone action instead
 * of disabled-looking inputs.
 */
export function RoleDetailDrawer({
  roleId,
  open,
  onOpenChange,
  onClone,
}: {
  roleId: string | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onClone: (role: { id: string; name: string }) => void;
}) {
  const { t } = useTranslation('iam-admin');
  const query = useRoleQuery(roleId);

  return (
    <EntityDrawer open={open} onOpenChange={onOpenChange} title={query.data?.name_ar ?? t(($) => $.roles.detail.title)}>
      {query.isLoading ? (
        <LoadingState />
      ) : query.isError ? (
        <ErrorState description={query.error instanceof Error ? query.error.message : undefined} />
      ) : query.data ? (
        <RoleDetailContent
          role={query.data}
          onDeleted={() => onOpenChange(false)}
          onClone={() => query.data && onClone({ id: query.data.id, name: query.data.name })}
        />
      ) : null}
    </EntityDrawer>
  );
}

function RoleDetailContent({
  role,
  onDeleted,
  onClone,
}: {
  role: RoleDetail;
  onDeleted: () => void;
  onClone: () => void;
}) {
  const { t } = useTranslation('iam-admin');
  const { t: tCommon } = useTranslation('common');
  const updateRole = useUpdateRoleMutation(role.id);
  const updatePermissions = useUpdateRolePermissionsMutation(role.id);
  const archiveRole = useArchiveRoleMutation(role.id);
  const restoreRole = useRestoreRoleMutation(role.id);
  const deleteRole = useDeleteRoleMutation();

  const [values, setValues] = useState<UpdateRolePayload>({ name: role.name, description: role.description ?? '' });
  const [permissions, setPermissions] = useState<string[]>(role.permissions);
  const [serverError, setServerError] = useState<string | null>(null);
  const [archiveOpen, setArchiveOpen] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [archiveReason, setArchiveReason] = useState('');

  // Keeps local state in sync when `role` changes identity (refetch, save, switching rows)
  // — adjusted during render (React's documented pattern), not a useEffect.
  const [prevRole, setPrevRole] = useState(role);
  if (role !== prevRole) {
    setPrevRole(role);
    setValues({ name: role.name, description: role.description ?? '' });
    setPermissions(role.permissions);
  }

  const editable = role.editable;
  const permissionsDirty = JSON.stringify([...permissions].sort()) !== JSON.stringify([...role.permissions].sort());

  function reportError(error: unknown) {
    setServerError(
      axios.isAxiosError(error) && typeof error.response?.data?.message === 'string'
        ? error.response.data.message
        : t(($) => $.roles.form.genericError),
    );
  }

  function handleSaveMetadata() {
    setServerError(null);
    updateRole.mutate(values, { onError: reportError });
  }

  function handleSavePermissions() {
    setServerError(null);
    updatePermissions.mutate(permissions, { onError: reportError });
  }

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center gap-2">
        {role.is_system ? (
          <Badge variant="outline">{t(($) => $.roles.systemBadge)}</Badge>
        ) : (
          <Badge variant="secondary">{t(($) => $.roles.customBadge)}</Badge>
        )}
        {role.is_business_catalog ? (
          <Badge className="bg-primary/10 text-primary border-primary/30">{t(($) => $.roles.businessCatalogBadge)}</Badge>
        ) : null}
        {role.archived ? <Badge variant="destructive">{t(($) => $.roles.archivedBadge)}</Badge> : null}
        {role.user_count !== null ? (
          <span className="text-muted-foreground text-xs">
            {t(($) => $.roles.detail.userCount, { count: role.user_count })}
          </span>
        ) : null}
      </div>

      {role.description_ar ? <p className="text-muted-foreground text-sm">{role.description_ar}</p> : null}

      {!editable ? (
        <Alert>
          <AlertDescription>
            {role.is_system ? t(($) => $.roles.detail.systemImmutable) : t(($) => $.roles.detail.templateImmutable)}
          </AlertDescription>
        </Alert>
      ) : null}

      {role.archived ? (
        <Alert>
          <AlertDescription>
            {t(($) => $.roles.detail.archivedNotice)}
            {role.archived_reason ? ` — ${role.archived_reason}` : ''}
          </AlertDescription>
        </Alert>
      ) : null}

      {serverError ? (
        <Alert variant="destructive">
          <AlertTitle>{t(($) => $.roles.form.genericError)}</AlertTitle>
          <AlertDescription>{serverError}</AlertDescription>
        </Alert>
      ) : null}

      <Tabs defaultValue="permissions">
        <TabsList>
          <TabsTrigger value="overview">{t(($) => $.roles.detail.tabs.overview)}</TabsTrigger>
          <TabsTrigger value="permissions">{t(($) => $.roles.detail.tabs.permissions)}</TabsTrigger>
          <TabsTrigger value="users">
            {t(($) => $.roles.detail.tabs.users)}
            {role.user_count !== null ? ` (${role.user_count})` : ''}
          </TabsTrigger>
          {!role.is_system ? (
            <TabsTrigger value="navigation">{t(($) => $.roles.detail.tabs.navigation)}</TabsTrigger>
          ) : null}
        </TabsList>

        <TabsContent value="overview" className="flex flex-col gap-4 pt-4">
          <fieldset disabled={!editable} className="contents">
            <FormField name="name" label={t(($) => $.roles.fields.name)} required>
              <Input value={values.name ?? ''} onChange={(e) => setValues((v) => ({ ...v, name: e.target.value }))} />
            </FormField>
            <FormField name="description" label={t(($) => $.roles.fields.description)} optional>
              <Textarea
                value={values.description ?? ''}
                onChange={(e) => setValues((v) => ({ ...v, description: e.target.value }))}
              />
            </FormField>
          </fieldset>

          {role.scope_expectation.length > 0 ? (
            <div>
              <p className="text-muted-foreground mb-1 text-xs">{t(($) => $.roles.detail.scopeExpectation)}</p>
              <div className="flex flex-wrap gap-1">
                {role.scope_expectation.map((type) => (
                  <Badge key={type} variant="outline" className="text-xs">
                    {type}
                  </Badge>
                ))}
              </div>
            </div>
          ) : null}

          {role.assigned_users.length > 0 ? (
            <div>
              <p className="text-muted-foreground mb-1 text-xs">{t(($) => $.roles.detail.assignedUsers)}</p>
              <div className="flex max-h-40 flex-col gap-1 overflow-y-auto">
                {role.assigned_users.map((u) => (
                  <div key={u.id} className="flex items-center justify-between rounded-md border px-2 py-1 text-xs">
                    <span>{u.name}</span>
                    <span className="text-muted-foreground">{u.email}</span>
                  </div>
                ))}
              </div>
            </div>
          ) : null}

          <div className="flex flex-wrap items-center justify-between gap-2 border-t pt-4">
            {editable ? (
              <Can permission="iam.roles.update">
                <Button type="button" onClick={handleSaveMetadata} disabled={updateRole.isPending}>
                  {updateRole.isPending ? tCommon(($) => $.actions.working) : tCommon(($) => $.common.save)}
                </Button>
              </Can>
            ) : (
              <span />
            )}

            <div className="flex flex-wrap items-center gap-2">
              <Can permission="iam.roles.create">
                <Button type="button" variant="outline" onClick={onClone}>
                  {t(($) => $.roles.detail.cloneTrigger)}
                </Button>
              </Can>

              {editable && !role.is_system ? (
                role.archived ? (
                  <Can permission="iam.roles.update">
                    <Button type="button" variant="outline" onClick={() => restoreRole.mutate()} disabled={restoreRole.isPending}>
                      {t(($) => $.roles.detail.restoreTrigger)}
                    </Button>
                  </Can>
                ) : (
                  <Can permission="iam.roles.update">
                    <Button type="button" variant="outline" onClick={() => setArchiveOpen(true)}>
                      {t(($) => $.roles.detail.archiveTrigger)}
                    </Button>
                  </Can>
                )
              ) : null}

              {editable && !role.is_system && (role.user_count ?? 0) === 0 ? (
                <Can permission="iam.roles.delete">
                  <Button type="button" variant="destructive" onClick={() => setDeleteOpen(true)}>
                    {t(($) => $.roles.detail.deleteTrigger)}
                  </Button>
                </Can>
              ) : null}
            </div>
          </div>
        </TabsContent>

        <TabsContent value="permissions" className="flex flex-col gap-3 pt-4">
          <PermissionMatrix value={permissions} onChange={setPermissions} readOnly={!editable} />
          {editable ? (
            <Can permission="iam.roles.update">
              <div className="flex items-center justify-end border-t pt-3">
                <Button type="button" onClick={handleSavePermissions} disabled={updatePermissions.isPending || !permissionsDirty}>
                  {updatePermissions.isPending ? tCommon(($) => $.actions.working) : t(($) => $.roles.detail.savePermissions)}
                </Button>
              </div>
            </Can>
          ) : null}
        </TabsContent>

        <TabsContent value="users" className="pt-4">
          <RoleUsersTab users={role.assigned_users} />
        </TabsContent>

        {!role.is_system ? (
          <TabsContent value="navigation" className="pt-4">
            <RoleNavigationSettings role={role} />
          </TabsContent>
        ) : null}
      </Tabs>

      <ConfirmDialog
        open={archiveOpen}
        onOpenChange={setArchiveOpen}
        title={t(($) => $.roles.detail.archiveConfirmTitle)}
        description={
          <div className="flex flex-col gap-2">
            <p>{t(($) => $.roles.detail.archiveConfirmDescription, { count: role.user_count ?? 0 })}</p>
            <Input
              value={archiveReason}
              onChange={(e) => setArchiveReason(e.target.value)}
              placeholder={t(($) => $.roles.detail.archiveReasonPlaceholder)}
            />
          </div>
        }
        loading={archiveRole.isPending}
        onConfirm={() =>
          archiveRole.mutate(archiveReason || undefined, {
            onSuccess: () => {
              setArchiveOpen(false);
              setArchiveReason('');
            },
          })
        }
      />
      <ConfirmDialog
        open={deleteOpen}
        onOpenChange={setDeleteOpen}
        title={t(($) => $.roles.detail.deleteConfirmTitle)}
        description={t(($) => $.roles.detail.deleteConfirmDescription)}
        variant="destructive"
        loading={deleteRole.isPending}
        onConfirm={() => deleteRole.mutate(role.id, { onSuccess: onDeleted })}
      />
    </div>
  );
}

/**
 * User-review remediation (Batch 02, item G): "For every Role: a visible list/tab/drawer of
 * the Users assigned to that Role." Reuses `role.assigned_users` — already fetched with the
 * role detail via the canonical `$role->users()` relation (RoleController::show(), capped at
 * 200) — rather than a second request or a client-side inference.
 */
function RoleUsersTab({ users }: { users: RoleDetail['assigned_users'] }) {
  const { t } = useTranslation('iam-admin');

  if (users.length === 0) {
    return <EmptyState title={t(($) => $.roles.detail.usersTab.empty)} />;
  }

  return (
    <div className="flex max-h-[420px] flex-col gap-2 overflow-y-auto">
      {users.map((user) => (
        <div key={user.id} className="flex items-center justify-between gap-3 rounded-md border px-3 py-2">
          <div className="flex min-w-0 flex-col">
            <span className="truncate text-sm font-medium">{user.name}</span>
            <span className="text-muted-foreground truncate text-xs">{user.username ?? user.email}</span>
          </div>
          <UserStatusBadge status={user.status} label={user.status_label} />
        </div>
      ))}
    </div>
  );
}
