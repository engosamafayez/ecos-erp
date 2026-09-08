import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { ShieldAlert } from 'lucide-react';

import { ROUTES } from '@/router/routes';

import { GapCard } from './gap-card';

/**
 * Return Discrepancies tab.
 *
 * Discrepancy quantities exist in two separate, real places today:
 *  - reconciliation `variance` per vehicle assignment, from
 *    `loadingOsService.getReconciliation` (Loading Workspace) — needs a known
 *    session + assignment id, no cross-trip list;
 *  - driver-declared `TripReturn.discrepancy_qty` / `driver_liable` per trip,
 *    visible on the Trips Workspace's Returns tab — no operator-facing
 *    cross-trip list either (see ExpectedReturnsTab for the same gap).
 *
 * Neither exposes a combined, cross-trip discrepancy list, and looping over
 * every assignment/trip client-side to fake one would mean inventing the
 * aggregation the backend doesn't provide — exactly what this workspace is
 * not allowed to do. Severity/liability is never computed here either way:
 * both real sources are linked instead of merged.
 */
export function ReturnDiscrepanciesTab() {
  const { t } = useTranslation('returns-settlement');
  const navigate = useNavigate();

  return (
    <GapCard
      icon={ShieldAlert}
      title={t(($) => $.discrepancies.title)}
      description={t(($) => $.discrepancies.description)}
      note={t(($) => $.discrepancies.note)}
      actions={[
        { label: t(($) => $.discrepancies.primaryAction), onClick: () => navigate(ROUTES.loadingOsWorkspace) },
        { label: t(($) => $.discrepancies.secondaryAction), onClick: () => navigate(ROUTES.logisticsTrips) },
      ]}
    />
  );
}
