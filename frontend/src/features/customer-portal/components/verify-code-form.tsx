import { useState } from 'react';
import axios from 'axios';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useVerifyMutation } from '@/features/customer-portal/hooks/use-customer-portal';

/**
 * §5 — code entry. Never renders the raw code back, never persists it client-side, and never
 * claims a code is valid before the server confirms it (§26 error-handling boundary).
 */
export function VerifyCodeForm({
  orderNumber,
  contact,
  onBack,
}: {
  orderNumber: string;
  contact: string;
  onBack: () => void;
}) {
  const { t } = useTranslation('customer-portal');
  const [code, setCode] = useState('');
  const [error, setError] = useState<string | null>(null);
  const verifyMutation = useVerifyMutation();

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);

    verifyMutation.mutate(
      { orderNumber, contact, code: code.trim() },
      {
        onError: (err: unknown) => {
          if (axios.isAxiosError(err)) {
            if (err.response?.status === 422) {
              setError(t(($) => $.verify.invalidCode));
              return;
            }
            if (err.response?.status === 429) {
              setError(t(($) => $.verify.tooManyAttempts));
              return;
            }
            if (!err.response) {
              setError(t(($) => $.errors.network));
              return;
            }
          }
          setError(t(($) => $.errors.generic));
        },
      },
    );
  };

  return (
    <div className="mx-auto flex min-h-screen max-w-md flex-col justify-center px-4 py-10 sm:px-6">
      <Card>
        <CardHeader className="flex flex-col gap-1">
          <h1 className="text-xl font-semibold tracking-tight sm:text-2xl">
            {t(($) => $.verify.heading)}
          </h1>
          <p className="text-muted-foreground text-sm">{t(($) => $.landing.confirmation)}</p>
          <p className="text-muted-foreground text-sm">{t(($) => $.verify.intro)}</p>
        </CardHeader>
        <CardContent>
          <form className="flex flex-col gap-4" onSubmit={handleSubmit} noValidate>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="track-verify-code">{t(($) => $.verify.codeLabel)}</Label>
              <Input
                id="track-verify-code"
                value={code}
                onChange={(e) => setCode(e.target.value)}
                placeholder={t(($) => $.verify.codePlaceholder)}
                inputMode="numeric"
                autoComplete="one-time-code"
                maxLength={20}
              />
              <p className="text-muted-foreground text-xs">{t(($) => $.verify.expiryNotice)}</p>
              <p className="text-muted-foreground text-xs">{t(($) => $.verify.attemptsNotice)}</p>
            </div>

            {error ? (
              <p role="alert" className="text-destructive text-sm">
                {error}
              </p>
            ) : null}

            <Button type="submit" disabled={verifyMutation.isPending || code.trim().length === 0}>
              {verifyMutation.isPending ? t(($) => $.verify.submitting) : t(($) => $.verify.submit)}
            </Button>
            <Button type="button" variant="ghost" onClick={onBack}>
              {t(($) => $.verify.backToLanding)}
            </Button>
          </form>
        </CardContent>
      </Card>
    </div>
  );
}
