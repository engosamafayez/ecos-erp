<?php

declare(strict_types=1);

namespace Modules\Operations\Distribution\Application\Services;

use Illuminate\Support\Facades\DB;
use Modules\Operations\Distribution\Application\Services\TripAuditService;
use Modules\Operations\Distribution\Domain\Models\DistributionLoadingManifest;
use Modules\Operations\Distribution\Domain\Models\DistributionLoadingManifestItem;
use Modules\Operations\Distribution\Domain\Models\DistributionTrip;
use Modules\Operations\Distribution\Domain\Models\DistributionTripCustody;
use RuntimeException;

class DriverHandoverService
{
    public function __construct(private readonly TripAuditService $audit) {}

    /**
     * Return the full driver handover status for a trip.
     * Called by DriverHandoverController::handoverStatus().
     */
    public function handoverStatus(string $tripId): array
    {
        $manifest     = DistributionLoadingManifest::with('items')->where('distribution_trip_id', $tripId)->first();
        $custodyItems = DistributionTripCustody::where('distribution_trip_id', $tripId)->get();

        if (!$manifest || $manifest->status !== 'completed') {
            return [
                'loading_phase'   => 'warehouse',
                'manifest'        => $manifest ? $this->formatManifestMini($manifest) : null,
                'custody'         => $this->formatCustody($custodyItems),
                'can_dispatch'    => false,
                'dispatch_issues' => [['message' => 'Warehouse loading not yet completed.', 'severity' => 'error']],
            ];
        }

        $issues = [];

        $pendingDriverConfirm = $manifest->items->where('driver_status', 'pending')->count();
        $driverDiscrepancies  = $manifest->items->where('driver_status', 'discrepancy')->count();

        if ($pendingDriverConfirm > 0) {
            $issues[] = [
                'message'  => "{$pendingDriverConfirm} product" . ($pendingDriverConfirm !== 1 ? 's' : '') . " awaiting driver confirmation.",
                'severity' => 'error',
            ];
        }

        if ($driverDiscrepancies > 0) {
            $issues[] = [
                'message'  => "{$driverDiscrepancies} product discrepanc" . ($driverDiscrepancies !== 1 ? 'ies require' : 'y requires') . " supervisor review.",
                'severity' => 'error',
            ];
        }

        $unconfirmedCustody = $custodyItems->where('is_driver_confirmed', false)->count();
        if ($unconfirmedCustody > 0) {
            $issues[] = [
                'message'  => "{$unconfirmedCustody} custody item" . ($unconfirmedCustody !== 1 ? 's' : '') . " awaiting driver confirmation.",
                'severity' => 'error',
            ];
        }

        return [
            'loading_phase'   => 'driver_handover',
            'manifest'        => $this->formatManifestMini($manifest),
            'custody'         => $this->formatCustody($custodyItems),
            'can_dispatch'    => empty($issues),
            'dispatch_issues' => $issues,
        ];
    }

    /**
     * Driver confirms the quantity received for one product.
     * Discrepancy is detected automatically (received vs loaded).
     */
    public function confirmProductReceipt(
        DistributionLoadingManifestItem $item,
        float $receivedQty,
        int $userId,
    ): DistributionLoadingManifestItem {
        if ($item->driver_status === 'confirmed' || $item->driver_status === 'accepted') {
            throw new RuntimeException('Product receipt already confirmed.');
        }

        $hasDiscrepancy = abs($receivedQty - (float) $item->loaded_qty) > 0.001;

        $item->update([
            'driver_received_qty' => $receivedQty,
            'driver_status'       => $hasDiscrepancy ? 'discrepancy' : 'confirmed',
            'driver_confirmed_at' => now(),
            'driver_confirmed_by' => $userId,
        ]);

        return $item->fresh();
    }

    /**
     * Supervisor accepts a product quantity discrepancy and unlocks dispatch.
     */
    public function acceptDiscrepancy(
        DistributionLoadingManifestItem $item,
        int $userId,
        ?string $notes = null,
    ): DistributionLoadingManifestItem {
        if ($item->driver_status !== 'discrepancy') {
            throw new RuntimeException('Item does not have an active discrepancy.');
        }

        $item->update([
            'driver_status' => 'accepted',
            'shortage_notes' => $notes ?? $item->shortage_notes,
            'driver_confirmed_by' => $userId,
        ]);

        return $item->fresh();
    }

