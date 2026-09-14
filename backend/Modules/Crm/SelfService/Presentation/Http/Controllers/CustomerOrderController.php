<?php

declare(strict_types=1);

namespace Modules\Crm\SelfService\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Crm\SelfService\Domain\Models\CustomerTrackingToken;
use Modules\Crm\SelfService\Domain\Services\CustomerOrderReadModel;

/**
 * §7/§8 — the customer-safe order view. Deliberately does NOT reuse
 * Modules\Commerce\Orders\Presentation\Http\Resources\OrderResource, which exposes
 * internal_notes, hold_reason_code, warehouse internals, reservation shortage internals,
 * unredacted driver name+mobile, and workflow-transition metadata — none of that is
 * customer-safe. CustomerOrderReadModel is built directly from Order and its canonical
 * relations instead.
 */
final class CustomerOrderController extends Controller
{
    public function __construct(private readonly CustomerOrderReadModel $readModel) {}

    public function show(Request $request): JsonResponse
    {
        /** @var CustomerTrackingToken $token */
        $token = $request->attributes->get('customer_tracking_token');

        $data = $this->readModel->build($token);

        if ($data === null) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        return response()->json(['data' => $data]);
    }
}
