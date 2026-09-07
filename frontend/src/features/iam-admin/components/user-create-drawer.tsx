import { useState } from 'react';
import axios from 'axios';
import { zodResolver } from '@hookform/resolvers/zod';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';

import { EntityDrawer, EntityForm, FormField } from '@/components/crud';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import { useCreateUser } from '@/features/iam-admin/hooks/use-users';
import type { CreateUserPayload } from '@/features/iam-admin/types/user';

import { EmployeeLookupField } from './employee-lookup-field';
import { OrganizationScopePicker } from './organization-scope-picker';
import { RoleAssignmentPicker } from './role-assignment-picker';
import { toCreatePayload, toFormValues, userSchema, type UserFormValues } from './user-form-schema';

function extractMessage(error: unknown, fallback: string): string {
  return axios.isAxiosError(error) && typeof error.response?.data?.message === 'string'
    ? error.response.data.message
    : fallback;
}

/** D6: field-level errors surface via the 422 `errors` object; anything else falls back to the top-level message. */
function extractFieldErrors(error: unknown): Record<string, string> {
  if (axios.isAxiosError(error) && error.response?.status === 422) {
    const errors = error.response.data?.errors as Record<string, string[] | string> | undefined;
    if (errors) {
      return Object.fromEntries(
        Object.entries(errors).map(([field, msg]) => [field, Array.isArray(msg) ? msg[0] : msg]),
      );
    }
  }
  return {};
}

/**
 * Create User (TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001, §6-§10).
 *
 * One submission now provisions a COMPLETE, usable account instead of leaving three more
 * round trips (set password, assign role, assign scope) for later — the exact gap that
 * produced §10's dead-end Draft account. Every part still runs through its own canonical
 * service, in one backend transaction (UserController::store()):
 *
 *   identity + initial password → UserIdentityService
 *   employee link (§6)          → EmployeeLookupField, verified server-side
 *   roles (§8)                  → UserRoleAssignmentService (self-authorizing)
 *   organization scope (§9)     → UserOrganizationAssignmentService (entity-validated)
 *   activation (§10)            → the canonical UserLifecycleService transition
 */
