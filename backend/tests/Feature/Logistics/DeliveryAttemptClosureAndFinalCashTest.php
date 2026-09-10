<?php

declare(strict_types=1);

namespace Tests\Feature\Logistics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Logistics\Distribution\Domain\Enums\DeliveryStopStatus;
use Modules\Logistics\Distribution\Domain\Enums\TripStatus;
use Modules\Logistics\Distribution\Domain\Events\TripCashHandoverConfirmed;
use Modules\Logistics\Distribution\Domain\Models\DistributionWindow;
use Modules\Logistics\Distribution\Domain\Models\Trip;
use Modules\Logistics\Distribution\Domain\Models\VirtualCapacitySlot;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Operations\Fulfillment\Application\FulfillmentEngine;
use Modules\Operations\Fulfillment\Application\Workflows\CompleteOrderWorkflow;
use Modules\Operations\Preparation\Domain\Events\WaveClosed;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-ECOS-OPERATIONS-PREPARATION-DRIVER-EOD-FINAL-023 §C/§D/§E/§G/§K —
 *
 *   - DeliveryAttemptClosureService, via the real WaveClosed event (so
 *     CloseWaveDeliveryAttemptsListener's registration is under test too).
 *   - CompleteOrderWorkflow's new "confirmed cash handover" guard.
 *   - HandleTripCashHandoverConfirmed, via the real TripCashHandoverConfirmed
 *     event (dispatched directly here rather than through the full
 *     CashHandoverService::confirmReceipt() call chain — that chain, and its
 *     own idempotency/Treasury-posting contract, is already covered by
 *     backend/tests/Feature/Operations/CashHandoverConfirmationTest.php; this
 *     file tests ONLY the new Order-status bridge the event triggers).
 */
final class DeliveryAttemptClosureAndFinalCashTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $customer;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
    }

    // ── §D/§E — the closure sweep ────────────────────────────────────────────

    public function test_wave_closure_releases_a_never_attempted_stop_to_in_progress(): void
    {
        $waveId = $this->wave();
        $trip = $this->tripFor($this->groupFor($this->window(), $waveId));
        $order = $this->orderOutForDelivery();
        $this->tripOrder($trip, $order->id);
        $this->stop($trip, $order->id, DeliveryStopStatus::Pending); // never resolved

        $this->closeWaveEvent($waveId);

        self::assertSame(OrderStatus::InProgress->value, $order->refresh()->status->value);
        self::assertNotNull(
            DB::table('distribution_trip_orders')
                ->where('trip_id', $trip->id)->where('order_id', $order->id)->value('superseded_at'),
            'The stale trip/order association must be released.',
        );
    }

    public function test_wave_closure_moves_a_non_retryable_failed_order_to_on_hold(): void
    {
        $waveId = $this->wave();
        $trip = $this->tripFor($this->groupFor($this->window(), $waveId));
        $order = $this->orderOutForDelivery();
        $this->tripOrder($trip, $order->id);
        $stop = $this->stop($trip, $order->id, DeliveryStopStatus::Failed);
        $this->deliveryAction($stop->id, 'customer_refused'); // non-retryable

        $this->closeWaveEvent($waveId);

        $order->refresh();
        self::assertSame(OrderStatus::OnHold->value, $order->status->value);
        self::assertSame('customer_refused', $order->hold_reason_code);
    }

    public function test_wave_closure_releases_a_retryable_failed_order_as_a_backstop(): void
    {
        $waveId = $this->wave();
        $trip = $this->tripFor($this->groupFor($this->window(), $waveId));
        $order = $this->orderOutForDelivery();
        $this->tripOrder($trip, $order->id);
        $stop = $this->stop($trip, $order->id, DeliveryStopStatus::Failed);
        $this->deliveryAction($stop->id, 'no_answer'); // retryable — normally handled immediately;
        // this proves the closure sweep is a safe backstop if that ever didn't fire.

        $this->closeWaveEvent($waveId);

        self::assertSame(OrderStatus::InProgress->value, $order->refresh()->status->value);
    }

    public function test_wave_closure_does_not_touch_a_delivered_order(): void
    {
        $waveId = $this->wave();
        $trip = $this->tripFor($this->groupFor($this->window(), $waveId));
        // Created directly AT Delivered — never transitioned via update() outside
        // the FulfillmentEngine guard, which the Order model's own write-hook
        // (UnauthorizedOrderStatusWriteException) would otherwise refuse.
        $order = $this->orderDelivered();
        $this->tripOrder($trip, $order->id);
        $this->stop($trip, $order->id, DeliveryStopStatus::Delivered);

        $this->closeWaveEvent($waveId);

        self::assertSame(OrderStatus::Delivered->value, $order->refresh()->status->value, 'Completed must remain Completed at closure.');
        self::assertNull(
            DB::table('distribution_trip_orders')
                ->where('trip_id', $trip->id)->where('order_id', $order->id)->value('superseded_at'),
            'A delivered order\'s trip association must not be released.',
        );
    }

    public function test_delivery_attempt_closure_sweep_is_idempotent(): void
    {
        $waveId = $this->wave();
        $trip = $this->tripFor($this->groupFor($this->window(), $waveId));
        $order = $this->orderOutForDelivery();
        $this->tripOrder($trip, $order->id);
        $this->stop($trip, $order->id, DeliveryStopStatus::Pending);

        $this->closeWaveEvent($waveId);
        self::assertSame(OrderStatus::InProgress->value, $order->refresh()->status->value);
        $firstUpdatedAt = $order->updated_at;

        // A replayed WaveClosed must find nothing left to do — the order is no
        // longer OutForDelivery, so it is excluded from the candidate set entirely.
        $this->closeWaveEvent($waveId);
        $order->refresh();

        self::assertSame(OrderStatus::InProgress->value, $order->status->value);
        self::assertSame($firstUpdatedAt->toIso8601String(), $order->updated_at->toIso8601String());
    }

    public function test_closure_sweep_does_not_mutate_warehouse_inventory(): void
    {
        $waveId = $this->wave();
        $trip = $this->tripFor($this->groupFor($this->window(), $waveId));
        $order = $this->orderOutForDelivery();
        $this->tripOrder($trip, $order->id);
        $this->stop($trip, $order->id, DeliveryStopStatus::Failed);

        $before = DB::table('inventory_items')->count();
        $beforeLedger = DB::table('stock_ledger_entries')->count();

        $this->closeWaveEvent($waveId);

        self::assertSame($before, DB::table('inventory_items')->count(), 'Midnight/closure must never mutate warehouse stock rows.');
        self::assertSame($beforeLedger, DB::table('stock_ledger_entries')->count(), 'Midnight/closure must never write a ledger movement.');
    }

    // ── §C/§K — Final Cash ───────────────────────────────────────────────────

    public function test_complete_order_workflow_refuses_without_confirmed_cash_handover(): void
    {
        $trip = $this->tripFor($this->groupFor($this->window(), $this->wave()));
        $order = $this->orderDelivered();
        $this->tripOrder($trip, $order->id);
        // Deliberately NO TripCashHandover row.

        $this->expectException(\Modules\Operations\Fulfillment\Domain\Exceptions\WorkflowPreconditionException::class);

        app(FulfillmentEngine::class)->run(app(CompleteOrderWorkflow::class), $order);
    }

    public function test_complete_order_workflow_succeeds_after_confirmed_cash_handover(): void
    {
        $trip = $this->tripFor($this->groupFor($this->window(), $this->wave()));
        $order = $this->orderDelivered();
        $this->tripOrder($trip, $order->id);
        $this->cashHandover($trip->id);

        $result = app(FulfillmentEngine::class)->run(app(CompleteOrderWorkflow::class), $order);

        self::assertSame(OrderStatus::FinalCash->value, $result->order->status->value);
    }

    public function test_cash_handover_confirmation_advances_delivered_orders_to_final_cash(): void
    {
        $trip = $this->tripFor($this->groupFor($this->window(), $this->wave()));
        $order = $this->orderDelivered();
        $this->tripOrder($trip, $order->id);

        $this->dispatchHandoverConfirmed($trip->id, 'handover-1');

        self::assertSame(OrderStatus::FinalCash->value, $order->refresh()->status->value);
    }

    public function test_a_different_trips_order_is_not_advanced_by_this_trips_handover(): void
    {
        $tripA = $this->tripFor($this->groupFor($this->window(), $this->wave()));
        $tripB = $this->tripFor($this->groupFor($this->window(), $this->wave()));

        $order = $this->orderDelivered();
        // Order failed on Trip A (released) and was later delivered on Trip B.
        $this->tripOrder($tripA, $order->id, superseded: true);
        $this->tripOrder($tripB, $order->id);

        $this->dispatchHandoverConfirmed($tripA->id, 'handover-a');

        self::assertSame(
            OrderStatus::Delivered->value,
            $order->refresh()->status->value,
            'Trip A\'s handover must not finalize an order actually delivered (and to-be-settled) via Trip B.',
        );
    }

    public function test_only_delivered_orders_are_candidates_for_final_cash(): void
    {
        $trip = $this->tripFor($this->groupFor($this->window(), $this->wave()));
        $onHold = Order::query()->create($this->orderAttributes(OrderStatus::OnHold));
        $this->tripOrder($trip, $onHold->id);

        $this->dispatchHandoverConfirmed($trip->id, 'handover-2');

        self::assertSame(OrderStatus::OnHold->value, $onHold->refresh()->status->value);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function wave(): string
    {
        $id = (string) Str::uuid();
        $date = now()->toDateString();

        DB::table('preparation_waves')->insert([
            'id' => $id,
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'wave_number' => 'W-'.substr(uniqid(), -8),
            'planning_date' => $date,
            'status' => 'preparing',
            'wave_type' => 'engine',
            'starts_at' => $date.' 00:00:00',
            'ends_at' => $date.' 23:59:59',
            'created_by' => (string) Str::uuid(),
            'updated_by' => (string) Str::uuid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function window(): string
    {
        $date = now()->toDateString();

        $existing = DistributionWindow::query()
            ->where('company_id', $this->company->id)
            ->whereDate('window_date', $date)
            ->first();

        if ($existing !== null) {
            return $existing->id;
        }

        $id = (string) Str::uuid();

        DB::table('distribution_windows')->insert([
            'id' => $id,
            'company_id' => $this->company->id,
            'window_date' => $date,
            'status' => 'open',
            'opens_at' => $date.' 00:00:00',
            'closes_at' => $date.' 23:59:59',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function groupFor(string $windowId, string $waveId): VirtualCapacitySlot
    {
        return VirtualCapacitySlot::query()->create([
            'company_id' => $this->company->id,
            'distribution_window_id' => $windowId,
            'preparation_wave_id' => $waveId,
            'warehouse_id' => $this->warehouse->id,
            'code' => 'DG-'.substr(uniqid(), -6),
        ]);
    }

    private function tripFor(VirtualCapacitySlot $group): Trip
    {
        return Trip::query()->create([
            'company_id' => $this->company->id,
            'virtual_slot_id' => $group->id,
            'name' => $group->code,
            'trip_number' => 'TRP-'.substr(uniqid(), -8),
            'status' => TripStatus::OutForDelivery->value,
        ]);
    }

    private function tripOrder(Trip $trip, string $orderId, bool $superseded = false): void
    {
        $trip->tripOrders()->create([
            'order_id' => $orderId,
            'assignment_type' => 'manual',
            'assigned_at' => now(),
            'superseded_at' => $superseded ? now() : null,
            'release_reason' => $superseded ? 'test_superseded' : null,
        ]);
    }

    private function stop(Trip $trip, string $orderId, DeliveryStopStatus $status): \Modules\Logistics\Distribution\Domain\Models\DeliveryStop
    {
        return \Modules\Logistics\Distribution\Domain\Models\DeliveryStop::query()->create([
            'trip_id' => $trip->id,
            'order_id' => $orderId,
            'status' => $status->value,
        ]);
    }

    private function deliveryAction(string $stopId, string $reason): void
    {
        \Modules\Logistics\Distribution\Domain\Models\DeliveryAction::query()->create([
            'stop_id' => $stopId,
            'action_type' => 'failed',
            'reason' => $reason,
        ]);
    }

    private function cashHandover(int $tripId): void
    {
        DB::table('distribution_trip_cash_handovers')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $this->company->id,
            'trip_settlement_id' => (string) Str::uuid(),
            'trip_id' => $tripId,
            'expected_cash' => 100.0,
            'received_cash' => 100.0,
            'difference' => 0.0,
            'cash_account_id' => 1,
            'cash_transaction_id' => 1,
            'received_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function dispatchHandoverConfirmed(int $tripId, string $handoverId): void
    {
        event(new TripCashHandoverConfirmed(
            tripId: (string) $tripId,
            tripSettlementId: (string) Str::uuid(),
            companyId: (string) $this->company->id,
            handoverId: $handoverId,
            receivedCash: 100.0,
            expectedCash: 100.0,
            confirmedBy: 1,
            confirmedAt: now()->toIso8601String(),
        ));
    }

    private function closeWaveEvent(string $waveId): void
    {
        event(new WaveClosed(
            waveId: $waveId,
            waveNumber: 'W-'.substr($waveId, 0, 6),
            companyId: (string) $this->company->id,
            warehouseId: (string) $this->warehouse->id,
            planningDate: now()->toDateString(),
            closedBy: (string) Str::uuid(),
            closedAt: now()->toIso8601String(),
        ));
    }

    private function orderOutForDelivery(): Order
    {
        return Order::query()->create($this->orderAttributes(OrderStatus::OutForDelivery));
    }

    private function orderDelivered(): Order
    {
        return Order::query()->create($this->orderAttributes(OrderStatus::Delivered));
    }

    /** @return array<string, mixed> */
    private function orderAttributes(OrderStatus $status): array
    {
        return [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-EOD-'.uniqid(),
            'order_date' => now()->toDateString(),
            'assigned_warehouse_id' => $this->warehouse->id,
            'city' => 'Maadi',
            'governorate' => 'Cairo',
            'status' => $status->value,
            'subtotal' => 100, 'total' => 100, 'deposit_amount' => 0,
            'shipping_total' => 0, 'discount_total' => 0, 'tax_total' => 0,
        ];
    }
}
