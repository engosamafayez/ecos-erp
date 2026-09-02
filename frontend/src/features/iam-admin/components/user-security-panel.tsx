import { useState } from 'react';
import axios from 'axios';
import { zodResolver } from '@hookform/resolvers/zod';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { z } from 'zod';

import { ConfirmDialog, ErrorState, LoadingState } from '@/components/crud';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Can } from '@/features/authorization';
import {
  useForceLogout,
  useResetPassword,
  useRevokeSession,
  useUserSessions,
} from '@/features/iam-admin/hooks/use-users';
import type { UserDetail } from '@/features/iam-admin/types/user';

/**
 * §7: the password-strength rule itself is NOT re-derived here — this schema only enforces
 * "non-empty and matches confirmation"; the actual strength policy (Password::defaults(), D5)
 * is validated server-side, and a 422 from that surfaces via the server-error alert below.
 */
const resetSchema = z
  .object({ password: z.string().min(1, 'Required.'), password_confirmation: z.string().min(1, 'Required.') })
  .refine((v) => v.password === v.password_confirmation, {
    path: ['password_confirmation'],
    message: 'Passwords do not match.',
  });
type ResetFormValues = z.infer<typeof resetSchema>;

/** §9: sessions/security, folded into the User detail rather than a separate top-level workspace. */
export function UserSecurityPanel({ user }: { user: UserDetail }) {
  const { t } = useTranslation('iam-admin');
  const [resetOpen, setResetOpen] = useState(false);
  const [forceLogoutOpen, setForceLogoutOpen] = useState(false);
  const sessionsQuery = useUserSessions(user.id, true);
  const revokeSession = useRevokeSession(user.id);
  const forceLogout = useForceLogout(user.id);

  const resetEligible = user.status !== 'archived' && user.status !== 'deleted';

  return (
    <div className="flex flex-col gap-6">
      <section className="flex flex-col gap-2">
        <h3 className="text-sm font-semibold">{t(($) => $.users.security.passwordTitle)}</h3>
        {/* §7: ARCHIVED/DELETED show the reset action disabled with an explanatory note rather
           than hiding it silently — restore must happen first (D1's ratified rule). */}
        {!resetEligible ? (
          <p className="text-muted-foreground text-xs">
            {user.status === 'archived'
              ? t(($) => $.users.security.resetRequiresRestore)
              : t(($) => $.users.security.resetUnavailableDeleted)}
          </p>
        ) : null}
        {user.status === 'suspended' || user.status === 'locked' ? (
          <p className="text-muted-foreground text-xs">{t(($) => $.users.security.resetKeepsStatus)}</p>
        ) : null}
        <Can permission="iam.users.reset-password">
          <Button type="button" variant="outline" disabled={!resetEligible} onClick={() => setResetOpen(true)} className="self-start">
            {t(($) => $.users.security.resetTrigger)}
          </Button>
        </Can>
      </section>

      <section className="flex flex-col gap-2 border-t pt-4">
        <div className="flex items-center justify-between">
          <h3 className="text-sm font-semibold">{t(($) => $.users.security.sessionsTitle)}</h3>
          <Can permission="iam.users.manage-sessions">
            <Button
              type="button"
              variant="destructive"
              size="sm"
              disabled={(sessionsQuery.data?.length ?? 0) === 0}
              onClick={() => setForceLogoutOpen(true)}
            >
              {t(($) => $.users.security.forceLogoutTrigger)}
            </Button>
          </Can>
        </div>

        {sessionsQuery.isLoading ? (
          <LoadingState />
        ) : sessionsQuery.isError ? (
          <ErrorState description={sessionsQuery.error instanceof Error ? sessionsQuery.error.message : undefined} />
        ) : (sessionsQuery.data?.length ?? 0) === 0 ? (
          <p className="text-muted-foreground text-sm">{t(($) => $.users.security.noSessions)}</p>
        ) : (
          <div className="flex flex-col gap-2">
            {sessionsQuery.data?.map((session) => (
              <div key={session.id} className="flex items-center justify-between rounded-md border px-3 py-2 text-sm">
                <div className="flex flex-col">
                  <span>
                    {session.browser ?? t(($) => $.users.security.unknownBrowser)} · {session.platform ?? '—'}
                  </span>
                  <span className="text-muted-foreground text-xs">
                    {session.ip_address ?? '—'} ·{' '}
                    {session.last_activity_at ? new Date(session.last_activity_at).toLocaleString() : '—'}
                  </span>
                </div>
                <Can permission="iam.users.manage-sessions">
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    disabled={revokeSession.isPending}
                    onClick={() => revokeSession.mutate(session.id)}
                  >
                    {t(($) => $.users.security.revokeSession)}
                  </Button>
                </Can>
              </div>
            ))}
          </div>
        )}
      </section>

      <ResetPasswordDialog userId={user.id} open={resetOpen} onOpenChange={setResetOpen} />
      <ConfirmDialog
        open={forceLogoutOpen}
        onOpenChange={setForceLogoutOpen}
        title={t(($) => $.users.security.forceLogoutTitle)}
        description={t(($) => $.users.security.forceLogoutDescription)}
        variant="destructive"
        loading={forceLogout.isPending}
        onConfirm={() => forceLogout.mutate(undefined, { onSuccess: () => setForceLogoutOpen(false) })}
      />
    </div>
  );
}

