import { useTranslation } from 'react-i18next';
import { Info, MapPin, PackageCheck } from 'lucide-react';

import { Badge } from '@/components/ui/badge';

/**
 * Honest capability-gap explainer for the Live Driver Map workspace.
 *
 * There is no working driver-location authority anywhere in the platform
 * today: the one route that looks like it should provide it validates and
 * discards its payload, no endpoint lets the web app read a driver's
 * position, and no location-history table exists — only one-off GPS
 * snapshots tied to specific delivery events (a stop, a trip's start/end).
 *
 * What IS solid is the driver custody/goods-handover boundary
 * (Trip::hasFullDriverAcceptance in the Distribution module, surfaced
 * elsewhere in the product as the "Custody Accepted" trip status) — the
 * correct conceptual moment for tracking to begin once it exists. This
 * panel says so plainly, in operational language, rather than presenting
 * a broken map or a bare "coming soon" placeholder.
 */
export function TrackingGapPanel() {
  const { t } = useTranslation('live-driver-map');

  return (
    <div className="flex flex-col gap-6 rounded-lg border bg-card p-4 sm:p-6">
      <div className="flex flex-col gap-3">
        <div className="flex flex-wrap items-center gap-3">
          <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
            <MapPin className="size-5" />
          </span>
          <div className="flex flex-wrap items-center gap-2">
            <h2 className="text-base font-semibold">{t($ => $.gap.heading)}</h2>
            <Badge variant="outline" className="text-muted-foreground">
              {t($ => $.gap.badge)}
            </Badge>
          </div>
        </div>
        <p className="max-w-2xl text-sm leading-relaxed text-muted-foreground">{t($ => $.gap.body)}</p>
      </div>

      <div className="border-t" />

      <div className="flex flex-col gap-3">
        <div className="flex flex-wrap items-center gap-3">
          <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
            <PackageCheck className="size-5" />
          </span>
          <h2 className="text-base font-semibold">{t($ => $.ready.heading)}</h2>
        </div>
        <p className="max-w-2xl text-sm leading-relaxed text-muted-foreground">{t($ => $.ready.body)}</p>
      </div>

      <details className="rounded-lg border p-3 text-xs text-muted-foreground">
        <summary className="inline-flex cursor-pointer items-center gap-1.5 font-medium text-foreground">
          <Info className="size-3.5" />
          {t($ => $.technical.toggle)}
        </summary>
        <p className="mt-2 max-w-2xl leading-relaxed">{t($ => $.technical.body)}</p>
      </details>
    </div>
  );
}
