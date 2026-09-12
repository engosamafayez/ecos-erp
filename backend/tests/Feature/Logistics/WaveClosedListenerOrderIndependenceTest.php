<?php

declare(strict_types=1);

namespace Tests\Feature\Logistics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Logistics\Distribution\Application\Listeners\CloseWaveDeliveryAttemptsListener;
use Modules\Logistics\Distribution\Application\Listeners\CloseWaveLoadingCustodyListener;
use Modules\Logistics\Distribution\Domain\Enums\DeliveryStopStatus;
use Modules\Logistics\Distribution\Domain\Enums\TripStatus;
use Modules\Logistics\Distribution\Domain\Models\DeliveryAction;
use Modules\Logistics\Distribution\Domain\Models\DeliveryStop;
use Modules\Logistics\Distribution\Domain\Models\DistributionWindow;
use Modules\Logistics\Distribution\Domain\Models\Trip;
use Modules\Logistics\Distribution\Domain\Models\VirtualCapacitySlot;
use Modules\Logistics\Distribution\Domain\Services\DeliveryAttemptClosureService;
use Modules\Logistics\Distribution\Domain\Services\WaveClosureCustodyService;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Operations\Preparation\Domain\Events\WaveClosed;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-ECOS-V1-REMEDIATION-OPERATIONS-DISTRIBUTION-035C §1 — proves
 * `WaveClosureCustodyService` and `DeliveryAttemptClosureService` (the two
 * WaveClosed reactions this task reconciled) reach the SAME correct outcome no
 * matter which one runs first, on a single unaccepted Trip carrying one order
 * of every status either service could plausibly see at once:
 *
 *   - OutForDelivery + non-retryable failure -> DeliveryAttemptClosureService's
 *     domain (must land OnHold with the real failure reason).
 *   - Cancelled with custody (Gate 4)        -> DeliveryAttemptClosureService's
 *     domain (must land OnHold with REASON_CANCELLED_WITH_CUSTODY).
 *   - Delivered                              -> already-succeeded; neither
 *     service may touch it.
 *   - ReadyForDispatch                       -> genuinely
 *     WaveClosureCustodyService's own domain (never attempted).
 *
 * Both orderings are exercised by invoking the two listeners directly
 * (bypassing `event()`, whose registration order is fixed) so this test keeps
 * proving the property even if the provider's registration order ever changes.
 */
