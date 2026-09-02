import { useEffect, useState } from 'react';
import axios from 'axios';
import { zodResolver } from '@hookform/resolvers/zod';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';

import { EntityDrawer, EntityForm, ErrorState, FormField, LoadingState } from '@/components/crud';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Can, usePermission } from '@/features/authorization';
import { useUpdateUser, useUserQuery } from '@/features/iam-admin/hooks/use-users';

import { UserOrganizationPanel } from './user-organization-panel';
import { UserRolesPanel } from './user-roles-panel';
import { UserSecurityPanel } from './user-security-panel';
import { toFormValues, toUpdatePayload, userSchema, type UserFormValues } from './user-form-schema';
import { UserStatusBadge } from './user-status-badge';

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

  useEffect(() => {
    form.reset(toFormValues(user));
    setServerError(null);
  }, [user, form]);

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

  return (
    <div className="flex flex-col gap-4">
      <UserStatusBadge status={user.status} label={user.status_label} />

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
              <FormField name="username" label={t(($) => $.users.fields.username)} optional>
                <Input {...form.register('username')} />
              </FormField>
              <FormField name="employee_number" label={t(($) => $.users.fields.employeeNumber)} optional>
                <Input {...form.register('employee_number')} />
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
