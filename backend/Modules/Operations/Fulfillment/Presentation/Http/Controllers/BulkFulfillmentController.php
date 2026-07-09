<?php

declare(strict_types=1);

namespace Modules\Operations\Fulfillment\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Operations\Fulfillment\Application\Services\BulkWorkflowEngine;
use Modules\Operations\Fulfillment\Application\Workflows\CancelOrderWorkflow;
use Modules\Operations\Fulfillment\Application\Workflows\CompleteDeliveryWorkflow;
use Modules\Operations\Fulfillment\Application\Workflows\ConfirmOrderWorkflow;
use Modules\Operations\Fulfillment\Application\Workflows\MoveToPreparationWorkflow;

/**
 * Bulk fulfillment endpoints — run a workflow across multiple orders in one request.
 *
 * Each order is processed independently: a failure on one does not affect others.
 * Responses always return 200 with a per-order breakdown.
 */
final class BulkFulfillmentController extends Controller
{
    public function __construct(
        private readonly BulkWorkflowEngine          $bulk,
        private readonly ConfirmOrderWorkflow         $confirmWorkflow,
        private readonly CancelOrderWorkflow          $cancelWorkflow,
        private readonly MoveToPreparationWorkflow    $prepWorkflow,
        private readonly CompleteDeliveryWorkflow     $deliveryWorkflow,
    ) {}

    /** POST /api/fulfillment/bulk/confirm */
    public function confirmBulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_ids'   => ['required', 'array', 'min:1', 'max:200'],
            'order_ids.*' => ['required', 'string'],
        ]);

        $result = $this->bulk->run(
            $this->confirmWorkflow,
            $data['order_ids'],
            [],
            Auth::id(),
        );

        return response()->json($result->toArray());
    }

    /** POST /api/fulfillment/bulk/cancel */
    public function cancelBulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_ids'   => ['required', 'array', 'min:1', 'max:200'],
            'order_ids.*' => ['required', 'string'],
            'reason'      => ['nullable', 'string', 'max:500'],
        ]);

        $result = $this->bulk->run(
            $this->cancelWorkflow,
            $data['order_ids'],
            ['reason' => $data['reason'] ?? null],
            Auth::id(),
        );

        return response()->json($result->toArray());
    }

    /** POST /api/fulfillment/bulk/move-to-preparation */
    public function moveToPreparationBulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_ids'   => ['required', 'array', 'min:1', 'max:200'],
            'order_ids.*' => ['required', 'string'],
        ]);

        $result = $this->bulk->run(
            $this->prepWorkflow,
            $data['order_ids'],
            [],
            Auth::id(),
        );

        return response()->json($result->toArray());
    }

    /** POST /api/fulfillment/bulk/complete-delivery */
    public function completeDeliveryBulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_ids'   => ['required', 'array', 'min:1', 'max:200'],
            'order_ids.*' => ['required', 'string'],
        ]);

        $result = $this->bulk->run(
            $this->deliveryWorkflow,
            $data['order_ids'],
            [],
            Auth::id(),
        );

        return response()->json($result->toArray());
    }
}