final class WaveClosedListenerOrderIndependenceTest extends TestCase
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

    public function test_custody_then_delivery_attempts_reaches_the_correct_outcome(): void
    {
        $fixture = $this->buildScenario();

        app(CloseWaveLoadingCustodyListener::class)->handle($fixture['event']);
        app(CloseWaveDeliveryAttemptsListener::class)->handle($fixture['event']);

        $this->assertCorrectOutcome($fixture);
    }

    public function test_delivery_attempts_then_custody_reaches_the_same_outcome(): void
    {
        $fixture = $this->buildScenario();

        app(CloseWaveDeliveryAttemptsListener::class)->handle($fixture['event']);
        app(CloseWaveLoadingCustodyListener::class)->handle($fixture['event']);

        $this->assertCorrectOutcome($fixture);
    }

    /**
     * @return array{
     *   event: WaveClosed,
     *   trip: Trip,
     *   refused: Order,
     *   cancelled: Order,
     *   delivered: Order,
     *   readyForDispatch: Order,
     * }
     */
    private function buildScenario(): array
    {
        $waveId = $this->wave();
        $group = $this->groupFor($this->window(), $waveId);

        // Deliberately unaccepted: hasFullDriverAcceptance() === false is the
        // only precondition either sweep needs to consider this Trip at all.
        $trip = Trip::query()->create([
            'company_id' => $this->company->id,
            'virtual_slot_id' => $group->id,
            'name' => $group->code,
            'trip_number' => 'TRP-'.substr(uniqid(), -8),
            'status' => TripStatus::OutForDelivery->value,
        ]);

        $refused = Order::query()->create($this->orderAttributes(OrderStatus::OutForDelivery));
        $this->tripOrder($trip, $refused->id);
        $stop = DeliveryStop::query()->create([
            'trip_id' => $trip->id,
            'order_id' => $refused->id,
            'status' => DeliveryStopStatus::Failed->value,
        ]);
        DeliveryAction::query()->create([
            'stop_id' => $stop->id,
            'action_type' => 'failed',
            'reason' => 'customer_refused',
        ]);

        $cancelled = Order::query()->create($this->orderAttributes(OrderStatus::Cancelled));
        $this->tripOrder($trip, $cancelled->id);

        $delivered = Order::query()->create($this->orderAttributes(OrderStatus::Delivered));
        $this->tripOrder($trip, $delivered->id);

        $readyForDispatch = Order::query()->create($this->orderAttributes(OrderStatus::ReadyForDispatch));
        $this->tripOrder($trip, $readyForDispatch->id);

        $event = new WaveClosed(
            waveId: $waveId,
            waveNumber: 'W-'.substr($waveId, 0, 6),
            companyId: (string) $this->company->id,
            warehouseId: (string) $this->warehouse->id,
            planningDate: now()->toDateString(),
            closedBy: (string) Str::uuid(),
            closedAt: now()->toIso8601String(),
        );

        return [
            'event' => $event,
            'trip' => $trip,
            'refused' => $refused,
            'cancelled' => $cancelled,
            'delivered' => $delivered,
            'readyForDispatch' => $readyForDispatch,
        ];
    }

    /** @param array{trip: Trip, refused: Order, cancelled: Order, delivered: Order, readyForDispatch: Order} $fixture */
    private function assertCorrectOutcome(array $fixture): void
    {
        $trip = $fixture['trip'];

        $refused = $fixture['refused']->refresh();
        self::assertSame(OrderStatus::OnHold->value, $refused->status->value, 'A non-retryable failure must reach On Hold regardless of listener order.');
        self::assertSame('customer_refused', $refused->hold_reason_code);
        self::assertSame(
            'customer_refused',
            DB::table('distribution_trip_orders')
                ->where('trip_id', $trip->id)->where('order_id', $refused->id)->value('release_reason'),
            'The specific failure reason must win over the custody sweep\'s generic reason.',
        );

        $cancelled = $fixture['cancelled']->refresh();
        self::assertSame(OrderStatus::OnHold->value, $cancelled->status->value, 'Gate 4: a Cancelled order with custody must reach On Hold regardless of listener order.');
        self::assertSame(DeliveryAttemptClosureService::REASON_CANCELLED_WITH_CUSTODY, $cancelled->hold_reason_code);
        self::assertSame(
            DeliveryAttemptClosureService::REASON_CANCELLED_WITH_CUSTODY,
            DB::table('distribution_trip_orders')
                ->where('trip_id', $trip->id)->where('order_id', $cancelled->id)->value('release_reason'),
        );

        $delivered = $fixture['delivered']->refresh();
        self::assertSame(OrderStatus::Delivered->value, $delivered->status->value, 'An already-delivered order must never be touched by either sweep.');
        self::assertNull(
            DB::table('distribution_trip_orders')
                ->where('trip_id', $trip->id)->where('order_id', $delivered->id)->value('superseded_at'),
            'A delivered order\'s trip association must stay active regardless of listener order.',
        );

        $readyForDispatch = $fixture['readyForDispatch']->refresh();
        self::assertSame(
            OrderStatus::ReadyForDispatch->value,
            $readyForDispatch->status->value,
            'WaveClosureCustodyService never writes the Order\'s own status.',
        );
        self::assertSame(
            WaveClosureCustodyService::REASON_NOT_LOADED,
            DB::table('distribution_trip_orders')
                ->where('trip_id', $trip->id)->where('order_id', $readyForDispatch->id)->value('release_reason'),
            'A never-attempted order is genuinely WaveClosureCustodyService\'s own domain, regardless of listener order.',
        );

        self::assertSame(TripStatus::Cancelled, $trip->refresh()->status);
    }

    // ── Fixtures (mirrors WaveClosureCustodySweepTest / DeliveryAttemptClosureAndFinalCashTest) ──

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

    private function tripOrder(Trip $trip, string $orderId): void
    {
        $trip->tripOrders()->create([
            'order_id' => $orderId,
            'assignment_type' => 'manual',
            'assigned_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function orderAttributes(OrderStatus $status): array
    {
        return [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-OI-'.uniqid(),
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
