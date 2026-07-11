<?php

declare(strict_types=1);

namespace Modules\Operations\Fulfillment\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Operations\Fulfillment\Application\FulfillmentEngine;
use Modules\Operations\Fulfillment\Application\Workflows\CancelOrderWorkflow;
use Modules\Operations\Fulfillment\Application\Workflows\CompleteDeliveryWorkflow;
use Modules\Operations\Fulfillment\Application\Workflows\ConfirmOrderWorkflow;
use Modules\Operations\Fulfillment\Application\Workflows\MoveToPreparationWorkflow;
use Modules\Operations\Fulfillment\Application\Workflows\ReceiveReturnWorkflow;
use Modules\Operations\Fulfillment\Application\Workflows\ReturnOrderWorkflow;
use Modules\Operations\Fulfillment\Domain\Exceptions\WorkflowPreconditionException;
use Modules\Operations\Fulfillment\Domain\Models\CustomerReturn;

final class FulfillmentController extends Controller
{
    public function __construct(
        private readonly FulfillmentEngine          $engine,
        private readonly ConfirmOrderWorkflow        $confirmWorkflow,
        private readonly CancelOrderWorkflow         $cancelWorkflow,
        private readonly MoveToPreparationWorkflow   $prepWorkflow,
        private readonly CompleteDeliveryWorkflow    $deliveryWorkflow,
        private readonly ReturnOrderWorkflow         $returnWorkflow,
        private readonly ReceiveReturnWorkflow       $receiveReturnWorkflow,
    ) {}

    /** POST /api/fulfillment/orders/{order}/confirm */
    public function confirm(Order $order): JsonResponse
    {
        $actorId = Auth::id() !== null ? (string) Auth::id() : null;
        $result = $this->engine->run($this->confirmWorkflow, $order, [], $actorId);

        return response()->json([
            'message'  => $result->message,
            'order_id' => $result->order->id,
            'status'   => $result->order->status->value,
            'meta'     => $result->meta,
        ]);
    }

    /** POST /api/fulfillment/orders/{order}/cancel */
    public function cancel(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $actorId = Auth::id() !== null ? (string) Auth::id() : null;
        $result = $this->engine->run(
            $this->cancelWorkflow,
            $order,
            ['reason' => $data['reason'] ?? null],
            $actorId,
        );

        return response()->json([
            'message'  => $result->message,
            'order_id' => $result->order->id,
            'status'   => $result->order->status->value,
        ]);
    }

    /** POST /api/fulfillment/orders/{order}/move-to-preparation */
    public function moveToPreparation(Order $order): JsonResponse
    {
        $actorId = Auth::id() !== null ? (string) Auth::id() : null;
        $result = $this->engine->run($this->prepWorkflow, $order, [], $actorId);

        return response()->json([
            'message'  => $result->message,
            'order_id' => $result->order->id,
            'status'   => $result->order->status->value,
        ]);
    }

    /** POST /api/fulfillment/orders/{order}/complete-delivery */
    public function completeDelivery(Order $order): JsonResponse
    {
        $actorId = Auth::id() !== null ? (string) Auth::id() : null;
        $result = $this->engine->run($this->deliveryWorkflow, $order, [], $actorId);

        return response()->json([
            'message'  => $result->message,
            'order_id' => $result->order->id,
            'status'   => $result->order->status->value,
            'meta'     => $result->meta,
        ]);
    }

    /** POST /api/fulfillment/orders/{order}/return */
    public function returnOrder(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'return_reason' => ['required', 'string', 'max:200'],
            'driver_notes'  => ['nullable', 'string', 'max:1000'],
            'lines'         => ['required', 'array', 'min:1'],
            'lines.*.order_line_id'     => ['nullable', 'string'],
            'lines.*.product_id'        => ['required', 'string'],
            'lines.*.quantity_returned' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.condition'         => ['nullable', 'string', 'in:sellable,damaged,destroyed'],
            'lines.*.inspection_notes'  => ['nullable', 'string', 'max:500'],
        ]);

        $actorId = Auth::id() !== null ? (string) Auth::id() : null;
        $result = $this->engine->run(
            $this->returnWorkflow,
            $order,
            $data,
            $actorId,
        );

        return response()->json([
            'message'            => $result->message,
            'order_id'           => $result->order->id,
            'status'             => $result->order->status->value,
            'customer_return_id' => $result->meta['customer_return_id'] ?? null,
            'return_number'      => $result->meta['return_number'] ?? null,
        ], 201);
    }

    /** POST /api/fulfillment/returns/{customerReturn}/receive */
    public function receiveReturn(Request $request, CustomerReturn $customerReturn): JsonResponse
    {
        $data = $request->validate([
            'warehouse_notes'         => ['nullable', 'string', 'max:1000'],
            'line_conditions'         => ['nullable', 'array'],
            'line_conditions.*'       => ['string', 'in:sellable,damaged,destroyed'],
        ]);

        $updated = $this->receiveReturnWorkflow->execute(
            $customerReturn,
            (string) Auth::id(),
            $data['warehouse_notes'] ?? null,
            $data['line_conditions'] ?? [],
        );

        return response()->json([
            'message'               => "CustomerReturn #{$updated->return_number} accepted.",
            'customer_return_id'    => $updated->id,
            'status'                => $updated->status,
            'inventory_restored_at' => $updated->inventory_restored_at?->toIso8601String(),
        ]);
    }
}
