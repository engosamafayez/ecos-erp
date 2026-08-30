<?php

declare(strict_types=1);

namespace Modules\Operations\Loading\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use BackedEnum;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Operations\Loading\Application\Actions\CompleteAllocationAction;
use Modules\Operations\Loading\Application\Actions\RecordProductDeliveryAction;
use Modules\Operations\Loading\Application\Actions\StartAllocationAction;
use Modules\Operations\Loading\Domain\Exceptions\LoadingSessionNotFoundException;
use Modules\Operations\Loading\Domain\Models\AllocationRecord;
use Modules\Operations\Loading\Domain\Models\LoadingSession;
use Modules\Operations\Loading\Domain\Models\VehicleAssignment;
use Modules\Operations\Loading\Domain\Services\AllocationDecisionChainService;
use Modules\Operations\Loading\Presentation\Http\Requests\OverrideAllocationRequest;
use Modules\Operations\Loading\Presentation\Http\Requests\RecordProductDeliveryRequest;
use Modules\Operations\Loading\Presentation\Http\Resources\AllocationRecordResource;
use RuntimeException;

final class AllocationController extends Controller
{
    use HasApiResponse;

    public function index(Request $request, string $sessionId, string $assignmentId): JsonResponse
    {
        $session = $this->findSession($sessionId, $request->user()->company_id);
        // Reading allocations no longer requires the manage authority — a viewer can
        // watch loading without being able to run allocation (G3).
        $this->authorize('viewAllocations', $session);

        $assignment = VehicleAssignment::where('id', $assignmentId)
            ->where('loading_session_id', $session->id)
            ->first();

        if (! $assignment) {
            abort(404, "Vehicle assignment [{$assignmentId}] not found.");
        }

        $records = AllocationRecord::where('vehicle_assignment_id', $assignment->id)
            ->orderBy('priority_rank')
            ->get();

        return $this->success(AllocationRecordResource::collection($records));
    }

    /**
     * Session-wide allocation read (G3). The operator workspace needs every
     * allocation across the whole session — not one vehicle at a time — to render
     * the ordered/allocated/loaded/delivered/remaining matrix. Optional `order_id`
     * narrows to a single order (the driver/customer drill-down). Company scope is
     * enforced by findSession(); the same read permission as the per-assignment list.
     */
    public function sessionIndex(Request $request, string $sessionId): JsonResponse
    {
        $session = $this->findSession($sessionId, $request->user()->company_id);
        $this->authorize('viewAllocations', $session);

        $records = AllocationRecord::where('loading_session_id', $session->id)
            ->when(
                $request->filled('order_id'),
                fn ($q) => $q->where('order_id', $request->string('order_id')),
            )
            ->orderBy('vehicle_assignment_id')
            ->orderBy('priority_rank')
            ->get();

        return $this->success(AllocationRecordResource::collection($records));
    }

    public function startAllocation(
        Request $request,
        string $sessionId,
        StartAllocationAction $action,
    ): JsonResponse {
        $session = $this->findSession($sessionId, $request->user()->company_id);
        $this->authorize('allocate', $session);

        $result = $action->execute($session, (string) $request->user()->id);

        return $this->success([
            'id' => $result->id,
            'status' => $result->status instanceof BackedEnum ? $result->status->value : $result->status,
            'allocation_started_at' => $result->allocation_started_at?->toIso8601String(),
        ]);
    }

    public function completeAllocation(
        Request $request,
        string $sessionId,
        CompleteAllocationAction $action,
    ): JsonResponse {
        $session = $this->findSession($sessionId, $request->user()->company_id);
        $this->authorize('allocate', $session);

        $result = $action->execute($session, (string) $request->user()->id);

        return $this->success([
            'id' => $result->id,
            'status' => $result->status instanceof BackedEnum ? $result->status->value : $result->status,
            'allocation_completed_at' => $result->allocation_completed_at?->toIso8601String(),
        ]);
    }

