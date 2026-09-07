import { useState } from 'react';
import axios from 'axios';
import { zodResolver } from '@hookform/resolvers/zod';
import { Archive, Lock, LockOpen, RotateCcw, UserCheck, UserMinus } from 'lucide-react';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';

import { EntityDrawer, EntityForm, ErrorState, FormField, LoadingState } from '@/components/crud';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Can, usePermission } from '@/features/authorization';
import { useUpdateUser, useUserQuery } from '@/features/iam-admin/hooks/use-users';
import type { LifecycleAction, UserDetail } from '@/features/iam-admin/types/user';

import { EmployeeLookupField } from './employee-lookup-field';
import { LifecycleConfirmDialog } from './users-tab';
import { UserOrganizationPanel } from './user-organization-panel';
import { UserRolesPanel } from './user-roles-panel';
import { UserSecurityPanel } from './user-security-panel';
import { toFormValues, toUpdatePayload, userSchema, type UserFormValues } from './user-form-schema';
import { UserStatusBadge } from './user-status-badge';

const ACTION_ICON: Record<LifecycleAction, typeof UserCheck> = {
  activate: UserCheck,
  suspend: UserMinus,
  deactivate: UserMinus,
  lock: Lock,
  unlock: LockOpen,
  archive: Archive,
  restore: RotateCcw,
};

