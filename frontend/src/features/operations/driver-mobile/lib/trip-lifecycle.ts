/**
 * Canonical driver trip / loading lifecycle vocabulary — ONE source of truth.
 *
 * TASK-DRIVER-APP-PHASE-2-LOADING-VEHICLE-TRIP-001 §28: "Do not implement a second
 * lifecycle map." These string groups mirror the backend `TripStatus` and the loading
 * custody `workflow_state`, and both the Driver Home resolver and the Driver Loading page
 * consume them from here so they cannot drift apart.
 *
 * The strings are the backend enum VALUES verbatim — this file adds no new state and makes
 * no lifecycle decision; it only names the groups the UI already reasoned about inline.
 */

/** The trip has physically left the warehouse — custody is locked, loading is over. */
export const ON_THE_ROAD: readonly string[] = ['dispatched', 'out_for_delivery', 'in_progress'];

/** The trip's operational day is winding down / done. */
export const COMPLETED_STATES: readonly string[] = ['completed', 'settlement_pending', 'closed'];

/** The trip cannot proceed without operator/dispatch intervention. */
export const BLOCKED_STATES: readonly string[] = ['dispatch_blocked', 'cancelled'];

/**
 * A loading `workflow_state` that still needs the driver's action. An item in one of
 * these is NOT confirmed, regardless of any shipment-level `loading_complete` flag — the
 * per-item state is the authority (§7, §22).
 */
export const UNRESOLVED_LOADING: readonly string[] = [
  'pending_loading',
  'awaiting_driver_confirmation',
  'awaiting_driver_reconfirmation',
];

/**
 * TRUE once the trip has genuinely departed (on the road or beyond). This is the point
 * after which loading confirmations are locked — NOT the shipment `loading_complete`
 * flag, which demo/broken data can set while items still await (§3, §31).
 */
export function hasTripDeparted(status: string | null | undefined): boolean {
  if (!status) {
    return false;
  }
  return ON_THE_ROAD.includes(status) || COMPLETED_STATES.includes(status);
}

/**
 * TRUE when the trip is on the road and may therefore execute a delivery — the exact mirror
 * of the backend `TripStatus::acceptsDeliveryExecution()` (= `isOnTheRoad()`), which
 * `DeliveryService::assertTripOnTheRoad()` enforces before Start Delivery / record / outcome
 * (422 `deliveryNotOnTheRoad` otherwise). This is NOT a second lifecycle map — it is the
 * `ON_THE_ROAD` group named after the backend predicate, so the driver UI cannot expose a
 * delivery control the trip cannot legally perform. Unlike `hasTripDeparted`, a COMPLETED /
 * closed trip does NOT accept new delivery execution.
 */
export function acceptsDeliveryExecution(status: string | null | undefined): boolean {
  return status != null && ON_THE_ROAD.includes(status);
}
