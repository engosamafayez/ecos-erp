<?php

declare(strict_types=1);

namespace Modules\Operations\Fulfillment\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Operations\Fulfillment\Application\FulfillmentEngine;
use Modules\Operations\Fulfillment\Application\Workflows\ApprovePartialReservationWorkflow;
use Modules\Operations\Fulfillment\Application\Workflows\CancelOrderWorkflow;
use Modules\Operations\Fulfillment\Application\Workflows\CompleteDeliveryWorkflow;
use Modules\Operations\Fulfillment\Application\Workflows\CompleteOrderWorkflow;
use Modules\Operations\Fulfillment\Application\Workflows\ConfirmOrderWorkflow;
use Modules\Operations\Fulfillment\Application\Workflows\DispatchOrderWorkflow;
use Modules\Operations\Fulfillment\Application\Workflows\MarkAwaitingStockWorkflow;
use Modules\Operations\Fulfillment\Application\Workflows\MarkRescheduledWorkflow;
use Modules\Operations\Fulfillment\Application\Workflows\MoveToPreparationWorkflow;
use Modules\Operations\Fulfillment\Application\Workflows\MoveToReviewWorkflow;
use Modules\Operations\Fulfillment\Application\Workflows\ProcessOrderWorkflow;
use Modules\Operations\Fulfillment\Application\Workflows\ReceiveReturnWorkflow;
use Modules\Operations\Fulfillment\Application\Workflows\RescheduleOrderWorkflow;
use Modules\Operations\Fulfillment\Application\Workflows\ResumeOrderWorkflow;
use Modules\Operations\Fulfillment\Application\Workflows\ReturnOrderWorkflow;
use Modules\Operations\Fulfillment\Application\Workflows\ReturnToPaymentWorkflow;
use Modules\Operations\Fulfillment\Application\Workflows\ReturnToPendingWorkflow;
use Modules\Operations\Fulfillment\Application\Workflows\ReturnToProcessingWorkflow;
use Modules\Operations\Fulfillment\Application\Workflows\RevertToConfirmedWorkflow;
use Modules\Operations\Fulfillment\Application\Workflows\SetEarlyStatusWorkflow;
use Modules\Operations\Fulfillment\Domain\Contracts\FulfillmentWorkflowInterface;
use Modules\Operations\Fulfillment\Domain\Models\CustomerReturn;

final class FulfillmentController extends Controller
{
    public function __construct(
        private readonly FulfillmentEngine $engine,
        private readonly ConfirmOrderWorkflow $confirmWorkflow,
        private readonly CancelOrderWorkflow $cancelWorkflow,
        private readonly MoveToPreparationWorkflow $prepWorkflow,
        private readonly DispatchOrderWorkflow $dispatchWorkflow,
        private readonly CompleteDeliveryWorkflow $deliveryWorkflow,
        private readonly CompleteOrderWorkflow $completeWorkflow,
        private readonly MarkAwaitingStockWorkflow $awaitingStockWorkflow,
        private readonly ReturnOrderWorkflow $returnWorkflow,
        private readonly ReceiveReturnWorkflow $receiveReturnWorkflow,
        private readonly RescheduleOrderWorkflow $rescheduleWorkflow,
        private readonly ResumeOrderWorkflow $resumeWorkflow,
        private readonly MoveToReviewWorkflow $reviewWorkflow,
        private readonly ReturnToPendingWorkflow $returnToPendingWorkflow,
        private readonly RevertToConfirmedWorkflow $revertToConfirmedWorkflow,
        private readonly ReturnToProcessingWorkflow $returnToProcessingWorkflow,
        // V2 workflows
        private readonly ProcessOrderWorkflow $processWorkflow,
        private readonly ReturnToPaymentWorkflow $returnToPaymentWorkflow,
        private readonly SetEarlyStatusWorkflow $setEarlyStatusWorkflow,
        private readonly MarkRescheduledWorkflow $markRescheduledWorkflow,
        // P1 workflows
        private readonly ApprovePartialReservationWorkflow $approvePartialReservationWorkflow,
    ) {}