function ResetPasswordDialog({
  userId,
  open,
  onOpenChange,
}: {
  userId: number;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const { t } = useTranslation('iam-admin');
  const { t: tCommon } = useTranslation('common');
  const resetPassword = useResetPassword(userId);
  const [serverError, setServerError] = useState<string | null>(null);
  const form = useForm<ResetFormValues>({
    resolver: zodResolver(resetSchema),
    defaultValues: { password: '', password_confirmation: '' },
  });

  function handleClose(next: boolean) {
    if (!next) {
      form.reset();
      setServerError(null);
    }
    onOpenChange(next);
  }

  function handleSubmit(values: ResetFormValues) {
    setServerError(null);
    resetPassword.mutate(values, {
      onSuccess: () => handleClose(false),
      onError: (error) =>
        setServerError(
          axios.isAxiosError(error) && typeof error.response?.data?.message === 'string'
            ? error.response.data.message
            : t(($) => $.users.detail.genericError),
        ),
    });
  }

  return (
    <Dialog open={open} onOpenChange={handleClose}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t(($) => $.users.security.resetTitle)}</DialogTitle>
        </DialogHeader>
        {serverError ? (
          <Alert variant="destructive">
            <AlertTitle>{t(($) => $.users.detail.genericError)}</AlertTitle>
            <AlertDescription>{serverError}</AlertDescription>
          </Alert>
        ) : null}
        <form onSubmit={form.handleSubmit(handleSubmit)} className="flex flex-col gap-3">
          <div className="flex flex-col gap-1.5">
            <Input type="password" placeholder={t(($) => $.users.security.newPassword)} {...form.register('password')} />
            {form.formState.errors.password ? (
              <p className="text-destructive text-xs">{form.formState.errors.password.message}</p>
            ) : null}
          </div>
          <div className="flex flex-col gap-1.5">
            <Input
              type="password"
              placeholder={t(($) => $.users.security.confirmPassword)}
              {...form.register('password_confirmation')}
            />
            {form.formState.errors.password_confirmation ? (
              <p className="text-destructive text-xs">{form.formState.errors.password_confirmation.message}</p>
            ) : null}
          </div>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => handleClose(false)}>
              {tCommon(($) => $.common.cancel)}
            </Button>
            <Button type="submit" disabled={resetPassword.isPending}>
              {resetPassword.isPending ? tCommon(($) => $.actions.working) : t(($) => $.users.security.resetSubmit)}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
