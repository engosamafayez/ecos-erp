<?php

declare(strict_types=1);

namespace Modules\Operations\Loading\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Operations\Loading\Domain\Enums\VehicleAssignmentStatus;
use Modules\Operations\Loading\Domain\Events\VehicleAssigned;
use Modules\Operations\Loading\Domain\Models\LoadingSession;
use Modules\Operations\Loading\Domain\Models\VehicleAssignment;
use Modules\Operations\Loading\Domain\Services\VehicleAssignmentNumberGenerator;

final class AssignVehicleToSessionAction
{
    public function __construct(
        private readonly VehicleAssignmentNumberGenerator $numberGen,
    ) {}

    /**
     * ECOS CAPACITY CONTRACT — capacity is an ORDER COUNT and nothing else.
     *
     * `$capacityWeightKg`, `$capacityVolumeM3` and `$refrigerated` are now
     * OPTIONAL and default to null/false. They are retained as parameters so the
     * pre-existing standalone Loading callers keep compiling, but they are no
     * longer requirements: weight, volume and refrigeration are not business
     * constraints in this platform, and nothing reads these values to make a
     * decision.
     *
     * Null is passed through as null rather than coerced to 0. A zero is a real
     * measurement meaning "carries nothing"; a null means "not measured". Only
     * the second is true here, and writing the first would quietly become a
     * ceiling the moment anything consumed it.
     *
     * `$tripId` is the canonical execution link. It lets Loading reach its Group
     * through Trip → virtual_slot_id without Loading storing a group id of its
     * own, and without a second Vehicle or Driver source of truth.
     */
    public function execute(
        LoadingSession $session,
        string $vehicleId,
        string $vehicleRegistration,
        string $vehicleType,
        string $actorId,
        ?float $capacityWeightKg = null,
        ?float $capacityVolumeM3 = null,
        bool $refrigerated = false,
        ?int $tripId = null,
        ?string $vehiclePlanSlotId = null,
        ?string $notes = null,
    ): VehicleAssignment {
        return DB::transaction(function () use (
            $session,
            $vehicleId,
            $vehicleRegistration,
            $vehicleType,
            $capacityWeightKg,
            $capacityVolumeM3,
            $refrigerated,
            $tripId,
            $actorId,
            $vehiclePlanSlotId,
            $notes,
        ): VehicleAssignment {
            $assignmentNumber = $this->numberGen->next($session->company_id);

            $assignment = VehicleAssignment::create([
                'company_id' => $session->company_id,
                'loading_session_id' => $session->id,
                'vehicle_plan_slot_id' => $vehiclePlanSlotId,
                // The canonical execution link. Group provenance is REACHED
                // through this, never copied onto the assignment.
                'trip_id' => $tripId,
                'vehicle_id' => $vehicleId,
                'vehicle_registration_snapshot' => $vehicleRegistration,
                'vehicle_type_snapshot' => $vehicleType,
                'capacity_weight_kg_snapshot' => $capacityWeightKg,
                'capacity_volume_m3_snapshot' => $capacityVolumeM3,
                'refrigerated_snapshot' => $refrigerated,
                'assignment_number' => $assignmentNumber,
                'status' => VehicleAssignmentStatus::Pending->value,
                'loading_weight_kg' => 0.0,
                'loading_volume_m3' => 0.0,
                'notes' => $notes,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);

            $session->increment('vehicles_count');

            event(new VehicleAssigned(
                companyId: $session->company_id,
                assignmentId: $assignment->id,
                assignmentNumber: $assignmentNumber,
                sessionId: $session->id,
                vehicleId: $vehicleId,
                vehicleRegistration: $vehicleRegistration,
                actorId: $actorId,
                occurredAt: now()->toIso8601String(),
            ));

            return $assignment;
        });
    }
}
