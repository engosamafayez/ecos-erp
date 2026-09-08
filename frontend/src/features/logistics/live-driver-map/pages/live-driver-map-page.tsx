import { useTranslation } from 'react-i18next';

import { PageHeader } from '@/components/crud';

import { TrackingGapPanel } from '../components/tracking-gap-panel';
import { MapFilterPreview } from '../components/map-filter-preview';

/**
 * Live Driver Map workspace.
 *
 * This is an honest capability-gap page, not a broken map. No driver-location
 * authority exists in the platform yet: the one route that looks like it
 * should provide live GPS (POST driver trip GPS) validates and discards its
 * payload by design, nothing lets the web app read a driver's position, and
 * there is no location-history table — only one-off snapshots tied to
 * specific delivery events (a stop, a trip's start/end). See
 * TrackingGapPanel for the plain-language explanation and the one boundary
 * that IS solid today: driver custody acceptance
 * (Trip::hasFullDriverAcceptance), which is where live tracking will start
 * once location data exists.
 *
 * The map/filter chrome in MapFilterPreview is fully disabled (native
 * `disabled` attributes, no handlers) — it previews the future page's shape
 * without pretending to be functional.
 */
export function LiveDriverMapPage() {
  const { t } = useTranslation('live-driver-map');

  return (
    <div className="flex flex-col gap-4 p-4">
      <PageHeader title={t($ => $.pageTitle)} subtitle={t($ => $.pageSubtitle)} />
      <TrackingGapPanel />
      <MapFilterPreview />
    </div>
  );
}
