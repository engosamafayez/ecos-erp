import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { HandCoins } from 'lucide-react';

import { ROUTES } from '@/router/routes';

import { GapCard } from './gap-card';

/**
 * Treasury Cash Handover tab.
 *
 * `TripCashHandover` / `CashHandoverController` exposes only `show` and
 * `confirm`, both scoped to one trip — there is no list/index endpoint, so
 * this tab cannot show even a compact real table without inventing one. It
 * deep-links to Trips Workspace, where `CashHandoverPanel` already renders
 * the real per-trip handover state (expected cash, driver-declared cash,
 * received cash, difference) inside that trip's Settlement tab.
 */
export function TreasuryCashHandoverTab() {
  const { t } = useTranslation('returns-settlement');
  const navigate = useNavigate();

  return (
    <GapCard
      icon={HandCoins}
      title={t(($) => $.cashHandover.title)}
      description={t(($) => $.cashHandover.description)}
      note={t(($) => $.cashHandover.note)}
      actions={[
        { label: t(($) => $.cashHandover.action), onClick: () => navigate(ROUTES.logisticsTrips) },
      ]}
    />
  );
}
