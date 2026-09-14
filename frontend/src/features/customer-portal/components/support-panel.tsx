import { useState } from 'react';
import axios from 'axios';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Textarea } from '@/components/ui/textarea';
import {
  useCreateSupportRequestMutation,
  useTrackSupportQuery,
} from '@/features/customer-portal/hooks/use-customer-portal';
import {
  POST_DELIVERY_CATEGORIES,
  type SupportCategory,
  type TrackSupportAvailability,
} from '@/features/customer-portal/types';

const ALL_CATEGORIES: SupportCategory[] = [
  'general_support',
  'payment_issue',
  'invoice_issue',
  'wrong_item',
  'damaged_item',
  'missing_item',
  'delivery_complaint',
  'return_request',
];

/**
 * §16/§17/§18/§19 — support history + creation. General/payment/invoice categories are always
 * offered; the 4 post-delivery-specific categories are only offered when the backend's own
 * `support.post_delivery_window.available` says so (§18 — never a frontend-computed "30 days").
 * A rejected submission (422 with `reason`) is shown using the backend's own reason code, never
 * a client-side guess.
 */
export function SupportPanel({ availability }: { availability: TrackSupportAvailability }) {
  const { t } = useTranslation('customer-portal');
  const historyQuery = useTrackSupportQuery(true);
  const createMutation = useCreateSupportRequestMutation();

  const [category, setCategory] = useState<SupportCategory>('general_support');
  const [subject, setSubject] = useState('');
  const [description, setDescription] = useState('');
  const [result, setResult] = useState<{ kind: 'success' | 'error'; text: string } | null>(null);

  const availableCategories = ALL_CATEGORIES.filter(
    (c) => !POST_DELIVERY_CATEGORIES.includes(c) || availability.post_delivery_window.available,
  );

  const isPostDeliveryCategory = POST_DELIVERY_CATEGORIES.includes(category);
  const blockedReason =
    isPostDeliveryCategory && !availability.post_delivery_window.available
      ? availability.post_delivery_window.reason
      : null;

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setResult(null);

    createMutation.mutate(
      { category, subject: subject.trim(), description: description.trim() || undefined },
      {
        onSuccess: () => {
          setResult({ kind: 'success', text: t(($) => $.support.submitSuccess) });
          setSubject('');
          setDescription('');
        },
        onError: (error: unknown) => {
          if (axios.isAxiosError(error) && error.response?.status === 422) {
            const reason = (error.response.data as { reason?: string } | undefined)?.reason;
            const reasonKey = reason as
              | 'not_yet_delivered'
              | 'delivery_timestamp_unavailable'
              | 'post_delivery_window_expired'
              | undefined;
            setResult({
              kind: 'error',
              text: reasonKey
                ? t(($) => $.support.notAvailableReason[reasonKey])
                : t(($) => $.errors.generic),
            });
            return;
          }
          setResult({ kind: 'error', text: t(($) => $.errors.generic) });
        },
      },
    );
  };

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h2 className="mb-2 text-lg font-semibold">{t(($) => $.support.heading)}</h2>
        {historyQuery.isLoading ? (
          <Skeleton className="h-20 w-full" />
        ) : (historyQuery.data ?? []).length === 0 ? (
          <p className="text-muted-foreground text-sm">{t(($) => $.support.historyEmpty)}</p>
        ) : (
          <ul className="flex flex-col gap-3">
            {(historyQuery.data ?? []).map((ticket) => (
              <li
                key={ticket.ticket_number}
                className="border-border rounded-md border p-3 text-sm"
              >
                <div className="flex justify-between font-medium">
                  <span>{ticket.subject}</span>
                  <span className="text-muted-foreground">{ticket.status}</span>
                </div>
                <div className="text-muted-foreground mt-1 text-xs">{ticket.ticket_number}</div>
                {(ticket.notes ?? []).map((note, i) => (
                  <p key={i} className="text-muted-foreground mt-2 text-xs">
                    {note.body}
                  </p>
                ))}
              </li>
            ))}
          </ul>
        )}
      </div>

      <form
        onSubmit={handleSubmit}
        className="border-border flex flex-col gap-3 rounded-md border p-4"
      >
        <p className="font-medium">{t(($) => $.support.newRequest)}</p>

        <div className="flex flex-col gap-1.5">
          <Label htmlFor="support-category">{t(($) => $.support.categoryLabel)}</Label>
          <select
            id="support-category"
            className="border-input bg-background h-9 rounded-md border px-3 text-sm"
            value={category}
            onChange={(e) => setCategory(e.target.value as SupportCategory)}
          >
            {availableCategories.map((c) => (
              <option key={c} value={c}>
                {t(($) => $.support.categories[c])}
              </option>
            ))}
          </select>
        </div>

        {blockedReason ? (
          <p className="text-muted-foreground text-xs">
            {t(
              ($) =>
                $.support.notAvailableReason[
                  blockedReason as
                    | 'not_yet_delivered'
                    | 'delivery_timestamp_unavailable'
                    | 'post_delivery_window_expired'
                ],
            )}
          </p>
        ) : null}

        {POST_DELIVERY_CATEGORIES.includes(category) ? (
          <p className="text-muted-foreground text-xs">{t(($) => $.support.returnIntakeNotice)}</p>
        ) : null}

        <div className="flex flex-col gap-1.5">
          <Label htmlFor="support-subject">{t(($) => $.support.subjectLabel)}</Label>
          <Input
            id="support-subject"
            value={subject}
            onChange={(e) => setSubject(e.target.value)}
            placeholder={t(($) => $.support.subjectPlaceholder)}
          />
        </div>

        <div className="flex flex-col gap-1.5">
          <Label htmlFor="support-description">{t(($) => $.support.descriptionLabel)}</Label>
          <Textarea
            id="support-description"
            value={description}
            onChange={(e) => setDescription(e.target.value)}
            placeholder={t(($) => $.support.descriptionPlaceholder)}
            rows={3}
          />
        </div>

        {result ? (
          <p
            role={result.kind === 'error' ? 'alert' : 'status'}
            className={
              result.kind === 'error'
                ? 'text-destructive text-sm'
                : 'text-sm text-emerald-600 dark:text-emerald-400'
            }
          >
            {result.text}
          </p>
        ) : null}

        <Button
          type="submit"
          disabled={
            createMutation.isPending || subject.trim().length === 0 || Boolean(blockedReason)
          }
        >
          {createMutation.isPending ? t(($) => $.support.submitting) : t(($) => $.support.submit)}
        </Button>
      </form>
    </div>
  );
}