    public function override(
        OverrideAllocationRequest $request,
        string $sessionId,
        string $assignmentId,
        AllocationDecisionChainService $chainService,
    ): JsonResponse {
        $session = $this->findSession($sessionId, $request->user()->company_id);
        $this->authorize('allocate', $session);

        $validated = $request->validated();

        $record = AllocationRecord::where('id', $validated['allocation_record_id'])
            ->where('vehicle_assignment_id', $assignmentId)
            ->first();

        if (! $record) {
            abort(404, "Allocation record [{$validated['allocation_record_id']}] not found.");
        }

        $actorType = $validated['actor_type'];
        $decision = match ($actorType) {
            'dispatcher' => $chainService->recordDispatcherOverride(
                $record,
                (float) $validated['new_quantity'],
                (string) $request->user()->id,
                $validated['reason'],
            ),
            'driver' => $chainService->recordDriverOverride(
                $record,
                (float) $validated['new_quantity'],
                (string) $request->user()->id,
                $validated['reason'],
            ),
            default => abort(422, "Unknown actor_type: {$actorType}"),
        };

        return $this->success([
            'decision_id' => $decision->id,
            'revision_number' => $decision->revision_number,
            'quantity_before' => $decision->quantity_before,
            'quantity_after' => $decision->quantity_after,
            'actor_type' => $decision->actor_type,
            'reason' => $decision->reason,
            'recorded_at' => $decision->recorded_at?->toIso8601String(),
        ]);
    }

    /**
     * Record the ACTUAL delivered quantity for one allocation line (T-09,
     * ADR-015 §6.4). The controller performs HTTP + tenant resolution only; the
     * quantity write, the remaining/status derivation, the over-delivery refusal
     * and the vehicle-inventory propagation all belong to the domain writer.
     *
     * Tenant chain — identical to index()/show() in this module:
     *   authenticated actor.company_id → LoadingSession (findSession)
     *   → VehicleAssignment scoped to that session → AllocationRecord scoped to
     *   that assignment. Company A therefore cannot address Company B's
     *   allocation: B's assignment is not in A's session, so it 404s before the
     *   record is ever reached.
     */
    public function recordDelivery(
        RecordProductDeliveryRequest $request,
        string $sessionId,
        string $assignmentId,
        RecordProductDeliveryAction $action,
    ): JsonResponse {
        $session = $this->findSession($sessionId, $request->user()->company_id);
        $this->authorize('allocate', $session);

        $assignment = VehicleAssignment::where('id', $assignmentId)
            ->where('loading_session_id', $session->id)
            ->first();

        if (! $assignment) {
            abort(404, "Vehicle assignment [{$assignmentId}] not found.");
        }

        $validated = $request->validated();

        $record = AllocationRecord::where('id', $validated['allocation_record_id'])
            ->where('vehicle_assignment_id', $assignment->id)
            ->first();

        if (! $record) {
            abort(404, "Allocation record [{$validated['allocation_record_id']}] not found.");
        }

        try {
            // The Action is the sole domain writer. It uses ABSOLUTE delivery
            // semantics, so replaying the same quantity is a no-op (no double add).
            $updated = $action->execute(
                $record,
                (float) $validated['quantity_delivered'],
                (string) $request->user()->id,
                $validated['actor_type'] ?? 'driver',
            );
        } catch (RuntimeException $e) {
            // Faithfully surface the domain refusal (over-delivery has no approved
            // contract and fails closed; a terminal allocation can no longer
            // record) as a client error rather than a 500. No new policy is
            // introduced here — only the existing behaviour is exposed.
            abort(422, $e->getMessage());
        }

        return $this->success(new AllocationRecordResource($updated));
    }

    private function findSession(string $sessionId, string $companyId): LoadingSession
    {
        $session = LoadingSession::where('id', $sessionId)
            ->where('company_id', $companyId)
            ->first();

        if (! $session) {
            throw LoadingSessionNotFoundException::forId($sessionId);
        }

        return $session;
    }
}
