<?php

declare(strict_types=1);

namespace Modules\Operations\Loading\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Operations\Loading\Domain\Exceptions\LoadingSessionNotFoundException;
use Modules\Operations\Loading\Domain\Models\LoadingSession;
use Modules\Operations\Loading\Domain\Models\ShipmentGroup;
use Modules\Operations\Loading\Presentation\Http\Resources\ShipmentGroupResource;

/**
 * TASK-SHIPPING-DRIVER-CLOSURE-001 §G4 — Shipping Company reachable by the operator.
 *
 * shipping_company_id is modelled on ShipmentGroup (the per-carrier grouping of a
 * session's orders) but had NO route, so the operator workspace could not display
 * the carrier at all. This read-only endpoint exposes the existing ShipmentGroup
 * read model. No new carrier model, no write path — just visibility. Company scope
 * is enforced through findSession(), the same tenant seam every loading controller
 * uses; the session-view permission gates it.
 */
final class ShipmentGroupController extends Controller
{
    use HasApiResponse;

    public function index(Request $request, string $sessionId): JsonResponse
    {
        $session = $this->findSession($sessionId, $request->user()->company_id);
        $this->authorize('view', $session);

        $groups = ShipmentGroup::where('loading_session_id', $session->id)
            ->orderBy('group_number')
            ->get();

        return $this->success(ShipmentGroupResource::collection($groups));
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