    /** POST /api/fulfillment/orders/{order}/confirm
     *  pending | awaiting_payment | processing | awaiting_stock → confirmed
     */
    public function confirm(Order $order): JsonResponse
    {
        $actorId = Auth::id() !== null ? (string) Auth::id() : null;
        $result = $this->engine->run($this->confirmWorkflow, $order, [], $actorId);

        return response()->json([
            'message' => $result->message,
            'order_id' => $result->order->id,
            'status' => $result->order->status->value,
            'meta' => $result->meta,
        ]);
    }

    /** POST /api/fulfillment/orders/{order}/cancel
     *  Any pre-delivery state → cancelled
     */
    public function cancel(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $actorId = Auth::id() !== null ? (string) Auth::id() : null;

        $result = $this->engine->run(
            $this->cancelWorkflow,
            $order,
            ['reason' => $data['reason'] ?? null],
            $actorId,
        );

        return response()->json([
            'message' => $result->message,
            'order_id' => $result->order->id,
            'status' => $result->order->status->value,
        ]);
    }

    /** POST /api/fulfillment/orders/{order}/approve-partial-reservation
     *  P1-002 — Manager approval gate: allows PartialReserved → Preparing.
     *  Body: { notes?: string }
     */
    public function approvePartialReservation(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:1000']]);
        $actorId = Auth::id() !== null ? (string) Auth::id() : null;

        $result = $this->engine->run(
            $this->approvePartialReservationWorkflow,
            $order,
            ['notes' => $data['notes'] ?? null],
            $actorId,
        );

        return response()->json([
            'message' => $result->message,
            'order_id' => $result->order->id,
            'approved_at' => $result->meta['approved_at'] ?? null,
            'approved_by' => $result->meta['approved_by'] ?? null,
        ]);
    }

    /** POST /api/fulfillment/orders/{order}/move-to-preparation
     *  confirmed | processing → preparing
     */
    public function moveToPreparation(Order $order): JsonResponse
    {
        $actorId = Auth::id() !== null ? (string) Auth::id() : null;
        $result = $this->engine->run($this->prepWorkflow, $order, [], $actorId);

        return response()->json([
            'message' => $result->message,
            'order_id' => $result->order->id,
            'status' => $result->order->status->value,
        ]);
    }

    /** POST /api/fulfillment/orders/{order}/complete-delivery
     *  out_for_delivery → delivered  (triggers revenue recognition)
     */
    public function completeDelivery(Order $order): JsonResponse
    {
        $actorId = Auth::id() !== null ? (string) Auth::id() : null;
        $result = $this->engine->run($this->deliveryWorkflow, $order, [], $actorId);

        return response()->json([
            'message' => $result->message,
            'order_id' => $result->order->id,
            'status' => $result->order->status->value,
            'meta' => $result->meta,
        ]);
    }

    /** POST /api/fulfillment/orders/{order}/complete
     *  delivered → completed  (financial completion)
     */
    public function complete(Order $order): JsonResponse
    {
        $actorId = Auth::id() !== null ? (string) Auth::id() : null;
        $result = $this->engine->run($this->completeWorkflow, $order, [], $actorId);

        return response()->json([
            'message' => $result->message,
            'order_id' => $result->order->id,
            'status' => $result->order->status->value,
            'meta' => $result->meta,
        ]);
    }

    /** POST /api/fulfillment/orders/{order}/awaiting-stock
     *  processing | confirmed → awaiting_stock
     */
    public function markAwaitingStock(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $actorId = Auth::id() !== null ? (string) Auth::id() : null;

        $result = $this->engine->run(
            $this->awaitingStockWorkflow,
            $order,
            ['reason' => $data['reason'] ?? null],
            $actorId,
        );

        return response()->json([
            'message' => $result->message,
            'order_id' => $result->order->id,
            'status' => $result->order->status->value,
        ]);
    }

