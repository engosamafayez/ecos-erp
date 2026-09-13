import { useState } from 'react';
import axios from 'axios';
import { useMutation, useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Link, useSearchParams } from 'react-router-dom';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ROUTES } from '@/router/routes';
import { invitationAcceptService } from '@/features/auth/services/invitation-accept-service';

/**
 * CORE-02 Task 1 — the invitee-facing counterpart to UserInvitationPanel. PUBLIC: no session,
 * no company context, no navigation rail — registered outside ProtectedRoute/AppShell exactly
 * like the careers portal (see router.ts). Carries no role/company field of any kind: this
 * page can only ever set a password on the account the admin already provisioned.
 */
export function AcceptInvitationPage() {
  const { t, i18n } = useTranslation('auth');
  const isRTL = i18n.language === 'ar';
  const [searchParams] = useSearchParams();
  const token = searchParams.get('token') ?? '';

  const preview = useQuery({
    queryKey: ['invitation-preview', token],
    queryFn: () => invitationAcceptService.show(token),
    enabled: token !== '',
    retry: false,
  });

  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [formError, setFormError] = useState<string | null>(null);
  const [done, setDone] = useState(false);

  const accept = useMutation({
    mutationFn: () => invitationAcceptService.accept({ token, password, password_confirmation: confirmation }),
    onSuccess: () => setDone(true),
    onError: (err) => {
      setFormError(
        axios.isAxiosError(err) && typeof err.response?.data?.message === 'string'
          ? err.response.data.message
          : t(($) => $.acceptInvitation.genericError),
      );
    },
  });

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setFormError(null);
    if (password !== confirmation) {
      setFormError(t(($) => $.acceptInvitation.passwordMismatch));
      return;
    }
    accept.mutate();
  }

  return (
    <div dir={isRTL ? 'rtl' : 'ltr'} className="bg-background flex min-h-screen items-center justify-center p-6">
      <div className="w-full max-w-sm rounded-lg border p-6 shadow-sm">
        <h1 className="text-lg font-semibold">{t(($) => $.acceptInvitation.title)}</h1>

        {token === '' ? (
          <Alert variant="destructive" className="mt-4">
            <AlertDescription>{t(($) => $.acceptInvitation.invalidLink)}</AlertDescription>
          </Alert>
        ) : preview.isLoading ? (
          <p className="text-muted-foreground mt-4 text-sm">{t(($) => $.acceptInvitation.loading)}</p>
        ) : preview.isError ? (
          <Alert variant="destructive" className="mt-4">
            <AlertDescription>{t(($) => $.acceptInvitation.invalidOrExpired)}</AlertDescription>
          </Alert>
        ) : done ? (
          <div className="mt-4 flex flex-col gap-4">
            <Alert>
              <AlertDescription>{t(($) => $.acceptInvitation.successMessage)}</AlertDescription>
            </Alert>
            <Button asChild>
              <Link to={ROUTES.login}>{t(($) => $.acceptInvitation.goToLogin)}</Link>
            </Button>
          </div>
        ) : (
          <form onSubmit={handleSubmit} className="mt-4 flex flex-col gap-4">
            <p className="text-muted-foreground text-sm">
              {t(($) => $.acceptInvitation.subtitle, { email: preview.data?.email ?? '' })}
            </p>

            {formError ? (
              <Alert variant="destructive">
                <AlertDescription>{formError}</AlertDescription>
              </Alert>
            ) : null}

            <div className="flex flex-col gap-1.5">
              <label htmlFor="accept-password" className="text-sm font-medium">
                {t(($) => $.acceptInvitation.password)}
              </label>
              <Input
                id="accept-password"
                type="password"
                required
                autoComplete="new-password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
              />
            </div>

            <div className="flex flex-col gap-1.5">
              <label htmlFor="accept-password-confirmation" className="text-sm font-medium">
                {t(($) => $.acceptInvitation.confirmPassword)}
              </label>
              <Input
                id="accept-password-confirmation"
                type="password"
                required
                autoComplete="new-password"
                value={confirmation}
                onChange={(e) => setConfirmation(e.target.value)}
              />
            </div>

            <Button type="submit" disabled={accept.isPending}>
              {accept.isPending ? t(($) => $.acceptInvitation.submitting) : t(($) => $.acceptInvitation.submit)}
            </Button>
          </form>
        )}
      </div>
    </div>
  );
}