    /**
     * Driver confirms receipt of a custody item (cash, device, equipment, etc.).
     */
    public function confirmCustodyItem(
        DistributionTripCustody $item,
        int $receivedQty,
        int $userId,
    ): DistributionTripCustody {
        $item->update([
            'received_quantity'   => $receivedQty,
            'is_driver_confirmed' => true,
            'driver_confirmed_at' => now(),
            'driver_confirmed_by' => $userId,
        ]);

        return $item->fresh();
    }

    /**
     * Authorize dispatch — validates all conditions, updates trip to 'dispatched'.
     */
    public function authorizeDispatch(DistributionTrip $trip, int $userId): DistributionTrip
    {
        if ($trip->status !== 'ready_for_dispatch') {
            throw new RuntimeException('Trip must be in Ready for Dispatch status to authorize dispatch.');
        }

        $status = $this->handoverStatus($trip->id);

        if (!$status['can_dispatch']) {
            $firstIssue = $status['dispatch_issues'][0]['message'] ?? 'Dispatch conditions not met.';
            throw new RuntimeException($firstIssue);
        }

        DB::transaction(function () use ($trip, $userId): void {
            $trip->update([
                'status'        => 'dispatched',
                'dispatched_at' => now(),
                'dispatched_by' => $userId,
            ]);
        });

        return $trip->fresh();
    }

    /**
     * Formal driver acceptance — 3 mandatory confirmations (ADR-DIST-007).
     * Sets trip status to 'driver_accepted' or 'dispatch_blocked' if a discrepancy is reported.
     */
    public function driverAcceptTrip(
        DistributionTrip $trip,
        bool    $productsAccepted,
        bool    $custodyAccepted,
        bool    $equipmentAccepted,
        bool    $hasDiscrepancy,
        ?string $discrepancyNotes,
        int     $userId,
    ): DistributionTrip {
        if ($trip->status !== 'loading_completed') {
            throw new RuntimeException('Driver acceptance requires trip status to be Loading Completed.');
        }

        $fromStatus = $trip->status;
        $toStatus   = $hasDiscrepancy ? 'dispatch_blocked' : 'driver_accepted';

        DB::transaction(function () use ($trip, $productsAccepted, $custodyAccepted, $equipmentAccepted, $hasDiscrepancy, $discrepancyNotes, $userId, $fromStatus, $toStatus): void {
            $trip->update([
                'driver_accepted_products'  => $productsAccepted,
                'driver_accepted_custody'   => $custodyAccepted,
                'driver_accepted_equipment' => $equipmentAccepted,
                'driver_acceptance_at'      => now(),
                'driver_acceptance_by'      => $userId,
                'has_discrepancy'           => $hasDiscrepancy,
                'discrepancy_notes'         => $discrepancyNotes,
                'status'                    => $toStatus,
            ]);

            $action = $hasDiscrepancy ? 'dispatch_blocked' : 'driver_accepted';
            $this->audit->record($trip->id, $action, $fromStatus, $toStatus, $userId, $discrepancyNotes);
        });

        return $trip->fresh();
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function formatManifestMini(DistributionLoadingManifest $manifest): array
    {
        $items = $manifest->items;

        return [
            'id'                  => $manifest->id,
            'status'              => $manifest->status,
            'total_products'      => $manifest->total_products,
            'driver_confirmed'    => $items->whereIn('driver_status', ['confirmed', 'accepted'])->count(),
            'driver_discrepancies' => $items->where('driver_status', 'discrepancy')->count(),
            'driver_pending'      => $items->where('driver_status', 'pending')->count(),
            'items'               => $items->map(fn (DistributionLoadingManifestItem $i) => [
                'id'                  => $i->id,
                'product_name'        => $i->product_name,
                'product_sku'         => $i->product_sku,
                'loaded_qty'          => $i->loaded_qty,
                'driver_received_qty' => $i->driver_received_qty,
                'driver_status'       => $i->driver_status,
                'driver_confirmed_at' => $i->driver_confirmed_at,
                'shortage_qty'        => $i->shortage_qty,
            ])->values(),
        ];
    }

    private function formatCustody(\Illuminate\Support\Collection $items): array
    {
        return [
            'total'     => $items->count(),
            'confirmed' => $items->where('is_driver_confirmed', true)->count(),
            'items'     => $items->map(fn (DistributionTripCustody $c) => [
                'id'                  => $c->id,
                'item_type'           => $c->item_type,
                'label'               => $c->label,
                'quantity'            => $c->quantity,
                'received_quantity'   => $c->received_quantity,
                'is_driver_confirmed' => $c->is_driver_confirmed,
                'driver_confirmed_at' => $c->driver_confirmed_at,
            ])->values(),
        ];
    }
}
