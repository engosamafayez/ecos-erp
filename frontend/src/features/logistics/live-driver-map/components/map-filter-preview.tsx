import { useTranslation } from 'react-i18next';
import { Lock, MapPin } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

const SELECT_CLASS =
  'border-input h-9 w-full rounded-md border bg-transparent px-3 text-sm shadow-xs disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50';

/**
 * Shows the intended shape of the future live-map toolbar — status chips,
 * entity/date filters, and the map canvas itself — entirely as disabled
 * chrome. Every control below uses the native `disabled` attribute and has
 * no click/change handler: none of it pretends to filter data that doesn't
 * exist. This is preview-of-the-shape, not a functional (or fake) filter.
 */
export function MapFilterPreview() {
  const { t } = useTranslation('live-driver-map');

  const statusChips = [
    t($ => $.filters.status.all),
    t($ => $.filters.status.onRoute),
    t($ => $.filters.status.atStop),
    t($ => $.filters.status.returning),
    t($ => $.filters.status.delayed),
  ];

  const selectFields = [
    { label: t($ => $.filters.fields.driver.label), placeholder: t($ => $.filters.fields.driver.placeholder) },
    { label: t($ => $.filters.fields.vehicle.label), placeholder: t($ => $.filters.fields.vehicle.placeholder) },
    { label: t($ => $.filters.fields.trip.label), placeholder: t($ => $.filters.fields.trip.placeholder) },
    { label: t($ => $.filters.fields.zone.label), placeholder: t($ => $.filters.fields.zone.placeholder) },
    { label: t($ => $.filters.fields.branch.label), placeholder: t($ => $.filters.fields.branch.placeholder) },
  ];

  return (
    <div className="flex flex-col gap-4 rounded-lg border bg-card p-4 sm:p-6">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">
          {t($ => $.filters.sectionLabel)}
        </h2>
        <Badge variant="outline" className="gap-1 text-muted-foreground">
          <Lock className="size-3" />
          {t($ => $.filters.previewBadge)}
        </Badge>
      </div>

      <div className="flex flex-wrap gap-2">
        {statusChips.map((label) => (
          <Button key={label} type="button" variant="outline" size="sm" disabled>
            {label}
          </Button>
        ))}
      </div>

      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        {selectFields.map((field) => (
          <div key={field.label} className="flex flex-col gap-1.5">
            <span className="text-xs font-medium text-muted-foreground">{field.label}</span>
            <select disabled aria-label={field.label} className={SELECT_CLASS}>
              <option>{field.placeholder}</option>
            </select>
          </div>
        ))}

        <div className="flex flex-col gap-1.5">
          <span className="text-xs font-medium text-muted-foreground">{t($ => $.filters.fields.date.label)}</span>
          <Input type="date" disabled aria-label={t($ => $.filters.fields.date.label)} className="h-9" />
        </div>
      </div>

      <div className="flex flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed bg-muted/30 py-16 text-center">
        <MapPin className="size-8 text-muted-foreground/40" />
        <p className="max-w-sm text-sm text-muted-foreground">{t($ => $.canvas.caption)}</p>
      </div>
    </div>
  );
}