export function UserCreateDrawer({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
  const { t } = useTranslation('iam-admin');
  const { t: tCommon } = useTranslation('common');
  const createUser = useCreateUser();
  const [serverError, setServerError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [roleTemplates, setRoleTemplates] = useState<string[]>([]);
  const [organizations, setOrganizations] = useState<NonNullable<CreateUserPayload['organizations']>>([]);
  const [activateNow, setActivateNow] = useState(false);

  const form = useForm<UserFormValues>({
    resolver: zodResolver(userSchema),
    defaultValues: toFormValues(),
  });

  // Resets the form/errors each time the drawer opens — adjusted during render (React's
  // documented pattern for this) rather than in a useEffect, which would cost an extra render.
  const [prevOpen, setPrevOpen] = useState(open);
  if (open !== prevOpen) {
    setPrevOpen(open);
    if (open) {
      form.reset(toFormValues());
      setServerError(null);
      setFieldErrors({});
      setRoleTemplates([]);
      setOrganizations([]);
      setActivateNow(false);
    }
  }

  const genericError = t(($) => $.users.create.errorTitle);

  const handleSubmit = (values: UserFormValues) => {
    setServerError(null);
    setFieldErrors({});
    const payload = toCreatePayload(values, {
      roleTemplates,
      primaryRoleTemplate: roleTemplates[0] ?? null,
      organizations,
      activate: activateNow,
    });
    createUser.mutate(payload, {
      onSuccess: () => onOpenChange(false),
      onError: (error) => {
        setServerError(extractMessage(error, genericError));
        setFieldErrors(extractFieldErrors(error));
      },
    });
  };

  const employeeNumber = form.watch('employee_number');
  const password = form.watch('password');

  return (
    <EntityDrawer
      open={open}
      onOpenChange={onOpenChange}
      title={t(($) => $.users.create.title)}
      description={t(($) => $.users.create.description)}
      footer={
        <>
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
            {tCommon(($) => $.common.cancel)}
          </Button>
          <Button type="submit" form="iam-user-create-form" disabled={createUser.isPending}>
            {createUser.isPending ? t(($) => $.users.create.submitting) : t(($) => $.users.create.submit)}
          </Button>
        </>
      }
    >
      {serverError ? (
        <Alert variant="destructive" className="mb-4">
          <AlertTitle>{t(($) => $.users.create.errorTitle)}</AlertTitle>
          <AlertDescription>{serverError}</AlertDescription>
        </Alert>
      ) : null}

      <EntityForm id="iam-user-create-form" form={form} onSubmit={handleSubmit} className="flex flex-col gap-4">
        <FormField name="name" label={t(($) => $.users.fields.name)} required error={fieldErrors.name}>
          <Input {...form.register('name')} />
        </FormField>
        <FormField name="email" label={t(($) => $.users.fields.email)} required error={fieldErrors.email}>
          <Input type="email" {...form.register('email')} />
        </FormField>
        <FormField name="display_name" label={t(($) => $.users.fields.displayName)} optional>
          <Input {...form.register('display_name')} />
        </FormField>
        <FormField
          name="username"
          label={t(($) => $.users.fields.username)}
          optional
          hint={t(($) => $.users.fields.usernameHint)}
          error={fieldErrors.username}
        >
          <Input {...form.register('username')} />
        </FormField>
        <FormField
          name="employee_number"
          label={t(($) => $.users.fields.employeeLink)}
          optional
          error={fieldErrors.employee_number}
        >
          <EmployeeLookupField
            value={employeeNumber || null}
            onChange={(value) => form.setValue('employee_number', value ?? '', { shouldDirty: true })}
          />
        </FormField>
        <FormField name="phone" label={t(($) => $.users.fields.phone)} optional>
          <Input {...form.register('phone')} />
        </FormField>

        <Separator />

        {/* §10 — the initial credential. Optional: leave blank to keep the historical
           unusable-random-password behaviour, resolved later through the invitation flow. */}
        <div className="flex flex-col gap-1">
          <p className="text-sm font-medium">{t(($) => $.users.password.initialSectionTitle)}</p>
          <p className="text-muted-foreground text-xs">{t(($) => $.users.password.initialSectionHint)}</p>
        </div>
        <FormField
          name="password"
          label={t(($) => $.users.password.initialPassword)}
          optional
          error={form.formState.errors.password?.message ?? fieldErrors.password}
        >
          <Input type="password" autoComplete="new-password" {...form.register('password')} />
        </FormField>
        {password ? (
          <>
            <FormField
              name="password_confirmation"
              label={t(($) => $.users.password.confirmPassword)}
              required
              error={form.formState.errors.password_confirmation?.message}
            >
              <Input type="password" autoComplete="new-password" {...form.register('password_confirmation')} />
            </FormField>
            <label className="flex items-center gap-2 text-sm">
              <Checkbox
                checked={form.watch('require_password_change') ?? true}
                onCheckedChange={(checked) => form.setValue('require_password_change', checked)}
              />
              {t(($) => $.users.password.requireChangeAtLogin)}
            </label>
          </>
        ) : null}

        <Separator />

        {/* §8 — role assignment. Template-mediated, self-authorizing, audited server-side. */}
        <div>
          <p className="mb-1.5 text-sm font-medium">{t(($) => $.users.roles.title)}</p>
          <RoleAssignmentPicker value={roleTemplates} onChange={setRoleTemplates} />
        </div>

        <Separator />

        {/* §9 — organization scope, entity-driven. */}
        <div>
          <p className="mb-1.5 text-sm font-medium">{t(($) => $.users.organization.title)}</p>
          <OrganizationScopePicker value={organizations} onChange={setOrganizations} />
        </div>

        <Separator />

        {/* §10 — explicit, discoverable activation, right where the account is provisioned. */}
        <label className="flex items-center gap-2 text-sm">
          <Checkbox checked={activateNow} onCheckedChange={setActivateNow} />
          {t(($) => $.users.lifecycle.activateNow)}
        </label>
      </EntityForm>
    </EntityDrawer>
  );
}
