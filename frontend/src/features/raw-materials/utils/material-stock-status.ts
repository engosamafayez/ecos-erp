import type { AvailabilityState } from '@/features/raw-materials/types';

export type MaterialStockStatus = 'in_stock' | 'out_of_stock';

/**
 * Presents the ERP availability answer. It does NOT compute it.
 *
 * TASK-PHASE3-GD2-STEP2-CLOSE-001 (Phase 3 Step 2). This function previously
 * ran its own rule — `available > 0 || allow_negative_stock` — which was a
 * second availability engine and could disagree with the backend's canonical
 * `AvailabilityState::fromAvailable()`.
 *
 * GD-2 resolution: `allow_negative_stock` is a permission to PROCEED despite
 * unavailability, applied at the point of action (reserve / manufacture /
 * consume). It does not change what the warehouse physically holds, so it must
 * not change the measured availability shown in a stock column. The platform
 * already separates the two concepts — "can we proceed" is
 * `manufacturing_availability` (ManufacturingAvailabilityService), a distinct
 * field with its own rule.
 *
 * `untracked` (no inventory record at all) collapses to `out_of_stock` because
 * this column is binary; the richer state is available on the API for surfaces
 * that want to distinguish it.
 */
export function resolveMaterialStockStatus(
  availabilityState: AvailabilityState | null | undefined,
): MaterialStockStatus {
  return availabilityState === 'in_stock' ? 'in_stock' : 'out_of_stock';
}
