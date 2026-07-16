<?php

declare(strict_types=1);

namespace Modules\Operations\Distribution\Application\Services;

use Illuminate\Support\Facades\DB;

class TripAuditService
{
    public function record(
        string  $tripId,
        string  $action,
        ?string $fromStatus,
        ?string $toStatus,
        ?int    $performedBy,
        ?string $notes    = null,
        ?array  $metadata = null,
    ): void {
        DB::table('distribution_trip_audit_trail')->insert([
            'distribution_trip_id' => $tripId,
            'action'               => $action,
            'from_status'          => $fromStatus,
            'to_status'            => $toStatus,
            'performed_by'         => $performedBy,
            'notes'                => $notes,
            'metadata'             => $metadata !== null ? json_encode($metadata, JSON_THROW_ON_ERROR) : null,
            'performed_at'         => now(),
        ]);
    }

    public function getTrail(string $tripId): array
    {
        return DB::table('distribution_trip_audit_trail as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.performed_by')
            ->where('a.distribution_trip_id', $tripId)
            ->orderBy('a.performed_at', 'desc')
            ->select([
                'a.id',
                'a.action',
                'a.from_status',
                'a.to_status',
                DB::raw("COALESCE(u.name, 'System') as performed_by_name"),
                'a.notes',
                'a.performed_at',
            ])
            ->get()
            ->map(fn ($r) => (array) $r)
            ->toArray();
    }
}
