/**
 * Raw Material Stock Status — exactly three business states.
 *
 * TASK-ORDERS-MATERIALS-STATUS-AND-SCHEDULE-POSITION-FIX-001.
 *
 * The status answers one commercial question — "can this material be committed
 * to right now, and does it have stock?" — from exactly two canonical inputs:
 *
 *     Available  (signed, `on_hand − reserved`, may be negative)
 *     Allow Negative Stock
 *
 *   Available > 0                          → In Stock
 *   Available <= 0  AND  allow_negative    → Negative Allowed
 *   Available <= 0  AND  !allow_negative   → Out of Stock
 *
 * `untracked` WAS a fourth state and is deliberately gone. It described the
 * SYSTEM (no `inventory_items` row exists) rather than the BUSINESS position,
 * and the two are not the same question. A material nobody has stocked and a
 * material that has run out are commercially identical: neither can be supplied,
 * and whether you may still commit against it is decided by Allow Negative, not
 * by whether a ledger row happens to exist. Presence of an inventory record is
 * therefore no longer an input here — PART 4.
 *
 * A null / undefined `available` is read as 0 for exactly that reason: it means
 * "nothing recorded", which is a quantity of nothing, not an unknown. So an
 * untracked material with Allow Negative OFF is **Out of Stock** (TEST 7), and
 * with Allow Negative ON it is **Negative Allowed**.
 *
 * This function still PRESENTS; it does not compute availability. `available_qty`
 * is produced server-side by the canonical signed calculation and is passed
 * through untouched — no clamping, no re-derivation.
 */
export type MaterialStockStatus = 'in_stock' | 'out_of_stock' | 'negative_allowed';

export function resolveMaterialStockStatus(
  availableQty: number | null | undefined,
  allowNegativeStock?: boolean | null,
  serverState?: MaterialStockStatus | null,
): MaterialStockStatus {
  // T-1 — PREFER THE SERVER'S ANSWER.
  //
  // `availability_state` is projected by the backend `ProductAvailability` enum, which is
  // the same rule the availability FILTER selects on. Consuming it means the badge and the
  // filter cannot disagree, and the classification exists in exactly one place.
  //
  // The computation below is retained ONLY as a fallback for payloads that predate the
  // field. It implements the identical rule, so the two can never differ — but while it
  // was the primary path it was a second, independently-maintained copy of a business
  // rule, which is precisely how the Products and Raw Materials surfaces came to disagree.
  if (serverState) {
    return serverState;
  }

  // Nothing recorded is a quantity of nothing — see the note above.
  const available = availableQty ?? 0;

  // CASE A — positive availability wins regardless of the negative-stock policy.
  if (available > 0) {
    return 'in_stock';
  }

  // CASE C — nothing available, but the policy permits committing anyway.
  if (allowNegativeStock === true) {
    return 'negative_allowed';
  }

  // CASE B — nothing available and no permission to go below zero.
  return 'out_of_stock';
}
