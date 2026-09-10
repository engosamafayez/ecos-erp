import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { useSetInitialImportPolicy, useSetOrdersSyncState } from '@/features/channels/hooks/use-channels';
import type { Channel } from '@/features/channels/types/channel';

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  channel: Channel | null;
};

const selectClass = 'border-input h-9 w-full rounded-md border bg-transparent px-3 text-sm shadow-xs';
const inputClass = 'border-input h-9 w-full rounded-md border bg-transparent px-3 text-sm shadow-xs';

/**
 * TASK-...-025 (P1/P3/P4/P11) — the ONE surface for Orders Sync setup/pause/resume. Deliberately
 * separate from the generic channel settings form: resuming from a pause requires an explicit
 * policy, which a bare checkbox can't express (W6-W8's "no ambiguous automatic behaviour").
 */
export function OrdersSyncDialog({ open, onOpenChange, channel }: Props) {
  const { t } = useTranslation('channels');
  const setState = useSetOrdersSyncState();
  const setInitialPolicy = useSetInitialImportPolicy();

  const [resumePolicy, setResumePolicy] = useState<'catch_up' | 'resume_from_now' | 'resume_from_point'>('catch_up');
  const [resumeFrom, setResumeFrom] = useState('');
  const [initialPolicy, setInitialPolicyChoice] = useState<'from_now' | 'from_date' | 'last_n_days' | 'historical'>('from_now');
  const [initialDate, setInitialDate] = useState('');
  const [initialDays, setInitialDays] = useState(30);

  // Static per-branch t() calls, resolved to a plain string via a runtime lookup afterward —
  // safer than indexing the typed translation path dynamically, a pattern not used elsewhere in
  // this codebase and not confirmed to be supported by the typed i18n helper.
  const healthLabels = {
    healthy: t($ => $.ordersSync.healthValues.healthy),
    warning: t($ => $.ordersSync.healthValues.warning),
    error: t($ => $.ordersSync.healthValues.error),
  };
  const resumePolicyDescriptions = {
    catch_up: t($ => $.ordersSync.resumePolicyDescriptions.catch_up),
    resume_from_now: t($ => $.ordersSync.resumePolicyDescriptions.resume_from_now),
    resume_from_point: t($ => $.ordersSync.resumePolicyDescriptions.resume_from_point),
  };

  if (!channel) return null;

  const notYetActivated = channel.orders_sync_activated_at === null;
  const isPaused = !channel.sync_orders;
  const isPending = setState.isPending || setInitialPolicy.isPending;

  const handlePause = () => {
    setState.mutate(
      { id: channel.id, payload: { state: 'paused' } },
      { onSuccess: () => onOpenChange(false) },
    );
  };

  const handleResume = () => {
    setState.mutate(
      {
        id: channel.id,
        payload: {
          state: 'enabled',
          resume_policy: resumePolicy,
          resume_from: resumePolicy === 'resume_from_point' ? resumeFrom : undefined,
        },
      },
      { onSuccess: () => onOpenChange(false) },
    );
  };

  const handleActivate = () => {
    setInitialPolicy.mutate(
      {
        id: channel.id,
        payload: {
          policy: initialPolicy,
          date: initialPolicy === 'from_date' ? initialDate : undefined,
          days: initialPolicy === 'last_n_days' ? initialDays : undefined,
        },
      },
      { onSuccess: () => onOpenChange(false) },
    );
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{t($ => $.ordersSync.title)}</DialogTitle>
          <DialogDescription>{channel.name}</DialogDescription>
        </DialogHeader>

        <div className="flex flex-col gap-3 text-sm">
          <div className="bg-muted/50 grid grid-cols-2 gap-2 rounded-md p-3">
            <InfoRow label={t($ => $.ordersSync.health)} value={healthLabels[channel.health_status]} />
            <InfoRow
              label={t($ => $.ordersSync.state)}
              value={channel.sync_orders ? t($ => $.ordersSync.enabled) : t($ => $.ordersSync.paused)}
            />
            <InfoRow
              label={t($ => $.ordersSync.checkpoint)}
              value={channel.orders_sync_watermark_at ? new Date(channel.orders_sync_watermark_at).toLocaleString() : '—'}
            />
            <InfoRow
              label={t($ => $.ordersSync.lastError)}
              value={channel.last_error_message ?? '—'}
            />
          </div>

          {notYetActivated ? (
            <div className="flex flex-col gap-2">
              <span className="font-medium">{t($ => $.ordersSync.setupTitle)}</span>
              <p className="text-muted-foreground text-xs">{t($ => $.ordersSync.setupDescription)}</p>
              <select
                className={selectClass}
                value={initialPolicy}
                onChange={(e) => setInitialPolicyChoice(e.target.value as typeof initialPolicy)}
              >
                <option value="from_now">{t($ => $.ordersSync.initialPolicies.fromNow)}</option>
                <option value="from_date">{t($ => $.ordersSync.initialPolicies.fromDate)}</option>
                <option value="last_n_days">{t($ => $.ordersSync.initialPolicies.lastNDays)}</option>
                <option value="historical">{t($ => $.ordersSync.initialPolicies.historical)}</option>
              </select>
              {initialPolicy === 'from_date' && (
                <input type="date" className={inputClass} value={initialDate} onChange={(e) => setInitialDate(e.target.value)} />
              )}
              {initialPolicy === 'last_n_days' && (
                <input
                  type="number"
                  min={1}
                  max={3650}
                  className={inputClass}
                  value={initialDays}
                  onChange={(e) => setInitialDays(Number(e.target.value))}
                />
              )}
            </div>
          ) : isPaused ? (
            <div className="flex flex-col gap-2">
              <span className="font-medium">{t($ => $.ordersSync.resumeTitle)}</span>
              <select
                className={selectClass}
                value={resumePolicy}
                onChange={(e) => setResumePolicy(e.target.value as typeof resumePolicy)}
              >
                <option value="catch_up">{t($ => $.ordersSync.resumePolicies.catchUp)}</option>
                <option value="resume_from_now">{t($ => $.ordersSync.resumePolicies.fromNow)}</option>
                <option value="resume_from_point">{t($ => $.ordersSync.resumePolicies.fromPoint)}</option>
              </select>
              <p className="text-muted-foreground text-xs">
                {resumePolicyDescriptions[resumePolicy]}
              </p>
              {resumePolicy === 'resume_from_point' && (
                <input
                  type="datetime-local"
                  className={inputClass}
                  value={resumeFrom}
                  onChange={(e) => setResumeFrom(e.target.value)}
                />
              )}
            </div>
          ) : (
            <p className="text-muted-foreground text-xs">{t($ => $.ordersSync.pauseDescription)}</p>
          )}
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            {t($ => $.ordersSync.cancel)}
          </Button>
          {notYetActivated ? (
            <Button onClick={handleActivate} disabled={isPending}>
              {t($ => $.ordersSync.activate)}
            </Button>
          ) : isPaused ? (
            <Button onClick={handleResume} disabled={isPending || (resumePolicy === 'resume_from_point' && !resumeFrom)}>
              {t($ => $.ordersSync.resume)}
            </Button>
          ) : (
            <Button variant="destructive" onClick={handlePause} disabled={isPending}>
              {t($ => $.ordersSync.pause)}
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function InfoRow({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex flex-col">
      <span className="text-muted-foreground text-xs">{label}</span>
      <span className="truncate text-sm font-medium">{value}</span>
    </div>
  );
}
