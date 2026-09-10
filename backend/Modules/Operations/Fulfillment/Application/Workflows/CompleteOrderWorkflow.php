<?php

declare(strict_types=1);

namespace Modules\Operations\Fulfillment\Application\Workflows;

use Illuminate\Support\Facades\DB;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentContext;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentResult;
use Modules\Operations\Fulfillment\Domain\Contracts\FulfillmentWorkflowInterface;
use Modules\Operations\Fulfillment\Domain\Events\OrderCompletedEvent;
use Modules\Operations\Fulfillment\Domain\Exceptions\WorkflowPreconditionException;

/**
 * Financially completes an order: delivered → final cash.
 *
 * TASK-ECOS-OPERATIONS-PREPARATION-DRIVER-EOD-FINAL-023 §C/§K — this workflow
 * previously rewrote `Delivered -> Delivered` (a no-op) and fired
 * `OrderCompletedEvent` into a void with zero registered listeners; it is the
 * ADR-042-shaped slot this codebase had already reserved for "financial
 * completion" (see the prior `FulfillmentController` comment this class's own
 * route/binding predates: "there is no Completed edge; financial completion
 * remains the dedicated /complete route"). It is repurposed here to be that
 * edge for real, rather than built as a second workflow/route/binding — the
 * class name, its `/complete` HTTP surface, and `OrderCompletedEvent` all stay
 * exactly as they were.
 *
 * Triggered by {@see \Modules\Commerce\Orders\Application\Listeners\HandleTripCashHandoverConfirmed}
 * once Treasury has physically received and confirmed the driver's Trip cash
 * handover — never merely because the operational day ended. The guard below
 * re-verifies that fact directly against the database rather than trusting the
 * caller, so a manual/direct `/complete` call is held to the identical rule.
 */
final class CompleteOrderWorkflow implements FulfillmentWorkflowInterface
{
    public function guard(FulfillmentContext $ctx): void
    {
        $order = $ctx->order;

        if ($order->status !== OrderStatus::Delivered) {
            throw new WorkflowPreconditionException(
                "Order [{$order->id}] must be in delivered status before completion. Current: [{$order->status->value}].",
            );
        }

        if (! $this->hasConfirmedCashHandover($order->id)) {
            throw new WorkflowPreconditionException(
                "Order [{$order->id}] cannot move to Final Cash until its delivering Trip's cash handover has been physically confirmed by Treasury.",
            );
        }
    }

    public function execute(FulfillmentContext $ctx): FulfillmentResult
    {
        $order = $ctx->order;

        $order->update(['status' => OrderStatus::FinalCash]);
        $order->refresh();

        return FulfillmentResult::success(
            $order,
            "Order #{$order->order_number} cash-settled and permanently closed.",
            [
                'revenue' => (float) ($order->total ?? 0),
                'cogs_amount' => (float) ($order->actual_cogs_amount ?? 0),
                'margin_amount' => (float) ($order->actual_margin_amount ?? 0),
                'margin_percent' => $order->actual_margin_percent !== null ? (float) $order->actual_margin_percent : null,
                'completed_at' => now()->toIso8601String(),
                'actor_id' => $ctx->actorId,
            ],
        );
    }

    /** @return list<object> */
    public function events(FulfillmentResult $result): array
    {
        $order = $result->order;

        return [
            new OrderCompletedEvent(
                orderId: $order->id,
                orderNumber: $order->order_number,
                companyId: $order->company_id ?? '',
                revenue: (float) ($result->meta['revenue'] ?? 0),
                cogsAmount: (float) ($result->meta['cogs_amount'] ?? 0),
                marginAmount: (float) ($result->meta['margin_amount'] ?? 0),
                marginPercent: $result->meta['margin_percent'] !== null ? (float) $result->meta['margin_percent'] : null,
                completedAt: $result->meta['completed_at'] ?? now()->toIso8601String(),
                actorId: $result->meta['actor_id'] ?? null,
            ),
        ];
    }

    public function name(): string
    {
        return 'complete_order';
    }

    /**
     * Whether the order's CURRENTLY ACTIVE delivering Trip (its live, non-superseded
     * `distribution_trip_orders` link — never a stale/historical one from an earlier
     * failed-and-replanned attempt) has a confirmed `TripCashHandover` row.
     *
     * Raw `DB::table()` reads across the Distribution schema, never an Eloquent
     * import of a Distribution model — the same lightweight cross-module read
     * shape `HandlePreparationWaveClosed` already uses for `preparation_wave_orders`.
     */
    private function hasConfirmedCashHandover(string $orderId): bool
    {
        return DB::table('distribution_trip_orders as tro')
            ->join('distribution_trip_cash_handovers as tch', 'tch.trip_id', '=', 'tro.trip_id')
            ->where('tro.order_id', $orderId)
            ->whereNull('tro.superseded_at')
            ->exists();
    }
}
