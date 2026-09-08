<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Logistics\Distribution\Domain\Models\DeliveryException;
use Modules\Logistics\Distribution\Domain\Models\DeliveryStop;
use Modules\Logistics\Distribution\Domain\Models\DriverLocationPing;
use Modules\Logistics\Distribution\Domain\Models\Trip;

/**
 * TASK-ECOS-SHIPPING-OS-REDESIGN-004 §8/§13 — Live Driver Map and Route
 * History read models.
 *
 * Both endpoints are read-only projections of existing canonical data
 * (Trip, its driver/vehicle pairing, DeliveryStop, DeliveryException) plus
 * DriverLocationPing — the one new time series this task introduced (see
 * that model's own docblock). Neither endpoint is a second tracking engine:
 * trackability is Trip::isTrackable()/scopeTrackable(), the same boundary
 * DriverRuntimeController::gps() writes against.
 */
class LiveMapController extends Controller
{
    /**
     * GET /logistics/distribution/live-map
     *
     * One bounded, tenant-scoped snapshot of every currently trackable Trip
     * (§4/§8) — never every Trip, only ones inside Trip::scopeTrackable()'s
     * custody+execution boundary, so an assigned-but-not-yet-departed or an
     * already-closed Trip never shows as "live". Four queries total
     * regardless of row count (trips + the driver/vehicle belongsTo chain +
     * the latestLocationPing correlated subquery + the withCount subselects)
     * — no per-trip query (§8/§27).
     *
     * Unfiltered by design: the trackable set is bounded by definition (at
     * most as many rows as this company has Trips simultaneously on the
     * road), so a server-side filter API is complexity this V1 read does not
     * need — the frontend narrows the returned set client-side.
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);

        $trips = Trip::query()
            ->trackable()
            ->where('company_id', $companyId)
            ->with(['driverVehicleAssignment.driver', 'driverVehicleAssignment.vehicle', 'latestLocationPing'])
            ->withCount([
                'stops',
                // "Completed" = left the two unsettled statuses — the same predicate
                // TripController::index()/loadTrip() already use for this rollup.
                'stops as stops_completed_count' => fn ($q) => $q->whereNotIn('status', ['pending', 'in_progress']),
                'exceptions',
            ])
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $trips->map(fn (Trip $t) => $this->presentLiveMapTrip($t))->values()->all(),
        ]);
    }

    /**
     * GET /logistics/distribution/trips/{id}/location-history
     *
     * Chronological GPS samples for ONE Trip (§13/§17/§27), bounded by
     * `limit` (default 1000, hard cap 5000 — a safety rail, not an expected
     * truncation point at this task's sampling cadence). Available for ANY
     * trip this company owns, active or completed: historical samples
     * remain readable after custody/trip completion (§14) — only the WRITE
     * path (DriverRuntimeController::gps()) is gated by current
     * trackability, not this read.
     */
    public function history(Request $request, string $id): JsonResponse
    {
        $companyId = $this->companyId($request);

        $trip = Trip::query()
            ->where('uuid', $id)
            ->where('company_id', $companyId)
            ->with(['driverVehicleAssignment.driver', 'driverVehicleAssignment.vehicle'])
            ->firstOrFail();

        $limit = max(1, min((int) $request->input('limit', 1000), 5000));

        $samples = DriverLocationPing::query()
            ->where('trip_id', $trip->id)
            ->orderBy('recorded_at')
            ->limit($limit)
            ->get();

        $stops = $trip->stops()->orderBy('sequence')->get();
        $exceptions = $trip->exceptions()->with('stop')->get();
        $pairing = $trip->driverVehicleAssignment;

        return response()->json([
            'trip' => [
                'trip_id' => $trip->uuid,
                'trip_number' => $trip->trip_number,
                'status' => $trip->status->value,
                'driver' => $pairing?->driver === null ? null : [
                    'id' => $pairing->driver->id,
                    'full_name' => $pairing->driver->full_name,
                    'mobile' => $pairing->driver->mobile,
                ],
                'vehicle' => $pairing?->vehicle === null ? null : [
                    'id' => $pairing->vehicle->id,
                    'plate_number' => $pairing->vehicle->plate_number,
                    'name' => $pairing->vehicle->name,
                ],
                'trip_started_at' => $trip->trip_started_at?->toIso8601String(),
                'trip_finished_at' => $trip->trip_finished_at?->toIso8601String(),
            ],
            // Raw recorded GPS points in chronological order — a path, never a
            // road-snapped/optimised route (§17): presented as GPS history, and
            // replay (frontend) must not interpolate between them (§18/§19).
            'samples' => $samples->map(static fn (DriverLocationPing $p): array => [
                'lat' => (float) $p->latitude,
                'lng' => (float) $p->longitude,
                'recorded_at' => $p->recorded_at->toIso8601String(),
            ])->values()->all(),
            'samples_truncated' => $samples->count() >= $limit,
            'stops' => $stops->map(static fn (DeliveryStop $s): array => [
                'id' => $s->uuid,
                'sequence' => $s->sequence,
                'status' => $s->status->value,
                // Only a real canonical coordinate — never a substitute position (§10).
                'location' => $s->gps_lat === null || $s->gps_lng === null ? null : [
                    'lat' => (float) $s->gps_lat,
                    'lng' => (float) $s->gps_lng,
                ],
                'completed_at' => $s->completed_at?->toIso8601String(),
            ])->values()->all(),
            // Only exceptions with a real timestamp — the timeline never invents one (§19/§21).
            'exceptions' => $exceptions->map(static fn (DeliveryException $e): array => [
                'id' => $e->id,
                'stop_id' => $e->stop?->uuid,
                'exception_type' => $e->exception_type,
                'reported_at' => $e->created_at?->toIso8601String(),
                'resolved_at' => $e->resolved_at?->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    /** @return array<string, mixed> */
    private function presentLiveMapTrip(Trip $t): array
    {
        $pairing = $t->driverVehicleAssignment;
        $ping = $t->latestLocationPing;

        return [
            'trip_id' => $t->uuid,
            'trip_number' => $t->trip_number,
            'status' => $t->status->value,
            'driver' => $pairing?->driver === null ? null : [
                'id' => $pairing->driver->id,
                'full_name' => $pairing->driver->full_name,
                'mobile' => $pairing->driver->mobile,
            ],
            'vehicle' => $pairing?->vehicle === null ? null : [
                'id' => $pairing->vehicle->id,
                'plate_number' => $pairing->vehicle->plate_number,
                'name' => $pairing->vehicle->name,
            ],
            // null means Unknown — no sample ever recorded for this trip; never a
            // substitute position (§4/§24). Freshness is Fresh/Stale ONLY once a
            // sample exists — see DriverLocationPing::isFresh() for the threshold.
            'location' => $ping === null ? null : [
                'lat' => (float) $ping->latitude,
                'lng' => (float) $ping->longitude,
                'recorded_at' => $ping->recorded_at->toIso8601String(),
                'freshness' => $ping->isFresh() ? 'fresh' : 'stale',
            ],
            'stops_total' => (int) $t->stops_count,
            'stops_completed' => (int) $t->stops_completed_count,
            'has_exception' => (int) $t->exceptions_count > 0,
        ];
    }

    /**
     * The acting company, or a hard failure — same contract as the sibling
     * operator-facing Distribution controllers (TripController,
     * DistributionWindowController): never degrade a missing company scope
     * into "see everything" (§22).
     */
    private function companyId(Request $request): string
    {
        $companyId = $request->user()?->company_id;

        if ($companyId === null || $companyId === '') {
            abort(403, 'No company scope for the acting user.');
        }

        return (string) $companyId;
    }
}
