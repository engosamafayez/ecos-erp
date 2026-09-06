<?php

declare(strict_types=1);

namespace Tests\Feature\Logistics;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Logistics\Delivery\Domain\Enums\FailureReason;
use Modules\Logistics\Distribution\Domain\Enums\DeliveryStopStatus;
use Modules\Logistics\Distribution\Domain\Enums\TripStatus;
use Modules\Logistics\Distribution\Domain\Enums\TripType;
use Modules\Logistics\Distribution\Domain\Exceptions\DistributionException;
use Modules\Logistics\Distribution\Domain\Models\Trip;
use Modules\Logistics\Distribution\Domain\Models\TripOrder;
use Modules\Logistics\Distribution\Domain\Services\DeliveryService;
use Modules\Logistics\Distribution\Domain\Services\TripService;
use Modules\Logistics\Drivers\Domain\Models\Driver;
use Modules\Logistics\Drivers\Domain\Services\DriverVehicleAssignmentService;
use Modules\Logistics\Vehicles\Domain\Models\Vehicle;
use Modules\Operations\Loading\Domain\Models\VehicleAssignment;
use Modules\Operations\Loading\Domain\Models\VehicleInventoryItem;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-POST-DRIVER-RETURN-WAREHOUSE-RETURNS-FINAL-IMPLEMENTATION-002 §26.
 *
 * Pins the CTO-approved historical-attempt invariant end to end: an order
 * whose Trip 1 attempt closes with a retryable outcome (No Answer /
 * Postponed) is released — not deleted — and becomes assignable to a new
 * Trip 2, while Trip 1's association, the old vehicle's custody, and the
 * order's full attempt history all remain exactly as they were.
 *
 * NOT RUN as part of this task (Validation Freeze, §27) — written against
 * the same fixture conventions as TripDepartureLifecycleTest (the sibling
 * suite in this same directory) and reviewed for correctness, not executed.
 */
final class RetryableDeliveryReleaseTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;

    private User $actor;

    public function test_no_answer_release_supersedes_the_trip_order_and_frees_the_order_for_replanning(): void
    {
        [$trip1, $orderId] = $this->tripWithOutForDeliveryOrder();

        $stop = $trip1->stops()->create([
            'order_id' => $orderId,
            'sequence' => 1,
            'status' => DeliveryStopStatus::InProgress->value,
        ]);

        app(DeliveryService::class)->recordAction($stop, [
            'action_type' => 'failed',
            'reason' => FailureReason::NoAnswer->value,
        ], $this->actor->id);

        // The one call any driver-app "No Answer" tap ultimately makes — this is what
        // dispatches DeliveryStopCompleted and, through it, the retryable-release listener.
        app(DeliveryService::class)->completeStop($stop, DeliveryStopStatus::Failed, [], (string) $this->actor->id);

        // TripOrder itself carries no global scope (only Trip::tripOrders() scopes the
        // relation) — a plain model query still sees every historical row.
        $originalTripOrder = TripOrder::query()
            ->where('trip_id', $trip1->id)->where('order_id', $orderId)->firstOrFail();

        self::assertNotNull($originalTripOrder->superseded_at, 'Trip 1\'s association is released, not left active');
        self::assertSame(FailureReason::NoAnswer->value, $originalTripOrder->release_reason);
        self::assertNull(
            TripOrder::query()->active()->where('order_id', $orderId)->first(),
            'no active association remains — the order is genuinely free',
        );

        $order = Order::query()->where('id', $orderId)->firstOrFail();
        self::assertSame(OrderStatus::InProgress, $order->status, '§4: eligible for replanning immediately, no future-dated wait');
    }

    public function test_postponed_release_uses_the_same_path_as_no_answer(): void
    {
        [$trip1, $orderId] = $this->tripWithOutForDeliveryOrder();

        $stop = $trip1->stops()->create([
            'order_id' => $orderId,
            'sequence' => 1,
            'status' => DeliveryStopStatus::InProgress->value,
        ]);

        app(DeliveryService::class)->recordAction($stop, [
            'action_type' => 'failed',
            'reason' => FailureReason::CustomerRescheduled->value, // §12's canonical "Postponed" analogue
        ], $this->actor->id);

        app(DeliveryService::class)->completeStop($stop, DeliveryStopStatus::Failed, [], (string) $this->actor->id);

        self::assertSame(OrderStatus::InProgress, Order::query()->where('id', $orderId)->firstOrFail()->status);
        self::assertNull(TripOrder::query()->active()->where('order_id', $orderId)->first());
    }

    public function test_a_non_retryable_reason_does_not_release_the_order(): void
    {
        [$trip1, $orderId] = $this->tripWithOutForDeliveryOrder();

        $stop = $trip1->stops()->create([
            'order_id' => $orderId,
            'sequence' => 1,
            'status' => DeliveryStopStatus::InProgress->value,
        ]);

        app(DeliveryService::class)->recordAction($stop, [
            'action_type' => 'refused',
            'reason' => FailureReason::CustomerRefused->value, // BR-9/BR-10: never auto-retryable
        ], $this->actor->id);

        app(DeliveryService::class)->completeStop($stop, DeliveryStopStatus::Failed, [], (string) $this->actor->id);

        self::assertSame(
            OrderStatus::OutForDelivery,
            Order::query()->where('id', $orderId)->firstOrFail()->status,
            'a hard refusal is left entirely alone — a different, CustomerReturn-owned scenario',
        );
        self::assertNotNull(
            TripOrder::query()->active()->where('order_id', $orderId)->first(),
            'the association is untouched',
        );
    }

    public function test_released_order_can_be_assigned_to_a_new_trip_while_trip_one_stays_historical(): void
    {
        [$trip1, $orderId] = $this->tripWithOutForDeliveryOrder();
        $trips = app(TripService::class);

        $trips->releaseOrder($trip1->refresh(), $orderId, FailureReason::NoAnswer->value, $this->actor->id);

        $trip2 = Trip::create([
            'company_id' => $this->company->id,
            'trip_number' => 'TRP-'.substr(md5(uniqid('t2', true)), 0, 6),
            'name' => 'Retry Run',
            'type' => TripType::CompanyVehicle->value,
            'capacity' => 3,
            'created_by' => $this->actor->id,
        ]);

        // §11: one Order across sequential Trips — this must succeed now that Trip 1's
        // association is superseded, and it must NOT touch Trip 1's row at all.
        $newAssignment = $trips->assignOrder($trip2, $orderId, [], $this->actor->id);

        self::assertSame($trip2->id, $newAssignment->trip_id);
        self::assertTrue($newAssignment->isActive());

        // §16: only ONE active execution at a time.
        self::assertSame(
            1,
            TripOrder::query()->active()->where('order_id', $orderId)->count(),
            'exactly one active association exists for this order',
        );

        // §11/§16: Trip 1 remains visible/auditable — never overwritten, never deleted.
        $trip1Association = TripOrder::query()
            ->where('trip_id', $trip1->id)->where('order_id', $orderId)->first();
        self::assertNotNull($trip1Association, 'Trip 1\'s historical row still exists');
        self::assertNotNull($trip1Association->superseded_at);

        // §16: assigning a THIRD trip while Trip 2 is active must still be refused.
        $trip3 = Trip::create([
            'company_id' => $this->company->id,
            'trip_number' => 'TRP-'.substr(md5(uniqid('t3', true)), 0, 6),
            'name' => 'Should Refuse',
            'type' => TripType::CompanyVehicle->value,
            'capacity' => 3,
            'created_by' => $this->actor->id,
        ]);

        $this->expectException(DistributionException::class);
        $trips->assignOrder($trip3, $orderId, [], $this->actor->id);
    }

    public function test_release_does_not_touch_the_old_vehicles_custody(): void
    {
        [$trip1, $orderId] = $this->tripWithOutForDeliveryOrder();

        $vehicleAssignment = VehicleAssignment::query()->where('trip_id', $trip1->id)->firstOrFail();
        $item = VehicleInventoryItem::create([
            'company_id' => $this->company->id,
            'vehicle_assignment_id' => $vehicleAssignment->id,
            'vehicle_id' => $vehicleAssignment->vehicle_id,
            'product_id' => (string) Str::uuid(),
            'sku_snapshot' => 'SKU-RETRY',
            'name_snapshot' => 'Retry Product',
            'operational_date' => now()->toDateString(),
            'quantity_loaded' => 5,
            'quantity_delivered' => 0,
            'quantity_returned' => 0,
            'quantity_on_hand' => 5,
            'created_by' => (string) $this->actor->id,
            'updated_by' => (string) $this->actor->id,
        ]);

        app(TripService::class)->releaseOrder($trip1->refresh(), $orderId, FailureReason::NoAnswer->value, $this->actor->id);

        // §5: "Do NOT move the old physical goods back to warehouse... Do NOT transfer
        // the old units to the new Trip." Releasing the ORDER must not touch custody at all.
        $item->refresh();
        self::assertSame(5.0, (float) $item->quantity_on_hand);
        self::assertSame(0.0, (float) $item->quantity_returned);
    }

    // ── Fixture ───────────────────────────────────────────────────────────────

    /** @return array{0: Trip, 1: string} */
    private function tripWithOutForDeliveryOrder(): array
    {
        $this->company ??= Company::factory()->create();
        $this->actor ??= User::factory()->create(['company_id' => $this->company->id]);

        $driverUser = User::factory()->create(['company_id' => $this->company->id]);
        $driver = $this->makeDriver($driverUser);
        $vehicle = $this->makeVehicle();
        $assignment = app(DriverVehicleAssignmentService::class)->assign($driver, $vehicle);

        // Created directly in InProgress: Trip::create() has no status guard (only
        // changeStatus() enforces the transition table) — this test is about the
        // retry/release mechanism, not the departure lifecycle already pinned by
        // TripDepartureLifecycleTest in this same directory.
        $trip = Trip::create([
            'company_id' => $this->company->id,
            'trip_number' => 'TRP-'.substr(md5(uniqid('t1', true)), 0, 6),
            'name' => 'First Attempt',
            'type' => TripType::CompanyVehicle->value,
            'capacity' => 3,
            'status' => TripStatus::InProgress->value,
            'created_by' => $this->actor->id,
            'driver_vehicle_assignment_id' => $assignment->id,
        ]);

        $sessionId = (string) Str::uuid();
        DB::table('loading_sessions')->insert([
            'id' => $sessionId,
            'company_id' => $this->company->id,
            'warehouse_id' => (string) Str::uuid(),
            'session_number' => 'LS-'.substr(md5($sessionId), 0, 8),
            'operational_date' => now()->toDateString(),
            'status' => 'completed',
            'created_by' => (string) $this->actor->id,
            'updated_by' => (string) $this->actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        VehicleAssignment::create([
            'company_id' => $this->company->id,
            'loading_session_id' => $sessionId,
            'trip_id' => $trip->id,
            'vehicle_id' => $vehicle->id,
            'vehicle_registration_snapshot' => $vehicle->plate_number,
            'vehicle_type_snapshot' => 'van',
            'assignment_number' => 'VA-'.substr(md5($sessionId), 0, 8),
            'status' => 'dispatched',
            'created_by' => (string) $this->actor->id,
            'updated_by' => (string) $this->actor->id,
        ]);

        $orderId = $this->makeOrder(OrderStatus::OutForDelivery);
        app(TripService::class)->assignOrder($trip->refresh(), $orderId, [], $this->actor->id);

        return [$trip->refresh(), $orderId];
    }

    private function makeOrder(OrderStatus $status): string
    {
        $customerId = (string) Str::uuid();
        DB::table('customers')->insert([
            'id' => $customerId,
            'code' => 'CUS-'.substr(md5($customerId), 0, 8),
            'name' => 'Retry Test Customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orderId = (string) Str::uuid();
        DB::table('orders')->insert([
            'id' => $orderId,
            'company_id' => $this->company->id,
            'customer_id' => $customerId,
            'order_number' => 'ORD-'.substr(md5($orderId), 0, 8),
            'order_date' => now()->toDateString(),
            'status' => $status->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $orderId;
    }

    private function makeDriver(User $user): Driver
    {
        $suffix = strtoupper(substr(md5(uniqid('', true)), 0, 8));

        $driver = new Driver([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'driver_code' => 'DRV-'.$suffix,
            'full_name' => 'Retry Driver',
            'mobile' => '01'.random_int(100000000, 999999999),
            'national_id' => (string) random_int(10000000000000, 99999999999999),
            'license_issue_date' => '2024-01-01',
            'license_expiry_date' => '2031-01-01',
            'status' => Driver::STATUS_ACTIVE,
        ]);
        $driver->company_id = $user->company_id;
        $driver->save();

        return $driver->refresh();
    }

    private function makeVehicle(): Vehicle
    {
        $suffix = substr(md5(uniqid('', true)), 0, 8);

        $vehicle = new Vehicle([
            'vehicle_code' => 'VEH-'.$suffix,
            'plate_number' => 'PL-'.$suffix,
            'type' => 'van',
            'capacity_orders' => 60,
        ]);
        $vehicle->company_id = $this->company->id;
        $vehicle->save();

        return $vehicle->refresh();
    }
}
