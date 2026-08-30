import { useTranslation } from 'react-i18next';

import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { useFormatter } from '@/hooks/use-formatter';

import type { SlotSummary, ZoneSummary } from '../types';

/**
 * Impact preview for Add / Remove / Move Zone.
 *
 * ┌─ EVERY NUMBER HERE IS THE SERVER'S ──────────────────────────────────────┐
 * │ The "before" figures come straight from the Group read model, and the     │
 * │ "after" figures are those same numbers plus or minus the ZONE'S OWN       │
 * │ server-computed totals. Nothing is re-derived from the order rows: a       │
 * │ second definition of "orders in a group" is exactly how a preview starts   │
 * │ disagreeing with the thing it is previewing.                              │
 * │                                                                           │
 * │ They are a PROJECTION, not a promise — the window is live, so the real     │
 * │ result is whatever the server computes on confirm.                        │
 * └───────────────────────────────────────────────────────────────────────────┘
 */

export type ZoneAction = 'add' | 'remove' | 'move';

function Delta({
  label,
  before,
  after,
}: {
  label: string;
  before: string | number;
  after: string | number;
}) {
  const changed = String(before) !== String(after);

  return (
    <div className="flex items-baseline justify-between gap-4 py-0.5">
      <dt className="text-xs uppercase text-muted-foreground">{label}</dt>
      <dd className="flex items-baseline gap-2 tabular-nums">
        <span className={changed ? 'text-muted-foreground line-through' : 'font-medium'}>
          {before}
        </span>
        {changed ? <span className="font-semibold">→ {after}</span> : null}
      </dd>
    </div>
  );
}

function GroupProjection({
  title,
  group,
  zone,
  sign,
  money,
}: {
  title: string;
  group: SlotSummary;
  zone: ZoneSummary;
  /** +1 the zone joins this group, -1 it leaves. */
  sign: 1 | -1;
  money: (n: number) => string;
}) {
  const { t } = useTranslation('logistics');

  return (
    <div className="rounded-md border p-3">
      <p className="mb-1 text-sm font-semibold">
        {title}: {group.code}
        {group.name ? ` — ${group.name}` : ''}
      </p>
      <dl>
        <Delta
          label={t(($) => $.distributionWorkspace.metrics.zones)}
          before={group.zones_count}
          after={group.zones_count + sign}
        />
        <Delta
          label={t(($) => $.distributionWorkspace.metrics.orders)}
          before={group.orders_count}
          after={group.orders_count + sign * zone.order_count}
        />
        <Delta
          label={t(($) => $.distributionWorkspace.metrics.products)}
          before={group.products_count}
          after={group.products_count + sign * zone.products_count}
        />
        <Delta
          label={t(($) => $.distributionWorkspace.metrics.orderValue)}
          before={money(group.total_value)}
          after={money(group.total_value + sign * zone.total_value)}
        />
      </dl>
    </div>
  );
}

export function ZoneImpactDialog({
  action,
  zone,
  group,
  destination,
  open,
  pending,
  error,
  onConfirm,
  onOpenChange,
}: {
  action: ZoneAction;
  zone: ZoneSummary | null;
  /** The group the zone is in (remove/move) or joining (add). */
  group: SlotSummary | null;
  /** Move only — where it is going. */
  destination?: SlotSummary | null;
  open: boolean;
  pending: boolean;
  error: string | null;
  onConfirm: () => void;
  onOpenChange: (open: boolean) => void;
}) {
  const { money } = useFormatter();
  const { t } = useTranslation('logistics');

  if (!zone || !group) return null;

  const zoneName =
    zone.zone_name ?? t(($) => $.distributionWorkspace.zoneFallback, { id: zone.zone_id });

  const title =
    action === 'add'
      ? t(($) => $.distributionWorkspace.impact.titleAdd, { zone: zoneName })
      : action === 'remove'
        ? t(($) => $.distributionWorkspace.impact.titleRemove, { zone: zoneName })
        : t(($) => $.distributionWorkspace.impact.titleMove, { zone: zoneName });

  return (
    <AlertDialog open={open} onOpenChange={onOpenChange}>
      <AlertDialogContent className="max-w-lg">
        <AlertDialogHeader>
          <AlertDialogTitle>{title}</AlertDialogTitle>
          <AlertDialogDescription asChild>
            <div className="space-y-3 pt-1">
              <p className="text-sm">
                {t(($) => $.distributionWorkspace.impact.carries, {
                  orders: zone.order_count,
                  products: zone.products_count,
                  value: money(zone.total_value),
                })}
              </p>

              {action === 'move' && destination ? (
                <div className="space-y-2">
                  <GroupProjection
                    title={t(($) => $.distributionWorkspace.impact.from)}
                    group={group}
                    zone={zone}
                    sign={-1}
                    money={money}
                  />
                  <GroupProjection
                    title={t(($) => $.distributionWorkspace.impact.to)}
                    group={destination}
                    zone={zone}
                    sign={1}
                    money={money}
                  />
                </div>
              ) : (
                <GroupProjection
                  title={t(($) => $.distributionWorkspace.impact.group)}
                  group={group}
                  zone={zone}
                  sign={action === 'add' ? 1 : -1}
                  money={money}
                />
              )}

              {action === 'remove' ? (
                <p className="text-xs text-muted-foreground">
                  {t(($) => $.distributionWorkspace.impact.removeNote)}
                </p>
              ) : null}

              {error ? <p className="text-sm text-destructive">{error}</p> : null}
            </div>
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel disabled={pending}>
            {t(($) => $.common.cancel)}
          </AlertDialogCancel>
          <AlertDialogAction
            onClick={(e) => {
              // Keep the dialog open so a server rejection is visible in place
              // rather than vanishing with the dialog.
              e.preventDefault();
              onConfirm();
            }}
            disabled={pending}
            data-testid="confirm-zone-action"
          >
            {pending
              ? t(($) => $.distributionWorkspace.impact.working)
              : t(($) => $.common.confirm)}
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  );
}
