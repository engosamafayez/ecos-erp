<?php

declare(strict_types=1);

namespace Modules\Operations\Loading\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Operations\Loading\Domain\Models\LoadingSession;
use Modules\Operations\Loading\Domain\Models\VehicleAssignment;
use Modules\Operations\Loading\Domain\Models\VehicleShiftReconciliation;
use Modules\Operations\Loading\Domain\Models\VehicleShiftReconciliationLine;
use Modules\Operations\Loading\Application\Actions\ReceiveVehicleReturnAction;
use Modules\Operations\Loading\Domain\Services\VehicleShiftReconciliationService;
use Modules\Operations\Loading\Presentation\Http\Requests\ReceiveVehicleReturnRequest;
use Modules\Operations\Loading\Presentation\Http\Requests\ReconciliationLineRequest;
use Modules\Operations\Loading\Presentation\Http\Resources\VehicleShiftReconciliationResource;
use RuntimeException;

/**
 * HTTP surface for end-of-shift vehicle reconciliation (ADR-015 §6.4).
 *
 * T-04/T-05 convergence: the reconciliation domain (VehicleShiftReconciliationService,
 * the two models, the ReconciliationLineRequest) was already complete and tested but
 * had no controller or route — an operator could not reach it. This controller ONLY
 * exposes that existing capability over HTTP. It computes no variance, invents no
 * return semantics, and creates no new authority: every quantity flows through the
 * service, which reads loaded/delivered from the vehicle inventory item and the
 * operator-counted returned quantity from the line.
 *
 * Tenant scoping mirrors AllocationController exactly: company_id comes from the
 * authenticated actor (never the route), findSession() scopes the session, and the
 * assignment/line are re-scoped to that session so a cross-tenant id 404s before any
 * record is read.
 */
final class VehicleShiftReconciliationController extends Controller
{
    use HasApiResponse;

    /** Current reconciliation read model for a shift (null envelope if never opened). */
    public function show(Request $request, string $sessionId, string $assignmentId): JsonResponse
    {
        $session = $this->findSession($sessionId, $request->user()->company_id);
        $this->authorize('view', $session);

        $assignment = $this->findAssignment($assignmentId, $session);

        $reconciliation = VehicleShiftReconciliation::where('vehicle_assignment_id', $assignment->id)
            ->with('lines')
            ->first();

        if ($reconciliation === null) {
            return $this->success(null);
        }

        return $this->success(new VehicleShiftReconciliationResource($reconciliation));
    }

    /**
     * Open (or refresh) the shift reconciliation and recompute every line from the
     * current loaded/delivered facts. Idempotent — re-opening returns the same header.
     */
    public function open(
        Request $request,
        string $sessionId,
        string $assignmentId,
        VehicleShiftReconciliationService $service,
    ): JsonResponse {
        $session = $this->findSession($sessionId, $request->user()->company_id);
        $this->authorize('operate', $session);

        $assignment = $this->findAssignment($assignmentId, $session);

        try {
            $reconciliation = $service->open($assignment, (string) $request->user()->id);
        } catch (RuntimeException $e) {
            // A shift with no driver / no operational date, or an already-approved
            // reconciliation, is refused by the domain. Surface as a client error
            // rather than a 500. No new policy is introduced.
            abort(422, $e->getMessage());
        }

        return $this->success(new VehicleShiftReconciliationResource($reconciliation->load('lines')));
    }

    /**
     * Record the quantity physically counted back into the warehouse for one line.
     * Absolute (re-submitting the same count is a no-op); recomputes the variance.
     */
    public function recordReturn(
        ReconciliationLineRequest $request,
        string $sessionId,
        string $assignmentId,
        string $lineId,
        VehicleShiftReconciliationService $service,
    ): JsonResponse {
        $session = $this->findSession($sessionId, $request->user()->company_id);
        $this->authorize('operate', $session);

        $assignment = $this->findAssignment($assignmentId, $session);

        // The line must belong to this assignment's reconciliation — resolve through
        // the header so a line id from another shift (or tenant) 404s.
        $line = VehicleShiftReconciliationLine::where('id', $lineId)
            ->whereHas('reconciliation', fn ($q) => $q->where('vehicle_assignment_id', $assignment->id))
            ->first();

        if (! $line) {
            abort(404, "Reconciliation line [{$lineId}] not found.");
        }

        $validated = $request->validated();

        try {
            $service->recordReturnedActual(
                $line,
                (float) $validated['quantity_returned_actual'],
                $validated['resolution_notes'] ?? null,
                (string) $request->user()->id,
            );
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        $reconciliation = VehicleShiftReconciliation::where('id', $line->reconciliation_id)
            ->with('lines')
            ->firstOrFail();

        return $this->success(new VehicleShiftReconciliationResource($reconciliation));
    }

    /**
     * Warehouse return receipt for one line (TASK-...-RETURNS-RECONCILIATION-001, §11).
     * The operator splits the physically-returned quantity into accepted (good, restocked
     * to warehouse via the canonical AdjustmentIn) vs damaged (kept out of good stock).
     * Idempotent + atomic; shortage stays visible as the reconciliation variance.
     */
    public function receiveReturn(
        ReceiveVehicleReturnRequest $request,
        string $sessionId,
        string $assignmentId,
        string $lineId,
        ReceiveVehicleReturnAction $action,
    ): JsonResponse {
        $session = $this->findSession($sessionId, $request->user()->company_id);
        $this->authorize('operate', $session);

        $assignment = $this->findAssignment($assignmentId, $session);

        $line = VehicleShiftReconciliationLine::where('id', $lineId)
            ->whereHas('reconciliation', fn ($q) => $q->where('vehicle_assignment_id', $assignment->id))
            ->first();

        if (! $line) {
            abort(404, "Reconciliation line [{$lineId}] not found.");
        }

        $validated = $request->validated();

        try {
            $action->execute(
                $line,
                (float) $validated['quantity_accepted'],
                (float) $validated['quantity_damaged'],
                $validated['damage_reason'] ?? null,
                (string) $request->user()->id,
            );
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        $reconciliation = VehicleShiftReconciliation::where('id', $line->reconciliation_id)
            ->with('lines')
            ->firstOrFail();

        return $this->success(new VehicleShiftReconciliationResource($reconciliation));
    }

    private function findAssignment(string $assignmentId, LoadingSession $session): VehicleAssignment
    {
        $assignment = VehicleAssignment::where('id', $assignmentId)
            ->where('loading_session_id', $session->id)
            ->first();

        if (! $assignment) {
            abort(404, "Vehicle assignment [{$assignmentId}] not found.");
        }

        return $assignment;
    }

    private function findSession(string $sessionId, string $companyId): LoadingSession
    {
        // Same company-scoped containment the whole Loading module uses (the tenant
        // authority is unchanged); a clean 404 on miss rather than the module's bare
        // RuntimeException → 500. company_id is the authenticated actor's, never the route.
        $session = LoadingSession::where('id', $sessionId)
            ->where('company_id', $companyId)
            ->first();

        if (! $session) {
            abort(404, "Loading session [{$sessionId}] not found.");
        }

        return $session;
    }
}