export function UserDetailDrawer({
  userId,
  open,
  onOpenChange,
}: {
  userId: number | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const { t } = useTranslation('iam-admin');
  const query = useUserQuery(userId);

  return (
    <EntityDrawer
      open={open}
      onOpenChange={onOpenChange}
      title={query.data ? query.data.display_name : t(($) => $.users.detail.title)}
      description={query.data?.email}
    >
      {query.isLoading ? (
        <LoadingState />
      ) : query.isError ? (
        <ErrorState description={query.error instanceof Error ? query.error.message : undefined} />
      ) : query.data ? (
        <UserDetailContent user={query.data} />
      ) : null}
    </EntityDrawer>
  );
}

/**
 * §10 — an explicit, discoverable lifecycle bar reachable FROM the user's own detail view,
 * not only the row-level menu on the list. `availableActions` reads the server-computed
 * `lifecycle` capability flags exactly like UsersTab does, so the two surfaces can never
 * disagree about what is currently a valid transition for THIS user.
 */
function LifecycleActionBar({ user }: { user: UserDetail }) {
  const { t } = useTranslation('iam-admin');
  const { can } = usePermission();
  const [pendingAction, setPendingAction] = useState<LifecycleAction | null>(null);

  const ACTION_LABEL: Record<LifecycleAction, string> = {
    activate: t(($) => $.users.lifecycle.activate),
    suspend: t(($) => $.users.lifecycle.suspend),
    deactivate: t(($) => $.users.lifecycle.deactivate),
    lock: t(($) => $.users.lifecycle.lock),
    unlock: t(($) => $.users.lifecycle.unlock),
    archive: t(($) => $.users.lifecycle.archive),
    restore: t(($) => $.users.lifecycle.restore),
  };

  const actions: LifecycleAction[] = (
    [
      ['activate', user.lifecycle.can_activate],
      ['suspend', user.lifecycle.can_suspend],
      ['deactivate', user.lifecycle.can_deactivate],
      ['lock', user.lifecycle.can_lock],
      ['unlock', user.lifecycle.can_unlock],
      ['archive', user.lifecycle.can_archive],
      ['restore', user.lifecycle.can_restore],
    ] as const
  )
    .filter(([, allowed]) => allowed)
    .map(([action]) => action)
    .filter((action) => can(`iam.users.${action}`));

  if (actions.length === 0) return null;

  return (
    <div className="flex flex-wrap gap-2">
      {actions.map((action) => {
        const Icon = ACTION_ICON[action];
        return (
          <Button
            key={action}
            type="button"
            size="sm"
            variant={action === 'archive' ? 'destructive' : action === 'activate' ? 'default' : 'outline'}
            onClick={() => setPendingAction(action)}
            className="gap-1.5"
          >
            <Icon className="size-3.5" />
            {ACTION_LABEL[action]}
          </Button>
        );
      })}

      {pendingAction ? (
        <LifecycleConfirmDialog userId={user.id} action={pendingAction} onClose={() => setPendingAction(null)} />
      ) : null}
    </div>
  );
}

function UserDetailContent({ user }: { user: NonNullable<ReturnType<typeof useUserQuery>['data']> }) {
  const { t } = useTranslation('iam-admin');
  const { t: tCommon } = useTranslation('common');
  const { can } = usePermission();
  const updateUser = useUpdateUser(user.id);
  const [serverError, setServerError] = useState<string | null>(null);

  const form = useForm<UserFormValues>({
    resolver: zodResolver(userSchema),
    defaultValues: toFormValues(user),
  });

  // Keeps the form/errors in sync whenever the `user` prop changes identity (refetch, save) —
  // adjusted during render (React's documented pattern for this) rather than in a useEffect,
  // which would cost an extra render.
  const [prevUser, setPrevUser] = useState(user);
  if (user !== prevUser) {
    setPrevUser(user);
    form.reset(toFormValues(user));
    setServerError(null);
  }

  const handleSubmit = (values: UserFormValues) => {
    setServerError(null);
    updateUser.mutate(toUpdatePayload(values), {
      onError: (error) =>
        setServerError(
          axios.isAxiosError(error) && typeof error.response?.data?.message === 'string'
            ? error.response.data.message
            : t(($) => $.users.detail.genericError),
        ),
    });
  };

  const canEdit = can('iam.users.update');
  const employeeNumber = form.watch('employee_number');

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <UserStatusBadge status={user.status} label={user.status_label} />
        <LifecycleActionBar user={user} />
      </div>

      {user.lifecycle.is_pre_activation ? (
        <Alert>
          <AlertDescription>{t(($) => $.users.lifecycle.preActivationHint)}</AlertDescription>
        </Alert>
      ) : null}

      <Tabs defaultValue="profile">
        <TabsList>
          <TabsTrigger value="profile">{t(($) => $.users.detail.tabs.profile)}</TabsTrigger>
          <TabsTrigger value="roles">{t(($) => $.users.detail.tabs.roles)}</TabsTrigger>
          <TabsTrigger value="organization">{t(($) => $.users.detail.tabs.organization)}</TabsTrigger>
          <Can permission="iam.users.manage-sessions">
            <TabsTrigger value="security">{t(($) => $.users.detail.tabs.security)}</TabsTrigger>
          </Can>
        </TabsList>

        <TabsContent value="profile" className="pt-4">
          {serverError ? (
            <Alert variant="destructive" className="mb-4">
              <AlertTitle>{t(($) => $.users.detail.genericError)}</AlertTitle>
              <AlertDescription>{serverError}</AlertDescription>
            </Alert>
          ) : null}
          <EntityForm
            id="iam-user-edit-form"
            form={form}
            onSubmit={handleSubmit}
            className="flex flex-col gap-4"
          >
            {/* §22/§26: a viewer without update permission sees the data, not the affordance to
               change it — handled by disabling inputs, not by hiding the tab (they still need
               to read the profile). */}
            <fieldset disabled={!canEdit} className="contents">
              <FormField name="name" label={t(($) => $.users.fields.name)} required>
                <Input {...form.register('name')} />
              </FormField>
              <FormField name="email" label={t(($) => $.users.fields.email)} required>
                <Input type="email" {...form.register('email')} />
              </FormField>
              <FormField name="display_name" label={t(($) => $.users.fields.displayName)} optional>
                <Input {...form.register('display_name')} />
              </FormField>
              <FormField name="username" label={t(($) => $.users.fields.username)} optional hint={t(($) => $.users.fields.usernameHint)}>
                <Input {...form.register('username')} />
              </FormField>
              <FormField name="employee_number" label={t(($) => $.users.fields.employeeLink)} optional>
                <EmployeeLookupField
                  value={employeeNumber || null}
                  currentEmployeeId={user.employee?.id ?? null}
                  onChange={(value) => form.setValue('employee_number', value ?? '', { shouldDirty: true })}
                />
              </FormField>
              <FormField name="phone" label={t(($) => $.users.fields.phone)} optional>
                <Input {...form.register('phone')} />
              </FormField>
            </fieldset>
            {canEdit ? (
              <div className="flex justify-end">
                <Button type="submit" disabled={updateUser.isPending}>
                  {updateUser.isPending ? tCommon(($) => $.actions.working) : tCommon(($) => $.common.save)}
                </Button>
              </div>
            ) : null}
          </EntityForm>
        </TabsContent>

        <TabsContent value="roles" className="pt-4">
          <UserRolesPanel user={user} />
        </TabsContent>

        <TabsContent value="organization" className="pt-4">
          <UserOrganizationPanel user={user} />
        </TabsContent>

        <Can permission="iam.users.manage-sessions">
          <TabsContent value="security" className="pt-4">
            <UserSecurityPanel user={user} />
          </TabsContent>
        </Can>
      </Tabs>
    </div>
  );
}
