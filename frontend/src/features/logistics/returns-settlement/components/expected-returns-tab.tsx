import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { PackageSearch } from 'lucide-react';

import { ROUTES } from '@/router/routes';

import { GapCard } from './gap-card';

/**
 * Expected Driver Returns tab.
 *
 * The task spec asks for Expected Driver Returns vs. Physical Warehouse Stock
 * vs. Current Shortage vs. Projected Shortage. There is no operator-facing
 * aggregate endpoint over TripReturn to build that comparison from — the only
 * route is `GET /driver/trips/{tripId}/returns`, scoped to the authenticated
 * driver's own app. Computing "projected shortage" (or any of the other three
 * figures) client-side from scattered per-trip reads would mean inventing a
 * shortage-calculation rule this workspace has no authority to define, so this
 * tab deliberately shows no numbers at all — real returns are still fully
 * visible per trip inside Trips Workspace.
 */
export function ExpectedReturnsTab() {
  const { t } = useTranslation('returns-settlement');
  const navigate = useNavigate();

  return (
    <GapCard
      icon={PackageSearch}
      title={t(($) => $.expectedReturns.title)}
      description={t(($) => $.expectedReturns.description)}
      note={t(($) => $.expectedReturns.note)}
      actions={[
        { label: t(($) => $.expectedReturns.action), onClick: () => navigate(ROUTES.logisticsTrips) },
      ]}
    />
  );
}
