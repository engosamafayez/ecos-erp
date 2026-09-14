import { useState } from 'react';
import axios from 'axios';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useRequestVerificationMutation } from '@/features/customer-portal/hooks/use-customer-portal';

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/**
 * §4 — the public landing page. Deliberately asks for ONLY order number + email (no phone/SMS
 * option — Task 1 proved no secure SMS delivery authority exists; §25 keeps this explicit) and
 * never accepts a customer/company/brand id from the visitor.
 */
export function TrackOrderLanding({
  onRequested,
}: {
  onRequested: (input: { orderNumber: string; contact: string }) => void;
}) {
  const { t } = useTranslation('customer-portal');
  const [orderNumber, setOrderNumber] = useState('');
  const [contact, setContact] = useState('');
  const [fieldError, setFieldError] = useState<string | null>(null);
  const requestMutation = useRequestVerificationMutation();

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setFieldError(null);

    const trimmedOrder = orderNumber.trim();
    const trimmedContact = contact.trim();

    if (!trimmedOrder) {
      setFieldError(t(($) => $.landing.validation.orderNumberRequired));
      return;
    }
    if (!EMAIL_PATTERN.test(trimmedContact)) {
      setFieldError(t(($) => $.landing.validation.emailInvalid));
      return;
    }

    requestMutation.mutate(
      { orderNumber: trimmedOrder, contact: trimmedContact },
      {
        onSuccess: () => {
          // Enumeration-safe: the app advances to code entry identically whether or not the
          // order/email actually matched — see the backend's own CustomerTrackingController
          // docblock. The verify screen's own copy carries the "if it matched" confirmation.
          onRequested({ orderNumber: trimmedOrder, contact: trimmedContact });
        },
        onError: (error: unknown) => {
          // Network/5xx only — the backend itself never returns a 4xx that would leak
          // existence for this endpoint, so any error here is a genuine transport failure.
          if (axios.isAxiosError(error) && !error.response) {
            setFieldError(t(($) => $.errors.network));
          } else {
            setFieldError(t(($) => $.landing.genericError));
          }
        },
      },
    );
  };

  return (
    <div className="mx-auto flex min-h-screen max-w-md flex-col justify-center px-4 py-10 sm:px-6">
      <Card>
        <CardHeader className="flex flex-col gap-1">
          <span className="text-muted-foreground text-xs font-medium uppercase tracking-wide">
            {t(($) => $.landing.eyebrow)}
          </span>
          <h1 className="text-xl font-semibold tracking-tight sm:text-2xl">
            {t(($) => $.landing.heading)}
          </h1>
          <p className="text-muted-foreground text-sm">{t(($) => $.landing.intro)}</p>
        </CardHeader>
        <CardContent>
          <form className="flex flex-col gap-4" onSubmit={handleSubmit} noValidate>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="track-order-number">{t(($) => $.landing.orderNumberLabel)}</Label>
              <Input
                id="track-order-number"
                value={orderNumber}
                onChange={(e) => setOrderNumber(e.target.value)}
                placeholder={t(($) => $.landing.orderNumberPlaceholder)}
                autoComplete="off"
              />
            </div>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="track-contact-email">{t(($) => $.landing.emailLabel)}</Label>
              <Input
                id="track-contact-email"
                type="email"
                value={contact}
                onChange={(e) => setContact(e.target.value)}
                placeholder={t(($) => $.landing.emailPlaceholder)}
                autoComplete="email"
              />
              <p className="text-muted-foreground text-xs">{t(($) => $.landing.emailOnlyNotice)}</p>
            </div>

            {fieldError ? (
              <p role="alert" className="text-destructive text-sm">
                {fieldError}
              </p>
            ) : null}

            <Button type="submit" disabled={requestMutation.isPending} className="mt-1">
              {requestMutation.isPending
                ? t(($) => $.landing.submitting)
                : t(($) => $.landing.submit)}
            </Button>
          </form>
        </CardContent>
      </Card>
    </div>
  );
}
