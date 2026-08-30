<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Operations\Fulfillment\Presentation\Http\Controllers\FulfillmentController;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * TASK-PHASE3-RC10-IMPLEMENT-CERTIFY-001 — Steps 4–6, V3 transition resolution.
 *
 * The generic /transition endpoint mapped (current, target) → workflow using V2
 * vocabulary (`pending`, `confirmed`, `processing`, `preparing`, `review`,
 * `rescheduled`, `completed`) — none of which are OrderStatus cases — so every
 * V3 order was refused 422 and the endpoint was effectively dead.
 *
 * These cases pin the ROUTING table only. Business preconditions live in
 * $workflow->guard() and are exercised by the workflow suites; nothing here
 * mocks a guard away.
 */
final class V3TransitionResolutionTest extends TestCase
{
    private function resolve(string $current, string $target): ?object
    {
        $controller = app(FulfillmentController::class);
        $method = new ReflectionMethod($controller, 'resolveTransitionWorkflow');

        /** @var object|null $workflow */
        $workflow = $method->invoke($controller, $current, $target);

        return $workflow;
    }

    private function shortName(?object $workflow): ?string
    {
        if ($workflow === null) {
            return null;
        }

        $parts = explode('\\', $workflow::class);

        return end($parts);
    }

    // ── Every routed edge resolves to its authoritative workflow ──────────────

    /** @return list<array{0:string,1:string,2:string}> */
    public static function routedEdges(): array
    {
        return [
            'activation' => [OrderStatus::NewOrder->value,          OrderStatus::InProgress->value,       'ProcessOrderWorkflow'],
            'activate from hold' => [OrderStatus::OnHold->value,            OrderStatus::InProgress->value,       'ProcessOrderWorkflow'],
            'ready for dispatch' => [OrderStatus::InProgress->value,        OrderStatus::ReadyForDispatch->value, 'MoveToPreparationWorkflow'],
            'dispatch' => [OrderStatus::ReadyForDispatch->value,  OrderStatus::OutForDelivery->value,   'DispatchOrderWorkflow'],
            'deliver' => [OrderStatus::OutForDelivery->value,    OrderStatus::Delivered->value,        'CompleteDeliveryWorkflow'],
            'return delivered' => [OrderStatus::Delivered->value,         OrderStatus::Returned->value,         'ReturnOrderWorkflow'],
            'cancel' => [OrderStatus::InProgress->value,        OrderStatus::Cancelled->value,        'CancelOrderWorkflow'],
            'release to new' => [OrderStatus::InProgress->value,        OrderStatus::NewOrder->value,         'ReturnToPendingWorkflow'],
            'release to payment' => [OrderStatus::InProgress->value,        OrderStatus::AwaitingPayment->value,  'ReturnToPaymentWorkflow'],
            'awaiting stock' => [OrderStatus::InProgress->value,        OrderStatus::AwaitingStock->value,    'MarkAwaitingStockWorkflow'],
            'on hold' => [OrderStatus::InProgress->value,        OrderStatus::OnHold->value,           'MoveToReviewWorkflow'],
            'reschedule' => [OrderStatus::InProgress->value,        OrderStatus::Scheduled->value,        'MarkRescheduledWorkflow'],
            'early to new' => [OrderStatus::AwaitingPayment->value,   OrderStatus::NewOrder->value,         'SetEarlyStatusWorkflow'],
        ];
    }

    #[DataProvider('routedEdges')]
    public function test_v3_edge_resolves_to_its_authoritative_workflow(string $from, string $to, string $expected): void
    {
        self::assertSame($expected, $this->shortName($this->resolve($from, $to)));
    }

    // ── Refusals ──────────────────────────────────────────────────────────────

    /** @return list<array{0:string,1:string}> */
    public static function refusedEdges(): array
    {
        return [
            'self transition' => [OrderStatus::InProgress->value,       OrderStatus::InProgress->value],
            'skip dispatch' => [OrderStatus::InProgress->value,       OrderStatus::Delivered->value],
            'reverse from delivered' => [OrderStatus::Delivered->value,        OrderStatus::InProgress->value],
            'locked ready to new' => [OrderStatus::ReadyForDispatch->value, OrderStatus::NewOrder->value],
            'locked out for delivery' => [OrderStatus::OutForDelivery->value,   OrderStatus::OnHold->value],
            'returned is locked' => [OrderStatus::Returned->value,         OrderStatus::InProgress->value],
            'new straight to dispatch' => [OrderStatus::NewOrder->value,         OrderStatus::OutForDelivery->value],
        ];
    }

    #[DataProvider('refusedEdges')]
    public function test_illegal_edge_is_refused(string $from, string $to): void
    {
        self::assertNull($this->resolve($from, $to), "[{$from}] → [{$to}] must not resolve.");
    }

    // ── PD-2: no Completed state ──────────────────────────────────────────────

    public function test_no_completed_edge_exists(): void
    {
        // V3 has no Completed case; the legacy token must not resolve either.
        self::assertNull($this->resolve(OrderStatus::Delivered->value, 'completed'));
    }

    public function test_retired_v2_vocabulary_no_longer_resolves(): void
    {
        foreach (['pending', 'confirmed', 'processing', 'preparing', 'review', 'rescheduled'] as $legacy) {
            self::assertNull(
                $this->resolve(OrderStatus::InProgress->value, $legacy),
                "Retired V2 token [{$legacy}] must not be a valid target.",
            );
        }
    }

    // ── Every enum case is reachable as a source without fatal ────────────────

    public function test_every_v3_state_is_handled_without_error(): void
    {
        foreach (OrderStatus::cases() as $from) {
            foreach (OrderStatus::cases() as $to) {
                $resolved = $this->resolve($from->value, $to->value);
                self::assertTrue($resolved === null || is_object($resolved));
            }
        }
    }
}
