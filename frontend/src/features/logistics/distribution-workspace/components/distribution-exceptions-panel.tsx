import { OrdersAwaitingGroup, ZonesWithoutGroup } from './distribution-groups-panel';

/**
 * EXCEPTIONS PANEL — TASK-DISTRIBUTION-PLANNING-WORKSPACE-PHASE-1.
 *
 * Composition only. It re-hosts the two already-certified exception surfaces
 * (`ZonesWithoutGroup` = the root cause, `OrdersAwaitingGroup` = the affected
 * orders) inside the on-demand overlay drawer instead of appending them to the
 * bottom of the Groups board, so they never permanently consume workspace width.
 * No filtering, counting or blocker logic is added or duplicated here — both
 * components keep reading the same canonical `awaiting-group` endpoint they always
 * did, including their own loading, empty and error states.
 */
export function DistributionExceptionsPanel({
  windowId,
  warehouseId,
}: {
  windowId: string | undefined;
  warehouseId: string | null;
}) {
  return (
    <div className="space-y-3" data-testid="distribution-exceptions">
      <ZonesWithoutGroup windowId={windowId} warehouseId={warehouseId} />
      <OrdersAwaitingGroup windowId={windowId} warehouseId={warehouseId} />
    </div>
  );
}