    /** POST /api/fulfillment/orders/{order}/return
     *  out_for_delivery | delivered → returned
     */
    public function returnOrder(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'return_reason' => ['required', 'string', 'max:200'],
            'driver_notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.order_line_id' => ['nullable', 'string'],
            'lines.*.product_id' => ['required', 'string'],
            'lines.*.quantity_returned' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.condition' => ['nullable', 'string', 'in:sellable,damaged,destroyed'],
            'lines.*.inspection_notes' => ['nullable', 'string', 'max:500'],
        ]);

        $actorId = Auth::id() !== null ? (string) Auth::id() : null;
        $result = $this->engine->run($this->returnWorkflow, $order, $data, $actorId);

        return response()->json([
            'message' => $result->message,
            'order_id' => $result->order->id,
            'status' => $result->order->status->value,
            'customer_return_id' => $result->meta['customer_return_id'] ?? null,
            'return_number' => $result->meta['return_number'] ?? null,
        ], 201);
    }

    /** POST /api/fulfillment/orders/{order}/reschedule
     *  Any pre-terminal non-rescheduled state → rescheduled
     */
    public function reschedule(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'next_delivery_date' => ['required', 'date', 'after:today'],
            'reschedule_reason' => ['nullable', 'string', 'max:500'],
            'resume_from_status' => ['nullable', 'string', 'max:50'],
        ]);

        $actorId = Auth::id() !== null ? (string) Auth::id() : null;

        $result = $this->engine->run(
            $this->rescheduleWorkflow,
            $order,
            $data,
            $actorId,
        );

        return response()->json([
            'message' => $result->message,
            'order_id' => $result->order->id,
            'status' => $result->order->status->value,
            'next_delivery_date' => $result->meta['next_delivery_date'] ?? null,
            'resume_from_status' => $result->meta['resume_from_status'] ?? null,
        ]);
    }

    /** POST /api/fulfillment/orders/{order}/resume
     *  rescheduled | review → processing (or stored resume_from_status)
     */
    public function resume(Order $order): JsonResponse
    {
        $actorId = Auth::id() !== null ? (string) Auth::id() : null;
        $result = $this->engine->run($this->resumeWorkflow, $order, [], $actorId);

        return response()->json([
            'message' => $result->message,
            'order_id' => $result->order->id,
            'status' => $result->order->status->value,
        ]);
    }

    /** POST /api/fulfillment/orders/{order}/dispatch
     *  preparing → out_for_delivery (direct dispatch, no loading OS)
     */
    public function dispatch(Order $order): JsonResponse
    {
        $actorId = Auth::id() !== null ? (string) Auth::id() : null;
        $result = $this->engine->run($this->dispatchWorkflow, $order, [], $actorId);

        return response()->json([
            'message' => $result->message,
            'order_id' => $result->order->id,
            'status' => $result->order->status->value,
        ]);
    }

    /** POST /api/fulfillment/orders/{order}/review
     *  Any pre-terminal non-review state → review
     */
    public function moveToReview(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $actorId = Auth::id() !== null ? (string) Auth::id() : null;

        $result = $this->engine->run(
            $this->reviewWorkflow,
            $order,
            ['reason' => $data['reason'] ?? null],
            $actorId,
        );

        return response()->json([
            'message' => $result->message,
            'order_id' => $result->order->id,
            'status' => $result->order->status->value,
        ]);
    }

    /** POST /api/fulfillment/orders/{order}/return-to-pending
     *  confirmed → pending  (releases inventory, unlocks structural edits)
     */
    public function returnToPending(Order $order): JsonResponse
    {
        $actorId = Auth::id() !== null ? (string) Auth::id() : null;
        $result = $this->engine->run($this->returnToPendingWorkflow, $order, [], $actorId);

        return response()->json([
            'message' => $result->message,
            'order_id' => $result->order->id,
            'status' => $result->order->status->value,
        ]);
    }

    /** POST /api/fulfillment/orders/{order}/revert-to-confirmed
     *  processing | awaiting_stock | review → confirmed  (no inventory change)
     */
    public function revertToConfirmed(Order $order): JsonResponse
    {
        $actorId = Auth::id() !== null ? (string) Auth::id() : null;
        $result = $this->engine->run($this->revertToConfirmedWorkflow, $order, [], $actorId);

        return response()->json([
            'message' => $result->message,
            'order_id' => $result->order->id,
            'status' => $result->order->status->value,
        ]);
    }

    /** POST /api/fulfillment/orders/{order}/return-to-processing
     *  preparing → processing
     */
    public function returnToProcessing(Order $order): JsonResponse
    {
        $actorId = Auth::id() !== null ? (string) Auth::id() : null;
        $result = $this->engine->run($this->returnToProcessingWorkflow, $order, [], $actorId);

        return response()->json([
            'message' => $result->message,
            'order_id' => $result->order->id,
            'status' => $result->order->status->value,
        ]);
    }

    /**
     * POST /api/fulfillment/orders/{order}/transition
     *
     * Generic transition endpoint: frontend sends target_status (a business state),
     * this method resolves the correct workflow internally.
     * The frontend MUST NOT hardcode workflow names or action keys.
     */
    public function transition(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'target_status' => ['required', 'string', 'max:50'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $current = $order->status->value;
        $target = $data['target_status'];
        $reason = $data['reason'] ?? null;
        $actorId = Auth::id() !== null ? (string) Auth::id() : null;

        $workflow = $this->resolveTransitionWorkflow($current, $target);

        if ($workflow === null) {
            return response()->json([
                'message' => "Transition from [{$current}] to [{$target}] is not allowed.",
            ], 422);
        }

        $result = $this->engine->run($workflow, $order, ['target_status' => $target, 'reason' => $reason], $actorId);

        return response()->json([
            'message' => $result->message,
            'order_id' => $result->order->id,
            'status' => $result->order->status->value,
            'meta' => $result->meta,
        ]);
    }

    /**
     * V3 transition routing table — the ONLY place that maps (current, target) → workflow.
     *
     * TASK-PHASE3-RC10-IMPLEMENT-CERTIFY-001 (Steps 4–7). This table previously
     * spoke V2 vocabulary (`pending`, `confirmed`, `processing`, `preparing`,
     * `review`, `rescheduled`, `completed`) — none of which are `OrderStatus`
     * cases — so every V3 order was refused with a 422 and the generic endpoint
     * was effectively dead. Every branch now reads its state from the enum, so a
     * future rename is a compile-time concern rather than a silent 422.
     *
     * This resolves ROUTING ONLY. Every business precondition continues to live
     * in `$workflow->guard()`, executed by FulfillmentEngine outside the
     * transaction — no guard is duplicated or bypassed here.
     *
     * Architecture rules (carried forward, restated in V3):
     *   - Cancelled is not terminal; orders may be reopened from cancelled.
     *   - InProgress is the single reserved state (PD-2 collapses V2's
     *     `confirmed` + `processing`).
     *   - Execution chain: InProgress → ReadyForDispatch → OutForDelivery → Delivered.
     *   - Delivered is terminal (PD-2). There is no Completed edge; financial
     *     completion remains the dedicated /complete route.
     *   - Returning to New or AwaitingPayment releases inventory.
     *   - Moving between other early states keeps any existing reservation.
     */
    private function resolveTransitionWorkflow(string $current, string $target): ?FulfillmentWorkflowInterface
    {
        // Block self-transitions
        if ($current === $target) {
            return null;
        }

        // ── Helper sets ───────────────────────────────────────────────────────────
        // Early states: no inventory held (or inventory was released).
        // 'scheduled' is a pre-activation early state; guard in ProcessOrderWorkflow
        // enforces the delivery-date constraint before allowing activation.
        $earlyStates = [
            OrderStatus::Scheduled->value,
            OrderStatus::NewOrder->value,
            OrderStatus::AwaitingPayment->value,
            OrderStatus::AwaitingStock->value,
            OrderStatus::OnHold->value,
            OrderStatus::Cancelled->value,
        ];
        // Reserved states: inventory is held and products are locked.
        // V2's `processing` and `confirmed` both map here (PD-2).
        $reservedStates = [OrderStatus::InProgress->value];

        // ── 1. Execution chain ────────────────────────────────────────────────────
        if ($current === OrderStatus::InProgress->value && $target === OrderStatus::ReadyForDispatch->value) {
            return $this->prepWorkflow;
        }
        if ($current === OrderStatus::ReadyForDispatch->value && $target === OrderStatus::OutForDelivery->value) {
            return $this->dispatchWorkflow;
        }
        if ($current === OrderStatus::OutForDelivery->value && $target === OrderStatus::Delivered->value) {
            return $this->deliveryWorkflow;
        }
        // No `delivered → completed` edge: V3 has no Completed state (PD-2).
        // Financial completion stays on the dedicated /complete route.
        if (in_array($current, [OrderStatus::OutForDelivery->value, OrderStatus::Delivered->value], true)
            && $target === OrderStatus::Returned->value
        ) {
            return $this->returnWorkflow;
        }

        // ── 2. Block locked states from using this endpoint ───────────────────────
        $locked = [
            OrderStatus::ReadyForDispatch->value,
            OrderStatus::OutForDelivery->value,
            OrderStatus::Delivered->value,
            OrderStatus::Returned->value,
        ];
        if (in_array($current, $locked, true)) {
            return null;
        }

        // ── 3. Cancel → always CancelOrderWorkflow (handles inventory release) ────
        if ($target === OrderStatus::Cancelled->value) {
            return $this->cancelWorkflow;
        }

        $anySource = array_merge($earlyStates, $reservedStates);

        // ── 4. TO in_progress ─────────────────────────────────────────────────────
        // V2's `confirmed` and `processing` both collapse here (PD-2). Idempotent
        // reservation: ProcessOrderWorkflow reserves if not already held, and its
        // guard enforces the scheduled-delivery-date constraint.
        if (in_array($current, $earlyStates, true) && $target === OrderStatus::InProgress->value) {
            return $this->processWorkflow;
        }

        // ── 5. TO new (V2 `pending`) ──────────────────────────────────────────────
        // reserved → new: release inventory (products become editable)
        if (in_array($current, $reservedStates, true) && $target === OrderStatus::NewOrder->value) {
            return $this->returnToPendingWorkflow;
        }
        // early → new: simple status change, no inventory
        if (in_array($current, $earlyStates, true) && $target === OrderStatus::NewOrder->value) {
            return $this->setEarlyStatusWorkflow;
        }

        // ── 6. TO awaiting_payment ────────────────────────────────────────────────
        // reserved → payment: release inventory (products become editable)
        if (in_array($current, $reservedStates, true) && $target === OrderStatus::AwaitingPayment->value) {
            return $this->returnToPaymentWorkflow;
        }
        // early → payment: simple status change, no inventory
        if (in_array($current, $earlyStates, true) && $target === OrderStatus::AwaitingPayment->value) {
            return $this->setEarlyStatusWorkflow;
        }

        // ── 7. TO awaiting_stock ──────────────────────────────────────────────────
        if (in_array($current, $anySource, true) && $target === OrderStatus::AwaitingStock->value) {
            return $this->awaitingStockWorkflow;
        }

        // ── 8. TO on_hold (V2 `review`) ───────────────────────────────────────────
        // MoveToReviewWorkflow sets OnHold — functionally correct, stale name (PD-2).
        if (in_array($current, $anySource, true) && $target === OrderStatus::OnHold->value) {
            return $this->reviewWorkflow;
        }

        // ── 9. TO scheduled (V2 `rescheduled`) ────────────────────────────────────
        if (in_array($current, $anySource, true) && $target === OrderStatus::Scheduled->value) {
            return $this->markRescheduledWorkflow;
        }

        return null;
    }

    /** POST /api/fulfillment/returns/{customerReturn}/receive */
    public function receiveReturn(Request $request, CustomerReturn $customerReturn): JsonResponse
    {
        $data = $request->validate([
            'warehouse_notes' => ['nullable', 'string', 'max:1000'],
            'line_conditions' => ['nullable', 'array'],
            'line_conditions.*' => ['string', 'in:sellable,damaged,destroyed'],
        ]);

        $updated = $this->receiveReturnWorkflow->execute(
            $customerReturn,
            (string) Auth::id(),
            $data['warehouse_notes'] ?? null,
            $data['line_conditions'] ?? [],
        );

        return response()->json([
            'message' => "CustomerReturn #{$updated->return_number} accepted.",
            'customer_return_id' => $updated->id,
            'status' => $updated->status,
            'inventory_restored_at' => $updated->inventory_restored_at?->toIso8601String(),
        ]);
    }
}
