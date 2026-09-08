import { useTranslation } from 'react-i18next';
import { UserCog } from 'lucide-react';

import { ROUTES } from '@/router/routes';

import { OpenWorkspaceLink } from './open-workspace-link';

/**
 * Deep-link-only, by design.
 *
 * The Groups tab's own source (`SlotSummary`, from `GET .../windows/{window}/slots`)
 * carries no vehicle/driver-assignment field — its type docblock says so directly:
 * "A group is a planning container... It is NOT a vehicle: the table carries no
 * vehicle_id and no driver_id, and that absence is deliberate — vehicle planning is
 * a later phase." The only canonical source of a Group's vehicle/driver assignment
 * is `getGroupTrips` (`GET .../slots/{slot}/trips`), which is PER-GROUP by design —
 * it is exactly what the existing Distribution Workspace's own "Vehicle & Driver"
 * detail tab calls, one Group at a time, once an operator opens that Group.
 *
 * Fanning that same per-group call out across every Group just to populate this
 * summary tab would multiply requests every time this coordination page is opened,
 * which is the "second endpoint" the workspace is supposed to stay light of. Rather
 * than fabricate an "awaiting assignment" flag from a response that does not carry
 * it, this tab says so honestly and links straight to where the assignment is
 * actually made.
 */
export function AssignmentTab() {
  const { t } = useTranslation('dispatch-execution');

  return (
    <div className="flex flex-col items-center justify-center gap-3 rounded-lg border border-dashed py-14 text-center">
      <span className="flex size-12 items-center justify-center rounded-full bg-muted text-muted-foreground">
        <UserCog className="size-6" />
      </span>
      <div className="max-w-md space-y-1">
        <p className="font-medium">{t($ => $.assignment.title)}</p>
        <p className="text-sm text-muted-foreground">{t($ => $.assignment.explain)}</p>
      </div>
      <OpenWorkspaceLink
        to={ROUTES.logisticsDistributionWorkspace}
        label={t($ => $.openFullWorkspace)}
        prominent
      />
    </div>
  );
}
