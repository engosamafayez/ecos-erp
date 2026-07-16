<?php

declare(strict_types=1);

namespace Modules\Operations\Distribution\Application\Services;

use Illuminate\Support\Facades\DB;

class OrderDistributionSyncService
{
    /**
     * The statuses that represent an active (in-progress) distribution stage.
     * Orders in trips with these statuses require impact analysis before editing.
     */
    private const ACTIVE_TRIP_STATUSES = [
        'approved',
        'loading',
        'loading_completed',
        'driver_accepted',
        'dispatch_blocked',
        'out_for_delivery',
    ];

    /**
     * Detect which operational Distribution stage an order is currently in.
     *
     * Returns null if the order is not assigned to any active trip.
     * Returns an array with trip/wave info and the mapped operational stage name.
     */
    public function getOrderOperationalStage(int|string $orderId): ?array
    {
        $row = DB::table('distribution_trip_orders as dto')
            ->join('distribution_trips as dt', 'dt.id', '=', 'dto.distribution_trip_id')
            ->leftJoin('preparation_waves as pw', 'pw.id', '=', 'dt.preparation_wave_id')
            ->where('dto.order_id', $orderId)
            ->whereNotIn('dt.status', ['completed', 'closed', 'cancelled'])
            ->select([
                'dt.id        as trip_id',
                'dt.trip_number',
                'dt.status    as trip_status',
                'dt.name      as trip_name',
                DB::raw("COALESCE(pw.wave_number::text, '') as wave_number"),
                'pw.id        as wave_id',
                'dto.zone_code_snapshot',
                'dto.governorate_snapshot',
            ])
            ->orderByRaw("CASE dt.status
                WHEN 'out_for_delivery' THEN 1
                WHEN 'driver_accepted'  THEN 2
                WHEN 'dispatch_blocked' THEN 3
                WHEN 'loading_completed' THEN 4
                WHEN 'loading'          THEN 5
                WHEN 'approved'         THEN 6
                ELSE 7
            END")
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'trip_id'             => $row->trip_id,
            'trip_number'         => $row->trip_number,
            'trip_name'           => $row->trip_name,
            'trip_status'         => $row->trip_status,
            'wave_id'             => $row->wave_id ?: null,
            'wave_number'         => $row->wave_number ?: null,
            'zone_code'           => $row->zone_code_snapshot,
            'governorate'         => $row->governorate_snapshot,
            'stage'               => $this->mapStage($row->trip_status),
            'is_active'           => in_array($row->trip_status, self::ACTIVE_TRIP_STATUSES, true),
            'impact_list'         => $this->buildImpactList($row->trip_status),
            'manifest_exists'     => $this->manifestExists($row->trip_id),
        ];
    }

    /**
     * Record a synchronization event in the audit log.
     */
    public function recordSyncEvent(
        int|string $orderId,
        string     $tripId,
        string     $action,
        string     $tripStage,
        array      $changedFields,
        array      $previousValues,
        array      $newValues,
        ?int       $performedBy,
        bool       $manifestRegenerated = false,
        ?string    $notes = null,
    ): void {
        DB::table('distribution_order_sync_events')->insert([
            'order_id'             => $orderId,
            'distribution_trip_id' => $tripId,
            'action'               => $action,
            'trip_stage'           => $tripStage,
            'changed_fields'       => json_encode($changedFields),
            'previous_values'      => json_encode($previousValues),
            'new_values'           => json_encode($newValues),
            'performed_by'         => $performedBy,
            'manifest_regenerated' => $manifestRegenerated,
            'notes'                => $notes,
            'synced_at'            => now(),
        ]);
    }

    /**
     * Get the full sync event history for an order.
     */
    public function getSyncHistory(int|string $orderId): array
    {
        return DB::table('distribution_order_sync_events as dse')
            ->leftJoin('users as u', 'u.id', '=', 'dse.performed_by')
            ->leftJoin('distribution_trips as dt', 'dt.id', '=', 'dse.distribution_trip_id')
            ->where('dse.order_id', $orderId)
            ->select([
                'dse.*',
                DB::raw("u.name as performed_by_name"),
                DB::raw("dt.trip_number"),
            ])
            ->orderBy('dse.synced_at', 'desc')
            ->get()
            ->map(fn ($row) => [
                'id'                   => $row->id,
                'action'               => $row->action,
                'trip_stage'           => $row->trip_stage,
                'trip_number'          => $row->trip_number,
                'changed_fields'       => json_decode($row->changed_fields ?? '[]', true),
                'previous_values'      => json_decode($row->previous_values ?? '{}', true),
                'new_values'           => json_decode($row->new_values ?? '{}', true),
                'manifest_regenerated' => (bool) $row->manifest_regenerated,
                'notes'                => $row->notes,
                'performed_by_name'    => $row->performed_by_name,
                'synced_at'            => $row->synced_at,
            ])
            ->toArray();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────────

    private function mapStage(string $tripStatus): string
    {
        return match ($tripStatus) {
            'planning'          => 'Planning',
            'approved'          => 'Approved — Awaiting Loading',
            'loading'           => 'Loading In Progress',
            'loading_completed' => 'Loading Completed',
            'driver_accepted'   => 'Driver Accepted',
            'dispatch_blocked'  => 'Dispatch Blocked',
            'out_for_delivery'  => 'Out for Delivery',
            'settlement_pending' => 'Settlement Pending',
            default             => ucfirst(str_replace('_', ' ', $tripStatus)),
        };
    }

    private function buildImpactList(string $tripStatus): array
    {
        return match (true) {
            $tripStatus === 'planning' => [
                'Order will be re-evaluated for the wave.',
            ],
            $tripStatus === 'approved' => [
                'Loading manifest will need to be regenerated.',
                'Warehouse staff will receive updated product quantities.',
                'Zone assignment may need to be reviewed.',
            ],
            in_array($tripStatus, ['loading', 'loading_completed'], true) => [
                'Loading is currently in progress — changes require supervisor approval.',
                'Loading manifest will need to be regenerated.',
                'Confirmed products in the loading bay may be affected.',
                'Warehouse supervisor must re-confirm after changes.',
            ],
            in_array($tripStatus, ['driver_accepted', 'dispatch_blocked'], true) => [
                'Driver has already accepted the trip — changes are highly disruptive.',
                'Dispatch clearance will be revoked and must be re-evaluated.',
                'All driver acceptance records will be reset.',
            ],
            $tripStatus === 'out_for_delivery' => [
                'Vehicle is currently en route — only address and contact updates are permitted.',
                'Driver mobile app will receive the updated delivery information.',
            ],
            default => [
                'Changes to this order will be synchronized with the distribution trip.',
            ],
        };
    }

    private function manifestExists(string $tripId): bool
    {
        return DB::table('distribution_loading_manifests')
            ->where('distribution_trip_id', $tripId)
            ->exists();
    }
}
